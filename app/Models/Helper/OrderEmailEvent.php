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
        return in_array($event, self::all(), true);
    }

    /**
     * The value that goes in the X-Entity-Ref category header, and which the
     * provider dashboards group by. Hyphenated because that is the convention
     * the existing MailDeliverability categories already use.
     */
    public static function category($event)
    {
        return str_replace('_', '-', $event);
    }
}
