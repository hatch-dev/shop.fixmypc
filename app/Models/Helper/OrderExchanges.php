<?php

namespace App\Models\Helper;

use App\Models\BusinessProductPricing;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\OrderedProductsInventory;
use App\Models\OrderReturn;
use App\Models\OrderReturnHistory;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Exchanges: a return case whose units are swapped for another variant of
 * the same product (agreed 2026-10-19: same product only; staff record any
 * payment for a dearer variant; nothing ships until the old unit is back).
 *
 * The swap is a NEW order, never an edit of the old one. The old order keeps
 * its rows and its revenue -- the sale still stands -- and the replacement
 * order carries only what the customer paid on top, so every order's money
 * is still read from its own rows (OrderSummary). A cheaper variant pays the
 * difference back through Refunder, recorded as the case's refund_amount.
 *
 * Prices: the old unit is what this order charged for it; the new one is
 * what the shop charges this customer for that variant today (Pricing, with
 * their business bands). Both are compared gross.
 */
class OrderExchanges
{
    /**
     * Other variants of each ordered product, priced for this customer.
     * Keyed by ordered_products.id.
     */
    public static function variantsFor(Order $order)
    {
        $order->loadMissing('ordered_products');
        $customer = $order->user_id ? User::find($order->user_id) : null;
        $out = [];

        foreach ($order->ordered_products as $line) {
            $others = UpdatedInventory::where('product_id', $line->product_id)
                ->where('id', '!=', (int)$line->inventory_id)
                ->with('inventory_attributes.attribute_value')
                ->get();

            $out[$line->id] = $others->map(function ($inventory) use ($order, $line, $customer) {
                return [
                    'inventory_id' => (int)$inventory->id,
                    'label' => Variant::label($inventory, false) ?: ('#' . $inventory->id),
                    'unit_price' => self::newUnitPrice($line, $inventory, $customer),
                    'quantity' => (int)$inventory->quantity,
                    // qty 0 with is_active 1 is a backorder, and sellable
                    'available' => (int)$inventory->quantity > 0 || (int)$inventory->is_active === 1,
                ];
            })->values()->all();
        }

        return $out;
    }

    /** Is $inventoryId a variant this line can be swapped for. */
    public static function validTarget(OrderedProduct $line, $inventoryId)
    {
        return (int)$inventoryId !== (int)$line->inventory_id
            && UpdatedInventory::where('id', (int)$inventoryId)->where('product_id', $line->product_id)->exists();
    }

    /** What one unit of the line cost the customer, gross. */
    public static function oldUnitPrice(OrderedProduct $line)
    {
        $selling = (float)$line->selling;

        // An ex-VAT line had its VAT added per unit as tax_price.
        return Pricing::round((int)$line->tax_inclusive === 0 ? $selling + (float)$line->tax_price : $selling);
    }

    /** What the shop charges this customer for one unit of $inventory today, gross. */
    public static function newUnitPrice(OrderedProduct $line, $inventory, $customer = null)
    {
        $product = Product::pricingRecord($line->product_id);
        $tiers = BusinessProductPricing::forCustomer($customer, $line->product_id);
        $price = Pricing::unitPrice($product, $inventory, 1, $tiers);

        // Same product, so the same VAT treatment as the line it replaces.
        if ((int)$line->tax_inclusive === 0 && !is_null($line->tax_rate)) {
            $price = $price * (1 + (float)$line->tax_rate / 100);
        }

        return Pricing::round($price);
    }

    /**
     * The difference the swap makes. > 0 the customer pays it, < 0 they are
     * owed it (never more than the order has left to give back).
     */
    public static function quote(OrderReturn $return)
    {
        $return->loadMissing('items');
        $order = Order::find($return->order_id);
        $customer = $order && $order->user_id ? User::find($order->user_id) : null;
        $lines = OrderedProduct::whereIn('id', $return->items->pluck('ordered_product_id'))->get()->keyBy('id');

        $items = [];
        $difference = 0;
        $inStock = true;

        foreach ($return->items as $item) {
            $line = $lines[$item->ordered_product_id] ?? null;
            $inventory = $item->exchange_inventory_id ? UpdatedInventory::find($item->exchange_inventory_id) : null;

            if (!$line || !$inventory) {
                continue;
            }

            $old = self::oldUnitPrice($line);
            $new = self::newUnitPrice($line, $inventory, $customer);
            $available = (int)$inventory->quantity >= $item->quantity || (int)$inventory->is_active === 1;
            $inStock = $inStock && $available;

            $items[$item->id] = [
                'item_id' => $item->id,
                'exchange_inventory_id' => (int)$inventory->id,
                'label' => Variant::label($inventory, false) ?: ('#' . $inventory->id),
                'quantity' => $item->quantity,
                'old_unit_price' => $old,
                'new_unit_price' => $new,
                'difference' => Pricing::round(($new - $old) * $item->quantity),
                'available' => $available,
                'stock' => (int)$inventory->quantity,
            ];

            $difference += Pricing::toMinorUnits(($new - $old) * $item->quantity);
        }

        $moneyLeft = $order ? ReturnRefundCalculator::moneyLeft($order) : 0;

        return [
            'items' => $items,
            'difference' => $difference / 100,
            // What can actually be paid back on a cheaper swap.
            'refundable' => $difference < 0 ? min(-$difference / 100, $moneyLeft) : 0,
            'money_left' => $moneyLeft,
            'in_stock' => $inStock,
        ];
    }

    /**
     * Make the swap: take the new units, create the replacement order, and
     * settle the difference.
     *
     * @param  float|null  $amount     dearer: what was charged (<= difference, null = all of it);
     *                                 cheaper: what to pay back (<= refundable, null = all of it)
     * @param  string|null $reference  dearer: where the payment was taken; cheaper + manual: where it was refunded
     * @param  string      $method     cheaper only: original | manual | none
     * @param  array       $options    'pay_link' => PayLink: the customer paid the
     *                                 difference by link (PayLinks::confirm). Its
     *                                 amount stands, and its units are already held.
     */
    public static function complete(OrderReturn $return, $amount, $reference, $method, $note, $adminId, array $options = [])
    {
        if (!$return->isExchange()) {
            throw new ReturnRefused('This is a return, not an exchange.');
        }

        $link = $options['pay_link'] ?? null;
        $from = $link ? OrderReturn::AWAITING_PAYMENT : OrderReturn::RECEIVED;

        if (!$link && $return->status === OrderReturn::AWAITING_PAYMENT) {
            throw new ReturnRefused('A pay link is open on this exchange. Cancel it first to record the payment yourself.');
        }

        $quote = self::quote($return);
        $order = Order::find($return->order_id);

        if (count($quote['items']) !== $return->items->count()) {
            throw new ReturnRefused('Every item on an exchange needs a variant to swap to.');
        }

        // Units held by a pay link are out of stock because they are ours.
        $held = $link && $link->stock_reserved;

        if (!$held && !$quote['in_stock']) {
            throw new ReturnRefused('A variant to swap to is out of stock. Restock it, or refund the return instead.');
        }

        $difference = $link ? (float)$link->amount : $quote['difference'];
        $reference = trim((string)$reference);
        $charged = 0.0;
        $refund = 0.0;

        if ($link) {
            // What the customer was asked for, and paid. Prices may have moved since.
            $charged = (float)$link->amount;
        } elseif ($difference > 0) {
            $charged = is_null($amount) || $amount === '' ? $difference : Pricing::round($amount);
            if ($charged < 0 || $charged > $difference + 0.001) {
                throw new ReturnRefused('The amount charged can be at most ' . number_format($difference, 2) . '.');
            }
            if ($charged > 0 && $reference === '') {
                throw new ReturnRefused('Enter where the payment was taken, for example the card terminal receipt.');
            }
        } elseif ($difference < 0) {
            $method = in_array($method, ['original', 'manual', 'none'], true) ? $method : 'original';
            $refund = $method === 'none' ? 0.0 : (is_null($amount) || $amount === '' ? $quote['refundable'] : Pricing::round($amount));
            if ($refund < 0 || $refund > $quote['refundable'] + 0.001) {
                throw new ReturnRefused('The refund can be at most ' . number_format($quote['refundable'], 2) . '.');
            }
            if ($method === 'manual' && $refund > 0 && $reference === '') {
                throw new ReturnRefused('Enter where the refund was made.');
            }
            if ($method === 'original' && $refund > 0
                && (int)$order->order_method === (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY')) {
                throw new ReturnRefused('Cash on delivery cannot be refunded automatically. Refund it yourself and record it as manual.');
            }
        }

        // The claim: one request completes this case.
        $claimed = OrderReturn::where('id', $return->id)->where('status', $from)
            ->update(['status' => OrderReturn::REFUNDING, 'refund_error' => null]);

        if (!$claimed) {
            throw new ReturnRefused('The old item must be received first, and an exchange completes once.');
        }

        $refundReference = $method === 'manual' ? $reference : null;

        if ($refund > 0 && $method === 'original') {
            $result = Refunder::refund($order, $refund, [
                'key' => 'refund-exchange-' . $return->id,
                'refunded' => ReturnRefundCalculator::moneyRefunded($order),
            ]);

            if (!$result['ok']) {
                OrderReturn::where('id', $return->id)->update(['status' => OrderReturn::RECEIVED, 'refund_error' => $result['reason']]);
                throw new ReturnRefused('The refund of the difference did not go through: ' . $result['reason']
                    . '. Nothing was recorded; you can retry.');
            }

            $refundReference = $result['reference'];
        }

        try {
            $replacement = DB::transaction(function () use ($return, $order, $quote, $charged, $refund, $method, $reference, $refundReference, $note, $adminId, $difference, $held, $link, $from) {

                // Take the new units, refusing if another sale got there first
                // (unless a pay link already holds them).
                foreach ($held ? [] : $quote['items'] as $line) {
                    $taken = UpdatedInventory::where('id', $line['exchange_inventory_id'])
                        ->where(function ($q) use ($line) {
                            $q->where('quantity', '>=', $line['quantity'])->orWhere('is_active', 1);
                        })
                        ->decrement('quantity', $line['quantity']);

                    if (!$taken) {
                        throw new ReturnRefused('A variant to swap to sold out a moment ago.');
                    }
                }

                $replacement = self::replacementOrder($return, $order, $quote, $charged, $reference, $adminId,
                    $link ? $link->gateway : null);

                // The units used up their share of the old order, so a later
                // return of the rest of the line is not handed it too.
                $shares = ReturnRefundCalculator::quote($order, $return->items->pluck('quantity', 'ordered_product_id')->all(), false, $return->id);

                foreach ($return->items as $item) {
                    $share = $shares['items'][$item->ordered_product_id] ?? null;
                    $item->line_refund = $share ? $share['refund'] : 0;
                    $item->gift_card_amount = $share ? $share['gift_card'] : 0;
                    $item->exchange_unit_price = $quote['items'][$item->id]['new_unit_price'] ?? null;
                    $item->save();
                }

                OrderReturn::where('id', $return->id)->update([
                    'status' => OrderReturn::EXCHANGED,
                    'replacement_order_id' => $replacement->id,
                    'exchange_difference' => $difference,
                    // the replacement total: what its lines add up to after any rounding
                    'amount_charged' => (float)$replacement->total_amount,
                    'payment_reference' => $charged > 0 ? $reference : null,
                    'refund_method' => $difference < 0 ? $method : null,
                    'refund_amount' => $refund,
                    'refund_reference' => $refundReference,
                    'refunded_at' => $refund > 0 ? now() : null,
                    'closed_at' => now(),
                ]);

                $what = 'Exchanged; replacement order ' . $replacement->order;
                if ($charged > 0) {
                    $what .= ', charged ' . number_format((float)$replacement->total_amount, 2) . ' (' . $reference . ')';
                } elseif ($refund > 0) {
                    $what .= ', refunded ' . number_format($refund, 2) . ' (' . $method . ($refundReference ? ', ' . $refundReference : '') . ')';
                }

                OrderReturnHistory::create([
                    'order_return_id' => $return->id,
                    'from_status' => $from,
                    'to_status' => OrderReturn::EXCHANGED,
                    'admin_id' => $adminId,
                    'note' => mb_substr(trim($what . '. ' . $note), 0, 500),
                ]);

                return $replacement;
            });

        } catch (ReturnRefused $e) {
            // Nothing in the transaction stuck. Any refund already sent is the problem to flag.
            $sent = $refund > 0 && $method === 'original';
            OrderReturn::where('id', $return->id)->update([
                'status' => $sent ? OrderReturn::REFUNDING : $from,
                'refund_error' => $sent ? 'Difference refunded (' . $refundReference . ') but the exchange failed: ' . $e->getMessage() : null,
            ]);
            throw $e;

        } catch (\Throwable $e) {
            OrderReturn::where('id', $return->id)->update([
                'refund_error' => 'Exchange could not be recorded' . ($refundReference ? ' after refunding (' . $refundReference . ')' : '')
                    . ': ' . substr($e->getMessage(), 0, 400),
            ]);
            Log::critical('Exchange recorded badly', ['return_id' => $return->id, 'message' => $e->getMessage()]);

            throw new ReturnRefused('The exchange could not be recorded. Check the case and the logs before trying again.');
        }

        $replacement->refreshFlashAllowances();

        OrderEmailDispatcher::dispatch($return->order_id,
            OrderEmailEvent::forReturn(OrderEmailEvent::RETURN_EXCHANGED, $return->id), $return->lang);

        return $return->fresh('items');
    }

    /**
     * The order the new units go out on. Confirmed and paid, so it joins the
     * normal fulfilment queue; its lines carry the variant's price as the
     * list price and what was charged on top as the price, so its receipt
     * reads "worth X, you paid the difference".
     */
    private static function replacementOrder(OrderReturn $return, Order $order, array $quote, $charged, $reference, $adminId, $paidBy = null)
    {
        $lines = OrderedProduct::whereIn('id', $return->items->pluck('ordered_product_id'))->get()->keyBy('id');

        // Share what was charged across the dearer units, in cents.
        $weights = [];
        foreach ($return->items as $item) {
            $weights[$item->id] = max(0, Pricing::toMinorUnits($quote['items'][$item->id]['difference']));
        }

        // Prices can move after a pay link is sent; spread by units then rather than lose the money.
        if ($charged > 0 && array_sum($weights) === 0) {
            foreach ($return->items as $item) {
                $weights[$item->id] = (int)$item->quantity;
            }
        }

        $chargedCents = Pricing::toMinorUnits($charged);
        $sum = array_sum($weights);

        $replacement = Order::forceCreate([
            'order' => Utils::generateOrderNumber(),
            'user_id' => $order->user_id,
            'user_address_id' => $order->user_address_id,
            'contact_name' => $order->contact_name,
            'contact_email' => $order->contact_email,
            'contact_phone' => $order->contact_phone,
            'account_type' => $order->account_type,
            'company_name' => $order->company_name,
            'vat_number' => $order->vat_number,
            'cro_number' => $order->cro_number,
            'user_token' => $order->user_token,
            'currency' => $order->currency,
            // Paid by link: the card gateway that took it. Otherwise as the order it replaces.
            'order_method' => $paidBy ?: $order->order_method,
            'status' => (int)Config::get('constants.orderStatus.CONFIRMED'),
            'cancelled' => (int)Config::get('constants.status.PRIVATE'),
            'total_amount' => 0,
            'wallet_amount' => 0,
            'trans_id' => $charged > 0 ? mb_substr($reference, 0, 255) : 'EXCHANGE-' . $return->rma_number,
        ] + Order::paymentStamp(true));

        $total = 0;

        foreach ($return->items as $item) {
            $q = $quote['items'][$item->id];
            $old = $lines[$item->ordered_product_id];

            $lineCents = $sum > 0 ? intdiv($chargedCents * $weights[$item->id], $sum) : 0;
            $unit = Pricing::round($lineCents / 100 / max(1, $item->quantity));
            $total += Pricing::toMinorUnits($unit * $item->quantity);

            OrderedProduct::forceCreate([
                'order_id' => $replacement->id,
                'product_id' => $old->product_id,
                'inventory_id' => $q['exchange_inventory_id'],
                'quantity' => $item->quantity,
                'selling' => $unit,
                // stated gross, so the replacement's total is exactly its lines
                'tax_price' => 0,
                'tax_rate' => $old->tax_rate,
                'tax_inclusive' => 1,
                'bundle_offer' => 0,
                'shipping_place_id' => $old->shipping_place_id,
                'shipping_type' => $old->shipping_type,
            ]);

            OrderedProductsInventory::forceCreate([
                'order_id' => $replacement->id,
                'product_id' => $old->product_id,
                'inventory_id' => $q['exchange_inventory_id'],
                'quantity' => $item->quantity,
                'actual_price' => $q['new_unit_price'],
                'price' => $unit,
                'shipping_price' => 0,
                'product_voucher_discount_applied' => 0,
            ]);
        }

        /*
         * The order total is what its lines add up to. Splitting an odd
         * amount across a multi-unit line can lose a cent to rounding; the
         * case's amount_charged is set to the same figure so the two agree.
         */
        Order::where('id', $replacement->id)->update(['total_amount' => $total / 100]);

        OrderStatusHistory::create([
            'order_id' => $replacement->id,
            'from_status' => (int)Config::get('constants.orderStatus.PENDING'),
            'to_status' => (int)Config::get('constants.orderStatus.CONFIRMED'),
            'changed_by' => $adminId,
            'changed_by_type' => 'admin',
            'note' => 'Exchange ' . $return->rma_number . ' for order ' . $order->order,
        ]);

        return $replacement->fresh();
    }
}
