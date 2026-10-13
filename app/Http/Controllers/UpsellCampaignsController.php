<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Helper\Variant;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\UpsellCampaign;
use App\Models\UpsellOption;
use App\Models\UpsellSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Promotions -> Product Recommendations -> Upsell.
 *
 * Admin CRUD for upsell campaigns. Nothing storefront-facing lives here yet:
 * the brief is to get the admin workflow complete and stable first, so there
 * is deliberately no endpoint a shopper could call.
 *
 * Two rules run through the whole file.
 *
 * The first is that a campaign is never half-written. A save replaces its
 * sources and its options together, inside a transaction, because a campaign
 * carrying new sources against old options is a rule nobody configured.
 *
 * The second is that a refusal names the field it is about and says what to do
 * next. "Validation failed" against a five-step wizard is not something an
 * admin can act on.
 */
class UpsellCampaignsController extends ControllerHelper
{
    /* ------------------------------------------------------------------ *
     *  List
     * ------------------------------------------------------------------ */

    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'upsell.view')) {
                return $can;
            }

            $query = UpsellCampaign::withCount(['sources', 'options']);

            if ($this->isVendor) {
                $query = $query->where('created_by', $this->user->id);
            }

            $query = $this->applySearch($query, $request);
            $query = $this->applyFilters($query, $request);

            /*
             * Whitelisted. orderby arrives from the query string and goes
             * straight into an ORDER BY; anything not on this list falls back
             * to priority, which is the order the campaigns actually compete
             * in and so the useful default.
             */
            $sortable = ['priority', 'name', 'status', 'created_at', 'updated_at'];

            $orderBy = in_array($request->orderby, $sortable, true)
                ? $request->orderby
                : 'priority';

            $type = strtolower((string)$request->type) === 'desc' ? 'desc' : 'asc';

            $query = $query->orderBy('upsell_campaigns.' . $orderBy, $type)
                /*
                 * Ties fall back to id so paging is stable. Without it two
                 * campaigns of equal priority can swap pages between requests
                 * and one of them is never seen.
                 */
                ->orderBy('upsell_campaigns.id', 'asc');

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
                $item['schedule_state'] = $item->scheduleState();
                $item['is_live'] = $item->isLive();
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Search by campaign name, product name or SKU.
     *
     * Server-side, and it has to be: "which campaign upgrades this SKU?" can
     * only be answered by a join, and the catalogue is far too large to filter
     * in the browser.
     */
    private function applySearch($query, Request $request)
    {
        $term = trim((string)$request->q);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($outer) use ($term) {
            $outer->where('upsell_campaigns.name', 'LIKE', "%{$term}%")
                ->orWhereIn('upsell_campaigns.id', $this->campaignIdsMatchingProduct($term));
        });
    }

    /**
     * Campaigns naming a product whose title or SKU matches.
     *
     * Both sides are searched. An admin looking for "iPhone 15 Pro 256GB"
     * usually wants the campaign that OFFERS it as much as the one that
     * upgrades away from it, and having to guess which is which is what makes
     * a search box feel broken.
     */
    private function campaignIdsMatchingProduct($term)
    {
        $productIds = Product::where('title', 'LIKE', "%{$term}%")->pluck('id');

        $skuProductIds = UpdatedInventory::where('sku', 'LIKE', "%{$term}%")
            ->pluck('product_id');

        $ids = $productIds->merge($skuProductIds)->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return UpsellSource::whereIn('product_id', $ids)
            ->pluck('upsell_campaign_id')
            ->merge(
                UpsellOption::whereIn('product_id', $ids)->pluck('upsell_campaign_id')
            )
            ->unique()
            ->values()
            ->all();
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('filter_status')) {
            $query = $query->where('status', (int)$request->filter_status);
        }

        if ($request->filled('filter_customer_type')
            && in_array($request->filter_customer_type, UpsellCampaign::CUSTOMER_TYPES, true)) {
            $query = $query->where('customer_type', $request->filter_customer_type);
        }

        $column = [
            'product' => 'show_on_product_page',
            'cart' => 'show_on_cart_page',
            'checkout' => 'show_on_checkout_page',
        ];

        if ($request->filled('filter_location') && isset($column[$request->filter_location])) {
            $query = $query->where($column[$request->filter_location], 1);
        }

        /*
         * The date filter asks about the DATES ALONE, never the on/off switch.
         * "Expired" is the answer to "why is my switched-on campaign showing
         * nothing", and folding status into it would hide exactly the row the
         * admin came looking for.
         */
        if ($request->filled('filter_schedule')) {
            $now = date('Y-m-d H:i:s');

            switch ($request->filter_schedule) {
                case 'active':
                    $query = $query
                        ->where(function ($q) use ($now) {
                            $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
                        })
                        ->where(function ($q) use ($now) {
                            $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
                        });
                    break;

                case 'scheduled':
                    $query = $query->whereNotNull('start_date')->where('start_date', '>', $now);
                    break;

                case 'expired':
                    $query = $query->whereNotNull('end_date')->where('end_date', '<', $now);
                    break;

                case 'none':
                    $query = $query->whereNull('start_date')->whereNull('end_date');
                    break;
            }
        }

        return $query;
    }


    /* ------------------------------------------------------------------ *
     *  Read one
     * ------------------------------------------------------------------ */

    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'upsell.view')) {
                return $can;
            }

            $campaign = UpsellCampaign::find($id);

            if (is_null($campaign)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($ownerError = $this->ownerError($campaign)) {
                return $ownerError;
            }

            return response()->json(new Response($request->token, $this->present($campaign)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * The campaign as the wizard and the view screen need it.
     *
     * Every product carries its own current price, stock and status rather
     * than an id the browser would have to resolve. An admin reviewing a
     * campaign is deciding whether these are the right products, and a list of
     * ids answers nothing.
     */
    private function present(UpsellCampaign $campaign)
    {
        $campaign->load([
            'sources.product', 'sources.inventory',
            'options.product', 'options.inventory',
        ]);

        $data = $campaign->toArray();

        $data['sources'] = $campaign->sources
            ->map(function ($row) { return $this->productCard($row); })
            ->values();

        $data['options'] = $campaign->options
            ->sortBy([['priority', 'asc'], ['id', 'asc']])
            ->map(function ($row) { return $this->productCard($row); })
            ->values();

        $data['sources_count'] = $data['sources']->count();
        $data['options_count'] = $data['options']->count();
        $data['schedule_state'] = $campaign->scheduleState();
        $data['is_live'] = $campaign->isLive();
        $data['blockers'] = $this->activationBlockers($campaign);

        return $data;
    }

    /** One product row, in the shape every admin screen here renders. */
    private function productCard($row)
    {
        $product = $row->product;

        return [
            'id' => $row->id,
            'product_id' => (int)$row->product_id,
            'inventory_id' => $row->inventory_id ? (int)$row->inventory_id : null,
            'priority' => isset($row->priority) ? (int)$row->priority : null,
            'title' => $product->title ?? null,
            'slug' => $product->slug ?? null,
            'image' => $product->image ?? null,
            'selling' => $product->selling ?? null,
            'offered' => $product->offered ?? null,
            'status' => isset($product->status) ? (int)$product->status : null,
            /*
             * Which variant, spelled out. "iPhone 15 Pro" listed three times
             * is not a list an admin can check.
             */
            'variant' => $row->inventory ? $this->variantLabel($row->inventory) : null,
            'sku' => $row->inventory->sku ?? null,
            'in_stock' => $this->inStock($row),
        ];
    }

    /** Which variant, spelled out for a shopper. See Helper\Variant. */
    private function variantLabel($inventory)
    {
        return Variant::label($inventory);
    }

    /**
     * Stock, answered for the thing that was actually chosen.
     *
     * A named variant answers for itself. A whole-product row answers "is ANY
     * variant buyable", because that is what the row means.
     */
    private function inStock($row)
    {
        if ($row->inventory_id) {
            return (int)($row->inventory->quantity ?? 0) > 0;
        }

        return UpdatedInventory::where('product_id', $row->product_id)
            ->where('quantity', '>', 0)
            ->exists();
    }


    /* ------------------------------------------------------------------ *
     *  Create / update
     * ------------------------------------------------------------------ */

    public function action(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');

            if ($validate = Validation::upsellCampaign($request)) {
                return response()->json($validate);
            }

            $existing = null;

            if ($id) {
                if ($can = Utils::userCan($this->user, 'upsell.edit')) {
                    return $can;
                }

                $existing = UpsellCampaign::find($id);

                if (is_null($existing)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if ($ownerError = $this->ownerError($existing)) {
                    return $ownerError;
                }

            } else {
                if ($can = Utils::userCan($this->user, 'upsell.create')) {
                    return $can;
                }
            }

            $sources = $this->normaliseRows($request->input('source_products', []));
            $options = $this->normaliseRows($request->input('upgrade_options', []));

            if ($refusal = $this->selectionError($sources, $options, $request, $lang)) {
                return $refusal;
            }

            $campaign = DB::transaction(function () use ($request, $existing, $sources, $options) {
                return $this->persist($request, $existing, $sources, $options);
            });

            return response()->json(new Response($request->token, $this->present($campaign)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * The selection, deduplicated and cast.
     *
     * A row is a product plus optionally one variant of it, and the PAIR is
     * the identity: "the whole product" and "the 256GB of it" are different
     * choices, and both may legitimately appear, so they are keyed apart.
     */
    private function normaliseRows($rows)
    {
        if (!is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row) || empty($row['product_id'])) {
                continue;
            }

            $productId = (int)$row['product_id'];
            $inventoryId = !empty($row['inventory_id']) ? (int)$row['inventory_id'] : null;

            $key = $productId . ':' . ($inventoryId ?: 'all');

            /*
             * Last one wins, so re-picking a product with a different priority
             * updates it rather than inserting a second row that would then
             * offer the same upgrade twice.
             */
            $clean[$key] = [
                'product_id' => $productId,
                'inventory_id' => $inventoryId,
                'priority' => isset($row['priority']) && (int)$row['priority'] > 0
                    ? (int)$row['priority']
                    // Falls back to the order they were picked in, which is
                    // the order the admin put them in on screen.
                    : $index + 1,
            ];
        }

        return array_values($clean);
    }

    private function rowKey($row)
    {
        return $row['product_id'] . ':' . ($row['inventory_id'] ?: 'all');
    }

    /**
     * Everything about the selection that has to be checked against the
     * database. Each refusal names the field it is about.
     */
    private function selectionError($sources, $options, Request $request, $lang)
    {
        if (empty($sources)) {
            return response()->json(Validation::error(
                $request->token, __('lang.upsell_needs_source', [], $lang), 'source_products'
            ));
        }

        if (empty($options)) {
            return response()->json(Validation::error(
                $request->token, __('lang.upsell_needs_option', [], $lang), 'upgrade_options'
            ));
        }

        /*
         * A choice cannot be its own upgrade.
         *
         * Compared on the product AND the variant, because 128GB upgrading to
         * 256GB is the whole point and both of those are the same product.
         * Only the identical choice on both sides is nonsense.
         */
        $sourceKeys = array_map([$this, 'rowKey'], $sources);
        $optionKeys = array_map([$this, 'rowKey'], $options);

        if (array_intersect($sourceKeys, $optionKeys)) {
            return response()->json(Validation::error(
                $request->token, __('lang.upsell_self_upgrade', [], $lang), 'upgrade_options'
            ));
        }

        /*
         * Every product has to exist and be published. A campaign pointing at
         * a private or deleted product silently offers nothing, and the admin
         * finds out from a customer.
         */
        $productIds = array_unique(array_merge(
            array_column($sources, 'product_id'),
            array_column($options, 'product_id')
        ));

        $published = Product::whereIn('id', $productIds)
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->pluck('id')
            ->all();

        $missing = array_diff($productIds, $published);

        if ($missing) {
            $names = Product::whereIn('id', $missing)->pluck('title')->implode(', ');

            return response()->json(Validation::error(
                $request->token,
                __('lang.upsell_unavailable_products',
                    ['products' => $names ?: implode(', ', $missing)], $lang),
                'source_products'
            ));
        }

        /* A named variant has to belong to the product it was named under. */
        foreach (array_merge($sources, $options) as $row) {
            if (is_null($row['inventory_id'])) {
                continue;
            }

            $belongs = UpdatedInventory::where('id', $row['inventory_id'])
                ->where('product_id', $row['product_id'])
                ->exists();

            if (!$belongs) {
                return response()->json(Validation::error(
                    $request->token, __('lang.upsell_variant_mismatch', [], $lang), 'source_products'
                ));
            }
        }

        if ($dateError = $this->dateError($request, $lang)) {
            return $dateError;
        }

        /*
         * A campaign showing nowhere is configured and invisible. Refused at
         * the point the admin can still see the tick boxes, rather than left
         * to be discovered as "it does not work".
         */
        if (!$request->boolean('show_on_product_page')
            && !$request->boolean('show_on_cart_page')
            && !$request->boolean('show_on_checkout_page')) {
            return response()->json(Validation::error(
                $request->token, __('lang.upsell_no_surface', [], $lang), 'display'
            ));
        }

        return null;
    }

    private function dateError(Request $request, $lang)
    {
        if (!$request->filled('start_date') || !$request->filled('end_date')) {
            return null;
        }

        if (strtotime($request->end_date) < strtotime($request->start_date)) {
            return response()->json(Validation::error(
                $request->token, __('lang.upsell_bad_dates', [], $lang), 'end_date'
            ));
        }

        return null;
    }

    /**
     * Write the campaign and both of its selections.
     *
     * The children are replaced wholesale rather than diffed. A campaign is
     * small, the wizard always submits the complete picture, and a diff here
     * would be a second place for "which rows did the admin actually remove"
     * to be answered differently.
     */
    private function persist(Request $request, $existing, $sources, $options)
    {
        $payload = [
            'name' => trim($request->name),
            'description' => $request->description ?: null,
            'status' => (int)$request->status,
            'customer_type' => $request->customer_type,
            'minimum_cart_value' => $request->filled('minimum_cart_value')
                ? (float)$request->minimum_cart_value
                : null,
            'show_on_product_page' => $request->boolean('show_on_product_page'),
            'show_on_cart_page' => $request->boolean('show_on_cart_page'),
            'show_on_checkout_page' => $request->boolean('show_on_checkout_page'),
            'start_date' => $request->filled('start_date')
                ? date('Y-m-d H:i:s', strtotime($request->start_date))
                : null,
            'end_date' => $request->filled('end_date')
                ? date('Y-m-d H:i:s', strtotime($request->end_date))
                : null,
            'priority' => (int)($request->priority ?: 10),
        ];

        if ($existing) {
            $existing->update($payload);
            $campaign = $existing;
        } else {
            $payload['created_by'] = $this->user->id ?? null;
            $campaign = UpsellCampaign::create($payload);
        }

        $campaign->sources()->delete();
        $campaign->options()->delete();

        foreach ($sources as $row) {
            UpsellSource::create([
                'upsell_campaign_id' => $campaign->id,
                'product_id' => $row['product_id'],
                'inventory_id' => $row['inventory_id'],
            ]);
        }

        foreach ($options as $row) {
            UpsellOption::create([
                'upsell_campaign_id' => $campaign->id,
                'product_id' => $row['product_id'],
                'inventory_id' => $row['inventory_id'],
                'priority' => $row['priority'],
            ]);
        }

        return $campaign->fresh();
    }


    /* ------------------------------------------------------------------ *
     *  Status
     * ------------------------------------------------------------------ */

    /**
     * Switch one or more campaigns on or off.
     *
     * Switching ON is refused for a campaign that cannot actually fire, and
     * the refusal says which ones and why. An admin who flips a switch and
     * sees nothing happen has no other way to find out.
     *
     * Switching OFF is never refused. There is no state a campaign can be in
     * that makes stopping it the wrong thing to do.
     */
    public function toggle(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'upsell.edit')) {
                return $can;
            }

            $lang = $request->header('language');

            $ids = array_filter(array_map('intval', (array)$request->input('ids', [])));
            $status = (int)$request->input('status');

            if (empty($ids) || !in_array($status, [1, 2], true)) {
                return response()->json(Validation::error(
                    $request->token, __('lang.nothing_found', [], $lang)
                ));
            }

            $campaigns = UpsellCampaign::whereIn('id', $ids)->get();

            $changed = [];
            $blocked = [];

            foreach ($campaigns as $campaign) {
                if ($this->ownerError($campaign)) {
                    continue;
                }

                if ($status === 1) {
                    $reasons = $this->activationBlockers($campaign, $lang);

                    if ($reasons) {
                        /*
                         * Named rather than silently skipped, so activating
                         * four campaigns at once says which one did not go and
                         * what to do about it.
                         */
                        $blocked[] = [
                            'id' => $campaign->id,
                            'name' => $campaign->name,
                            'reasons' => $reasons,
                        ];

                        continue;
                    }
                }

                $campaign->update(['status' => $status]);
                $changed[] = $campaign->id;
            }

            return response()->json(new Response($request->token, [
                'changed' => $changed,
                'blocked' => $blocked,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Why this campaign cannot go live, as a list of things to fix.
     *
     * Also returned by find(), so the detail screen can say so before the
     * admin reaches for the switch.
     */
    private function activationBlockers(UpsellCampaign $campaign, $lang = null)
    {
        $reasons = [];

        if (!$campaign->sources()->exists()) {
            $reasons[] = __('lang.upsell_block_no_sources', [], $lang);
        }

        if (!$campaign->options()->exists()) {
            $reasons[] = __('lang.upsell_block_no_options', [], $lang);
        }

        if (!$campaign->show_on_product_page
            && !$campaign->show_on_cart_page
            && !$campaign->show_on_checkout_page) {
            $reasons[] = __('lang.upsell_block_no_surface', [], $lang);
        }

        if ($campaign->start_date && $campaign->end_date
            && strtotime($campaign->end_date) < strtotime($campaign->start_date)) {
            $reasons[] = __('lang.upsell_block_bad_dates', [], $lang);
        }

        if ($campaign->scheduleState() === 'expired') {
            $reasons[] = __('lang.upsell_block_expired', [], $lang);
        }

        /*
         * A campaign whose products have since been unpublished is configured
         * but empty. Checked here rather than only at save time, because the
         * catalogue moves underneath a saved campaign.
         */
        $productIds = $campaign->sources()->pluck('product_id')
            ->merge($campaign->options()->pluck('product_id'))
            ->unique();

        if ($productIds->isNotEmpty()) {
            $live = Product::whereIn('id', $productIds)
                ->where('status', Config::get('constants.status.PUBLIC'))
                ->count();

            if ($live < $productIds->count()) {
                $reasons[] = __('lang.upsell_block_unavailable_products', [], $lang);
            }
        }

        return $reasons;
    }


    /* ------------------------------------------------------------------ *
     *  Duplicate / delete
     * ------------------------------------------------------------------ */

    /**
     * Copy a campaign, products and all.
     *
     * The copy is created switched OFF unless the admin says otherwise, so it
     * never starts competing with its original for the same source products
     * before anyone has edited it.
     */
    public function duplicate(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'upsell.create')) {
                return $can;
            }

            $original = UpsellCampaign::find($id);

            if (is_null($original)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($ownerError = $this->ownerError($original)) {
                return $ownerError;
            }

            $name = trim((string)$request->input('name'));

            if ($name === '') {
                $name = $original->name . ' (Copy)';
            }

            $status = (int)$request->input('status') === 1 ? 1 : 2;

            $copy = DB::transaction(function () use ($original, $name, $status) {
                $copy = $original->replicate(['created_at', 'updated_at']);
                $copy->name = $name;
                $copy->status = $status;
                $copy->created_by = $this->user->id ?? null;
                $copy->save();

                foreach ($original->sources as $row) {
                    UpsellSource::create([
                        'upsell_campaign_id' => $copy->id,
                        'product_id' => $row->product_id,
                        'inventory_id' => $row->inventory_id,
                    ]);
                }

                foreach ($original->options as $row) {
                    UpsellOption::create([
                        'upsell_campaign_id' => $copy->id,
                        'product_id' => $row->product_id,
                        'inventory_id' => $row->inventory_id,
                        'priority' => $row->priority,
                    ]);
                }

                return $copy;
            });

            return response()->json(new Response($request->token, $this->present($copy)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Takes a comma-joined list, so a bulk delete is one transaction. */
    public function delete(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'upsell.delete')) {
                return $can;
            }

            $ids = array_filter(array_map('intval', explode(',', (string)$id)));

            if (empty($ids)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $deleted = DB::transaction(function () use ($ids) {
                $done = [];

                foreach (UpsellCampaign::whereIn('id', $ids)->get() as $campaign) {
                    if ($this->ownerError($campaign)) {
                        continue;
                    }

                    /*
                     * The sources and options go with it through the cascade
                     * on their foreign keys. Deleting them here as well would
                     * be a second answer to the same question.
                     */
                    $campaign->delete();
                    $done[] = $campaign->id;
                }

                return $done;
            });

            if (empty($deleted)) {
                return response()->json(Validation::noDataLang($lang));
            }

            return response()->json(new Response($request->token, ['deleted' => $deleted]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /* ------------------------------------------------------------------ *
     *  Product picker
     * ------------------------------------------------------------------ */

    /**
     * The catalogue, for the two product-picking steps.
     *
     * Paginated and searched on the server for the same reason the list is:
     * this catalogue runs to tens of thousands of rows and the picker has to
     * stay usable from the first keystroke.
     *
     * Each row carries its variants, because a campaign may name one. That is
     * what makes "128GB -> 256GB" expressible without inventing a second
     * variant system alongside updated_inventories.
     */
    public function searchProducts(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'upsell.view')) {
                return $can;
            }

            $query = Product::query()
                ->where('products.status', Config::get('constants.status.PUBLIC'));

            if ($term = trim((string)$request->q)) {
                $skuProductIds = UpdatedInventory::where('sku', 'LIKE', "%{$term}%")
                    ->pluck('product_id');

                $query = $query->where(function ($q) use ($term, $skuProductIds) {
                    $q->where('products.title', 'LIKE', "%{$term}%")
                        ->orWhereIn('products.id', $skuProductIds);
                });
            }

            if ($request->filled('category_id')) {
                $categoryId = (int)$request->category_id;

                $query = $query->whereHas('product_categories', function ($q) use ($categoryId) {
                    $q->where('category_id', $categoryId);
                });
            }

            if ($request->filled('brand_id')) {
                $query = $query->where('products.brand_id', (int)$request->brand_id);
            }

            $query = $query
                /*
                 * product_categories is the pivot, so the name an admin reads
                 * hangs off its own category relation. brand() already selects
                 * its columns.
                 */
                ->with([
                    'product_categories.category',
                    'brand',
                    'product_inventories.inventory_attributes.attribute_value',
                ])
                ->orderBy('products.title', 'asc');

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            $data->getCollection()->transform(function ($product) {
                return $this->pickerRow($product);
            });

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** One catalogue row, carrying everything the picker's columns show. */
    private function pickerRow($product)
    {
        $inventories = $product->product_inventories ?? collect();

        $variants = $inventories->map(function ($inventory) {
            return [
                'id' => (int)$inventory->id,
                'label' => $this->variantLabel($inventory),
                'sku' => $inventory->sku,
                'price' => $inventory->price,
                'quantity' => (int)$inventory->quantity,
                'in_stock' => (int)$inventory->quantity > 0,
            ];
        })->values();

        return [
            'id' => (int)$product->id,
            'title' => $product->title,
            'image' => $product->image,
            'slug' => $product->slug,
            /*
             * The SKU an admin recognises is the first variant's: products
             * themselves do not carry one in this catalogue.
             */
            'sku' => $inventories->first()->sku ?? null,
            'category' => optional(optional($product->product_categories->first())->category)->title,
            'brand' => optional($product->brand)->title,
            'selling' => $product->selling,
            'offered' => $product->offered,
            'status' => (int)$product->status,
            'in_stock' => $variants->contains('in_stock', true),
            'variants' => $variants,
        ];
    }


    /* ------------------------------------------------------------------ *
     *  Shared
     * ------------------------------------------------------------------ */

    /**
     * A vendor only reaches its own campaigns.
     *
     * Utils::isDataOwner reads admin_id; this module records created_by, so
     * the check is written out rather than borrowed.
     */
    private function ownerError(UpsellCampaign $campaign)
    {
        if (!$this->isVendor) {
            return false;
        }

        if ((int)$campaign->created_by === (int)($this->user->id ?? 0)) {
            return false;
        }

        return response()->json(Validation::unauthorized());
    }
}
