<?php

namespace App\Models\Helper;

use App\Models\BundleDeal;

/**
 * The Essentials Bundle discount a basket is currently entitled to.
 *
 * Re-derived from `carts.bundle_deal_id` every time money is worked out, and
 * never stored. This is the whole reason that column holds a reference rather
 * than an amount: `carts.price` is rewritten from the catalogue on every
 * pricing pass -- the basket read, add-to-cart, every quantity change, the
 * voucher preview, checkout twice and the sign-in merge -- so a discounted
 * figure banked on a line is silently undone by the next pass, and the basket
 * would quote one total while the order charged another.
 *
 * One entry point, used by the basket summary and by checkout, so the two
 * cannot drift. Everything it needs is on the rows it is handed; it opens one
 * query for the bundle definitions and nothing else.
 */
class BundleDiscount
{
    /**
     * What each bundle in this basket is worth, and the total.
     *
     * $rows are the cart lines being charged -- the SELECTED ones at checkout,
     * because a deselected line is not being bought and must not earn or
     * enlarge a discount.
     *
     * Returns ['total' => float, 'bundles' => [['id','title','discount','subtotal']]].
     */
    public static function forRows($rows)
    {
        $rows = collect($rows);

        $grouped = $rows
            ->filter(function ($row) { return !empty($row->bundle_deal_id); })
            ->groupBy('bundle_deal_id');

        if ($grouped->isEmpty()) {
            return ['total' => 0.0, 'bundles' => []];
        }

        $bundles = BundleDeal::with('products')
            ->whereIn('id', $grouped->keys())
            ->get()
            ->keyBy('id');

        // Every product the basket holds, however it got there: an item bought
        // separately still completes the set.
        $heldProductIds = $rows->pluck('product_id')
            ->map(function ($id) { return (int)$id; })
            ->unique();

        /*
         * What each product in this basket is being charged for one of it,
         * and how many of it the basket holds.
         *
         * The price is `charged_unit_price` when the caller has one, `price`
         * otherwise. Checkout works each line's price out fresh and never
         * writes it back to the row, so reading carts.price there would base
         * the percentage on whatever was last stored -- the figure from
         * before the customer's band, a flash sale or a catalogue edit moved
         * it. The basket read has no such gap: repriceForCustomer has already
         * corrected the column by the time this is asked.
         *
         * The cheapest line wins when a product is in the basket more than
         * once at two variants: that is the variant BundleService quotes the
         * set at, so it is the one the set is charged at. The quantities are
         * added, because two variants of the same product are still two of
         * the thing the bundle asks for.
         */
        $byProduct = [];

        foreach ($rows as $row) {
            $productId = (int)$row->product_id;
            $unit = (float)($row->charged_unit_price ?? $row->price);
            $quantity = max(1, (int)$row->quantity);

            if (!array_key_exists($productId, $byProduct)) {
                $byProduct[$productId] = ['price' => $unit, 'quantity' => 0];
            }

            $byProduct[$productId]['price'] = min($byProduct[$productId]['price'], $unit);
            $byProduct[$productId]['quantity'] += $quantity;
        }

        $result = [];
        $total = 0.0;

        foreach ($grouped->keys() as $bundleId) {
            $bundle = $bundles->get((int)$bundleId);

            if (is_null($bundle)) {
                continue;
            }

            /*
             * The discount is for the SET. If the shopper has since removed an
             * item, they no longer have the bundle, and charging the bundle
             * price for part of it would be charging a price that was never
             * offered.
             *
             * This is checked against the whole basket rather than against the
             * lines still carrying the marker, so removing an item and adding
             * it back on its own restores the bundle rather than stranding it.
             */
            $required = $bundle->products->pluck('id')
                ->map(function ($id) { return (int)$id; });

            if ($required->isEmpty() || $required->diff($heldProductIds)->isNotEmpty()) {
                continue;
            }

            /*
             * The base is one of each of the bundle's products, times the
             * number of COMPLETE SETS the basket holds.
             *
             * A set, because that is what is offered: BundleService quotes
             * the bundle by summing a single unit of every item, and
             * addBundle writes exactly that. Charging by the set is the only
             * way the label and the till agree when anything moves
             * afterwards.
             *
             * Summing the MARKED LINES at their basket quantity -- which is
             * what this did -- got that wrong two ways:
             *
             *   - Raising ONE item to five multiplied the saving against four
             *     units of a single product that no set contained. A bundle
             *     quoted at EUR 5.50 paid EUR 15.50.
             *   - A line can only carry ONE bundle id, so taking a second
             *     bundle that shares a product overwrote the first's marker.
             *     The first bundle lost that line from its base and paid less
             *     than it had quoted.
             *
             * Counting sets fixes both, and still pays twice for a bundle
             * taken twice: the completeness of the set is what is measured,
             * not how many of any one item happen to be in the basket.
             *
             * Reading by PRODUCT rather than by marker is what makes the
             * shared line count for both bundles. The marker's job is only to
             * say which bundles are in play.
             */
            $sets = $required->map(function ($productId) use ($byProduct) {
                return (int)($byProduct[$productId]['quantity'] ?? 0);
            })->min();

            $sets = max(0, (int)$sets);

            if ($sets < 1) {
                continue;
            }

            $perSet = $required->sum(function ($productId) use ($byProduct) {
                return (float)($byProduct[$productId]['price'] ?? 0.0);
            });

            $subtotal = Pricing::round($perSet * $sets);

            $discount = Pricing::bundleDiscount($bundle, $subtotal);

            if ($discount <= 0) {
                continue;
            }

            $result[] = [
                'id' => (int)$bundle->id,
                'title' => $bundle->title,
                'subtotal' => $subtotal,
                'discount' => $discount,
            ];

            $total += $discount;
        }

        return ['total' => Pricing::round($total), 'bundles' => $result];
    }

    /** Just the money, for the callers that only subtract it. */
    public static function totalFor($rows)
    {
        return self::forRows($rows)['total'];
    }
}
