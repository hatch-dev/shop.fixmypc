<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\Config;

/**
 * The one place prices, discounts and tax are decided.
 *
 * Before this existed the ladder was written out by hand in four places —
 * twice inside OrdersController::action() alone, using two different tests for
 * the same flash price — and the storefront had a fifth copy in
 * productPriceHelper.js. They disagreed, which is QA audit PRC-01 and part of
 * MON-01. Everything server-side now calls these methods.
 */
class Pricing
{
    /**
     * Price of one unit, before bundle, voucher, flash-discount and tax.
     *
     * Order of precedence, highest first:
     *   1. an active flash sale price  — a sale is a deliberate, dated override
     *   2. the variant's own price     — only when the variant has attributes
     *   3. the product's offer price
     *   4. the product's selling price
     *
     * The flash price used to be tested as "> 0" in one loop and "!== null" in
     * the other, so a 0.00 flash price meant two different things inside a
     * single request. It is now "a flash sale is running", once.
     *
     * $tiers are the business_product_pricing bands the customer is entitled
     * to — empty for everyone else. They are passed in rather than looked up so
     * this class keeps no database access. See businessPrice() for how a band
     * interacts with the retail ladder above.
     */
    public static function unitPrice($product, $inventory = null, $quantity = 1, $tiers = null)
    {
        if (is_null($product)) {
            return 0.0;
        }

        $retail = self::retailUnitPrice($product, $inventory);

        return self::businessPrice($retail, $tiers, $quantity);
    }


    /**
     * The ladder as a retail shopper sees it.
     */
    public static function retailUnitPrice($product, $inventory = null)
    {
        if (is_null($product)) {
            return 0.0;
        }

        /*
         * The price this line would carry with no sale on it: the variant's
         * own price when the line is a variation, the product's otherwise.
         */
        $base = self::baseUnitPrice($product, $inventory);

        /*
         * A sale reduces THAT, rather than replacing it.
         *
         * It used to replace it with one product-level figure, which is
         * wrong the moment a product has variants at different prices: a
         * flash price of 648 on the iPhone 15 Pro was a 51 reduction on the
         * 128GB and a 351 reduction on the 512GB, because all 36 variants
         * were flattened onto the same number.
         */
        if (self::hasActiveFlashSale($product)) {
            return self::flashUnitPrice($product, $base);
        }

        /*
         * A clearance reduces the base the same way, and for the same reason:
         * it is a percentage or a flat sum off whatever this line costs, so it
         * has to be worked out per variant rather than flattened onto one
         * product-level figure.
         *
         * Below the flash sale deliberately. A flash sale is a dated override
         * the shop has scheduled; a clearance is the standing markdown
         * underneath it, and the storefront has always shown the flash price
         * in preference -- so the till charges it.
         *
         * This step did not exist. The clearance was applied to the response
         * payload by FrontendController and nowhere else, so every caller of
         * this method -- the basket, the order, the voucher preview, the
         * bundle, cross-sell, the upgrade engine -- charged the undiscounted
         * price for a product the page had just advertised at a discount.
         */
        if (self::hasActiveClearance($product)) {
            return self::clearanceUnitPrice($product, $base);
        }

        return $base;
    }


    /** What this line costs before any sale, band or voucher. */
    public static function baseUnitPrice($product, $inventory = null)
    {
        $inventoryPrice = self::inventoryPrice($inventory);

        if ($inventoryPrice > 0) {
            return $inventoryPrice;
        }

        return self::shelfPrice($product);
    }


    /**
     * The sale price for one line, worked out from that line's own base.
     *
     * Three shapes, and the row says which it is:
     *
     *   percentage   a share off the base, so it scales across variants
     *   amount       a flat sum off the base, likewise
     *   fixed_price  one absolute price, which is every row written before
     *                flash_sale_products grew a discount shape and is still
     *                the right answer for a product with a single price
     *
     * A sale never RAISES a price -- the same rule businessPrice() applies
     * to a quantity band. A fixed_price above the base, or an amount bigger
     * than the base, lands on the base rather than above it.
     */
    public static function flashUnitPrice($product, $base)
    {
        $base = (float)$base;
        $type = $product->flash_discount_type ?? 'fixed_price';
        $value = max(0.0, (float)($product->flash_discount_value ?? 0));

        if ($type === 'percentage') {
            $price = $base - ($base * min($value, 100) / 100);
        } else if ($type === 'amount') {
            $price = $base - $value;
        } else {
            $price = (float)$product->price;
        }

        return self::round(max(0.0, min($price, $base)));
    }


    /**
     * The clearance price for one line, worked out from that line's own base.
     *
     * Two shapes, and the row says which:
     *
     *   percentage   a share off the base, so it scales across variants
     *   fixed        a flat sum off the base, likewise
     *
     * A clearance never RAISES a price and never takes it below zero -- the
     * same two rules a flash sale and a quantity band are held to. A stored
     * percentage above 100, or a flat sum bigger than the base, lands on zero
     * rather than going negative; the admin screen refuses to save either, and
     * this is the second line of defence for the rows saved before it did.
     */
    public static function clearanceUnitPrice($product, $base)
    {
        $base = (float)$base;

        $type = $product->clearance_discount_type ?? 'percentage';
        $value = max(0.0, (float)($product->clearance_discount_value ?? 0));

        if ($type === 'percentage') {
            $price = $base - ($base * min($value, 100) / 100);
        } else {
            $price = $base - $value;
        }

        return self::round(max(0.0, min($price, $base)));
    }


    /**
     * Is a clearance deal running on this record?
     *
     * The record carries the deal as two columns, put there by the join in
     * ClearanceWindow or by its attach(). A record fetched by a query that
     * does not ask for them simply has no clearance, which is the right answer
     * for the payloads that discount themselves before Pricing sees them.
     */
    public static function hasActiveClearance($product)
    {
        return !is_null($product)
            && !is_null($product->clearance_discount_value ?? null)
            && (float)$product->clearance_discount_value > 0;
    }


    /**
     * Apply a business quantity band to a retail price.
     *
     * Two rules, both deliberate:
     *
     * A band never raises the price. Wholesale rates are agreed months before
     * a flash sale is scheduled, so without this a business account could be
     * charged more than a walk-up consumer during a promotion — the one
     * outcome a trade customer will always notice.
     *
     * When bands overlap, the cheapest matching one wins. Overlaps are rejected
     * at the admin screen, but rows predating that check still exist, and
     * picking deterministically beats charging whichever row the database
     * happened to return first.
     */
    public static function businessPrice($retailPrice, $tiers, $quantity = 1)
    {
        $retailPrice = (float)$retailPrice;

        $band = self::businessTier($tiers, $quantity);

        if (is_null($band)) {
            return $retailPrice;
        }

        return self::round(min($retailPrice, $band));
    }


    /**
     * The cheapest band covering this quantity, or null when none does.
     */
    public static function businessTier($tiers, $quantity = 1)
    {
        $best = null;

        foreach (($tiers ?? []) as $tier) {
            if (!$tier->coversQuantity($quantity)) {
                continue;
            }

            $price = $tier->computedFinalPrice();

            if (is_null($best) || $price < $best) {
                $best = $price;
            }
        }

        return $best;
    }


    /**
     * A variant price only counts when the variant actually varies. A
     * single-inventory product carrying a stray price must not override the
     * product's own pricing.
     */
    public static function inventoryPrice($inventory)
    {
        if (is_null($inventory)) {
            return 0.0;
        }

        if (!self::isVariant($inventory)) {
            return 0.0;
        }

        return (float)($inventory->price ?? 0);
    }


    /**
     * The "before" figure a discount is measured against for one line.
     *
     * This is the variant's own list price when the line is a variation, and
     * the product's selling price otherwise — never the charged price, and
     * never below it, so a line can never render as a negative discount.
     *
     * It existed in two places and was written a third way at checkout:
     * CartsController decided it from the variant, while
     * OrdersController::action() persisted ordered_products_inventories
     * .actual_price straight from products.selling. On a variation product the
     * two disagree, which is exactly what made the Order Confirmation show a
     * larger Subtotal and a larger Product Discount than the customer had just
     * confirmed — €8,990 / −€2,250 against the basket's €6,990 / −€250 on a
     * variant listed at 699 against a product selling at 899.
     *
     * $charged is the unit price the line is actually being sold at, which is
     * what a business or trade band has already been applied to.
     */
    public static function listPrice($product, $inventory = null, $charged = null)
    {
        $variantPrice = self::inventoryPrice($inventory);

        $listPrice = $variantPrice > 0
            ? $variantPrice
            : (float)($product->selling ?? 0);

        if (is_null($charged)) {
            return self::round($listPrice);
        }

        return self::round(max($listPrice, (float)$charged));
    }


    /**
     * Does this inventory row represent a real variation?
     *
     * Accepts either the loaded relation or a withCount() result, so listing
     * queries can decide this without pulling every attribute row.
     */
    public static function isVariant($inventory)
    {
        if (is_null($inventory)) {
            return false;
        }

        $count = $inventory->inventory_attributes_count ?? null;

        if (!is_null($count)) {
            return (int)$count > 0;
        }

        $attributes = $inventory->inventory_attributes ?? null;

        return !is_null($attributes) && count($attributes) > 0;
    }


    /**
     * Is this variation actually buyable right now?
     *
     * Out of stock is fine when the variant allows backorder (is_active).
     */
    public static function isPurchasable($inventory)
    {
        if (is_null($inventory)) {
            return false;
        }

        return (int)($inventory->quantity ?? 0) > 0
            || (int)($inventory->is_active ?? 0) === 1;
    }


    /**
     * The price to advertise for a product, and the range it spans.
     *
     * A variation product's headline price has to be a price somebody can
     * actually pay. Listings previously showed products.offered / selling — the
     * product-level figure — while checkout charges the selected variant's own
     * price, so a product whose cheapest variant costs 500.00 advertised at
     * 450.00 and then charged 500.00 at the till.
     *
     * Only purchasable variants count, so a cheap sold-out variation cannot
     * advertise a price nobody can buy.
     *
     * Returns:
     *   min       lowest purchasable price (the headline)
     *   max       highest purchasable price
     *   variable  true when variants disagree, so the UI can show "From x"
     *   compare   the struck-through "was" price, or null when there is no offer
     *   in_stock  whether anything is purchasable at all
     */
    public static function displayRange($product, $inventories = null, $tiers = null)
    {
        $selling = (float)($product->selling ?? 0);
        $offered = (float)($product->offered ?? 0);

        /*
         * $tiers are the quantity bands this customer is entitled to, empty
         * for everyone else. A listing advertises the price of buying one, so
         * the bands are applied at quantity 1 — the same call checkout makes
         * for a single-unit line.
         *
         * Without this a business or approved-trade customer was advertised
         * the retail price on every tile, in search and on the product page,
         * and only discovered their own rate at the basket. The figures were
         * not wrong in the customer's favour, which is precisely why nobody
         * reported it: the store was quietly under-selling its own trade rates.
         */
        $band = self::businessTier($tiers, 1);

        /*
         * A flash sale used to return here, flat: one price, min == max,
         * variable false. On a product with variants at different prices
         * that was wrong twice over -- it discounted the dear variants far
         * harder than intended, and it collapsed "From 699" into a single
         * figure, so the tile stopped saying the product had variants at
         * all.
         *
         * It is now applied per variant, below, alongside the quantity
         * band -- both are reducers on a base price, and the range, the
         * "was" figure and the variable flag all fall out of the existing
         * machinery rather than being special-cased.
         */
        $flash = self::hasActiveFlashSale($product);

        // A clearance is the same kind of reducer, one rung lower: the flash
        // sale is the dated override, so where both run the flash price wins.
        $clearance = !$flash && self::hasActiveClearance($product);

        $base = $offered > 0 ? $offered : $selling;

        $variantPrices = [];

        foreach (($inventories ?? []) as $inventory) {
            if (!self::isPurchasable($inventory)) {
                continue;
            }

            $price = self::inventoryPrice($inventory);

            // A variant with no price of its own inherits the product's.
            $variantPrices[] = $price > 0 ? $price : $base;
        }

        if (count($variantPrices) === 0) {
            $single = $flash ? self::flashUnitPrice($product, $base)
                : ($clearance ? self::clearanceUnitPrice($product, $base) : $base);
            $single = self::businessPrice($single, $tiers, 1);

            return [
                'min' => self::round($single),
                'max' => self::round($single),
                'variable' => false,
                /*
                 * When a band is what brings the price down, the struck-through
                 * figure is the retail price it undercuts — otherwise a trade
                 * customer sees a discount with nothing shown to compare it to.
                 */
                'compare' => self::compareFor($selling, $offered, $base, $single),
                'in_stock' => self::anyPurchasable($inventories),
            ];
        }

        // Kept so the "was" figure is the price before any reduction.
        $retailMin = min($variantPrices);

        if ($flash) {
            $variantPrices = array_map(function ($price) use ($product) {
                return self::flashUnitPrice($product, $price);
            }, $variantPrices);
        }

        /*
         * The same reducer, for the markdown that sits under a flash sale.
         *
         * display_price is the figure a tile and the product page actually
         * print. It is built from the variants' own prices, which the
         * clearance pass in FrontendController never touched -- so on a
         * product whose variants carry their own price the deal moved
         * products.offered, which nothing renders, and left the headline at
         * full price under a CLEARANCE badge.
         */
        if ($clearance) {
            $variantPrices = array_map(function ($price) use ($product) {
                return self::clearanceUnitPrice($product, $price);
            }, $variantPrices);
        }

        if (!is_null($band)) {
            $variantPrices = array_map(function ($price) use ($tiers) {
                return self::businessPrice($price, $tiers, 1);
            }, $variantPrices);
        }

        $min = min($variantPrices);
        $max = max($variantPrices);

        /*
         * Only show a "was" price when the price is genuinely reduced.
         * Comparing a variant price against the product's selling price
         * produced struck-through numbers identical to the live price.
         */
        $compare = null;

        if ($min < $retailMin - 0.005) {
            $compare = self::round($retailMin);

        } else if ($offered > 0 && $selling > $offered && abs($min - $offered) < 0.005) {
            $compare = self::round($selling);
        }

        return [
            'min' => self::round($min),
            'max' => self::round($max),
            'variable' => abs($max - $min) >= 0.005,
            'compare' => $compare,
            'in_stock' => true,
        ];
    }


    /**
     * The struck-through "was" figure for a single unit.
     *
     * $base is the retail price before any band, $charged what the customer
     * will actually pay. Three cases, in order: a band undercut the retail
     * price, so show the retail price; there is a genuine offer, so show the
     * pre-offer selling price; otherwise there is nothing to strike through.
     */
    public static function compareFor($selling, $offered, $base, $charged)
    {
        if ($charged < $base - 0.005) {
            return self::round($base);
        }

        if ($offered > 0 && $selling > $offered) {
            return self::round($selling);
        }

        return null;
    }


    public static function anyPurchasable($inventories)
    {
        foreach (($inventories ?? []) as $inventory) {
            if (self::isPurchasable($inventory)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Is a flash sale running on this record?
     *
     * The query that built the record decided the WINDOW (see
     * Helper\FlashSaleWindow); end_time is only present when a live sale
     * joined. What is decided here is whether the price it carries is one
     * that can be charged.
     *
     * Zero and below are not. flash_sale_products.price defaults to 0.00 and
     * the admin form seeds its input with 0, so 'a sale nobody finished
     * entering' and 'this product is free' were the same row -- and the
     * basket charged 0.00 for it. A negative one became a negative line.
     * The admin screen now refuses both; this covers the rows that predate
     * that check and anything written around it.
     */
    public static function hasActiveFlashSale($product)
    {
        return !is_null($product)
            && !is_null($product->end_time)
            && !is_null($product->price)
            && (float)$product->price > 0;
    }


    /** The product's own price, before variants and before any sale. */
    public static function shelfPrice($product)
    {
        $offered = (float)($product->offered ?? 0);

        return $offered > 0 ? $offered : (float)($product->selling ?? 0);
    }


    /**
     * Units given away by a "buy X get Y free" bundle on this line.
     */
    public static function bundleFreeQuantity($product, $quantity)
    {
        $bundleDeal = $product->bundle_deal ?? null;

        if (!$bundleDeal) {
            return 0;
        }

        return (int)$quantity >= (int)$bundleDeal->buy ? (int)$bundleDeal->free : 0;
    }


    /**
     * Tax on one unit.
     *
     * QA audit ORD-03: the caller must multiply this by the *payable* quantity
     * (quantity minus bundle freebies), not the ordered quantity — free items
     * were being taxed.
     */
    public static function unitTax($taxRule, $unitPrice, $productTaxable)
    {
        if (!$taxRule || !$productTaxable) {
            return 0.0;
        }

        if ((int)$taxRule->type === (int)Config::get('constants.priceType.FLAT')) {
            return self::round((float)$taxRule->price);
        }

        return self::round(((float)$taxRule->price * (float)$unitPrice) / 100);
    }


    /**
     * How this shop treats a product's price for VAT.
     *
     * Two conventions live side by side in this catalogue, and which one
     * applies is the single most important thing to know before showing a
     * price:
     *
     *   excludeVAT = 1  the listed price is EX-VAT and the rule's rate is
     *                   added on top at checkout. 11 products, all B2B-facing.
     *
     *   excludeVAT = 0  the listed price is INC-VAT. The VAT is inside the
     *                   figure already and nothing is added; this is the
     *                   ordinary retail convention and covers 111 products.
     *
     * Returns true when the price is VAT-inclusive.
     */
    public static function isVatInclusive($product)
    {
        return (int)($product->excludeVAT ?? 0) !== 1;
    }


    /**
     * The rate a product's tax rule charges, as a percentage.
     *
     * A flat-amount rule has no percentage to report, so it answers 0 and the
     * caller shows the amount instead. "VAT Margin" is a real 0% rule — a
     * margin-scheme product — and is not the same thing as "no rule", but both
     * come out as 0 here because both mean no percentage is applied.
     */
    public static function vatRate($taxRule)
    {
        if (!$taxRule) {
            return 0.0;
        }

        if ((int)$taxRule->type === (int)Config::get('constants.priceType.FLAT')) {
            return 0.0;
        }

        return (float)$taxRule->price;
    }


    /**
     * Split an amount into ex-VAT, VAT and inc-VAT.
     *
     * The one place the arithmetic lives. Every screen that shows a VAT
     * breakdown — the product page, the basket, checkout, the order, the admin
     * and the invoice — asks this rather than dividing by 1.23 of its own
     * accord, so they cannot drift apart or disagree about which way round the
     * split goes.
     *
     * $inclusive says whether $amount already contains the VAT:
     *
     *   inclusive   ex  = amount / (1 + rate)     vat = amount - ex
     *   exclusive   ex  = amount                  vat = amount * rate
     *
     * Rounded once, at the end, to the same two decimals as every other money
     * figure — and `inc` is derived from the rounded parts rather than rounded
     * separately, so ex + vat = inc always holds on screen.
     *
     * @param  float  $amount     the price as the shop states it
     * @param  float  $rate       percentage, e.g. 23 — never assumed
     * @param  bool   $inclusive  whether $amount already contains the VAT
     * @return array{ex: float, vat: float, inc: float, rate: float, inclusive: bool}
     */
    public static function vatBreakdown($amount, $rate, $inclusive)
    {
        $amount = (float)$amount;
        $rate = (float)$rate;

        if ($rate <= 0) {
            // A zero-rated or margin-scheme line: no VAT either way.
            return [
                'ex' => self::round($amount),
                'vat' => 0.0,
                'inc' => self::round($amount),
                'rate' => 0.0,
                'inclusive' => (bool)$inclusive,
            ];
        }

        if ($inclusive) {
            $ex = self::round($amount / (1 + ($rate / 100)));
            $vat = self::round($amount - $ex);

            return [
                'ex' => $ex,
                'vat' => $vat,
                'inc' => self::round($ex + $vat),
                'rate' => $rate,
                'inclusive' => true,
            ];
        }

        $ex = self::round($amount);
        $vat = self::round(($amount * $rate) / 100);

        return [
            'ex' => $ex,
            'vat' => $vat,
            'inc' => self::round($ex + $vat),
            'rate' => $rate,
            'inclusive' => false,
        ];
    }


    /**
     * Discount for a voucher against a subtotal.
     *
     * QA audit CPN-04: the cap used to be applied with an (int) cast, so a
     * 10.99 cap paid out 10 and a 0.99 cap paid out nothing.
     */
    public static function voucherDiscount($voucher, $subtotal)
    {
        if (!$voucher) {
            return 0.0;
        }

        // A gift card: its remaining balance, up to the value of the goods.
        if (is_object($voucher) && method_exists($voucher, 'isGiftCard') && $voucher->isGiftCard()) {
            return self::round(max(0, min($voucher->giftBalance(), (float)$subtotal)));
        }

        if ((int)$voucher->type === (int)Config::get('constants.priceType.FLAT')) {
            $discount = (float)$voucher->price;
        } else {
            $discount = ((float)$voucher->price * (float)$subtotal) / 100;
        }

        if (!is_null($voucher->capped_price)) {
            $discount = min($discount, (float)$voucher->capped_price);
        }

        // Never hand back more than the goods are worth.
        return self::round(max(0, min($discount, (float)$subtotal)));
    }


    /**
     * An Essentials Bundle's discount against the subtotal of ITS OWN lines.
     *
     * The same shape as the other basket-level discounts here, and for the
     * same reason: it is re-derived from the rule every time money is worked
     * out, never banked on a line. `carts.price` is rewritten from the
     * catalogue on every pass, so a figure written there is undone by the next
     * one -- a line may only remember WHICH bundle it came in under
     * (`carts.bundle_deal_id`), and this turns that reference back into money.
     *
     * The subtotal passed in must be what those lines are actually being
     * charged, not their list prices, so the percentage cannot quietly become
     * larger than the goods.
     */
    public static function bundleDiscount($bundle, $subtotal)
    {
        if (!$bundle) {
            return 0.0;
        }

        $subtotal = (float)$subtotal;

        if ($subtotal <= 0) {
            return 0.0;
        }

        if ($bundle->discount_type === 'percentage') {
            $discount = ($subtotal * (float)$bundle->discount_value) / 100;
        } else {
            $discount = (float)$bundle->discount_value;
        }

        // Never hand back more than the bundle's own goods are worth.
        return self::round(max(0, min($discount, $subtotal)));
    }

    /**
     * Store-wide flash discount against a subtotal.
     *
     * QA audit ORD-03: the base is the subtotal actually charged, not the sum
     * of list prices, which is what the old inline version used.
     *
     * A cap of 0 means NO cap, not "no discount". The admin form offers Max
     * Discount as an optional field and starts it at 0, so `is_null` alone
     * read an untouched field as a ceiling of zero and min(discount, 0) then
     * cancelled the whole promotion -- server-side only, because the basket
     * banner tested the same field for truthiness and paid out in full. A
     * EUR 100 basket previewed EUR 10 off and was charged EUR 0 off.
     * loyaltyDiscount below has always guarded with `> 0`; this now matches it.
     */
    public static function flashDiscount($flash, $subtotal)
    {
        if (!$flash) {
            return 0.0;
        }

        $subtotal = (float)$subtotal;

        if ($subtotal <= 0 || $subtotal < (float)$flash->min_cart_value) {
            return 0.0;
        }

        if ($flash->type === 'percentage') {
            $discount = ($subtotal * (float)$flash->value) / 100;
        } else {
            $discount = (float)$flash->value;
        }

        if (!is_null($flash->max_discount) && (float)$flash->max_discount > 0) {
            $discount = min($discount, (float)$flash->max_discount);
        }

        return self::round(max(0, min($discount, $subtotal)));
    }

    /**
     * Loyalty group discount against a subtotal.
     *
     * Same shape as flashDiscount: a floor the cart has to clear, an optional
     * cap, and never more than the goods are worth. A percentage group with no
     * cap could otherwise take an order to zero -- which nothing prevented,
     * because nothing applied the group's discount at all.
     */
    public static function loyaltyDiscount($group, $subtotal)
    {
        if (!$group) {
            return 0.0;
        }

        $subtotal = (float)$subtotal;

        if ($subtotal <= 0 || $subtotal < (float)$group->min_cart_value) {
            return 0.0;
        }

        if ($group->discount_type === 'percentage') {
            $discount = ($subtotal * (float)$group->discount_value) / 100;
        } else {
            $discount = (float)$group->discount_value;
        }

        if (!is_null($group->max_discount) && (float)$group->max_discount > 0) {
            $discount = min($discount, (float)$group->max_discount);
        }

        return self::round(max(0, min($discount, $subtotal)));
    }



    /**
     * Money rounding, in one place. Half-up to two decimals.
     */
    public static function round($amount)
    {
        return round((float)$amount, 2);
    }


    /**
     * Minor units for a gateway (cents).
     *
     * QA audit PAY-04: (int)($total * 100) truncated, so 1.15 was charged as
     * 114 cents. Rounding first fixes the float representation.
     */
    public static function toMinorUnits($amount)
    {
        return (int)round(((float)$amount) * 100);
    }
}
