<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\Config;

/**
 * The catalogue of order emails: what events exist, and which of them a given
 * order transition produces.
 *
 * Every order email in the system is named here and nowhere else. The name is
 * what the idempotency index is keyed on, so it is a stored value -- renaming
 * a constant's *string* after this ships would make already-sent events look
 * unsent and re-send them to customers.
 *
 * "Order placed" and "order confirmation" are one message, not two. The
 * receipt this store sends is the confirmation: it names the order, lists what
 * was bought and what it cost, and is sent the moment the order exists
 * whatever it was paid with. Sending a second, near-identical message would
 * not tell the customer anything the first did not, and two receipts minutes
 * apart is the pattern a spam filter reads as a misconfigured sender.
 */
class OrderEmailEvent
{
    /** The receipt. Sent once, when the order exists. */
    const ORDER_PLACED = 'order_placed';

    /**
     * Money confirmed by the gateway after the fact -- a redirect that came
     * back late, a webhook, a bank transfer marked off by an admin. Only sent
     * when the receipt has already gone out, because otherwise the receipt
     * itself carries the news.
     */
    const PAYMENT_RECEIVED = 'payment_received';

    /** A charge the gateway refused, or one that could not be verified. */
    const PAYMENT_FAILED = 'payment_failed';

    const ORDER_CONFIRMED = 'order_confirmed';
    const ORDER_PICKED_UP = 'order_picked_up';
    const ORDER_SHIPPED = 'order_shipped';
    const ORDER_DELIVERED = 'order_delivered';
    const ORDER_CANCELLED = 'order_cancelled';
    const ORDER_RETURNED = 'order_returned';
    const ORDER_REFUNDED = 'order_refunded';

    /**
     * Courier and tracking number added to an order that was already on its
     * way, so the "on the way" email went out without them. Sent once per
     * order; a corrected number shows on the tracking page behind the same
     * link instead of emailing again.
     */
    const TRACKING_ADDED = 'tracking_added';

    /**
     * The courier's own scans, via AfterShip. Each once per order: a second
     * failed attempt or a second day out for delivery does not email again
     * (the tracking page shows it).
     */
    const OUT_FOR_DELIVERY = 'out_for_delivery';
    const DELIVERY_ATTEMPT_FAILED = 'delivery_attempt_failed';

    /** The parcel waits at a pickup point or post office for the customer. */
    const READY_FOR_PICKUP = 'ready_for_pickup';

    /**
     * Return cases. An order can have more than one, and the log allows one
     * row per (order, event), so these are stored with the case id after a
     * colon -- "return_opened:12" -- via forReturn(). The id is also how the
     * mailer finds the case again when the retry sweep replays the event
     * without its context.
     */
    const RETURN_OPENED = 'return_opened';
    const RETURN_REFUNDED = 'return_refunded';
    const RETURN_REJECTED = 'return_rejected';
    // An exchange completed: the replacement order exists.
    const RETURN_EXCHANGED = 'return_exchanged';

    // The customer asked from their order page: "we have your request".
    const RETURN_REQUESTED = 'return_requested';
    // A dearer exchange: the pay link for the difference.
    const RETURN_PAYMENT = 'return_payment';

    const RETURN_EVENTS = [self::RETURN_OPENED, self::RETURN_REFUNDED, self::RETURN_REJECTED, self::RETURN_EXCHANGED,
        self::RETURN_REQUESTED, self::RETURN_PAYMENT];

    public static function forReturn($event, $returnId)
    {
        return $event . ':' . (int)$returnId;
    }

    /**
     * [event, return id] for a return event, null for anything else.
     *
     * @return array|null
     */
    public static function parseReturn($event)
    {
        if (!preg_match('/^([a-z_]+):(\d+)$/', (string)$event, $m)
            || !in_array($m[1], self::RETURN_EVENTS, true)) {
            return null;
        }

        return [$m[1], (int)$m[2]];
    }

    /**
     * Which email an order status corresponds to.
     *
     * PENDING is deliberately absent. It is the status an order is created
     * with, so an email for it would arrive alongside the receipt saying
     * nothing the receipt did not.
     *
     * @param  int         $status  A constants.orderStatus value.
     * @return string|null          The event, or null if the status has no email.
     */
    public static function forStatus($status)
    {
        $statuses = Config::get('constants.orderStatus');

        $map = [
            $statuses['CONFIRMED'] => self::ORDER_CONFIRMED,
            $statuses['PICKED_UP'] => self::ORDER_PICKED_UP,
            $statuses['ON_THE_WAY'] => self::ORDER_SHIPPED,
            $statuses['DELIVERED'] => self::ORDER_DELIVERED,
            $statuses['CANCELLED'] => self::ORDER_CANCELLED,
            $statuses['RETURNED'] => self::ORDER_RETURNED,
            $statuses['REFUNDED'] => self::ORDER_REFUNDED,
        ];

        return isset($map[(int)$status]) ? $map[(int)$status] : null;
    }

    /**
     * Every event this system can send. Used by the log's validation and by
     * the tests, so a new constant that nobody wired up is visible.
     */
    public static function all()
    {
        return [
            self::ORDER_PLACED,
            self::PAYMENT_RECEIVED,
            self::PAYMENT_FAILED,
            self::ORDER_CONFIRMED,
            self::ORDER_PICKED_UP,
            self::ORDER_SHIPPED,
            self::ORDER_DELIVERED,
            self::ORDER_CANCELLED,
            self::ORDER_RETURNED,
            self::ORDER_REFUNDED,
            self::TRACKING_ADDED,
            self::OUT_FOR_DELIVERY,
            self::DELIVERY_ATTEMPT_FAILED,
            self::READY_FOR_PICKUP,
        ];
    }

    /**
     * The status an event corresponds to, for the events that map to one.
     * The inverse of forStatus(); used when an event is replayed from the log
     * and the order has since moved on, so the email still describes the
     * transition it was raised for rather than wherever the order is now.
     *
     * @return int|null
     */
    public static function statusFor($event)
    {
        $statuses = Config::get("constants.orderStatus");

        foreach ($statuses as $value) {
            if (self::forStatus($value) === $event) {
                return (int)$value;
            }
        }

        return null;
    }

    public static function isKnown($event)
    {
        return in_array($event, self::all(), true) || !is_null(self::parseReturn($event));
    }

    /**
     * The value that goes in the X-Entity-Ref category header, and which the
     * provider dashboards group by. Hyphenated because that is the convention
     * the existing MailDeliverability categories already use.
     */
    public static function category($event)
    {
        // One category for every case, not one per return id.
        $return = self::parseReturn($event);

        return str_replace('_', '-', $return ? $return[0] : $event);
    }
}
