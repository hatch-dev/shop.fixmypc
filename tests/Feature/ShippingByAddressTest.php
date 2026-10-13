<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cart;
use App\Models\Helper\OrderSummary;
use App\Models\Helper\Shipping;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\Product;
use App\Models\ShippingPlace;
use App\Models\ShippingRule;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Delivery is decided by the address, not the product (2026-09-28).
 *
 * Products are no longer assigned a shipping rule. A product may only be
 * limited to the countries it is sold in (products.sell_countries); the row
 * that is charged is the one covering the delivery address - exact state
 * over "all states" over "all countries" - or the collection point, and it is
 * charged once per order. The browser says delivery or collection and which
 * address; it never picks the row or the price.
 *
 * Each test pins one thing the old per-product design got wrong:
 * the €24-per-line charge, the client choosing any row it liked, shipping
 * type 3 being free, the first row being charged whatever the address, and
 * "ALL states" beating an exact state match.
 *
 * The fixture rules use France, which no real rule covers, so the store's
 * own Ireland and UK rows cannot move these figures.
 */
class ShippingByAddressTest extends TestCase
{
    use DatabaseTransactions;

    private $rule;
    private $franceAll;
    private $franceIdf;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Admin::orderBy('id')->first();

        $this->rule = ShippingRule::create(['title' => 'Test France', 'admin_id' => $admin->id]);

        $row = function ($state, $price) use ($admin) {
            return ShippingPlace::create([
                'country' => 'FR', 'state' => $state, 'price' => $price, 'day_needed' => 4,
                'pickup_point' => 0, 'pickup_price' => 0,
                'shipping_rule_id' => $this->rule->id, 'admin_id' => $admin->id,
            ]);
        };

        // The exact-state row is created second on purpose: the old matcher
        // let a later "ALL" row overwrite an earlier exact match, and an
        // earlier one too, depending on order.
        $this->franceAll = $row('ALL', 7.50);
        $this->franceIdf = $row('IDF', 5.00);
    }

    private function products($count = 2)
    {
        $products = Product::where('status', 1)
            ->whereHas('product_inventories')
            ->orderBy('id')
            ->take($count)
            ->get();

        if ($products->count() < $count) {
            $this->markTestSkipped('Not enough published products with inventory.');
        }

        return $products->map(function ($product) {
            $inventory = UpdatedInventory::where('product_id', $product->id)->orderBy('id')->first();
            UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 5000]);
            $product->test_inventory = $inventory->id;

            return $product;
        });
    }

    private function customer($country = 'FR', $state = 'IDF')
    {
        $customer = User::create([
            'name' => 'Shipping Fixture',
            'email' => 'shipping.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();

        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Shipping Fixture',
            'email' => $customer->email,
            'phone' => $customer->phone,
            'country' => $country,
            'state' => $state,
            'city' => 'Paris',
            'zip' => '75001',
            'address_1' => '1 Rue de Test',
            'type' => 1,
            'default' => 1,
        ]);

        $customer->default_address = $address->id;
        $customer->save();
        $customer->test_address = $address->id;

        Passport::actingAs($customer->fresh(), ['user'], 'user');

        return $customer;
    }

    private function addToCart($product, $quantity = 1)
    {
        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $product->test_inventory,
            'quantity' => $quantity,
        ])->assertStatus(200);

        return Cart::where('product_id', $product->id)->orderByDesc('id')->first();
    }

    private function saveShipping($customer, array $lines, array $extra = [])
    {
        return $this->postJson('/api/v1/cart/update-shipping', array_merge([
            'cart' => $lines,
            'selected_address' => $customer->test_address,
        ], $extra));
    }

    private function line($cart, $type = 1, $placeId = null)
    {
        return [
            'cart' => $cart->id,
            'shipping_type' => $type,
            'shipping_place' => $placeId ? ['id' => $placeId] : null,
        ];
    }

    private function placeOrder()
    {
        $json = json_encode([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.BANK'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ]);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        $data = openssl_encrypt($json, 'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210'));

        return $this->postJson('/api/v1/order/action', [
            'data' => $data,
            'order_method' => (int)config('constants.paymentMethod.BANK'),
        ]);
    }

    public function test_an_exact_state_row_beats_the_all_states_row()
    {
        $this->assertSame($this->franceIdf->id, Shipping::deliveryPlace('FR', 'IDF')->id);
        $this->assertSame($this->franceAll->id, Shipping::deliveryPlace('FR', 'NOR')->id);
        $this->assertSame($this->franceAll->id, Shipping::deliveryPlace('fr', '')->id);
    }

    public function test_the_quote_is_priced_from_the_address()
    {
        [$product] = $this->products(1);
        $customer = $this->customer('FR', 'NOR');
        $this->addToCart($product);

        $quote = $this->postJson('/api/v1/cart/shipping-quote', [
            'shipping_type' => 1,
            'selected_address' => $customer->test_address,
        ])->assertStatus(200)->json('data');

        $this->assertTrue($quote['ok']);
        $this->assertEquals(7.50, $quote['price']);
        $this->assertSame($this->franceAll->id, $quote['place']['id']);

        // A guest still typing their address asks by country and county.
        $typed = $this->postJson('/api/v1/cart/shipping-quote', [
            'shipping_type' => 1, 'country' => 'FR', 'state' => 'IDF',
        ])->json('data');

        $this->assertEquals(5.00, $typed['price']);
    }

    public function test_a_country_no_rule_covers_is_refused_not_charged_the_first_row()
    {
        [$product] = $this->products(1);
        $customer = $this->customer('ZW', '');
        $cart = $this->addToCart($product);

        $response = $this->saveShipping($customer, [$this->line($cart, 1, $this->franceIdf->id)]);

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertStringContainsString('do not deliver to', json_encode($response->json('data')));
        $this->assertNull($cart->fresh()->shipping_place_id);
    }

    public function test_the_row_the_browser_names_is_ignored()
    {
        [$product] = $this->products(1);
        $customer = $this->customer('FR', 'NOR');
        $cart = $this->addToCart($product);

        // The cheaper exact-state row, which does not cover this address.
        $this->saveShipping($customer, [$this->line($cart, 1, $this->franceIdf->id)])
            ->assertJsonPath('status', 200);

        $this->assertSame($this->franceAll->id, (int)$cart->fresh()->shipping_place_id);
    }

    public function test_a_shipping_type_other_than_delivery_or_collection_is_refused()
    {
        [$product] = $this->products(1);
        $customer = $this->customer();
        $cart = $this->addToCart($product);

        $response = $this->saveShipping($customer, [$this->line($cart, 3)]);

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertStringContainsString('delivered or to collect', json_encode($response->json('data')));
    }

    public function test_a_basket_half_posted_and_half_collected_is_refused()
    {
        [$a, $b] = $this->products(2);
        $customer = $this->customer();
        $lineA = $this->addToCart($a);
        $lineB = $this->addToCart($b);

        $response = $this->saveShipping($customer, [$this->line($lineA, 1), $this->line($lineB, 2)]);

        $this->assertNotEquals(200, $response->json('status'));
    }

    public function test_a_product_sold_only_elsewhere_cannot_be_shipped_here()
    {
        [$a, $b] = $this->products(2);
        Product::where('id', $b->id)->update(['sell_countries' => 'IE']);

        $customer = $this->customer();
        $lineA = $this->addToCart($a);
        $lineB = $this->addToCart($b);

        $quote = $this->postJson('/api/v1/cart/shipping-quote', [
            'shipping_type' => 1, 'selected_address' => $customer->test_address,
        ])->json('data');

        $this->assertFalse($quote['ok']);
        $this->assertArrayHasKey((string)$lineB->id, $quote['restricted_carts']);
        $this->assertArrayNotHasKey((string)$lineA->id, $quote['restricted_carts']);
        $this->assertStringContainsString('cannot be shipped to France', $quote['restricted_carts'][$lineB->id]);

        $response = $this->saveShipping($customer, [$this->line($lineA), $this->line($lineB)]);

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertStringContainsString('cannot be shipped to France', json_encode($response->json('data')));
    }

    public function test_an_unrestricted_product_is_sold_anywhere_a_rule_delivers()
    {
        $this->assertTrue(Shipping::canSellTo((object)['sell_countries' => null], 'FR'));
        $this->assertTrue(Shipping::canSellTo((object)['sell_countries' => 'FR,IE'], 'fr'));
        $this->assertFalse(Shipping::canSellTo((object)['sell_countries' => 'IE'], 'FR'));

        $this->assertSame('GB,IE', Shipping::normaliseCountries(['ie', ' GB', 'XX', '']));
        $this->assertNull(Shipping::normaliseCountries([]));
        $this->assertNull(Shipping::normaliseCountries('Ireland'));
    }

    public function test_an_order_is_charged_delivery_once_not_per_line()
    {
        [$a, $b] = $this->products(2);
        $customer = $this->customer('FR', 'NOR');
        $lineA = $this->addToCart($a, 2);
        $lineB = $this->addToCart($b);

        $this->saveShipping($customer, [$this->line($lineA), $this->line($lineB)])
            ->assertJsonPath('status', 200);

        $response = $this->placeOrder();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();
        $this->assertNotNull($order, 'no order: ' . json_encode($response->json()));

        $lines = OrderedProduct::where('order_id', $order->id)->get();

        $this->assertCount(2, $lines);
        $this->assertEquals(7.50, $lines->sum('shipping_price'), 'delivery must be charged once');
        $this->assertSame([$this->franceAll->id], $lines->pluck('shipping_place_id')->map('intval')->unique()->values()->all());
        $this->assertEquals(7.50, OrderSummary::for($order->fresh())['shipping']);
    }

    public function test_an_order_decides_delivery_again_rather_than_trusting_the_basket()
    {
        [$product] = $this->products(1);
        $customer = $this->customer('FR', 'NOR');
        $cart = $this->addToCart($product);

        // A basket row written straight to the table, as a tampered or stale
        // request would leave it: the cheaper row, for an address it does
        // not cover.
        Cart::where('id', $cart->id)->update(['shipping_place_id' => $this->franceIdf->id, 'shipping_type' => 1]);

        $this->placeOrder();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();
        $this->assertNotNull($order);

        $line = OrderedProduct::where('order_id', $order->id)->first();
        $this->assertEquals(7.50, (float)$line->shipping_price);
        $this->assertSame($this->franceAll->id, (int)$line->shipping_place_id);
    }

    public function test_an_order_with_a_restricted_product_is_refused()
    {
        [$product] = $this->products(1);
        $customer = $this->customer('FR', 'NOR');
        $cart = $this->addToCart($product);

        $this->saveShipping($customer, [$this->line($cart)])->assertJsonPath('status', 200);

        // Restricted after the shipping step: the order still says no.
        Product::where('id', $product->id)->update(['sell_countries' => 'IE,GB']);

        $response = $this->placeOrder();

        $this->assertStringContainsString('cannot be shipped to France', json_encode($response->json('data')));
        $this->assertNull(Order::where('user_id', $customer->id)->first());
    }

    public function test_collection_charges_the_collection_point_once_and_checks_its_country()
    {
        $point = Shipping::collectionPoints()->first();

        if (!$point || Shipping::collectionPoints()->count() !== 1) {
            $this->markTestSkipped('Needs exactly one collection point.');
        }

        [$a, $b] = $this->products(2);
        $customer = $this->customer();
        $lineA = $this->addToCart($a);
        $lineB = $this->addToCart($b);

        $quote = $this->postJson('/api/v1/cart/shipping-quote', ['shipping_type' => 2])->json('data');
        $this->assertTrue($quote['ok']);
        $this->assertSame($point->id, $quote['place']['id']);
        $this->assertEquals((float)$point->pickup_price, $quote['price']);

        // Sold only outside the collection point's country: cannot be collected.
        $elsewhere = $point->country === 'GB' ? 'IE' : 'GB';
        Product::where('id', $b->id)->update(['sell_countries' => $elsewhere]);

        $response = $this->saveShipping($customer, [$this->line($lineA, 2), $this->line($lineB, 2)], ['selected_address' => null]);
        $this->assertStringContainsString('cannot be collected there', json_encode($response->json('data')));

        Product::where('id', $b->id)->update(['sell_countries' => $point->country]);

        $this->saveShipping($customer, [$this->line($lineA, 2), $this->line($lineB, 2)], ['selected_address' => null])
            ->assertJsonPath('status', 200);

        $this->assertSame($point->id, (int)$lineA->fresh()->shipping_place_id);
        $this->assertSame(2, (int)$lineB->fresh()->shipping_type);
    }

    /* ------------------------------------------------------------------ admin */

    private function actAsAdmin()
    {
        $this->app['auth']->forgetGuards();
        Passport::actingAs(Admin::orderBy('id')->first(), ['admin'], 'admin');
    }

    private function saveRule(array $places, $id = null, $title = 'Test rule')
    {
        return $this->postJson('/api/admin/shipping-rule/action' . ($id ? "/{$id}" : ''), [
            'title' => $title,
            'shipping_places' => $places,
        ]);
    }

    private function place(array $overrides = [])
    {
        return array_merge([
            'country' => 'DE', 'state' => 'ALL', 'price' => 9, 'day_needed' => 3,
            'pickup_point' => 0, 'pickup_price' => 0,
        ], $overrides);
    }

    public function test_admin_saves_a_rule_on_its_own()
    {
        $this->actAsAdmin();

        $response = $this->saveRule([$this->place()]);

        $response->assertJsonPath('status', 200);
        $this->assertSame('DE', ShippingPlace::where('shipping_rule_id', $response->json('data.id'))->value('country'));
    }

    public function test_admin_cannot_put_one_country_in_two_rules()
    {
        $this->actAsAdmin();

        $response = $this->saveRule([$this->place(['country' => 'FR', 'state' => 'ALL'])]);

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertStringContainsString('already covered by the shipping rule', json_encode($response->json('data')));

        // One destination per rule: a second row is refused outright.
        $twice = $this->saveRule([$this->place(), $this->place(['country' => 'ES'])]);
        $this->assertStringContainsString('has one destination', json_encode($twice->json('data')));
    }

    public function test_an_older_rule_with_several_rows_saves_once_the_extras_go()
    {
        $this->actAsAdmin();

        // $this->rule was built with two rows, as rules could be before.
        $response = $this->saveRule([
            ['id' => $this->franceAll->id, 'country' => 'FR', 'state' => 'ALL', 'price' => 8, 'day_needed' => 4],
            ['id' => $this->franceIdf->id, 'country' => 'FR', 'state' => 'IDF', 'price' => 5, 'day_needed' => 4, 'deleted' => true],
        ], $this->rule->id, 'Test France');

        $response->assertJsonPath('status', 200);
        $this->assertEquals(8.00, (float)$this->franceAll->fresh()->price);
        $this->assertNull(ShippingPlace::find($this->franceIdf->id));
    }

    public function test_admin_prices_and_days_cannot_be_negative()
    {
        $this->actAsAdmin();

        foreach ([['price' => -5], ['pickup_price' => -1], ['day_needed' => -2], ['day_needed' => 1.5], ['price' => 'abc']] as $bad) {
            $response = $this->saveRule([$this->place($bad)]);
            $this->assertNotEquals(200, $response->json('status'), json_encode($bad));
        }
    }

    public function test_coverage_lists_what_other_rules_hold()
    {
        $this->actAsAdmin();

        $all = collect($this->getJson('/api/admin/shipping-rule/coverage')->json('data'))->pluck('key');
        $this->assertContains('FR|ALL', $all);
        $this->assertContains('FR|IDF', $all);

        // Editing this rule: its own rows are not "taken".
        $except = collect($this->getJson("/api/admin/shipping-rule/coverage?except={$this->rule->id}")->json('data'))->pluck('key');
        $this->assertNotContains('FR|ALL', $except);
    }

    public function test_a_collection_point_needs_a_real_country()
    {
        $this->actAsAdmin();

        $response = $this->saveRule([$this->place(['country' => 'ALL', 'state' => '', 'pickup_point' => 1])]);

        $this->assertStringContainsString('collection point needs a specific country', json_encode($response->json('data')));
    }

    public function test_admin_cannot_edit_another_rules_row_through_this_one()
    {
        $this->actAsAdmin();

        $mine = $this->saveRule([$this->place()])->json('data.id');

        $response = $this->saveRule([
            $this->place(['id' => $this->franceIdf->id, 'country' => 'FR', 'state' => 'IDF', 'price' => 0]),
        ], $mine);

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertEquals(5.00, (float)$this->franceIdf->fresh()->price);
    }

    public function test_a_refused_save_writes_nothing()
    {
        $this->actAsAdmin();

        $response = $this->saveRule([
            $this->place(['id' => $this->franceAll->id, 'country' => 'FR', 'state' => 'ALL', 'price' => 1]),
            $this->place(['country' => 'FR', 'state' => 'ALL']),
        ], $this->rule->id, 'Renamed');

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertEquals(7.50, (float)$this->franceAll->fresh()->price);
        $this->assertSame('Test France', $this->rule->fresh()->title);
    }

    private function saveProduct(array $over = [], $id = null)
    {
        $payload = array_merge([
            'title' => 'QA Sell Only In ' . uniqid(),
            'short_heading' => 'Short heading',
            'slug' => 'qa-sell-only-in-' . uniqid(),
            'unit' => 'pcs',
            'meta_title' => 'Meta title',
            'meta_description' => 'Meta description',
            'description' => 'Description',
            'overview' => 'Overview',
            'selling' => 100,
            'purchased' => 50,
            'offered' => '',
            'excludeVAT' => 0,
            'status' => 2,
            'tax_rule_id' => \App\Models\TaxRules::value('id') ?: 1,
        ], $over);

        return $this->postJson('/api/admin/product/action' . ($id ? "/{$id}" : ''), $payload);
    }

    public function test_a_product_saves_without_a_shipping_rule_and_keeps_its_countries()
    {
        $this->actAsAdmin();

        $response = $this->saveProduct(['sell_countries' => ['ie', 'GB', 'XX']]);
        $this->assertSame(200, $response->json('status'), json_encode($response->json()));

        $id = $response->json('data.id');
        $this->assertSame('GB,IE', Product::where('id', $id)->value('sell_countries'));

        // A save that does not mention the countries leaves them alone.
        $this->actAsAdmin();
        $this->saveProduct(['title' => 'Renamed ' . uniqid()], $id)->assertJsonPath('status', 200);
        $this->assertSame('GB,IE', Product::where('id', $id)->value('sell_countries'));

        // An empty list is "anywhere".
        $this->actAsAdmin();
        $this->saveProduct(['sell_countries' => []], $id)->assertJsonPath('status', 200);
        $this->assertNull(Product::where('id', $id)->value('sell_countries'));
    }

    public function test_a_rule_whose_rows_orders_point_to_cannot_be_deleted()
    {
        $used = OrderedProduct::whereNotNull('shipping_place_id')->value('shipping_place_id');
        $ruleId = $used ? ShippingPlace::where('id', $used)->value('shipping_rule_id') : null;

        if (!$ruleId) {
            $this->markTestSkipped('No order points at a shipping row here.');
        }

        $this->actAsAdmin();

        $response = $this->deleteJson("/api/admin/shipping-rule/delete/{$ruleId}");

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertNotNull(ShippingRule::find($ruleId));

        // An unused rule goes, even though products once carried rule ids.
        $this->deleteJson("/api/admin/shipping-rule/delete/{$this->rule->id}")->assertJsonPath('status', 200);
        $this->assertNull(ShippingRule::find($this->rule->id));
    }

    /** A store with no address cannot be offered for collection. */
    public function test_a_collection_point_needs_an_address()
    {
        $this->actAsAdmin();

        $response = $this->saveRule([$this->place(['country' => 'NL', 'pickup_point' => 1, 'pickup_phone' => '0320 000000'])]);

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertStringContainsString('street address', json_encode($response->json('data')));
    }

    /** Phone and postcode are asked for too: only address line 2 is optional. */
    public function test_a_collection_point_needs_a_phone_and_postcode()
    {
        $this->actAsAdmin();

        $base = [
            'country' => 'NL', 'pickup_point' => 1, 'pickup_price' => 0,
            'pickup_address_line_1' => 'Damrak 1', 'pickup_city' => 'Lelystad', 'pickup_state' => 'Flevoland',
        ];

        $noPhone = $this->saveRule([$this->place($base + ['pickup_zip' => '8232 AA'])]);
        $this->assertStringContainsString('phone number', json_encode($noPhone->json('data')));

        $noZip = $this->saveRule([$this->place($base + ['pickup_phone' => '0320 000000'])]);
        $this->assertStringContainsString('postcode', json_encode($noZip->json('data')));
    }

    /**
     * The store is in the row's country: the "Test Germany" row once held a
     * Dublin address, and every collection there was treated as a German sale.
     */
    public function test_a_collection_point_must_be_in_the_rows_country()
    {
        $this->actAsAdmin();

        $response = $this->saveRule([$this->place([
            'country' => 'NL', 'pickup_point' => 1,
            'pickup_address_line_1' => '1 Main Street', 'pickup_city' => 'Dundrum', 'pickup_phone' => '01 000 0000', 'pickup_zip' => 'D14 AB12', 'pickup_state' => 'Dublin',
        ])]);

        $this->assertNotEquals(200, $response->json('status'));
        $this->assertStringContainsString('must be in Netherlands', json_encode($response->json('data')));
    }

    /** Whatever country is typed, the row's is stored, with the county's name. */
    public function test_a_collection_point_takes_the_rows_country()
    {
        $this->actAsAdmin();

        $response = $this->saveRule([$this->place([
            'country' => 'NL', 'pickup_point' => 1,
            'pickup_address_line_1' => 'Damrak 1', 'pickup_city' => 'Lelystad', 'pickup_phone' => '0320 000000', 'pickup_zip' => '8232 AA',
            'pickup_state' => 'fl', 'pickup_country' => 'Germany',
        ])]);

        $response->assertJsonPath('status', 200);

        $place = ShippingPlace::where('shipping_rule_id', $response->json('data.id'))->first();
        $this->assertSame(Shipping::countryName('NL'), $place->pickup_country);
        $this->assertSame('Flevoland', $place->pickup_state);
    }
}
