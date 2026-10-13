<?php

namespace Tests\Unit;

use App\Models\Helper\Pricing;
use Tests\TestCase;

/**
 * Regression tests for the pricing, discount and tax arithmetic.
 *
 * Each case here corresponds to a finding in
 * reference/qa/qa-audit-2026-09-09.html. None of it needs a database.
 */
class PricingTest extends TestCase
{
    private function product($overrides = [])
    {
        return (object)array_merge([
            'selling' => 100.0,
            'offered' => 0.0,
            'price' => null,
            'end_time' => null,
            'bundle_deal' => null,
        ], $overrides);
    }

    private function inventory($price, $hasAttributes = true)
    {
        return (object)[
            'price' => $price,
            'inventory_attributes' => $hasAttributes ? [1] : [],
        ];
    }

    // ---- PRC-01: one price ladder, flash first -----------------------------

    public function test_flash_price_beats_a_variant_price()
    {
        $product = $this->product(['price' => 50.0, 'end_time' => '2030-01-01', 'offered' => 80.0]);

        $this->assertSame(50.0, Pricing::unitPrice($product, $this->inventory(90.0)));
    }

    public function test_variant_price_beats_the_offer_price()
    {
        $product = $this->product(['offered' => 80.0]);

        $this->assertSame(90.0, Pricing::unitPrice($product, $this->inventory(90.0)));
    }

    public function test_offer_price_beats_the_selling_price()
    {
        $this->assertSame(80.0, Pricing::unitPrice($this->product(['offered' => 80.0]), null));
    }

    public function test_selling_price_is_the_floor()
    {
        $this->assertSame(100.0, Pricing::unitPrice($this->product(), null));
    }

    public function test_a_price_on_a_single_inventory_row_is_not_a_variant_price()
    {
        $product = $this->product(['offered' => 80.0]);

        $this->assertSame(80.0, Pricing::unitPrice($product, $this->inventory(90.0, false)));
    }

    /**
     * A flash price of zero is not a flash price.
     *
     * This used to assert the opposite, for a good reason that is preserved
     * here: the code tested the flash price as "> 0" in one loop and
     * "!== null" in another, so a free flash item was priced two different
     * ways inside one request, and settling on a single reading mattered more
     * than which reading it was.
     *
     * The reading has since been settled the other way, because the ambiguity
     * turned out to be in the DATA rather than the code.
     * flash_sale_products.price defaults to 0.00 and the admin form seeds its
     * input with 0 the moment a product is added, so "a sale nobody finished
     * entering" and "this product is free" were the same row -- and the
     * basket charged 0.00 for it (QA FS-V2/FS-X2). The admin screen now
     * refuses to save one; this is the guard for the rows that predate it.
     */
    public function test_a_zero_flash_price_is_not_a_flash_price()
    {
        $product = $this->product(['price' => 0.0, 'end_time' => '2030-01-01', 'offered' => 80.0]);

        $this->assertSame(80.0, Pricing::unitPrice($product, null));
    }

    /** Nor is a negative one, which became a negative basket line. */
    public function test_a_negative_flash_price_is_not_a_flash_price()
    {
        $product = $this->product(['price' => -50.0, 'end_time' => '2030-01-01', 'offered' => 80.0]);

        $this->assertSame(80.0, Pricing::unitPrice($product, null));
    }

    /**
     * A sale never raises a price -- the rule businessPrice() already applies
     * to a quantity band, for the same reason.
     */
    public function test_a_flash_price_above_the_shelf_price_does_not_raise_it()
    {
        $product = $this->product(['price' => 150.0, 'end_time' => '2030-01-01', 'offered' => 80.0]);

        $this->assertSame(80.0, Pricing::unitPrice($product, null));
    }

    /** A genuine reduction still wins outright. */
    public function test_a_flash_price_below_the_shelf_price_wins()
    {
        $product = $this->product(['price' => 60.0, 'end_time' => '2030-01-01', 'offered' => 80.0]);

        $this->assertSame(60.0, Pricing::unitPrice($product, null));
    }

    public function test_a_null_product_prices_at_zero_rather_than_throwing()
    {
        $this->assertSame(0.0, Pricing::unitPrice(null, null));
    }

    // ---- CPN-04: voucher caps are not truncated ----------------------------

    public function test_a_fractional_voucher_cap_is_not_truncated_to_a_whole_unit()
    {
        $voucher = (object)['type' => 1, 'price' => 500, 'capped_price' => 10.99];

        $this->assertSame(10.99, Pricing::voucherDiscount($voucher, 200));
    }

    public function test_a_sub_unit_voucher_cap_still_pays_out()
    {
        $voucher = (object)['type' => 1, 'price' => 500, 'capped_price' => 0.99];

        $this->assertSame(0.99, Pricing::voucherDiscount($voucher, 200));
    }

    public function test_a_percentage_voucher_is_calculated_on_the_subtotal()
    {
        $voucher = (object)['type' => 2, 'price' => 15, 'capped_price' => 150];

        $this->assertSame(30.0, Pricing::voucherDiscount($voucher, 200));
    }

    public function test_a_voucher_never_discounts_more_than_the_goods_are_worth()
    {
        $voucher = (object)['type' => 1, 'price' => 500, 'capped_price' => null];

        $this->assertSame(5.0, Pricing::voucherDiscount($voucher, 5));
    }

    public function test_no_voucher_means_no_discount()
    {
        $this->assertSame(0.0, Pricing::voucherDiscount(null, 200));
    }

    // ---- PAY-04: money reaches the gateway intact --------------------------

    public function test_minor_units_round_rather_than_truncate()
    {
        // (int)(1.15 * 100) is 114 — a cent short on every such order.
        $this->assertSame(115, Pricing::toMinorUnits(1.15));
        $this->assertSame(1010, Pricing::toMinorUnits(10.10));
        $this->assertSame(4620, Pricing::toMinorUnits(46.20));
        $this->assertSame(7990, Pricing::toMinorUnits(79.90));
        $this->assertSame(1, Pricing::toMinorUnits(0.01));
        $this->assertSame(139600, Pricing::toMinorUnits(1396.00));
    }

    // ---- ORD-03: bundles, tax and the flash discount base ------------------

    public function test_percentage_tax_uses_the_rule_rate_not_a_hardcoded_23()
    {
        $rule = (object)['type' => 2, 'price' => 13.5];

        $this->assertSame(13.5, Pricing::unitTax($rule, 100.0, true));
    }

    public function test_a_flat_tax_rule_is_a_flat_amount()
    {
        $rule = (object)['type' => 1, 'price' => 5.0];

        $this->assertSame(5.0, Pricing::unitTax($rule, 100.0, true));
    }

    public function test_a_non_taxable_product_is_not_taxed()
    {
        $rule = (object)['type' => 2, 'price' => 23];

        $this->assertSame(0.0, Pricing::unitTax($rule, 100.0, false));
    }

    public function test_a_zero_rate_rule_adds_nothing()
    {
        $rule = (object)['type' => 2, 'price' => 0];

        $this->assertSame(0.0, Pricing::unitTax($rule, 100.0, true));
    }

    public function test_bundle_free_units_are_counted_once_the_threshold_is_met()
    {
        $product = $this->product(['bundle_deal' => (object)['buy' => 3, 'free' => 1]]);

        $this->assertSame(0, Pricing::bundleFreeQuantity($product, 2));
        $this->assertSame(1, Pricing::bundleFreeQuantity($product, 3));
        $this->assertSame(1, Pricing::bundleFreeQuantity($product, 10));
    }

    public function test_a_product_with_no_bundle_gives_nothing_away()
    {
        $this->assertSame(0, Pricing::bundleFreeQuantity($this->product(), 10));
    }

    public function test_tax_applies_only_to_the_units_actually_paid_for()
    {
        // Buy 3 get 1 free at 100.00 with 23% VAT: three units are taxed, not
        // four. The old code multiplied by the full ordered quantity.
        $rule = (object)['type' => 2, 'price' => 23];
        $product = $this->product(['bundle_deal' => (object)['buy' => 3, 'free' => 1]]);

        $unitTax = Pricing::unitTax($rule, 100.0, true);
        $payable = 4 - Pricing::bundleFreeQuantity($product, 4);

        $this->assertSame(69.0, Pricing::round($unitTax * $payable));
    }

    // ---- flash discount ----------------------------------------------------

    public function test_flash_discount_respects_its_cap()
    {
        $flash = (object)['type' => 'percentage', 'value' => 10, 'min_cart_value' => 100, 'max_discount' => 50];

        $this->assertSame(30.0, Pricing::flashDiscount($flash, 300));
        $this->assertSame(50.0, Pricing::flashDiscount($flash, 900));
    }

    public function test_flash_discount_does_not_apply_below_the_minimum_spend()
    {
        $flash = (object)['type' => 'percentage', 'value' => 10, 'min_cart_value' => 100, 'max_discount' => 50];

        $this->assertSame(0.0, Pricing::flashDiscount($flash, 50));
    }

    public function test_a_flat_flash_discount_never_exceeds_the_subtotal()
    {
        $flash = (object)['type' => 'flat', 'value' => 500, 'min_cart_value' => 100, 'max_discount' => null];

        $this->assertSame(150.0, Pricing::flashDiscount($flash, 150));
    }

    // ---- MON-01: the payable total ----------------------------------------

    public function test_every_discount_comes_off_the_payable_total()
    {
        // Order 65 in production: 200.00 of flash discount was recorded against
        // the order and 1646.00 was charged anyway.
        $total = 1646.00;
        $voucher = 0.0;
        $flash = 200.00;
        $points = 50.00;

        $payable = Pricing::round(max(0, $total - $voucher - $flash - $points));

        $this->assertSame(1396.0, $payable);
    }

    public function test_the_payable_total_is_floored_at_zero()
    {
        $this->assertSame(0.0, Pricing::round(max(0, 10 - 0 - 20 - 5)));
    }
    // ---- listing display price: variation products ------------------------

    private function inv($price, $qty, $backorder = 0, $attrCount = 2)
    {
        $i = new \stdClass();
        $i->price = $price;
        $i->quantity = $qty;
        $i->is_active = $backorder;
        $i->inventory_attributes_count = $attrCount;
        return $i;
    }

    public function test_a_variation_product_advertises_its_cheapest_buyable_variant()
    {
        /*
         * The listing tile used to print products.offered, so a laptop offered
         * at 450.00 whose cheapest in-stock variant costs 500.00 advertised
         * 450.00 and charged 500.00 at the till.
         */
        $product = $this->product(["selling" => 500.0, "offered" => 450.0]);

        $range = Pricing::displayRange($product, [
            $this->inv(500.0, 10),
            $this->inv(650.0, 5),
            $this->inv(700.0, 0, 1),
        ]);

        $this->assertSame(500.0, $range["min"]);
        $this->assertSame(700.0, $range["max"]);
        $this->assertTrue($range["variable"]);
        $this->assertTrue($range["in_stock"]);
    }

    public function test_a_sold_out_variant_cannot_set_the_headline_price()
    {
        $product = $this->product(["selling" => 900.0]);

        $range = Pricing::displayRange($product, [
            $this->inv(300.0, 0),   // cheapest, but nobody can buy it
            $this->inv(800.0, 4),
        ]);

        $this->assertSame(800.0, $range["min"]);
    }

    public function test_a_backorder_variant_still_counts_as_buyable()
    {
        $product = $this->product(["selling" => 900.0]);

        $range = Pricing::displayRange($product, [
            $this->inv(300.0, 0, 1),
            $this->inv(800.0, 4),
        ]);

        $this->assertSame(300.0, $range["min"]);
    }

    public function test_a_product_with_no_buyable_variant_falls_back_to_its_own_price()
    {
        $product = $this->product(["selling" => 1000.0, "offered" => 899.0]);

        $range = Pricing::displayRange($product, [$this->inv(1000.0, 0)]);

        $this->assertSame(899.0, $range["min"]);
        $this->assertFalse($range["in_stock"]);
        $this->assertSame(1000.0, $range["compare"]);
    }

    public function test_a_variant_without_its_own_price_inherits_the_product_price()
    {
        $product = $this->product(["selling" => 400.0, "offered" => 0.0]);

        $range = Pricing::displayRange($product, [$this->inv(0.0, 3)]);

        $this->assertSame(400.0, $range["min"]);
        $this->assertFalse($range["variable"]);
    }

    public function test_a_lone_inventory_row_without_attributes_is_not_a_variant_price()
    {
        $product = $this->product(["selling" => 400.0]);

        // attrCount 0: a simple product, so its inventory price must not win.
        $range = Pricing::displayRange($product, [$this->inv(999.0, 3, 0, 0)]);

        $this->assertSame(400.0, $range["min"]);
    }

    public function test_no_compare_price_when_the_offer_equals_the_selling_price()
    {
        // Listings showed "€689.99 €689.99" with the second struck through.
        $product = $this->product(["selling" => 689.99, "offered" => 689.99]);

        $range = Pricing::displayRange($product, [$this->inv(0.0, 5)]);

        $this->assertNull($range["compare"]);
    }

    /**
     * An absolute flash price still overrides the variant range.
     *
     * The "was" figure is the VARIANT's price, not the product's selling
     * price: 700 is what this customer would otherwise pay, and 900 is a
     * number nobody is charged for it. This used to assert 900, from when a
     * flash sale replaced the whole range with one product-level price.
     */
    public function test_an_absolute_flash_price_overrides_the_variant_range()
    {
        $product = $this->product([
            "selling" => 900.0, "offered" => 800.0,
            "price" => 500.0, "end_time" => "2030-01-01",
        ]);

        $range = Pricing::displayRange($product, [$this->inv(700.0, 5)]);

        $this->assertSame(500.0, $range["min"]);
        $this->assertFalse($range["variable"]);
        $this->assertSame(700.0, $range["compare"]);
    }

    /**
     * A percentage reduces each variant from its own price, so a product
     * whose variants differ in price stays a range.
     *
     * The case the whole discount shape exists for: one absolute price
     * across tiers of 700 and 1000 is a different reduction on each, and it
     * also collapsed the tile from "From 700" to a single figure.
     */
    public function test_a_percentage_flash_sale_scales_across_the_variants()
    {
        $product = $this->product([
            "selling" => 1000.0, "offered" => 0.0,
            "price" => 800.0, "end_time" => "2030-01-01",
            "flash_discount_type" => "percentage", "flash_discount_value" => 20.0,
        ]);

        $range = Pricing::displayRange($product, [$this->inv(700.0, 5), $this->inv(1000.0, 5)]);

        $this->assertSame(560.0, $range["min"]);
        $this->assertSame(800.0, $range["max"]);
        $this->assertTrue($range["variable"]);
        $this->assertSame(700.0, $range["compare"]);
    }

    /** A flat amount off does the same, per variant. */
    public function test_an_amount_flash_sale_comes_off_each_variant()
    {
        $product = $this->product([
            "selling" => 1000.0, "offered" => 0.0,
            "price" => 900.0, "end_time" => "2030-01-01",
            "flash_discount_type" => "amount", "flash_discount_value" => 100.0,
        ]);

        $this->assertSame(600.0,
            Pricing::retailUnitPrice($product, $this->inv(700.0, 5)));
        $this->assertSame(900.0,
            Pricing::retailUnitPrice($product, $this->inv(1000.0, 5)));
    }

    /** And neither shape can push a variant below zero or above its price. */
    public function test_a_scaling_flash_sale_never_leaves_the_variant_price_range()
    {
        $tooBig = $this->product([
            "selling" => 1000.0, "offered" => 0.0,
            "price" => 1.0, "end_time" => "2030-01-01",
            "flash_discount_type" => "amount", "flash_discount_value" => 5000.0,
        ]);

        $this->assertSame(0.0, Pricing::retailUnitPrice($tooBig, $this->inv(700.0, 5)));

        $negative = $this->product([
            "selling" => 1000.0, "offered" => 0.0,
            "price" => 800.0, "end_time" => "2030-01-01",
            "flash_discount_type" => "percentage", "flash_discount_value" => -50.0,
        ]);

        $this->assertSame(700.0, Pricing::retailUnitPrice($negative, $this->inv(700.0, 5)));
    }

    public function test_a_product_with_no_inventory_rows_uses_its_own_price()
    {
        $product = $this->product(["selling" => 250.0]);

        $range = Pricing::displayRange($product, []);

        $this->assertSame(250.0, $range["min"]);
        $this->assertFalse($range["in_stock"]);
    }

    // ---- loyalty group discount -------------------------------------------

    private function loyaltyGroup($overrides = [])
    {
        return (object)array_merge([
            'discount_type' => 'fixed',
            'discount_value' => 10.0,
            'min_cart_value' => null,
            'max_discount' => null,
        ], $overrides);
    }

    public function test_a_percentage_loyalty_discount_is_taken_off_the_subtotal()
    {
        $group = $this->loyaltyGroup(['discount_type' => 'percentage', 'discount_value' => 10]);

        $this->assertSame(20.0, Pricing::loyaltyDiscount($group, 200));
    }

    public function test_a_loyalty_discount_respects_its_cap()
    {
        $group = $this->loyaltyGroup([
            'discount_type' => 'percentage',
            'discount_value' => 50,
            'max_discount' => 15,
        ]);

        $this->assertSame(15.0, Pricing::loyaltyDiscount($group, 200));
    }

    public function test_a_loyalty_discount_does_not_apply_below_the_minimum_spend()
    {
        $group = $this->loyaltyGroup(['discount_value' => 20, 'min_cart_value' => 100]);

        $this->assertSame(0.0, Pricing::loyaltyDiscount($group, 50));
        $this->assertSame(20.0, Pricing::loyaltyDiscount($group, 150));
    }

    /**
     * An uncapped group could otherwise hand back more than the goods cost,
     * which with the voucher and the points on top is a negative order.
     */
    public function test_a_loyalty_discount_never_exceeds_the_subtotal()
    {
        $group = $this->loyaltyGroup(['discount_value' => 500]);

        $this->assertSame(200.0, Pricing::loyaltyDiscount($group, 200));
    }

    public function test_no_loyalty_group_means_no_discount()
    {
        $this->assertSame(0.0, Pricing::loyaltyDiscount(null, 200));
    }

    public function test_an_empty_basket_earns_no_loyalty_discount()
    {
        $this->assertSame(0.0, Pricing::loyaltyDiscount($this->loyaltyGroup(), 0));
    }

    /**
     * The four discounts stack in one direction only: each is capped by what is
     * still owed after the ones before it, so the payable total bottoms out at
     * zero rather than going negative.
     */
    public function test_loyalty_is_capped_by_what_the_earlier_discounts_left_owing()
    {
        $subtotal = 100.0;
        $voucher  = 60.0;
        $flash    = 30.0;

        $group = $this->loyaltyGroup(['discount_value' => 25]);

        $offered   = Pricing::loyaltyDiscount($group, $subtotal);
        $remaining = max(0, Pricing::round($subtotal - $voucher - $flash));
        $granted   = Pricing::round(max(0, min($offered, $remaining)));

        $this->assertSame(25.0, $offered);
        $this->assertSame(10.0, $granted);

        $payable = Pricing::round(max(0, $subtotal - $voucher - $flash - $granted));

        $this->assertSame(0.0, $payable);
    }
}
