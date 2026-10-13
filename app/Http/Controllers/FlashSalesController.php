<?php

namespace App\Http\Controllers;

use App\Console\Commands\ExpireFlashSales;
use App\Models\FlashSale;
use App\Models\FlashSaleLang;
use App\Models\FlashSaleProduct;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FlashSaleWindow;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\UpdatedInventory;
use Illuminate\Http\Request;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class FlashSalesController extends ControllerHelper
{
    public function all(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'flash_sale.view')) {
                return $can;
            }

            /*
             * Switch off anything that has lapsed since the list was
             * last looked at, so a finished sale is never shown as
             * Public. One UPDATE that matches nothing almost every
             * time; flash-sale:expire runs the same sweep on a
             * schedule, and this covers installs where cron is not
             * ticking.
             */
            ExpireFlashSales::sweep();

            /*
             * A default sort and a direction the query builder will take.
             * Without either, a list call with no query string threw
             * 'Order direction must be "asc" or "desc"' straight back at
             * the caller.
             */
            $sortable = ['created_at', 'title', 'status', 'start_time', 'end_time'];
            $orderBy = in_array($request->orderby, $sortable, true) ? $request->orderby : 'created_at';
            $direction = strtolower((string)$request->type) === 'asc' ? 'asc' : 'desc';

            $query = FlashSale::orderBy('flash_sales.' . $orderBy, $direction);

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }

            if (in_array($request->filter_status, ['1', '2'], true)) {
                $query = $query->where('flash_sales.status', (int)$request->filter_status);
            }

            /*
             * Where a sale sits against the clock, which is not the same
             * question as whether it is switched on. The list showed
             * 'Active' for a sale that starts next month, because the
             * column was derived from end_time alone and nothing here
             * could narrow to one state or the other.
             */
            $now = FlashSaleWindow::now();

            if ($request->filter_window === 'upcoming') {
                $query = $query->where('flash_sales.start_time', '>', $now);
            } else if ($request->filter_window === 'expired') {
                $query = $query->where('flash_sales.end_time', '<', $now);
            } else if ($request->filter_window === 'running') {
                $query = $query->where('flash_sales.start_time', '<=', $now)
                    ->where('flash_sales.end_time', '>=', $now);
            }

            if ($lang) {
                $query = $query->leftJoin('flash_sale_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                    $join->where('cl.lang', $lang);
                });
                /*
                 * COALESCE, as CategoriesController already does: a sale
                 * with no row for this language listed with a null title,
                 * and opening it put an empty string in the title field.
                 */
                $query = $query->select('flash_sales.*',
                    DB::raw('COALESCE(cl.title, flash_sales.title) as title'));

                if ($request->q) {
                    $query = $query->where(function ($q) use ($request) {
                        $q->where('cl.title', 'LIKE', "%{$request->q}%")
                            ->orWhere('flash_sales.title', 'LIKE', "%{$request->q}%");
                    });
                }
            }else {


                if ($request->q) {
                    $query = $query->where('flash_sales.title', 'LIKE', "%{$request->q}%");
                }
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $item) {
                if ($request->time_zone) {
                    $item['created'] = Utils::convertTimeToUSERzone($item->created_at, $request->time_zone);
                    $item['start_time'] = Utils::convertTimeToUSERzone($item->start_time, $request->time_zone);
                    $item['end_time'] = Utils::convertTimeToUSERzone($item->end_time, $request->time_zone);
                } else {
                    $item['created'] = Utils::formatDate($item->created_at);
                    $item['start_time'] = Utils::formatDate($item->start_time);
                    $item['end_time'] = Utils::formatDate($item->end_time);
                }
            }

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }



    public function action(Request $request, $id = null)
    {
        try {

            $lang = $request->header('language');

            $validate = Validation::flashSale($request);
            if ($validate) {
                return response()->json($validate);
            }

            /*
             * The admin screen posts DD-MM-YYYY HH:mm:ss; the column is a
             * timestamp. The fallback is kept for callers that already send
             * something strtotime understands.
             */
            foreach (['start_time', 'end_time'] as $field) {
                if (!$request->$field) {
                    continue;
                }

                $parsed = \DateTime::createFromFormat('d-m-Y H:i:s', $request->$field);

                $request[$field] = $parsed
                    ? $parsed->format('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s', strtotime($request->$field));
            }

            if ($request->end_time <= $request->start_time) {
                return response()->json(Validation::error($request->token,
                    __('lang.end_time_must', [], $lang)));
            }

            if ($request->time_zone) {
                $request['start_time'] = Utils::convertTimeToUTCzone($request->start_time, $request->time_zone);
                $request['end_time'] = Utils::convertTimeToUTCzone($request->end_time, $request->time_zone);
            }

            unset($request['time_zone']);

            /*
             * Permission first, then the products, then a single write.
             *
             * The order matters. This used to create the flash_sales row and
             * only then look at the products, so anything the products block
             * choked on -- a price the column would not take, a product id
             * that is not there -- left a titled, dated, empty sale behind
             * and answered "Something went wrong" (QA FS-I4).
             */
            if ($id) {
                if ($can = Utils::userCan($this->user, 'flash_sale.edit')) {
                    return $can;
                }

                $existing = FlashSale::find($id);

                if (is_null($existing)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }
            } else {
                if ($can = Utils::userCan($this->user, 'flash_sale.create')) {
                    return $can;
                }
            }

            $rows = $this->reviewProducts($request, $id, $lang);

            if ($rows instanceof \Illuminate\Http\JsonResponse) {
                return $rows;
            }

            $adminId = $request->user()->id;

            $saleId = \Illuminate\Support\Facades\DB::transaction(
                function () use ($request, $id, $lang, $rows, $adminId) {
                    return $this->persist($request, $id, $lang, $rows, $adminId);
                }
            );

            $query = FlashSale::query()->with('products.product');

            if ($lang) {
                $query = $query->leftJoin('flash_sale_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('flash_sales.*',
                    \Illuminate\Support\Facades\DB::raw('COALESCE(cl.title, flash_sales.title) as title'));
            }

            return response()->json(new Response($request->token, $query->find($saleId)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Turn the payload's `products` into rows this controller will act on,
     * or a refusal naming the first thing wrong with them.
     *
     * Nothing here existed. Validation::flashSale checked four scalars and
     * never looked at the products at all, so the price column decided what
     * was acceptable: a negative price was stored and charged as a negative
     * basket line, 0.00 gave the product away, a price above the shelf price
     * raised what the customer paid, an unknown id died on the foreign key,
     * and the same product could be listed twice in one sale.
     *
     * Each shape the admin screen sends is kept: a new row (no id), an edited
     * row (id + updated), and a removed row (id + deleted).
     */
    private function reviewProducts(Request $request, $id, $lang)
    {
        $rows = [];
        $seen = [];

        foreach ((array)$request->products as $value) {
            if (!is_array($value)) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_product', [], $lang)));
            }

            $rowId = key_exists('id', $value) && '' !== trim((string)$value['id'])
                ? (int)$value['id'] : null;
            $deleted = key_exists('deleted', $value) && $value['deleted'];

            // A row on its way out needs an id and nothing else.
            if ($deleted) {
                if ($rowId) {
                    $rows[] = ['action' => 'delete', 'id' => $rowId];
                }
                continue;
            }

            $productId = (int)($value['product']['id'] ?? 0);

            if ($productId < 1) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_product', [], $lang)));
            }

            if (key_exists($productId, $seen)) {
                return response()->json(Validation::error($request->token,
                    __('lang.product_duplicate', [], $lang)));
            }

            $seen[$productId] = true;

            $product = Product::select('id', 'title', 'status', 'selling', 'offered')->find($productId);

            if (is_null($product)) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_product', [], $lang)));
            }

            if ((int)$product->status !== Config::get('constants.status.PUBLIC')) {
                return response()->json(Validation::error($request->token,
                    __('lang.product_not_public', ['product' => $product->title], $lang)));
            }

            if ($refusal = $this->reviewPrice($request, $product, $value, $lang)) {
                return $refusal;
            }

            $type = $value['discount_type'] ?? 'fixed_price';
            $headline = $this->headlinePrice($product, $type, $value);

            /*
             * Blank means no cap, which is every row that predates the
             * column. '0' is not blank -- it is a cap of zero, which would
             * be a sale nobody can buy at, so it is refused.
             */
            $limit = $value['quantity_limit'] ?? null;
            $limit = (is_null($limit) || '' === trim((string)$limit)) ? null : $limit;

            if (!is_null($limit) && (!is_numeric($limit) || (int)$limit < 1)) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_quantity_limit', ['product' => $product->title], $lang)));
            }

            $rows[] = [
                'action' => $rowId ? 'update' : 'add',
                'id' => $rowId,
                'product_id' => $productId,
                'discount_type' => $type,
                'discount_value' => $type === 'fixed_price'
                    ? round((float)$value['price'], 2)
                    : round((float)$value['discount_value'], 2),
                /*
                 * The headline figure, resolved against the product's own
                 * price. Pricing works each line out from ITS base, so
                 * this is not what a variant is charged -- it is what the
                 * twenty queries that select flash_sale_products.price
                 * show, and what recognises the row as a live sale.
                 */
                'price' => $headline,
                'quantity_limit' => is_null($limit) ? null : (int)$limit,
            ];
        }

        return $this->refuseIfPromotedElsewhere($request, $rows, $id, $lang) ?: $rows;
    }


    /**
     * What the product picker needs to know about a page of results.
     *
     * Two things it cannot tell from the product row it already has:
     * whether the product has variants -- which decides whether an absolute
     * sale price is even offered for it -- and whether some other flash sale
     * already holds it, which the save would refuse anyway. Refusing at the
     * point of picking is cheaper than refusing at the point of saving.
     *
     * Bulk, because the picker shows fourteen at a time and one request per
     * row would be fourteen.
     */
    public function productMeta(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'flash_sale.view')) {
                return $can;
            }

            /*
             * Spelled out, not ->map('trim')->map('intval').
             *
             * Collection::map calls back with (value, KEY), so those
             * become trim($id, $key) -- the key read as a character
             * list -- and intval($id, $key) -- the key read as the
             * numeric BASE. Base 1 is invalid and returns 0, so every
             * id after the first was silently dropped and the picker
             * only ever learned about one product per page.
             *
             * It bites here and not at the other ->map('intval') call
             * sites in this codebase because these arrive as STRINGS
             * from explode(); intval ignores the base when the value
             * is already an int.
             */
            $ids = collect(explode(',', (string)$request->ids))
                ->map(function ($id) { return (int)trim($id); })
                ->filter()
                ->unique()
                ->take(60);

            if ($ids->isEmpty()) {
                return response()->json(new Response($request->token, []));
            }

            $products = Product::select('id', 'selling', 'offered')
                ->whereIn('id', $ids)->get();

            /*
             * Which other sale would CLASH with the one being edited --
             * the same test refuseIfPromotedElsewhere makes, so the
             * picker cannot offer a product the save will refuse, or
             * block one it would accept.
             *
             * The window comes from the form. Without it -- the picker
             * opened before the dates are filled in -- fall back to
             * "any sale that has not finished", which is the same
             * answer for every case except a deliberately
             * non-overlapping one.
             *
             * Excludes the sale being edited, or every product already
             * in it would show as taken.
             */
            $now = FlashSaleWindow::now();
            $start = $request->start ?: $now;
            $end = $request->end;

            $held = FlashSaleProduct::with(['flash_sale', 'product'])
                ->whereIn('product_id', $ids)
                ->whereHas('flash_sale', function ($sale) use ($now, $start, $end) {
                    $sale->where('end_time', '>=', $now);

                    if ($end) {
                        $sale->where('start_time', '<=', $end)
                            ->where('end_time', '>=', $start);
                    }
                })
                ->when($request->exclude, function ($query) use ($request) {
                    return $query->where('flash_sale_id', '!=', (int)$request->exclude);
                })
                ->get()
                ->keyBy('product_id');

            $out = [];

            foreach ($products as $product) {
                $tiers = $this->priceTiers($product);

                $variants = UpdatedInventory::where('product_id', $product->id)
                    ->whereHas('inventory_attributes')
                    ->count();

                $row = $held->get($product->id);
                $sale = $row ? $row->flash_sale : null;

                $out[(string)$product->id] = [
                    'variants' => (int)$variants,
                    'tiers' => array_map(function ($tier) { return round($tier, 2); }, $tiers),
                    'held_by' => $sale ? [
                        'id' => (int)$sale->id,
                        'title' => (string)$sale->title,
                        'live' => (int)$sale->status === Config::get('constants.status.PUBLIC')
                            && $sale->start_time <= $now && $sale->end_time >= $now,
                    ] : null,
                ];
            }

            return response()->json(new Response($request->token, $out));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * The price tiers a product sells at, for the admin form.
     *
     * The form has to know these to preview what a reduction does and to
     * refuse an absolute price on a product whose variants differ -- and
     * the product payload it already holds carries only selling/offered,
     * which say nothing about the variants.
     *
     * Scoped to this module rather than added to the shared product list,
     * which several other screens read and none of them need this.
     */
    public function productTiers(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'flash_sale.view')) {
                return $can;
            }

            $product = Product::select('id', 'title', 'selling', 'offered')->find($id);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($request->header('language')));
            }

            return response()->json(new Response($request->token, $this->tiersFor($product)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /** The shape the admin form reads: every tier, and the two bases. */
    private function tiersFor($product)
    {
        $tiers = $this->priceTiers($product);

        return [
            'product_id' => (int)$product->id,
            'tiers' => array_map(function ($tier) { return round($tier, 2); }, $tiers),
            'shelf' => round(Pricing::shelfPrice($product), 2),
            'selling' => round((float)$product->selling, 2),
        ];
    }

    /**
     * Every price tier this product sells at, before any sale.
     *
     * A variation product has one per distinct variant price -- the iPhone
     * 15 Pro has three (699, 799, 999) across its 36 variants. A product
     * with no priced variants has exactly one: its own shelf price. The
     * reduction has to hold for every one of them, because it is applied to
     * each of them (Pricing::flashUnitPrice).
     */
    private function priceTiers($product)
    {
        $tiers = UpdatedInventory::where('product_id', $product->id)
            ->whereHas('inventory_attributes')
            ->where('price', '>', 0)
            ->distinct()
            ->pluck('price')
            ->map(function ($price) { return (float)$price; })
            ->all();

        if (count($tiers) > 0) {
            sort($tiers);
            return $tiers;
        }

        return [Pricing::shelfPrice($product)];
    }


    /**
     * Is this a reduction that works on every tier the product sells at?
     *
     * A percentage or a per-unit amount scales, so it is checked against the
     * cheapest tier -- clear that and every dearer one is fine. An absolute
     * price does not scale at all, which is the whole reason the other two
     * shapes exist: 648 on the iPhone 15 Pro is 7% off the 128GB and 35% off
     * the 512GB. It is refused outright on anything with more than one tier.
     */
    private function reviewPrice(Request $request, $product, array $value, $lang)
    {
        $type = $value['discount_type'] ?? 'fixed_price';
        $tiers = $this->priceTiers($product);
        $cheapest = min($tiers);

        $fail = function ($key, $extra = []) use ($request, $product, $lang) {
            return response()->json(Validation::error($request->token,
                __($key, array_merge(['product' => $product->title], $extra), $lang)));
        };

        if (!in_array($type, ['percentage', 'amount', 'fixed_price'], true)) {
            return $fail('lang.invalid_discount_type');
        }

        if ($type === 'fixed_price') {
            $price = $value['price'] ?? null;

            if (!is_numeric($price)) {
                return $fail('lang.invalid_price');
            }

            $price = round((float)$price, 2);

            if ($price <= 0) {
                return $fail('lang.price_above_zero');
            }

            if (count($tiers) > 1) {
                return $fail('lang.fixed_price_on_variants', [
                    'count' => count($tiers),
                    'low' => number_format($cheapest, 2),
                    'high' => number_format(max($tiers), 2),
                ]);
            }

            if ($price > $cheapest) {
                $key = (float)$product->selling > $cheapest
                    ? 'lang.price_above_offer'
                    : 'lang.price_above_retail';

                return $fail($key, ['price' => number_format($cheapest, 2)]);
            }

            return null;
        }

        $amount = $value['discount_value'] ?? null;

        if (!is_numeric($amount) || (float)$amount <= 0) {
            return $fail('lang.invalid_discount_value');
        }

        $amount = (float)$amount;

        if ($type === 'percentage' && $amount >= 100) {
            return $fail('lang.discount_below_hundred');
        }

        // The cheapest tier is the one a flat amount can wipe out.
        if ($type === 'amount' && $amount >= $cheapest) {
            return $fail('lang.discount_above_price', [
                'price' => number_format($cheapest, 2),
            ]);
        }

        return null;
    }


    /**
     * The figure stored on the row and shown wherever a single flash
     * price is quoted, resolved against the product's own price.
     *
     * On a variation product no single number is the price -- each
     * variant is reduced from its own -- so this is the product-level
     * one, which is also what the legacy `price` column has always been.
     */
    private function headlinePrice($product, $type, array $value)
    {
        $base = Pricing::shelfPrice($product);

        if ($type === 'fixed_price') {
            return round((float)$value['price'], 2);
        }

        $amount = (float)($value['discount_value'] ?? 0);

        $price = $type === 'percentage'
            ? $base - ($base * min($amount, 100) / 100)
            : $base - $amount;

        return round(max(0.0, min($price, $base)), 2);
    }


    /**
     * A product may be in one flash sale AT A TIME.
     *
     * The rule used to be "in one flash sale, ever": any other sale holding
     * the product refused the save, including one that finished weeks ago.
     * That made a product unusable for the rest of its life after a single
     * sale, and there was no way to tell from the message that the offending
     * sale was already over.
     *
     * What actually conflicts is two sales trying to price the same product
     * over the same period, so that is what is tested: the other sale has to
     * still have life left in it, and its window has to overlap the one being
     * saved. Two sales a month apart are fine, and so is re-running a product
     * whose previous sale has ended.
     *
     * Status is deliberately not part of it. A switched-off sale whose window
     * overlaps is still a clash, because switching it back on is one click
     * and would leave two sales live on the same product.
     */
    private function refuseIfPromotedElsewhere(Request $request, array $rows, $id, $lang)
    {
        $productIds = collect($rows)->where('action', '!=', 'delete')->pluck('product_id')->all();

        if (count($productIds) === 0) {
            return null;
        }

        $now = FlashSaleWindow::now();
        $start = $request->start_time;
        $end = $request->end_time;

        $query = FlashSaleProduct::with(['product', 'flash_sale'])
            ->whereIn('product_id', $productIds)
            ->whereHas('flash_sale', function ($sale) use ($now, $start, $end) {
                $sale->where('end_time', '>=', $now)
                    ->where('start_time', '<=', $end)
                    ->where('end_time', '>=', $start);
            });

        if ($id) {
            $query = $query->where('flash_sale_id', '!=', $id);
        }

        $clash = $query->first();

        if (!$clash) {
            return null;
        }

        return response()->json(Validation::error($request->token,
            __('lang.product_clashes', [
                'product' => optional($clash->product)->title,
                'sale' => optional($clash->flash_sale)->title,
            ], $lang)));
    }


    /**
     * Write the sale and its products. Called inside a transaction, so a
     * failure anywhere leaves the tables as they were.
     */
    private function persist(Request $request, $id, $lang, array $rows, $adminId)
    {
        if ($id) {
            /*
             * The editable columns, and only those. This used to be
             * array_filter($request->all()), and update() does not consult
             * $fillable -- admin_id was settable from the payload, which
             * handed the sale to another admin and stepped around the
             * ownership check the vendor paths make.
             */
            $filtered = array_filter(
                $request->only(['title', 'status', 'start_time', 'end_time']),
                function ($element) {
                    return !is_array($element) && !is_null($element) && '' !== trim($element);
                }
            );

            if ($lang) {
                [$langData, $mainData] = Utils::seperateLangData($filtered, ['title']);

                if (count($mainData) > 0) {
                    FlashSale::where('id', $id)->update($mainData);
                }

                $existingLang = FlashSaleLang::where('flash_sale_id', $id)->where('lang', $lang)->first();

                if (!$existingLang) {
                    $langData['flash_sale_id'] = $id;
                    $langData['lang'] = $lang;
                    FlashSaleLang::create($langData);
                } else {
                    FlashSaleLang::where('id', $existingLang->id)->update($langData);
                }
            } else {
                FlashSale::where('id', $id)->update($filtered);
            }
        } else {
            $request['admin_id'] = $adminId;

            if ($lang) {
                [$langData, $mainData] = Utils::seperateLangData($request->all(), ['title']);
                $flashSale = FlashSale::create($mainData);

                $langData['flash_sale_id'] = $flashSale->id;
                $langData['lang'] = $lang;
                FlashSaleLang::create($langData);
            } else {
                $flashSale = FlashSale::create($request->all());
            }

            $id = $flashSale->id;
        }

        $add = [];
        $remove = [];

        foreach ($rows as $row) {
            if ($row['action'] === 'delete') {
                $remove[] = $row['id'];
                continue;
            }

            if ($row['action'] === 'update') {
                FlashSaleProduct::where('id', $row['id'])
                    ->where('flash_sale_id', $id)
                    ->update([
                        'price' => $row['price'],
                        'discount_type' => $row['discount_type'],
                        'discount_value' => $row['discount_value'],
                        'quantity_limit' => $row['quantity_limit'],
                    ]);
                continue;
            }

            $add[] = [
                'product_id' => $row['product_id'],
                'price' => $row['price'],
                'discount_type' => $row['discount_type'],
                'discount_value' => $row['discount_value'],
                'quantity_limit' => $row['quantity_limit'],
                'flash_sale_id' => $id,
                'admin_id' => $adminId,
            ];
        }

        if (count($add) > 0) {
            FlashSaleProduct::insert($add);
        }

        if (count($remove) > 0) {
            FlashSaleProduct::whereIn('id', $remove)->where('flash_sale_id', $id)->delete();
        }

        return $id;
    }
    public function find(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'flash_sale.view')) {
                return $can;
            }

            $query = FlashSale::query();



            if ($lang) {
                $query = $query->with(['products.product' => function ($query) use ($lang) {
                    $query->leftJoin('product_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('products.id', '=', 'avl.product_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('products.id', 'products.title', 'products.image', 'products.selling',
                            'products.offered', 'avl.title');
                }]);



                $query = $query->leftJoin('flash_sale_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('flash_sales.*',
                    DB::raw('COALESCE(cl.title, flash_sales.title) as title'));


            } else {
                $query = $query->with(['products.product']);
            }

            $data = $query->find($id);




            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }

            /*
             * The form needs each product's price tiers to preview a
             * reduction and to know whether an absolute price is even
             * valid for it. Attached here so opening a sale is one
             * request rather than one per row.
             */
            foreach ($data->products as $row) {
                if ($row->product) {
                    $row->tiers = $this->tiersFor($row->product);
                }
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'flash_sale.delete')) {
                return $can;
            }

            $ids = explode(",", $id);

            /*
             * Every id is checked before any row is removed.
             *
             * The loop used to check and delete in the same pass, so a bulk
             * delete naming one id that is not there deleted everything
             * before it and then answered "not found" -- the caller was told
             * nothing happened while half the selection had gone. It also
             * read admin_id off the record before testing it for null, so the
             * ownership check fataled on a missing id rather than refusing.
             */
            $flashSales = [];

            foreach ($ids as $i) {
                $flashSale = FlashSale::with('products')->find($i);

                if (is_null($flashSale)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $flashSale)) {
                    return $isOwner;
                }

                $flashSales[] = $flashSale;
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($flashSales) {
                foreach ($flashSales as $flashSale) {
                    if (count($flashSale['products']) > 0) {
                        FlashSaleProduct::where(['flash_sale_id' => $flashSale->id])->delete();
                    }

                    FlashSaleLang::where('flash_sale_id', $flashSale->id)->delete();
                    $flashSale->delete();
                }
            });






            return response()->json(new Response($request->token, true));

            //return response()->json(Validation::error($request->token, $lang));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
