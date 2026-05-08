<?php

namespace App\Http\Controllers;
use App\Models\SaveForLater;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SaveForLaterController extends Controller
{
    public function action(Request $request)
    {
        $user = Auth::user();

        $cart = Cart::findOrFail($request->cart_id);

        $exists = SaveForLater::where('user_id', $user->id)
            ->where('product_id', $cart->product_id)
            ->where('inventory_id', $cart->inventory_id)
            ->first();

        if ($exists) {
            $exists->delete();
            return response()->json([
                'status' => 'removed'
            ]);
        }

        SaveForLater::create([
            'user_id' => $user->id,
            'product_id' => $cart->product_id,
            'inventory_id' => $cart->inventory_id,
            'quantity' => $cart->quantity,
        ]);

        $cart->delete();

        return response()->json([
            'status' => 'added'
        ]);
    }
}
