<?php

namespace App\Http\Controllers;
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
            'product' => function ($query) {
                $query->select('products.*');
            },
            'product.product_inventories' => function ($q) {
                $q->select('id', 'product_id', 'quantity');
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
