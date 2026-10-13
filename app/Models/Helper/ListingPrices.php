<?php

namespace App\Models\Helper;

use App\Models\BusinessProductPricing;
use App\Models\Helper\ClearanceWindow;
use App\Models\Product;
use App\Models\TaxRules;
use Illuminate\Support\Facades\Auth;

/**
 * The price a product tile advertises, for whoever is looking at it.
 *
 * Every surface that shows a product card asks this: the shop listing, a
 * category, search, the home rails, the wishlist, recently viewed, the compare
 * list and a flash sale. They used to answer it in whichever way their own
 * controller happened to, and most of them did not answer it at all — they
 * shipped products.offered and the tile rendered that, so a business or
 * approved-trade account was advertised the retail price on the surfaces that
 * had been missed while the product page, the basket and the till all used its
 * own rate.
 *
 * There is one calculation, in Pricing::displayRange, and this is the one
 * place that feeds it the customer's entitlement. It sits here rather than in
 * a controller so a controller cannot be written without it.
 *
 * Pricing itself stays free of database access, which is why the band lookup
 * lives here and the arithmetic lives there.
 */
class ListingPrices
{
    /**
     * Decorate a collection of products with the price to advertise.
     *
     * Writes display_price, display_price_max, display_compare_price,
     * is_variable and in_stock onto each row — the fields the storefront tile
     * reads, in preference to the raw offer price.
     *
     * A listing advertises the price of buying one, so the bands are applied
     * at quantity 1. The basket and checkout price the quantity the customer
     * actually chose; a tile has no quantity to speak of.
     */
    public static function decorate($items, $user = null)
    {
        if (is_null($items)) {
            return $items;
        }

        $tierMap = self::tierMap($items, $user);
        $vatMap = self::vatMap($items);

        foreach ($items as $product) {
            if (!is_object($product)) {
                continue;
            }

            /*
             * Listings expose the variations as product_inventories; the
             * detail endpoint reshapes the same rows onto `inventory`.
             */
            $inventories = $product->product_inventories ?? null;

            if (is_null($inventories)) {
                $inventories = $product->inventory ?? null;
            }

            $range = Pricing::displayRange(
                $product, $inventories, $tierMap->get($product->id ?? null)
            );

            $product->display_price = $range['min'];
            $product->display_price_max = $range['max'];
            $product->display_compare_price = $range['compare'];
            $product->is_variable = $range['variable'];
            $product->in_stock = $range['in_stock'];

            /*
             * The advertised price split for VAT, so a tile can say which side
             * of it the headline figure sits on.
             *
             * `display_price` is the figure the shop quotes; whether that is
             * net or gross depends on the product, which is exactly why the
             * tile cannot be left to guess. Split once, here, by the same
             * helper the basket, the order and the invoice use.
             */
            $vat = $vatMap->get($product->id ?? null)
                ?: ['rate' => 0.0, 'inclusive' => true];

            $split = Pricing::vatBreakdown($range['min'], $vat['rate'], $vat['inclusive']);

            $product->display_price_ex_vat = $split['ex'];
            $product->display_price_vat = $split['vat'];
            $product->display_price_inc_vat = $split['inc'];
            $product->vat_rate = $split['rate'];
            $product->vat_inclusive = $split['inclusive'];
        }

        return $items;
    }


    /**
     * Decorate products that hang off a wrapper row.
     *
     * A wishlist, a compare list and a recently-viewed list are lists of
     * *references*; the product is on a relation. The tile is given the
     * product, so the product is what has to carry the price.
     *
     * $relation is the property holding it — 'product' for all three today.
     */
    public static function decorateRelation($rows, $relation = 'product', $user = null)
    {
        if (is_null($rows)) {
            return $rows;
        }

        $products = [];

        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }

            $product = $row->{$relation} ?? null;

            if (is_object($product)) {
                $products[] = $product;
            }
        }

        /*
         * The clearance running on each of these, if any.
         *
         * The surfaces that come through here -- the wishlist, recently
         * viewed and the compare list -- build their own queries and do not
         * go through the listing's clearance pass, so their tiles advertised
         * the pre-clearance price while the product page, the shop listing
         * and the till all had the deal. One batched query for the set.
         *
         * Only here, not in decorate(): the listing paths reduce their rows
         * before decorate() sees them, so attaching the columns there as well
         * would take the discount off twice.
         */
        ClearanceWindow::attach($products);

        self::decorate($products, $user);

        return $rows;
    }


    /**
     * The quantity bands this caller is entitled to, keyed by product id.
     *
     * Empty for guests, personal accounts, trade accounts still waiting on
     * approval, rejected ones and suspended ones —
     * BusinessProductPricing::tierMap answers that from
     * User::hasBusinessPricing(), the same test the basket and the order use.
     * A tile cannot advertise a rate the till will not honour.
     *
     * One query per listing rather than one per tile.
     */
    public static function tierMap($items, $user = null)
    {
        $ids = collect($items)
            ->filter(function ($item) { return is_object($item); })
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        /*
         * Auth::user() by default, so a controller does not have to remember
         * to pass it. Explicit only where the caller already holds the model,
         * or is pricing on someone else's behalf.
         */
        return BusinessProductPricing::tierMap(
            $user ?: Auth::user(), $ids
        );
    }
    /**
     * How each of these products is treated for VAT.
     *
     * Two conventions run side by side in this catalogue and a tile cannot
     * show a meaningful price without knowing which applies: `excludeVAT = 1`
     * means the listed figure is net and the rate goes on top, anything else
     * means the VAT is already inside it.
     *
     * Looked up in bulk — one query for the products that did not arrive with
     * the columns selected, one for the rules — rather than per tile, because
     * a category page is fifty of these.
     *
     * @return \Illuminate\Support\Collection  keyed by product id
     */
    public static function vatMap($items)
    {
        $rows = collect($items)->filter(function ($item) {
            return is_object($item) && !is_null($item->id ?? null);
        });

        if ($rows->isEmpty()) {
            return collect();
        }

        /*
         * Most listing queries select a named column list that leaves these
         * out, so anything missing them is fetched once by id rather than the
         * tile silently falling back to "no VAT".
         */
        $missing = $rows->filter(function ($item) {
            return is_null($item->excludeVAT ?? null) || !array_key_exists('tax_rule_id', $item->getAttributes());
        })->pluck('id')->unique()->values();

        $fetched = $missing->isEmpty()
            ? collect()
            : Product::whereIn('id', $missing)
                ->get(['id', 'excludeVAT', 'tax_rule_id'])
                ->keyBy('id');

        $ruleIds = $rows->map(function ($item) use ($fetched) {
            $row = $fetched->get($item->id) ?: $item;

            return $row->tax_rule_id ?? null;
        })->filter()->unique()->values();

        $rules = $ruleIds->isEmpty()
            ? collect()
            : TaxRules::whereIn('id', $ruleIds)->get()->keyBy('id');

        $map = [];

        foreach ($rows as $item) {
            $row = $fetched->get($item->id) ?: $item;
            $rule = $rules->get($row->tax_rule_id ?? null);

            $map[$item->id] = [
                'rate' => Pricing::vatRate($rule),
                'inclusive' => Pricing::isVatInclusive($row),
            ];
        }

        return collect($map);
    }
}
