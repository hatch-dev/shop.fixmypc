<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Helper\Response;
use App\Models\BusinessProductPricing;
use Carbon\Carbon;
use App\Models\Helper\Validation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class BusinessProductsController extends Controller
{
    public function all(Request $request)
    {
        try {
            $search = $request->q;
            $orderType = $request->type ?? 'desc';

            switch ($request->orderby) {
                case 'title':
                    $orderBy = 'products.title';
                    break;

                case 'created_at':
                default:
                    $orderBy = 'business_product_pricing.created_at';
                    break;
            }

            $query = BusinessProductPricing::select(
                'business_product_pricing.product_id',
                DB::raw('MIN(business_product_pricing.id) as id'),
                'products.title',
                DB::raw('COUNT(*) as total_ranges')
            )
            ->join('products', 'products.id', '=', 'business_product_pricing.product_id');

            if ($search) {
                $query = $query->where('products.title', 'LIKE', "%{$search}%");
            }

            $query->groupBy('business_product_pricing.product_id', 'products.title');

            $query->orderBy($orderBy, $orderType);

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function find(Request $request, $id)
    {
        try {
            $pricing = BusinessProductPricing::where('product_id', $id)->get();

            $data = [
                'product_id' => $id,
                'pricing' => $pricing->map(function ($item) {
                    return [
                        'min' => $item->min_qty,
                        'max' => $item->max_qty,
                        'wholesale_price' => $item->wholesale_price,
                        'discount_type' => $item->discount_type,
                        'discount_value' => $item->discount_value,
                        'final_price' => $item->final_price,
                    ];
                })
            ];

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function action(Request $request, $id = null)
    {
        try {
            
            $productId = $request->product_id;
            $pricingData = $request->pricing;

            if ($id && $id != $productId) {
                BusinessProductPricing::where('product_id', $id)->delete();
            }

            BusinessProductPricing::where('product_id', $productId)->delete();

            foreach ($pricingData as $data) {
                BusinessProductPricing::create([
                    'product_id' => $productId,
                    'min_qty' => $data['min'],
                    'max_qty' => $data['max'],
                    'wholesale_price' => $data['wholesale_price'],
                    'discount_type' => $data['discount_type'],
                    'discount_value' => $data['discount_value'],
                    'final_price' => $data['final_price']
                ]);
            }

            $pricing = BusinessProductPricing::where('product_id', $productId)->get();

            $data = [
                'product_id' => $productId,
                'pricing' => $pricing->map(function ($item) {
                    return [
                        'min' => $item->min_qty,
                        'max' => $item->max_qty,
                        'wholesale_price' => $item->wholesale_price,
                        'discount_type' => $item->discount_type,
                        'discount_value' => $item->discount_value,
                        'final_price' => $item->final_price,
                    ];
                })
            ];


            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {

            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function delete(Request $request, $id)
    {
        try {

            $ids = explode(",", $id);

            foreach ($ids as $productId) {
                BusinessProductPricing::where('product_id', $productId)->delete();
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }
}
