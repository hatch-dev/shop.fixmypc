<?php

namespace App\Models\Helper;

use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\OrderedVoucher;
use App\Models\OrderReturn;
use App\Models\OrderReturnHistory;
use App\Models\OrderReturnItem;
use App\Models\OrderStatusHistory;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\UpdatedInventory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every move a return case can make, and what each one does to stock, money,
 * points and the order.
 *
 *   open      a delivered, paid order; the items and how many of each
 *   receive   the parcel arrived; per item, back on the shelf or not
 *   refund    money back by the original method, recorded as refunded
 *             elsewhere ("manual"), or nothing ("none" -> closed)
 *   reject    before the parcel arrives
 *
 * When the last unit of the order has been returned, the order itself moves
 * to RETURNED, through the same restoreStockAndRewards() every other route
 * uses -- which nets off what the cases already did.
 *
 * A refusal is thrown as ReturnRefused with a message staff can act on.
 */
class OrderReturns
{
    const METHODS = ['original', 'manual', 'none'];

    /** Days after delivery a return is normally accepted. Advisory: staff can go past it. */
    public static function windowDays()
    {
        return (int)Config::get('constants.returns.WINDOW_DAYS', 30);
    }

    /**
     * Can a return be opened on this order, and how long ago was it delivered.
     */
    public static function eligibility(Order $order, $lang = null)
    {
        $deliveredAt = OrderStatusHistory::where('order_id', $order->id)
            ->where('to_status', (int)Config::get('constants.orderStatus.DELIVERED'))
            ->max('created_at');

        $days = $deliveredAt ? (int)\Carbon\Carbon::parse($deliveredAt)->diffInDays(now()) : null;

        $reason = null;

        if ((int)$order->status !== (int)Config::get('constants.orderStatus.DELIVERED')) {
            $reason = __('lang.return_not_delivered', [], $lang);
        } elseif ((int)$order->payment_done !== 1) {
            $reason = __('lang.return_not_paid', [], $lang);
        } elseif ($order->isCancelled()) {
            $reason = __('lang.return_order_cancelled', [], $lang);
        }

        return [
            'ok' => is_null($reason),
            'reason' => $reason,
            'delivered_at' => $deliveredAt,
            'days_since_delivery' => $days,
            'window_days' => self::windowDays(),
            'within_window' => is_null($days) ? null : $days <= self::windowDays(),
        ];
    }

    /**
     * @param  array        $quantities  {ordered_product_id: qty}
     * @param  string|null  $lang        the language the customer's emails go out in
     * @param  array        $exchangeFor {ordered_product_id: inventory_id} -- given, the case is an
     *                                   exchange and every chosen line needs a variant to swap to
     */
    public static function open(Order $order, array $quantities, $reason, $customerNote, $staffNote, $adminId, $notify = true, $lang = null, array $exchangeFor = [], array $options = [])
    {
        // 'by_customer' => true: the customer asked from their order page (request()).
        $byCustomer = !empty($options['by_customer']);
        $startStatus = $byCustomer ? OrderReturn::REQUESTED : OrderReturn::AWAITING;
        $lang = self::language($lang);
        $exchangeFor = array_filter(array_map('intval', $exchangeFor));
        $type = $exchangeFor ? OrderReturn::TYPE_EXCHANGE : OrderReturn::TYPE_RETURN;
        // A refusal reaches the customer in their language; staff screens stay English.
        $msgLang = $byCustomer ? $lang : null;

        if (!array_key_exists($reason, OrderReturn::REASONS)) {
            throw new ReturnRefused(__('lang.return_choose_reason', [], $msgLang));
        }

        $quantities = array_filter(array_map('intval', $quantities), function ($q) { return $q > 0; });

        if (!$quantities) {
            throw new ReturnRefused(__('lang.return_choose_items', [], $msgLang));
        }

        $return = DB::transaction(function () use ($order, $quantities, $reason, $customerNote, $staffNote, $adminId, $lang, $type, $exchangeFor, $byCustomer, $startStatus, $msgLang) {

            // Two staff opening a return on the same order at once must not both get the last unit.
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            $eligible = self::eligibility($order, $msgLang);
            if (!$eligible['ok']) {
                throw new ReturnRefused($eligible['reason']);
            }

            // Staff may go past the window; a customer may not.
            if ($byCustomer && $eligible['within_window'] === false) {
                throw new ReturnRefused(__('lang.return_window_passed', ['days' => $eligible['window_days']], $lang));
            }

            $lines = ReturnRefundCalculator::lines($order);

            foreach ($quantities as $id => $qty) {
                if (!isset($lines[$id])) {
                    throw new ReturnRefused(__('lang.return_item_not_on_order', [], $msgLang));
                }
                if ($qty > $lines[$id]['returnable_quantity']) {
                    throw new ReturnRefused(__('lang.return_too_many_units', [], $msgLang));
                }
                /*
                 * A product marked non-refundable is not taken back for a refund
                 * because the customer changed their mind. A fault, damage or a
                 * wrong item is the shop's to put right whatever the flag says,
                 * and an exchange is not a refund.
                 */
                if ($byCustomer && $type === OrderReturn::TYPE_RETURN
                    && in_array($reason, ['changed_mind', 'other'], true)
                    && !(OrderReturns::itemDetails([$id])[$id]['refundable'] ?? true)) {
                    throw new ReturnRefused(__('lang.return_not_refundable', [], $lang));
                }

                if ($type === OrderReturn::TYPE_EXCHANGE) {
                    $line = OrderedProduct::find($id);
                    if (empty($exchangeFor[$id]) || !OrderExchanges::validTarget($line, $exchangeFor[$id])) {
                        throw new ReturnRefused(__('lang.return_exchange_variant', [], $msgLang));
                    }
                }
            }

            $return = OrderReturn::create([
                'rma_number' => OrderReturn::newRmaNumber(),
                'type' => $type,
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'status' => $startStatus,
                'reason' => $reason,
                'lang' => $lang,
                'customer_note' => $customerNote ?: null,
                'staff_note' => $staffNote ?: null,
                'created_by' => $adminId,
            ]);

            foreach ($quantities as $id => $qty) {
                OrderReturnItem::create([
                    'order_return_id' => $return->id,
                    'ordered_product_id' => $id,
                    'product_id' => $lines[$id]['product_id'],
                    'inventory_id' => $lines[$id]['inventory_id'],
                    'exchange_inventory_id' => $type === OrderReturn::TYPE_EXCHANGE ? $exchangeFor[$id] : null,
                    'quantity' => $qty,
                ]);
            }

            self::history($return, null, $startStatus, $adminId, ($type === OrderReturn::TYPE_EXCHANGE ? 'Exchange ' : 'Return ')
                . ($byCustomer ? 'requested by the customer' : 'opened'));

            return $return;
        });

        if ($byCustomer) {
            self::email($return, OrderEmailEvent::RETURN_REQUESTED);

            try {
                OrderMailer::staffReturnRequest($return->fresh('items'));
            } catch (\Throwable $e) {
                // The request stands; the alert is a courtesy.
                Log::warning('Return request staff alert not sent', ['return_id' => $return->id, 'message' => $e->getMessage()]);
            }
        } elseif ($notify) {
            self::email($return, OrderEmailEvent::RETURN_OPENED);
        }

        return $return->fresh('items');
    }

    /**
     * The customer's own request, from their order page. Same rules as staff
     * opening one, plus the return window and the non-refundable flag.
     */
    public static function request(Order $order, array $quantities, array $exchangeFor, $reason, $note, $lang = null)
    {
        return self::open($order, $quantities, $reason, $note, null, null, false, $lang, $exchangeFor, ['by_customer' => true]);
    }

    /** Staff accept a customer's request: it now waits for the parcel. */
    public static function approve(OrderReturn $return, $note, $adminId, $notify = true)
    {
        $approved = DB::transaction(function () use ($return, $note, $adminId) {
            $return = OrderReturn::where('id', $return->id)->lockForUpdate()->first();

            if ($return->status !== OrderReturn::REQUESTED) {
                throw new ReturnRefused('Only a request waiting for review can be approved.');
            }

            $return->status = OrderReturn::AWAITING;
            $return->save();

            self::history($return, OrderReturn::REQUESTED, OrderReturn::AWAITING, $adminId, trim('Approved. ' . $note));

            return $return;
        });

        if ($notify) {
            self::email($approved, OrderEmailEvent::RETURN_OPENED);
        }

        return $approved->fresh('items');
    }

    /**
     * The customer withdraws it, before anything has come back. Frees the units.
     */
    public static function cancelByCustomer(OrderReturn $return)
    {
        return DB::transaction(function () use ($return) {
            $return = OrderReturn::where('id', $return->id)->lockForUpdate()->first();

            if (!in_array($return->status, [OrderReturn::REQUESTED, OrderReturn::AWAITING], true)) {
                throw new ReturnRefused(__('lang.return_cannot_cancel', [], $return->lang));
            }

            $from = $return->status;
            $return->status = OrderReturn::CANCELLED;
            $return->closed_at = now();
            $return->save();

            self::history($return, $from, OrderReturn::CANCELLED, null, 'Cancelled by the customer');

            return $return->fresh('items');
        });
    }

    /**
     * The parcel is here. $restock is {order_return_items.id: bool}; an item
     * left out is not restocked.
     */
    public static function receive(OrderReturn $return, array $restock, $note, $adminId)
    {
        return DB::transaction(function () use ($return, $restock, $note, $adminId) {

            $return = OrderReturn::where('id', $return->id)->lockForUpdate()->first();

            if (!in_array($return->status, [OrderReturn::AWAITING, OrderReturn::REQUESTED], true)) {
                throw new ReturnRefused('This return has already been received.');
            }

            $from = $return->status;

            foreach ($return->items as $item) {
                $back = !empty($restock[$item->id]);

                $item->restock = $back;

                if ($back && $item->inventory_id) {
                    UpdatedInventory::where('id', $item->inventory_id)->increment('quantity', $item->quantity);
                    $item->restocked_qty = $item->quantity;
                }

                $item->save();
            }

            $return->status = OrderReturn::RECEIVED;
            $return->received_at = now();
            $return->save();

            $restocked = $return->items->where('restock', true)->sum('quantity');
            self::history($return, $from, OrderReturn::RECEIVED, $adminId,
                trim('Received; ' . $restocked . ' of ' . $return->items->sum('quantity') . ' unit(s) back in stock. ' . $note));

            return $return->fresh('items');
        });
    }

    /**
     * Pay the return back.
     *
     * @param  string      $method          original | manual | none
     * @param  float|null  $amount          less than the quote (a deduction), or null for the quote
     * @param  bool        $refundShipping  give back the delivery charge too
     * @param  string|null $reference       where a manual refund was made
     */
    public static function refund(OrderReturn $return, $method, $amount, $refundShipping, $reference, $note, $adminId)
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new ReturnRefused('Choose how the refund is made.');
        }

        if ($method === 'manual' && trim((string)$reference) === '') {
            throw new ReturnRefused('Enter where the refund was made, for example the bank transfer reference.');
        }

        $order = Order::find($return->order_id);

        $quantities = $return->items->pluck('quantity', 'ordered_product_id')->all();
        $quote = ReturnRefundCalculator::quote($order, $quantities, (bool)$refundShipping, $return->id);

        if ($method === 'none') {
            $amount = 0.0;
        } elseif (is_null($amount) || $amount === '') {
            $amount = $quote['refund'];
        } else {
            $amount = Pricing::round($amount);

            if ($amount < 0 || $amount > $quote['refund'] + 0.001) {
                throw new ReturnRefused('The refund can be at most ' . number_format($quote['refund'], 2) . '.');
            }
        }

        if ($method === 'original' && $amount > 0
            && (int)$order->order_method === (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY')) {
            throw new ReturnRefused('Cash on delivery cannot be refunded automatically. Refund it yourself and record it as a manual refund.');
        }

        // The claim: only one request gets to move money for this case.
        $claimed = OrderReturn::where('id', $return->id)
            ->where('status', OrderReturn::RECEIVED)
            ->update(['status' => OrderReturn::REFUNDING, 'refund_error' => null]);

        if (!$claimed) {
            throw new ReturnRefused('This return must be received first, and can be refunded once.');
        }

        $referenceOut = $method === 'manual' ? trim((string)$reference) : null;

        if ($method === 'original' && $amount > 0) {
            $result = Refunder::refund($order, $amount, [
                'key' => 'refund-return-' . $return->id,
                'refunded' => ReturnRefundCalculator::moneyRefunded($order),
            ]);

            if (!$result['ok']) {
                OrderReturn::where('id', $return->id)->update([
                    'status' => OrderReturn::RECEIVED,
                    'refund_error' => $result['reason'],
                ]);

                Log::error('Return refund failed', ['return_id' => $return->id, 'reason' => $result['reason']]);

                throw new ReturnRefused('The refund did not go through: ' . $result['reason']
                    . '. Nothing was recorded; you can retry, or refund it yourself and record it as manual.');
            }

            $referenceOut = $result['reference'];
        }

        try {
            DB::transaction(function () use ($return, $order, $method, $amount, $quote, $refundShipping, $referenceOut, $note, $adminId) {

                $paysBack = $method !== 'none';

                /*
                 * The item figures are the share of the order these units used
                 * up, written even when nothing is paid back, so a later return
                 * of the rest of the line is not handed this one's share too.
                 */
                foreach ($return->items as $item) {
                    $line = $quote['items'][$item->ordered_product_id] ?? null;
                    $item->line_refund = $line ? $line['refund'] : 0;
                    $item->gift_card_amount = $line ? $line['gift_card'] : 0;
                    $item->save();
                }

                $gift = $paysBack ? $quote['gift_card'] : 0.0;

                if ($gift > 0) {
                    OrderedVoucher::where('order_id', $order->id)->where('voucher_id', $order->voucher_id)
                        ->limit(1)->increment('returned_amount', $gift);
                }

                $pointsBack = $paysBack ? $quote['points_returned'] : 0;
                $pointsTaken = $paysBack ? min($quote['points_taken'], PointTransaction::balanceFor($order->user_id) + $pointsBack) : 0;

                self::points($order, 'credit', $pointsBack);
                self::points($order, 'debit', $pointsTaken);

                $to = $paysBack ? OrderReturn::REFUNDED : OrderReturn::CLOSED;

                OrderReturn::where('id', $return->id)->update([
                    'status' => $to,
                    'refund_method' => $method,
                    'refund_amount' => $amount,
                    'shipping_refund' => $refundShipping ? $quote['shipping_refund'] : 0,
                    'gift_card_amount' => $gift,
                    'points_returned' => $pointsBack,
                    'points_taken' => $pointsTaken,
                    'refund_reference' => $referenceOut,
                    'refunded_at' => $paysBack ? now() : null,
                    'closed_at' => now(),
                ]);

                $what = $paysBack
                    ? 'Refunded ' . number_format($amount, 2) . ' (' . $method . ($referenceOut ? ', ' . $referenceOut : '') . ')'
                    : 'Closed without a refund';

                self::history($return, OrderReturn::RECEIVED, $to, $adminId, trim($what . '. ' . $note));

                self::completeOrderIfFullyReturned($order, $return, $adminId);
            });

        } catch (\Throwable $e) {
            /*
             * The money may already have gone. Leave the case in REFUNDING so
             * it cannot be refunded a second time, and say so loudly.
             */
            OrderReturn::where('id', $return->id)->update([
                'refund_error' => 'Money sent (' . ($referenceOut ?: 'no reference') . ') but recording it failed: '
                    . substr($e->getMessage(), 0, 500),
            ]);

            Log::critical('Return refund recorded badly', ['return_id' => $return->id, 'message' => $e->getMessage()]);

            throw new ReturnRefused('The refund was sent but could not be recorded. Do not refund again; check the case and the logs.');
        }

        $fresh = $return->fresh('items');

        if ($fresh->status === OrderReturn::REFUNDED) {
            self::email($fresh, OrderEmailEvent::RETURN_REFUNDED);
        }

        return $fresh;
    }

    public static function reject(OrderReturn $return, $note, $adminId, $notify = true)
    {
        $rejected = DB::transaction(function () use ($return, $note, $adminId) {

            $return = OrderReturn::where('id', $return->id)->lockForUpdate()->first();

            if (!in_array($return->status, [OrderReturn::AWAITING, OrderReturn::REQUESTED], true)) {
                throw new ReturnRefused('Only a return that has not arrived yet can be rejected.');
            }

            $from = $return->status;
            $return->status = OrderReturn::REJECTED;
            $return->closed_at = now();
            $return->save();

            self::history($return, $from, OrderReturn::REJECTED, $adminId, $note ?: 'Rejected');

            return $return;
        });

        if ($notify) {
            self::email($rejected, OrderEmailEvent::RETURN_REJECTED);
        }

        return $rejected->fresh('items');
    }

    public static function addNote(OrderReturn $return, $note, $adminId)
    {
        $note = trim((string)$note);

        if ($note === '') {
            throw new ReturnRefused('Write a note first.');
        }

        self::history($return, $return->status, $return->status, $adminId, $note);

        return $return->fresh('items');
    }

    /**
     * Everything the order and items need on screen, plus the quote for what
     * a refund would be now.
     */
    public static function present(OrderReturn $return)
    {
        $return->loadMissing(['items', 'history.admin:id,name']);

        $order = Order::find($return->order_id);
        $products = self::itemDetails($return->items->pluck('ordered_product_id')->all());

        $quote = null;
        $contact = $order ? $order->contactDetails() : [];

        $exchangeQuote = null;

        if (in_array($return->status, [OrderReturn::REQUESTED, OrderReturn::AWAITING, OrderReturn::RECEIVED], true) && $order) {
            $quantities = $return->items->pluck('quantity', 'ordered_product_id')->all();
            $quote = [
                'without_shipping' => ReturnRefundCalculator::quote($order, $quantities, false, $return->id),
                'with_shipping' => ReturnRefundCalculator::quote($order, $quantities, true, $return->id),
            ];

            if ($return->isExchange()) {
                $exchangeQuote = OrderExchanges::quote($return);
            }
        }

        $replacement = $return->replacement_order_id ? Order::find($return->replacement_order_id) : null;
        $targets = UpdatedInventory::whereIn('id', $return->items->pluck('exchange_inventory_id')->filter())
            ->with('inventory_attributes.attribute_value')->get()->keyBy('id');

        return [
            'id' => $return->id,
            'rma_number' => $return->rma_number,
            'type' => $return->type,
            'status' => $return->status,
            'status_label' => $return->statusLabel(),
            'exchange_difference' => $return->exchange_difference,
            'amount_charged' => $return->amount_charged,
            'payment_reference' => $return->payment_reference,
            'replacement_order' => $replacement ? ['id' => $replacement->id, 'order' => $replacement->order,
                'status' => (int)$replacement->status, 'total_amount' => (float)$replacement->total_amount] : null,
            'exchange_quote' => $exchangeQuote,
            'pay_link' => ($link = \App\Models\PayLink::where('order_return_id', $return->id)->latest('id')->first()) ? [
                'status' => $link->status === \App\Models\PayLink::PENDING && $link->isExpired() ? 'expired' : $link->status,
                'amount' => $link->amount,
                'url' => $link->status === \App\Models\PayLink::PENDING ? PayLinks::url($link) : null,
                'gateway' => (int)$link->gateway === (int)Config::get('constants.paymentMethod.STRIPE') ? 'Stripe' : 'SumUp',
                'started' => !empty($link->gateway_session),
                'expires_at' => (string)$link->expires_at,
                'paid_at' => $link->paid_at ? (string)$link->paid_at : null,
                'reference' => $link->gateway_reference,
            ] : null,
            'reason' => $return->reason,
            'reason_label' => $return->reasonLabel(),
            'lang' => $return->lang,
            'customer_note' => $return->customer_note,
            'staff_note' => $return->staff_note,
            'refund_method' => $return->refund_method,
            'refund_amount' => $return->refund_amount,
            'shipping_refund' => $return->shipping_refund,
            'gift_card_amount' => $return->gift_card_amount,
            'points_returned' => $return->points_returned,
            'points_taken' => $return->points_taken,
            'refund_reference' => $return->refund_reference,
            'refund_error' => $return->refund_error,
            'created_at' => (string)$return->created_at,
            'received_at' => $return->received_at ? (string)$return->received_at : null,
            'refunded_at' => $return->refunded_at ? (string)$return->refunded_at : null,
            'closed_at' => $return->closed_at ? (string)$return->closed_at : null,
            'order' => $order ? [
                'id' => $order->id,
                'order' => $order->order,
                'status' => (int)$order->status,
                'order_method' => (int)$order->order_method,
                'total_amount' => (float)$order->total_amount,
                'wallet_amount' => (float)$order->wallet_amount,
                'contact_name' => $contact['name'] ?? null,
                'contact_email' => $contact['email'] ?? null,
                'cash_on_delivery' => (int)$order->order_method === (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY'),
            ] : null,
            'items' => $return->items->map(function ($item) use ($products, $targets) {
                $target = $item->exchange_inventory_id ? ($targets[$item->exchange_inventory_id] ?? null) : null;

                return array_merge($products[$item->ordered_product_id] ?? [], [
                    'id' => $item->id,
                    'exchange_inventory_id' => $item->exchange_inventory_id,
                    'exchange_label' => $target ? (Variant::label($target, false) ?: '#' . $target->id) : null,
                    'exchange_unit_price' => $item->exchange_unit_price,
                    'ordered_product_id' => $item->ordered_product_id,
                    'quantity' => $item->quantity,
                    'restock' => $item->restock,
                    'restocked_qty' => $item->restocked_qty,
                    'line_refund' => $item->line_refund,
                    'gift_card_amount' => $item->gift_card_amount,
                ]);
            })->values(),
            'quote' => $quote,
            'history' => $return->history->map(function ($h) {
                return [
                    'from' => $h->from_status,
                    'to' => $h->to_status,
                    'note' => $h->note,
                    'admin' => optional($h->admin)->name,
                    'at' => (string)$h->created_at,
                ];
            })->values(),
        ];
    }

    /**
     * Name, variant, image and refundable flag for ordered lines, keyed by
     * ordered_products.id.
     */
    public static function itemDetails(array $orderedProductIds)
    {
        if (!$orderedProductIds) {
            return [];
        }

        $rows = OrderedProduct::whereIn('ordered_products.id', $orderedProductIds)
            ->leftJoin('products', 'products.id', '=', 'ordered_products.product_id')
            ->with('updated_inventory.inventory_attributes.attribute_value.attribute')
            ->get(['ordered_products.*', 'products.title as product_title', 'products.image as product_image',
                'products.refundable as product_refundable']);

        $out = [];

        foreach ($rows as $row) {
            $variant = optional($row->updated_inventory)->inventory_attributes;

            $out[$row->id] = [
                'title' => $row->product_title ?: 'Product #' . $row->product_id,
                'image' => $row->product_image,
                'variant' => $variant ? $variant->map(function ($a) {
                    return optional($a->attribute_value)->title;
                })->filter()->implode(' / ') : '',
                'refundable' => (int)$row->product_refundable === (int)Config::get('constants.status.PUBLIC'),
                'unit_price' => (float)$row->selling,
            ];
        }

        return $out;
    }

    /**
     * All units of every line returned on finished cases: the order is
     * returned. The order email for that status is not sent -- the return
     * email the customer just got says it better.
     */
    private static function completeOrderIfFullyReturned(Order $order, OrderReturn $return, $adminId)
    {
        /*
         * Units given back on finished cases, per line. Exchanged units do not
         * count: they were swapped, and the order they came from still stands
         * (its gift card spend, loyalty discount and revenue included).
         */
        $back = OrderReturnItem::join('order_returns as r', 'r.id', '=', 'order_return_items.order_return_id')
            ->where('r.order_id', $order->id)
            ->whereIn('r.status', OrderReturn::GIVEN_BACK)
            ->groupBy('order_return_items.ordered_product_id')
            ->selectRaw('order_return_items.ordered_product_id as line_id, SUM(order_return_items.quantity) as qty')
            ->pluck('qty', 'line_id');

        foreach (OrderedProduct::where('order_id', $order->id)->get(['id', 'quantity']) as $line) {
            if ((int)($back[$line->id] ?? 0) < (int)$line->quantity) {
                return;
            }
        }

        $returned = (int)Config::get('constants.orderStatus.RETURNED');
        $from = (int)$order->status;

        if (!Order::canTransition($from, $returned)) {
            return;
        }

        Order::where('id', $order->id)->update(['status' => $returned]);

        // Before the history row: it decides "already released" from history.
        $order->restoreStockAndRewards();
        $order->refreshFlashAllowances();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $returned,
            'changed_by' => $adminId,
            'changed_by_type' => 'admin',
            'note' => 'Every item returned (' . $return->rma_number . ')',
        ]);
    }

    private static function points(Order $order, $type, $points)
    {
        if ($points <= 0 || !$order->user_id) {
            return;
        }

        PointTransaction::create([
            'user_id' => $order->user_id,
            'type' => $type,
            'points' => $points,
            'value' => round($points * 0.01, 2),
            'source' => 'order_return',
            'reference_id' => $order->id,
        ]);
    }

    private static function history(OrderReturn $return, $from, $to, $adminId, $note = null)
    {
        OrderReturnHistory::create([
            'order_return_id' => $return->id,
            'from_status' => $from,
            'to_status' => $to,
            'admin_id' => $adminId,
            'note' => $note ? mb_substr($note, 0, 500) : null,
        ]);
    }

    private static function email(OrderReturn $return, $event)
    {
        OrderEmailDispatcher::dispatch($return->order_id, OrderEmailEvent::forReturn($event, $return->id), $return->lang);
    }

    /** A language the store has, or null for its default. */
    public static function language($lang)
    {
        $lang = strtolower(trim((string)$lang));

        if ($lang === '') {
            return null;
        }

        return \App\Models\Language::where('code', $lang)->exists() ? $lang : null;
    }

    /** Product names for an order's lines, in the customer's language where translated. */
    public static function forCustomerTitles(Order $order, $lang = null)
    {
        return OrderedProduct::where('ordered_products.order_id', $order->id)
            ->join('products', 'products.id', '=', 'ordered_products.product_id')
            ->leftJoin('product_langs as pl', function ($join) use ($lang) {
                $join->on('pl.product_id', '=', 'products.id')->where('pl.lang', (string)$lang);
            })
            ->selectRaw('ordered_products.id as line_id, COALESCE(pl.title, products.title) as line_title')
            ->pluck('line_title', 'line_id')
            ->all();
    }

    /**
     * An order's returns as its customer may see them, in their language.
     *
     * Staff notes, admin names and the history are left out, and money is
     * shown only once it has actually gone back. "Refunding" is an internal
     * claim and reads as a refund in progress.
     */
    public static function forCustomer(Order $order, $lang = null)
    {
        $returns = OrderReturn::with('items')->where('order_id', $order->id)->orderByDesc('id')->get();

        if ($returns->isEmpty()) {
            return [];
        }

        $names = OrderedProduct::whereIn('ordered_products.id', $returns->pluck('items')->flatten()->pluck('ordered_product_id'))
            ->join('products', 'products.id', '=', 'ordered_products.product_id')
            ->leftJoin('product_langs as pl', function ($join) use ($lang) {
                $join->on('pl.product_id', '=', 'products.id')->where('pl.lang', (string)$lang);
            })
            // pluck() cannot take an expression: select it under a name first.
            ->selectRaw('ordered_products.id as line_id, COALESCE(pl.title, products.title) as line_title')
            ->pluck('line_title', 'line_id');

        $targets = UpdatedInventory::whereIn('id', $returns->pluck('items')->flatten()->pluck('exchange_inventory_id')->filter())
            ->with('inventory_attributes.attribute_value')->get()->keyBy('id');
        $replacements = Order::whereIn('id', $returns->pluck('replacement_order_id')->filter())->get(['id', 'order'])->keyBy('id');

        return $returns->map(function (OrderReturn $r) use ($names, $lang, $targets, $replacements) {
            $paid = in_array($r->status, OrderReturn::MONEY_BACK, true);
            $replacement = $r->replacement_order_id ? ($replacements[$r->replacement_order_id] ?? null) : null;

            return [
                'id' => $r->id,
                'rma_number' => $r->rma_number,
                'can_cancel' => in_array($r->status, [OrderReturn::REQUESTED, OrderReturn::AWAITING], true),
                'pay_url' => $r->status === OrderReturn::AWAITING_PAYMENT ? PayLinks::urlForReturn($r) : null,
                'type' => $r->type,
                'type_label' => __('lang.return_type_' . $r->type, [], $lang),
                'status' => $r->status,
                'status_label' => __('lang.return_status_' . $r->status, [], $lang),
                // Return progress and refund progress, separately (OrderState).
                'return_key' => OrderState::returnKey($r->status),
                'refund_key' => OrderState::caseRefundKey($r),
                'replacement_order' => $replacement ? ['id' => $replacement->id, 'order' => $replacement->order] : null,
                'amount_charged' => $r->status === OrderReturn::EXCHANGED ? $r->amount_charged : null,
                'reason_label' => __('lang.return_reason_' . $r->reason, [], $lang),
                'items' => $r->items->map(function ($item) use ($names, $targets) {
                    $target = $item->exchange_inventory_id ? ($targets[$item->exchange_inventory_id] ?? null) : null;

                    return [
                        'ordered_product_id' => $item->ordered_product_id,
                        'title' => $names[$item->ordered_product_id] ?? '',
                        'quantity' => $item->quantity,
                        'exchange_label' => $target ? Variant::label($target, false) : null,
                    ];
                })->values(),
                // A cheaper exchange pays back too; a refund of 0 is not worth stating.
                'refund_amount' => $paid && ($r->status === OrderReturn::REFUNDED || $r->refund_amount > 0) ? $r->refund_amount : null,
                'gift_card_amount' => $paid ? $r->gift_card_amount : null,
                'points_returned' => $paid ? $r->points_returned : null,
                'created_at' => optional($r->created_at)->toIso8601String(),
                'received_at' => optional($r->received_at)->toIso8601String(),
                'refunded_at' => optional($r->refunded_at)->toIso8601String(),
                'closed_at' => optional($r->closed_at)->toIso8601String(),
            ];
        })->values()->all();
    }
}
