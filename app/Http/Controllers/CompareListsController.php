<?php

namespace App\Http\Controllers;

use App\Models\CompareList;
use App\Models\Helper\ListingPrices;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\ProductSpecification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class CompareListsController extends Controller
{
    public function action(Request $request)
    {
        try {

            $lang = $request->header('language');

            $validate = Validation::user_wishlist($request);
            if ($validate){
                return response()->json($validate);
            }

            $user = Auth::user();

            $list = CompareList::where('product_id', $request->product_id)
                ->where('user_id', $user->id)
                ->get()
                ->first();

            if (is_null($list)) {
                $request['user_id'] = $user->id;
                $list = CompareList::create($request->all());
                return Validation::success($request,
                    __('lang.compare_success', [], $lang),
                    $list);
            } else {
                CompareList::where('id', $list->id)->delete();
                return Validation::success($request,
                    __('lang.compare_removed', [], $lang),
                    null);
            }

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function all(Request $request)
    {

        try {
            $lang = $request->header('language');

            $user = Auth::user();

            $query = CompareList::query();

            if ($lang) {
                $query = $query->with(['product' => function ($query) use ($lang) {

                    $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                        $join->on('pl.product_id', '=', 'products.id');
                        $join->where('pl.lang', $lang);
                    })
                        ->select(['products.id', 'products.slug', 'pl.badge', 'pl.title',
                            'products.selling', 'products.offered',
                            'products.image', 'flash_sale_products.price',
                            'flash_sale_products.discount_type as flash_discount_type',
                            'flash_sale_products.discount_value as flash_discount_value',
                            'flash_sales.end_time']);

                }]);
            } else {
                $query = $query->with(['product']);
            }

            /*
             * The variations, so the tile advertises the cheapest one a
             * customer can actually buy rather than the product-level offer —
             * the same relation the shop listing loads for the same reason.
             */
            $query = $query->with(['product.product_inventories' => function ($q) {
                $q->select('id', 'product_id', 'quantity', 'price', 'is_active')
                    ->withCount('inventory_attributes');
            }]);


            $data = $query->where('user_id', $user->id)
                ->orderBy($request->order_by, $request->type)
                ->paginate(Config::get('constants.api.PAGINATION'));

            if ($request->time_zone) {
                foreach ($data as $item) {
                    $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                }

            } else {
                foreach ($data as $item) {
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }

            /*
             * A comparison whose prices are not the customer's own prices is
             * the one place a wrong figure is guaranteed to be read carefully.
             * Same calculation as every other tile.
             */
            ListingPrices::decorateRelation($data, 'product', $user);

            $this->attachComparison($data);

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * What pages/user/compared.vue sets side by side, for the products on this
     * page: each product's specification rows as `product.specifications`
     * ({group, label, value}, the product page's order) and the key facts every
     * product has as `product.compare` (brand, rating, review count, and the
     * variant options it comes in, e.g. Storage: 128GB, 256GB).
     */
    private function attachComparison($data)
    {
        $productIds = $data->getCollection()->pluck('product_id')->filter()->unique()->values()->all();
        if (!$productIds) {
            return;
        }

        $specs = ProductSpecification::with('group')
            ->whereIn('product_id', $productIds)
            ->orderBy('sort_order')->orderBy('id')
            ->get()->groupBy('product_id');

        $facts = DB::table('products')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->whereIn('products.id', $productIds)
            ->get(['products.id', 'brands.title as brand', 'products.rating', 'products.review_count'])
            ->keyBy('id');

        $options = DB::table('updated_inventories as ui')
            ->join('inventory_attributes as ia', 'ia.inventory_id', '=', 'ui.id')
            ->join('attribute_values as av', 'av.id', '=', 'ia.attribute_value_id')
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->whereIn('ui.product_id', $productIds)
            ->orderBy('a.id')->orderBy('av.rank')->orderBy('av.id')
            ->get(['ui.product_id', 'a.title as attribute', 'av.title as value'])
            ->groupBy('product_id');

        foreach ($data as $item) {
            if (!$item->product) {
                continue;
            }
            $pid = (int)$item->product_id;

            $item->product->setRelation('specifications', collect($specs->get($pid, []))->map(function ($spec) {
                return [
                    'group' => optional($spec->group)->title,
                    'label' => $spec->label,
                    'value' => $spec->value,
                ];
            })->values());

            $fact = $facts->get($pid);
            $item->product->setAttribute('compare', [
                'brand' => $fact->brand ?? null,
                'rating' => $fact && $fact->rating ? round($fact->rating, 1) : 0,
                'review_count' => (int)($fact->review_count ?? 0),
                'options' => collect($options->get($pid, []))->groupBy('attribute')->map(function ($rows, $attribute) {
                    return ['attribute' => $attribute, 'values' => $rows->pluck('value')->unique()->values()];
                })->values(),
            ]);
        }
    }
}
