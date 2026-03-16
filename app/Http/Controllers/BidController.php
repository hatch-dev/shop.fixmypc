<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\SupplierNotification;
use App\Models\Product;
use Illuminate\Support\Facades\Crypt;


class BidController extends Controller
{
    public function orderDetail(Request $request, $token){
        try {

            $decoded = json_decode(
                Crypt::decryptString($token),
                true
            );

            $orderId = $decoded['order_id'];
            $supplierId = $decoded['supplier_id'];

            $orderNumber = Order::where(['id' => $orderId])->pluck('order')->first();

            $products = SupplierNotification::where([
                'supplier_id' => $supplierId,
                'order_id'    => $orderId
            ])
            ->with([
                'product' => function ($query) {
                    $query->with('product_images.attributes')
                          ->select(
                              'id',
                              'title',
                              'image',
                              'selling',
                              'offered',
                              'unit'
                          );
                },

                'inventory.inventory_attributes.attribute_value.attribute'
            ])
            ->get();

            $products->each(function ($item) {

                $quantity = \App\Models\OrderedProduct::where([
                    'order_id'     => $item->order_id,
                    'product_id'   => $item->product_id,
                    'inventory_id' => $item->inventory_id
                ])->value('quantity');

                $item->quantity = $quantity ?? 0;
            });

            return response()->json([
                'order_id'    => $orderId,
                'order_number' => $orderNumber,
                'supplier_id' => $supplierId,
                'total_items' => $products->count(),
                'data'        => $products
            ]);
            
        }catch (\Exception $ex) {
            return response()->json([
                'message' => $ex->getMessage()
            ], 500);
        }
    }
}
