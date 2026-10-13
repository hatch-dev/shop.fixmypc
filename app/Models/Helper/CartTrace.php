<?php

namespace App\Models\Helper;

use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Where a basket line came from, written to storage/logs/cart-trace-*.log.
 *
 * Added to answer "a new account opened with products already in its cart".
 * The carts table cannot answer that on its own: a guest line adopted at
 * sign-in keeps its id and created_at but loses its user_token, so once it is
 * on the account nothing says it was ever a guest's, or how it got there.
 *
 * Two events, joined on cart_id:
 *
 *  - line_created: every new carts row, with the page action that made it
 *    (add to cart, buy now, cross-sell, bundle) and its owner;
 *  - guest_basket_merged: every guest-to-account hand-over, listing each line
 *    moved with its age and the age of the guest identity it came from.
 *
 * Read with `php artisan cart:trace <email or user id>`.
 *
 * Tracing is never allowed to fail a cart write: every entry point swallows
 * its own errors. Guest tokens are credentials, so only a prefix is logged.
 */
class CartTrace
{
    const CHANNEL = 'cart_trace';

    /*
     * An account this young at sign-in is treated as a registration. The
     * storefront signs a new account in straight after the emailed code is
     * checked, so half an hour covers a slow inbox with room to spare.
     */
    const NEW_ACCOUNT_MINUTES = 30;

    /**
     * A carts row has just been inserted.
     */
    public static function lineCreated($cart)
    {
        try {
            if (!$cart) {
                return;
            }

            self::write('line_created', [
                'cart_id' => (int)$cart->id,
                'origin' => self::origin(),
                'owner' => self::owner($cart),
                'product_id' => (int)$cart->product_id,
                'product' => self::titles([$cart->product_id])[(int)$cart->product_id] ?? null,
                'inventory_id' => (int)$cart->inventory_id,
                'quantity' => (int)$cart->quantity,
                'bundle_deal_id' => $cart->bundle_deal_id ? (int)$cart->bundle_deal_id : null,
                'endpoint' => self::endpoint(),
                'ip' => request() ? request()->ip() : null,
            ]);
        } catch (\Throwable $e) {
            // Tracing must never cost a shopper their basket line.
        }
    }

    /**
     * Snapshot guest rows before CartMerge folds, adopts or deletes them.
     *
     * Taken inside the merge transaction, from the rows it locked, so the
     * snapshot is exactly what was moved and not a re-read that could race.
     */
    public static function snapshot($guestRows)
    {
        try {
            return collect($guestRows)->map(function ($row) {
                return [
                    'cart_id' => (int)$row->id,
                    'product_id' => (int)$row->product_id,
                    'inventory_id' => (int)$row->inventory_id,
                    'quantity' => (int)$row->quantity,
                    'selected' => (int)$row->selected,
                    'bundle_deal_id' => $row->bundle_deal_id ? (int)$row->bundle_deal_id : null,
                    'added_at' => self::stamp($row->created_at),
                    'last_changed_at' => self::stamp($row->updated_at),
                    'age' => self::age($row->created_at),
                ];
            })->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * A guest basket has just been handed to an account -- or there was
     * nothing to hand to an account that is brand new, which is worth logging
     * too: it proves the basket was empty at registration.
     */
    public static function merged($user, $userToken, array $lines, array $summary, $basket = null)
    {
        try {
            if (!$user) {
                return;
            }

            $newAccount = self::isNewAccount($user);

            if (empty($lines) && !$newAccount) {
                return;
            }

            $titles = self::titles(array_column($lines, 'product_id'));

            foreach ($lines as &$line) {
                $line['product'] = $titles[$line['product_id']] ?? null;
            }
            unset($line);

            $guest = $userToken
                ? GuestUser::where('user_token', $userToken)->first(['created_at'])
                : null;

            self::write('guest_basket_merged', [
                'user_id' => (int)$user->id,
                'email' => $user->email,
                'account_created_at' => self::stamp($user->created_at),
                'new_account' => $newAccount,
                'guest_token' => self::tokenPrefix($userToken),
                'guest_token_issued_at' => $guest ? self::stamp($guest->created_at) : null,
                'guest_token_age' => $guest ? self::age($guest->created_at) : null,
                'endpoint' => self::endpoint(),
                'ip' => request() ? request()->ip() : null,
                'guest_basket' => $basket,
                'lines_moved' => count($lines),
                'lines' => $lines,
                'result' => $summary,
            ]);
        } catch (\Throwable $e) {
            // As above: a failed trace must not fail the sign-in.
        }
    }

    /**
     * How long the guest basket had been idle, and when it would have been
     * cleared: 20 minutes after its last activity if it held a reservation
     * (CartHold), otherwise the guest basket lifetime (BasketUpkeep) after the
     * newest change to any of its lines or the guest's last visit. An old
     * figure next to a line moved into a new account is the answer to "where
     * did this come from?".
     */
    public static function basketState($userToken)
    {
        try {
            if (!$userToken) {
                return null;
            }

            $lastLine = Cart::whereNull('user_id')->where('user_token', $userToken)->max('updated_at');
            $lastVisit = GuestUser::where('user_token', $userToken)->value('updated_at');

            $last = collect([$lastLine, $lastVisit])->filter()->map(function ($value) {
                return Carbon::parse($value);
            })->max();

            $hold = \DB::table('cart_holds')->where('owner_key', CartHold::ownerKey(null, $userToken))->first();

            if ($hold) {
                $active = Carbon::parse($hold->last_activity_at);

                return [
                    'reserved' => true,
                    'last_activity_at' => $active->toDateTimeString(),
                    'idle' => self::age($active),
                    'expires_at' => $active->copy()->addMinutes(CartHold::EXPIRE_AFTER_MINUTES)->toDateTimeString(),
                ];
            }

            if (!$last) {
                return ['reserved' => false, 'last_activity_at' => null];
            }

            return [
                'reserved' => false,
                'last_activity_at' => $last->toDateTimeString(),
                'idle' => self::age($last),
                'expires_at' => $last->copy()->addDays(BasketUpkeep::GUEST_BASKET_DAYS)->toDateTimeString(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function tokenPrefix($token)
    {
        return $token ? substr($token, 0, 8) . '...' : null;
    }

    /**
     * Which storefront action created the line, from the request actually
     * being served -- cross-sell builds its own sub-request whose path is just
     * "/", so the global request is the one to ask.
     */
    private static function origin()
    {
        $path = request() ? trim(request()->path(), '/') : '';

        $map = [
            'cart/buy-now' => 'buy_now',
            'cart/cross-sell' => 'cross_sell',
            'cart/bundle' => 'bundle',
            'cart/action' => 'add_to_cart',
        ];

        foreach ($map as $suffix => $origin) {
            if (substr($path, -strlen($suffix)) === $suffix) {
                return strpos($path, 'admin') !== false ? 'admin_' . $origin : $origin;
            }
        }

        return $path ?: 'unknown';
    }

    private static function owner($cart)
    {
        if ($cart->admin_id) {
            return ['type' => 'admin', 'admin_id' => (int)$cart->admin_id];
        }

        if ($cart->user_id) {
            return ['type' => 'account', 'user_id' => (int)$cart->user_id];
        }

        return ['type' => 'guest', 'guest_token' => self::tokenPrefix($cart->user_token)];
    }

    private static function endpoint()
    {
        return request() ? request()->method() . ' /' . ltrim(request()->path(), '/') : null;
    }

    private static function isNewAccount($user)
    {
        return $user->created_at
            && Carbon::parse($user->created_at)->gt(now()->subMinutes(self::NEW_ACCOUNT_MINUTES));
    }

    private static function titles($ids)
    {
        $ids = array_values(array_unique(array_filter(array_map(function ($id) {
            return (int)$id;
        }, (array)$ids))));

        if (!$ids) {
            return [];
        }

        return Product::whereIn('id', $ids)->pluck('title', 'id')->all();
    }

    private static function stamp($value)
    {
        return $value ? Carbon::parse($value)->toDateTimeString() : null;
    }

    /**
     * "3 days" rather than a timestamp to subtract: the question asked of this
     * log is nearly always "was that line old?".
     */
    private static function age($value)
    {
        return $value ? Carbon::parse($value)->diffForHumans(now(), true) : null;
    }

    private static function write($event, array $context)
    {
        if (!config('logging.channels.' . self::CHANNEL . '.enabled', true)) {
            return;
        }

        Log::channel(self::CHANNEL)->info($event, $context);
    }
}
