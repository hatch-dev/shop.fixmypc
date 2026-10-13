<?php

namespace App\Services;

use App\Models\BusinessProductPricing;
use App\Models\CrossSell;
use App\Models\CrossSellProduct;
use App\Models\Helper\Pricing;
use App\Models\Helper\Variant;
use App\Models\Product;
use App\Models\ProductCrossSell;
use App\Models\UpdatedInventory;
use Illuminate\Support\Facades\Config;

/**
 * What a shopper is shown alongside a product, and what it costs them.
 *
 * One class rather than logic on each surface. The product page, the basket
 * and the add-to-basket call all have to agree on three things -- which
 * recommendations are eligible, what they cost, and which variant is being
 * bought -- and the moment those are written out twice they start disagreeing.
 * That is the defect this codebase already carried once: the retail ladder was
 * written by hand in four places before Pricing existed.
 *
 * Nothing here decides a price for itself. It asks Pricing exactly the way
 * CartsController::serverSidePricing does, with the same record, the same
 * bands and the same quantity, so the figure in the block is by construction
 * the figure the basket charges. A cross-sell that quotes 25 and charges 27 is
 * worse than no cross-sell.
 */
class CrossSellService
{
    /**
     * The eligible recommendations for one product, priced for this customer.
     *
     * $exclude is what the basket already holds. Rule 9: a recommendation the
     * customer has already taken is not a recommendation, and showing it
     * invites them to add it twice.
     */
    public function forProduct($productId, $customer = null, array $exclude = [])
    {
        return $this->forProducts([$productId], $customer, $exclude);
    }

    /**
     * The combined recommendations for several primary products.
     *
     * Rule 9, step by step. The live assignments of every primary product are
     * read in the admin's order; each promotion's products are validated and
     * capped at that promotion's own maximum; the results are merged with the
     * first appearance of a product winning. A laptop, a monitor and a
     * keyboard may all point at the same mouse -- it is ONE recommendation.
     *
     * A promotion's cap counts ELIGIBLE products, so a sold-out accessory is
     * replaced by the next one rather than leaving a gap.
     */
    public function forProducts(array $productIds, $customer = null, array $exclude = [])
    {
        $productIds = collect($productIds)->filter()->map(function ($id) { return (int)$id; })->unique()->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        /*
         * A primary product is never its own recommendation (rule 4), and
         * neither is any other product in the same set -- a basket holding
         * both the laptop and the mouse should not be offered the mouse.
         */
        $excluded = $productIds
            ->merge(collect($exclude)->filter()->map(function ($id) { return (int)$id; }))
            ->unique()
            ->all();

        $assignments = ProductCrossSell::with('crossSell')
            ->whereIn('product_id', $productIds)
            ->active()
            ->ordered()
            ->get()
            ->filter(function ($row) {
                return $row->crossSell && $row->crossSell->isActive();
            });

        if ($assignments->isEmpty()) {
            return [];
        }

        // Each promotion once, at its best position across the primaries.
        $promotions = $assignments->unique('cross_sell_id')->values();

        $members = CrossSellProduct::whereIn('cross_sell_id', $promotions->pluck('cross_sell_id'))
            ->whereNotIn('product_id', $excluded)
            ->ordered()
            ->get()
            ->groupBy('cross_sell_id');

        $candidateIds = $members->flatten()->pluck('product_id')->map(function ($id) { return (int)$id; })->unique()->values()->all();

        // Priced and validated once for every candidate, however many
        // promotions list it.
        $cards = $this->cardsFor($candidateIds, $customer);

        $result = [];
        $seen = [];

        foreach ($promotions as $assignment) {
            $crossSell = $assignment->crossSell;
            $shown = 0;

            foreach ($members->get($crossSell->id, collect()) as $member) {
                if ($shown >= $crossSell->displayLimit()) {
                    break;
                }

                $id = (int)$member->product_id;

                if (isset($seen[$id]) || empty($cards[$id])) {
                    continue;
                }

                $card = $cards[$id];
                $card['cross_sell_id'] = (int)$crossSell->id;

                $result[] = $card;
                $seen[$id] = true;
                $shown++;
            }
        }

        return $result;
    }

    /**
     * Cards for candidate ids, keyed by product id; null where nothing about
     * the product is buyable. No cap here -- the caller applies each
     * promotion's own.
     *
     * Everything is loaded in a handful of queries rather than per candidate.
     * This runs on every add-to-basket, and rule 29 is explicit that a slow
     * or failing recommendation must never hold up the basket itself.
     */
    private function cardsFor(array $candidateIds, $customer)
    {
        if (empty($candidateIds)) {
            return [];
        }

        // Rules 10 and 11: deleted or unpublished is never shown, and the
        // membership row is left alone so it returns on its own.
        $products = Product::whereIn('id', $candidateIds)
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return [];
        }

        $inventories = UpdatedInventory::with('inventory_attributes.attribute_value')
            ->whereIn('product_id', $products->keys())
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        // One query for every band covering every candidate.
        $tiers = BusinessProductPricing::tierMap($customer, $products->keys());

        /*
         * And one for every pricing record, rather than one per candidate.
         *
         * This asked Product::pricingRecord() inside the loop below, which is
         * three queries each -- the row and its two eager loads -- so a block
         * of eight recommendations cost twenty-four. It is the same record
         * either way: pricingRecords() is built from the same query, because
         * a record that differs by one column between the two is a card that
         * quotes one price and a basket that charges another.
         */
        $pricingRecords = Product::pricingRecords($products->keys());

        $cards = [];

        foreach ($products as $id => $product) {
            $cards[(int)$id] = $this->card(
                $product,
                // Priced from the same record CartsController prices from.
                $pricingRecords->get($id),
                $inventories->get($id, collect()),
                $tiers->get($id)
            );
        }

        return $cards;
    }

    /**
     * One recommendation, as the block renders it. Null when nothing about
     * the product is buyable (rule 16): not recommended at all rather than
     * shown greyed out.
     */
    private function card($product, $pricingRecord, $inventories, $tiers)
    {
        // Purchasable is not the same as in stock: a variant that allows
        // backorder is buyable at zero -- see Pricing::isPurchasable.
        $purchasable = $inventories->filter(function ($inventory) {
            return Pricing::isPurchasable($inventory);
        })->values();

        if ($purchasable->isEmpty()) {
            return null;
        }

        /*
         * Every buyable variant, priced individually, because that is what
         * the basket charges: CartsController prices the VARIANT that was
         * added. A card quoting the product's offer price and then charging
         * the variant's own is the "advertised 450, charged 500" defect
         * Pricing::displayRange was written to end.
         */
        $options = $purchasable->map(function ($inventory) use ($pricingRecord, $tiers) {
            $price = Pricing::round(Pricing::unitPrice($pricingRecord, $inventory, 1, $tiers));

            return [
                'inventory_id' => (int)$inventory->id,
                'label' => $this->variantLabel($inventory),
                'sku' => $inventory->sku,
                'price' => $price,
                'old_price' => Pricing::listPrice($pricingRecord, $inventory, $price),
                'quantity' => (int)$inventory->quantity,
                'in_stock' => (int)$inventory->quantity > 0,
                'backorder' => (int)($inventory->is_active ?? 0) === 1,
            ];
        })->values();

        // Rule 14. More than one buyable option means the customer chooses;
        // exactly one means there is nothing to choose.
        $requiresOption = $options->count() > 1;

        $headline = $options->sortBy('price')->first();

        return [
            'id' => (int)$product->id,
            'name' => $product->title,
            'slug' => $product->slug,
            'image' => $product->image,
            'sku' => $headline['sku'] ?? null,
            // Null when the customer has to choose, so nothing downstream can
            // mistake a default for a decision.
            'inventory_id' => $requiresOption ? null : $headline['inventory_id'],
            'price' => $headline['price'],
            'old_price' => $headline['old_price'],
            'requires_option' => $requiresOption,
            'options' => $requiresOption ? $options : [],
            'in_stock' => $options->contains('in_stock', true),
            'backorder' => $options->contains('backorder', true),
            'from' => $requiresOption && $options->pluck('price')->unique()->count() > 1,
        ];
    }

    /** Which variant, spelled out for a shopper. See Helper\Variant. */
    private function variantLabel($inventory)
    {
        return Variant::label($inventory);
    }

    /**
     * Is this exact product-and-variant still buyable, and is it genuinely on
     * offer under something in the basket or on the page?
     *
     * Rules 16 and 30: nothing the page sends is trusted. Between the block
     * rendering and the click the product can be unpublished, the variant can
     * sell out, the promotion can be switched off and the assignment removed.
     *
     * Returns null when it is fine, or a lang KEY plus its replacements when
     * it is not -- so the caller decides how to report it and this class stays
     * out of the HTTP layer.
     */
    public function refusalFor($primaryIds, $productId, $inventoryId)
    {
        $productId = (int)$productId;
        $primaryIds = collect($primaryIds)->filter()->map(function ($id) { return (int)$id; })->values();

        /*
         * It has to actually BE on offer: a live assignment, of a live
         * promotion, that lists this product -- under some OTHER product.
         * Without this the endpoint is an unrestricted "add anything to my
         * basket" call wearing a cross-sell name.
         *
         * "Other" matters. The primaries include everything already in the
         * basket, and rule 18 wants a second tick of the mouse to become
         * "mouse x 2" -- so the mouse being in the basket must not read as
         * the mouse recommending itself. Only the promotion assigned to the
         * mouse is excluded from vouching for it.
         */
        $offered = ProductCrossSell::whereIn('product_id', $primaryIds->reject(function ($id) use ($productId) {
                return $id === $productId;
            })->values()->all())
                ->active()
                ->whereHas('crossSell', function ($q) use ($productId) {
                    $q->where('status', CrossSell::STATUS_ACTIVE)
                        ->whereHas('products', function ($p) use ($productId) {
                            $p->where('product_id', $productId);
                        });
                })
                ->exists();

        if (!$offered) {
            return ['lang.cross_sell_gone', []];
        }

        $product = Product::where('id', $productId)
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->first();

        if (is_null($product)) {
            return ['lang.cross_sell_gone', []];
        }

        $inventories = UpdatedInventory::where('product_id', $productId)->get();

        $purchasable = $inventories->filter(function ($inventory) {
            return Pricing::isPurchasable($inventory);
        })->values();

        if ($purchasable->isEmpty()) {
            return ['lang.cross_sell_out_of_stock', ['product' => $product->title]];
        }

        // Rule 14: a real choice must arrive made. Picking one here would be
        // exactly the arbitrary variation the brief rules out.
        if (is_null($inventoryId)) {
            return $purchasable->count() > 1
                ? ['lang.cross_sell_needs_option', ['product' => $product->title]]
                : null;
        }

        $chosen = $inventories->firstWhere('id', (int)$inventoryId);

        if (is_null($chosen)) {
            // It belongs to a different product, or does not exist at all.
            return ['lang.cross_sell_bad_option', ['product' => $product->title]];
        }

        if (!Pricing::isPurchasable($chosen)) {
            return ['lang.cross_sell_option_unavailable', ['product' => $product->title]];
        }

        return null;
    }

    /**
     * The variant to charge when the customer was not asked to choose. Only
     * reached for a product with exactly one buyable option, so no arbitrary
     * choice is being made -- refusalFor has already refused anything else.
     */
    public function defaultInventoryId($productId)
    {
        $inventory = UpdatedInventory::where('product_id', $productId)
            ->get()
            ->first(function ($row) { return Pricing::isPurchasable($row); });

        return $inventory ? (int)$inventory->id : null;
    }
}
