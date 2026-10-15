<?php

namespace App\Models\Helper;

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanBenefit;
use App\Models\OrderedLoyaltyDiscount;
use App\Models\User;
use App\Models\UserMembership;
use Carbon\Carbon;

/**
 * What a customer's membership is worth, decided once (2026-10-15).
 *
 * The basket, the checkout preview, the shipping quote and the order all ask
 * here, the same way every price comes from Pricing, so what a member is
 * shown and what they are charged cannot drift apart.
 *
 * Stacking, as agreed with the shop:
 *  - free delivery applies to every member, whatever else the basket has;
 *  - the percent discount counts only lines at their normal price. A line
 *    already reduced by a flash sale, a clearance or a business/trade band
 *    is left out unless the benefit's include_* flag says otherwise;
 *  - a loyalty group and the membership never both apply: the bigger
 *    discount wins (bestOrderDiscount), on a tie the membership, so a
 *    one-time loyalty benefit is kept for later;
 *  - vouchers, the store-wide flash discount, bundles and points still
 *    apply as before (OrdersController takes the member discount after the
 *    voucher, flash and bundle, in the loyalty slot).
 */
class Membership
{
    /**
     * The membership carrying benefits now, with its plan and benefits, or
     * null. A suspended account has none, whatever it paid for.
     */
    public static function currentFor($user, $at = null)
    {
        $user = self::user($user);

        if (!$user || $user->isSuspended()) {
            return null;
        }

        return UserMembership::with('plan.benefits')
            ->where('user_id', $user->id)
            ->entitled($at)
            // a paid subscription before a granted one, then the newest
            ->orderByRaw("CASE WHEN source = 'stripe' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The membership to show on the account page: the one carrying benefits,
     * else the newest one still open (a renewal that failed), else the newest.
     */
    public static function latestFor($user)
    {
        $user = self::user($user);

        if (!$user) {
            return null;
        }

        return self::currentFor($user)
            ?? UserMembership::with('plan.benefits')->where('user_id', $user->id)->open()->orderByDesc('id')->first()
            ?? UserMembership::with('plan.benefits')->where('user_id', $user->id)
                ->whereNotIn('status', ['incomplete', 'incomplete_expired'])
                ->orderByDesc('id')->first();
    }

    /** @return array{0: UserMembership, 1: MembershipPlanBenefit}|null */
    public static function benefitFor($user, $type, $at = null)
    {
        $membership = self::currentFor($user, $at);

        if (!$membership || !$membership->plan) {
            return null;
        }

        $benefit = $membership->plan->benefit($type);

        return $benefit ? [$membership, $benefit] : null;
    }

    /**
     * Free delivery for this member on a basket worth $goodsSubtotal?
     * Collection is not delivery and keeps its own price.
     */
    public static function freeShippingFor($user, $goodsSubtotal, $at = null)
    {
        $found = self::benefitFor($user, MembershipPlanBenefit::FREE_SHIPPING, $at);

        if (!$found) {
            return false;
        }

        $floor = (float)$found[1]->min_order;

        return $floor <= 0 || (float)$goodsSubtotal + 0.005 >= $floor;
    }

    /**
     * The part of one line the percent discount counts: its charged amount,
     * or 0 when the line is already reduced and the benefit leaves those out.
     *
     *   on sale  the retail ladder is below the line's own base price (a
     *            flash sale or a clearance is running on it)
     *   banded   the charged price is below retail (a business/trade band)
     *
     * The product's offer price is its normal price here, not a sale: it is
     * what baseUnitPrice already starts from.
     */
    public static function lineAmount($product, $inventory, $payableQty, $unitPrice, MembershipPlanBenefit $benefit)
    {
        if (!$product || (int)$payableQty <= 0 || (float)$unitPrice <= 0) {
            return 0.0;
        }

        $base = Pricing::baseUnitPrice($product, $inventory);
        $retail = Pricing::retailUnitPrice($product, $inventory);

        $onSale = $retail < $base - 0.005;
        $banded = (float)$unitPrice < $retail - 0.005;

        if ($onSale && !$benefit->include_sale_items) {
            return 0.0;
        }

        if ($banded && !$benefit->include_business_prices) {
            return 0.0;
        }

        return Pricing::round((float)$unitPrice * (int)$payableQty);
    }

    /**
     * The percent discount on these lines, or null when there is none.
     *
     * $lines: [['product', 'inventory', 'quantity' (payable), 'unit' (charged)]].
     * $goodsSubtotal is the whole basket, for the benefit's minimum order;
     * defaults to the sum of the lines.
     *
     * @return array{membership: UserMembership, benefit: MembershipPlanBenefit, eligible: float, discount: float, label: string}|null
     */
    public static function discountFor($user, $lines, $goodsSubtotal = null, $at = null)
    {
        $found = self::benefitFor($user, MembershipPlanBenefit::PERCENT_DISCOUNT, $at);

        if (!$found) {
            return null;
        }

        [$membership, $benefit] = $found;

        $percent = min(100.0, max(0.0, (float)$benefit->percent));

        if ($percent <= 0) {
            return null;
        }

        $eligible = 0.0;
        $all = 0.0;

        foreach ($lines as $line) {
            $all += Pricing::round((float)$line['unit'] * (int)$line['quantity']);
            $eligible += self::lineAmount(
                $line['product'], $line['inventory'] ?? null, $line['quantity'], $line['unit'], $benefit
            );
        }

        $goodsSubtotal = is_null($goodsSubtotal) ? $all : (float)$goodsSubtotal;
        $eligible = Pricing::round($eligible);

        if ($eligible <= 0 || $goodsSubtotal + 0.005 < (float)$benefit->min_order) {
            return null;
        }

        $discount = $eligible * $percent / 100;

        if ((float)$benefit->max_discount > 0) {
            $discount = min($discount, (float)$benefit->max_discount);
        }

        $discount = Pricing::round(max(0, min($discount, $eligible)));

        if ($discount <= 0) {
            return null;
        }

        return [
            'membership' => $membership,
            'benefit' => $benefit,
            'eligible' => $eligible,
            'discount' => $discount,
            'label' => self::discountLabel($membership->plan),
        ];
    }

    /** "Premium member discount" - what the receipt calls it. */
    public static function discountLabel($plan)
    {
        $name = trim((string)optional($plan)->name);

        return $name !== '' ? $name . ' member discount' : 'Member discount';
    }

    /**
     * The lines a signed-in customer is about to buy, priced the way
     * OrdersController::processOrder prices them - same query, same tiers,
     * same ladder - for the previews that have to agree with it.
     *
     * @return array{lines: array, subtotal: float}
     */
    public static function checkoutLines($user)
    {
        $user = self::user($user);

        if (!$user) {
            return ['lines' => [], 'subtotal' => 0.0];
        }

        $carts = Cart::with('product_inner')
            ->where('user_id', $user->id)
            ->forCheckout()
            ->with('updated_inventory')
            ->get();

        $tiers = BusinessProductPricing::tierMap($user, $carts->pluck('product_id'));

        return self::linesFromCarts($carts, $tiers);
    }

    /**
     * Cart rows (product_inner + updated_inventory loaded) as discount lines.
     * The subtotal is OrdersController's $totalCartPrice: charged price x
     * the units actually paid for.
     */
    public static function linesFromCarts($carts, $tiers)
    {
        $lines = [];
        $subtotal = 0.0;

        foreach ($carts as $cart) {
            $product = $cart->product_inner;

            if (!$product) {
                continue;
            }

            $unit = Pricing::unitPrice($product, $cart->updated_inventory, $cart->quantity, $tiers->get($product->id));
            $payable = max(0, (int)$cart->quantity - (int)Pricing::bundleFreeQuantity($product, $cart->quantity));

            $lines[] = [
                'product' => $product,
                'inventory' => $cart->updated_inventory,
                'quantity' => $payable,
                'unit' => $unit,
            ];

            $subtotal += Pricing::round($unit * $payable);
        }

        return ['lines' => $lines, 'subtotal' => Pricing::round($subtotal)];
    }

    /**
     * The one order-level discount a customer's groups and membership give:
     * the bigger of Loyalty::bestFor and discountFor, never both.
     *
     * Same shape as Loyalty::bestFor (a 'discount' key the caller may lower
     * to what was actually granted) plus 'kind' and 'label', and recorded by
     * redeemOrderDiscount.
     *
     * $memberSubtotal is the goods total the membership's minimum order is
     * measured against when it is not $subtotal (the basket preview prices
     * the loyalty group on the browser's figure and the membership on the
     * server's own lines).
     */
    public static function bestOrderDiscount($user, $subtotal, $lines, $memberSubtotal = null, $at = null)
    {
        $user = self::user($user);

        if (!$user) {
            return null;
        }

        $loyalty = Loyalty::bestFor($user->id, $subtotal, $at);
        $member = self::discountFor($user, $lines, $memberSubtotal ?? $subtotal, $at);

        if ($member && (!$loyalty || $member['discount'] >= $loyalty['discount'])) {
            return array_merge($member, ['kind' => 'membership']);
        }

        if ($loyalty) {
            return array_merge($loyalty, [
                'kind' => 'loyalty',
                'label' => $loyalty['group']->displayLabel(),
            ]);
        }

        return null;
    }

    /** Record the discount bestOrderDiscount chose against a placed order. */
    public static function redeemOrderDiscount($best, $orderId)
    {
        if (!$best || $best['discount'] <= 0) {
            return;
        }

        if (($best['kind'] ?? 'loyalty') !== 'membership') {
            Loyalty::redeem($best, $orderId);
            return;
        }

        OrderedLoyaltyDiscount::create([
            'order_id' => $orderId,
            'loyalty_group_id' => null,
            'loyalty_group_user_id' => null,
            'user_membership_id' => $best['membership']->id,
            // frozen, like a loyalty label: a renamed plan must not rewrite receipts
            'label' => $best['label'],
            'discount' => $best['discount'],
        ]);
    }

    /** Member discount on this customer's orders that still stand. */
    public static function savedBy($user)
    {
        $user = self::user($user);

        if (!$user) {
            return 0.0;
        }

        $rows = OrderedLoyaltyDiscount::query()
            ->join('orders', 'orders.id', '=', 'ordered_loyalty_discount.order_id')
            ->join('user_memberships', 'user_memberships.id', '=', 'ordered_loyalty_discount.user_membership_id')
            ->where('user_memberships.user_id', $user->id)
            ->whereNull('orders.deleted_at')
            ->select('orders.id', 'orders.status', 'orders.cancelled', 'ordered_loyalty_discount.discount')
            ->get();

        // the same test as Order::isCancelled
        $public = (int)config("constants.status.PUBLIC");
        $dead = [(int)config("constants.orderStatus.CANCELLED"), (int)config("constants.orderStatus.REFUNDED")];

        return Pricing::round($rows->reject(function ($row) use ($public, $dead) {
            return (int)$row->cancelled === $public || in_array((int)$row->status, $dead, true);
        })->sum("discount"));
    }

    private static function user($user)
    {
        if ($user instanceof User) {
            return $user;
        }

        return $user ? User::find($user) : null;
    }
}
