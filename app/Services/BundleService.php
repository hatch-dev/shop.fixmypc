<?php

namespace App\Services;

use App\Models\BundleDeal;
use App\Models\BusinessProductPricing;
use App\Models\Helper\Pricing;
use App\Models\Helper\Variant;
use App\Models\Product;
use App\Models\UpdatedInventory;
use Illuminate\Support\Facades\Config;

/**
 * The "Essentials Bundle" under a product: a fixed set of accessories offered
 * together, and what they cost this particular customer.
 *
 * The sibling of CrossSellService, and deliberately built the same way. Both
 * blocks sit in the same card, both quote a price and then put products in the
 * basket, and both are worse than useless if the figure on screen is not the
 * figure the basket charges. So neither works a price out for itself: every
 * line is priced with the exact call CartsController::serverSidePricing makes,
 * against the same variant that would be added.
 *
 * A bundle is ALL OR NOTHING, which is the one rule that makes it different
 * from a recommendation block. A cross-sell that has lost a product is still a
 * cross-sell with fewer cards; a five-item bundle missing an item is not that
 * bundle, and certainly not that bundle's price. So a bundle whose every item
 * is not buyable right now is not offered at all.
 */
class BundleService
{
    /**
     * The bundles offered under one product, priced for this customer.
     *
     * `products.bundle_deal_id` is a comma-separated list, which is how the
     * admin assigns more than one. Unreadable entries are skipped rather than
     * throwing: a mistyped id should cost the shopper a bundle, not the
     * product page.
     */
    public function forProduct($product, $customer = null)
    {
        /*
         * Every callback here is spelled out rather than passed as a string.
         *
         * Collection::map calls back with (value, KEY), so `->map('trim')` is
         * trim($id, $key) -- and trim's second argument is the character list.
         * A bundle whose id ended in a zero had it stripped ("160" became
         * "16"), which silently looked up a different bundle, or none: the
         * Essentials Bundle vanished for exactly one product in ten and came
         * back on its own. `->map('intval')` is the same trap with the base
         * argument, and turns every element after the first into 0.
         */
        $bundleIds = collect(explode(',', (string)($product->bundle_deal_id ?? '')))
            ->map(function ($id) { return trim($id); })
            ->filter(function ($id) { return $id !== '' && ctype_digit($id); })
            ->map(function ($id) { return (int)$id; })
            ->unique()
            ->values();

        if ($bundleIds->isEmpty()) {
            return [];
        }

        $bundles = BundleDeal::with('products')
            ->whereIn('id', $bundleIds)
            ->get();

        if ($bundles->isEmpty()) {
            return [];
        }

        [$records, $inventories, $tiers] = $this->catalogueFor($bundles, $customer);

        $cards = [];

        foreach ($bundles as $bundle) {
            $card = $this->card($bundle, $records, $inventories, $tiers);

            if ($card) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * Everything a set of bundles needs to be priced, read once.
     *
     * Three statements for the catalogue side plus one for the bands, however
     * many bundles and however many products they hold. The callers touch the
     * database not at all -- a block that queries per item is the defect this
     * very page carried until recently.
     */
    private function catalogueFor($bundles, $customer = null)
    {
        $productIds = collect($bundles)->pluck('products')->flatten()->pluck('id')
            ->map(function ($id) { return (int)$id; })->unique()->values();

        return [
            Product::pricingRecords($productIds),

            /*
             * The attribute values come with the variants, eager-loaded.
             *
             * Helper\Variant::label falls back to loadMissing, which would be
             * one query per item -- and the block's whole point is that its
             * cost does not grow with the size of the bundle
             * (BundleStorefrontTest::test_the_query_count_does_not_grow...).
             */
            UpdatedInventory::with('inventory_attributes.attribute_value')
                ->whereIn('product_id', $productIds)
                ->orderBy('id')
                ->get()
                ->groupBy('product_id'),

            BusinessProductPricing::tierMap($customer, $productIds),
        ];
    }

    /**
     * What each of these bundles costs, for the admin list.
     *
     * The list used to add up `products.offered ?: products.selling` and take
     * the percentage off that -- a product-LEVEL figure, while the storefront
     * and the basket price every item at its cheapest purchasable VARIANT. On
     * any product whose variants carry their own prices the two disagreed, and
     * the administrator was shown a total the shop never charges. Measured on
     * this database: the list said EUR 120.00 / EUR 108.00 where the shop
     * quoted EUR 110.00 / EUR 99.00.
     *
     * Priced at retail (no customer), which is what a catalogue screen should
     * state. `sellable` is false when the storefront would refuse to offer the
     * bundle at all -- an unpublished or sold-out item -- in which case the
     * figures are what it WOULD cost, so the row still reads sensibly.
     *
     * Returns [bundleId => ['total_price','discount_amount','final_price','sellable']].
     */
    public function totalsFor($bundles)
    {
        $bundles = collect($bundles);

        if ($bundles->isEmpty()) {
            return [];
        }

        [$records, $inventories, $tiers] = $this->catalogueFor($bundles, null);

        $totals = [];

        foreach ($bundles as $bundle) {

            $total = 0.0;
            $sellable = $bundle->products->isNotEmpty();

            foreach ($bundle->products as $product) {

                $item = $this->item($product, $records, $inventories, $tiers);

                if (is_null($item)) {
                    // Not offerable, but the admin still wants a figure.
                    $sellable = false;
                    $item = $this->item($product, $records, $inventories, $tiers, false);
                }

                $total += $item ? $item['price'] : 0.0;
            }

            $total = Pricing::round($total);

            // The same call the storefront and the till both make.
            $discount = Pricing::bundleDiscount($bundle, $total);

            $totals[(int)$bundle->id] = [
                'total_price' => $total,
                'discount_amount' => $discount,
                'final_price' => Pricing::round($total - $discount),
                'sellable' => $sellable,
            ];
        }

        return $totals;
    }

    /**
     * One bundle as the block renders it, or null when it cannot be sold.
     *
     * Null rather than a card marked unavailable: there is no such thing as a
     * bundle you may look at but not buy, and an "Essentials Bundle" heading
     * over a dead row is exactly the empty container the product page is meant
     * not to have.
     */
    private function card($bundle, $records, $inventories, $tiers)
    {
        if ($bundle->products->isEmpty()) {
            return null;
        }

        $items = [];
        $total = 0.0;
        $compare = 0.0;

        foreach ($bundle->products as $product) {
            $item = $this->item($product, $records, $inventories, $tiers);

            /*
             * All or nothing. One unpublished or sold-out item and the whole
             * bundle goes, because the price on the label is the price of all
             * of them together.
             */
            if (is_null($item)) {
                return null;
            }

            $items[] = $item;
            $total += $item['price'];
            $compare += $item['old_price'] ?: $item['price'];
        }

        $total = Pricing::round($total);
        $compare = Pricing::round($compare);

        // The same call BundleDiscount makes against the basket, so the block
        // and the till cannot disagree about what the set costs.
        $discount = Pricing::bundleDiscount($bundle, $total);
        $payable = Pricing::round($total - $discount);

        return [
            'id' => (int)$bundle->id,
            'title' => $bundle->title,
            'description' => $bundle->description,
            'items' => $items,

            /*
             * What the shopper actually pays for the set: the lines at
             * catalogue prices, less the bundle's own discount.
             *
             * The discount is safe to quote here only because nothing banks it
             * on a line. `carts.price` is rewritten from the catalogue on
             * every pricing pass, so a discounted figure written onto a row
             * would be undone by the next one. Instead each line remembers
             * which bundle it came in under (`carts.bundle_deal_id`) and
             * Helper\BundleDiscount re-derives this same figure -- from this
             * same Pricing::bundleDiscount call -- at the basket summary and
             * again at checkout. The quote and the charge are the same
             * arithmetic on the same inputs, which is the only way they stay
             * equal.
             */
            'lines_total' => $total,
            'discount' => $discount,
            'price' => $payable,

            /*
             * The struck-through figure: the same items at their list prices,
             * so the saving shown covers both the catalogue's own offers and
             * the bundle discount on top.
             */
            'old_price' => $compare > $payable ? $compare : null,
            'savings' => $compare > $payable ? Pricing::round($compare - $payable) : 0.0,

            'discount_type' => $bundle->discount_type,
            'discount_value' => (float)$bundle->discount_value,
        ];
    }

    /**
     * One product in a bundle, priced at the variant that would be added.
     *
     * Null when nothing about it is buyable. The cheapest purchasable variant
     * wins, which is the choice Pricing::displayRange makes for a headline
     * price -- and because the id of that variant travels with the figure, the
     * basket cannot end up charging for a different one.
     *
     * $purchasableOnly is relaxed by the admin list alone, which needs a
     * figure for a bundle it is also telling the administrator is not
     * sellable. Every storefront path leaves it true: a price for something
     * that cannot be bought has no business on a shopping page.
     */
    private function item($product, $records, $inventories, $tiers, $purchasableOnly = true)
    {
        $id = (int)$product->id;

        if ($purchasableOnly
            && (int)$product->status !== (int)Config::get('constants.status.PUBLIC')) {
            return null;
        }

        $record = $records->get($id);

        if (is_null($record)) {
            return null;
        }

        $variants = $inventories->get($id, collect());

        // The same test the basket applies: in stock, or on backorder.
        $purchasable = $variants->filter(function ($inventory) {
            return Pricing::isPurchasable($inventory);
        })->values();

        if ($purchasable->isEmpty()) {
            if ($purchasableOnly || $variants->isEmpty()) {
                return null;
            }

            // Admin list only: quote what it would cost if it came back.
            $purchasable = $variants->values();
        }

        $priced = $purchasable->map(function ($inventory) use ($record, $tiers, $id) {
            return [
                'inventory' => $inventory,
                'price' => Pricing::round(
                    Pricing::unitPrice($record, $inventory, 1, $tiers->get($id))
                ),
            ];
        })->sortBy('price')->values();

        $cheapest = $priced->first();

        return [
            'id' => $id,
            'name' => $product->title,
            'slug' => $product->slug,
            'image' => $product->image,
            'inventory_id' => (int)$cheapest['inventory']->id,

            /*
             * WHICH variant the set includes -- "128GB / Midnight".
             *
             * A bundle stores a PRODUCT, and this class picks the variant, so
             * without this the card said "iPhone 15 Pro, EUR 599" and the
             * shopper had no way to know which of thirty-six they were being
             * sold. It is also what makes the fall-through visible: when the
             * chosen variant sells out the next one is taken, and the label
             * changes on the card instead of the set silently becoming a
             * different thing.
             *
             * Null for a product with no variations, so the page can leave
             * the line out rather than print an empty one.
             */
            'variant' => Variant::label($cheapest['inventory'], false),
            'sku' => $cheapest['inventory']->sku ?: null,

            'price' => $cheapest['price'],
            'old_price' => Pricing::listPrice($record, $cheapest['inventory'], $cheapest['price']),
        ];
    }

    /**
     * Is this bundle still offered under this product, and still complete?
     *
     * Nothing the page sends is trusted. Between the block rendering and the
     * click the bundle can be unassigned, an item unpublished, a variant sold
     * out -- and a bundle that has lost an item is not the bundle whose price
     * was quoted.
     *
     * Returns null when it is fine, or a lang KEY plus its replacements, so
     * the caller decides how to report it and this class stays out of HTTP.
     */
    public function refusalFor($primaryId, $bundleId)
    {
        $primary = Product::find((int)$primaryId);

        if (is_null($primary)) {
            return ['lang.bundle_gone', []];
        }

        // Spelled out for the same reason as in forProduct: `->map('trim')`
        // passes the key as trim's character list.
        $offered = collect(explode(',', (string)($primary->bundle_deal_id ?? '')))
            ->map(function ($id) { return trim($id); })
            ->contains((string)(int)$bundleId);

        if (!$offered) {
            return ['lang.bundle_gone', []];
        }

        /*
         * forProduct drops a bundle it cannot sell, so its absence IS the
         * refusal. One definition of "complete", used by the block and by the
         * add, which is what stops the two disagreeing.
         */
        $card = collect($this->forProduct($primary))
            ->firstWhere('id', (int)$bundleId);

        return is_null($card) ? ['lang.bundle_unavailable', []] : null;
    }

    /**
     * The lines to write for a bundle: one of each item, at the variant the
     * block quoted.
     *
     * Only reached once refusalFor has passed, so every item here is one this
     * customer can buy right now.
     */
    public function linesFor($primary, $bundleId)
    {
        $card = collect($this->forProduct($primary))
            ->firstWhere('id', (int)$bundleId);

        if (is_null($card)) {
            return [];
        }

        return collect($card['items'])->map(function ($item) {
            return [
                'product_id' => (int)$item['id'],
                'inventory_id' => (int)$item['inventory_id'],
                'quantity' => 1,
            ];
        })->all();
    }
}
