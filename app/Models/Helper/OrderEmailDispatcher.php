<?php

namespace App\Models\Helper;

use App\Jobs\SendOrderEmail;
use App\Models\Order;
use App\Models\OrderEmailLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one way an order email is raised.
 *
 * Everything the rest of the application needs to know is the single call
 * `OrderEmailDispatcher::dispatch($order, OrderEmailEvent::ORDER_SHIPPED)`.
 * Whether that address is usable, whether this event already went out, whether
 * there is a queue to put it on, and what happens when the mail server refuses
 * -- none of that is the caller's problem, and every caller that used to
 * answer those questions for itself answered them slightly differently.
 *
 * THIS CLASS NEVER THROWS. That is not a style preference: it is called from
 * inside checkout, from payment confirmation and from the admin status update,
 * and an exception escaping into any of those is an exception that can abort a
 * request which has already taken a customer's money. Every path out of here
 * is a bool.
 */
class OrderEmailDispatcher
{
    /**
     * Raise an order email, once.
     *
     * @param  Order|int  $order
     * @param  string     $event    An OrderEmailEvent constant.
     * @param  string     $lang
     * @param  array      $context  Event extras: refund_amount, time_zone, ...
     * @return bool                 True if this call is the one that queued it.
     */
    public static function dispatch($order, $event, $lang = null, array $context = [])
    {
        try {
            if (!OrderEmailEvent::isKnown($event)) {
                Log::warning('Unknown order email event', ['event' => $event]);
                return false;
            }

            $order = $order instanceof Order ? $order : Order::find($order);

            if (is_null($order)) {
                return false;
            }

            $contact = static::recipient($order);

            /*
             * No usable address is recorded, not retried. An order placed
             * against a mistyped address is not a delivery failure that will
             * come good on the third attempt, and a row saying so is what
             * stops support hunting for an email that was never sendable.
             */
            if (!$contact['email']) {

                static::record($order->id, $event, null, OrderEmailLog::STATUS_SKIPPED,
                    'No usable recipient address on the order');

                Log::warning('Order email skipped: no recipient', [
                    'order_id' => $order->id,
                    'event' => $event,
                ]);

                return false;
            }

            if (!static::claim($order->id, $event, $contact['email'])) {
                /*
                 * Already raised. The duplicate is the expected case here, not
                 * an error: markOrderPaid() and the checkout branch both call
                 * for the receipt by design, precisely so that whichever one
                 * happens first wins.
                 */
                return false;
            }

            /*
             * Its own guard, separate from the one below.
             *
             * On a queued connection this only writes a jobs row and cannot
             * realistically throw. On the 'sync' connection -- which is what
             * the test suite runs, and what an install that has not switched
             * QUEUE_CONNECTION over is still on -- it runs the send inline, so
             * a mail server refusing a connection arrives here as an
             * exception.
             *
             * The claim has already been made either way, and the row is left
             * saying 'queued', which is true: the sweep will pick it up. What
             * must not happen is this being reported as "not claimed", because
             * the caller would then read it as "already sent" and, in
             * markOrderPaid's case, follow it with a second email.
             */
            try {
                SendOrderEmail::dispatch(
                    $order->id,
                    $event,
                    $contact['email'],
                    $contact['name'],
                    $lang,
                    $context
                )->onQueue(static::queueName());

            } catch (\Throwable $e) {

                Log::error('Order email could not be handed to the queue', [
                    'order_id' => $order->id,
                    'event' => $event,
                    'message' => MailFailure::summarise($e),
                ]);
            }

            return true;

        } catch (\Throwable $e) {

            /*
             * Reached only if the claim itself failed -- the log table missing,
             * the queue table missing, the database gone. The order stands
             * either way.
             */
            Log::error('Order email could not be raised', [
                'order_id' => $order instanceof Order ? $order->id : $order,
                'event' => $event,
                'message' => MailFailure::summarise($e),
            ]);

            return false;
        }
    }

    /**
     * Claim the event by inserting its row.
     *
     * The unique index on (order_id, event) is what makes this atomic. Two
     * requests racing -- a gateway webhook and the browser returning from the
     * redirect, which is the common case and not a rare one -- both reach
     * here; exactly one insert succeeds and the other is refused by the
     * database. A SELECT-then-INSERT would let both read "not sent".
     *
     * @return bool True if this caller won the claim.
     */
    private static function claim($orderId, $event, $recipient)
    {
        try {
            DB::table('order_email_log')->insert([
                'order_id' => $orderId,
                'event' => $event,
                'status' => OrderEmailLog::STATUS_QUEUED,
                'recipient' => $recipient,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;

        } catch (\Illuminate\Database\QueryException $e) {

            /*
             * 23000 is the SQLSTATE class for an integrity constraint -- here,
             * the unique index doing its job. Any other database error is a
             * real problem and is re-thrown to the caller's catch, which logs
             * it; swallowing everything would turn a missing table into
             * "emails silently stopped".
             */
            if ((string)$e->getCode() === '23000') {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Write a terminal row for an event that will never be queued.
     */
    private static function record($orderId, $event, $recipient, $status, $reason)
    {
        try {
            DB::table('order_email_log')->insert([
                'order_id' => $orderId,
                'event' => $event,
                'status' => $status,
                'recipient' => $recipient,
                'attempts' => 0,
                'last_error' => $reason,
                'failed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            // Already recorded by an earlier trigger. Nothing to add.
        }
    }

    /**
     * Who this order's mail goes to.
     *
     * Order::contactDetails() already walks account -> guest row -> checkout
     * contact -> delivery address, and is the same resolution the receipt has
     * used since the guest checkout work; this adds the validation, so an
     * address that cannot be sent to is caught here rather than inside the
     * transport four retries later.
     */
    public static function recipient(Order $order)
    {
        $contact = $order->contactDetails();

        $email = trim((string)$contact['email']);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => null, 'name' => ''];
        }

        /*
         * Swift refuses a header containing a newline, and rightly: an address
         * carrying CRLF is a header-injection attempt, not a typo. Caught here
         * so it is recorded as an unusable address rather than throwing inside
         * the job and being retried four times.
         */
        if (preg_match('/[\r\n]/', $email)) {
            return ['email' => null, 'name' => ''];
        }

        $name = trim((string)$contact['name']);
        $name = preg_replace('/[\r\n]+/', ' ', $name);

        return ['email' => $email, 'name' => $name];
    }

    /**
     * Order mail rides its own queue so a backlog of some other job type
     * cannot hold up a receipt.
     */
    public static function queueName()
    {
        return config('mail.order_queue', 'emails');
    }
}
