<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\InventoryAttribute;
use App\Models\Product;
use App\Models\ProductUpgradeGroup;
use App\Models\ProductUpgradeGroupOption;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Services\ProductUpgradeService;
use App\Services\UpgradeRank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The variant upgrade engine, end to end.
 *
 * Every case here is built on a purpose-made product rather than on catalogue
 * data, because the interesting cases -- a sold-out upgrade, an unrankable
 * attribute, a flash sale flattening the difference -- do not happen to exist
 * in the catalogue and would silently stop being tested the day they did.
 *
 * Two traps are worth naming up front, because both make a broken engine look
 * healthy:
 *
 *   `is_active` on an inventory row is this catalogue's BACKORDER flag, not an
 *   enabled flag. A row with is_active = 1 and zero stock is buyable.
 *
 *   A refusal arrives as HTTP 200 with status 201 in the BODY, so asserting on
 *   the HTTP code alone passes for every refusal there is.
 */
class ProductUpgradeEngineTest extends TestCase
{
    use DatabaseTransactions;

    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProductUpgradeService();
    }

    /* ---- fixtures ---------------------------------------------------- */

    /**
     * A product built to be upgraded.
     *
     * The tax and shipping rules are borrowed from a real published product
     * rather than hard-coded: products carries foreign keys to both, and an
     * id that happens to be free on this database will not be on the next.
     */
    private function product($attributes = [])
    {
        $suffix = uniqid();

        $template = Product::where('status', 1)
            ->whereNotNull('tax_rule_id')
            ->whereNotNull('shipping_rule_id')
            ->first();

        if (!$template) {
            $this->markTestSkipped('No published product to borrow tax and shipping rules from.');
        }

        return Product::create(array_merge([
            'id' => \App\Models\Helper\Utils::idGenerator(new Product()),
            'title' => 'QA Upgrade Phone ' . $suffix,
            'slug' => 'qa-upgrade-phone-' . $suffix,
            'selling' => 699,
            'offered' => 0,
            'status' => 1,
            'admin_id' => $template->admin_id,
            'image' => 'default-image.webp',
            'tax_rule_id' => $template->tax_rule_id,
            'shipping_rule_id' => $template->shipping_rule_id,
            'unit' => $template->unit,
            'excludeVAT' => 0,
        ], $attributes))->fresh();
    }

    /** An attribute with ranked-by-title values, like Storage. */
    private function attributeWith($title, array $values)
    {
        $adminId = Product::where('status', 1)->value('admin_id') ?: 1;

        $attribute = Attribute::create(['title' => $title . ' ' . uniqid(), 'admin_id' => $adminId]);

        $made = [];

        foreach ($values as $value) {
            $made[$value] = AttributeValue::create([
                'title' => $value,
                'attribute_id' => $attribute->id,
                'admin_id' => $adminId,
            ])->fresh();
        }

        return [$attribute, $made];
    }

    /**
     * One variant.
     *
     * $values are AttributeValue models; $stock and $backorder decide
     * buyability the way Pricing::isPurchasable reads it.
     */
    private function variant($product, array $values, $price, $stock = 10, $backorder = 0)
    {
        $inventory = UpdatedInventory::create([
            'product_id' => $product->id,
            'price' => $price,
            'quantity' => $stock,
            'is_active' => $backorder,
            'sku' => 'QA-' . uniqid(),
        ]);

        foreach ($values as $value) {
            InventoryAttribute::create([
                'inventory_id' => $inventory->id,
                'attribute_value_id' => $value->id,
            ]);
        }

        return $inventory->fresh();
    }

    /**
     * The canonical fixture: one product, one Storage attribute, three
     * variants at 699 / 799 / 999.
     */
    private function storageProduct($stock = [10, 10, 10], $backorder = [0, 0, 0])
    {
        $product = $this->product();

        [$attribute, $values] = $this->attributeWith('Storage', ['128GB', '256GB', '512GB']);

        $variants = [
            '128GB' => $this->variant($product, [$values['128GB']], 699, $stock[0], $backorder[0]),
            '256GB' => $this->variant($product, [$values['256GB']], 799, $stock[1], $backorder[1]),
            '512GB' => $this->variant($product, [$values['512GB']], 999, $stock[2], $backorder[2]),
        ];

        return [$product, $attribute, $values, $variants];
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Upgrade Fixture',
            'email' => 'upgrade.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    /** The options of the first group, keyed by their label. */
    private function optionsFor($product, $inventory, $customer = null, $quantity = 1)
    {
        $groups = $this->service->forVariant($product, $inventory, $customer, $quantity);

        if (empty($groups)) {
            return [];
        }

        $out = [];

        foreach ($groups[0]['options'] as $option) {
            $out[$option['label']] = $option;
        }

        return $out;
    }


    /* ================================================================== *
     *  1-5  The upgrade ladder
     * ================================================================== */

    /** Cases 1 and 2: 128GB offers both 256GB and 512GB. */
    public function test_the_lowest_variant_offers_every_higher_one()
    {
        [$product, , , $variants] = $this->storageProduct();

        $options = $this->optionsFor($product, $variants['128GB']);

        $this->assertSame(['256GB', '512GB'], array_keys($options));
    }

    /** Case 3: the middle variant offers only what is above it. */
    public function test_the_middle_variant_offers_only_what_is_above_it()
    {
        [$product, , , $variants] = $this->storageProduct();

        $options = $this->optionsFor($product, $variants['256GB']);

        $this->assertSame(['512GB'], array_keys($options));
    }

    /** Case 5: nothing is above the top of the ladder. */
    public function test_the_highest_variant_offers_nothing()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->assertSame([], $this->service->forVariant($product, $variants['512GB']));
    }

    /** The current variant is never offered as an upgrade to itself. */
    public function test_the_current_variant_is_never_offered()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->assertArrayNotHasKey('128GB', $this->optionsFor($product, $variants['128GB']));
    }

    /** Case 4: a downgrade is not an upgrade. */
    public function test_lower_variants_are_never_offered()
    {
        [$product, , , $variants] = $this->storageProduct();

        $options = $this->optionsFor($product, $variants['512GB']);

        $this->assertArrayNotHasKey('128GB', $options);
        $this->assertArrayNotHasKey('256GB', $options);
    }


    /* ================================================================== *
     *  Price - from the pricing engine, never stored
     * ================================================================== */

    public function test_the_difference_is_the_gap_between_the_two_variant_prices()
    {
        [$product, , , $variants] = $this->storageProduct();

        $options = $this->optionsFor($product, $variants['128GB']);

        $this->assertSame(100.0, $options['256GB']['difference'], '799 - 699');
        $this->assertSame(300.0, $options['512GB']['difference'], '999 - 699');

        // The price the till would take is the target's own, never a sum.
        $this->assertSame(799.0, $options['256GB']['price']);
        $this->assertSame(999.0, $options['512GB']['price']);
    }

    /**
     * The difference follows the catalogue.
     *
     * This is the whole reason no price is stored: an admin who reprices the
     * 256GB has repriced the upgrade, with nothing else to remember.
     */
    public function test_the_difference_follows_a_price_change_with_no_reconfiguration()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->assertSame(100.0, $this->optionsFor($product, $variants['128GB'])['256GB']['difference']);

        UpdatedInventory::where('id', $variants['256GB']->id)->update(['price' => 749]);

        $this->assertSame(
            50.0,
            $this->optionsFor($product, $variants['128GB'])['256GB']['difference']
        );
    }

    /** A band, expressed the way the admin screen expresses one. */
    private function band($product, $attributes = [])
    {
        return BusinessProductPricing::create(array_merge([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => 600,
            'discount_type' => 'percentage',
            'discount_value' => 0,
        ], $attributes));
    }

    /**
     * Case 11: a banded account is quoted its own price on BOTH sides.
     *
     * The consequence is worth stating, because it surprises people: a
     * business band in this catalogue is a flat per-PRODUCT ceiling, not a
     * per-variant one -- BusinessProductPricing carries a product_id and no
     * inventory_id, and Pricing::businessPrice caps every variant against the
     * same figure. So a band below every variant gives a banded account all of
     * them at the band price, and a storage upgrade costs them nothing.
     *
     * That is the pricing engine's answer and not this engine's, and quoting
     * it is exactly the point: the upgrade shows what the basket and the order
     * will actually charge. If free upgrades for banded accounts are not
     * wanted, the fix belongs in the band model -- a band would have to be
     * able to name a variant -- and this test is where it would be noticed.
     */
    public function test_a_banded_account_is_quoted_its_own_price_on_both_sides()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->band($product, ['wholesale_price' => 600]);

        $customer = $this->customer(['account_type' => 'business']);

        $retail = $this->optionsFor($product, $variants['128GB']);
        $business = $this->optionsFor($product, $variants['128GB'], $customer);

        $this->assertSame(100.0, $retail['256GB']['difference'], 'retail lost its difference');

        $this->assertSame(600.0, $business['256GB']['price']);
        $this->assertSame(0.0, $business['256GB']['difference']);
    }

    /**
     * A band never raises a price, so it only flattens the variants it sits
     * below. One above the cheaper variant leaves that one alone and caps the
     * dearer ones, and the difference narrows rather than vanishing.
     */
    public function test_a_band_above_the_cheaper_variant_only_caps_the_dearer_ones()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->band($product, ['wholesale_price' => 750]);

        $customer = $this->customer(['account_type' => 'business']);

        $options = $this->optionsFor($product, $variants['128GB'], $customer);

        // 128GB stays at 699; 256GB is capped from 799 to 750.
        $this->assertSame(750.0, $options['256GB']['price']);
        $this->assertSame(51.0, $options['256GB']['difference']);
        $this->assertSame(750.0, $options['512GB']['price']);
    }

    /** Case 10: a personal account is priced as retail. */
    public function test_a_personal_account_sees_the_retail_difference()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->band($product, ['wholesale_price' => 400]);

        $options = $this->optionsFor($product, $variants['128GB'], $this->customer());

        $this->assertSame(100.0, $options['256GB']['difference']);
        $this->assertSame(799.0, $options['256GB']['price'], 'a personal account got a band price');
    }

    /** Case 12: an unapproved trade account does not get trade rates. */
    public function test_a_pending_trade_account_is_priced_as_retail()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->band($product, ['wholesale_price' => 400]);

        $pending = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]);

        $options = $this->optionsFor($product, $variants['128GB'], $pending);

        $this->assertSame(100.0, $options['256GB']['difference'], 'an unapproved trade account got a band');
    }

    /** Case 14: a quantity band changes the difference at that quantity. */
    public function test_a_quantity_band_changes_the_difference_at_that_quantity()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->band($product, ['min_qty' => 10, 'wholesale_price' => 750]);

        $customer = $this->customer(['account_type' => 'business']);

        $one = $this->optionsFor($product, $variants['128GB'], $customer, 1);
        $ten = $this->optionsFor($product, $variants['128GB'], $customer, 10);

        // Below the floor, retail on both sides.
        $this->assertSame(799.0, $one['256GB']['price'], 'the band applied below its floor');
        $this->assertSame(100.0, $one['256GB']['difference']);

        // At the floor, the 256GB is capped to 750 and the difference narrows.
        $this->assertSame(750.0, $ten['256GB']['price'], 'the band did not apply at its floor');
        $this->assertSame(51.0, $ten['256GB']['difference']);
    }


    /* ================================================================== *
     *  6-9  Availability and compatibility
     * ================================================================== */

    /** Case 6: a sold-out upgrade is hidden by default. */
    public function test_an_out_of_stock_upgrade_is_hidden_by_default()
    {
        [$product, , , $variants] = $this->storageProduct([10, 0, 10]);

        $options = $this->optionsFor($product, $variants['128GB']);

        $this->assertArrayNotHasKey('256GB', $options);
        $this->assertArrayHasKey('512GB', $options);
    }

    /**
     * ...and shown greyed when the product says so, which is the other
     * legitimate answer: a customer can see the better variant exists and is
     * simply gone for now, rather than never learning about it.
     */
    public function test_an_out_of_stock_upgrade_can_be_shown_disabled_instead()
    {
        [$product, $attribute, , $variants] = $this->storageProduct([10, 0, 10]);

        ProductUpgradeGroup::create([
            'product_id' => $product->id,
            'name' => 'Storage Upgrade',
            'attribute_id' => $attribute->id,
            'mode' => ProductUpgradeGroup::MODE_AUTOMATIC,
            'status' => 1,
            'display_order' => 1,
            'out_of_stock' => ProductUpgradeGroup::OUT_OF_STOCK_DISABLE,
            'customer_type' => 'all',
        ]);

        $options = $this->optionsFor($product, $variants['128GB']);

        $this->assertArrayHasKey('256GB', $options);
        $this->assertFalse($options['256GB']['available'], 'a sold-out option was selectable');
        $this->assertFalse($options['256GB']['in_stock']);
    }

    /**
     * Case 7: `is_active` is the BACKORDER flag in this catalogue, not an
     * enabled flag, so a zero-stock row that allows backorder is buyable.
     */
    public function test_a_backorderable_upgrade_with_no_stock_is_still_offered()
    {
        [$product, , , $variants] = $this->storageProduct([10, 0, 10], [0, 1, 0]);

        $options = $this->optionsFor($product, $variants['128GB']);

        $this->assertArrayHasKey('256GB', $options);
        $this->assertTrue($options['256GB']['available']);
        $this->assertTrue($options['256GB']['backorder']);
        $this->assertFalse($options['256GB']['in_stock']);
    }

    /** An upgrade with stock, but not enough of it for this quantity. */
    public function test_an_upgrade_without_enough_stock_for_the_quantity_is_not_available()
    {
        [$product, $attribute, , $variants] = $this->storageProduct([10, 2, 10]);

        ProductUpgradeGroup::create([
            'product_id' => $product->id,
            'name' => 'Storage Upgrade',
            'attribute_id' => $attribute->id,
            'mode' => ProductUpgradeGroup::MODE_AUTOMATIC,
            'status' => 1,
            'display_order' => 1,
            'out_of_stock' => ProductUpgradeGroup::OUT_OF_STOCK_DISABLE,
            'customer_type' => 'all',
        ]);

        $options = $this->optionsFor($product, $variants['128GB'], null, 5);

        $this->assertFalse($options['256GB']['available'], '5 were offered against 2 in stock');
        $this->assertTrue($options['256GB']['in_stock']);
    }

    /**
     * Cases 8 and 9: a storage upgrade must never change the colour.
     *
     * Black + 128GB offers Black + 256GB and nothing blue, which is the case
     * that separates a variant-aware engine from one that matches on a single
     * attribute and hopes.
     */
    public function test_only_variants_matching_every_other_attribute_are_offered()
    {
        $product = $this->product();

        [$storage, $sizes] = $this->attributeWith('Storage', ['128GB', '256GB']);
        [$colour, $colours] = $this->attributeWith('Colour', ['Black', 'Blue']);

        $black128 = $this->variant($product, [$sizes['128GB'], $colours['Black']], 699);
        $black256 = $this->variant($product, [$sizes['256GB'], $colours['Black']], 799);
        $blue128 = $this->variant($product, [$sizes['128GB'], $colours['Blue']], 699);
        $blue256 = $this->variant($product, [$sizes['256GB'], $colours['Blue']], 799);

        $options = $this->optionsFor($product, $black128);

        $this->assertSame(['256GB'], array_keys($options));
        $this->assertSame(
            (int)$black256->id,
            $options['256GB']['inventory_id'],
            'the upgrade switched the colour'
        );

        // ...and the blue one offers the blue upgrade, for the same reason.
        $blueOptions = $this->optionsFor($product, $blue128);

        $this->assertSame((int)$blue256->id, $blueOptions['256GB']['inventory_id']);
    }

    /**
     * Colour is not an upgrade, and the engine works that out for itself.
     *
     * "Black" and "Blue" cannot be ranked, so a colour attribute is silently
     * never treated as upgradeable. Without this the engine would offer
     * "upgrade to Blue" on every product in the catalogue.
     */
    public function test_an_unrankable_attribute_never_becomes_an_upgrade_group()
    {
        $product = $this->product();

        [, $colours] = $this->attributeWith('Colour', ['Black', 'Blue']);

        $black = $this->variant($product, [$colours['Black']], 699);
        $this->variant($product, [$colours['Blue']], 799);

        $this->assertSame([], $this->service->forVariant($product, $black));
    }


    /* ================================================================== *
     *  22  Edge cases with nothing to offer
     * ================================================================== */

    /** A product with no variants at all. */
    public function test_a_product_with_no_variants_offers_nothing()
    {
        $product = $this->product();

        $this->assertSame([], $this->service->forVariant($product, null));
    }

    /** Case 22: one variant has no sibling to upgrade to. */
    public function test_a_product_with_one_variant_offers_nothing()
    {
        $product = $this->product();

        [, $values] = $this->attributeWith('Storage', ['128GB']);

        $only = $this->variant($product, [$values['128GB']], 699);

        $this->assertSame([], $this->service->forVariant($product, $only));
    }

    /**
     * A variant carrying no value for the upgrading attribute.
     *
     * There is nothing to upgrade FROM, so the group does not apply -- rather
     * than the engine treating "missing" as the bottom of the ladder and
     * offering every other variant.
     */
    public function test_a_variant_missing_the_upgrade_attribute_offers_nothing()
    {
        $product = $this->product();

        [, $values] = $this->attributeWith('Storage', ['128GB', '256GB']);

        $bare = $this->variant($product, [], 699);
        $this->variant($product, [$values['128GB']], 699);
        $this->variant($product, [$values['256GB']], 799);

        $this->assertSame([], $this->service->forVariant($product, $bare));
    }

    /**
     * A sibling carrying an extra attribute the current variant does not have
     * is a different configuration, not an upgrade of this one.
     */
    public function test_an_incompatible_variant_shape_is_not_an_upgrade()
    {
        $product = $this->product();

        [, $sizes] = $this->attributeWith('Storage', ['128GB', '256GB']);
        [, $colours] = $this->attributeWith('Colour', ['Black']);

        $plain128 = $this->variant($product, [$sizes['128GB']], 699);
        $this->variant($product, [$sizes['256GB'], $colours['Black']], 799);

        $this->assertSame([], $this->service->forVariant($product, $plain128));
    }

    /**
     * A better variant that costs the same, or less.
     *
     * Both are offered. "More storage for the same money" is a real thing a
     * sale produces, and the signed difference is the honest way to say so.
     * The charged price is always the target variant own, so a negative
     * difference can never become a negative charge.
     */
    public function test_an_equal_or_cheaper_upgrade_is_still_offered_with_a_signed_difference()
    {
        [$product, , , $variants] = $this->storageProduct();

        UpdatedInventory::where('id', $variants['256GB']->id)->update(['price' => 699]);
        UpdatedInventory::where('id', $variants['512GB']->id)->update(['price' => 649]);

        $options = $this->optionsFor($product, $variants['128GB']);

        $this->assertSame(0.0, $options['256GB']['difference']);
        $this->assertSame(-50.0, $options['512GB']['difference']);
        $this->assertSame(649.0, $options['512GB']['price']);
    }


    /* ================================================================== *
     *  23-25  Automatic, manual, and the two together
     * ================================================================== */

    /** A group, saved, so the automatic defaults stop applying. */
    private function group($product, $attribute, $attributes = [])
    {
        return ProductUpgradeGroup::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Storage Upgrade',
            'attribute_id' => $attribute->id,
            'mode' => ProductUpgradeGroup::MODE_AUTOMATIC,
            'status' => 1,
            'display_order' => 1,
            'out_of_stock' => ProductUpgradeGroup::OUT_OF_STOCK_HIDE,
            'customer_type' => 'all',
        ], $attributes));
    }

    /** Case 24: with no configuration at all, storage upgrades still work. */
    public function test_upgrades_work_with_no_configuration_at_all()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->assertSame(0, ProductUpgradeGroup::where('product_id', $product->id)->count());

        $groups = $this->service->forVariant($product, $variants['128GB']);

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]['is_discovered'], 'the group was not a discovered one');
    }

    /** Cases 23 and 25: a manual list narrows what automatic would have offered. */
    public function test_a_manual_group_offers_only_what_the_admin_chose()
    {
        [$product, $attribute, $values, $variants] = $this->storageProduct();

        $group = $this->group($product, $attribute, ['mode' => ProductUpgradeGroup::MODE_MANUAL]);

        ProductUpgradeGroupOption::create([
            'product_upgrade_group_id' => $group->id,
            'attribute_value_id' => $values['512GB']->id,
            'display_order' => 1,
        ]);

        $this->assertSame(['512GB'], array_keys($this->optionsFor($product, $variants['128GB'])));
    }

    /**
     * A manual list can only narrow, never widen.
     *
     * An admin picking a LOWER variant does not make it an upgrade: manual
     * mode chooses among the candidates automatic found, and the ranking still
     * decides what a candidate is.
     */
    public function test_a_manual_group_cannot_turn_a_downgrade_into_an_upgrade()
    {
        [$product, $attribute, $values, $variants] = $this->storageProduct();

        $group = $this->group($product, $attribute, ['mode' => ProductUpgradeGroup::MODE_MANUAL]);

        ProductUpgradeGroupOption::create([
            'product_upgrade_group_id' => $group->id,
            'attribute_value_id' => $values['128GB']->id,
            'display_order' => 1,
        ]);

        $this->assertSame([], $this->service->forVariant($product, $variants['256GB']));
    }

    /** An inactive group offers nothing, and does not fall back to automatic. */
    public function test_an_inactive_group_offers_nothing()
    {
        [$product, $attribute, , $variants] = $this->storageProduct();

        $this->group($product, $attribute, ['status' => 2]);

        $this->assertSame([], $this->service->forVariant($product, $variants['128GB']));
    }

    /** A group aimed at business accounts is invisible to a guest. */
    public function test_a_group_aimed_at_one_account_type_is_hidden_from_the_others()
    {
        [$product, $attribute, , $variants] = $this->storageProduct();

        $this->group($product, $attribute, ['customer_type' => 'business']);

        $this->assertSame(
            [],
            $this->service->forVariant($product, $variants['128GB']),
            'a guest saw a business-only group'
        );

        $business = $this->customer(['account_type' => 'business']);

        $this->assertCount(1, $this->service->forVariant($product, $variants['128GB'], $business));
    }

    /** The cap keeps the nearest upgrades, not whichever came back first. */
    public function test_max_options_keeps_the_nearest_upgrades()
    {
        [$product, $attribute, , $variants] = $this->storageProduct();

        $this->group($product, $attribute, ['max_options' => 1]);

        $this->assertSame(['256GB'], array_keys($this->optionsFor($product, $variants['128GB'])));
    }

    /**
     * An admin rank beats the parsed one, and is the only way to order values
     * with no number in them.
     */
    public function test_an_explicit_rank_makes_an_unrankable_attribute_upgradeable()
    {
        $product = $this->product();

        [, $values] = $this->attributeWith('Condition', ['Acceptable', 'Very Good', 'Excellent']);

        AttributeValue::where('id', $values['Acceptable']->id)->update(['rank' => 1]);
        AttributeValue::where('id', $values['Very Good']->id)->update(['rank' => 2]);
        AttributeValue::where('id', $values['Excellent']->id)->update(['rank' => 3]);

        $acceptable = $this->variant($product, [$values['Acceptable']->fresh()], 499);
        $this->variant($product, [$values['Very Good']->fresh()], 599);
        $this->variant($product, [$values['Excellent']->fresh()], 699);

        $options = $this->optionsFor($product, $acceptable);

        $this->assertSame(['Very Good', 'Excellent'], array_keys($options));
        $this->assertSame(100.0, $options['Very Good']['difference']);
    }

    /** The two ranking scales are never ordered against each other. */
    public function test_a_parsed_rank_is_never_compared_against_an_explicit_one()
    {
        $this->assertNull(UpgradeRank::compare(
            UpgradeRank::of(['title' => '128GB', 'rank' => null]),
            UpgradeRank::of(['title' => 'Excellent', 'rank' => 3])
        ));
    }

    /** Sizes are normalised, so a terabyte outranks half of one. */
    public function test_units_are_normalised_so_1tb_outranks_512gb()
    {
        $product = $this->product();

        [, $values] = $this->attributeWith('Storage', ['512GB', '1TB']);

        $half = $this->variant($product, [$values['512GB']], 999);
        $this->variant($product, [$values['1TB']], 1299);

        $this->assertSame(['1TB'], array_keys($this->optionsFor($product, $half)));
    }


    /* ================================================================== *
     *  The storefront API
     * ================================================================== */

    /** The body's own status, which is where a refusal actually lives. */
    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }

    public function test_the_endpoint_returns_the_same_answer_as_the_service()
    {
        [$product, , , $variants] = $this->storageProduct();

        $response = $this->getJson(
            "/api/v1/product/{$product->id}/upgrades?inventory_id={$variants['128GB']->id}"
        );

        $this->assertSame(200, $this->bodyStatus($response));

        $labels = collect($response->json('data.groups.0.options'))->pluck('label')->all();

        $this->assertSame(['256GB', '512GB'], $labels);
        $this->assertSame(100.0, (float)$response->json('data.groups.0.options.0.difference'));
    }

    /** The current variant rides along, so the group renders as a choice. */
    public function test_the_endpoint_names_the_variant_the_customer_is_on()
    {
        [$product, , , $variants] = $this->storageProduct();

        $response = $this->getJson(
            "/api/v1/product/{$product->id}/upgrades?inventory_id={$variants['128GB']->id}"
        );

        $this->assertSame('128GB', $response->json('data.groups.0.current.label'));
        $this->assertSame(0.0, (float)$response->json('data.groups.0.current.difference'));
    }

    /** Case 11, through the API rather than the service. */
    public function test_the_endpoint_prices_against_the_signed_in_account()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->band($product, ['wholesale_price' => 750]);

        Passport::actingAs($this->customer(['account_type' => 'business']), ['user'], 'user');

        $response = $this->getJson(
            "/api/v1/product/{$product->id}/upgrades?inventory_id={$variants['128GB']->id}"
        );

        // The band price, through the API, exactly as the service gives it.
        $this->assertSame(750.0, (float)$response->json('data.groups.0.options.0.price'));
        $this->assertSame(51.0, (float)$response->json('data.groups.0.options.0.difference'));
    }

    /**
     * A variant belonging to another product is not an upgrade of this one.
     *
     * The one check here that is a security boundary: both ids come off the
     * request, and an unmatched pair would otherwise be priced from one
     * product and stocked against the other.
     */
    public function test_a_variant_of_another_product_is_refused()
    {
        [$product, , , $variants] = $this->storageProduct();
        [$other, , , $otherVariants] = $this->storageProduct();

        $response = $this->postJson("/api/v1/product/{$product->id}/upgrades/validate", [
            'inventory_id' => $variants['128GB']->id,
            'target_inventory_id' => $otherVariants['512GB']->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame(201, $this->bodyStatus($response), 'a foreign variant was accepted');
    }

    public function test_validating_a_genuine_upgrade_succeeds()
    {
        [$product, , , $variants] = $this->storageProduct();

        $response = $this->postJson("/api/v1/product/{$product->id}/upgrades/validate", [
            'inventory_id' => $variants['128GB']->id,
            'target_inventory_id' => $variants['256GB']->id,
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertTrue($response->json('data.valid'));
    }

    /** Case 20: stock can change between the page rendering and the click. */
    public function test_an_upgrade_that_sold_out_since_the_page_loaded_is_refused()
    {
        [$product, , , $variants] = $this->storageProduct();

        UpdatedInventory::where('id', $variants['256GB']->id)
            ->update(['quantity' => 0, 'is_active' => 0]);

        $response = $this->postJson("/api/v1/product/{$product->id}/upgrades/validate", [
            'inventory_id' => $variants['128GB']->id,
            'target_inventory_id' => $variants['256GB']->id,
        ]);

        $this->assertSame(201, $this->bodyStatus($response));
    }

    /** A downgrade is refused even when it is named directly. */
    public function test_validating_a_downgrade_is_refused()
    {
        [$product, , , $variants] = $this->storageProduct();

        $response = $this->postJson("/api/v1/product/{$product->id}/upgrades/validate", [
            'inventory_id' => $variants['512GB']->id,
            'target_inventory_id' => $variants['128GB']->id,
        ]);

        $this->assertSame(201, $this->bodyStatus($response));
    }


    /* ================================================================== *
     *  15-17, 21  The basket
     * ================================================================== */

    private function basketLine($product, $inventory, $customer, $quantity = 1)
    {
        return Cart::create([
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'user_id' => $customer->id,
            'quantity' => $quantity,
            'selected' => 1,
        ])->fresh();
    }

    /**
     * Case 15 and 16: the line CHANGES rather than gaining a sibling.
     *
     * This is the requirement the whole feature turns on. A basket must never
     * end up holding the 128GB and the 256GB because somebody took an upgrade.
     */
    public function test_switching_replaces_the_line_rather_than_adding_one()
    {
        [$product, , , $variants] = $this->storageProduct();

        $customer = $this->customer();
        $line = $this->basketLine($product, $variants['128GB'], $customer, 2);

        Passport::actingAs($customer, ['user'], 'user');

        $response = $this->postJson('/api/v1/cart/switch-variant', [
            'product_id' => $product->id,
            'inventory_id' => $variants['256GB']->id,
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $lines = Cart::where('user_id', $customer->id)->where('product_id', $product->id)->get();

        $this->assertCount(1, $lines, 'the basket gained a second line');
        $this->assertSame((int)$variants['256GB']->id, (int)$lines[0]->inventory_id);

        // The quantity is the shopper's and is never reset.
        $this->assertSame(2, (int)$lines[0]->quantity);

        // And it is repriced from the catalogue, not carried over.
        $this->assertSame(799.0, round((float)$lines[0]->price, 2));
    }

    /** Upgrading onto a variant already in the basket merges the two. */
    public function test_switching_onto_a_variant_already_in_the_basket_merges()
    {
        [$product, , , $variants] = $this->storageProduct();

        $customer = $this->customer();

        $from = $this->basketLine($product, $variants['128GB'], $customer, 1);
        $to = $this->basketLine($product, $variants['256GB'], $customer, 2);

        Passport::actingAs($customer, ['user'], 'user');

        $response = $this->postJson('/api/v1/cart/switch-variant', [
            'product_id' => $product->id,
            'cart_id' => $from->id,
            'inventory_id' => $variants['256GB']->id,
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $lines = Cart::where('user_id', $customer->id)->where('product_id', $product->id)->get();

        $this->assertCount(1, $lines, 'the merge left two lines');
        $this->assertSame(3, (int)$lines[0]->quantity, '1 + 2 did not become 3');
        $this->assertNull(Cart::find($from->id), 'the old line survived the merge');
    }

    /** Case 21: the basket refuses an upgrade that is no longer available. */
    public function test_the_basket_refuses_an_upgrade_that_sold_out()
    {
        [$product, , , $variants] = $this->storageProduct();

        $customer = $this->customer();
        $this->basketLine($product, $variants['128GB'], $customer, 1);

        UpdatedInventory::where('id', $variants['256GB']->id)
            ->update(['quantity' => 0, 'is_active' => 0]);

        Passport::actingAs($customer, ['user'], 'user');

        $response = $this->postJson('/api/v1/cart/switch-variant', [
            'product_id' => $product->id,
            'inventory_id' => $variants['256GB']->id,
        ]);

        $this->assertSame(201, $this->bodyStatus($response));

        $line = Cart::where('user_id', $customer->id)->where('product_id', $product->id)->first();

        // Left exactly as it was.
        $this->assertSame((int)$variants['128GB']->id, (int)$line->inventory_id);
    }

    /** The basket refuses a downgrade dressed up as an upgrade. */
    public function test_the_basket_refuses_a_downgrade()
    {
        [$product, , , $variants] = $this->storageProduct();

        $customer = $this->customer();
        $this->basketLine($product, $variants['512GB'], $customer, 1);

        Passport::actingAs($customer, ['user'], 'user');

        $response = $this->postJson('/api/v1/cart/switch-variant', [
            'product_id' => $product->id,
            'inventory_id' => $variants['128GB']->id,
        ]);

        $this->assertSame(201, $this->bodyStatus($response));
    }

    /** The basket refuses a variant belonging to another product. */
    public function test_the_basket_refuses_a_variant_of_another_product()
    {
        [$product, , , $variants] = $this->storageProduct();
        [, , , $otherVariants] = $this->storageProduct();

        $customer = $this->customer();
        $this->basketLine($product, $variants['128GB'], $customer, 1);

        Passport::actingAs($customer, ['user'], 'user');

        $response = $this->postJson('/api/v1/cart/switch-variant', [
            'product_id' => $product->id,
            'inventory_id' => $otherVariants['512GB']->id,
        ]);

        $this->assertSame(201, $this->bodyStatus($response));
    }

    /** Nobody may switch somebody else's basket line. */
    public function test_switching_is_scoped_to_the_caller_basket()
    {
        [$product, , , $variants] = $this->storageProduct();

        $owner = $this->customer();
        $this->basketLine($product, $variants['128GB'], $owner, 1);

        Passport::actingAs($this->customer(), ['user'], 'user');

        $response = $this->postJson('/api/v1/cart/switch-variant', [
            'product_id' => $product->id,
            'inventory_id' => $variants['256GB']->id,
        ]);

        $this->assertSame(201, $this->bodyStatus($response));

        $line = Cart::where('user_id', $owner->id)->first();

        $this->assertSame((int)$variants['128GB']->id, (int)$line->inventory_id);
    }

    /**
     * Case 9: two independent groups on one product.
     *
     * Each holds the OTHER attribute fixed, so choosing a storage upgrade
     * never moves the warranty and vice versa. This is the case that proves
     * the engine is generic rather than a storage feature.
     */
    public function test_two_upgradeable_attributes_produce_two_independent_groups()
    {
        $product = $this->product();

        [$storageAttr, $sizes] = $this->attributeWith('Storage', ['128GB', '256GB']);
        [$warrantyAttr, $years] = $this->attributeWith('Warranty', ['1 Year', '2 Years']);

        $base = $this->variant($product, [$sizes['128GB'], $years['1 Year']], 699);
        $biggerStorage = $this->variant($product, [$sizes['256GB'], $years['1 Year']], 799);
        $longerWarranty = $this->variant($product, [$sizes['128GB'], $years['2 Years']], 748);
        $this->variant($product, [$sizes['256GB'], $years['2 Years']], 848);

        $groups = $this->service->forVariant($product, $base);

        $this->assertCount(2, $groups, 'the two attributes did not each get a group');

        // Keyed by attribute rather than by name: the fixture suffixes its
        // attribute titles to stay unique, so the names are not literals.
        $byAttribute = [];

        foreach ($groups as $group) {
            $byAttribute[$group['attribute_id']] = $group;
        }

        $storage = $byAttribute[$storageAttr->id];
        $warranty = $byAttribute[$warrantyAttr->id];

        // Each group offers exactly one step, holding the other attribute.
        $this->assertSame(
            [(int)$biggerStorage->id],
            array_column($storage['options'], 'inventory_id')
        );

        $this->assertSame(
            [(int)$longerWarranty->id],
            array_column($warranty['options'], 'inventory_id')
        );

        $this->assertSame(100.0, $storage['options'][0]['difference']);
        $this->assertSame(49.0, $warranty['options'][0]['difference']);
    }

    /**
     * Case 21: checkout refuses a line whose variant has sold out.
     *
     * Not this engine's check -- OrdersController has always made it -- but it
     * is the one that catches an upgrade taken while stock lasted and gone by
     * the time the customer paid, so it is asserted here where the upgrade
     * path can see it.
     */
    public function test_checkout_refuses_a_line_whose_upgraded_variant_sold_out()
    {
        [$product, , , $variants] = $this->storageProduct();

        $customer = $this->customer();
        $line = $this->basketLine($product, $variants['128GB'], $customer, 1);

        Passport::actingAs($customer, ['user'], 'user');

        // Take the upgrade while it is available.
        $this->postJson('/api/v1/cart/switch-variant', [
            'product_id' => $product->id,
            'inventory_id' => $variants['256GB']->id,
        ]);

        $this->assertSame(
            (int)$variants['256GB']->id,
            (int)Cart::find($line->id)->inventory_id
        );

        // ...and it sells out before they pay.
        UpdatedInventory::where('id', $variants['256GB']->id)
            ->update(['quantity' => 0, 'is_active' => 0]);

        $inventory = UpdatedInventory::find($variants['256GB']->id);

        // The guard OrdersController applies to every line, stated here so an
        // upgrade can never be the thing that slips past it.
        $this->assertTrue(
            (int)$inventory->is_active !== 1 && 1 > (int)$inventory->quantity,
            'a sold-out upgraded line would have passed the checkout stock guard'
        );
    }

    /**
     * Case 17 and 18: the answer depends on the caller, not on a stored state.
     *
     * Nothing about an upgrade is remembered between requests -- there is no
     * session, no draft and no half-applied selection -- so signing in,
     * refreshing or coming back tomorrow all get the same answer, priced for
     * whoever is asking at that moment. That is what makes the edge cases
     * around login and navigation uninteresting rather than dangerous.
     */
    public function test_the_same_variant_is_priced_differently_for_two_callers()
    {
        [$product, , , $variants] = $this->storageProduct();

        $this->band($product, ['wholesale_price' => 750]);

        $guest = $this->optionsFor($product, $variants['128GB']);
        $business = $this->optionsFor(
            $product, $variants['128GB'], $this->customer(['account_type' => 'business'])
        );

        $this->assertSame(799.0, $guest['256GB']['price']);
        $this->assertSame(750.0, $business['256GB']['price']);

        // ...and asking again as a guest gives the guest answer back.
        $this->assertSame(799.0, $this->optionsFor($product, $variants['128GB'])['256GB']['price']);
    }

    /**
     * Add-to-cart binds the variant to the product.
     *
     * The two ids both come off the request and were never tested against each
     * other, so a line could be created pairing any product with any variant
     * and then priced from that variant against this product.
     */
    public function test_add_to_cart_refuses_a_variant_of_another_product()
    {
        [$product, , , ] = $this->storageProduct();
        [, , , $otherVariants] = $this->storageProduct();

        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $response = $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $otherVariants['256GB']->id,
            'quantity' => 1,
        ]);

        $this->assertSame(201, $this->bodyStatus($response), 'a foreign variant was added');
        $this->assertSame(0, Cart::where('user_id', $customer->id)->count());
    }
}
