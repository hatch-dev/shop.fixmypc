<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Helper\FileHelper;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\InventoryAttribute;
use App\Models\OrderedProduct;
use App\Models\UpdatedInventory;
use App\Models\InventoryImage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Product;

class UpdatedInventoriesController extends ControllerHelper
{
    /** Variants with order history that this save is retiring (QA audit STK-04). */
    private $retiredInventoryIds = [];

    public function byProduct(Request $request, $productId)
    {
        try {
            $lang = $request->header('language');

            $query = UpdatedInventory::query();

            if ($lang) {
                $query = $query->with(['inventory_attributes.attribute_value' => function ($query) use ($lang) {
                    $query->leftJoin('attribute_value_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('attribute_values.*', 'avl.title');
                }, 'images.image']);
            } else {
                $query = $query->with(['inventory_attributes.attribute_value', 'images.image']);
            }

            $data = $query->where('product_id', $productId)->get();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function action(Request $request, $productId)
    {
        try {
            $lang = $request->header('language');

            /*
             * This controller extended Controller rather than ControllerHelper,
             * so it had no $this->user and ran no permission check at all: any
             * signed-in admin could rewrite any product's stock, prices, SKUs
             * and barcodes, whatever their role granted. Stock is part of
             * editing a product, so it is gated on the same permission the
             * product save uses.
             */
            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $product = Product::find($productId);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                return $isOwner;
            }

            $validate = Validation::updatedInventory($request, 'inventory');
            if ($validate) {
                return response()->json($validate);
            }

            /*
             * QA audit STK-04.
             *
             * Any inventory id that had ever appeared on an order and was
             * missing from the submitted list blocked the whole save with a
             * generic message, so a discontinued variant could never be
             * retired and the admin was not told which one was in the way.
             *
             * A variant with order history is retired rather than deleted —
             * historical orders keep their reference — and the save proceeds.
             * The old check also leant on `array_search(...) > -1`, which reads
             * as a false-vs-int comparison and only worked by accident.
             */
            $orderedInventoryIds = OrderedProduct::where('product_id', $productId)
                ->pluck('inventory_id')
                ->unique()
                ->filter()
                ->all();

            $submittedIds = array_values(array_filter(array_column($request->inventories, 'id')));

            $this->retiredInventoryIds = array_values(array_diff($orderedInventoryIds, $submittedIds));


            /*
             * Saving stock is a delete-and-rewrite across four tables -
             * updated_inventories, inventory_attributes, inventory_images and
             * the carts that point at a retired row. None of it shared a
             * transaction, so a failure partway through left a product with
             * some variants updated, some deleted and some untouched, and no
             * way to tell which. The admin sees one failure message either
             * way; now it means nothing was written.
             */
            DB::beginTransaction();

            $now = Carbon::now();

            // $product is loaded and confirmed above, so this no longer needs a
            // second lookup or a null branch.
            $currentPrice = $product->offered > 0 ? $product->offered : $product->selling;

            $data = UpdatedInventory::where('product_id', $productId)->get(['id']);

            // Preparing the existing inventory attributes for checking
            $deletedInventories = [];

            foreach ($data as $i) {
                array_push($deletedInventories, $i->id);
            }

            /*
             * What each variant currently has attached, as the ROW rather than
             * as the value it points at.
             *
             * This used to collect $j->attribute_value['id'] - the
             * attribute_value id - and the removal below then fed that list to
             * InventoryAttribute::whereIn('id', ...), treating a value id as an
             * inventory_attributes primary key. attribute_values run 1..64 in
             * this catalogue and inventory_attributes 55..273, so unticking a
             * value deleted whichever unrelated variant happened to own the row
             * with that id, and left the row the admin actually unticked in
             * place.
             *
             * Read straight from the table rather than through
             * UpdatedInventory::inventory_attributes(), because that relation
             * selects only inventory_id and attribute_value_id - the row's own
             * id never arrives, which is how the mix-up started. Fetching it
             * here keeps the relation's payload unchanged for the storefront
             * and the admin form, which both serialise it.
             */
            $existingAttributes = [];

            foreach ($deletedInventories as $inventoryId) {
                $existingAttributes[$inventoryId] = [];
            }

            $attributeRows = InventoryAttribute::whereIn('inventory_id', $deletedInventories)
                ->get(['id', 'inventory_id', 'attribute_value_id']);

            foreach ($attributeRows as $row) {
                $existingAttributes[$row->inventory_id][] = [
                    'id' => (int)$row->id,
                    'attribute_value_id' => (int)$row->attribute_value_id,
                ];
            }

            // Add / edit / delete inventories
            foreach ($request->inventories as $i) {


                if(!key_exists('values', $i)){
                    $i['values'] = [];
                }


                $price = 0;
                if (count($i['values']) > 0) {
                    $price = $currentPrice != $i['price'] ? $i['price'] : 0;
                }




                // Inventory updating
                if (key_exists('id', $i) && $i['id'] && key_exists($i['id'], $existingAttributes)) {
                    UpdatedInventory::where('id', $i['id'])->update([
                        'quantity' => $i['quantity'],
                        'sku' => $i['sku'],
                        'price' => $i['price'],
                        'imei' => $i['imei'] ?? null,
                        'barcode' => $i['barcode'] ?? null,
                        'is_active' => $i['is_active'] ?? 0
                    ]);



                    // Add / edit / delete inventory attributes
                    $addedAttributes = [];
                    foreach ($i['values'] as $j) {

                        // Checking if the attribute value already exists on
                        // this variant. Matched on attribute_value_id, and the
                        // row that matched is dropped from the list so that
                        // whatever is left over is what the admin removed.
                        $key = null;
                        foreach ($existingAttributes[$i['id']] as $k => $existing) {
                            if ($existing['attribute_value_id'] === (int)$j['id']) {
                                $key = $k;
                                break;
                            }
                        }

                        if (!is_null($key)) {
                            unset($existingAttributes[$i['id']][$key]);

                        } else {
                            array_push($addedAttributes, [
                                'inventory_id' => $i['id'],
                                'attribute_value_id' => $j['id'],
                                'updated_at' => $now,
                                'created_at' => $now
                            ]);
                        }
                    }

                    // The rows the admin took off this variant, by their own
                    // primary key. Scoped to the variant as well, so that a
                    // future mistake about which id this list holds cannot
                    // reach another product's rows.
                    $removedAttributeIds = array_column($existingAttributes[$i['id']], 'id');

                    if (count($removedAttributeIds) > 0) {
                        InventoryAttribute::where('inventory_id', $i['id'])
                            ->whereIn('id', $removedAttributeIds)
                            ->delete();
                    }

                    if (count($addedAttributes) > 0) {
                        InventoryAttribute::insert($addedAttributes);
                    }


                    // Removing the inventory from array to delete the inventory from database
                    if (($key = array_search($i['id'], $deletedInventories)) !== false) {
                        unset($deletedInventories[$key]);
                    }

                    if(isset($i['image_ids'])){

                        // delete existing images
                        InventoryImage::where('inventory_id', $i['id'])->delete();

                        $imageInsert = [];

                        foreach($i['image_ids'] as $imgId){
                            $imageInsert[] = [
                                'inventory_id' => $i['id'],
                                'product_image_id' => $imgId,
                                'created_at' => $now,
                                'updated_at' => $now
                            ];
                        }

                        if($imageInsert){
                            InventoryImage::insert($imageInsert);
                        }
                    }
                } else {


                    // Inventory adding
                    $addedInventory = UpdatedInventory::create([
                        'product_id' => $productId,
                        'quantity' => $i['quantity'],
                        'sku' => $i['sku'],
                        'price' => $i['price'],
                        'imei' => $i['imei'] ?? null,
                        'barcode' => $i['barcode'] ?? null,
                        'is_active' => $i['is_active'] ?? 0
                    ]);

                    // Adding inventory attributes
                    $addedAttributes = [];
                    foreach ($i['values'] as $j) {
                        array_push($addedAttributes, [
                            'inventory_id' => $addedInventory['id'],
                            'attribute_value_id' => $j['id'],
                            'updated_at' => $now,
                            'created_at' => $now
                        ]);
                    }



                    InventoryAttribute::insert($addedAttributes);

                    if(isset($i['image_ids'])){

                        $imageInsert = [];

                        foreach($i['image_ids'] as $imgId){
                            $imageInsert[] = [
                                'inventory_id' => $addedInventory->id,
                                'product_image_id' => $imgId,
                                'created_at' => $now,
                                'updated_at' => $now
                            ];
                        }

                        if($imageInsert){
                            InventoryImage::insert($imageInsert);
                        }
                    }
                }
            }


            /*
             * QA audit STK-04. Variants that appear on a past order are retired
             * instead of deleted, so the order history still resolves; the rest
             * are removed as before.
             */
            $retired = array_values(array_intersect(
                $deletedInventories,
                $this->retiredInventoryIds ?? []
            ));

            $removable = array_values(array_diff($deletedInventories, $retired));

            if (count($retired) > 0) {
                // Out of stock, backorder off: unreachable from the storefront,
                // but still joinable from ordered_products.
                UpdatedInventory::whereIn('id', $retired)->update([
                    'quantity' => 0,
                    'is_active' => 0
                ]);

                Cart::whereIn('inventory_id', $retired)->delete();
            }

            InventoryAttribute::whereIn('inventory_id', $removable)->delete();


            Cart::whereIn('inventory_id', $removable)->delete();


            UpdatedInventory::whereIn('id', $removable)->delete();

            DB::commit();

            $result = UpdatedInventory::with(['inventory_attributes.attribute_value', 'images.image'])
                ->where('product_id', $productId)
                ->get();

            return response()->json(new Response($request->token, $result));

        } catch (\Exception $ex) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json(Validation::error(null, explode('.', $ex->getMessage())[0], 'inventory'));
        }
    }
}
