<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\BusinessProductPricing;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\ProductUpgradeGroup;
use App\Models\UpdatedInventory;
use Illuminate\Support\Facades\Config;

/**
 * The product upgrade engine.
 *
 * An upgrade offers a BETTER VARIANT OF THE PRODUCT THE CUSTOMER IS ALREADY
 * LOOKING AT -- 128GB to 256GB -- and taking one replaces their choice. It is
 * not a second product and it never becomes a second basket line.
 *
 * Nothing about which variants upgrade to which is stored. Given the variant
 * in front of the customer, this class works the answer out from the product's
 * own variant matrix every time:
 *
 *   - hold every attribute EXCEPT the upgrading one fixed, so a storage
 *     upgrade can never quietly change the colour;
 *   - keep the siblings whose value of that attribute ranks higher;
 *   - drop anything nobody can buy;
 *   - price each one through the ordinary pricing engine, for whoever is
 *     asking, at the quantity they are asking for.
 *
 * That last point is the one worth being strict about. The upgrade cost is
 * never stored and never computed here: it is the target variant's price minus
 * the current variant's, both produced by Pricing::unitPrice, so a business
 * band, a flash sale, a clearance and a quantity break apply to an upgrade in
 * exactly the way they apply to a purchase. The figure is derived for display
 * only -- what the till charges is always the target variant's own price.
 */
class ProductUpgradeService
{
    /* Why an upgrade was refused. Used by the storefront and the cart alike. */
    const REFUSE_NO_PRODUCT = 'product_missing';
    const REFUSE_NO_VARIANT = 'variant_missing';
    const REFUSE_FOREIGN_VARIANT = 'variant_not_of_product';
    const REFUSE_SAME_VARIANT = 'variant_unchanged';
    const REFUSE_NOT_AN_UPGRADE = 'not_an_upgrade';
    const REFUSE_UNAVAILABLE = 'unavailable';
    const REFUSE_QUANTITY = 'quantity_unavailable';


    /* ------------------------------------------------------------------ *
     *  Reading
     * ------------------------------------------------------------------ */

    /**
     * Every upgrade on offer for one selected variant.
     *
     * Returns a list of groups, each already filtered, priced, ordered and
     * capped. A group with nothing left to offer is not returned at all --
     * there is no empty "Storage Upgrade" heading.
     */
    public function forVariant($product, $inventory, $customer = null, $quantity = 1)
    {
        if (is_null($product) || is_null($inventory)) {
            return [];
        }

        $quantity = max(1, (int)$quantity);

        $inventories = $this->variantsOf($product->id);

        // Nothing to upgrade between. A single-variant product, or none.
        if ($inventories->count() < 2) {
            return [];
        }

        $current = $inventories->firstWhere('id', (int)$inventory->id);

        if (is_null($current)) {
            return [];
        }

        $currentValues = $this->valueMap($current);

        $pricingProduct = Product::pricingRecord($product->id) ?? $product;
        $tiers = BusinessProductPricing::forCustomer($customer, $product->id);

        $currentPrice = Pricing::round(
            Pricing::unitPrice($pricingProduct, $current, $quantity, $tiers)
        );

        $groups = [];

        foreach ($this->groupsFor($product, $inventories, $customer) as $group) {
            $built = $this->buildGroup(
                $group, $current, $currentValues, $inventories,
                $pricingProduct, $tiers, $quantity, $currentPrice
            );

            if ($built) {
                $groups[] = $built;
            }
        }

        usort($groups, function ($a, $b) {
            return [$a['display_order'], $a['name']] <=> [$b['display_order'], $b['name']];
        });

        return $groups;
    }


    /**
     * The groups to consider for this product.
     *
     * Configuration wins where it exists. Where it does not, the engine
     * discovers its own -- which is what "automatic by default" has to mean:
     * a shop that has never opened the upgrade screen still offers storage
     * upgrades on every product whose variants have them.
     */
    public function groupsFor($product, $inventories = null, $customer = null)
    {
        $configured = ProductUpgradeGroup::with('options')
            ->where('product_id', $product->id)
            ->get();

        if ($configured->isEmpty()) {
            return $this->discoverGroups($product, $inventories ?: $this->variantsOf($product->id));
        }

        return $configured
            ->filter(function ($group) use ($customer) {
                return $group->isActive() && $group->appliesTo($customer);
            })
            ->values();
    }

    /**
     * Groups nobody configured.
     *
     * An attribute qualifies when the product's variants give it more than one
     * value AND those values can be ranked. The ranking test is what keeps
     * Colour out: "Black" and "Blue" have no order, so a colour attribute is
     * silently never treated as upgradeable. Condition stays out for the same
     * reason until an admin ranks its values, which is the right default --
     * the engine offers what it can prove, and nothing else.
     *
     * The returned groups are unsaved models, so everything downstream reads
     * them exactly as it reads configured ones.
     */
    public function discoverGroups($product, $inventories)
    {
        $byAttribute = [];

        foreach ($inventories as $inventory) {
            foreach ($this->valuesOf($inventory) as $value) {
                $byAttribute[(int)$value['attribute_id']][(int)$value['id']] = $value;
            }
        }

        $titles = Attribute::whereIn('id', array_keys($byAttribute))
            ->pluck('title', 'id');

        $groups = collect();
        $order = 1;

        foreach ($byAttribute as $attributeId => $values) {
            if (count($values) < 2) {
                continue;
            }

            $ranked = array_filter($values, function ($value) {
                return !is_null(UpgradeRank::of($value));
            });

            // Every value has to be rankable, and on the same scale as its
            // neighbours -- half a ranked group is not an ordering.
            if (count($ranked) !== count($values)) {
                continue;
            }

            $title = $titles[$attributeId] ?? null;

            if (is_null($title)) {
                continue;
            }

            $group = new ProductUpgradeGroup([
                'product_id' => $product->id,
                'name' => $title . ' Upgrade',
                'attribute_id' => $attributeId,
                'mode' => ProductUpgradeGroup::MODE_AUTOMATIC,
                'status' => Config::get('constants.status.PUBLIC'),
                'display_order' => $order++,
                'out_of_stock' => ProductUpgradeGroup::OUT_OF_STOCK_HIDE,
                'customer_type' => 'all',
                'max_options' => null,
            ]);

            // Marks the group as one nobody saved, so the admin screen can
            // show it as a preview rather than as existing configuration.
            $group->is_discovered = true;

            $groups->push($group);
        }

        return $groups->values();
    }


    /**
     * One group's options, or null when it has nothing to offer.
     */
    private function buildGroup($group, $current, $currentValues, $inventories,
                                $pricingProduct, $tiers, $quantity, $currentPrice)
    {
        $attributeId = (int)$group->attribute_id;

        // The current variant has no value for this attribute at all. Nothing
        // to upgrade FROM, so the group does not apply to this variant.
        if (!isset($currentValues[$attributeId])) {
            return null;
        }

        $currentValue = $currentValues[$attributeId];
        $currentRank = UpgradeRank::of($currentValue);

        // An unrankable current value cannot be a floor, so nothing can be
        // proved to be above it.
        if (is_null($currentRank)) {
            return null;
        }

        $allowed = $group->isManual() ? $this->manualValueIds($group) : null;

        if (!is_null($allowed) && empty($allowed)) {
            // Manual mode with nothing chosen offers nothing. Deliberately not
            // a silent fall back to automatic: an admin who emptied the list
            // meant to.
            return null;
        }

        $options = [];

        foreach ($inventories as $candidate) {
            if ((int)$candidate->id === (int)$current->id) {
                continue;
            }

            $values = $this->valueMap($candidate);

            if (!$this->isCompatible($currentValues, $values, $attributeId)) {
                continue;
            }

            $targetValue = $values[$attributeId];

            if (!UpgradeRank::isHigher($currentRank, UpgradeRank::of($targetValue))) {
                continue;
            }

            if (!is_null($allowed) && !in_array((int)$targetValue['id'], $allowed, true)) {
                continue;
            }

            $available = $this->availability($candidate, $quantity);

            if (!$available['purchasable'] && !$group->showsUnavailable()) {
                continue;
            }

            $price = Pricing::round(
                Pricing::unitPrice($pricingProduct, $candidate, $quantity, $tiers)
            );

            $options[] = [
                'inventory_id' => (int)$candidate->id,
                'attribute_value_id' => (int)$targetValue['id'],
                'label' => $targetValue['title'],
                'sku' => $candidate->sku,
                'price' => $price,
                /*
                 * What the upgrade costs on top of what is selected now, at
                 * this customer's rates and this quantity. Signed on purpose:
                 * a flash sale can make a bigger variant the same price or
                 * cheaper, and saying "+€0.00" or "-€50.00" is honest where
                 * hiding it is not. Never used to charge anything -- the till
                 * takes `price`.
                 */
                'difference' => Pricing::round($price - $currentPrice),
                'in_stock' => $available['in_stock'],
                'backorder' => $available['backorder'],
                'available' => $available['purchasable'] && $available['enough'],
                'quantity' => $available['quantity'],
                'reason' => $available['reason'],
                'rank' => UpgradeRank::of($targetValue)['value'],
            ];
        }

        if (empty($options)) {
            return null;
        }

        // Best-ranked first, so a cap keeps the nearest upgrades rather than
        // whichever the database returned first.
        usort($options, function ($a, $b) {
            return [$a['rank'], $a['inventory_id']] <=> [$b['rank'], $b['inventory_id']];
        });

        if ($group->max_options > 0) {
            $options = array_slice($options, 0, (int)$group->max_options);
        }

        $currentAvailability = $this->availability($current, $quantity);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'attribute_id' => $attributeId,
            'mode' => $group->mode,
            'display_order' => (int)$group->display_order,
            'is_discovered' => (bool)($group->is_discovered ?? false),
            /*
             * The variant the customer is on, rendered as the ticked first
             * option so the group reads as a choice between siblings rather
             * than as a list of extras.
             */
            'current' => [
                'inventory_id' => (int)$current->id,
                'attribute_value_id' => (int)$currentValue['id'],
                'label' => $currentValue['title'],
                'sku' => $current->sku,
                'price' => $currentPrice,
                'difference' => 0.0,
                'in_stock' => $currentAvailability['in_stock'],
                'backorder' => $currentAvailability['backorder'],
                'available' => true,
                'quantity' => $currentAvailability['quantity'],
            ],
            'options' => array_map(function ($option) {
                unset($option['rank']);
                return $option;
            }, $options),
        ];
    }


    /* ------------------------------------------------------------------ *
     *  Validation
     * ------------------------------------------------------------------ */

    /**
     * Is this target a legitimate upgrade of this variant, right now?
     *
     * Called again from the cart and again at checkout, because everything it
     * checks can change between the page rendering and the customer clicking:
     * the variant can sell out, the group can be switched off, the account can
     * change and the quantity can move past what is left.
     *
     * Returns ['ok' => bool, 'reason' => string|null, 'option' => array|null].
     */
    public function validate($product, $currentInventory, $targetInventoryId,
                             $customer = null, $quantity = 1)
    {
        if (is_null($product)) {
            return $this->refuse(self::REFUSE_NO_PRODUCT);
        }

        $target = UpdatedInventory::find($targetInventoryId);

        if (is_null($target)) {
            return $this->refuse(self::REFUSE_NO_VARIANT);
        }

        /*
         * The one check that is a security boundary rather than a courtesy: a
         * request may name any inventory id it likes, and a variant of another
         * product would otherwise be priced from ITS price against THIS
         * product's tax rule and stock.
         */
        if ((int)$target->product_id !== (int)$product->id) {
            return $this->refuse(self::REFUSE_FOREIGN_VARIANT);
        }

        if (is_null($currentInventory)) {
            return $this->refuse(self::REFUSE_NO_VARIANT);
        }

        if ((int)$currentInventory->id === (int)$target->id) {
            return $this->refuse(self::REFUSE_SAME_VARIANT);
        }

        $offered = null;

        foreach ($this->forVariant($product, $currentInventory, $customer, $quantity) as $group) {
            foreach ($group['options'] as $option) {
                if ((int)$option['inventory_id'] === (int)$target->id) {
                    $offered = $option + ['group' => $group['name'], 'group_id' => $group['id']];
                    break 2;
                }
            }
        }

        if (is_null($offered)) {
            return $this->refuse(self::REFUSE_NOT_AN_UPGRADE);
        }

        if (!$offered['in_stock'] && !$offered['backorder']) {
            return $this->refuse(self::REFUSE_UNAVAILABLE, $offered);
        }

        if (!$offered['available']) {
            return $this->refuse(self::REFUSE_QUANTITY, $offered);
        }

        return ['ok' => true, 'reason' => null, 'option' => $offered];
    }

    private function refuse($reason, $option = null)
    {
        return ['ok' => false, 'reason' => $reason, 'option' => $option];
    }


    /* ------------------------------------------------------------------ *
     *  Shared
     * ------------------------------------------------------------------ */

    /**
     * Every variant of a product, with its attribute values loaded.
     *
     * One query for the lot. The alternative -- a lookup per candidate inside
     * the compatibility loop -- is a query per variant per group on a page
     * that already makes several round trips.
     */
    public function variantsOf($productId)
    {
        return UpdatedInventory::with('inventory_attributes.attribute_value')
            ->where('product_id', $productId)
            ->orderBy('id')
            ->get();
    }

    /** This variant's attribute values, keyed by attribute. */
    private function valueMap($inventory)
    {
        $map = [];

        foreach ($this->valuesOf($inventory) as $value) {
            $map[(int)$value['attribute_id']] = $value;
        }

        return $map;
    }

    private function valuesOf($inventory)
    {
        $values = [];

        foreach ($inventory->inventory_attributes ?? [] as $row) {
            $value = $row->attribute_value ?? null;

            if (is_null($value)) {
                continue;
            }

            $values[] = [
                'id' => (int)$value->id,
                'title' => $value->title,
                'attribute_id' => (int)$value->attribute_id,
                'rank' => $value->rank,
            ];
        }

        return $values;
    }

    /**
     * Does this candidate differ from the current variant ONLY in the
     * upgrading attribute?
     *
     * This is what stops a storage upgrade switching the colour. Black + 128GB
     * offers Black + 256GB and Black + 512GB, and never Blue anything.
     *
     * Both directions are checked, so a candidate carrying an extra attribute
     * the current variant does not have is incompatible rather than a match.
     */
    private function isCompatible($currentValues, $candidateValues, $attributeId)
    {
        if (!isset($candidateValues[$attributeId])) {
            return false;
        }

        if (count($currentValues) !== count($candidateValues)) {
            return false;
        }

        foreach ($currentValues as $id => $value) {
            if ($id === $attributeId) {
                continue;
            }

            if (!isset($candidateValues[$id])) {
                return false;
            }

            if ((int)$candidateValues[$id]['id'] !== (int)$value['id']) {
                return false;
            }
        }

        // The upgrading attribute has to actually differ, or this is the same
        // configuration under another inventory row.
        return (int)$candidateValues[$attributeId]['id'] !== (int)$currentValues[$attributeId]['id'];
    }

    /**
     * Can this variant be bought, and enough of it?
     *
     * `is_active` is this catalogue's backorder flag, not an enabled flag --
     * Pricing::isPurchasable reads it that way and so does the cart, and a
     * second opinion here is how the page and the till end up disagreeing.
     */
    private function availability($inventory, $quantity)
    {
        $stock = (int)($inventory->quantity ?? 0);
        $backorder = (int)($inventory->is_active ?? 0) === 1;
        $purchasable = Pricing::isPurchasable($inventory);

        $enough = $backorder || $stock >= max(1, (int)$quantity);

        $reason = null;

        if (!$purchasable) {
            $reason = self::REFUSE_UNAVAILABLE;
        } elseif (!$enough) {
            $reason = self::REFUSE_QUANTITY;
        }

        return [
            'quantity' => $stock,
            'in_stock' => $stock > 0,
            'backorder' => $backorder,
            'purchasable' => $purchasable,
            'enough' => $enough,
            'reason' => $reason,
        ];
    }

    /** The values an admin picked, in manual mode. */
    private function manualValueIds($group)
    {
        return $group->options
            ->pluck('attribute_value_id')
            ->map(function ($id) { return (int)$id; })
            ->all();
    }
}
