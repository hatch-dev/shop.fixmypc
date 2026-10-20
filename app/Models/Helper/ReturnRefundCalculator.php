<?php

namespace App\Models\Helper;

use App\Models\Cancellation;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\PointTransaction;
use App\Models\Voucher;

/**
 * What a returned item is worth back, worked out from the order's own rows.
 *
 * The customer did not pay the list price for a line: order-wide discounts
 * (voucher, flash sale, Essentials Bundle, loyalty or membership, points) came
 * off the whole order. Each one is shared across the lines by what each line
 * paid, so returning one item refunds what that item actually cost, and
 * returning every item refunds exactly what the order took -- never a cent
 * more, whatever order the returns come in.
 *
 * A gift card is a payment, not a discount, but it is not money the gateway
 * holds either: its share goes back onto the card (ordered_voucher
 * .returned_amount), not to the bank. Points spent come back as points;
 * points earned on the order are taken back in proportion.
 *
 * Nothing here reads the catalogue, a voucher's current settings or today's
 * prices -- only OrderSummary, which reads only what the order recorded.
 * Money is worked in cents throughout so the shares add up exactly.
 */
class ReturnRefundCalculator
{
    /**
     * Every line of the order with what it paid and how much of it is still
     * returnable. Keyed by ordered_products.id.
     *
     * @param  int|null  $ignoreReturnId  a case whose own items should not
     *                                    count as "already returned"
     */
    public static function lines(Order $order, $ignoreReturnId = null)
    {
        $order->loadMissing('ordered_products');

        $summary = OrderSummary::for($order);
        $byPair = [];

        foreach ($summary['lines'] as $line) {
            $byPair[$line['product_id'] . ':' . $line['inventory_id']] = $line;
        }

        // What each line paid, gross, after its own line discounts and freebies.
        $paid = [];

        foreach ($order->ordered_products as $product) {
            $line = $byPair[$product->product_id . ':' . $product->inventory_id] ?? null;

            $paid[$product->id] = $line
                ? Pricing::toMinorUnits($line['line_total'] - $line['bundle_offer_value'] + $line['tax_total'])
                : 0;
        }

        $giftCard = self::giftCardVoucher($order);

        $discounts = Pricing::round(
            $summary['flash_discount'] + $summary['bundle_discount']
            + $summary['loyalty_discount'] + $summary['points_discount']
            + ($giftCard ? 0 : $summary['voucher'])
        );

        $discountShares = self::share(Pricing::toMinorUnits($discounts), $paid);
        $giftShares = self::share(Pricing::toMinorUnits($giftCard ? $summary['voucher'] : 0), $paid);

        $points = self::orderPoints($order);
        $spentShares = self::share($points['spent'], $paid);
        $earnedShares = self::share($points['earned'], $paid);

        $taken = self::returnedSoFar($order->id, $ignoreReturnId);

        $out = [];

        foreach ($order->ordered_products as $product) {
            $id = $product->id;
            $quantity = (int)$product->quantity;
            $live = $taken[$id]['live_qty'] ?? 0;

            $out[$id] = [
                'ordered_product_id' => (int)$id,
                'product_id' => (int)$product->product_id,
                'inventory_id' => $product->inventory_id ? (int)$product->inventory_id : null,
                'quantity' => $quantity,
                'returned_quantity' => $live,
                'returnable_quantity' => max(0, $quantity - $live),

                // Whole-line figures, in cents and points.
                'paid' => $paid[$id],
                'refundable' => max(0, $paid[$id] - $discountShares[$id] - $giftShares[$id]),
                'gift_card' => $giftShares[$id],
                'points_spent' => $spentShares[$id],
                'points_earned' => $earnedShares[$id],

                // What finished cases already took off this line.
                'done_qty' => $taken[$id]['done_qty'] ?? 0,
                'done_refund' => $taken[$id]['done_refund'] ?? 0,
                'done_gift' => $taken[$id]['done_gift'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * The refund for returning $quantities ({ordered_product_id: qty}).
     *
     * @return array  amounts in currency units; points as integers
     */
    public static function quote(Order $order, array $quantities, $refundShipping = false, $ignoreReturnId = null)
    {
        $lines = self::lines($order, $ignoreReturnId);
        $points = self::orderPoints($order);

        $items = [];
        $money = 0;
        $gift = 0;
        $pointsBack = 0;
        $pointsTaken = 0;

        foreach ($quantities as $orderedProductId => $qty) {
            $qty = (int)$qty;
            $line = $lines[$orderedProductId] ?? null;

            if (!$line || $qty <= 0) {
                continue;
            }

            $refund = self::part($line['refundable'], $line['quantity'], $qty, $line['done_qty'], $line['done_refund']);
            $giftPart = self::part($line['gift_card'], $line['quantity'], $qty, $line['done_qty'], $line['done_gift']);

            // Points have no per-line record, so they are always proportional.
            $back = (int)floor($line['points_spent'] * $qty / max(1, $line['quantity']));
            $earned = (int)floor($line['points_earned'] * $qty / max(1, $line['quantity']));

            $items[$orderedProductId] = [
                'ordered_product_id' => (int)$orderedProductId,
                'quantity' => $qty,
                'refund' => $refund / 100,
                'gift_card' => $giftPart / 100,
            ];

            $money += $refund;
            $gift += $giftPart;
            $pointsBack += $back;
            $pointsTaken += $earned;
        }

        $shipping = $refundShipping ? Pricing::toMinorUnits(self::shippingLeft($order)) : 0;

        // Never more than the order took in money and has not already given back.
        $cap = Pricing::toMinorUnits(self::moneyLeft($order));
        $total = min($money + $shipping, $cap);

        return [
            'items' => $items,
            'items_refund' => $money / 100,
            'shipping_refund' => $shipping / 100,
            'refund' => max(0, $total) / 100,
            'gift_card' => $gift / 100,
            'gift_card_code' => optional(self::giftCardVoucher($order))->code,
            'points_returned' => min($pointsBack, $points['spent']),
            'points_taken' => min($pointsTaken, $points['earned']),
            'money_left' => $cap / 100,
        ];
    }

    /**
     * What the order took in money (card, wallet, bank...) and has not paid
     * back yet, across returns and a cancellation refund.
     */
    public static function moneyLeft(Order $order)
    {
        return max(0, Pricing::round((float)$order->total_amount - self::moneyRefunded($order)));
    }

    /** Money already paid back on this order, by returns and a cancellation. */
    public static function moneyRefunded(Order $order)
    {
        // A cheaper exchange pays back the difference, which is a refund too.
        $refunded = (float)OrderReturn::where('order_id', $order->id)
            ->whereIn('status', OrderReturn::MONEY_BACK)->sum('refund_amount');

        $refunded += (float)Cancellation::where('order_id', $order->id)
            ->where('refunded', 1)->sum('refunded_amount');

        return Pricing::round($refunded);
    }

    /** Delivery charged on the order, less any a return already gave back. */
    public static function shippingLeft(Order $order)
    {
        $order->loadMissing('ordered_products');

        $charged = OrderSummary::for($order)['shipping'];
        $given = (float)OrderReturn::where('order_id', $order->id)
            ->whereIn('status', OrderReturn::DONE)->sum('shipping_refund');

        return max(0, Pricing::round($charged - $given));
    }

    /** The gift card voucher this order used, if the voucher on it was one. */
    public static function giftCardVoucher(Order $order)
    {
        if (!$order->voucher_id) {
            return null;
        }

        $voucher = Voucher::find($order->voucher_id);

        return $voucher && $voucher->isGiftCard() ? $voucher : null;
    }

    /**
     * Part of a whole-line figure for $qty units.
     *
     * Proportional, except when these units finish the line: then it is
     * whatever earlier returns left, so rounding never makes the parts add up
     * to more (or less) than the whole.
     */
    private static function part($whole, $lineQty, $qty, $doneQty, $doneAmount)
    {
        $left = max(0, $whole - $doneAmount);

        if ($qty + $doneQty >= $lineQty) {
            return $left;
        }

        return min($left, (int)round($whole * $qty / max(1, $lineQty)));
    }

    /**
     * Split an integer amount across weights so the parts add up exactly.
     * What rounding down leaves over goes to the largest weights first.
     */
    private static function share($amount, array $weights)
    {
        $amount = (int)$amount;
        $sum = array_sum($weights);
        $parts = array_fill_keys(array_keys($weights), 0);

        if ($amount <= 0 || $sum <= 0) {
            return $parts;
        }

        // A discount can never take a line below zero.
        $amount = min($amount, $sum);

        foreach ($weights as $key => $weight) {
            $parts[$key] = intdiv($amount * $weight, $sum);
        }

        $left = $amount - array_sum($parts);
        arsort($weights);

        foreach (array_keys($weights) as $key) {
            if ($left <= 0) {
                break;
            }
            $parts[$key]++;
            $left--;
        }

        return $parts;
    }

    /** Points the order spent and earned (the original movements only). */
    public static function orderPoints(Order $order)
    {
        $rows = PointTransaction::where('reference_id', $order->id)->where('source', 'order')->get();

        return [
            'spent' => (int)$rows->where('type', 'debit')->sum('points'),
            'earned' => (int)$rows->where('type', 'credit')->sum('points'),
        ];
    }

    /**
     * Per ordered line: units on live cases, and what finished cases took.
     */
    private static function returnedSoFar($orderId, $ignoreReturnId = null)
    {
        $rows = OrderReturnItem::join('order_returns as r', 'r.id', '=', 'order_return_items.order_return_id')
            ->where('r.order_id', $orderId)
            ->whereIn('r.status', OrderReturn::LIVE)
            ->when($ignoreReturnId, function ($q) use ($ignoreReturnId) {
                $q->where('r.id', '!=', $ignoreReturnId);
            })
            ->get(['order_return_items.*', 'r.status as return_status']);

        $out = [];

        foreach ($rows as $row) {
            $id = $row->ordered_product_id;
            $out[$id] = $out[$id] ?? ['live_qty' => 0, 'done_qty' => 0, 'done_refund' => 0, 'done_gift' => 0];
            $out[$id]['live_qty'] += (int)$row->quantity;

            if (in_array($row->return_status, OrderReturn::DONE, true)) {
                $out[$id]['done_qty'] += (int)$row->quantity;
                $out[$id]['done_refund'] += Pricing::toMinorUnits($row->line_refund);
                $out[$id]['done_gift'] += Pricing::toMinorUnits($row->gift_card_amount);
            }
        }

        return $out;
    }
}
