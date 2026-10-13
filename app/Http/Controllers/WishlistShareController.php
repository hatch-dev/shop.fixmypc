<?php

namespace App\Http\Controllers;

use App\Models\Helper\ListingPrices;
use App\Models\Helper\Response;
use App\Models\User;
use App\Models\UserWishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * "Share Wishlist" on My Wishlist, which was a dead href="#".
 *
 * A signed-in customer gets one link, /wishlist/shared/{token}. The token is
 * 40 random characters on the user row (users.wishlist_share_token), so the
 * link cannot be guessed from an id or an email, and "Stop sharing" clears
 * it: the old link then finds nothing. Creating it again issues a new token,
 * so a link that was passed around once does not come back to life.
 *
 * The public read shows the products and the owner's first name. Nothing
 * else about the account leaves: no email, no surname, no ids.
 */
class WishlistShareController extends Controller
{
    /** The customer's current link, or null when they are not sharing. */
    public function status()
    {
        return response()->json(new Response(null, [
            'token' => Auth::user()->wishlist_share_token,
        ]));
    }

    /** Turn sharing on. Idempotent: an existing link is returned, not replaced. */
    public function enable()
    {
        $user = Auth::user();

        if (!$user->wishlist_share_token) {
            $user->wishlist_share_token = Str::random(40);
            $user->save();
        }

        return response()->json(new Response(null, [
            'token' => $user->wishlist_share_token,
        ]));
    }

    /** Stop sharing: the link stops working at once. */
    public function disable()
    {
        $user = Auth::user();
        $user->wishlist_share_token = null;
        $user->save();

        return response()->json(new Response(null, ['token' => null]));
    }

    /**
     * The shared list, for anyone holding the link.
     *
     * Prices are the viewer's, not the owner's: ListingPrices decorates for
     * whoever is asking, so a trade customer's list shared with a friend shows
     * the friend retail prices, as the product page will.
     */
    public function show(Request $request, $token)
    {
        $owner = strlen((string)$token) === 40
            ? User::where('wishlist_share_token', $token)->first()
            : null;

        // Also covers a suspended account: its list is not on show.
        if (!$owner || (isset($owner->status) && $owner->status === 'suspended')) {
            return response()->json(['status' => 404, 'message' => 'This wishlist is no longer shared.'], 200);
        }

        $lang = $request->header('language');

        $data = UserWishlist::where('user_id', $owner->id)
            ->whereHas('product')
            ->with([
                'product' => function ($query) use ($lang) {
                    if ($lang) {
                        $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                            $join->on('pl.product_id', '=', 'products.id');
                            $join->where('pl.lang', $lang);
                        });
                    }
                },
                'product.product_inventories' => function ($q) {
                    $q->select('id', 'product_id', 'quantity', 'price', 'is_active')
                        ->withCount('inventory_attributes');
                }
            ])
            ->orderBy('id', 'desc')
            ->paginate(Config::get('constants.api.PAGINATION'));

        ListingPrices::decorateRelation($data);

        // Only the product goes out; the row itself carries the owner's ids.
        $items = collect($data->items())->map(function ($row) {
            return $row->product;
        })->filter()->values();

        $firstName = trim(explode(' ', trim((string)$owner->name))[0] ?? '');

        return response()->json(new Response(null, [
            'owner'        => $firstName !== '' ? $firstName : null,
            'data'         => $items,
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]));
    }
}
