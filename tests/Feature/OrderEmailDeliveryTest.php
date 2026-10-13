<?php

namespace Tests\Feature;

use App\Jobs\SendOrderEmail;
use App\Models\Helper\MailFailure;
use App\Models\Helper\OrderEmailDispatcher;
use App\Models\Helper\OrderEmailEvent;
use App\Models\Order;
use App\Models\OrderEmailLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Order email delivery: the triggers, the idempotency and the failure handling.
 *
 * Mail is asserted against the array transport rather than Mail::fake(). This
 * codebase sends with the raw Mail::send($views, $data, $callback) form, and
 * MailFake silently drops anything that is not a Mailable -- a faked assertion
 * here would pass whether or not a message was ever built. Reading the array
 * transport tests the rendered message.
 *
 * phpunit.xml pins QUEUE_CONNECTION=sync, so a dispatched job runs inline and
 * the outbox holds what the queue would have sent. Where the point of a test
 * is that something was *queued* rather than sent, Queue::fake() is used
 * explicitly and says so.
 */
class OrderEmailDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox()->flush();
    }

    // ------------------------------------------------------------- fixtures

    private function mailbox()
    {
        return Mail::getSwiftMailer()->getTransport();
    }

    /**
     * The array transport hands back a Collection, not an array. Normalised so
     * "no email" fails as "no email" rather than as a method call on false.
     *
     * @return \Swift_Message[]
     */
    private function outbox()
    {
        $messages = $this->mailbox()->messages();

        return is_array($messages) ? $messages : $messages->all();
    }

    /**
     * An order with a usable address on it and nothing else.
     *
     * Built directly rather than driven through checkout: what is under test
     * is the email machinery, and a fixture that has to survive stock levels,
     * shipping rules and nine payment branches fails for reasons that have
     * nothing to do with email.
     */
    private function order(array $overrides = [])
    {
        return Order::create(array_merge([
            'order' => 'TEST' . uniqid(),
            'status' => config('constants.orderStatus.PENDING'),
            'order_method' => config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'total_amount' => 149.99,
            'currency' => 'EUR',
            'payment_done' => 0,
            'contact_name' => 'Test Customer',
            'contact_email' => 'order.test@example.com',
            'contact_phone' => '+353871234567',
        ], $overrides));
    }

    /**
     * The messages addressed to this order's customer.
     *
     * The sweep is database-wide: it re-queues every stuck row, not just the
     * one a test inserted. On a database with a real row stuck in 'queued' --
     * any local install with no queue worker -- the whole-outbox assertions
     * failed (or passed) on someone else's email. Scoping to the recipient
     * makes the sweep tests about their own row.
     *
     * @return \Swift_Message[]
     */
    private function outboxFor(Order $order)
    {
        return array_values(array_filter($this->outbox(), function ($message) use ($order) {
            return array_key_exists($order->contact_email, (array)$message->getTo());
        }));
    }

    private function logFor(Order $order, $event)
    {
        return OrderEmailLog::where('order_id', $order->id)
            ->where('event', $event)
            ->first();
    }

    /**
     * Swap in a transport that refuses every message, so a failure can be
     * provoked without a mail server.
     */
    private function failWith($message)
    {
        Mail::setSwiftMailer(new \Swift_Mailer(new ThrowingTransport($message)));
    }

    // ------------------------------------------------- failure classification

    /**
     * The decision the whole retry strategy rests on. Each of these is a real
     * reply the store's provider can return.
     */
    public function test_temporary_failures_are_classified_as_retryable()
    {
        $temporary = [
            'Connection could not be established with host smtp-relay.brevo.com',
            'Expected response code 250 but got code "421", with message "421 4.7.0 Try again later"',
            'Expected response code 250 but got code "451", with message "451 Temporary local problem"',
            'Expected response code 354 but got code "450", with message "450 4.2.0 Greylisted"',
            'HTTP 429 Too Many Requests',
            'HTTP 503 Service Unavailable',
            'stream_socket_enable_crypto(): SSL operation failed',
            'Connection timed out',
        ];

        foreach ($temporary as $message) {
            $this->assertSame(
                MailFailure::TRANSIENT,
                MailFailure::classify(new \Swift_TransportException($message)),
                "should be retried: {$message}"
            );
        }
    }

    public function test_permanent_failures_are_not_classified_as_retryable()
    {
        $permanent = [
            'Expected response code 250 but got code "550", with message "550 5.1.1 User unknown"',
            'Expected response code 250 but got code "553", with message "553 Recipient address rejected"',
            'Expected response code 250 but got code "554", with message "554 Message rejected"',
            'Expected response code 235 but got code "535", with message "535 Authentication failed"',
        ];

        foreach ($permanent as $message) {
            $this->assertSame(
                MailFailure::PERMANENT,
                MailFailure::classify(new \Swift_TransportException($message)),
                "should not be retried: {$message}"
            );
        }

        // A malformed address never becomes well-formed, whatever the delay.
        $this->assertSame(
            MailFailure::PERMANENT,
            MailFailure::classify(new \Swift_RfcComplianceException(
                'Address in mailbox given [not an address] does not comply with RFC 2822'
            ))
        );
    }

    /**
     * An unrecognised failure is retried. The ceiling in SendOrderEmail is
     * what makes that safe -- see the comment on $tries.
     */
    public function test_an_unrecognised_failure_is_retried_rather_than_discarded()
    {
        $this->assertSame(
            MailFailure::TRANSIENT,
            MailFailure::classify(new \RuntimeException('something nobody has seen before'))
        );
    }

    /**
     * Provider errors quote the recipient back at you. The log file is not
     * held to the same standard as the database, so nothing that reaches it
     * may carry a customer's address in full.
     */
    public function test_a_failure_summary_masks_the_customer_address()
    {
        $summary = MailFailure::summarise(new \Swift_TransportException(
            '550 5.1.1 <charlotte.hennessy@example.com>: Recipient address rejected'
        ));

        $this->assertStringNotContainsString('charlotte.hennessy@example.com', $summary);
        $this->assertStringContainsString('@example.com', $summary,
            'the domain is worth keeping -- it is what says which provider refused');
    }

    // ------------------------------------------------------------- idempotency

    /**
     * The duplicate-prevention requirement, in its plainest form.
     *
     * Order placed, triggered twice: one email.
     */
    public function test_a_repeated_event_does_not_send_a_second_email()
    {
        $order = $this->order();

        $first = OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_PLACED);
        $second = OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_PLACED);

        $this->assertTrue($first, 'the first trigger should claim the event');
        $this->assertFalse($second, 'the second trigger should find it already claimed');

        $this->assertCount(1, $this->outbox(), 'the customer received more than one copy');

        $this->assertSame(1, OrderEmailLog::where('order_id', $order->id)
            ->where('event', OrderEmailEvent::ORDER_PLACED)->count());
    }

    /**
     * The claim is made by the database, not by a read-then-write in PHP.
     *
     * Simulated by inserting the row behind the dispatcher's back, which is
     * what the losing side of a real race sees: the row it is about to write
     * already exists.
     */
    public function test_the_claim_is_enforced_by_the_unique_index()
    {
        $order = $this->order();

        DB::table('order_email_log')->insert([
            'order_id' => $order->id,
            'event' => OrderEmailEvent::ORDER_PLACED,
            'status' => OrderEmailLog::STATUS_QUEUED,
            'recipient' => $order->contact_email,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(
            OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_PLACED)
        );

        $this->assertEmpty($this->outbox());
    }

    /**
     * Different events on the same order are different claims. Confirming an
     * order must not suppress the message that it later shipped.
     */
    public function test_different_events_on_one_order_each_send()
    {
        $order = $this->order();

        OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_CONFIRMED);
        OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_SHIPPED);

        $this->assertCount(2, $this->outbox());
    }

    /**
     * Rapid status updates: an admin clicking through confirmed, on the way,
     * delivered gets one email per stage, and a stage set twice gets one.
     */
    public function test_rapid_status_updates_send_one_email_per_stage()
    {
        $order = $this->order();

        $sequence = [
            config('constants.orderStatus.CONFIRMED'),
            config('constants.orderStatus.PICKED_UP'),
            config('constants.orderStatus.ON_THE_WAY'),
            config('constants.orderStatus.ON_THE_WAY'),
            config('constants.orderStatus.DELIVERED'),
            config('constants.orderStatus.DELIVERED'),
        ];

        foreach ($sequence as $status) {
            $event = OrderEmailEvent::forStatus($status);
            OrderEmailDispatcher::dispatch($order, $event, null, ['status' => $status]);
        }

        $this->assertCount(4, $this->outbox(),
            'six updates over four distinct stages should produce four emails');
    }

    // ------------------------------------------------------------- recipients

    /**
     * An address that cannot be written to is recorded and dropped, not
     * queued. Four attempts against a malformed address is four rejections
     * against the sending domain for no possible gain.
     */
    public function test_an_invalid_address_is_recorded_and_never_queued()
    {
        Queue::fake();

        $order = $this->order(['contact_email' => 'not-an-address']);

        $this->assertFalse(
            OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_PLACED)
        );

        Queue::assertNothingPushed();

        $log = $this->logFor($order, OrderEmailEvent::ORDER_PLACED);

        $this->assertNotNull($log, 'an unsendable email still has to leave a record');
        $this->assertSame(OrderEmailLog::STATUS_SKIPPED, $log->status);
    }

    public function test_an_order_with_no_address_at_all_is_recorded_and_dropped()
    {
        $order = $this->order(['contact_email' => null, 'contact_name' => null]);

        $this->assertFalse(
            OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_PLACED)
        );

        $this->assertEmpty($this->outbox());
        $this->assertSame(
            OrderEmailLog::STATUS_SKIPPED,
            $this->logFor($order, OrderEmailEvent::ORDER_PLACED)->status
        );
    }

    /**
     * An address carrying CRLF is a header-injection attempt, not a typo, and
     * must never reach the transport.
     */
    public function test_an_address_containing_a_newline_is_refused()
    {
        $order = $this->order([
            'contact_email' => "real@example.com\nBcc: everyone@example.com",
        ]);

        $this->assertNull(OrderEmailDispatcher::recipient($order)['email']);
    }

    // --------------------------------------------------------- failure handling

    /**
     * A temporary failure is thrown back at the worker, which is what makes
     * the queue apply the backoff and try again. Swallowing it here would be
     * indistinguishable from success and the email would be lost.
     */
    public function test_a_temporary_failure_is_thrown_back_for_the_queue_to_retry()
    {
        $order = $this->order();

        DB::table('order_email_log')->insert([
            'order_id' => $order->id,
            'event' => OrderEmailEvent::ORDER_SHIPPED,
            'status' => OrderEmailLog::STATUS_QUEUED,
            'recipient' => $order->contact_email,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->failWith('Connection could not be established with host smtp-relay.brevo.com');

        $job = new SendOrderEmail(
            $order->id, OrderEmailEvent::ORDER_SHIPPED,
            $order->contact_email, 'Test Customer'
        );

        $threw = false;

        try {
            $job->handle();
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a temporary failure must reach the worker');

        $log = $this->logFor($order, OrderEmailEvent::ORDER_SHIPPED);

        $this->assertSame(OrderEmailLog::STATUS_QUEUED, $log->status,
            'a retryable attempt must not be marked failed -- it is still going');
        $this->assertNotNull($log->last_error);
    }

    /**
     * A permanent refusal stops immediately. It does not consume the
     * remaining attempts, and it does not leave the row saying 'queued' for a
     * job that has stopped running.
     */
    public function test_a_permanent_failure_stops_and_is_recorded()
    {
        $order = $this->order();

        DB::table('order_email_log')->insert([
            'order_id' => $order->id,
            'event' => OrderEmailEvent::ORDER_SHIPPED,
            'status' => OrderEmailLog::STATUS_QUEUED,
            'recipient' => $order->contact_email,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->failWith('Expected response code 250 but got code "550", '
            . 'with message "550 5.1.1 User unknown"');

        $job = new SendOrderEmail(
            $order->id, OrderEmailEvent::ORDER_SHIPPED,
            $order->contact_email, 'Test Customer'
        );

        // Returns rather than throwing: there is nothing for the worker to do.
        $job->handle();

        $log = $this->logFor($order, OrderEmailEvent::ORDER_SHIPPED);

        $this->assertSame(OrderEmailLog::STATUS_FAILED, $log->status);
        $this->assertNotNull($log->failed_at);
    }

    /**
     * Nothing stored on the row carries the customer's address in full
     * either -- the provider quoted it back in the refusal.
     */
    public function test_a_stored_error_does_not_carry_the_address_in_full()
    {
        $order = $this->order(['contact_email' => 'charlotte.hennessy@example.com']);

        DB::table('order_email_log')->insert([
            'order_id' => $order->id,
            'event' => OrderEmailEvent::ORDER_SHIPPED,
            'status' => OrderEmailLog::STATUS_QUEUED,
            'recipient' => $order->contact_email,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->failWith('550 5.1.1 <charlotte.hennessy@example.com>: Recipient address rejected');

        $job = new SendOrderEmail(
            $order->id, OrderEmailEvent::ORDER_SHIPPED,
            $order->contact_email, 'Test Customer'
        );

        $job->handle();

        $this->assertStringNotContainsString(
            'charlotte.hennessy@example.com',
            $this->logFor($order, OrderEmailEvent::ORDER_SHIPPED)->last_error
        );
    }

    /**
     * A job that arrives after the email has already gone -- a duplicated
     * queue entry, a worker restarting mid-batch -- sends nothing.
     */
    public function test_a_job_for_an_already_sent_event_does_nothing()
    {
        $order = $this->order();

        DB::table('order_email_log')->insert([
            'order_id' => $order->id,
            'event' => OrderEmailEvent::ORDER_SHIPPED,
            'status' => OrderEmailLog::STATUS_SENT,
            'recipient' => $order->contact_email,
            'attempts' => 1,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new SendOrderEmail(
            $order->id, OrderEmailEvent::ORDER_SHIPPED,
            $order->contact_email, 'Test Customer'
        ))->handle();

        $this->assertEmpty($this->outbox());
    }

    // ------------------------------------------------------------- recovery

    /**
     * Queue/job failure recovery: the sweep picks up a row that was claimed
     * and then orphaned -- a worker killed, a cron that stopped, a jobs table
     * lost to a restore -- and hands it back to the queue.
     */
    public function test_the_sweep_requeues_an_orphaned_claim()
    {
        $order = $this->order();

        DB::table('order_email_log')->insert([
            'order_id' => $order->id,
            'event' => OrderEmailEvent::ORDER_PLACED,
            'status' => OrderEmailLog::STATUS_QUEUED,
            'recipient' => $order->contact_email,
            'attempts' => 0,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('orders:retry-emails', ['--minutes' => 30])
            ->assertExitCode(0);

        $this->assertNotEmpty($this->outboxFor($order),
            'an orphaned claim should have been sent on the sweep');
    }

    /**
     * And, just as importantly, does not touch one that was refused
     * permanently. Replaying those on a schedule is the "retry indefinitely"
     * behaviour the classification exists to prevent.
     */
    public function test_the_sweep_leaves_permanently_failed_rows_alone()
    {
        $order = $this->order();

        DB::table('order_email_log')->insert([
            'order_id' => $order->id,
            'event' => OrderEmailEvent::ORDER_PLACED,
            'status' => OrderEmailLog::STATUS_FAILED,
            'recipient' => $order->contact_email,
            'attempts' => 4,
            'last_error' => '550 User unknown',
            'failed_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('orders:retry-emails', ['--minutes' => 30])
            ->assertExitCode(0);

        $this->assertEmpty($this->outboxFor($order));

        $this->assertSame(
            OrderEmailLog::STATUS_FAILED,
            $this->logFor($order, OrderEmailEvent::ORDER_PLACED)->status
        );
    }

    // ------------------------------------------------------------- content

    /**
     * A refund tells the customer how much is coming back. "Your order is
     * Refunded" with no figure on it is the email that generates the support
     * ticket it was meant to prevent.
     */
    public function test_the_refund_email_states_the_amount_and_reference()
    {
        $order = $this->order([
            'status' => config('constants.orderStatus.REFUNDED'),
        ]);

        OrderEmailDispatcher::dispatch(
            $order,
            OrderEmailEvent::ORDER_REFUNDED,
            null,
            [
                'status' => config('constants.orderStatus.REFUNDED'),
                'refund_amount' => 149.99,
                'refund_reference' => 're_TESTREF123',
            ]
        );

        $messages = $this->outbox();
        $this->assertNotEmpty($messages, 'a refund must tell the customer');

        $body = $messages[0]->getBody() . json_encode(array_map(function ($part) {
            return $part->getBody();
        }, $messages[0]->getChildren()));

        $this->assertStringContainsString('149.99', $body);
        $this->assertStringContainsString('re_TESTREF123', $body);
    }

    /**
     * The payment-failure email says the payment did not go through and
     * nothing else. No decline code, no gateway error text: none of it is
     * actionable by the customer, and all of it is detail a forwarded inbox
     * should not be carrying.
     */
    public function test_the_payment_failed_email_leaks_no_gateway_detail()
    {
        $order = $this->order();

        OrderEmailDispatcher::dispatch($order, OrderEmailEvent::PAYMENT_FAILED);

        $messages = $this->outbox();
        $this->assertNotEmpty($messages, 'a failed payment must tell the customer');

        $message = $messages[0];

        $this->assertStringContainsString($order->order, $message->getSubject());

        $body = $message->getBody() . json_encode(array_map(function ($part) {
            return $part->getBody();
        }, $message->getChildren()));

        foreach (['card_declined', 'insufficient_funds', 'do_not_honor', 'stripe', 'sk_live'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $body,
                "the customer should never be shown {$leak}");
        }
    }

    /**
     * Order mail is transactional, not marketing. An unsubscribe link on a
     * receipt invites people to opt out of mail they cannot opt out of, and
     * the auto-response suppression is what stops a holiday auto-reply
     * bouncing back at the sending domain.
     */
    public function test_order_mail_carries_transactional_headers_and_no_unsubscribe()
    {
        $order = $this->order();

        OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_SHIPPED);

        $messages = $this->outbox();
        $this->assertNotEmpty($messages);

        $headers = $messages[0]->getHeaders();

        $this->assertTrue($headers->has('Auto-Submitted'));
        $this->assertSame('auto-generated',
            $headers->get('Auto-Submitted')->getFieldBody());

        $this->assertFalse($headers->has('List-Unsubscribe'),
            'a receipt is not something a customer can unsubscribe from');
    }

    /**
     * A guest order is emailed on the same footing as a signed-in customer:
     * the recipient comes off the order, not off whoever is asking.
     */
    public function test_a_guest_order_resolves_its_recipient_from_the_order()
    {
        $order = $this->order([
            'user_id' => null,
            'contact_email' => 'guest.shopper@example.com',
            'contact_name' => 'Guest Shopper',
        ]);

        $this->assertSame(
            'guest.shopper@example.com',
            OrderEmailDispatcher::recipient($order)['email']
        );

        OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_SHIPPED);

        $messages = $this->outbox();
        $this->assertNotEmpty($messages);
        $this->assertArrayHasKey('guest.shopper@example.com', $messages[0]->getTo());
    }

    // ------------------------------------------------------------- isolation

    /**
     * The requirement that matters most: an email failure must never break
     * order handling. The dispatcher is called from inside checkout and from
     * payment confirmation, and an exception escaping either of those can
     * abort a request that has already taken money.
     */
    public function test_a_mail_failure_never_escapes_the_dispatcher()
    {
        $order = $this->order();

        $this->failWith('Connection could not be established with host smtp-relay.brevo.com');

        // No try/catch. If this throws, the test fails, which is the point.
        $result = OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_PLACED);

        $this->assertIsBool($result);
    }

    /**
     * Same guarantee when the log table itself is the problem. The order
     * stands whatever is wrong with the email plumbing.
     */
    public function test_an_unknown_event_is_refused_without_throwing()
    {
        $order = $this->order();

        $this->assertFalse(
            OrderEmailDispatcher::dispatch($order, 'not_a_real_event')
        );
    }

    /**
     * The retry ceiling is real, and is expressed as $tries.
     *
     * Laravel 8 treats $tries and $retryUntil as exclusive rather than
     * additive: Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts returns
     * early whenever retryUntil is set and still in the future, so a job
     * declaring both retries on the clock and never consults $tries at all.
     * This job used to declare both, and the four-attempt ceiling it
     * documented was not the one in force.
     *
     * Asserted rather than commented, because the failure mode is silent: the
     * job keeps working, it simply retries more often than anyone reading it
     * would think.
     */
    public function test_the_retry_ceiling_is_bounded_by_tries()
    {
        $job = new SendOrderEmail(1, OrderEmailEvent::ORDER_PLACED, 'a@example.com', 'A');

        $this->assertSame(4, $job->tries);

        $this->assertFalse(
            property_exists($job, 'retryUntil') && !is_null($job->retryUntil),
            'a retryUntil on this job silently disables $tries -- see the comment on it'
        );

        $this->assertSame([60, 300, 900], $job->backoff,
            'the backoff must increase: hammering a host that is rate-limiting '
                . 'you is how a temporary limit becomes a block'
        );
    }

    /**
     * Every event in the catalogue renders. A constant added without a
     * template behind it fails here rather than in a customer's inbox.
     */
    public function test_every_event_in_the_catalogue_produces_a_message()
    {
        foreach (OrderEmailEvent::all() as $event) {

            $this->mailbox()->flush();

            $order = $this->order([
                'status' => OrderEmailEvent::statusFor($event)
                    ?: config('constants.orderStatus.CONFIRMED'),
            ]);

            OrderEmailDispatcher::dispatch($order, $event, null, [
                'refund_amount' => 10.00,
                'refund_reference' => 'ref_1',
            ]);

            $this->assertNotEmpty($this->outbox(), "{$event} produced no email");
        }
    }
}

/**
 * A transport that refuses everything, so a delivery failure can be provoked
 * without a mail server to point at.
 */
class ThrowingTransport implements \Swift_Transport
{
    private $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function isStarted()
    {
        return true;
    }

    public function start()
    {
    }

    public function stop()
    {
    }

    public function ping()
    {
        return true;
    }

    public function send(\Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        throw new \Swift_TransportException($this->message);
    }

    public function registerPlugin(\Swift_Events_EventListener $plugin)
    {
    }
}
