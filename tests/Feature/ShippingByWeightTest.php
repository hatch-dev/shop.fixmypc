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
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Delivery priced by what the order weighs (2026-10-14), after WooCommerce's
 * Table Rate Shipping.
 *
 * A delivery row may carry weight bands, each a flat price up to a weight.
 * The order's weight - each line's product weight times its quantity, an
 * unweighted product counting as 0 - picks the band, charged once for the
 * order. An order heavier than the last band pays that band's cost plus the
 * row's one charge per extra kg (weight_extra_per_kg); without that charge it
 * is not delivered. A row without bands keeps its flat price.
 *
 * The figure has to be the same everywhere it is shown or taken: the quote on
 * /shipping and the product page, the basket lines /checkout reads (and the
 * card is charged from), and the order itself.
 *
 * Fixture: France, which no real rule covers, priced
 *   up to 2 kg   €5.00
 *   up to 10 kg  €9.00
 * and nothing above 10 kg.
 */
class ShippingByWeightTest extends TestCase
{
    use DatabaseTransactions;

    private $rule;
    private $france;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Admin::orderBy('id')->first();

        $this->rule = ShippingRule::create(['title' => 'Test France by weight', 'admin_id' => $admin->id]);

        $this->france = ShippingPlace::create([
            'country' => 'FR', 'state' => 'ALL', 'price' => 7.50, 'day_needed' => 4,
            'pickup_point' => 0, 'pickup_price' => 0,
            'weight_rates' => [
                ['max' => 2, 'cost' => 5],
                ['max' => 10, 'cost' => 9],
            ],
            'weight_extra_per_kg' => null,
            'shipping_rule_id' => $this->rule->id, 'admin_id' => $admin->id,
        ]);
    }

    /** Published products with stock, weighed as given (kg; null = none). */
    private function products(array $weights)
    {
        $products = Product::where('status', 1)
            ->whereHas('product_inventories')
            ->orderBy('id')
            ->take(count($weights))
            ->get();

        if ($products->count() < count($weights)) {
            $this->markTestSkipped('Not enough published products with inventory.');
        }

        return $products->values()->map(function ($product, $i) use ($weights) {
            Product::where('id', $product->id)->update(['weight' => $weights[$i], 'sell_countries' => null]);

            $inventory = UpdatedInventory::where('product_id', $product->id)->orderBy('id')->first();
            UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 5000]);
            $product->test_inventory = $inventory->id;

            return $product;
        });
    }

    private function customer()
    {
        $customer = User::create([
            'name' => 'Weight Fixture',
            'email' => 'weight.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();

        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Weight Fixture',
            'email' => $customer->email,
            'phone' => $customer->phone,
            'country' => 'FR',
            'state' => 'NOR',
            'city' => 'Rouen',
            'zip' => '76000',
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

    private function saveShipping($customer, array $carts)
    {
        return $this->postJson('/api/v1/cart/update-shipping', [
            'cart' => array_map(function ($cart) {
                return ['cart' => $cart->id, 'shipping_type' => 1, 'shipping_place' => null];
            }, $carts),
            'selected_address' => $customer->test_address,
        ]);
    }

    private function quote($customer, array $extra = [])
    {
        return $this->postJson('/api/v1/cart/shipping-quote', array_merge([
            'shipping_type' => 1,
            'selected_address' => $customer->test_address,
        ], $extra));
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

    // ---- the rules themselves --------------------------------------------

    public function test_a_row_without_bands_keeps_its_flat_price_whatever_the_weight()
    {
        $flat = ['price' => 7.50, 'weight_rates' => null];

        $this->assertEquals(7.50, Shipping::deliveryPrice($flat, 0));
        $this->assertEquals(7.50, Shipping::deliveryPrice($flat, 250));
    }

    public function test_the_band_the_weight_falls_in_is_charged_flat()
    {
        $this->assertEquals(5.00, Shipping::deliveryPrice($this->france, 0));
        $this->assertEquals(5.00, Shipping::deliveryPrice($this->france, 2), '"up to" includes the limit');
        $this->assertEquals(9.00, Shipping::deliveryPrice($this->france, 2.001));
        $this->assertEquals(9.00, Shipping::deliveryPrice($this->france, 10));
        $this->assertNull(Shipping::deliveryPrice($this->france, 10.5), 'heavier than the last band, no extra charge');
    }

    public function test_heavier_than_the_last_band_pays_its_cost_plus_the_charge_per_extra_kg()
    {
        $place = ['price' => 0, 'weight_extra_per_kg' => '1.50', 'weight_rates' => [
            ['max' => 10, 'cost' => 9],
            ['max' => 2, 'cost' => 5],
        ]];

        $this->assertEquals(9.00, Shipping::deliveryPrice($place, 10), 'the band, not the extra charge');
        $this->assertEquals(15.00, Shipping::deliveryPrice($place, 14), '€9 + 4 kg x €1.50, bands sorted');

        $place['weight_extra_per_kg'] = 0;
        $this->assertEquals(9.00, Shipping::deliveryPrice($place, 80), '0 per kg: any weight at the last band');
    }

    public function test_an_order_weighs_weight_times_quantity_and_unweighted_products_count_zero()
    {
        [$a, $b, $c] = $this->products([1.5, 0.4, null]);

        $weight = Shipping::linesWeight([
            ['product_id' => $a->id, 'quantity' => 2],
            ['product_id' => $b->id, 'quantity' => 3],
            ['product_id' => $c->id, 'quantity' => 9],
        ]);

        $this->assertEquals(4.2, $weight);
    }

    // ---- the quote, the basket, the order --------------------------------

    public function test_the_shipping_page_quote_prices_the_basket_by_weight()
    {
        [$a, $b] = $this->products([1.5, 0.4]);
        $customer = $this->customer();

        $this->addToCart($a);
        $this->quote($customer)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.weight', 1.5)
            ->assertJsonPath('data.price', 5);

        $this->addToCart($b, 2);
        $response = $this->quote($customer);

        $response->assertJsonPath('data.ok', true);
        $this->assertEquals(2.3, $response->json('data.weight'));
        $this->assertEquals(9.00, $response->json('data.price'));
    }

    public function test_the_product_page_quote_weighs_that_item_at_its_quantity_not_the_basket()
    {
        [$a, $b] = $this->products([1.5, 0.4]);
        $customer = $this->customer();
        $this->addToCart($b, 20);

        $response = $this->quote($customer, ['product_id' => $a->id, 'quantity' => 1]);

        $this->assertEquals(1.5, $response->json('data.weight'), 'not the 8 kg basket');
        $this->assertEquals(5.00, $response->json('data.price'));
    }

    public function test_an_order_too_heavy_for_every_band_is_refused_with_a_reason()
    {
        [$a] = $this->products([1.5]);
        $customer = $this->customer();
        $cart = $this->addToCart($a, 7);

        $quote = $this->quote($customer);
        $quote->assertJsonPath('data.ok', false)->assertJsonPath('data.too_heavy', true);
        $this->assertStringContainsString('10.5 kg', $quote->json('data.message'));
        $this->assertStringContainsString('too heavy', $quote->json('data.message'));

        $this->assertNotSame(200, $this->saveShipping($customer, [$cart])->json('status'));
    }

    public function test_a_charge_per_extra_kg_lets_a_heavy_order_through()
    {
        [$a] = $this->products([1.5]);
        $customer = $this->customer();
        $this->addToCart($a, 8);

        ShippingPlace::where('id', $this->france->id)->update(['weight_extra_per_kg' => 2]);

        $quote = $this->quote($customer);
        $quote->assertJsonPath('data.ok', true)->assertJsonPath('data.too_heavy', false);
        $this->assertEquals(13.00, $quote->json('data.price'), '€9 + 2 kg x €2');
    }

    public function test_the_basket_lines_checkout_reads_carry_the_weight_price()
    {
        [$a, $b] = $this->products([1.5, 0.4]);
        $customer = $this->customer();
        $lineA = $this->addToCart($a);
        $lineB = $this->addToCart($b, 2);

        $saved = $this->saveShipping($customer, [$lineA, $lineB]);
        $saved->assertJsonPath('status', 200);

        foreach ([$saved->json('data'), $this->getJson('/api/v1/cart/by-user')->json('data')] as $lines) {
            $line = collect($lines)->firstWhere('id', $lineA->id);

            $this->assertNotNull($line, 'basket line missing');
            $this->assertSame($this->france->id, (int)$line['shipping_place']['id']);
            $this->assertEquals(9.00, $line['shipping_place']['price'], 'not the flat €7.50');
            $this->assertEquals(2.3, $line['shipping_place']['order_weight']);
        }
    }

    public function test_an_order_is_charged_its_weight_band_once()
    {
        [$a, $b] = $this->products([1.5, 0.4]);
        $customer = $this->customer();
        $lineA = $this->addToCart($a);
        $lineB = $this->addToCart($b, 2);

        $this->saveShipping($customer, [$lineA, $lineB])->assertJsonPath('status', 200);

        $response = $this->placeOrder();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();
        $this->assertNotNull($order, 'no order: ' . json_encode($response->json()));

        $lines = OrderedProduct::where('order_id', $order->id)->get();

        $this->assertCount(2, $lines);
        $this->assertEquals(9.00, $lines->sum('shipping_price'), 'one band charge for the order');
        $this->assertEquals(9.00, OrderSummary::for($order->fresh())['shipping']);
    }

    public function test_an_order_that_became_too_heavy_after_the_shipping_step_is_refused()
    {
        [$a] = $this->products([1.5]);
        $customer = $this->customer();
        $cart = $this->addToCart($a);

        $this->saveShipping($customer, [$cart])->assertJsonPath('status', 200);

        // the basket grew past the last band after delivery was chosen
        Cart::where('id', $cart->id)->update(['quantity' => 8]);

        $this->placeOrder();

        $this->assertNull(Order::where('user_id', $customer->id)->first());
    }

    // ---- admin -----------------------------------------------------------

    private function actAsAdmin()
    {
        $this->app['auth']->forgetGuards();
        Passport::actingAs(Admin::orderBy('id')->first(), ['admin'], 'admin');
    }

    private function saveRule(array $place, $id = null)
    {
        return $this->postJson('/api/admin/shipping-rule/action' . ($id ? "/{$id}" : ''), [
            'title' => 'Test Portugal by weight',
            'shipping_places' => [array_merge([
                'country' => 'PT', 'state' => 'ALL', 'price' => 9, 'day_needed' => 3,
                'pickup_point' => 0, 'pickup_price' => 0,
            ], $place)],
        ]);
    }

    public function test_admin_saves_weight_bands_lightest_first_and_edits_them()
    {
        $this->actAsAdmin();

        $created = $this->saveRule([
            'weight_rates' => [
                ['max' => '10', 'cost' => '30'],
                ['max' => '5', 'cost' => '8'],
            ],
            'weight_extra_per_kg' => '1.5',
        ]);
        $created->assertJsonPath('status', 200);

        $place = ShippingPlace::where('shipping_rule_id', $created->json('data.id'))->first();
        $this->assertEquals([
            ['max' => 5.0, 'cost' => 8.0],
            ['max' => 10.0, 'cost' => 30.0],
        ], $place->weight_rates);
        $this->assertEquals(1.5, $place->weight_extra_per_kg);

        // the edit path writes through the query builder, past the cast;
        // an empty charge per extra kg means heavier orders are not delivered
        $this->saveRule([
            'id' => $place->id,
            'weight_rates' => [['max' => 3, 'cost' => 6]],
            'weight_extra_per_kg' => '',
        ], $created->json('data.id'))->assertJsonPath('status', 200);

        $place = $place->fresh();
        $this->assertEquals([['max' => 3.0, 'cost' => 6.0]], $place->weight_rates);
        $this->assertNull($place->weight_extra_per_kg);

        // and clearing the bands goes back to the flat price, extra charge too
        $this->saveRule([
            'id' => $place->id, 'weight_rates' => [], 'weight_extra_per_kg' => '4',
        ], $created->json('data.id'))->assertJsonPath('status', 200);

        $this->assertNull($place->fresh()->weight_rates);
        $this->assertNull($place->fresh()->weight_extra_per_kg);
    }

    public function test_admin_cannot_save_bands_that_do_not_make_sense()
    {
        $this->actAsAdmin();

        $bad = [
            'band with no limit' => [[['max' => '', 'cost' => 1]], null],
            'same limit twice' => [[['max' => 2, 'cost' => 1], ['max' => '2.000', 'cost' => 2]], null],
            'zero limit' => [[['max' => 0, 'cost' => 1]], null],
            'no cost' => [[['max' => 2, 'cost' => '']], null],
            'negative cost' => [[['max' => 2, 'cost' => -1]], null],
            'negative per extra kg' => [[['max' => 2, 'cost' => 1]], -0.5],
        ];

        foreach ($bad as $case => [$rates, $extra]) {
            $response = $this->saveRule(['weight_rates' => $rates, 'weight_extra_per_kg' => $extra]);

            $this->assertNotSame(200, $response->json('status'), $case . ' was saved');
            $this->assertArrayHasKey('weight_rates', (array)$response->json('data.fields'), $case);
        }

        $this->assertSame(0, ShippingPlace::where('country', 'PT')->count());
    }

    // ---- the product's side: its weight, set on the product form -----------

    private function saveProduct(array $over = [], $id = null)
    {
        return $this->postJson('/api/admin/product/action' . ($id ? "/{$id}" : ''), array_merge([
            'title' => 'QA Weighed ' . uniqid(),
            'short_heading' => 'Short heading',
            'slug' => 'qa-weighed-' . uniqid(),
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
        ], $over));
    }

    public function test_the_weight_saved_on_the_product_form_is_the_one_shipping_weighs()
    {
        $this->actAsAdmin();

        $created = $this->saveProduct(['weight' => '2.5']);
        $this->assertSame(200, $created->json('status'), json_encode($created->json()));
        $id = $created->json('data.id');

        $this->assertEquals(2.5, Product::where('id', $id)->value('weight'));
        $this->assertEquals(5.0, Shipping::linesWeight([['product_id' => $id, 'quantity' => 2]]));

        // edited on the form
        $this->actAsAdmin();
        $this->saveProduct(['weight' => '3.25'], $id)->assertJsonPath('status', 200);
        $this->assertEquals(3.25, Product::where('id', $id)->value('weight'));

        // a save that does not send the weight leaves it alone
        $this->actAsAdmin();
        $this->saveProduct(['title' => 'Renamed ' . uniqid()], $id)->assertJsonPath('status', 200);
        $this->assertEquals(3.25, Product::where('id', $id)->value('weight'));
    }
}
