<?php

namespace App\Http\Controllers;

use App\Models\Helper\Loyalty;
use App\Models\Helper\Membership;
use App\Models\Helper\Pricing;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * The customer-facing half of the loyalty group feature, which did not exist.
 *
 * The admin panel could put a customer in a discount group and the storefront
 * had no way to find out, so the discount was never shown and never charged.
 */
class LoyaltyController extends Controller
{
    /**
     * What this customer's loyalty membership is worth on the basket they are
     * looking at, and what the next benefit up would take to reach.
     *
     * Both figures are for display. The amount actually charged is recomputed
     * server-side when the order is placed, from the same Loyalty::bestFor()
     * call, so a stale or tampered preview cannot change what is taken.
     */
    public function current(Request $request)
    {
        $user = $request->user('user');

        if (!$user) {
            return response()->json(['active' => false, 'next' => null]);
        }

        $currency = $this->currencyIcon();

        /*
         * The loyalty group or the membership, whichever pays more - the
         * same choice the order makes, on the same figures. Both are priced
         * on the basket's own lines: the subtotal is the order's
         * $totalCartPrice (charged price x units paid for), worked out here.
         *
         * It used to be the `subtotal` the browser sent - a sum the page did
         * for itself. Wherever that drifted from what the order charges (a
         * clearance the basket had lost on a translated page, a line priced
         * by the page's own fallback ladder) the preview promised a discount,
         * or a minimum cart value, that checkout then disagreed with. The
         * parameter is still accepted and is no longer read.
         */
        $checkout = Membership::checkoutLines($user);
        $subtotal = $checkout['subtotal'];
        $best = Membership::bestOrderDiscount($user, $subtotal, $checkout['lines'], $checkout['subtotal']);
        $applied = $best ? Pricing::round($best['discount']) : 0.0;

        /*
         * Worked out even when a discount is already applying: a shopper on a
         * flat EUR 8 who is EUR 40 away from 20% off is the case where saying
         * so is worth most.
         */
        $next = Loyalty::nextUnlockFor($user->id, $subtotal, $applied);

        $payload = [
            'active' => (bool)$best,
            'next' => null
        ];

        if ($best && $best['kind'] === 'membership') {
            $benefit = $best['benefit'];

            $payload = array_merge($payload, [
                'kind' => 'membership',
                'label' => $best['label'],
                'benefit' => rtrim(rtrim(number_format($benefit->percent, 2), '0'), '.') . '% off with your '
                    . $best['membership']->plan->name . ' membership',
                'validity' => 'membership',
                'expires_at' => null,
                'amount' => $applied
            ]);
        } else if ($best) {
            $group = $best['group'];

            $payload = array_merge($payload, [
                /*
                 * displayLabel(), never $group->title. The title is the
                 * admin's internal name for the group and printing it is how
                 * "Group 1" ended up on a customer's order summary.
                 */
                'label' => $group->displayLabel(),
                'benefit' => $group->benefitSummary($currency),
                'validity' => $group->validity,
                'expires_at' => optional($best['membership']->expires_at)->toISOString(),
                'amount' => $applied
            ]);
        }

        if ($next) {
            $payload['next'] = [
                'label' => $next['group']->displayLabel(),
                'benefit' => $next['group']->benefitSummary($currency),
                'shortfall' => $next['shortfall'],
                'threshold' => $next['threshold'],
                'reward' => $next['reward'],
                // True when this would replace a discount they already have.
                'upgrade' => $applied > 0
            ];
        }

        return response()->json($payload);
    }

    private function currencyIcon()
    {
        $setting = Setting::first();

        return $setting->currency_icon ?? '';
    }
}
