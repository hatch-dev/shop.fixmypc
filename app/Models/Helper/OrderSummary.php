<?php

namespace App\Models\Helper;

use App\Models\OrderedBundleDiscount;
use App\Models\OrderedFlashDiscount;
use App\Models\OrderedProductsInventory;
use App\Models\OrderedVoucher;
use App\Models\PointTransaction;

/**
 * The money on an order, read back from what the order recorded.
 *
 * Everything here comes out of rows written when the order was created:
 *
 *   ordered_products_inventories  actual_price, price, shipping_price
 *   ordered_products              tax_price, quantity, bundle_offer
 *   ordered_voucher               discount
 *   ordered_flash_discount        discount
 *   ordered_loyalty_discount      discount, label
 *   point_transactions            value
 *
 * Nothing is looked up in products, updated_inventories,
 * business_product_pricing, tax_rules, vouchers, flash_discounts or
 * loyalty_groups. An order is a historical fact; changing a price, a band, a
 * VAT rate, a promotion or a customer's account status next month must not
 * move a figure on a receipt that was printed today.
 *
 * Two things this replaces did exactly that:
 *
 *  - Utils::calcPrice() re-derived the voucher discount from the live voucher
 *    row (its type, its rate and its cap), so editing a voucher rewrote the
 *    discount on every order that had ever used it — including orders placed
 *    before the edit, and including ones where the code has since expired.
 *
 *  - OrdersController::orderByUser() built its own copy of this summary inline,
 *    with a different definition of "subtotal" from calcPrice's, so the order
 *    detail page and the order confirmation page showed two different Subtotals
 *    for the same order.
 *
 * The shape is the shape of the checkout summary, in the same order, so a
 * customer can lay the confirmation beside the basket they just approved:
 *
 *   Subtotal            list price  x quantity
 *   Product Discount    (list - charged) x quantity
 *   Bundle offer        free units given away
 *   Shipping            what the order was charged to deliver
 *   Tax                 unit tax x payable quantity
 *   Voucher / Flash / Loyalty / Points
 *   Total
 */
class OrderSummary
{
    /**
     * The relations that make a summary free of extra queries.
     *
     * A caller summarising many orders at once loads these for the whole page
     * and passes the line prices in via inventoriesFor(); everything below then
     * reads what is already in memory. Without it each order cost five round
     * trips of its own — the line prices, the voucher, the flash discount, the
     * loyalty discount and the points — which on a fifteen-row admin list is
     * seventy-five queries to print one table.
     */
    const SUMMARY_RELATIONS = [
        'ordered_products',
        'ordered_voucher',
        'ordered_flash_discount',
        'ordered_loyalty_discount',
        'ordered_bundle_discount',
        'spent_points',
    ];


    /**
     * Line prices for many orders in one query, grouped by order.
     *
     * The result is handed back to for()/decorate() as `$inventories`.
     *
     * @param  iterable  $orderIds
     * @return \Illuminate\Support\Collection
     */
    public static function inventoriesFor($orderIds)
    {
        $ids = collect($orderIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return OrderedProductsInventory::whereIn('order_id', $ids)
            ->get()
            ->groupBy('order_id');
    }


    /**
     * @param  \App\Models\Order  $order  with ordered_products loaded
     * @param  \Illuminate\Support\Collection|null  $inventories  this order's
     *         ordered_products_inventories rows, when the caller already has
     *         them; fetched here when not.
     * @return array
     */
    public static function for($order, $inventories = null)
    {
        $lines = self::lines($order, $inventories);

        $subtotal = 0.0;      // list price x quantity
        $charged = 0.0;       // charged price x payable quantity
        $discount = 0.0;      // the difference, per line
        $bundleOffer = 0.0;   // value of the units a bundle gave away
        $shipping = 0.0;
        $tax = 0.0;

        /*
         * The order as ex-VAT and VAT, and the same figures split by rate.
         *
         * An invoice has to state the net and the VAT against each rate it
         * charged, so the goods are accumulated per rate rather than only as a
         * single total. Only rates that actually appear on the order are
         * listed; a shop with one rate gets one row.
         */
        $exVat = 0.0;
        $vat = 0.0;
        $byRate = [];

        /*
         * The list price of the goods, stated gross.
         *
         * `subtotal` is the list price in whichever convention each product is
         * quoted in — net for the ex-VAT lines, gross for the rest — so it
         * cannot be labelled as either on a summary that adds it up. This is
         * the same figure put through the same split as everything else, so a
         * summary can say "Products (Inc-VAT)" and mean it for both
         * conventions at once.
         */
        $subtotalIncVat = 0.0;
        // The same list price stated net, for a summary read ex-VAT.
        $subtotalExVat = 0.0;

        foreach ($lines as $line) {
            $subtotalExVat += $line['list_ex_vat'];

            $subtotal += $line['list_total'];
            $charged += $line['line_total'];

            $subtotalIncVat += $line['vat_known']
                ? Pricing::vatBreakdown(
                    $line['list_total'], $line['vat_rate'], $line['vat_inclusive']
                )['inc']
                /*
                 * An order placed before the treatment was recorded reports
                 * the list price as it stands, which is what its own lines do
                 * (see lines()). Inventing a rate for it here would be the
                 * restatement those columns exist to prevent.
                 */
                : $line['list_total'];
            $discount += $line['discount'];
            $bundleOffer += $line['bundle_offer_value'];
            $shipping += $line['shipping_price'];
            $tax += $line['tax_total'];

            $exVat += $line['ex_vat'];
            $vat += $line['vat'];

            $key = number_format($line['vat_rate'], 2, '.', '');

            if (!isset($byRate[$key])) {
                $byRate[$key] = ['rate' => $line['vat_rate'], 'net' => 0.0, 'vat' => 0.0];
            }

            $byRate[$key]['net'] += $line['ex_vat'];
            $byRate[$key]['vat'] += $line['vat'];
        }

        $orderId = $order->id ?? null;

        $voucher = 0.0;
        $flashDiscount = 0.0;
        $bundleDiscount = 0.0;
        $pointsDiscount = 0.0;
        $loyalty = ['amount' => 0.0, 'label' => ''];

        if ($orderId) {
            /*
             * Read through the relations when they are loaded — an order list
             * eager-loads them — and fall back to a query for a bare model.
             */
            $voucher = (float)(
                $order->relationLoaded('ordered_voucher')
                    ? $order->ordered_voucher->sum('discount')
                    : OrderedVoucher::where('order_id', $orderId)->sum('discount')
            );

            $flashDiscount = (float)(
                $order->relationLoaded('ordered_flash_discount')
                    ? $order->ordered_flash_discount->sum('discount')
                    : OrderedFlashDiscount::where('order_id', $orderId)->sum('discount')
            );

            /*
             * The Essentials Bundle discount as it was granted on this order.
             * Read from the order's own row, never re-derived from the live
             * bundle: the percentage can be edited and the bundle deleted, and
             * neither may change what a past order charged.
             */
            $bundleDiscount = (float)(
                $order->relationLoaded('ordered_bundle_discount')
                    ? $order->ordered_bundle_discount->sum('discount')
                    : OrderedBundleDiscount::where('order_id', $orderId)->sum('discount')
            );

            $pointsDiscount = (float)(
                $order->relationLoaded('spent_points')
                    ? $order->spent_points->sum('value')
                    : PointTransaction::where('reference_id', $orderId)
                        ->where('source', 'order')
                        ->where('type', 'debit')
                        ->sum('value')
            );

            $loyalty = $order->relationLoaded('ordered_loyalty_discount')
                ? Loyalty::fromRows($order->ordered_loyalty_discount)
                : Loyalty::forOrder($orderId);
        }

        $total = max(0.0, Pricing::round(
            $subtotal - $discount - $bundleOffer
            + $shipping + $tax
            - $voucher - $flashDiscount - $bundleDiscount
            - $loyalty['amount'] - $pointsDiscount
        ));

        /*
         * The order-wide discounts come off the gross total, after VAT, which
         * is how this shop has always applied them. So "ex-VAT + VAT" is the
         * goods and their tax, and the payable total is that less whatever the
         * voucher, the flash sale, the loyalty group and the points took off.
         * Both figures are reported rather than one being made to look like
         * the other.
         */
        $orderWide = Pricing::round(
            $voucher + $flashDiscount + $bundleDiscount
            + $loyalty['amount'] + $pointsDiscount
        );

        $rates = array_values(array_map(function ($row) {
            return [
                'rate' => $row['rate'],
                'net' => Pricing::round($row['net']),
                'vat' => Pricing::round($row['vat']),
            ];
        }, $byRate));

        usort($rates, function ($a, $b) {
            return $b['rate'] <=> $a['rate'];
        });

        /*
         * Rounded once, here, because the gross discount below is derived from
         * these two and has to leave them reconciling exactly.
         */
        $incVat = Pricing::round($exVat - $bundleOffer + $vat);
        $subtotalIncVat = Pricing::round($subtotalIncVat);

        return [
            'subtotal' => Pricing::round($subtotal),
            'discount' => Pricing::round($discount),
            'goods' => Pricing::round($charged - $bundleOffer),
            'bundle_offer' => Pricing::round($bundleOffer),
            'shipping' => Pricing::round($shipping),
            'tax' => Pricing::round($tax),

            /*
             * The same order stated the way a VAT invoice has to state it.
             * `ex_vat` is the goods net of VAT after the line discounts;
             * `vat` is the tax on them; `inc_vat` is the two together, before
             * the order-wide discounts below.
             */
            'ex_vat' => Pricing::round($exVat - $bundleOffer),
            'vat' => Pricing::round($vat),
            'inc_vat' => $incVat,

            /*
             * The net statement, for a business summary:
             *   subtotal_ex_vat - discount_ex_vat = ex_vat
             * Derived like its gross twin, so the identity holds exactly.
             */
            'subtotal_ex_vat' => Pricing::round($subtotalExVat),
            'discount_ex_vat' => Pricing::round(Pricing::round($subtotalExVat) - Pricing::round($exVat - $bundleOffer)),

            /*
             * The gross statement of the same goods, for a summary that leads
             * with the inc-VAT figure:
             *
             *   subtotal_inc_vat - discount_inc_vat = inc_vat
             *
             * The discount is derived from the two ends rather than summed
             * separately so that identity holds exactly, to the cent, whatever
             * rounding the lines did and whatever a bundle gave away. It is
             * the saving against the list price; the order-wide discounts
             * below are a different thing and are not folded into it.
             */
            'subtotal_inc_vat' => $subtotalIncVat,
            'discount_inc_vat' => Pricing::round($subtotalIncVat - $incVat),
            'order_wide_discount' => $orderWide,
            'vat_breakdown' => $rates,
            'voucher' => Pricing::round($voucher),
            'flash_discount' => Pricing::round($flashDiscount),

            /*
             * The Essentials Bundle discount, stated rather than merely
             * subtracted.
             *
             * It was folded into `total` and into `order_wide_discount` and
             * reported nowhere else, so every document built from this array
             * -- the confirmation email, the PDF invoice, the admin order
             * detail -- printed rows that came to MORE than the customer was
             * charged, by exactly this amount, with no line accounting for
             * the difference. Every other order-wide discount beside it has
             * its own key for that reason.
             */
            'bundle_discount' => Pricing::round($bundleDiscount),

            'loyalty_discount' => Pricing::round($loyalty['amount']),
            'loyalty_label' => $loyalty['label'],
            'points_discount' => Pricing::round($pointsDiscount),
            'total' => $total,

            /*
             * A split payment: the part the wallet paid and what the order's
             * own method was charged. A payment, not a discount - total is
             * unchanged and the VAT figures above do not move.
             */
            'wallet_paid' => Pricing::round((float)($order->wallet_amount ?? 0)),
            'amount_due' => Pricing::round(max(0, $total - (float)($order->wallet_amount ?? 0))),
            'lines' => $lines,
        ];
    }


    /**
     * One entry per ordered line, carrying every figure the confirmation
     * screen prints against that product.
     *
     * ordered_products holds the charged unit price, the tax and the quantity;
     * ordered_products_inventories holds the list price the discount is
     * measured against and the shipping. They are matched on the pair that
     * identifies a line, (product_id, inventory_id) — the same pair the order
     * was written with.
     */
    public static function lines($order, $inventories = null)
    {
        $orderId = $order->id ?? null;

        if (is_null($inventories)) {
            $inventories = $orderId
                ? OrderedProductsInventory::where('order_id', $orderId)->get()
                : collect();
        } else {
            $inventories = collect($inventories);
        }

        $lines = [];

        foreach (($order->ordered_products ?? []) as $product) {

            $stored = $inventories
                ->where('product_id', $product->product_id)
                ->where('inventory_id', $product->inventory_id)
                ->first();

            $quantity = (int)$product->quantity;
            $freeUnits = (int)($product->bundle_offer ?? 0);

            // Units the customer paid for. Tax follows this, not the ordered
            // quantity — the free ones were not taxed when the order was
            // charged either (QA audit ORD-03).
            $payableQty = max(0, $quantity - $freeUnits);

            /*
             * The charged unit price is on ordered_products.selling, which is
             * written for every line. ordered_products_inventories carries the
             * list price beside it; an order missing that row (they exist —
             * see the pre-INT-01 rows in this database) falls back to the
             * charged price, which shows no discount rather than inventing one.
             */
            $charged = (float)$product->selling;
            $listPrice = $stored ? (float)$stored->actual_price : $charged;

            // A line can never render as a negative discount.
            if ($listPrice < $charged) {
                $listPrice = $charged;
            }

            $unitTax = (float)$product->tax_price;

            /*
             * The VAT on this line, from what the order recorded.
             *
             * Two conventions exist in this catalogue and the line says which
             * one applied to it. `tax_inclusive` means the charged price
             * already contained the VAT and nothing was added; otherwise the
             * rate was applied on top and `tax_price` is what it came to.
             *
             * An order placed before those columns existed has neither, so it
             * falls back to exactly what it does record — the price charged
             * plus the tax added — and reports no rate rather than guessing
             * one from today's tax rules.
             */
            $knownTreatment = !is_null($product->tax_rate) && !is_null($product->tax_inclusive);

            $rate = $knownTreatment ? (float)$product->tax_rate : 0.0;
            $inclusive = $knownTreatment ? (bool)$product->tax_inclusive : false;

            $lineCharged = Pricing::round($charged * $quantity);

            if ($knownTreatment) {
                /*
                 * Bundle freebies are not taxed (QA audit ORD-03), so an
                 * exclusive line's VAT follows the payable quantity while its
                 * ex-VAT value follows what the customer is billed for.
                 */
                $vatBase = $inclusive ? $lineCharged : Pricing::round($charged * $payableQty);
                $split = Pricing::vatBreakdown($vatBase, $rate, $inclusive);

                $lineEx = $inclusive ? $split['ex'] : $lineCharged;
                $lineVat = $split['vat'];

            } else {
                $lineEx = $lineCharged;
                $lineVat = Pricing::round($unitTax * $payableQty);
            }

            /*
             * The list price through the same split as the charge. An order
             * from before the treatment was recorded reports the list price
             * as it stands, as subtotal_inc_vat does for the whole order.
             */
            $listTotal = Pricing::round($listPrice * $quantity);
            $lineList = $knownTreatment
                ? Pricing::vatBreakdown($listTotal, $rate, $inclusive)
                : ['ex' => $listTotal, 'inc' => $listTotal];
            $unitList = $knownTreatment
                ? Pricing::vatBreakdown(Pricing::round($listPrice), $rate, $inclusive)
                : ['ex' => Pricing::round($listPrice), 'inc' => Pricing::round($listPrice)];

            $lines[] = [
                'product_id' => (int)$product->product_id,
                'inventory_id' => (int)$product->inventory_id,
                'quantity' => $quantity,
                'bundle_offer' => $freeUnits,
                'payable_quantity' => $payableQty,
                'actual_price' => Pricing::round($listPrice),
                'price' => Pricing::round($charged),
                'list_total' => Pricing::round($listPrice * $quantity),
                'line_total' => $lineCharged,
                'discount' => Pricing::round(($listPrice - $charged) * $quantity),
                'bundle_offer_value' => Pricing::round($charged * $freeUnits),
                'shipping_price' => $stored ? Pricing::round($stored->shipping_price) : 0.0,
                'tax_price' => Pricing::round($unitTax),
                /*
                 * The VAT *added* to this line, which is what the order's
                 * total is built from. It stays zero on a VAT-inclusive line:
                 * nothing was added there, and adding the contained VAT here
                 * would inflate the total by the tax the customer had already
                 * paid inside the price.
                 *
                 * The contained VAT is reported below, under `vat`, where it
                 * is presentation rather than arithmetic.
                 */
                'tax_total' => Pricing::round($unitTax * $payableQty),

                /*
                 * The line as ex-VAT / VAT / inc-VAT, which is what every
                 * screen showing a VAT breakdown prints. Split once, here,
                 * from the order's own figures.
                 */
                'ex_vat' => $lineEx,
                'vat' => $lineVat,
                'inc_vat' => Pricing::round($lineEx + $lineVat),
                'vat_rate' => $rate,
                'vat_inclusive' => $inclusive,
                'vat_known' => $knownTreatment,

                /* One unit, the same way, for the item rows. */
                'unit_ex_vat' => $knownTreatment && $inclusive
                    ? Pricing::vatBreakdown($charged, $rate, true)['ex']
                    : Pricing::round($charged),
                'unit_inc_vat' => $knownTreatment && $inclusive
                    ? Pricing::round($charged)
                    : Pricing::round($charged + $unitTax),

                /*
                 * The list price and the discount off it, stated both ways,
                 * so an item row can be read in the same basis as the summary
                 * under it. Printing the product's own convention there - net
                 * for an ex-VAT product - put "Discount -€100" above a summary
                 * saying "Discount -€123" for the same discount.
                 *
                 * The discounts are derived, list minus charged, so each basis
                 * reconciles to the cent:  list - discount = ex_vat / inc_vat.
                 */
                'unit_list_ex_vat' => $unitList['ex'],
                'unit_list_inc_vat' => $unitList['inc'],
                'list_ex_vat' => $lineList['ex'],
                'list_inc_vat' => $lineList['inc'],
                'discount_ex_vat' => Pricing::round($lineList['ex'] - $lineEx),
                'discount_inc_vat' => Pricing::round($lineList['inc'] - ($lineEx + $lineVat)),

                'voucher_discount' => $stored
                    ? Pricing::round($stored->product_voucher_discount_applied)
                    : 0.0,
            ];
        }

        return $lines;
    }


    /**
     * Copy the per-line figures onto the ordered_products the API is about to
     * serialise, so the confirmation screen prints the stored numbers rather
     * than working any of them out for itself.
     */
    public static function decorate($order, $inventories = null)
    {
        $summary = self::for($order, $inventories);

        $byLine = [];

        foreach ($summary['lines'] as $line) {
            $byLine[$line['product_id'] . ':' . $line['inventory_id']] = $line;
        }

        foreach (($order->ordered_products ?? []) as $product) {

            $line = $byLine[$product->product_id . ':' . $product->inventory_id] ?? null;

            if (is_null($line)) {
                continue;
            }

            $product['actual_price'] = $line['actual_price'];
            $product['price'] = $line['price'];
            $product['line_discount'] = $line['discount'];
            $product['line_total'] = $line['line_total'];
            $product['shipping_price'] = $line['shipping_price'];
            $product['tax_total'] = $line['tax_total'];
            /*
             * The line as ex-VAT / VAT / inc-VAT, split once here from what
             * the order recorded. Every screen that prints a VAT breakdown
             * reads these rather than dividing by the rate itself.
             */
            $product['tax_rate'] = $line['vat_rate'];
            $product['vat_inclusive'] = $line['vat_inclusive'];
            $product['vat_known'] = $line['vat_known'];
            $product['ex_vat'] = $line['ex_vat'];
            $product['vat'] = $line['vat'];
            $product['inc_vat'] = $line['inc_vat'];
            $product['unit_ex_vat'] = $line['unit_ex_vat'];
            $product['unit_inc_vat'] = $line['unit_inc_vat'];
            $product['unit_list_ex_vat'] = $line['unit_list_ex_vat'];
            $product['unit_list_inc_vat'] = $line['unit_list_inc_vat'];
            $product['list_ex_vat'] = $line['list_ex_vat'];
            $product['list_inc_vat'] = $line['list_inc_vat'];
            $product['discount_ex_vat'] = $line['discount_ex_vat'];
            $product['discount_inc_vat'] = $line['discount_inc_vat'];
            $product['payable_quantity'] = $line['payable_quantity'];
            $product['product_voucher_discount_applied'] = $line['voucher_discount'];
        }

        // The lines are on the products now; no need to send them twice.
        unset($summary['lines']);

        return $summary;
    }
}
