<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Helper\Response;
use App\Models\Helper\Validation;
use App\Models\SaveForLater;
use Illuminate\Http\Request;

class SaveForLaterController extends Controller
{
    /**
     * Narrow a query to whoever is asking.
     *
     * Same ownership rule the cart uses: a signed-in customer owns rows by
     * `user_id`, a guest by the token the server issued it. Returns null when
     * the caller is neither, so the endpoint refuses rather than falling back
     * to every row.
     */
    private function ownerScope(Request $request, $query)
    {
        if ($request->user('user')) {
            return $query->where('user_id', $request->user('user')->id);
        }

        if ($request->user_token) {
            return $query->whereNull('user_id')
                ->where('user_token', $request->user_token);
        }

        return null;
    }

    /**
     * The owner columns to stamp on a new row.
     */
    private function ownerAttributes(Request $request)
    {
        if ($request->user('user')) {
            return ['user_id' => $request->user('user')->id];
        }

        // The guest record is what makes the token resolvable later, and what
        // ValidateGuestToken checks.
        if (!GuestUser::where('user_token', $request->user_token)->exists()) {
            GuestUser::create(['user_token' => $request->user_token]);
        }

        return ['user_token' => $request->user_token];
    }

    /**
     * Move a cart line into the saved list, or put a saved line back.
     */
    public function action(Request $request)
    {
        try {
            $validate = Validation::validationMessage($request, ['cart_id' => 'required']);
            if ($validate) {
                return response()->json($validate);
            }

            /*
             * Scope the cart lookup to the caller.
             *
             * This was Cart::findOrFail($request->cart_id) against the raw id,
             * so any id saved — and deleted — someone else's basket line.
             */
            $cartQuery = $this->ownerScope($request, Cart::query());

            if (is_null($cartQuery)) {
                return response()->json(Validation::unauthorized());
            }

            $cart = $cartQuery->where('id', $request->cart_id)->first();

            if (!$cart) {
                return response()->json(Validation::unauthorized());
            }

            $existing = $this->ownerScope($request, SaveForLater::query())
                ->where('product_id', $cart->product_id)
                ->where('inventory_id', $cart->inventory_id)
                ->first();

            if ($existing) {
                $existing->delete();

                return response()->json(new Response($request->token, ['status' => 'removed']));
            }

            SaveForLater::create(array_merge($this->ownerAttributes($request), [
                'product_id' => $cart->product_id,
                'inventory_id' => $cart->inventory_id,
                'quantity' => $cart->quantity,
            ]));

            $cart->delete();

            return response()->json(new Response($request->token, ['status' => 'added']));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Everything the caller has put aside, with enough of the product to
     * render a row and link back to it.
     */
    public function all(Request $request)
    {
        try {
            $lang = $request->header('language');

            $query = $this->ownerScope($request, SaveForLater::query());

            if (is_null($query)) {
                return response()->json(Validation::unauthorized());
            }

            $query = $query->with([
                'product' => function ($query) use ($lang) {

                    if ($lang) {
                        $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                            $join->on('pl.product_id', '=', 'products.id');
                            $join->where('pl.lang', $lang);
                        });
                    }
                },
                'inventory'
            ]);

            $data = $query->orderBy('id', 'desc')->get();

            // A saved line whose product has since been removed has nothing to
            // show and nothing to move back, so leave it out.
            $data = $data->filter(function ($item) {
                return !is_null($item->product);
            })->values();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Drop one saved line.
     */
    public function delete(Request $request, $id)
    {
        try {
            $query = $this->ownerScope($request, SaveForLater::query());

            if (is_null($query)) {
                return response()->json(Validation::unauthorized());
            }

            $saved = $query->where('id', $id)->first();

            if (!$saved) {
                return response()->json(Validation::unauthorized());
            }

            $saved->delete();

            return response()->json(new Response($request->token, ['status' => 'removed']));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
