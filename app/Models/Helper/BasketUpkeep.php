<?php

namespace App\Models\Helper;

use App\Models\Cart;
use App\Models\GuestUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The basket sweep, run every minute.
 *
 *  - Reservations (CartHold::sweep): a reminder email at 5 idle minutes, and
 *    at 20 the basket is emptied and its reserved units go back on the shelf.
 *  - Buy Now lines (carts.buy_now): a checkout of one item, not part of the
 *    basket. Untouched for BUY_NOW_MINUTES it is abandoned and deleted;
 *    starting to pay touches it (CartsController::holdForPayment), so a
 *    shopper part-way through paying never loses it.
 *  - A safety net for guest lines with no reservation at all - written before
 *    reservations existed, or by a path that never opened one. Nothing ever
 *    timed those out, which is how months-old guest baskets reached accounts
 *    opened in the same browser. After GUEST_BASKET_DAYS idle they go.
 *
 * Runs from the scheduler (cart:sweep-holds) and, for a host whose cron is
 * not ticking, from API traffic (middleware cart.hold:sweep).
 * Times are PHP times, never SQL NOW() - the two clocks differ here.
 */
class BasketUpkeep
{
    const GUEST_BASKET_DAYS = 90;

    const BUY_NOW_MINUTES = 30;

    /** The guest-basket pass groups the whole carts table; hourly is plenty. */
    const GUEST_PASS_EVERY_SECONDS = 3600;

    /**
     * When the sweep last ran, and when the scheduler (cron) last ran it.
     * Read by `mail:check`: basket reminders only go out from the sweep, so
     * "never from the scheduler" means they depend on someone using the site.
     */
    const LAST_SWEEP_KEY = 'basket-sweep-last';
    const LAST_SCHEDULED_SWEEP_KEY = 'basket-sweep-last-scheduled';

    /**
     * @return array{reminded:int, expired:int, reconciled:int, buy_now_expired:int, guest_lines_expired:int}
     */
    public static function sweep($everything = false, $source = 'traffic')
    {
        try {
            $now = date('Y-m-d H:i:s');
            Cache::forever(self::LAST_SWEEP_KEY, ['at' => $now, 'source' => $source]);

            if ($source === 'scheduler') {
                Cache::forever(self::LAST_SCHEDULED_SWEEP_KEY, $now);
            }
        } catch (\Throwable $e) {
            // Diagnostics only: never stop the sweep for them.
        }
        $done = CartHold::sweep();

        $done['buy_now_expired'] = self::expireBuyNow();
        $done['guest_lines_expired'] = 0;

        if ($everything || Cache::add('basket-upkeep-guest-pass', 1, self::GUEST_PASS_EVERY_SECONDS)) {
            $done['guest_lines_expired'] = self::expireGuestBaskets();
        }

        return $done;
    }

    /** Delete Buy Now lines nobody has touched for BUY_NOW_MINUTES. */
    public static function expireBuyNow()
    {
        $cutoff = self::now()->subMinutes(self::BUY_NOW_MINUTES)->format('Y-m-d H:i:s');

        $stale = Cart::where('buy_now', 1)
            ->where(function ($query) use ($cutoff) {
                $query->where('updated_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('updated_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->get(['id', 'user_id', 'user_token']);

        if ($stale->isEmpty()) {
            return 0;
        }

        Cart::whereIn('id', $stale->pluck('id')->all())->delete();

        // The units those lines had reserved go back.
        foreach ($stale->unique(function ($line) { return $line->user_id ?: 'g:' . $line->user_token; }) as $line) {
            CartHold::reconcile($line->user_id, $line->user_id ? null : $line->user_token);
        }

        return $stale->count();
    }

    /** Delete guest lines that have no reservation and have been idle GUEST_BASKET_DAYS. */
    public static function expireGuestBaskets()
    {
        $cutoff = self::now()->subDays(self::GUEST_BASKET_DAYS)->format('Y-m-d H:i:s');

        $idle = Cart::whereNull('user_id')->whereNull('admin_id')->whereNotNull('user_token')
            ->groupBy('user_token')
            ->havingRaw('MAX(COALESCE(updated_at, created_at)) < ?', [$cutoff])
            ->pluck('user_token');

        if ($idle->isEmpty()) {
            return 0;
        }

        $deleted = 0;

        foreach ($idle->chunk(500) as $tokens) {

            // A guest who came back recently keeps their basket.
            $seen = GuestUser::whereIn('user_token', $tokens->all())
                ->where('updated_at', '>=', $cutoff)
                ->pluck('user_token');

            foreach ($tokens->diff($seen) as $token) {
                // A basket with a reservation is the reservation timer's job.
                if (\DB::table('cart_holds')->where('owner_key', CartHold::ownerKey(null, $token))->exists()) {
                    continue;
                }

                $deleted += Cart::whereNull('user_id')->whereNull('admin_id')
                    ->where('user_token', $token)->delete();
            }
        }

        if ($deleted) {
            Log::info('Expired idle guest baskets', [
                'lines' => $deleted, 'older_than_days' => self::GUEST_BASKET_DAYS,
            ]);
        }

        return $deleted;
    }

    /**
     * The shopper has started paying: keep their Buy Now line alive for
     * another BUY_NOW_MINUTES, so the sweep cannot remove it mid-payment.
     */
    public static function touchBuyNow($userId, $userToken)
    {
        $query = Cart::where('buy_now', 1)->whereNull('admin_id');

        if ($userId) {
            $query->where('user_id', $userId);
        } else if ($userToken) {
            $query->whereNull('user_id')->where('user_token', $userToken);
        } else {
            return 0;
        }

        return $query->update(['updated_at' => self::now()->format('Y-m-d H:i:s')]);
    }

    private static function now()
    {
        return Carbon::parse(date('Y-m-d H:i:s'));
    }
}
