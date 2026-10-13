<?php

namespace App\Http\Controllers;

use App\Models\GuestUser;
use App\Models\Helper\ListingPrices;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\UserWishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class UserWishlistsController extends Controller
{
    /**
     * Narrow a wishlist query to whoever is asking.
     *
     * A signed-in customer owns rows by `user_id`; a guest owns them by the
     * `user_token` the server issued it, the same ownership rule the cart uses.
     * Returns null when the caller is neither, so the endpoint refuses rather
     * than falling back to "every row".
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

    public function wishlistAction(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::user_wishlist($request);
            if ($validate)
                return response()->json($validate);

            $query = $this->ownerScope($request, UserWishlist::query());

            if (is_null($query)) {
                return response()->json(Validation::unauthorized());
            }

            $userWishlist = $query->where('product_id', $request->product_id)->first();

            if (is_null($userWishlist)) {

                $attributes = ['product_id' => $request->product_id];

                if ($request->user('user')) {

                    $attributes['user_id'] = $request->user('user')->id;

                } else {

                    $attributes['user_token'] = $request->user_token;

                    // The guest row is what makes the token resolvable later
                    // (and what ValidateGuestToken checks), so make sure it
                    // exists before hanging a wishlist off the token.
                    if (!GuestUser::where('user_token', $request->user_token)->exists()) {
                        GuestUser::create(['user_token' => $request->user_token]);
                    }
                }

                $userWishlist = UserWishlist::create($attributes);

                $message = __('lang.added', [], $lang);
            } else {
                UserWishlist::where('id', $userWishlist->id)->delete();

                $message = __('lang.removed', [], $lang);
                $userWishlist = null;
            }

            return Validation::success($request,
                __('lang.wishlist_message', ['message' => $message], $lang)
                , $userWishlist);


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function wishlists(Request $request)
    {
        try {

            $lang = $request->header('language');

            $query = $this->ownerScope($request, UserWishlist::query());

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

                /*
                 * The price and the backorder flag, not just the stock count.
                 *
                 * The headline price is the cheapest variation a customer can
                 * actually buy (Pricing::displayRange), and it cannot work
                 * that out from rows with no price on them — it fell back to
                 * the product-level offer, which on a variation product is a
                 * figure nobody can pay. withCount is how a row is recognised
                 * as a real variation without loading every attribute.
                 */
                'product.product_inventories' => function ($q) {
                    $q->select('id', 'product_id', 'quantity', 'price', 'is_active')
                        ->withCount('inventory_attributes');
                }
            ]);

            $data = $query->orderBy($request->order_by ?: 'id', $request->type ?: 'desc')
                ->paginate(Config::get('constants.api.PAGINATION'));


            if ($request->time_zone) {
                foreach ($data as $item) {
                    $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                }

            } else {
                foreach ($data as $item) {
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }

            /*
             * The wishlist renders the same product tile as the shop listing,
             * and that tile shows whatever price it is given. It was given
             * products.offered, so a business or approved-trade customer was
             * advertised the retail price on everything they had saved —
             * while the product page, the basket and the till all used the
             * rate their account is entitled to.
             */
            ListingPrices::decorateRelation($data);

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
