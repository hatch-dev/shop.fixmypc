<?php

namespace App\Http\Controllers;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Product;
use App\Models\ProductUpgradeGroup;
use App\Models\ProductUpgradeGroupOption;
use App\Models\UpdatedInventory;
use App\Services\ProductUpgradeService;
use App\Services\UpgradeRank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Product -> Upgrades, in the admin.
 *
 * Gated on product.* rather than a permission group of its own: this is part
 * of setting a product up, and anyone who may edit the product may configure
 * how it upgrades.
 *
 * The screen this serves has one job beyond CRUD, which is to make the
 * automatic behaviour visible. An admin who has configured nothing still has
 * working upgrades, so the endpoint always returns what the engine WOULD do
 * alongside what has been saved -- otherwise the screen reads as "upgrades are
 * off" when they are on.
 */
class ProductUpgradeConfigController extends ControllerHelper
{
    /**
     * The configuration, the attributes it could use, and a live preview.
     */
    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.view')) {
                return $can;
            }

            $product = Product::find($id);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $service = new ProductUpgradeService();
            $inventories = $service->variantsOf($product->id);

            $saved = ProductUpgradeGroup::with('options')
                ->where('product_id', $product->id)
                ->orderBy('display_order')
                ->get();

            return response()->json(new Response($request->token, [
                'product_id' => (int)$product->id,
                'product_title' => $product->title,
                'variant_count' => $inventories->count(),
                'groups' => $saved->map(function ($group) {
                    return $this->presentGroup($group);
                })->values(),
                /*
                 * What the engine runs on its own when nothing is saved.
                 *
                 * Returned as complete configuration, not just a name, because
                 * the screen seeds its form with these. Saving an untouched
                 * form then persists exactly the behaviour that was already
                 * live, rather than replacing it with whatever the admin
                 * happened to add first -- which is how a Condition group with
                 * no ranks silently switched off working Storage upgrades.
                 */
                'discovered' => $service->discoverGroups($product, $inventories)
                    ->map(function ($group) {
                        return [
                            'id' => null,
                            'name' => $group->name,
                            'attribute_id' => (int)$group->attribute_id,
                            'mode' => $group->mode,
                            'status' => (int)$group->status,
                            'display_order' => (int)$group->display_order,
                            'out_of_stock' => $group->out_of_stock,
                            'customer_type' => $group->customer_type,
                            'max_options' => null,
                            'option_value_ids' => [],
                        ];
                    })->values(),
                'attributes' => $this->attributesOf($inventories),
                'is_using_defaults' => $saved->isEmpty(),
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * The attributes this product's variants actually use, with their values,
     * each value's rank and whether the engine can rank it unaided.
     *
     * `rankable` is the field the screen leans on: it is how an admin finds
     * out that Condition needs ranks typed in before it can upgrade, without
     * having to save a group and watch nothing happen.
     */
    private function attributesOf($inventories)
    {
        $byAttribute = [];

        foreach ($inventories as $inventory) {
            foreach ($inventory->inventory_attributes ?? [] as $row) {
                $value = $row->attribute_value ?? null;

                if (is_null($value)) {
                    continue;
                }

                $byAttribute[(int)$value->attribute_id][(int)$value->id] = $value;
            }
        }

        if (empty($byAttribute)) {
            return [];
        }

        $titles = Attribute::whereIn('id', array_keys($byAttribute))->pluck('title', 'id');

        $out = [];

        foreach ($byAttribute as $attributeId => $values) {
            $rows = [];
            $rankable = true;

            foreach ($values as $value) {
                $rank = UpgradeRank::of($value);

                if (is_null($rank)) {
                    $rankable = false;
                }

                $rows[] = [
                    'id' => (int)$value->id,
                    'title' => $value->title,
                    'rank' => is_null($value->rank) ? null : (int)$value->rank,
                    // What the engine would use with no rank typed in.
                    'derived_rank' => $rank ? $rank['value'] : null,
                    'scale' => $rank ? $rank['scale'] : null,
                ];
            }

            usort($rows, function ($a, $b) {
                return [$a['derived_rank'] ?? PHP_INT_MAX, $a['title']]
                    <=> [$b['derived_rank'] ?? PHP_INT_MAX, $b['title']];
            });

            $out[] = [
                'id' => (int)$attributeId,
                'title' => $titles[$attributeId] ?? '',
                'rankable' => $rankable && count($rows) > 1,
                'value_count' => count($rows),
                'values' => $rows,
            ];
        }

        usort($out, function ($a, $b) { return $a['title'] <=> $b['title']; });

        return $out;
    }

    private function presentGroup($group)
    {
        return [
            'id' => (int)$group->id,
            'name' => $group->name,
            'attribute_id' => (int)$group->attribute_id,
            'mode' => $group->mode,
            'status' => (int)$group->status,
            'display_order' => (int)$group->display_order,
            'out_of_stock' => $group->out_of_stock,
            'customer_type' => $group->customer_type,
            'max_options' => $group->max_options ? (int)$group->max_options : null,
            'option_value_ids' => $group->options
                ->sortBy('display_order')
                ->pluck('attribute_value_id')
                ->map(function ($id) { return (int)$id; })
                ->values(),
        ];
    }


    /**
     * Save this product's groups, replacing what was there.
     *
     * Replaced wholesale rather than diffed: the screen always submits the
     * complete picture, and a diff here would be a second place for "which
     * groups did the admin remove" to be answered differently.
     *
     * Saving an empty list is meaningful and is not the same as never having
     * configured anything: it turns the automatic defaults OFF for this
     * product, which is the only way an admin can say "no upgrades here".
     */
    public function action(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $product = Product::find($id);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($validate = Validation::productUpgradeGroups($request)) {
                return response()->json($validate);
            }

            $groups = $request->input('groups', []);

            if ($refusal = $this->groupsError($product, $groups, $request, $lang)) {
                return $refusal;
            }

            DB::transaction(function () use ($product, $groups) {
                ProductUpgradeGroup::where('product_id', $product->id)->delete();

                foreach ($groups as $index => $row) {
                    $group = ProductUpgradeGroup::create([
                        'product_id' => $product->id,
                        'name' => trim($row['name']),
                        'attribute_id' => (int)$row['attribute_id'],
                        'mode' => in_array($row['mode'] ?? '', ['automatic', 'manual'], true)
                            ? $row['mode']
                            : ProductUpgradeGroup::MODE_AUTOMATIC,
                        'status' => (int)($row['status'] ?? Config::get('constants.status.PUBLIC')),
                        'display_order' => (int)($row['display_order'] ?? ($index + 1)),
                        'out_of_stock' => in_array($row['out_of_stock'] ?? '', ['hide', 'disable'], true)
                            ? $row['out_of_stock']
                            : ProductUpgradeGroup::OUT_OF_STOCK_HIDE,
                        'customer_type' => in_array($row['customer_type'] ?? '', ProductUpgradeGroup::CUSTOMER_TYPES, true)
                            ? $row['customer_type']
                            : 'all',
                        'max_options' => !empty($row['max_options']) ? (int)$row['max_options'] : null,
                        'admin_id' => $this->user->id ?? null,
                    ]);

                    foreach (array_values($row['option_value_ids'] ?? []) as $position => $valueId) {
                        ProductUpgradeGroupOption::create([
                            'product_upgrade_group_id' => $group->id,
                            'attribute_value_id' => (int)$valueId,
                            'display_order' => $position + 1,
                        ]);
                    }
                }
            });

            return $this->find($request, $id);

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Everything about the submitted groups that needs the database.
     */
    private function groupsError($product, $groups, Request $request, $lang)
    {
        $seen = [];

        $usable = (new ProductUpgradeService())->variantsOf($product->id);

        $productAttributeIds = [];

        foreach ($usable as $inventory) {
            foreach ($inventory->inventory_attributes ?? [] as $row) {
                if ($row->attribute_value) {
                    $productAttributeIds[(int)$row->attribute_value->attribute_id] = true;
                }
            }
        }

        foreach ($groups as $row) {
            $attributeId = (int)($row['attribute_id'] ?? 0);

            /*
             * One group per attribute. Two groups on Storage would offer the
             * same variant twice, under two headings, with two sets of rules
             * -- and the unique index would refuse the second one anyway,
             * with a message nobody could act on.
             */
            if (isset($seen[$attributeId])) {
                return response()->json(Validation::error(
                    $request->token, __('lang.upgrade_duplicate_attribute', [], $lang), 'groups'
                ));
            }

            $seen[$attributeId] = true;

            // An attribute none of the variants use can never match anything.
            if (!isset($productAttributeIds[$attributeId])) {
                return response()->json(Validation::error(
                    $request->token, __('lang.upgrade_attribute_unused', [], $lang), 'groups'
                ));
            }

            if (($row['mode'] ?? '') === ProductUpgradeGroup::MODE_MANUAL) {
                $valueIds = array_map('intval', $row['option_value_ids'] ?? []);

                if (empty($valueIds)) {
                    return response()->json(Validation::error(
                        $request->token, __('lang.upgrade_manual_needs_options', [], $lang), 'groups'
                    ));
                }

                /*
                 * A chosen value has to belong to the attribute being
                 * upgraded, or the group would be manually configured to offer
                 * something it can never match.
                 */
                $belong = AttributeValue::whereIn('id', $valueIds)
                    ->where('attribute_id', $attributeId)
                    ->count();

                if ($belong !== count(array_unique($valueIds))) {
                    return response()->json(Validation::error(
                        $request->token, __('lang.upgrade_value_mismatch', [], $lang), 'groups'
                    ));
                }
            }
        }

        return null;
    }


    /**
     * What a customer would actually be offered, for one variant.
     *
     * The screen calls this with the unsaved form so an admin can see the
     * effect of a change before committing to it -- which matters most for
     * the cases that produce nothing, where saving and then hunting the
     * storefront is the only alternative.
     */
    public function preview(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.view')) {
                return $can;
            }

            $product = Product::find($id);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $inventory = UpdatedInventory::where('product_id', $product->id)
                ->when($request->filled('inventory_id'), function ($query) use ($request) {
                    return $query->where('id', (int)$request->input('inventory_id'));
                })
                ->orderByDesc('quantity')
                ->first();

            if (is_null($inventory)) {
                return response()->json(new Response($request->token, ['groups' => []]));
            }

            $service = new ProductUpgradeService();

            /*
             * Previewed against the SAVED configuration, and against the
             * automatic defaults when nothing is saved. An unsaved form is not
             * previewed here on purpose: a preview of rules the engine has
             * never seen is a second implementation of the engine.
             */
            $groups = $service->forVariant($product, $inventory, null, 1);

            return response()->json(new Response($request->token, [
                'inventory_id' => (int)$inventory->id,
                'groups' => $groups,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Set the rank on attribute values.
     *
     * Ranks live on attribute_values, not on the group, because "Excellent
     * beats Very Good" is a fact about the values and is true for every
     * product that uses them. Editing them from this screen is a convenience;
     * the effect is global and the screen says so.
     */
    public function saveRanks(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $ranks = $request->input('ranks', []);

            if (!is_array($ranks) || empty($ranks)) {
                return response()->json(new Response($request->token, ['updated' => 0]));
            }

            $updated = DB::transaction(function () use ($ranks) {
                $count = 0;

                foreach ($ranks as $row) {
                    if (empty($row['id'])) {
                        continue;
                    }

                    $rank = $row['rank'] ?? null;

                    AttributeValue::where('id', (int)$row['id'])->update([
                        // Cleared rather than zeroed: null means "work it out",
                        // and 0 is a legitimate lowest rank.
                        'rank' => ($rank === '' || is_null($rank)) ? null : (int)$rank,
                    ]);

                    $count++;
                }

                return $count;
            });

            return response()->json(new Response($request->token, ['updated' => $updated]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Remove one group.
     *
     * Deleting the last group does NOT restore the automatic defaults: an
     * empty saved configuration means "no upgrades on this product", which is
     * the only way an admin can turn them off. Switching a group to inactive
     * is the other way, and keeps the configuration for later.
     */
    public function delete(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $group = ProductUpgradeGroup::find($id);

            if (is_null($group)) {
                return response()->json(Validation::noDataLang($lang));
            }

            // The options go with it through the cascade on their foreign key.
            $group->delete();

            return response()->json(new Response($request->token, ['deleted' => (int)$id]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
