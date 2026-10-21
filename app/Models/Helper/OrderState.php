<?php

namespace App\Models\Helper;

use App\Models\Cancellation;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * An order's state as three separate facts (2026-10-21):
 *
 *   order status   where the order itself is: processing, shipped, delivered,
 *                  cancelled, returned (every unit came back on return cases)
 *   return status  the latest return or exchange case on it, if any
 *   refund status  money owed or sent back: pending, processing, completed,
 *                  partially refunded, failed
 *
 * They used to be one number. Refunding a cancelled order moved its status
 * from CANCELLED (6) to REFUNDED (8), so the customer's Cancelled tab, which
 * filtered on "closed" statuses, showed it as "Refunded", and the admin's
 * Cancelled chip lost it altogether. A refund is now recorded on the
 * cancellation or the return case and the order keeps its own status; this
 * class also reads the orders already moved to 8 that way back as cancelled.
 *
 * A refund is only ever reported as completed from a record of money that
 * went back (cancellations.refunded, order_returns.refund_amount on a
 * refunded case). The one exception is a legacy order an admin set to
 * "Refunded" by hand, which is reported as the shop recorded it.
 *
 * Which tab an order belongs to is decided here in SQL, so the customer's
 * counts, search and pagination all come from the server, not from the one
 * page of orders the browser happens to hold.
 */
class OrderState
{
    const TABS = ['all', 'processing', 'shipped', 'delivered', 'cancelled', 'returns'];

    private static function s($key)
    {
        return (int)Config::get('constants.orderStatus.' . $key);
    }

    /**
     * Was the order cancelled. The customer's flag, the status, or (for the
     * records moved on to REFUNDED by the old cancellation refund) a status
     * of REFUNDED that came from a cancellation.
     */
    public static function cancelledSql($a = 'orders')
    {
        $cancelled = self::s('CANCELLED');
        $refunded = self::s('REFUNDED');

        return "($a.cancelled = 1 OR $a.status = $cancelled OR ($a.status = $refunded AND ("
            . "EXISTS (SELECT 1 FROM cancellations cx WHERE cx.order_id = $a.id)"
            . " OR EXISTS (SELECT 1 FROM order_status_histories hx WHERE hx.order_id = $a.id AND hx.to_status = $cancelled))))";
    }

    /**
     * Anything to show under Returns & Refunds: a return or exchange case,
     * an order returned or marked refunded, or a cancelled order whose money
     * is owed, sent back or failed to go back.
     */
    public static function returnsSql($a = 'orders')
    {
        $cancelled = self::cancelledSql($a);
        $cod = (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY');
        $closed = implode(',', [self::s('RETURNED'), self::s('REFUNDED')]);

        return "(EXISTS (SELECT 1 FROM order_returns rx WHERE rx.order_id = $a.id)"
            . " OR ($a.status IN ($closed) AND NOT $cancelled)"
            . " OR ($cancelled AND ($a.payment_done = 1 AND $a.order_method != $cod"
            . " OR EXISTS (SELECT 1 FROM cancellations cy WHERE cy.order_id = $a.id AND (cy.refunded = 1 OR cy.refund_error IS NOT NULL)))))";
    }

    /** Narrow a query of orders to one tab. */
    public static function applyTab($query, $tab, $a = 'orders')
    {
        $cancelled = self::cancelledSql($a);

        switch ($tab) {
            case 'cancelled':
                return $query->whereRaw($cancelled);
            case 'processing':
                return $query->whereRaw("NOT $cancelled")->whereIn("$a.status", [self::s('PENDING'), self::s('CONFIRMED')]);
            case 'shipped':
                return $query->whereRaw("NOT $cancelled")->whereIn("$a.status", [self::s('PICKED_UP'), self::s('ON_THE_WAY')]);
            case 'delivered':
                return $query->whereRaw("NOT $cancelled")->where("$a.status", self::s('DELIVERED'));
            case 'returns':
                return $query->whereRaw(self::returnsSql($a));
            default:
                return $query;
        }
    }

    /** {tab: count} over a base query (already scoped to the customer and the search). */
    public static function counts($base)
    {
        $out = [];

        foreach (self::TABS as $tab) {
            $q = clone $base;
            $q->getQuery()->orders = null;
            $out[$tab] = self::applyTab($q, $tab)->count();
        }

        return $out;
    }

    /**
     * State for many orders at once: two queries, not two per order.
     *
     * @return array {order id: describe()}
     */
    public static function describeMany($orders)
    {
        $orders = collect($orders);
        $ids = $orders->pluck('id')->filter()->all();

        if (!$ids) {
            return [];
        }

        $cancellations = Cancellation::whereIn('order_id', $ids)->orderByDesc('id')->get()->unique('order_id')->keyBy('order_id');
        $returns = OrderReturn::whereIn('order_id', $ids)->orderByDesc('id')->get()->groupBy('order_id');
        $fromCancelled = \App\Models\OrderStatusHistory::whereIn('order_id', $ids)
            ->where('to_status', self::s('CANCELLED'))->pluck('order_id')->flip();

        $out = [];

        foreach ($orders as $order) {
            $out[$order->id] = self::describe($order, $cancellations[$order->id] ?? null,
                $returns[$order->id] ?? collect(), isset($fromCancelled[$order->id]));
        }

        return $out;
    }

    public static function forOrder(Order $order)
    {
        return self::describeMany([$order])[$order->id] ?? null;
    }

    /**
     * @param  Cancellation|null  $cancellation
     * @param  Collection         $returns        newest first
     * @param  bool               $everCancelled  the status history passed through CANCELLED
     */
    public static function describe($order, $cancellation, Collection $returns, $everCancelled = false)
    {
        $status = (int)$order->status;
        $wasCancelled = (int)$order->cancelled === 1 || $status === self::s('CANCELLED')
            || ($status === self::s('REFUNDED') && ($cancellation || $everCancelled));

        if ($wasCancelled) {
            $orderKey = 'cancelled';
        } elseif ($status === self::s('RETURNED')) {
            $orderKey = 'returned';
        } elseif ($status === self::s('REFUNDED')) {
            // Legacy: an admin set a delivered order to "Refunded" by hand.
            $orderKey = 'refunded';
        } elseif ($status === self::s('DELIVERED')) {
            $orderKey = 'delivered';
        } elseif (in_array($status, [self::s('PICKED_UP'), self::s('ON_THE_WAY')], true)) {
            $orderKey = 'shipped';
        } else {
            $orderKey = 'processing';
        }

        $latest = $returns->first();

        return [
            'order' => $orderKey,
            // progress through the five steps, for an order still on its way
            'step' => in_array($orderKey, ['processing', 'shipped', 'delivered'], true) ? max(1, min(5, $status)) : null,
            'refund' => $wasCancelled
                ? self::cancellationRefund($order, $cancellation, $status)
                : self::returnsRefund($order, $returns, $status),
            'return' => $latest ? [
                'key' => self::returnKey($latest->status),
                'type' => $latest->type ?: OrderReturn::TYPE_RETURN,
                'rma_number' => $latest->rma_number,
                'count' => $returns->count(),
            ] : null,
        ];
    }

    /** What a return case's status means to the customer. */
    public static function returnKey($caseStatus)
    {
        return [
            OrderReturn::REQUESTED => 'requested',
            OrderReturn::AWAITING => 'approved',
            OrderReturn::RECEIVED => 'item_received',
            OrderReturn::REFUNDING => 'item_received',
            OrderReturn::REFUNDED => 'completed',
            OrderReturn::CLOSED => 'completed',
            OrderReturn::EXCHANGED => 'completed',
            OrderReturn::AWAITING_PAYMENT => 'awaiting_payment',
            OrderReturn::REJECTED => 'rejected',
            OrderReturn::CANCELLED => 'withdrawn',
        ][$caseStatus] ?? 'requested';
    }

    /** The refund on one case, as the customer reads it; null when no money is involved. */
    public static function caseRefundKey(OrderReturn $case)
    {
        if ($case->status === OrderReturn::REFUNDING) {
            return 'processing';
        }
        if (in_array($case->status, OrderReturn::MONEY_BACK, true) && (float)$case->refund_amount > 0) {
            return 'completed';
        }
        if ($case->type === OrderReturn::TYPE_EXCHANGE) {
            return null;
        }
        if ($case->status === OrderReturn::RECEIVED && $case->refund_error) {
            return 'failed';
        }
        if (in_array($case->status, [OrderReturn::AWAITING, OrderReturn::RECEIVED], true)) {
            return 'pending';
        }

        return null;
    }

    private static function cancellationRefund($order, $cancellation, $status)
    {
        if ($cancellation && (int)$cancellation->refunded === 1) {
            return ['key' => 'completed', 'amount' => (float)$cancellation->refunded_amount];
        }
        if ($cancellation && $cancellation->refund_error) {
            return ['key' => 'failed', 'amount' => null];
        }
        // Moved to "Refunded" by hand: the shop's record, not a gateway's.
        if ($status === self::s('REFUNDED')) {
            return ['key' => 'completed', 'amount' => null];
        }
        // Paid by a method that can be refunded: the money is owed back.
        if ((int)$order->payment_done === 1
            && (int)$order->order_method !== (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY')) {
            return ['key' => 'pending', 'amount' => null];
        }

        return null;
    }

    private static function returnsRefund($order, Collection $returns, $status)
    {
        if ($returns->contains('status', OrderReturn::REFUNDING)) {
            return ['key' => 'processing', 'amount' => null];
        }

        $failed = $returns->first(function ($r) {
            return $r->status === OrderReturn::RECEIVED && $r->refund_error && $r->type !== OrderReturn::TYPE_EXCHANGE;
        });
        if ($failed) {
            return ['key' => 'failed', 'amount' => null];
        }

        $sent = round((float)$returns->whereIn('status', OrderReturn::MONEY_BACK)->sum('refund_amount'), 2);

        if ($sent > 0) {
            $whole = $status === self::s('RETURNED') || $sent >= round((float)$order->total_amount, 2) - 0.005;

            // A further return still waiting for its money keeps it pending.
            $waiting = $returns->first(function ($r) { return self::caseRefundKey($r) === 'pending'; });

            return ['key' => $waiting ? 'pending' : ($whole ? 'completed' : 'partial'), 'amount' => $sent];
        }

        if ($returns->first(function ($r) { return self::caseRefundKey($r) === 'pending'; })) {
            return ['key' => 'pending', 'amount' => null];
        }

        if ($status === self::s('REFUNDED')) {
            return ['key' => 'completed', 'amount' => null];
        }

        return null;
    }
}
