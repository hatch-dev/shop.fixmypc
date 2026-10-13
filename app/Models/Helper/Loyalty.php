<?php

namespace App\Models\Helper;

use App\Models\LoyaltyGroup;
use App\Models\LoyaltyGroupUser;
use App\Models\OrderedLoyaltyDiscount;
use Carbon\Carbon;

/**
 * Everything that decides whether a customer's loyalty group pays for part of
 * an order lives here, so the checkout preview and the order that is actually
 * charged cannot disagree.
 *
 * A customer may legitimately sit in more than one group (a "new customer 30
 * days" group and a permanent "trade account" group, say). Nothing in the
 * original feature said which of those wins, so the rule is stated once, here:
 * the group that saves this particular cart the most money, and on a tie the
 * one whose membership expires soonest -- spend the perishable benefit first.
 */
class Loyalty
{
    /**
     * The best redeemable membership for a user against a given subtotal.
     *
     * Returns null when the user is in no group, every membership is spent or
     * expired, or no group's rules pay out on this cart.
     *
     * @return array{membership: LoyaltyGroupUser, group: LoyaltyGroup, discount: float}|null
     */
    public static function bestFor($userId, $subtotal, $at = null)
    {
        if (!$userId || (float)$subtotal <= 0) {
            return null;
        }

        $at = $at ? Carbon::parse($at) : Carbon::now();

        $memberships = self::redeemableMemberships($userId, $at);

        $best = null;

        foreach ($memberships as $membership) {

            $group = $membership->loyaltyGroup;

            if (!$group || !$group->isRunning($at)) {
                continue;
            }

            $discount = Pricing::loyaltyDiscount($group, $subtotal);

            if ($discount <= 0) {
                continue;
            }

            $candidate = [
                'membership' => $membership,
                'group' => $group,
                'discount' => $discount
            ];

            if (is_null($best) || self::beats($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Every membership this customer could still spend, oldest first.
     *
     * Ordered explicitly. Two memberships can tie on every selection rule --
     * same discount on this cart, neither one dated -- and without an ORDER BY
     * it would be the query planner deciding which of the customer's benefits
     * got spent.
     */
    private static function redeemableMemberships($userId, $at)
    {
        return LoyaltyGroupUser::with('loyaltyGroup')
            ->where('user_id', $userId)
            ->redeemable($at)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The nearest loyalty benefit this basket does not yet qualify for.
     *
     * A group can carry a minimum cart value, and until now a shopper who was
     * a few pounds short simply saw nothing: the discount they had been given
     * was invisible until the basket happened to cross the line, with no way
     * to know it existed or how close they were.
     *
     * Only an offer that beats what they are already getting is returned --
     * telling someone holding EUR 250 off that they could unlock EUR 100 off
     * is worse than saying nothing.
     *
     * @param float $currentDiscount what the basket already earns, so a worse
     *                               offer is never suggested as an upgrade
     * @return array{group: LoyaltyGroup, shortfall: float, reward: float, threshold: float}|null
     */
    public static function nextUnlockFor($userId, $subtotal, $currentDiscount = 0.0, $at = null)
    {
        if (!$userId) {
            return null;
        }

        $at = $at ? Carbon::parse($at) : Carbon::now();
        $subtotal = max(0, (float)$subtotal);

        $best = null;

        foreach (self::redeemableMemberships($userId, $at) as $membership) {

            $group = $membership->loyaltyGroup;

            if (!$group || !$group->isRunning($at)) {
                continue;
            }

            $threshold = (float)$group->min_cart_value;

            // No minimum, or already cleared: nothing to unlock.
            if ($threshold <= 0 || $subtotal >= $threshold) {
                continue;
            }

            /*
             * What the group pays at the threshold, not at today's subtotal --
             * at today's subtotal it pays nothing, which is the whole point.
             * This is the floor of what they would get, so the promise is one
             * the basket can always keep.
             */
            $reward = Pricing::loyaltyDiscount($group, $threshold);

            if ($reward <= 0 || $reward <= (float)$currentDiscount) {
                continue;
            }

            $candidate = [
                'group' => $group,
                'threshold' => Pricing::round($threshold),
                'shortfall' => Pricing::round($threshold - $subtotal),
                'reward' => $reward
            ];

            /*
             * The most useful nudge is the one they can actually reach, so the
             * smallest shortfall wins; a bigger reward breaks a tie.
             */
            if (is_null($best)
                || $candidate['shortfall'] < $best['shortfall']
                || ($candidate['shortfall'] === $best['shortfall']
                    && $candidate['reward'] > $best['reward'])) {

                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Bigger discount wins; on a tie the membership that expires first wins,
     * and a membership with no expiry never displaces one that has one.
     *
     * When neither is dated the incumbent stands, and because the candidates
     * arrive oldest-joined-first that resolves to "spend the benefit you have
     * held longest" rather than to whatever order the database returned.
     */
    private static function beats($candidate, $incumbent)
    {
        if ($candidate['discount'] !== $incumbent['discount']) {
            return $candidate['discount'] > $incumbent['discount'];
        }

        $a = $candidate['membership']->expires_at;
        $b = $incumbent['membership']->expires_at;

        if (is_null($a)) {
            return false;
        }

        if (is_null($b)) {
            return true;
        }

        if ($a->equalTo($b)) {
            return false;
        }

        return $a->lt($b);
    }

    /**
     * Record a redemption against a paid order.
     *
     * 'one_time' memberships are spent here and nowhere else. The others stay
     * open until their window closes, which is what "valid for 30 days" has to
     * mean if it is to mean anything.
     */
    public static function redeem($best, $orderId)
    {
        if (!$best || $best['discount'] <= 0) {
            return;
        }

        OrderedLoyaltyDiscount::create([
            'order_id' => $orderId,
            'loyalty_group_id' => $best['group']->id,
            'loyalty_group_user_id' => $best['membership']->id,
            /*
             * Frozen here. Reading it back through the live group would let a
             * later rename rewrite the wording on every receipt, email and
             * invoice already issued under it.
             */
            'label' => $best['group']->displayLabel(),
            'discount' => $best['discount']
        ]);

        if ($best['group']->validity === 'one_time') {
            LoyaltyGroupUser::where('id', $best['membership']->id)
                ->whereNull('used_at')
                ->update([
                    'used_at' => Carbon::now(),
                    'used_order_id' => $orderId
                ]);
        }
    }

    /**
     * What a placed order was discounted by, and under what wording.
     *
     * One reader for the order-complete page, the admin order detail, the
     * order emails and the invoice, so the four cannot describe the same
     * order differently. Always reads the frozen label off the order row, not
     * the live group.
     *
     * @return array{amount: float, label: string}
     */
    public static function forOrder($orderId)
    {
        return self::fromRows(OrderedLoyaltyDiscount::where('order_id', $orderId)->get());
    }


    /**
     * The same answer from rows already in hand.
     *
     * A list of orders eager-loads ordered_loyalty_discount for the whole page;
     * without this, summarising each row went back to the database for its own
     * copy — one query per order, for a relation that was already loaded.
     */
    public static function fromRows($rows)
    {
        if (is_null($rows) || count($rows) === 0) {
            return ['amount' => 0.0, 'label' => ''];
        }

        $amount = Pricing::round(collect($rows)->sum('discount'));

        $label = trim((string)collect($rows)->first()->label);

        return [
            'amount' => $amount,
            'label' => $label !== '' ? $label : 'Loyalty Discount'
        ];
    }

    /**
     * Give a one-time redemption back when the order it paid for is cancelled,
     * returned or refunded. Without this a customer whose order is refunded
     * loses the benefit permanently.
     */
    public static function release($orderId)
    {
        $records = OrderedLoyaltyDiscount::where('order_id', $orderId)->get();

        foreach ($records as $record) {

            if (!$record->loyalty_group_user_id) {
                continue;
            }

            LoyaltyGroupUser::where('id', $record->loyalty_group_user_id)
                ->where('used_order_id', $orderId)
                ->update([
                    'used_at' => null,
                    'used_order_id' => null
                ]);
        }
    }
}
