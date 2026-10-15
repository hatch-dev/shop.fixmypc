<?php

namespace App\Http\Middleware;

use App\Models\Helper\BasketUpkeep;
use App\Models\Helper\CartHold;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * cart.hold - after a basket request, make the basket's reserved stock match
 * what is now in it (App\Models\Helper\CartHold::reconcile). One place for
 * every endpoint that changes a basket: add, quantity, variant switch,
 * bundle, cross-sell, save for later, delete.
 *
 * cart.hold:sweep - on any API request, run the basket sweep (BasketUpkeep) at most
 * once a minute, after the response has gone. A backup for the scheduled
 * command on a host whose cron may not be ticking.
 */
class ReconcileCartHold
{
    /**
     * Modes: reconcile (the basket routes) and checkout (placing an order,
     * authorising a card, the guest email-code steps) both
     *
     *   - first empty the basket if its reservation time has lapsed, so a lapsed
     *     basket is never priced, shipped or paid for - whether or not the
     *     sweep has reached it yet;
     *   - and, after a request that changed something, make the reserved
     *     stock match the basket.
     *
     * Viewing the basket or working through checkout does not restart the
     * clock: the user's rule is that only adding to the basket does
     * (CartHold::reconcile). Payment has its own grace (markPaying).
     *
     * sweep (the whole api group) only runs the once-a-minute sweep below.
     */
    public function handle(Request $request, Closure $next, $mode = 'reconcile')
    {
        $owner = $mode === 'sweep' ? null : CartHold::ownerFromRequest($request);

        if ($owner) {
            try {
                CartHold::expireIfLapsed($owner[0], $owner[1]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Basket activity check failed', ['message' => $e->getMessage()]);
            }
        }

        $response = $next($request);

        if ($owner && !$request->isMethod('GET')) {
            CartHold::reconcile($owner[0], $owner[1]);
        }

        return $response;
    }

    public function terminate(Request $request, $response)
    {
        try {
            if (Cache::add('cart-hold-sweep', 1, 60)) {
                // Reservations (on the admin timings) and abandoned Buy Now lines.
                BasketUpkeep::sweep();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Cart hold sweep failed', ['message' => $e->getMessage()]);
        }
    }
}
