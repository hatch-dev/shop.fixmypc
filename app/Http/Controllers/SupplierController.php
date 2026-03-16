<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierCategory;
use Illuminate\Http\Request;
use App\Models\Helper\Response;

class SupplierController extends Controller
{

    public function all(Request $request)
    {
        $orderby = $request->orderby ?? 'created_at';
        $orderbyType = $request->orderbyType ?? 'desc';
        $perPage     = $request->per_page ?? 10;
        $search = $request->q ?? null;

        $query = Supplier::with('categories');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhereHas('categories', function ($catQuery) use ($search) {
                    $catQuery->where('name', 'like', "%{$search}%");
                });
            });
        }

        $query->orderBy($orderby, $orderbyType);

        $suppliers = $query->paginate($perPage);

        return response()->json([
            'status' => 200,
            'data' => $suppliers
        ]);
    }

    public function find($id)
    {
        $supplier = Supplier::with('categories')->find($id);

        if (!$supplier) {
            return response()->json([
                'status' => 404,
                'message' => 'Supplier not found'
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => $supplier
        ]);
    }

    public function action(Request $request, $id = null)
    {
        $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:suppliers,email,' . $id,
            'country_code' => 'required|string|max:10',
            'phone'        => 'required|string|max:20',
            'address'      => 'required|string',
            'categories'   => 'required|array|min:1',
            'categories.*' => 'exists:categories,id'
        ]);


       $supplier = Supplier::updateOrCreate(
            ['id' => $id], // condition
            [
                'name'         => $request->name,
                'email'        => $request->email,
                'country_code' => $request->country_code,
                'phone'        => $request->phone,
                'address'      => $request->address
            ]
        );

        $categories = $request->categories ?? [];

        $supplier->categories()->sync($categories);

        return response()->json([
            'status'  => 200,
            'message' => $id
                ? 'Supplier updated successfully'
                : 'Supplier created successfully',
            'data'    => $supplier->load('categories')
        ]);
    }

    public function delete($id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'status' => 404,
                'message' => 'Supplier not found'
            ], 404);
        }

        $supplier->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Supplier deleted successfully'
        ]);
    }
}
