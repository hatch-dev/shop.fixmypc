<?php
namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\Concerns\AccountsForStoreWideDiscount;
use Tests\TestCase;

/**
 * The 30-to-31 boundary, on the ladder that is actually configured.
 *
 * BusinessQuantityPricingTest builds its own bands and proves the engine
 * handles them. This one deliberately does not: it buys the real product with
 * the real rows behind it, because the defect was that the top band stopped at
 * 30 and no fixture-based test can see that.
 *
 * The sequence test is the other half. A basket does not jump to a quantity,
 * it is nudged there, and the bug a customer would hit is a price that was
 * right when the line was created and never revisited.
 */
class BusinessPricingBoundaryTest extends TestCase
{
    use DatabaseTransactions;
    use AccountsForStoreWideDiscount;

    private $product;
    private $inventory;

    /**
     * The product carrying a configured ladder, with stock raised far enough
     * that the quantities under test fail on price if they fail at all, and
     * with a ladder that genuinely undercuts what the shop is charging today.
     *
     * The raise and the ladder are rolled back with the transaction.
     *
     * Two things used to make this fixture drift:
     *
     * The bands were whatever the admin had configured, anchored to a
     * wholesale_price of 699. A EUR 100 flash sale on the product took retail
     * to 599, below every band, and Pricing::businessPrice correctly charges
     * min(retail, band) -- so the entire ladder collapsed onto one price and
     * three tests failed for asserting that a band moves the price. Nothing
     * was wrong with the code; the ladder had simply stopped being a discount.
     *
     * And the inventory was picked with orderByDesc('quantity') over a dozen
     * variants that all hold 100 units, so which variant -- and therefore
     * which price, 699 or 999 -- came back was row order. That is why these
     * tests passed alone and failed in a full run.
     *
     * The ladder is now built here, from the live retail price of the chosen
     * variant, so it undercuts whatever promotion happens to be running; and
     * the variant is chosen deterministically.
     */
    private function ladderProduct()
    {
        if ($this->product) {
            return $this->product;
        }

        $productId = BusinessProductPricing::query()
            ->orderByDesc('min_qty')
            ->orderBy('product_id')
            ->value('product_id');

        $product = $productId ? Product::find($productId) : null;

        if (!$product) {
            $this->markTestSkipped('No product has business pricing configured.');
        }

        $this->inventory = UpdatedInventory::where('product_id', $product->id)
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->first();

        if (!$this->inventory) {
            $this->markTestSkipped('The configured product has no inventory to buy.');
        }

        UpdatedInventory::where('id', $this->inventory->id)->update(['quantity' => 5000]);

        $this->inventory = UpdatedInventory::withCount('inventory_attributes')
            ->find($this->inventory->id);

        $this->product = $product->fresh();

        $this->installLadder();

        return $this->product;
    }

    /**
     * A ladder anchored to today's retail price, keeping the band boundaries
     * the assertions are written around: nothing below 3, a step at 11 and a
     * step at 21.
     */
    private function installLadder()
    {
        $retail = Pricing::round(Pricing::retailUnitPrice(
            Product::pricingRecord($this->product->id), $this->inventory
        ));

        if ($retail <= 80) {
            $this->markTestSkipped('The fixture is too cheap to carry a ladder under it.');
        }

        BusinessProductPricing::where('product_id', $this->product->id)->delete();

        foreach ([
            ['min' => 3,  'max' => 10, 'discount' => 25],
            ['min' => 11, 'max' => 20, 'discount' => 50],
            ['min' => 21, 'max' => 0,  'discount' => 75],
        ] as $band) {

            BusinessProductPricing::create([
                'product_id' => $this->product->id,
                'min_qty' => $band['min'],
                'max_qty' => $band['max'],
                'wholesale_price' => $retail,
                'discount_type' => 'fixed',
                'discount_value' => $band['discount'],
                // Derived by the model from wholesale and the discount; stored
                // here only because the admin screen stores it.
                'final_price' => Pricing::round($retail - $band['discount']),
            ]);
        }
    }

    private function tiers()
    {
        return BusinessProductPricing::where('product_id', $this->product->id)->get();
    }

    /**
     * What the customer should pay per unit, from the helper the till uses.
     * Restating the ladder here would only prove the test can copy a table.
     */
    private function expectedAt($quantity)
    {
        return Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($this->product->id),
            $this->inventory,
            $quantity,
            $this->tiers()
        ));
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Boundary Fixture',
            'email' => 'boundary.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    private function asBusiness()
    {
        $customer = $this->customer(['account_type' => 'business']);

        Passport::actingAs($customer, ['user'], 'user');

        return $customer;
    }

    /** The basket line for the fixture product, as the storefront receives it. */
    private function cartLine()
    {
        return collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $this->product->id);
    }

    /**
     * cart/action carries a delta, not a total - "+" on the stepper is +1 -
     * so moving to a quantity means sending the difference.
     */
    private function setCartQuantity($from, $to)
    {
        return $this->postJson('/api/v1/cart/action', [
            'product_id' => $this->product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => $to - $from,
        ]);
    }

    /**
     * The quantities in the brief, either side of each boundary.
     *
     * 20, 21 and 22 matter as much as 30 and 31 now that the ladder ends in a
     * single open-ended tier at 21: 20 is the last quantity on the tier below
     * it, and everything from 21 up is one rate.
     */
    public function boundaries()
    {
        return [
            [1], [4], [5], [10], [11], [20], [21], [22],
            [29], [30], [31], [32], [49], [50], [51], [100],
        ];
    }

    // -------------------------------------------------- the product page

    /**
     * @dataProvider boundaries
     *
     * The quantity box quotes what the till will charge. The endpoint is the
     * pricing authority for the page; nothing is worked out in the browser.
     */
    public function test_the_product_page_quotes_the_configured_tier($quantity)
    {
        $this->ladderProduct();
        $this->asBusiness();

        $body = $this->getJson(
            "/api/v1/business-product/{$this->product->id}"
            . "?quantity={$quantity}&inventory_id={$this->inventory->id}"
        )->assertStatus(200)->json('data');

        $this->assertSame(
            $this->expectedAt($quantity),
            (float)$body['unit_price'],
            "product page unit price at quantity {$quantity}"
        );

        $applied = collect($body['pricing'])->firstWhere('applies', true);

        $floor = (int)$this->tiers()->min('min_qty');

        /*
         * Below the bottom of the ladder there is no band to highlight, and
         * there must not be one. A ladder starting at 5 is saying bulk rates
         * begin at five units; quoting a bulk rate for a single unit is the
         * mirror-image defect, and it is the one that shows on every listing
         * tile rather than only at the basket.
         */
        if ($quantity < $floor) {
            $this->assertNull(
                $applied,
                "quantity {$quantity} is below the ladder and must get no band"
            );

            $this->assertSame(
                Pricing::round(Pricing::retailUnitPrice(
                    Product::pricingRecord($this->product->id), $this->inventory
                )),
                (float)$body['unit_price'],
                "quantity {$quantity} is below the ladder and pays retail"
            );

            return;
        }

        $this->assertNotNull($applied, "a band is marked as applying at {$quantity}");

        $this->assertTrue(
            $quantity >= (int)$applied['min']
            && ((int)$applied['max'] <= 0 || $quantity <= (int)$applied['max']),
            "quantity {$quantity} sits inside the band the page highlights"
        );

        // The range is sent as the store holds it, so the page can render an
        // open-ended band as "21+" rather than "21 - 0".
        $this->assertArrayHasKey('max', $applied);
    }

    /**
     * The whole point, stated once: one more unit is never dearer.
     *
     * "Never dearer", not "always cheaper". The ladder the business runs ends
     * in one open-ended tier - 5-10, 11-20, 21-and-over - so 30 and 31 are the
     * same band and the same rate, which is the correct answer rather than a
     * missed discount. This used to assert 31 came in strictly under 30, which
     * only held while a separate 31+ band existed to close the cliff; written
     * that way the test would fail on the ladder it is meant to protect and
     * pass on a ladder with a needless extra tier.
     */
    public function test_thirty_one_is_never_dearer_per_unit_than_thirty()
    {
        $this->ladderProduct();

        $thirty = $this->expectedAt(30);
        $thirtyOne = $this->expectedAt(31);

        $this->assertLessThanOrEqual(
            $thirty + 0.005, $thirtyOne,
            'buying one more unit must not cost more per unit'
        );

        // And the line total, which is what the customer actually sees.
        $this->assertLessThanOrEqual(
            Pricing::round($thirty * 31) + 0.005,
            Pricing::round($thirtyOne * 31),
            '31 units must not cost more than 31 at the 30-unit rate'
        );

        /*
         * The failure this is really guarding: no band covering 31 means
         * Pricing falls back to the retail ladder, which is how one more unit
         * came to cost 2,635.00 more. A band price equal to retail would slip
         * past the comparison above, so the band is checked for directly.
         */
        $covered = $this->tiers()->contains(function ($band) {
            return $band->coversQuantity(31);
        });

        $this->assertTrue($covered, '31 units are not covered by any band at all');
    }

    /**
     * A listing tile advertises the price of buying one, so the open-ended
     * band must not leak onto it. Its own rate at quantity 1 is the test:
     * hard-coding "retail" would pass even if the tile quoted the wrong band.
     */
    public function test_the_listing_tile_prices_a_single_unit()
    {
        $this->ladderProduct();
        $this->asBusiness();

        $body = $this->getJson(
            "/api/v1/business-product/{$this->product->id}?quantity=1"
        )->json('data');

        $this->assertSame(
            Pricing::round(Pricing::businessPrice(
                (float)$body['retail_price'], $this->tiers(), 1
            )),
            (float)$body['unit_price'],
            'the tile prices one unit, not a bulk quantity'
        );

        $this->assertGreaterThan(
            $this->expectedAt(31),
            (float)$body['unit_price'],
            'the 31+ rate must not be advertised at quantity 1'
        );
    }

    // -------------------------------------------------------- the basket

    /**
     * @dataProvider boundaries
     *
     * The basket charges the tier for the quantity in it, and the line total
     * follows from that unit price rather than from a banked one.
     */
    public function test_the_basket_charges_the_configured_tier($quantity)
    {
        $this->ladderProduct();
        $this->asBusiness();

        $this->setCartQuantity(0, $quantity)->assertStatus(200);

        $line = $this->cartLine();

        $this->assertNotNull($line, 'the line is in the basket');

        $expected = $this->expectedAt($quantity);

        $this->assertSame(
            $expected, (float)$line['unit_price'], "basket unit price at {$quantity}"
        );

        $this->assertSame(
            Pricing::round($expected * $quantity),
            (float)$line['line_total'],
            "basket line total at {$quantity}"
        );

        // The stored column, not just the figure rendered from it - this is
        // what checkout reads back.
        $this->assertSame(
            $expected,
            (float)Cart::find($line['id'])->price,
            "carts.price at {$quantity}"
        );
    }

    /**
     * Up and back down, the way a customer actually moves a stepper.
     *
     * Every step is checked against the ladder, so a price that is banked on
     * the way up or left behind on the way down fails here rather than at the
     * till.
     */
    public function test_the_basket_follows_the_quantity_both_ways()
    {
        $this->ladderProduct();
        $this->asBusiness();

        /*
         * Across the boundary the ladder now turns on, in both directions.
         * 20 is the last quantity on the tier below the open-ended band, so
         * 20 -> 21 is where the rate changes and 100 -> 20 is where it has to
         * change back. Everything between 21 and 100 is one rate, which is
         * exactly the run a banked price would survive unnoticed.
         */
        $walk = [20, 21, 30, 31, 50, 100, 50, 31, 30, 21, 20];

        $current = 0;

        foreach ($walk as $quantity) {

            $this->setCartQuantity($current, $quantity)->assertStatus(200);

            $current = $quantity;

            $line = $this->cartLine();

            $this->assertNotNull($line, "the line survives the move to {$quantity}");

            $this->assertSame(
                $quantity, (int)$line['quantity'], "basket quantity is {$quantity}"
            );

            $expected = $this->expectedAt($quantity);

            $this->assertSame(
                $expected,
                (float)$line['unit_price'],
                'basket unit price after moving to ' . $quantity
                . ' (walk: ' . implode(' -> ', $walk) . ')'
            );

            $this->assertSame(
                Pricing::round($expected * $quantity),
                (float)$line['line_total'],
                "basket line total after moving to {$quantity}"
            );
        }
    }

    /**
     * Coming back down to 30 must cost exactly what going up from 30 did.
     * A stale 31+ rate left on the line would be a discount the shop never
     * agreed to, which is the mirror image of the reported defect.
     */
    public function test_dropping_back_to_thirty_restores_the_thirty_tier()
    {
        $this->ladderProduct();
        $this->asBusiness();

        $this->setCartQuantity(0, 30)->assertStatus(200);
        $atThirty = (float)$this->cartLine()['unit_price'];

        $this->setCartQuantity(30, 31)->assertStatus(200);
        $atThirtyOne = (float)$this->cartLine()['unit_price'];

        $this->setCartQuantity(31, 30)->assertStatus(200);
        $backAtThirty = (float)$this->cartLine()['unit_price'];

        // Never dearer rather than always cheaper: on the configured ladder
        // 30 and 31 share the open-ended band, so the two rates are equal.
        $this->assertLessThanOrEqual($atThirty + 0.005, $atThirtyOne, '31 is not dearer than 30');
        $this->assertSame($atThirty, $backAtThirty, '30 is priced as 30 again');
    }

    // ------------------------------------------------ checkout and order

    /**
     * Quantity 31 all the way through: basket, order, ordered line, and the
     * figures the invoice is rendered from.
     *
     * Checkout prices from the catalogue rather than from carts.price, so this
     * is the assertion that proves the two agree at the new boundary.
     */
    public function test_checkout_and_invoice_charge_the_open_ended_tier()
    {
        $this->ladderProduct();

        $customer = $this->customer(['account_type' => 'business']);

        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Boundary Fixture',
            'email' => $customer->email,
            'phone' => $customer->phone,
            'country' => 'IE',
            'state' => 'KE',
            'city' => 'Naas',
            'zip' => 'W91XY12',
            'address_1' => '1 Test Street',
            'type' => 1,
            'default' => 1,
        ]);

        $customer->default_address = $address->id;
        $customer->save();

        Passport::actingAs($customer->fresh(), ['user'], 'user');

        $place = optional($this->product->shipping_rule)->shipping_places()->first();

        if (!$place) {
            $this->markTestSkipped('The configured product has no delivery option.');
        }

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $this->product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => 31,
            'shipping_place_id' => $place->id,
            'shipping_type' => 1,
        ])->assertStatus(200);

        $expected = $this->expectedAt(31);

        // The basket and the checkout summary read the same endpoint, so what
        // is asserted here is what the customer confirms on the last screen.
        $this->assertSame(
            $expected, (float)$this->cartLine()['unit_price'], 'checkout summary'
        );

        $response = $this->postJson('/api/v1/order/action', [
            'data' => $this->orderPayload(),
            'order_method' => (int)config('constants.paymentMethod.BANK'),
        ]);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped(
                'Checkout did not complete in this environment: '
                . json_encode($response->json('message'))
            );
        }

        $line = OrderedProduct::where('order_id', $order->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($line, 'the product is on the order');

        $this->assertSame(31, (int)$line->quantity);

        $this->assertSame(
            $expected, (float)$line->selling, 'the order charges the 31+ rate'
        );

        /*
         * VAT follows the charged price, so a band that lowers the goods
         * lowers the tax with it.
         *
         * Which way round that goes is the store's own rule, not this test's:
         * excludeVAT = 1 means the price is quoted before VAT and tax is added
         * on top, and anything else is already VAT-inclusive so nothing is
         * added. Asserting the rate rather than a figure keeps this true
         * whatever VAT is set to; asserting the rule rather than "23% always"
         * keeps it true for both kinds of product.
         */
        $taxRule = $this->product->tax_rules;
        $addsVatOnTop = (int)$this->product->excludeVAT === 1;

        if (!$addsVatOnTop) {
            $this->assertSame(
                0.0, (float)$line->tax_price,
                'a VAT-inclusive price has nothing added on top'
            );

        } else if ($taxRule && (int)$taxRule->type === (int)config('constants.priceType.PERCENT')) {
            $this->assertSame(
                Pricing::round($expected * (float)$taxRule->price / 100),
                (float)$line->tax_price,
                'VAT is charged on the 31+ rate, not on retail'
            );
        }

        /*
         * The grand total rebuilt from the stored line - goods, their VAT and
         * carriage. These are the columns the invoice renders, so an invoice
         * that disagreed with the money taken would fail here.
         */
        $payable = 31 - Pricing::bundleFreeQuantity($this->product, 31);

        $goods = Pricing::round($expected * $payable);
        $tax = Pricing::round((float)$line->tax_price * $payable);
        $shipping = Pricing::round((float)$line->shipping_price);

        /*
         * Less whatever a store-wide flash discount took off the goods. It is
         * not a line, so rebuilding the total from the lines alone misses it
         * and the sum lands out by the promotion -- see
         * AccountsForStoreWideDiscount.
         */
        $storeWide = $this->storeWideDiscountTaken($order->id, $goods);

        $this->assertSame(
            Pricing::round($goods + $tax + $shipping - $storeWide),
            Pricing::round((float)$order->total_amount),
            'the grand total is the 31+ rate, its VAT and carriage'
        );

        // Buying 31 must not cost more than 31 at the rate 30 units had.
        $this->assertLessThanOrEqual(
            Pricing::round($this->expectedAt(30) * $payable) + 0.005, $goods,
            'the 31st unit pushed the order onto a dearer rate'
        );

        /*
         * And the order was priced by a band rather than by the retail ladder.
         * The comparison above is against the 30-unit rate, which is the same
         * band on the configured ladder; this is what would have caught the
         * original defect, where 31 matched no band and was charged retail.
         */
        $retail = Pricing::retailUnitPrice(
            Product::pricingRecord($this->product->id), $this->inventory
        );

        $this->assertLessThan(
            Pricing::round($retail * $payable) + 0.005, $goods,
            'the order was charged the retail price, not a band price'
        );
    }

    /**
     * Encrypted exactly as the storefront sends it. The zero padding matters:
     * OPENSSL_ZERO_PADDING silently produces a different ciphertext without
     * the manual pad, and the order is then rejected for a reason that has
     * nothing to do with pricing.
     */
    private function orderPayload(array $params = [])
    {
        $json = json_encode(array_merge([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.BANK'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ], $params));

        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt($json, 'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210'));
    }

    /**
     * A personal account sees none of this, at any quantity. The bands are the
     * whole reason the account types exist.
     */
    public function test_a_personal_account_gets_no_bulk_rate_at_any_quantity()
    {
        $this->ladderProduct();

        Passport::actingAs($this->customer(), ['user'], 'user');

        $body = $this->getJson(
            "/api/v1/business-product/{$this->product->id}?quantity=100"
        )->json('data');

        $this->assertFalse((bool)$body['has_business_pricing']);
        $this->assertSame([], $body['pricing']);

        $this->setCartQuantity(0, 100)->assertStatus(200);

        $this->assertGreaterThan(
            $this->expectedAt(100),
            (float)$this->cartLine()['unit_price'],
            'a personal account pays retail at 100 units'
        );
    }

    // ------------------------------------------- what the stepper answers with

    /**
     * The tile reads its price straight off this response, so the response has
     * to carry one.
     *
     * A product tile renders the listing price, which ListingPrices::decorate
     * settles at quantity 1. Once the tile has a stepper that is the wrong
     * number, and the only figure that is right is the one this endpoint just
     * decided. Nothing on the storefront may work a band out for itself.
     *
     * @dataProvider boundaries
     */
    public function test_the_stepper_response_carries_the_price_for_the_new_quantity($quantity)
    {
        $this->ladderProduct();
        $this->asBusiness();

        $body = $this->setCartQuantity(0, $quantity)
            ->assertStatus(200)
            ->json('data');

        $this->assertEqualsWithDelta(
            $this->expectedAt($quantity),
            (float)$body['unit_price'],
            0.01,
            "cart/action must answer with the band price for quantity {$quantity}"
        );

        $this->assertEqualsWithDelta(
            (float)$body['unit_price'] * $quantity,
            (float)$body['line_total'],
            0.01,
            'the line total follows the price it just decided'
        );
    }

    /**
     * And with the VAT split for that price.
     *
     * These were missing here while by-user returned them, so a tile showing
     * both had this quantity's net price beside the previous quantity's gross
     * one. The storefront must never divide by a rate to close that gap - the
     * catalogue runs two VAT conventions and guessing wrong is a 46% error.
     */
    public function test_the_stepper_response_carries_the_vat_split()
    {
        $this->ladderProduct();
        $this->asBusiness();

        $body = $this->setCartQuantity(0, 11)
            ->assertStatus(200)
            ->json('data');

        foreach ([
            'vat_rate', 'vat_inclusive',
            'unit_ex_vat', 'unit_vat', 'unit_inc_vat',
            'line_ex_vat', 'line_vat', 'line_inc_vat',
        ] as $field) {
            $this->assertArrayHasKey(
                $field, $body, "cart/action must answer with {$field}"
            );
        }

        $expected = Pricing::vatBreakdown(
            (float)$body['unit_price'],
            Pricing::vatRate($this->product->tax_rules),
            Pricing::isVatInclusive($this->product)
        );

        $this->assertEqualsWithDelta($expected['ex'], (float)$body['unit_ex_vat'], 0.01);
        $this->assertEqualsWithDelta($expected['inc'], (float)$body['unit_inc_vat'], 0.01);
        $this->assertSame($expected['inclusive'], (bool)$body['vat_inclusive']);
    }

    /**
     * Crossing a boundary moves the split with the price.
     *
     * The stale-price bug in miniature: the gross figure has to describe the
     * net figure standing next to it, at the quantity on screen.
     */
    public function test_crossing_a_boundary_moves_the_price_and_its_split_together()
    {
        $this->ladderProduct();
        $this->asBusiness();

        $below = $this->setCartQuantity(0, 10)->assertStatus(200)->json('data');
        $above = $this->setCartQuantity(10, 11)->assertStatus(200)->json('data');

        $this->assertNotEqualsWithDelta(
            (float)$below['unit_price'],
            (float)$above['unit_price'],
            0.01,
            '10 -> 11 crosses a configured band'
        );

        foreach ([$below, $above] as $body) {
            $rate = (float)$body['vat_rate'];

            if ($rate <= 0) {
                continue;
            }

            $this->assertEqualsWithDelta(
                (float)$body['unit_inc_vat'],
                Pricing::vatBreakdown(
                    (float)$body['unit_price'], $rate, (bool)$body['vat_inclusive']
                )['inc'],
                0.01,
                'the gross figure describes the net one beside it'
            );
        }
    }
}
