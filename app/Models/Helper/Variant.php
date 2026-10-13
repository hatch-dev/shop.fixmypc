<?php

namespace App\Models\Helper;

/**
 * Which variant of a product this is, spelled out for a shopper.
 *
 * "128GB / Midnight", not an inventory id. Every surface that offers a
 * specific variant needs the same sentence, and three of them had grown their
 * own identical copy of it -- CrossSellService, UpsellCampaignsController and,
 * as of the Essentials Bundle showing its chosen variant, BundleService.
 * Three copies of a display rule is how two of them quietly stop agreeing.
 */
class Variant
{
    /**
     * The attribute values of one variant, joined, or its SKU when it has
     * none -- and null when it has neither, so a caller can leave the line out
     * rather than print an empty label.
     *
     * `loadMissing` is a safety net, not the plan: it is a no-op when the
     * caller has eager-loaded, and callers listing many variants MUST
     * eager-load `inventory_attributes.attribute_value` or this turns into a
     * query per row.
     */
    /**
     * $fallbackToSku is for pickers -- a dropdown of variants has to show
     * SOMETHING against each row, so a variant with no attributes falls back
     * to its code. A surface that shows one already-chosen variant wants the
     * opposite: this catalogue's SKUs are values like "1" and "1m", and
     * "Car Phone Holder (1)" reads as a defect rather than as information.
     * Those callers pass false and get null, and leave the line out.
     */
    public static function label($inventory, $fallbackToSku = true)
    {
        if (is_null($inventory)) {
            return null;
        }

        $inventory->loadMissing('inventory_attributes.attribute_value');

        $parts = collect($inventory->inventory_attributes ?? [])
            ->map(function ($attribute) {
                return $attribute->attribute_value->title ?? null;
            })
            ->filter()
            ->values()
            ->all();

        if ($parts) {
            return implode(' / ', $parts);
        }

        return $fallbackToSku ? ($inventory->sku ?: null) : null;
    }
}
