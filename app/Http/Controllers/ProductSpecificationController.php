<?php

namespace App\Http\Controllers;

use App\Models\ProductSpecification;
use Illuminate\Http\Request;

class ProductSpecificationController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'inventory_id' => 'nullable',
            'specifications' => 'required|array'
        ]);

        ProductSpecification::where(
            'inventory_id',
            $request->inventory_id
        )->delete();

        foreach ($request->specifications as $spec) {

            ProductSpecification::create([

                'product_id' => $request->product_id,

                'inventory_id' => $request->inventory_id,

                'group_id' => $spec['group_id'] ?? null,

                'label' => $spec['label'],

                'value' => $spec['value'],

                'sort_order' => $spec['sort_order'] ?? 0

            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Specifications Saved'
        ]);
    }

    public function getByInventory($inventoryId)
    {
        return ProductSpecification::with('group')
            ->where('inventory_id', $inventoryId)
            ->orderBy('sort_order')
            ->get();
    }
}
