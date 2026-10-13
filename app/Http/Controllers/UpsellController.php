<?php

namespace App\Http\Controllers;

use App\Models\Upsell;
use App\Models\UpsellProduct;
use App\Models\Helper\ControllerHelper;
use App\Models\Product;
use App\Models\UpsellCategories;
use App\Models\UpsellSubCategories;
use Illuminate\Http\Request;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Support\Facades\Config;

class UpsellController extends ControllerHelper
{
    /*
     * Admin CRUD for the legacy upsell groups was removed with the
     * Promotions -> Product Recommendations rebuild. What is left here is
     * read-only and storefront-facing: cart.vue still reads a group's
     * products through /upsells/{id}/products.
     */

    public function findProducts(Request $request,$id)
    {
        try {
			
            $UpsellProducts = UpsellProduct::where(['upsell_id' => $id])->get();

            foreach ($UpsellProducts as $item) {
                $product = Product::where(['id' => $item['product_id']])
                    ->get(['title', 'selling', 'offered','image'])
                    ->first();
                $item['title'] = $product['title'];
				$item['image']= $product['image'];
				
                $item['current_price'] = $product['offered'] ? $product['offered'] : $product['selling'];
				$item['selling_price'] = $product['selling'];
				$item['upsell_price'] = $item['price'];
            }

            return response()->json(new Response($request->token, $UpsellProducts));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


	public function getActiveUpsells()
	{
		$upsells = Upsell::select('id', 'title')
			->where('status', 1) // Only active upsells
			->orderBy('title')
			->get()
			->keyBy('id')
			->map(function ($upsell) {
				return [
					'id' => $upsell->id,
					'title' => $upsell->title
				];
			})
			->toArray();

		return response()->json($upsells);
	}
}
