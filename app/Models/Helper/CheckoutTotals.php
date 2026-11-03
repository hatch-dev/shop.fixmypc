<?php

namespace App\Models\Helper;

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\FlashDiscount;
use App\Models\GuestUser;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\UserAddress;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * What a checkout costs, decided in one place.
 *
 * OrdersController::processOrder() charges an order from these methods, and
 * quote() answers the same question for a checkout that has not been placed
 * yet -- the amount a card gateway is asked to take before the order exists.
 *
 * That amount used to be the browser's own sum. On /checkout it left out the
 * Essentials Bundle discount, so SumUp took more than the order it was paying
 * for and the gateway check (GatewayVerifier, half a cent) left a paid order
 * reading "Not Paid". The browser may still show its sum; the gateways are
 * now handed this one.
 *
 * The order of operations is the order's, unchanged:
 *
 *   goods      unit price x payable quantity, per line
 *   + tax      VAT added to ex-VAT lines: unit VAT x payable quantity
 *   + shipping charged once for the order
 *   - voucher, flash discount, Essentials Bundle, loyalty/membership, points
 *     each capped by what is still owed after the ones before it
 */
class CheckoutTotals
{
    /**
     * One basket line priced as the order charges it.
     *
     * $cart carries product_inner and updated_inventory. $tiers are the
     * business bands for this product, or null.
     */
    public static function line($cart, $tiers = null)
    {
        $product = $cart->product_inner;
        $quantity = (int)$cart->quantity;

        $unit = Pricing::unitPrice($product, $cart->updated_inventory, $quantity, $tiers);

        $free = (int)Pricing::bundleFreeQuantity($product, $quantity);
        $payable = max(0, $quantity - $free);

        $taxRule = $product->tax_rules ?? null;
        $taxable = self::taxable($product);
        $unitTax = Pricing::unitTax($taxRule, $unit, $taxable);

        return [
            'unit' => $unit,
            'quantity' => $quantity,
            'free' => $free,
            'payable' => $payable,
            'tax_rule' => $taxRule,
            'taxable' => $taxable,
            'unit_tax' => $unitTax,
            // charged before any VAT is added: net on an ex-VAT line, gross otherwise
            'goods' => Pricing::round($unit * $payable),
            'tax' => Pricing::round($unitTax * $payable),
            'list' => Pricing::listPrice($product, $cart->updated_inventory, $unit),
        ];
    }


    /**
     * excludeVAT = 1: the price is ex-VAT and the rule's tax is added.
     *
     * Read off the relation when it was selected, and from the product row
     * when it was not -- a missing column reads as null, which would silently
     * mean "not taxable" and drop VAT from the whole order.
     */
    public static function taxable($product)
    {
        $excludeVAT = $product->getAttribute('excludeVAT');

        if (is_null($excludeVAT)) {
            $excludeVAT = Product::where('id', $product->id)->value('excludeVAT');
        }

        return (int)$excludeVAT === 1;
    }


    /**
     * Every line of a checkout, keyed by cart id, with the goods and tax
     * they come to and the lines the membership discount is priced on.
     */
    public static function lines($rows, $tiers)
    {
        $lines = [];
        $goods = 0.0;
        $tax = 0.0;
        $memberLines = [];

        foreach ($rows as $cart) {
            if (is_null($cart->product_inner)) {
                continue;
            }

            $line = self::line($cart, $tiers->get($cart->product_inner->id));

            // The Essentials Bundle discount is worked out on what is charged.
            $cart->charged_unit_price = $line['unit'];

            $lines[$cart->id] = $line;
            $goods += $line['goods'];
            $tax += $line['tax'];

            $memberLines[] = [
                'product' => $cart->product_inner,
                'inventory' => $cart->updated_inventory,
                'quantity' => $line['payable'],
                'unit' => $line['unit'],
            ];
        }

        return [
            'lines' => $lines,
            'goods' => Pricing::round($goods),
            'tax' => Pricing::round($tax),
            'member_lines' => $memberLines,
        ];
    }


    /**
     * Validate a voucher code against a goods subtotal and price it.
     *
     * Returns ['voucher' => Voucher|null, 'discount' => float, 'error' => ?string].
     * The refusals are the order's own (QA audit CPN-01..05), moved here so
     * a quote refuses exactly what placing the order would.
     */
    public static function voucher($code, $subtotal, $user, $userToken, $lang = null)
    {
        $refuse = function ($message) {
            return ['voucher' => null, 'discount' => 0.0, 'error' => $message];
        };

        $voucher = Voucher::where('code', $code)
            ->where('apply_type', 1)
            ->where('status', Config::get('constants.status.PUBLIC'))
            // a redeemed gift voucher is its owner's alone
            ->usableBy(optional($user)->id)
            ->first();

        if (is_null($voucher)) {
            return $refuse(__('lang.invalid_voucher', [], $lang));
        }

        if ($voucher->isGiftCard() && $voucher->giftBalance() <= 0) {
            return $refuse(__('lang.gc_used_up', [], $lang));
        }

        $now = Carbon::now();

        if ($now->lt(new Carbon($voucher->start_time)) || $now->gt(new Carbon($voucher->end_time))) {
            return $refuse(__('lang.voucher_expired', [], $lang));
        }

        if ($subtotal < $voucher->min_spend) {
            $setting = Setting::select('currency', 'currency_icon', 'currency_position')->first();
            $price = $voucher->min_spend . $setting->currency_icon;

            if ((int)$setting->currency_position == Config::get('constants.currencyPosition.PRE')) {
                $price = $setting->currency_icon . $voucher->min_spend;
            }

            return $refuse(__('lang.min_spent', ['amount' => $price], $lang));
        }

        // Only orders that stand consume a redemption (CPN-05).
        if (Voucher::redemptionQuery($voucher->id)->count() >= $voucher->usage_limit) {
            return $refuse(__('lang.voucher_exceeded', [], $lang));
        }

        $byCustomer = Voucher::redemptionQuery($voucher->id);

        if ($user) {
            $byCustomer = $byCustomer->where('user_id', $user->id);
        } else if ($userToken) {
            $byCustomer = $byCustomer->where('user_token', $userToken);
        } else {
            return $refuse(__('lang.went_wrong', [], $lang));
        }

        if ($byCustomer->count() >= $voucher->limit_per_customer) {
            return $refuse(__('lang.voucher_max', [], $lang));
        }

        return [
            'voucher' => $voucher,
            'discount' => Pricing::voucherDiscount($voucher, $subtotal),
            'error' => null,
        ];
    }


    /**
     * What delivery costs this order: the row covering the address (or the
     * collection point), charged once, free for a member's delivery.
     */
    public static function shipping($rows, $shippingType, $address, $isCollection, $user, $goods)
    {
        $quote = Shipping::quote(
            $shippingType,
            optional($address)->country,
            optional($address)->state,
            $isCollection ? collect($rows)->pluck('shipping_place_id')->filter()->first() : null,
            collect($rows)->pluck('product_inner'),
            Shipping::linesWeight($rows)
        );

        if ($quote['ok']
            && (int)$shippingType === Shipping::deliveryType()
            && Membership::freeShippingFor($user, $goods)) {
            $quote['price'] = 0.0;
        }

        return $quote;
    }


    /**
     * The order-wide discounts, in the order they are taken, each capped by
     * what is still owed after the ones before it. $gross is goods + tax +
     * shipping; voucher and flash arrive already capped by the goods.
     */
    public static function chain($gross, $voucher, $flash, $bundleOffered, $loyaltyOffered,
                                 $pointsRequested = 0.0, $pointsAvailable = 0.0)
    {
        $gross = Pricing::round($gross);
        $voucher = Pricing::round($voucher);
        $flash = Pricing::round($flash);

        $bundle = Pricing::round(max(0, min(
            (float)$bundleOffered,
            max(0, Pricing::round($gross - $voucher - $flash))
        )));

        $loyalty = Pricing::round(max(0, min(
            (float)$loyaltyOffered,
            max(0, Pricing::round($gross - $voucher - $flash - $bundle))
        )));

        $points = max(0, Pricing::round(min(
            (float)$pointsRequested,
            (float)$pointsAvailable,
            max(0, $gross - $voucher - $flash - $bundle - $loyalty)
        )));

        return [
            'voucher' => $voucher,
            'flash' => $flash,
            'bundle' => $bundle,
            'loyalty' => $loyalty,
            'points' => $points,
            'payable' => Pricing::round(max(0,
                $gross - $voucher - $flash - $bundle - $loyalty - $points)),
        ];
    }


    /** The money value of a customer's points balance (1 point = 0.01). */
    public static function pointsValue($userId)
    {
        $points = PointTransaction::where('user_id', $userId)
            ->selectRaw("SUM(CASE WHEN type='credit' THEN points ELSE -points END) as total")
            ->value('total') ?? 0;

        return $points * 0.01;
    }


    /**
     * The checkout's money, for a basket that has not been ordered yet.
     *
     * Reads the same lines, address, promotions and balances processOrder()
     * reads and writes nothing. $choices are the shopper's own decisions --
     * which voucher, whether to take the flash discount, points and wallet --
     * never amounts: every figure is worked out here.
     *
     * ['ok' => false, 'message' => ...] when the order could not be placed as
     * it stands, so a gateway is never asked to take money for one.
     */
    public static function quote($request, array $choices = [], $lang = null)
    {
        $user = $request->user('user');
        $userToken = $user ? null : $request->user_token;

        $refuse = function ($message) {
            return ['ok' => false, 'message' => $message];
        };

        if (!$user && !$userToken) {
            return $refuse(__('lang.went_wrong', [], $lang));
        }

        $query = Cart::with('product_inner.admin');
        $query = $user ? $query->where('user_id', $user->id) : $query->where('user_token', $userToken);

        $rows = $query->with('shipping_place.shipping_rule')
            ->forCheckout()
            ->with('updated_inventory')
            ->get();

        if ($rows->isEmpty()) {
            return $refuse(__('lang.no_cart', [], $lang));
        }

        foreach ($rows as $row) {
            if (is_null($row->product_inner)) {
                return $refuse(__('lang.private_product', ['product' => optional($row->product)->title], $lang));
            }
        }

        $types = $rows->pluck('shipping_type')->map(function ($type) { return (int)$type; })->unique();

        if ($types->count() !== 1 || !Shipping::isValidType($types->first())) {
            return $refuse('Choose whether to have your order delivered or to collect it.');
        }

        $shippingType = $types->first();

        $tiers = BusinessProductPricing::tierMap($user, $rows->pluck('product_id'));
        $priced = self::lines($rows, $tiers);
        $goods = $priced['goods'];

        $voucherDiscount = 0.0;

        if (!empty($choices['voucher'])) {
            $voucher = self::voucher($choices['voucher'], $goods, $user, $userToken, $lang);

            if ($voucher['error']) {
                return $refuse($voucher['error']);
            }

            $voucherDiscount = $voucher['discount'];
        }

        $isCollection = $rows->every(function ($row) {
            return (int)$row->shipping_type === (int)Config::get('constants.shippingTypeIn.PICKUP');
        });

        $address = null;

        if (!$isCollection) {
            $address = self::deliveryAddress($user, $userToken);

            if (!$address) {
                return $refuse(__('lang.select_address', [], $lang));
            }
        }

        $shipping = self::shipping($rows, $shippingType, $address, $isCollection, $user, $goods);

        if (!$shipping['ok']) {
            return $refuse($shipping['message'] ?: __('lang.went_wrong', [], $lang));
        }

        $flash = 0.0;

        if (!empty($choices['apply_flash_discount'])) {
            $flash = Pricing::flashDiscount(FlashDiscount::current(), $goods);
        }

        $bundleOffered = BundleDiscount::forRows($rows)['total'];

        $loyaltyOffered = 0.0;

        if ($user) {
            $best = Membership::bestOrderDiscount($user, $goods, $priced['member_lines']);
            $loyaltyOffered = $best ? (float)$best['discount'] : 0.0;
        }

        $pointsRequested = 0.0;
        $pointsAvailable = 0.0;

        if ($user && !empty($choices['use_points'])) {
            $pointsRequested = (float)($choices['points_discount'] ?? 0);
            $pointsAvailable = self::pointsValue($user->id);
        }

        $gross = Pricing::round($goods + $priced['tax'] + (float)$shipping['price']);

        $chain = self::chain($gross, $voucherDiscount, $flash, $bundleOffered, $loyaltyOffered,
            $pointsRequested, $pointsAvailable);

        $wallet = 0.0;

        if ($user && !empty($choices['use_wallet'])
            && (int)($choices['order_method'] ?? 0) !== (int)Config::get('constants.paymentMethod.WALLET')) {
            $wallet = WalletSplit::preview($user->id, $chain['payable']);
        }

        return [
            'ok' => true,
            'message' => null,
            'goods' => $goods,
            'tax' => $priced['tax'],
            'shipping' => Pricing::round($shipping['price']),
            'gross' => $gross,
            'voucher' => $chain['voucher'],
            'flash_discount' => $chain['flash'],
            'bundle_discount' => $chain['bundle'],
            'loyalty_discount' => $chain['loyalty'],
            'points_discount' => $chain['points'],
            'total' => $chain['payable'],
            'wallet' => $wallet,
            'amount_due' => Pricing::round(max(0, $chain['payable'] - $wallet)),
        ];
    }


    /**
     * The address an order would be delivered to: the account's default, or
     * for a guest the default on their token, else the first address they
     * saved -- the same order processOrder() looks in. Read only.
     */
    private static function deliveryAddress($user, $userToken)
    {
        if ($user) {
            return $user->default_address ? UserAddress::find($user->default_address) : null;
        }

        $guest = GuestUser::where('user_token', $userToken)->first();

        if ($guest && $guest->default_address) {
            return UserAddress::find($guest->default_address);
        }

        return UserAddress::where('user_token', $userToken)->first();
    }
}
