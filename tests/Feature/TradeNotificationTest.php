<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessProductPricing;
use App\Models\Product;
use App\Models\User;
use App\Models\UserBusinessProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The customer email that follows a trade decision.
 *
 * Mail is asserted against the array transport rather than Mail::fake(),
 * because this codebase sends with the raw Mail::send($views, $data, $callback)
 * form and MailFake silently drops anything that is not a Mailable — a faked
 * assertion here would pass whether or not a message was ever built. Reading
 * the array transport tests the rendered message: its subject, its recipient
 * and the words in it.
 */
class TradeNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox()->flush();
    }

    private function mailbox()
    {
        return Mail::getSwiftMailer()->getTransport();
    }

    /**
     * The array transport hands back an Illuminate Collection, not an array.
     * Normalised here because end() on a Collection returns false, which turns
     * "no email" into a confusing "call to getSubject() on bool" several
     * frames away from the cause.
     *
     * @return \Swift_Message[]
     */
    private function outbox()
    {
        $messages = $this->mailbox()->messages();

        return is_array($messages) ? $messages : $messages->all();
    }

    private function lastMessage()
    {
        $messages = $this->outbox();

        $this->assertNotEmpty($messages, 'no email was generated');

        return end($messages);
    }

    /** The HTML part and the plain-text alternative, concatenated. */
    private function bodyOf($message)
    {
        $body = $message->getBody();

        foreach ($message->getChildren() as $child) {
            $body .= "\n" . $child->getBody();
        }

        return $body;
    }

    private function admin()
    {
        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        // auth:admin AND scope:admin - see phpunit notes in AccountTypeApiTest.
        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    private function applicant($attributes = [], $company = 'Quinn Electrical Ltd')
    {
        $user = User::create(array_merge([
            'name' => 'Dana Quinn',
            'email' => 'trade.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_PENDING,
        ], $attributes));

        if ($company) {
            UserBusinessProfile::create([
                'user_id' => $user->id,
                'company_name' => $company,
                'vat_number' => 'IE' . random_int(1000000, 9999999) . 'X',
                'trade' => 'Electrician',
            ]);
        }

        return $user->fresh();
    }

    private function review($applicant, $status, $note = null)
    {
        $this->admin();

        return $this->postJson("/api/admin/trade-application/action/{$applicant->id}", [
            'trade_status' => $status,
            'trade_review_note' => $note,
        ]);
    }

    /** A product with a band that halves its price, for entitlement checks. */
    private function bandedProduct()
    {
        $product = Product::where('status', 1)
            ->whereNotNull('selling')->where('selling', '>', 0)
            ->whereIn('id', function ($query) {
                $query->select('product_id')->from('updated_inventories');
            })->first();

        if (!$product) {
            $this->markTestSkipped('No published product with inventory to price against.');
        }

        BusinessProductPricing::create([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => round($product->selling * 0.5, 2),
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'final_price' => round($product->selling * 0.5, 2),
        ]);

        return $product;
    }

    private function bandsSeenBy($customer, $productId)
    {
        Passport::actingAs($customer->fresh(), ['user'], 'user');

        return $this->getJson("/api/v1/business-product/{$productId}")->json('data.pricing');
    }

    private function dashboardAccount($customer)
    {
        Passport::actingAs($customer->fresh(), ['user'], 'user');

        return $this->getJson('/api/v1/user/profile')->json('data.account');
    }

    // ---- approval, end to end ----------------------------------------------

    public function test_approving_a_pending_application_emails_the_customer()
    {
        $applicant = $this->applicant();

        $response = $this->review($applicant, User::TRADE_APPROVED, 'Checked against your VAT certificate.');

        $response->assertStatus(200);
        $this->assertSame(200, $response->json('status'));

        // The decision is saved.
        $fresh = $applicant->fresh();
        $this->assertSame(User::TRADE_APPROVED, $fresh->trade_status);

        // The entitlement follows it.
        $this->assertTrue($fresh->hasTradePricing());
        $this->assertTrue($fresh->hasBusinessPricing());

        // And exactly one email went out, to the right person.
        $this->assertCount(1, $this->outbox());

        $message = $this->lastMessage();

        $this->assertSame('Your Trade Account Has Been Approved', $message->getSubject());
        $this->assertSame([$applicant->email => $applicant->name], $message->getTo());
    }

    public function test_the_approval_email_says_what_changed_and_where_to_use_it()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_APPROVED);

        $body = $this->bodyOf($this->lastMessage());

        $this->assertStringContainsString('approved', $body);
        $this->assertStringContainsString('Trade pricing now applies automatically', $body);
        // The company the application was made for.
        $this->assertStringContainsString('Quinn Electrical Ltd', $body);
        // Somewhere to go: the shop, and their account.
        $this->assertStringContainsString('Start shopping at trade prices', $body);
        $this->assertStringContainsString('/user/dashboard', $body);
    }

    public function test_the_approval_email_carries_the_reviewers_note_when_there_is_one()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_APPROVED, 'Welcome aboard — your rep is Sam.');

        $this->assertStringContainsString(
            'Welcome aboard', $this->bodyOf($this->lastMessage())
        );
    }

    public function test_an_approved_customer_sees_approved_and_gets_trade_prices()
    {
        $product = $this->bandedProduct();
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_APPROVED);

        $account = $this->dashboardAccount($applicant);

        $this->assertSame('approved', $account['trade_status']);
        $this->assertTrue($account['has_business_pricing']);

        $this->assertNotEmpty($this->bandsSeenBy($applicant, $product->id));
    }

    // ---- rejection, end to end ----------------------------------------------

    public function test_rejecting_a_pending_application_emails_the_customer()
    {
        $applicant = $this->applicant();

        $response = $this->review($applicant, User::TRADE_REJECTED, 'We could not match the VAT number you gave us.');

        $response->assertStatus(200);

        $fresh = $applicant->fresh();

        $this->assertSame(User::TRADE_REJECTED, $fresh->trade_status);
        // The entitlement stays off.
        $this->assertFalse($fresh->hasTradePricing());
        $this->assertFalse($fresh->hasBusinessPricing());
        // The account still works, at retail.
        $this->assertTrue($fresh->canCheckout());

        $this->assertCount(1, $this->outbox());

        $message = $this->lastMessage();

        $this->assertSame('Update on Your Trade Account Application', $message->getSubject());
        $this->assertSame([$applicant->email => $applicant->name], $message->getTo());
    }

    public function test_the_rejection_email_is_clear_and_offers_a_way_back()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_REJECTED, 'We could not match the VAT number you gave us.');

        $body = $this->bodyOf($this->lastMessage());

        $this->assertStringContainsString('not able to approve it', $body);
        $this->assertStringContainsString('Not approved', $body);
        // The customer-safe reason the reviewer left.
        $this->assertStringContainsString('could not match the VAT number', $body);
        // What the account can still do, and how to reach a human.
        $this->assertStringContainsString('standard prices', $body);
        $this->assertStringContainsString('contact us at', $body);
    }

    public function test_a_rejected_customer_sees_rejected_and_gets_no_trade_prices()
    {
        $product = $this->bandedProduct();
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_REJECTED);

        $account = $this->dashboardAccount($applicant);

        $this->assertSame('rejected', $account['trade_status']);
        $this->assertFalse($account['has_business_pricing']);

        $this->assertSame([], $this->bandsSeenBy($applicant, $product->id));
    }

    public function test_a_rejection_with_no_note_still_sends_a_usable_email()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_REJECTED, null);

        $body = $this->bodyOf($this->lastMessage());

        // The reason block is left out entirely rather than printing a heading
        // with nothing under it.
        $this->assertStringNotContainsString('What the reviewer said', $body);
        $this->assertStringContainsString('not able to approve it', $body);
    }

    /**
     * A note that is only whitespace is not a reason, and must not produce an
     * empty "what the reviewer said" block.
     */
    public function test_a_blank_note_is_treated_as_no_reason()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_REJECTED, "   \n\t  ");

        $this->assertStringNotContainsString(
            'What the reviewer said', $this->bodyOf($this->lastMessage())
        );
    }

    // ---- exactly once --------------------------------------------------------

    public function test_approving_twice_sends_one_email()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_APPROVED)->assertStatus(200);
        $second = $this->review($applicant, User::TRADE_APPROVED);

        // The second call still succeeds - it is a legitimate note edit.
        $second->assertStatus(200);
        $this->assertSame(200, $second->json('status'));
        $this->assertFalse($second->json('data.notification.triggered'));

        $this->assertCount(1, $this->outbox());
    }

    public function test_rejecting_twice_sends_one_email()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_REJECTED);
        $this->review($applicant, User::TRADE_REJECTED);

        $this->assertCount(1, $this->outbox());
    }

    /**
     * Reading the queue or opening an application is not a decision. This is
     * the "admin refreshed the page" case.
     */
    public function test_reading_the_queue_or_the_application_sends_nothing()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_APPROVED);
        $this->assertCount(1, $this->outbox());

        $this->admin();
        $this->getJson('/api/admin/trade-application/all?status=approved&limit=50')->assertStatus(200);
        $this->getJson("/api/admin/trade-application/find/{$applicant->id}")->assertStatus(200);
        $this->getJson("/api/admin/user/find/{$applicant->id}")->assertStatus(200);

        $this->assertCount(1, $this->outbox());
    }

    /**
     * Editing the note on a decision already made is a legitimate save, and it
     * must not re-email the customer about a decision they already know.
     */
    public function test_editing_the_note_after_the_decision_does_not_re_email()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_APPROVED, 'First note.');
        $this->review($applicant, User::TRADE_APPROVED, 'Corrected note.');

        $this->assertCount(1, $this->outbox());
        $this->assertSame('Corrected note.', $applicant->fresh()->trade_review_note);
    }

    /**
     * The case the rule must NOT block: a real second decision on a new
     * application deserves its own email.
     */
    public function test_rejected_then_pending_then_approved_sends_both_emails()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_REJECTED);
        $this->review($applicant, User::TRADE_PENDING);
        $this->review($applicant, User::TRADE_APPROVED);

        $messages = $this->outbox();

        // Back-to-pending is not a customer-facing decision and sends nothing.
        $this->assertCount(2, $messages);

        $this->assertSame('Update on Your Trade Account Application', $messages[0]->getSubject());
        $this->assertSame('Your Trade Account Has Been Approved', $messages[1]->getSubject());
    }

    /** Withdrawing trade pricing is outside this feature's scope and emails nobody. */
    public function test_suspending_trade_pricing_sends_no_email()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_APPROVED);
        $this->mailbox()->flush();

        $this->review($applicant, User::TRADE_SUSPENDED)->assertStatus(200);

        $this->assertCount(0, $this->outbox());
        $this->assertSame(User::TRADE_SUSPENDED, $applicant->fresh()->trade_status);
    }

    // ---- authorization -------------------------------------------------------

    public function test_a_customer_cannot_approve_themselves_or_trigger_the_email()
    {
        $applicant = $this->applicant();

        Passport::actingAs($applicant, ['user'], 'user');

        $this->postJson("/api/admin/trade-application/action/{$applicant->id}", [
            'trade_status' => User::TRADE_APPROVED,
        ]);

        $this->assertSame(User::TRADE_PENDING, $applicant->fresh()->trade_status);
        $this->assertCount(0, $this->outbox());
    }

    public function test_an_anonymous_caller_cannot_trigger_a_decision_email()
    {
        $applicant = $this->applicant();

        $this->postJson("/api/admin/trade-application/action/{$applicant->id}", [
            'trade_status' => User::TRADE_REJECTED,
        ]);

        $this->assertSame(User::TRADE_PENDING, $applicant->fresh()->trade_status);
        $this->assertCount(0, $this->outbox());
    }

    // ---- nothing sensitive leaves the building --------------------------------

    public function test_the_email_carries_nothing_internal()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_REJECTED, 'VAT number did not match.');

        $body = $this->bodyOf($this->lastMessage());

        // No database identifiers.
        $this->assertStringNotContainsString('user_id', $body);
        $this->assertStringNotContainsString('trade_status', $body);
        $this->assertStringNotContainsString('verification_status', $body);

        // No verification detail from the application itself.
        $this->assertStringNotContainsString(
            $applicant->businessProfile->vat_number, $body
        );
    }

    // ---- failure handling -----------------------------------------------------

    /**
     * A decision the ladder refuses never reaches the database, so it must
     * never reach the customer either.
     */
    public function test_a_refused_transition_emails_nobody()
    {
        $applicant = $this->applicant();

        // pending -> suspended is not a legal move.
        $response = $this->review($applicant, User::TRADE_SUSPENDED);

        $this->assertSame(201, $response->json('status'));
        $this->assertSame(User::TRADE_PENDING, $applicant->fresh()->trade_status);
        $this->assertCount(0, $this->outbox());
    }

    public function test_an_unknown_decision_emails_nobody()
    {
        $applicant = $this->applicant();

        $this->review($applicant, 'platinum');

        $this->assertSame(User::TRADE_PENDING, $applicant->fresh()->trade_status);
        $this->assertCount(0, $this->outbox());
    }

    public function test_a_decision_on_a_customer_who_is_not_there_emails_nobody()
    {
        $this->admin();

        $this->postJson('/api/admin/trade-application/action/99999999', [
            'trade_status' => User::TRADE_APPROVED,
        ]);

        $this->assertCount(0, $this->outbox());
    }

    /**
     * The decision is committed before the email is attempted, so a mail
     * server that will not take the message must not undo it.
     */
    public function test_the_decision_stands_when_the_mailer_fails()
    {
        $applicant = $this->applicant();

        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new \Swift_TransportException('Connection refused'));

        $response = $this->review($applicant, User::TRADE_APPROVED);

        // The admin still gets a successful decision...
        $response->assertStatus(200);
        $this->assertSame(200, $response->json('status'));

        // ...the transition happened and the entitlement is live...
        $fresh = $applicant->fresh();
        $this->assertSame(User::TRADE_APPROVED, $fresh->trade_status);
        $this->assertTrue($fresh->hasBusinessPricing());

        // ...and the response says plainly that nobody was emailed, so the
        // admin screen can warn rather than claim success.
        $this->assertTrue($response->json('data.notification.triggered'));
        $this->assertFalse($response->json('data.notification.delivered'));
    }

    /**
     * An account with no address is not a delivery failure, it is an account
     * we were never able to write to. The decision still has to land.
     */
    public function test_a_customer_with_no_email_is_still_approved()
    {
        $applicant = $this->applicant(['email' => null]);

        $response = $this->review($applicant, User::TRADE_APPROVED);

        $response->assertStatus(200);
        $this->assertSame(User::TRADE_APPROVED, $applicant->fresh()->trade_status);
        $this->assertTrue($applicant->fresh()->hasBusinessPricing());

        $this->assertCount(0, $this->outbox());
        $this->assertFalse($response->json('data.notification.delivered'));
    }

    public function test_a_customer_with_an_unusable_email_is_still_rejected()
    {
        $applicant = $this->applicant(['email' => 'not-an-address']);

        $response = $this->review($applicant, User::TRADE_REJECTED);

        $response->assertStatus(200);
        $this->assertSame(User::TRADE_REJECTED, $applicant->fresh()->trade_status);

        // Never handed to the mailer, which would have thrown.
        $this->assertCount(0, $this->outbox());
        $this->assertFalse($response->json('data.notification.delivered'));
    }

    // ---- what the admin screen is told ----------------------------------------

    public function test_the_response_reports_whether_the_customer_was_told()
    {
        $applicant = $this->applicant();

        $notification = $this->review($applicant, User::TRADE_APPROVED)
            ->json('data.notification');

        $this->assertTrue($notification['triggered']);
        $this->assertTrue($notification['delivered']);

        /*
         * MailHelper sends inside the request, so the message has already gone
         * by the time this answers and the screen must say "sent", never
         * "queued" — including on an install that has a queue configured for
         * other work, because Mail::send does not use it.
         */
        $this->assertFalse($notification['queued']);
    }

    public function test_the_response_carries_the_account_state_the_dashboard_shows()
    {
        $applicant = $this->applicant();

        $account = $this->review($applicant, User::TRADE_APPROVED)->json('data.account');

        $this->assertSame('approved', $account['trade_status']);
        $this->assertTrue($account['has_business_pricing']);
    }

    // ---- the note is trimmed, not trusted to be short -------------------------

    public function test_a_very_long_note_is_shortened_rather_than_sent_whole()
    {
        $applicant = $this->applicant();

        $this->review($applicant, User::TRADE_REJECTED, str_repeat('word ', 400));

        $body = $this->bodyOf($this->lastMessage());

        $this->assertStringContainsString('…', $body);
        // 2000 characters of note would have produced a wall of text.
        $this->assertLessThan(400, substr_count($body, 'word '));
    }

    // ---- the whole journey ----------------------------------------------------

    /**
     * Registration through to the approval email, driven entirely through the
     * public and admin APIs rather than by writing rows.
     *
     * The individual steps are covered above; this exists because they are
     * covered *individually*, and the failure this catches is the one where
     * each part works and the chain does not.
     */
    public function test_a_trade_customer_can_apply_be_approved_and_be_told()
    {
        $email = 'journey.' . uniqid() . '@example.test';

        // 1. The customer applies.
        $this->postJson('/api/v1/user/signup', [
            'name' => 'Dana Quinn',
            'email' => $email,
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => 'Abcdefghij1',
            'terms' => 1,
            'account_type' => 'trade',
            'company_name' => 'Quinn Electrical Ltd',
            'trade' => 'Electrician',
            'vat_number' => 'IE' . random_int(1000000, 9999999) . 'X',
            'billing_address' => '2 High Street, Maynooth',
            'expected_spend' => '1000-5000',
            'resale_confirmed' => 1,
        ])->assertStatus(200);

        $applicant = User::where('email', $email)->first();

        $this->assertNotNull($applicant);
        $this->assertSame(User::TRADE_PENDING, $applicant->trade_status);

        // Signup sends its own verification code; that is not a trade decision.
        $this->mailbox()->flush();

        // 2. Confirm the address, the way the signup form does.
        $applicant->forceFill(['verified' => true])->save();

        $product = $this->bandedProduct();

        // 3. Waiting for review: retail prices, and the dashboard says so.
        $pending = $this->dashboardAccount($applicant);

        $this->assertSame('pending', $pending['trade_status']);
        $this->assertFalse($pending['has_business_pricing']);
        $this->assertSame([], $this->bandsSeenBy($applicant, $product->id));

        // 4. The application is in the queue an admin actually looks at.
        $this->admin();

        $queue = collect($this->getJson('/api/admin/trade-application/all?status=pending&limit=100')
            ->json('data.data'))->pluck('id');

        $this->assertTrue($queue->contains($applicant->id));

        // 5. The decision.
        $decision = $this->review($applicant, User::TRADE_APPROVED, 'Checked against your VAT certificate.');

        $decision->assertStatus(200);

        // 6. Status, entitlement and pricing all follow it.
        $this->assertSame(User::TRADE_APPROVED, $applicant->fresh()->trade_status);

        $approved = $this->dashboardAccount($applicant);

        $this->assertSame('approved', $approved['trade_status']);
        $this->assertTrue($approved['has_business_pricing']);
        $this->assertNotEmpty($this->bandsSeenBy($applicant, $product->id));

        // 7. And the customer is told, once.
        $this->assertCount(1, $this->outbox());

        $message = $this->lastMessage();

        $this->assertSame('Your Trade Account Has Been Approved', $message->getSubject());
        $this->assertSame([$email => 'Dana Quinn'], $message->getTo());
        $this->assertStringContainsString('Quinn Electrical Ltd', $this->bodyOf($message));
    }

    public function test_a_trade_customer_can_apply_be_turned_down_and_be_told()
    {
        $applicant = $this->applicant();
        $product = $this->bandedProduct();

        $this->review($applicant, User::TRADE_REJECTED, 'We could not match the VAT number you gave us.')
            ->assertStatus(200);

        $rejected = $this->dashboardAccount($applicant);

        $this->assertSame('rejected', $rejected['trade_status']);
        $this->assertFalse($rejected['has_business_pricing']);
        $this->assertSame([], $this->bandsSeenBy($applicant, $product->id));

        // Still a customer, still able to buy at retail.
        $this->assertTrue($applicant->fresh()->canCheckout());

        $this->assertCount(1, $this->outbox());

        $message = $this->lastMessage();

        $this->assertSame('Update on Your Trade Account Application', $message->getSubject());
        $this->assertStringContainsString(
            'could not match the VAT number', $this->bodyOf($message)
        );
    }

    /**
     * Two approve clicks racing each other.
     *
     * Both requests read the application while it still says pending, so both
     * pass the ladder check and both try to write. The conditional UPDATE is
     * what separates them: the first matches `trade_status = pending` and the
     * second matches nothing, so only one reports a transition and only one
     * email goes out.
     *
     * Simulated by moving the row after the model was loaded, which is exactly
     * the state the losing request is in.
     */
    public function test_a_losing_race_reports_no_transition_and_sends_nothing()
    {
        $applicant = $this->applicant();

        // The stale model the second request is holding.
        $stale = User::find($applicant->id);

        // The first request gets there first.
        User::where('id', $applicant->id)->update(['trade_status' => User::TRADE_APPROVED]);

        $transitioned = null;

        $error = \App\Models\Helper\AccountStatus::applyTrade(
            $stale, User::TRADE_APPROVED, 'Second click.', $transitioned
        );

        $this->assertNull($error);
        $this->assertFalse($transitioned, 'the losing request must not claim the transition');

        // The model now reflects the row rather than the write that never landed.
        $this->assertSame(User::TRADE_APPROVED, $stale->trade_status);

        // The note from the losing click was not written either.
        $this->assertNotSame('Second click.', $applicant->fresh()->trade_review_note);
    }

    /**
     * The mirror: the winning request does report the transition, so the rule
     * is "only one of them", not "neither of them".
     */
    public function test_the_winning_request_does_report_the_transition()
    {
        $applicant = $this->applicant();

        $transitioned = null;

        \App\Models\Helper\AccountStatus::applyTrade(
            $applicant, User::TRADE_APPROVED, 'First click.', $transitioned
        );

        $this->assertTrue($transitioned);
        $this->assertSame(User::TRADE_APPROVED, $applicant->fresh()->trade_status);
        $this->assertSame('First click.', $applicant->fresh()->trade_review_note);
    }

    /**
     * Applications written before the trade_status column existed carry NULL,
     * which the ladder reads as pending. A `= 'pending'` comparison never
     * matches NULL in SQL, so without the null branch in applyTrade those
     * accounts would be permanently unapprovable.
     */
    public function test_an_application_with_no_recorded_status_can_still_be_approved()
    {
        $applicant = $this->applicant();

        User::where('id', $applicant->id)->update(['trade_status' => null]);

        $this->review($applicant->fresh(), User::TRADE_APPROVED)->assertStatus(200);

        $this->assertSame(User::TRADE_APPROVED, $applicant->fresh()->trade_status);
        $this->assertCount(1, $this->outbox());
    }
}
