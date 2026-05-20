<?php

namespace App\Http\Controllers;

use App\Models\UpdatedUpsell;
use App\Models\UpdatedUpsellProductService;
use App\Models\UpdatedUpsellProductServiceItems;
use App\Models\Helper\ControllerHelper;
use App\Models\Product;
use App\Models\UpdatedInventory;
use Illuminate\Http\Request;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UpdatedUpsellController extends ControllerHelper
{

    private function uploadBase64Image($base64)
    {
        if (!$base64) {
            return null;
        }

        if (!str_contains($base64, 'base64')) {
            return null;
        }

        if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {

            $image = substr($base64, strpos($base64, ',') + 1);
            $type = strtolower($type[1]);
            $image = base64_decode($image);
            $fileName = uniqid() . '.' . $type;
            $folderPath = base_path('uploads');
            $filePath = $folderPath . '/' . $fileName;
            file_put_contents($filePath, $image);
            return "uploads/" . $fileName;
        }else{
            return null;
        }
    }

    public function all(Request $request)
    {
		
        try {
            $lang = $request->header('language');

            $query = UpdatedUpsell::orderBy('updated_upsells.' . $request->orderby, $request->type);

            if ($request->q) {
                $query = $query->where('updated_upsells.title', 'LIKE', "%{$request->q}%");
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }



    public function action(Request $request, $id = null)
    {
        try {

            $lang = $request->header('language');

            $validate = Validation::UpdatedUpsell($request);
            if ($validate) {
                return response()->json($validate);
            }

            $id = $request->id;
            $title = $request->title;
            $status = $request->status;
            $now = date('Y-m-d H:i:s');
            $upsells = $request->upsells ?? [];
            $deleted_ids = $request->deleted_ids;

            if(!empty($id)){
                $updatedUpsell = UpdatedUpsell::findOrFail($id);
                $updatedUpsell->update([
                    'title' => $title,
                    'status' => $status,
                    'updated_at' => $now,
                ]);
                if (!empty($deleted_ids)) {
                    UpdatedUpsellProductService::whereIn('id', $deleted_ids)->delete();
                }
            }else{
                $updated_upsells = [
                    'title' => $title,
                    'status' => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $created = UpdatedUpsell::create($updated_upsells);

                $id = $created->id;
            }

            foreach ($upsells as $upsell) {

                $imagePath = null;

                $imagePath = $this->uploadBase64Image($upsell['image']);

                 if (!empty($upsell['id'])) {

                    $item = UpdatedUpsellProductService::find($upsell['id']);

                    if ($item) {

                        $updateData = [
                            'title' => $upsell['item_title'],
                            'updated_at' => $now,
                        ];

                        if ($imagePath) {
                            $updateData['image'] = $imagePath;
                        }

                        $item->update($updateData);
                    }

                }else{
                    $item = UpdatedUpsellProductService::create([
                        'updated_upsells_id' => $id,
                        'title' => $upsell['item_title'],
                        'image' => $imagePath,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                UpdatedUpsellProductServiceItems::where(
                    'updated_upsell_product_service_id',
                    $item->id
                )->delete();

                if (!empty($upsell['upgrade_groups'])) {
                    foreach ($upsell['upgrade_groups'] as $group) {

                        foreach ($group['options'] as $option) {

                            $product = Product::create([
                                'title' => $upsell['item_title'] . '-' . $option['title'],
                                'image' => $imagePath,
                                'selling' => $option['price'] ?? 0,
                                'status' => 1,
                                'admin_id' => 1,
                                'tax_rule_id' => 1,
                                'shipping_rule_id' => 1,
                            ]);
                        
                            $inventory = UpdatedInventory::create([
                                'product_id' => $product->id,
                                'quantity' => 100,
                                'price' => $option['price'] ?? 0,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        
                            UpdatedUpsellProductServiceItems::create([
                                'updated_upsell_product_service_id' => $item->id,
                                'attribute_id' => $group['attribute_id'] ?? null,
                                'attribute_name' => $group['title'] ?? null,
                                'value_id' => $option['value_id'] ?? null,
                                'product_id' => $product->id,
                                'inventory_id' => $inventory->id,
                                'name' => $option['title'] ?? null,
                                'price' => $option['price'] ?? 0,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                }
            }

            $data = UpdatedUpsell::with([
                'items',
                'items.upgradeOptions'
            ])->findOrFail($id);

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            $query = UpdatedUpsell::query();

            // Load relations
            $query->with([
                'items',
                'items.upgradeOptions'
            ]);

            $data = $query->find($id);

            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }

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
            foreach ($ids as $i) {
                $upsell = UpdatedUpsell::find($i);
                $upsell->delete();
            }
            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

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
	
	// In your UpsellController or API controller
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
