<?php

namespace App\Http\Controllers;
use App\Models\Helper\FlashSaleWindow;
use App\Models\Helper\ListingPrices;
use App\Models\RecentlyViewedProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use App\Models\Helper\Utils;
use App\Models\Helper\Response;

class RecentlyViewedController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = RecentlyViewedProduct::query();
        $query = $query->with([
            /*
             * The live flash sale, joined the same way UserWishlist and
             * CompareList join it.
             *
             * `products.*` alone left Pricing::hasActiveFlashSale with no
             * end_time and no price to look at, so it answered "no sale" and
             * the tile advertised the pre-sale price. Every other surface --
             * the shop listing, search, the wishlist, the compare list, the
             * product page -- showed the sale price for the same product, and
             * the basket charged it. Recently viewed was the one that did not,
             * which made it the one place a customer was quoted a price higher
             * than the shop would take.
             *
             * `quantity_limit` is honoured by liveProduct, so a sale that has
             * sold its allowance simply stops joining and the product reverts
             * to its ordinary price rather than becoming unbuyable.
             */
            'product' => function ($query) {
                $query->leftJoin('flash_sales', function ($join) {

                    $join->on('products.id', '=', 'flash_sale_products.product_id');

                    $join->leftJoin('flash_sale_products', function ($join) {
                        $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                    });

                    FlashSaleWindow::liveProduct($join);
                })
                ->select('products.*',
                    'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time');
            },
            /*
             * The price and the backorder flag too: the headline price is the
             * cheapest variation a customer can actually buy, and rows with no
             * price on them made it fall back to the product-level offer.
             */
            'product.product_inventories' => function ($q) {
                $q->select('id', 'product_id', 'quantity', 'price', 'is_active')
                    ->withCount('inventory_attributes');
            }

        ]);

        if ($request->range && $request->range !== 'all') {

            if ($request->range === '7_days') {
                $query->where('updated_at', '>=', now()->subDays(7));
            }

            if ($request->range === '30_days') {
                $query->where('updated_at', '>=', now()->subDays(30));
            }

            if ($request->range === '90_days') {
                $query->where('updated_at', '>=', now()->subDays(90));
            }
        }

        $data = $query->where('user_id', $user->id)
            ->orderBy($request->order_by ?? 'updated_at', $request->type ?? 'desc')
            ->paginate(Config::get('constants.api.PAGINATION'));

        /*
         * Before the `price` assignment below, which overwrites the flash-sale
         * column Pricing reads. This renders the same tile as the shop
         * listing, and the tile was being handed products.offered — so a
         * business or approved-trade customer saw the retail price on
         * everything they had just been looking at.
         */
        ListingPrices::decorateRelation($data, 'product', $user);

        foreach ($data as $item) {
            $item['created'] = $request->time_zone
                ? Utils::formatDate(Utils::convertTimeToUSERzone($item->updated_at, $request->time_zone))
                : Utils::formatDate($item->updated_at);

            if ($item->product) {
                $item->product->price = $item->product->offered ?? $item->product->selling;
            }
        }

        return response()->json(new Response($request->token, $data));
    }

    public function clear()
    {
        $user = Auth::user();
        RecentlyViewedProduct::where('user_id', $user->id)->delete();
        return response()->json([
            'status' => 200,
            'message' => 'History cleared'
        ]);
    }

    public function deleteOne($product_id)
    {
        try {
            $user = Auth::user();
            RecentlyViewedProduct::where('user_id', $user->id)
                ->where('product_id', $product_id)
                ->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Item removed'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => $e->getMessage()
            ]);
        }
    }
}
