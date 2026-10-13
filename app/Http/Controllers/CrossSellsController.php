<?php

namespace App\Http\Controllers;

use App\Models\CrossSell;
use App\Models\CrossSellProduct;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Product;
use App\Models\ProductCrossSell;
use App\Models\UpdatedInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Promotions -> Cross-sell, and the assignment section on a product.
 *
 * Two halves, one file, because they are two ends of the same row:
 *
 *   the PROMOTION  -- Promotions -> Cross-sell. Create, edit, switch, delete a
 *                     named set of complementary products. Gated by crosssell.*.
 *   the ASSIGNMENT -- Products -> Product -> Cross-sell. Put an EXISTING
 *                     promotion under a product, order it, switch it, take it
 *                     off. Gated by product.edit, because it is an edit of the
 *                     product and nothing else.
 *
 * A product page can only assign; it cannot create. That is the whole point of
 * the promotion shape -- "Laptop Accessories" is written once and reaches
 * every laptop -- and a create button on the product page is how thirty
 * near-identical promotions come to exist.
 *
 * Nothing here decides a price, a stock level or an eligibility. Those are
 * read at display time from the catalogue by CrossSellService, because all
 * three move underneath a saved row.
 */
class CrossSellsController extends ControllerHelper
{
    /* ------------------------------------------------------------------ *
     *  Promotions: list
     * ------------------------------------------------------------------ */

    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'crosssell.view')) {
                return $can;
            }

            $query = CrossSell::withCount(['products', 'assignments']);

            if ($this->isVendor) {
                $query = $query->where('created_by', $this->user->id);
            }

            $query = $this->applySearch($query, $request);

            if ($request->filled('filter_status')
                && in_array((int)$request->filter_status, CrossSell::STATUSES, true)) {
                $query = $query->where('status', (int)$request->filter_status);
            }

            /*
             * Whitelisted. orderby arrives from the query string and goes
             * straight into an ORDER BY; anything not on this list falls back
             * to name, which is how an admin finds a promotion they made.
             */
            $sortable = ['name', 'status', 'products_count', 'assignments_count', 'created_at', 'updated_at'];

            $orderBy = in_array($request->orderby, $sortable, true) ? $request->orderby : 'name';
            $type = strtolower((string)$request->type) === 'desc' ? 'desc' : 'asc';

            // Ties fall back to id so paging is stable; without it two rows
            // with the same name can swap pages between requests.
            $data = $query->orderBy($orderBy, $type)
                ->orderBy('cross_sells.id', 'asc')
                ->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Search by promotion name, or by the name or SKU of a product it offers.
     *
     * Server-side, and it has to be: "which promotion offers this SKU?" can
     * only be answered by a join, and the catalogue is far too large to
     * filter in the browser.
     */
    private function applySearch($query, Request $request)
    {
        $term = trim((string)$request->q);

        if ($term === '') {
            return $query;
        }

        $productIds = Product::where('title', 'LIKE', "%{$term}%")->pluck('id')
            ->merge(UpdatedInventory::where('sku', 'LIKE', "%{$term}%")->pluck('product_id'))
            ->unique();

        $matching = $productIds->isEmpty()
            ? []
            : CrossSellProduct::whereIn('product_id', $productIds)->pluck('cross_sell_id')->unique()->all();

        return $query->where(function ($outer) use ($term, $matching) {
            $outer->where('cross_sells.name', 'LIKE', "%{$term}%")
                ->orWhereIn('cross_sells.id', $matching);
        });
    }


    /* ------------------------------------------------------------------ *
     *  Promotions: read one
     * ------------------------------------------------------------------ */

    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'crosssell.view')) {
                return $can;
            }

            $crossSell = CrossSell::find($id);

            if (is_null($crossSell)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($ownerError = $this->ownerError($crossSell)) {
                return $ownerError;
            }

            return response()->json(new Response($request->token, $this->present($crossSell)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * The promotion as the form and the view screen need it.
     *
     * Every product carries its own current price, stock and published state
     * rather than an id the browser would have to resolve. An admin reviewing
     * a promotion is deciding whether these are still the right products, and
     * a list of ids answers nothing.
     */
    private function present(CrossSell $crossSell)
    {
        $crossSell->load(['products.product.product_inventories']);

        $data = $crossSell->toArray();

        $data['products'] = $crossSell->products
            ->map(function ($row) { return $this->productCard($row); })
            ->values();

        $data['products_count'] = $data['products']->count();
        $data['assignments_count'] = $crossSell->assignments()->count();
        $data['created'] = Utils::formatDate($crossSell->created_at);

        /*
         * A promotion with nothing to offer cannot go live, and the screen
         * should say so before the admin reaches for the switch.
         */
        $data['blockers'] = $this->activationBlockers($crossSell);

        return $data;
    }

    /**
     * One product row, in the shape every admin screen here renders.
     *
     * `eligible` is the answer to "would a shopper see this right now" -- the
     * same test the storefront applies, reported so an admin can tell a
     * product that has sold out from one that has been unpublished, which
     * look identical from a list of names.
     */
    private function productCard($row)
    {
        $product = $row->product;
        $inventories = $product ? ($product->product_inventories ?? collect()) : collect();

        $inStock = $inventories->contains(function ($inventory) {
            return (int)$inventory->quantity > 0 || (int)$inventory->is_active === 1;
        });

        $published = $product && (int)$product->status === Config::get('constants.status.PUBLIC');

        return [
            'id' => (int)$row->id,
            'product_id' => (int)$row->product_id,
            'sort_order' => (int)$row->sort_order,
            'title' => $product->title ?? null,
            'slug' => $product->slug ?? null,
            'image' => $product->image ?? null,
            // Products themselves carry no SKU in this catalogue; the one an
            // admin recognises is the first variant's.
            'sku' => $inventories->first()->sku ?? null,
            'selling' => $product->selling ?? null,
            'offered' => $product->offered ?? null,
            'product_status' => $product ? (int)$product->status : null,
            'stock' => (int)$inventories->sum('quantity'),
            'in_stock' => $inStock,
            'eligible' => $published && $inStock,
        ];
    }

    /** Why this promotion cannot go live, as a list of things to fix. */
    private function activationBlockers(CrossSell $crossSell, $lang = null)
    {
        $reasons = [];

        if (!$crossSell->products()->exists()) {
            $reasons[] = __('lang.cross_sell_block_no_products', [], $lang);
        }

        return $reasons;
    }


    /* ------------------------------------------------------------------ *
     *  Promotions: create / update
     * ------------------------------------------------------------------ */

    /**
     * The form always submits the complete picture -- name, settings and the
     * full product list -- so the products are replaced wholesale inside one
     * transaction. A promotion carrying its new name against its old products
     * is a rule nobody configured.
     */
    public function action(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');

            if ($validate = Validation::crossSell($request)) {
                return response()->json($validate);
            }

            $existing = null;

            if ($id) {
                if ($can = Utils::userCan($this->user, 'crosssell.edit')) {
                    return $can;
                }

                $existing = CrossSell::find($id);

                if (is_null($existing)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if ($ownerError = $this->ownerError($existing)) {
                    return $ownerError;
                }
            } else {
                if ($can = Utils::userCan($this->user, 'crosssell.create')) {
                    return $can;
                }
            }

            $productIds = $this->normaliseIds($request->input('products', []));

            if ($refusal = $this->productsError($productIds, $request, $lang)) {
                return $refusal;
            }

            /*
             * A promotion with nothing in it can be SAVED -- as a draft, so
             * an admin can name it now and fill it in later -- but it cannot
             * be switched on. Active with nothing to show is configured and
             * invisible, which the admin only finds out from a customer.
             */
            $status = (int)$request->status;

            if ($status === CrossSell::STATUS_ACTIVE && empty($productIds)) {
                return response()->json(Validation::error(
                    $request->token, __('lang.cross_sell_block_no_products', [], $lang), 'products'
                ));
            }

            $crossSell = DB::transaction(function () use ($request, $existing, $productIds, $status) {
                $payload = [
                    'name' => trim($request->name),
                    'description' => $request->description ?: null,
                    'status' => $status,
                    'max_products' => max(
                        CrossSell::MIN_PRODUCTS_SHOWN,
                        min((int)($request->max_products ?: 4), CrossSell::MAX_PRODUCTS_SHOWN)
                    ),
                ];

                if ($existing) {
                    $existing->update($payload);
                    $crossSell = $existing;
                } else {
                    $payload['created_by'] = $this->user->id ?? null;
                    $crossSell = CrossSell::create($payload);
                }

                $crossSell->products()->delete();

                $position = 0;

                foreach ($productIds as $productId) {
                    CrossSellProduct::create([
                        'cross_sell_id' => $crossSell->id,
                        'product_id' => $productId,
                        'sort_order' => ++$position,
                    ]);
                }

                return $crossSell->fresh();
            });

            return response()->json(new Response($request->token, $this->present($crossSell)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * The picked ids, deduplicated and cast, in the order they were sent.
     *
     * Rule 12's "same product twice" is settled here: a picker that sends an
     * id twice in one payload is a UI slip, not something to make the admin
     * fix by hand. The unique index holds against anything that gets past.
     */
    private function normaliseIds($ids)
    {
        if (!is_array($ids)) {
            return [];
        }

        $clean = [];

        foreach ($ids as $id) {
            $id = (int)(is_array($id) ? ($id['product_id'] ?? 0) : $id);

            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return $clean;
    }

    /**
     * Everything about the product list that has to be checked against the
     * database. Each refusal names the products it is about.
     */
    private function productsError(array $productIds, Request $request, $lang)
    {
        if (empty($productIds)) {
            return null;
        }

        if (count($productIds) > CrossSell::MAX_PRODUCTS) {
            return response()->json(Validation::error(
                $request->token,
                __('lang.cross_sell_too_many', ['max' => CrossSell::MAX_PRODUCTS], $lang),
                'products'
            ));
        }

        /*
         * Every product has to exist and be published. A promotion offering a
         * private or deleted product silently offers nothing, and the admin
         * finds out from a customer.
         */
        $published = Product::whereIn('id', $productIds)
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->pluck('id')
            ->map(function ($id) { return (int)$id; })
            ->all();

        $missing = array_diff($productIds, $published);

        if ($missing) {
            $names = Product::whereIn('id', $missing)->pluck('title')->implode(', ');

            return response()->json(Validation::error(
                $request->token,
                __('lang.cross_sell_unavailable', ['products' => $names ?: implode(', ', $missing)], $lang),
                'products'
            ));
        }

        return null;
    }


    /* ------------------------------------------------------------------ *
     *  Promotions: status, delete
     * ------------------------------------------------------------------ */

    /**
     * Switch one or more promotions on, off, or back to draft.
     *
     * Switching ON is refused for a promotion with nothing to show, and the
     * refusal says which ones. Switching OFF is never refused: there is no
     * state a promotion can be in that makes stopping it wrong.
     */
    public function toggle(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'crosssell.edit')) {
                return $can;
            }

            $lang = $request->header('language');

            $ids = array_filter(array_map('intval', (array)$request->input('ids', [])));
            $status = (int)$request->input('status');

            if (empty($ids) || !in_array($status, CrossSell::STATUSES, true)) {
                return response()->json(Validation::error($request->token, __('lang.nothing_found', [], $lang)));
            }

            $changed = [];
            $blocked = [];

            foreach (CrossSell::whereIn('id', $ids)->get() as $crossSell) {
                if ($this->ownerError($crossSell)) {
                    continue;
                }

                if ($status === CrossSell::STATUS_ACTIVE) {
                    $reasons = $this->activationBlockers($crossSell, $lang);

                    if ($reasons) {
                        $blocked[] = ['id' => $crossSell->id, 'name' => $crossSell->name, 'reasons' => $reasons];

                        continue;
                    }
                }

                $crossSell->update(['status' => $status]);
                $changed[] = $crossSell->id;
            }

            return response()->json(new Response($request->token, ['changed' => $changed, 'blocked' => $blocked]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Takes a comma-joined list, so a bulk delete is one transaction.
     *
     * The products and the assignments go with it through the cascades on
     * their foreign keys. The products themselves are untouched: deleting
     * "Laptop Accessories" does not delete the mouse.
     */
    public function delete(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'crosssell.delete')) {
                return $can;
            }

            $ids = array_filter(array_map('intval', explode(',', (string)$id)));

            if (empty($ids)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $deleted = DB::transaction(function () use ($ids) {
                $done = [];

                foreach (CrossSell::whereIn('id', $ids)->get() as $crossSell) {
                    if ($this->ownerError($crossSell)) {
                        continue;
                    }

                    $crossSell->delete();
                    $done[] = $crossSell->id;
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
     *  Promotions: product picker
     * ------------------------------------------------------------------ */

    /**
     * The catalogue, for the promotion form's "Add Products" selector.
     *
     * Paginated and searched on the server: this catalogue runs to tens of
     * thousands of rows, and a picker that filters in the browser stops
     * finding things at the end of the first page.
     *
     * No variant column. A cross-sell promotion offers PRODUCTS; which colour
     * of the laptop bag a customer wants is the customer's choice, made on the
     * storefront where they can see the colours.
     */
    public function searchProducts(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'crosssell.view')) {
                return $can;
            }

            $query = Product::query();

            /*
             * Published only by default, with an explicit filter to see the
             * rest. A promotion can only offer a published product -- the
             * save refuses anything else -- so listing the others by default
             * is offering the admin a choice that will be taken back.
             */
            if ($request->filled('filter_status')) {
                $query = $query->where('products.status', (int)$request->filter_status);
            } else {
                $query = $query->where('products.status', Config::get('constants.status.PUBLIC'));
            }

            if ($term = trim((string)$request->q)) {
                $skuProductIds = UpdatedInventory::where('sku', 'LIKE', "%{$term}%")->pluck('product_id');

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

            $data = $query->with(['product_categories.category', 'brand', 'product_inventories'])
                ->orderBy('products.title', 'asc')
                ->paginate(Config::get('constants.api.PAGINATION'));

            $data->getCollection()->transform(function ($product) {
                $inventories = $product->product_inventories ?? collect();

                return [
                    'id' => (int)$product->id,
                    'title' => $product->title,
                    'image' => $product->image,
                    'slug' => $product->slug,
                    'sku' => $inventories->first()->sku ?? null,
                    'category' => optional(optional($product->product_categories->first())->category)->title,
                    'brand' => optional($product->brand)->title,
                    'selling' => $product->selling,
                    'offered' => $product->offered,
                    'status' => (int)$product->status,
                    'stock' => (int)$inventories->sum('quantity'),
                    'in_stock' => $inventories->contains(function ($inventory) {
                        return (int)$inventory->quantity > 0 || (int)$inventory->is_active === 1;
                    }),
                ];
            });

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * The promotions an admin may assign to a product: the "Assign Cross-sell"
     * selector on the product page.
     *
     * Gated by product.edit, not crosssell.*: it is opened from the product
     * screen by somebody editing the product. Every status is listed and
     * labelled -- an admin assigning a draft they are about to switch on
     * should not be told it does not exist -- but a promotion that already
     * sits under this product is marked, and one that OFFERS this product is
     * marked too, because assigning it would be the self-reference rule 12
     * refuses.
     */
    public function options(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $productId = (int)$request->input('product_id');

            $query = CrossSell::withCount('products');

            if ($term = trim((string)$request->q)) {
                $query = $query->where('name', 'LIKE', "%{$term}%");
            }

            $data = $query->orderBy('name', 'asc')
                ->orderBy('id', 'asc')
                ->paginate(Config::get('constants.api.PAGINATION'));

            $assigned = $productId > 0
                ? ProductCrossSell::where('product_id', $productId)->pluck('cross_sell_id')->map(function ($id) { return (int)$id; })->all()
                : [];

            $offering = $productId > 0
                ? CrossSellProduct::where('product_id', $productId)->pluck('cross_sell_id')->map(function ($id) { return (int)$id; })->all()
                : [];

            $data->getCollection()->transform(function ($row) use ($assigned, $offering) {
                return [
                    'id' => (int)$row->id,
                    'name' => $row->name,
                    'description' => $row->description,
                    'status' => (int)$row->status,
                    'max_products' => (int)$row->max_products,
                    'products_count' => (int)$row->products_count,
                    'already_assigned' => in_array((int)$row->id, $assigned, true),
                    'offers_this_product' => in_array((int)$row->id, $offering, true),
                ];
            });

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /* ------------------------------------------------------------------ *
     *  Assignments: Products -> Product -> Cross-sell
     * ------------------------------------------------------------------ */

    /** The promotions assigned to one product, in the admin's order. */
    public function assignments(Request $request, $productId)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.view')) {
                return $can;
            }

            $product = Product::find($productId);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($ownerError = $this->productOwnerError($product)) {
                return $ownerError;
            }

            return response()->json(new Response($request->token, $this->presentAssignments($product)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    private function presentAssignments(Product $product)
    {
        $rows = ProductCrossSell::with('crossSell')
            ->withCount([])
            ->where('product_id', $product->id)
            ->ordered()
            ->get();

        $counts = CrossSellProduct::whereIn('cross_sell_id', $rows->pluck('cross_sell_id'))
            ->select('cross_sell_id', DB::raw('COUNT(*) as total'))
            ->groupBy('cross_sell_id')
            ->pluck('total', 'cross_sell_id');

        return [
            'product' => ['id' => (int)$product->id, 'title' => $product->title],
            'rows' => $rows->map(function ($row) use ($counts) {
                $crossSell = $row->crossSell;

                return [
                    'id' => (int)$row->id,
                    'cross_sell_id' => (int)$row->cross_sell_id,
                    'sort_order' => (int)$row->sort_order,
                    'status' => (int)$row->status,
                    'name' => $crossSell->name ?? null,
                    'description' => $crossSell->description ?? null,
                    'cross_sell_status' => $crossSell ? (int)$crossSell->status : null,
                    'max_products' => $crossSell ? (int)$crossSell->max_products : null,
                    'products_count' => (int)($counts[$row->cross_sell_id] ?? 0),
                    // Would a shopper see it: this switch AND the promotion's.
                    'live' => (int)$row->status === 1 && $crossSell && $crossSell->isActive()
                        && (int)($counts[$row->cross_sell_id] ?? 0) > 0,
                ];
            })->values(),
        ];
    }

    /**
     * Put an EXISTING promotion under a product. Never creates one.
     *
     * Rule 12, both halves: the same promotion twice is refused with its
     * name, and a promotion that OFFERS this very product is refused too --
     * "buy this laptop, and may we suggest this laptop" is nonsense however
     * it is spelled.
     */
    public function assign(Request $request, $productId)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $product = Product::find($productId);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($ownerError = $this->productOwnerError($product)) {
                return $ownerError;
            }

            $crossSell = CrossSell::find((int)$request->input('cross_sell_id'));

            if (is_null($crossSell)) {
                return response()->json(Validation::error(
                    $request->token, __('lang.cross_sell_not_found', [], $lang), 'cross_sell_id'
                ));
            }

            $already = ProductCrossSell::where('product_id', $product->id)
                ->where('cross_sell_id', $crossSell->id)
                ->exists();

            if ($already) {
                return response()->json(Validation::error(
                    $request->token,
                    __('lang.cross_sell_already_assigned', ['name' => $crossSell->name], $lang),
                    'cross_sell_id'
                ));
            }

            $offersItself = CrossSellProduct::where('cross_sell_id', $crossSell->id)
                ->where('product_id', $product->id)
                ->exists();

            if ($offersItself) {
                return response()->json(Validation::error(
                    $request->token,
                    __('lang.cross_sell_self_assign', ['name' => $crossSell->name], $lang),
                    'cross_sell_id'
                ));
            }

            $next = (int)ProductCrossSell::where('product_id', $product->id)->max('sort_order');

            ProductCrossSell::firstOrCreate(
                ['product_id' => $product->id, 'cross_sell_id' => $crossSell->id],
                ['sort_order' => $next + 1, 'status' => 1]
            );

            return response()->json(new Response($request->token, $this->presentAssignments($product)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Take a promotion off a product. The promotion itself is untouched. */
    public function unassign(Request $request, $productId, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $product = Product::find($productId);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($ownerError = $this->productOwnerError($product)) {
                return $ownerError;
            }

            // Scoped to the product, so a crafted id cannot reach another
            // product's assignments.
            $deleted = ProductCrossSell::where('id', (int)$id)
                ->where('product_id', $product->id)
                ->delete();

            if (!$deleted) {
                return response()->json(Validation::noDataLang($lang));
            }

            $this->resequence($product->id);

            return response()->json(new Response($request->token, $this->presentAssignments($product)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Switch one assignment on or off, or save the order of them all. */
    public function updateAssignments(Request $request, $productId)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $product = Product::find($productId);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($ownerError = $this->productOwnerError($product)) {
                return $ownerError;
            }

            DB::transaction(function () use ($request, $product) {
                if ($request->filled('status') && $request->filled('id')) {
                    ProductCrossSell::where('id', (int)$request->id)
                        ->where('product_id', $product->id)
                        ->update(['status' => (int)$request->status === 1 ? 1 : 2]);
                }

                /*
                 * The complete list, because a drag moves every row below it
                 * -- one row's new position leaves the server to guess what
                 * happened to the rest. Ids not belonging to this product are
                 * ignored rather than refused; the screen can only produce
                 * them through a bug, and failing the whole reorder would lose
                 * the arrangement the admin just made.
                 */
                $position = 0;

                foreach (array_filter(array_map('intval', (array)$request->input('order', []))) as $rowId) {
                    ProductCrossSell::where('id', $rowId)
                        ->where('product_id', $product->id)
                        ->update(['sort_order' => ++$position]);
                }
            });

            return response()->json(new Response($request->token, $this->presentAssignments($product)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Close the gaps in sort_order after a removal, keeping the order. */
    private function resequence($productId)
    {
        $position = 0;

        foreach (ProductCrossSell::where('product_id', $productId)->ordered()->get() as $row) {
            if ((int)$row->sort_order !== ++$position) {
                $row->update(['sort_order' => $position]);
            }
        }
    }


    /* ------------------------------------------------------------------ *
     *  Shared
     * ------------------------------------------------------------------ */

    /** A vendor only reaches its own promotions. */
    private function ownerError(CrossSell $crossSell)
    {
        if (!$this->isVendor || (int)$crossSell->created_by === (int)($this->user->id ?? 0)) {
            return false;
        }

        return response()->json(Validation::unauthorized());
    }

    /**
     * A vendor only edits the assignments of its own products. Which
     * promotions it may assign is not restricted: a promotion is a
     * shop-window decision about the vendor's own product.
     */
    private function productOwnerError(Product $product)
    {
        if (!$this->isVendor || (int)$product->admin_id === (int)($this->user->id ?? 0)) {
            return false;
        }

        return response()->json(Validation::unauthorized());
    }
}
