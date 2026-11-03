<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\Helper\OrderSummary;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\IsolatedTestCase;

/**
 * The basket, the checkout quote, the order and the confirmation agree to the
 * cent (2026-11-03).
 *
 * VAT is worked out per unit, rounded to the cent, and multiplied by the units
 * paid for - the rule the order has always charged by (Pricing::unitTax x
 * payable quantity). The basket used to round once per line instead, so an
 * ex-VAT EUR 10.03 x 3 was shown with 6.92 VAT against 6.93 charged, and a card
 * gateway refuses a cent's difference. Every expected figure below is worked
 * out by hand in the comment beside it, not by the code under test.
 *
 * The basket: an ex-VAT laptop repriced to EUR 10.03 (x3) and the inc-VAT
 * phone at EUR 500 (x2), collected for EUR 4.99.
 *
 *   laptop  10.03 + 2.31 VAT (23% of 10.03 = 2.3069) = 12.34;  x3 = 30.09 + 6.93 = 37.02
 *   phone   500.00 = 406.50 + 93.50 VAT (500 / 1.23 = 406.504);  x2 = 813.00 + 187.00
 *   goods   ex 843.09   VAT 193.93 (187.00 included + 6.93 added)   inc 1,037.02
 *   collection 4.99                                        payable 1,042.01
 */
class CheckoutVatConsistencyTest extends IsolatedTestCase
{
    private $customer;
    private $place;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('payments')->update(['bank' => 1]);

        $this->customer = User::findOrFail(Fixture::USER_PERSONAL_ID);

        DB::table('products')->where('id', Fixture::PRODUCT_LAPTOP_ID)
            ->update(['selling' => 10.03, 'offered' => 10.03]);

        $this->place = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('shipping_places')->where('id', $this->place)->update(['pickup_point' => 1, 'pickup_price' => 4.99]);

        Cart::where('user_id', $this->customer->id)->delete();
    }

    private function line($productId, $inventoryId, $quantity, array $extra = [])
    {
        return Cart::create(array_merge([
            'user_id' => $this->customer->id,
            'product_id' => $productId,
            'inventory_id' => $inventoryId,
            'quantity' => $quantity,
            'selected' => (int)config('constants.status.PUBLIC'),
            'shipping_place_id' => $this->place,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ], $extra));
    }

    private function mixedBasket(array $extra = [])
    {
        $this->line(Fixture::PRODUCT_LAPTOP_ID, Fixture::LAPTOP_INVENTORY_ID, 3, $extra);
        $this->line(Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID, 2, $extra);
    }

    private function basket(array $headers = [])
    {
        $response = $this->asCustomer($this->customer->id)->withHeaders($headers)
            ->getJson('/api/v1/cart/by-user');

        $this->assertApiOk($response);

        return collect($response->json('data'))->keyBy('product_id');
    }

    private function quote(array $choices = [])
    {
        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/checkout-total', $choices);
        $this->assertApiOk($response);

        return $response->json('data');
    }

    private function placeOrder(array $extra = [])
    {
        $method = (int)config('constants.paymentMethod.BANK');

        $json = json_encode(array_merge([
            'user_token' => null,
            'order_method' => $method,
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ], $extra));
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC',
                hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
                hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => $method,
        ]);

        $this->assertApiOk($response);

        return Order::with(OrderSummary::SUMMARY_RELATIONS)
            ->where('user_id', $this->customer->id)->orderByDesc('id')->firstOrFail();
    }

    private function cents($amount)
    {
        return (int)round((float)$amount * 100);
    }

    private function assertMoney($expected, $actual, $what)
    {
        $this->assertSame($this->cents($expected), $this->cents($actual), $what);
    }

    // ------------------------------------------------------- the rule itself

    public function test_vat_is_per_unit_rounded_to_the_cent_then_times_the_units()
    {
        $vat23 = (object)['type' => 2, 'price' => 23];

        $line = Pricing::lineVat(10.03, 3, $vat23, false);
        $this->assertMoney(2.31, $line['unit_vat'], '23% of 10.03 = 2.3069');
        $this->assertMoney(6.93, $line['line_vat'], '2.31 x 3, not 23% of 30.09 (6.92)');
        $this->assertMoney(37.02, $line['line_inc'], '30.09 + 6.93');
        $this->assertMoney(Pricing::unitTax($vat23, 10.03, true) * 3, $line['line_vat'], 'what the order charges');

        $inc = Pricing::lineVat(500, 2, $vat23, true);
        $this->assertMoney(406.50, $inc['unit_ex'], '500 / 1.23 = 406.504');
        $this->assertMoney(93.50, $inc['unit_vat'], 'the VAT inside 500');
        $this->assertMoney(1000, $inc['line_inc'], 'an inc-VAT price is never added to');

        $reduced = Pricing::lineVat(629, 11, (object)['type' => 2, 'price' => 13.5], false);
        $this->assertMoney(84.92, $reduced['unit_vat'], '13.5% of 629 = 84.915, half up');
        $this->assertMoney(934.12, $reduced['line_vat'], '84.92 x 11');

        $flat = Pricing::lineVat(100, 2, (object)['type' => 1, 'price' => 5], false);
        $this->assertSame(0.0, $flat['rate'], 'a flat rule has no percentage');
        $this->assertMoney(5, $flat['unit_vat'], 'but its tax is real');
        $this->assertMoney(210, $flat['line_inc'], '(100 + 5) x 2');

        $none = Pricing::lineVat(300, 1, (object)['type' => 2, 'price' => 0], true);
        $this->assertMoney(0, $none['unit_vat'], 'margin scheme: no VAT');
        $this->assertMoney(300, $none['line_inc'], 'and nothing added');

        $free = Pricing::lineVat(0, 3, $vat23, false);
        $this->assertMoney(0, $free['line_inc'], 'a free product stays free');
    }

    public function test_the_split_is_exact_where_a_float_would_not_be()
    {
        // 1.15 x 100 is 114.999... as a float; the split works in whole cents.
        $split = Pricing::vatBreakdown(1.15, 23, true);

        $this->assertMoney(1.15, $split['ex'] + $split['vat'], 'ex + VAT = the price, to the cent');
        $this->assertMoney(0.93, $split['ex'], '1.15 / 1.23 = 0.9349');
    }

    // ---------------------------- basket = quote = order = confirmation

    public function test_basket_quote_order_and_confirmation_agree_to_the_cent()
    {
        $this->mixedBasket();

        $basket = $this->basket();
        $laptop = $basket[Fixture::PRODUCT_LAPTOP_ID];
        $phone = $basket[Fixture::PRODUCT_PHONE_ID];

        // The ex-VAT line, as the card prints it.
        $this->assertMoney(10.03, $laptop['unit_ex_vat'], 'laptop unit excl. VAT');
        $this->assertMoney(2.31, $laptop['unit_vat'], 'laptop VAT per unit');
        $this->assertMoney(12.34, $laptop['unit_inc_vat'], 'laptop unit incl. VAT');
        $this->assertMoney(6.93, $laptop['line_vat'], 'laptop VAT: 2.31 x 3');
        $this->assertMoney(37.02, $laptop['line_inc_vat'], 'laptop total incl. VAT');
        $this->assertFalse((bool)$laptop['vat_inclusive']);

        // The inc-VAT line: split, never added to.
        $this->assertMoney(406.50, $phone['unit_ex_vat'], 'phone unit excl. VAT');
        $this->assertMoney(93.50, $phone['unit_vat'], 'phone VAT inside per unit');
        $this->assertMoney(1000, $phone['line_inc_vat'], 'phone total = its price x 2');
        $this->assertTrue((bool)$phone['vat_inclusive']);

        // What the summary adds up (Σ of those lines) and the server's quote.
        $basketGoods = $this->cents($laptop['line_inc_vat']) + $this->cents($phone['line_inc_vat']);
        $this->assertSame(103702, $basketGoods, 'goods incl. VAT: 1,037.02');

        $quote = $this->quote();
        $this->assertMoney(1030.09, $quote['goods'], 'goods before VAT is added: 30.09 + 1,000.00');
        $this->assertMoney(6.93, $quote['tax'], 'VAT added');
        $this->assertMoney(4.99, $quote['shipping'], 'collection');
        $this->assertMoney(1042.01, $quote['total'], 'the quote');
        $this->assertMoney(($basketGoods / 100) + 4.99, $quote['total'], 'the quote = the summary');

        $order = $this->placeOrder();
        $this->assertMoney(1042.01, $order->total_amount, 'the order charges the quote');

        $summary = OrderSummary::for($order);
        $this->assertMoney(1042.01, $summary['total'], 'the confirmation total');
        $this->assertMoney(843.09, $summary['ex_vat'], 'confirmation net: 30.09 + 813.00');
        $this->assertMoney(193.93, $summary['vat'], 'confirmation VAT: 6.93 + 187.00');
        $this->assertMoney(1037.02, $summary['inc_vat'], 'confirmation goods incl. VAT');
        $this->assertMoney(6.93, $summary['tax'], 'VAT the order added');
        $this->assertMoney($summary['ex_vat'] + $summary['vat'], $summary['inc_vat'], 'ex + VAT = inc');
    }

    public function test_every_order_wide_discount_lands_on_the_same_total_everywhere()
    {
        $now = now();

        // Voucher: 10% of the goods (1,030.09) = 103.009 -> 103.01.
        DB::table('vouchers')->insert([
            'title' => 'Ten', 'type' => (int)config('constants.priceType.PERCENT'), 'status' => 1,
            'usage_limit' => 100, 'limit_per_customer' => 10, 'price' => 10, 'min_spend' => 0,
            'code' => 'TEN', 'start_time' => $now->copy()->subDay(), 'end_time' => $now->copy()->addDay(),
            'admin_id' => Fixture::ADMIN_SUPER_ID, 'apply_type' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Store-wide flash discount: 5% of 1,030.09 = 51.5045 -> 51.50.
        DB::table('flash_discounts')->insert([
            'type' => 'percentage', 'value' => 5, 'min_cart_value' => 0, 'max_discount' => 0,
            'start_time' => $now->copy()->subDay(), 'end_time' => $now->copy()->addDay(), 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Essentials Bundle of the two products: EUR 20 off the set.
        $bundleId = DB::table('bundle_deals')->insertGetId([
            'title' => 'Desk set', 'discount_type' => 'fixed', 'discount_value' => 20,
            'buy' => 0, 'free' => 0, 'admin_id' => Fixture::ADMIN_SUPER_ID,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('bundle_deal_products')->insert([
            ['bundle_deal_id' => $bundleId, 'product_id' => Fixture::PRODUCT_LAPTOP_ID],
            ['bundle_deal_id' => $bundleId, 'product_id' => Fixture::PRODUCT_PHONE_ID],
        ]);

        // Loyalty group: 5% of 1,030.09 = 51.50.
        $groupId = DB::table('loyalty_groups')->insertGetId([
            'title' => 'Regulars', 'status' => 1, 'discount_type' => 'percentage', 'discount_value' => 5,
            'min_cart_value' => 0, 'max_discount' => 0, 'validity' => 'one_time',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('loyalty_group_users')->insert([
            'loyalty_group_id' => $groupId, 'user_id' => $this->customer->id, 'joined_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->mixedBasket(['bundle_deal_id' => $bundleId]);

        // 1,042.01 - 103.01 - 51.50 - 20.00 - 51.50 = 816.00
        $choices = ['voucher' => 'TEN', 'apply_flash_discount' => 1];

        $quote = $this->quote($choices);
        $this->assertMoney(103.01, $quote['voucher'], 'voucher');
        $this->assertMoney(51.50, $quote['flash_discount'], 'flash discount');
        $this->assertMoney(20, $quote['bundle_discount'], 'Essentials Bundle');
        $this->assertMoney(51.50, $quote['loyalty_discount'], 'loyalty');
        $this->assertMoney(816.00, $quote['total'], 'the quote');

        // The browser's loyalty preview reads the server's subtotal, not the one it sends.
        $preview = $this->asCustomer($this->customer->id)
            ->getJson('/api/v1/user/loyalty-discount?subtotal=99999');
        $this->assertMoney(51.50, $preview->json('amount'), 'loyalty preview = what the order takes');

        $order = $this->placeOrder($choices);
        $this->assertMoney(816.00, $order->total_amount, 'the order');
        $this->assertMoney(816.00, OrderSummary::for($order)['total'], 'the confirmation');
    }

    // ----------------------------------------------------- the gateways

    public function test_sumup_is_asked_for_the_server_total_not_the_page_total()
    {
        DB::table('payments')->update([
            'sumup_api_key' => 'sk_fake', 'sumup_merchant_email' => 'shop@fixture.test',
            'sumup_merchant_code' => 'MFAKE',
        ]);

        $this->mixedBasket();

        $sent = null;
        $this->fakeHttp(['api.sumup.com/*' => function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response(['id' => 'chk_1', 'checkout_reference' => $sent['checkout_reference'], 'amount' => $sent['amount']]);
        }]);

        // The page says 1,000.00; the basket is 1,042.01.
        $response = $this->asCustomer($this->customer->id)->postJson('/api/create-sumup-checkout', [
            'amount' => 1000.00, 'currency' => 'EUR',
        ]);

        $response->assertOk();
        $this->assertMoney(1042.01, $sent['amount'], 'SumUp is asked for the quote');
        $this->assertMoney(1042.01, $response->json('charged_amount'), 'and the page is told so');
        $this->assertMoney($this->quote()['amount_due'], $sent['amount'], 'which is the order total');
    }

    // ---------------------------------------- free units, clearance, flat

    public function test_free_units_are_not_charged_or_taxed_on_the_confirmation()
    {
        // A legacy "buy 2 get 1 free" deal on the inc-VAT phone.
        $dealId = DB::table('bundle_deals')->insertGetId([
            'title' => 'Three for two', 'discount_type' => 'fixed', 'discount_value' => 0,
            'buy' => 2, 'free' => 1, 'admin_id' => Fixture::ADMIN_SUPER_ID,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->where('id', Fixture::PRODUCT_PHONE_ID)->update(['bundle_deal_id' => (string)$dealId]);

        $this->line(Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID, 3);

        // 3 held, 2 paid for: 1,000.00 incl. 187.00 VAT (93.50 x 2).
        $phone = $this->basket()[Fixture::PRODUCT_PHONE_ID];
        $this->assertSame(1, (int)$phone['free_units']);
        $this->assertMoney(1000, $phone['line_inc_vat'], 'two paid units');
        $this->assertMoney(187, $phone['line_vat'], 'VAT on the paid units only');
        $this->assertMoney(1000, $phone['line_goods'], 'the flash discount base');

        $order = $this->placeOrder();
        $this->assertMoney(1004.99, $order->total_amount, '1,000.00 + 4.99');

        $summary = OrderSummary::for($order);
        $line = $summary['lines'][0];

        $this->assertMoney(187, $line['vat'], 'not 280.49, the VAT inside three units');
        $this->assertMoney(813, $line['ex_vat'], 'the net of the two paid units');
        $this->assertMoney(1000, $line['inc_vat'], 'the line as charged');
        $this->assertMoney(187, $summary['vat'], 'order VAT');
        $this->assertMoney(813, $summary['ex_vat'], 'order net');
        $this->assertMoney(1004.99, $summary['total'], 'the confirmation total');
    }

    public function test_a_clearance_price_does_not_move_with_the_language()
    {
        DB::table('clearances')->insert([
            'product_id' => Fixture::PRODUCT_PHONE_ID, 'title' => 'Clear', 'discount_type' => 'percentage',
            'discount_value' => 10, 'end_time' => null, 'status' => 1, 'sort_order' => 0,
            'admin_id' => Fixture::ADMIN_SUPER_ID, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->line(Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID, 1);

        $default = $this->basket()[Fixture::PRODUCT_PHONE_ID];
        $french = $this->basket(['language' => 'fr'])[Fixture::PRODUCT_PHONE_ID];

        $this->assertMoney(450, $default['unit_price'], '10% off 500');
        $this->assertMoney(450, $french['unit_price'], 'the same in French');
        $this->assertMoney($default['line_inc_vat'], $french['line_inc_vat'], 'and the same line');

        $this->assertMoney(454.99, $this->placeOrder()->total_amount, 'and the order charges it');
    }

    public function test_a_flat_amount_tax_rule_is_shown_and_charged_the_same()
    {
        $flatId = DB::table('tax_rules')->insertGetId([
            'title' => 'Levy', 'type' => (int)config('constants.priceType.FLAT'), 'price' => 5,
            'admin_id' => Fixture::ADMIN_SUPER_ID, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->where('id', Fixture::PRODUCT_LAPTOP_ID)->update(['tax_rule_id' => $flatId]);

        $this->line(Fixture::PRODUCT_LAPTOP_ID, Fixture::LAPTOP_INVENTORY_ID, 3);

        // 10.03 + 5.00 a unit, x3: 30.09 + 15.00 = 45.09
        $laptop = $this->basket()[Fixture::PRODUCT_LAPTOP_ID];
        $this->assertMoney(5, $laptop['vat_flat'], 'the flat amount');
        $this->assertMoney(15, $laptop['line_vat'], 'shown as tax');
        $this->assertMoney(45.09, $laptop['line_inc_vat'], 'the line incl. the levy');

        $order = $this->placeOrder();
        $this->assertMoney(50.08, $order->total_amount, '45.09 + 4.99: charged as shown');
        $this->assertMoney(15, OrderSummary::for($order)['vat'], 'and stated on the confirmation');
    }
}
