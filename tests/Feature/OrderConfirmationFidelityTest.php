<?php
namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Helper\Pricing;
use App\Models\FlashDiscount;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderedProductsInventory;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserBusinessProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\Concerns\AccountsForStoreWideDiscount;
use Tests\TestCase;

/**
 * The order confirmation has to be the order.
 *
 * The reported defect: a business account confirmed a basket and was then
 * shown a different one. A variant listed at 699 and sold to the account at
 * 674 for ten units was confirmed at "Subtotal 6,990 / Product Discount −250"
 * and then displayed as "Subtotal 8,990 / Product Discount −2,250" — because
 * ordered_products_inventories.actual_price was written from products.selling
 * (899), the product-level figure, while the basket had measured the same
 * discount against the variant's own list price.
 *
 * The total agreed either way, which is why it survived: 8,990 − 2,250 and
 * 6,990 − 250 are the same 6,740. Only the two lines the customer reads to
 * understand what they saved were wrong, and both were wrong in the direction
 * that flatters the shop.
 *
 * So the assertions here are not "the arithmetic adds up". They are "every
 * figure on the confirmation is the figure the basket showed", and then "none
 * of them move when the catalogue does".
 */
class OrderConfirmationFidelityTest extends TestCase
{
    use DatabaseTransactions;
    use AccountsForStoreWideDiscount;

    private $product;
    private $inventory;
    private $place;

    // ------------------------------------------------------------- fixtures

    /**
     * A published, variant-priced product with business bands on it.
     *
     * Variant-priced deliberately: a product whose variants have no price of
     * their own cannot show this bug at all, because the variant list price and
     * products.selling are then the same number.
     */
    private function bandedProduct()
    {
        if ($this->product) {
            return $this->product;
        }

        $product = Product::whereHas('product_inventories', function ($query) {
                $query->where('price', '>', 0)
                    ->whereHas('inventory_attributes');
            })
            ->where('selling', '>', 0)
            ->where('status', config('constants.status.PUBLIC'))
            ->whereNotNull('shipping_rule_id')
            ->first();

        if (!$product) {
            $this->markTestSkipped('No variant-priced product to buy.');
        }

        $this->inventory = UpdatedInventory::where('product_id', $product->id)
            ->where('price', '>', 0)
            ->whereHas('inventory_attributes')
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->first();

        $this->place = optional($product->shipping_rule)->shipping_places()->first();

        if (!$this->inventory || !$this->place) {
            $this->markTestSkipped('The fixture product cannot be taken to checkout.');
        }

        // Rolled back with the transaction. Keeps a 10-unit basket from being
        // refused for stock rather than assessed for price.
        UpdatedInventory::where('id', $this->inventory->id)->update(['quantity' => 5000]);
        $this->inventory = $this->inventory->fresh();

        /*
         * The variant is priced away from the product, which is the whole
         * point of the fixture. If the data ever stops being so, say that
         * rather than passing vacuously.
         */
        $this->assertNotEqualsWithDelta(
            (float)$product->selling, (float)$this->inventory->price, 0.01,
            'the fixture variant must be priced away from products.selling, '
                . 'or this test cannot tell the two apart'
        );

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $wholesale = Pricing::retailUnitPrice(
            Product::pricingRecord($product->id), $this->inventory
        );

        foreach ([
            ['min' => 1,  'max' => 2,  'discount' => 5],
            ['min' => 3,  'max' => 10, 'discount' => 25],
            ['min' => 11, 'max' => 0,  'discount' => 50],
        ] as $band) {
            $tier = new BusinessProductPricing([
                'product_id' => $product->id,
                'min_qty' => $band['min'],
                'max_qty' => $band['max'],
                'wholesale_price' => $wholesale,
                'discount_type' => 'fixed',
                'discount_value' => $band['discount'],
            ]);
            $tier->final_price = $tier->computedFinalPrice();
            $tier->save();
        }

        return $this->product = $product->fresh();
    }

    private function customer($accountType = 'business', $attributes = [])
    {
        $user = User::create(array_merge([
            'name' => 'Confirmation Fixture',
            'email' => 'conf.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => $accountType,
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();

        if ($accountType !== 'personal') {
            UserBusinessProfile::create([
                'user_id' => $user->id,
                'company_name' => 'Murphy IT Solutions Limited',
                'vat_number' => 'IE1234567B',
                'cro_number' => '123456',
                'requires_po' => true,
            ]);
        }

        $address = UserAddress::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'country' => 'IE',
            'state' => 'D',
            'city' => 'Dublin',
            'zip' => 'D02X286',
            'address_1' => '26 Main Street',
            'type' => 1,
            'default' => 1,
        ]);

        $user->default_address = $address->id;
        $user->save();

        return $user->fresh();
    }

    private function fillBasket($quantity)
    {
        $product = $this->bandedProduct();

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => $quantity,
            'shipping_place_id' => $this->place->id,
            'shipping_type' => 1,
        ])->assertStatus(200);
    }

    /**
     * What the checkout summary is drawing from.
     *
     * CheckoutRight.vue reads exactly these two keys off each basket line —
     * `unit_price` for what is charged and `original_price` for the
     * struck-through figure — and builds Subtotal and Discount from them. So
     * this is the checkout screen's own arithmetic, in the order the component
     * does it.
     */
    private function checkoutSummary()
    {
        $rows = $this->getJson('/api/v1/cart/by-user')->json('data');

        $this->assertNotEmpty($rows, 'the basket is empty before checkout');

        $summary = ['subtotal' => 0.0, 'goods' => 0.0, 'tax' => 0.0, 'shipping' => 0.0];

        foreach ($rows as $row) {
            $quantity = (int)$row['quantity'];

            $summary['subtotal'] += (float)$row['original_price'] * $quantity;
            $summary['goods'] += (float)$row['unit_price'] * $quantity;

            $taxRule = $row['flash_product']['tax_rules'] ?? null;

            if ($taxRule && (int)($row['flash_product']['excludeVAT'] ?? 0) === 1) {
                $summary['tax'] += Pricing::unitTax(
                    (object)$taxRule, (float)$row['unit_price'], true
                ) * $quantity;
            }

            $summary['shipping'] += (float)($row['shipping_place']['price'] ?? 0);
        }

        $summary['discount'] = $summary['subtotal'] - $summary['goods'];

        /*
         * A store-wide flash discount is not a line, so nothing above can see
         * it -- and the checkout takes it off the goods anyway. Rebuilt here
         * from the same promotion and the same helper the order uses, or every
         * total below is out by whatever promotion happens to be running.
         */
        $summary['flash_discount'] = $this->storeWideDiscountOn($summary['goods']);

        $summary['total'] = $summary['goods'] + $summary['shipping']
            + $summary['tax'] - $summary['flash_discount'];

        return array_map([Pricing::class, 'round'], $summary);
    }

    /** The encrypted envelope /order/action expects. */
    private function payload(array $params = [])
    {
        $json = json_encode(array_merge([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ], $params));

        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt($json, 'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210'));
    }

    private function placeOrder(array $params = [])
    {
        return $this->postJson('/api/v1/order/action', [
            'data' => $this->payload($params),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);
    }

    /** The order as the confirmation screen receives it. */
    private function confirmation($orderId)
    {
        $response = $this->postJson('/api/v1/order/by-user', [
            'order_id' => $orderId,
            'time_zone' => 'Europe/Dublin',
        ]);

        $this->assertSame(200, $response->json('status'),
            'the confirmation screen could not load the order: '
                . json_encode($response->json('message')));

        return $response->json('data');
    }

    // ----------------------------------------- the store-wide discount, opt-in

    /**
     * The promotion is offered, not imposed.
     *
     * It used to apply to every order the moment one was live, whatever the
     * basket had shown the customer. These two are the pair that matters: an
     * order that does not ask is charged without it, and an order that asks
     * gets exactly what the live promotion is worth on the goods it is buying
     * -- a figure the browser never sends.
     */
    public function test_an_order_that_does_not_ask_is_not_given_the_flash_discount()
    {
        if (!FlashDiscount::current()) {
            $this->markTestSkipped('No live promotion to decline.');
        }

        $customer = $this->customer('personal');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);
        $this->placeOrder();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'the order was not placed');

        $this->assertSame(
            0, DB::table('ordered_flash_discount')->where('order_id', $order->id)->count(),
            'an order that never asked for the promotion was given it anyway'
        );
    }

    public function test_an_order_that_asks_receives_the_flash_discount()
    {
        $promotion = FlashDiscount::current();

        if (!$promotion) {
            $this->markTestSkipped('No live promotion to accept.');
        }

        $customer = $this->customer('personal');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);

        $goods = $this->checkoutSummary()['goods'];

        $expected = Pricing::flashDiscount($promotion, $goods);

        if ($expected <= 0) {
            $this->markTestSkipped('This basket does not qualify for the live promotion.');
        }

        $this->placeOrder(['apply_flash_discount' => 1]);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'the order was not placed');

        $this->assertEqualsWithDelta(
            $expected,
            (float)DB::table('ordered_flash_discount')->where('order_id', $order->id)->sum('discount'),
            0.02,
            'the order asked for the promotion and did not get what it is worth'
        );
    }

    /**
     * The client asks for the discount; it does not price it.
     *
     * A payload naming its own amount must change nothing, or the browser
     * could write its own discount into an order.
     */
    public function test_an_order_cannot_name_its_own_flash_discount()
    {
        $promotion = FlashDiscount::current();

        if (!$promotion) {
            $this->markTestSkipped('No live promotion to abuse.');
        }

        $customer = $this->customer('personal');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);

        $goods = $this->checkoutSummary()['goods'];

        $honest = Pricing::flashDiscount($promotion, $goods);

        if ($honest <= 0) {
            $this->markTestSkipped('This basket does not qualify for the live promotion.');
        }

        $this->placeOrder([
            'apply_flash_discount' => 1,
            'flash_discount' => 99999,
            'flash_discount_amount' => 99999,
        ]);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertEqualsWithDelta(
            $honest,
            (float)DB::table('ordered_flash_discount')->where('order_id', $order->id)->sum('discount'),
            0.02,
            'the browser priced its own discount'
        );
    }

    // ------------------------------------------- the reported defect, end to end

    public function test_a_business_order_confirms_the_figures_the_basket_showed()
    {
        $customer = $this->customer('business');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);

        // Step 5 of the brief: record what checkout shows before placing.
        $checkout = $this->checkoutSummary();

        $response = $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'checkout did not complete: '
            . json_encode($response->json('message')));

        $confirmation = $this->confirmation($order->id);
        $shown = $confirmation['calculations'];

        foreach (['subtotal', 'discount', 'shipping', 'tax', 'flash_discount', 'total'] as $row) {
            $this->assertEqualsWithDelta(
                $checkout[$row], (float)$shown[$row], 0.02,
                "the confirmation's $row is not the one the customer approved"
            );
        }

        // And the total the order was actually saved at.
        $this->assertEqualsWithDelta(
            (float)$order->total_amount, (float)$shown['total'], 0.02,
            'the confirmation total is not what the order was charged'
        );
    }

    public function test_the_discount_is_measured_against_the_variant_not_the_product()
    {
        $customer = $this->customer('business');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);

        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $stored = OrderedProductsInventory::where('order_id', $order->id)->first();

        $this->assertEqualsWithDelta(
            (float)$this->inventory->price, (float)$stored->actual_price, 0.01,
            'actual_price is the variant list price, not products.selling'
        );

        // The specific wrong value the defect wrote.
        $this->assertNotEqualsWithDelta(
            (float)$this->product->selling, (float)$stored->actual_price, 0.01,
            'actual_price fell back to the product-level selling price'
        );
    }

    public function test_the_quantity_band_actually_charged_is_the_one_stored()
    {
        $customer = $this->customer('business');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);

        $expected = Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($this->product->id),
            $this->inventory,
            10,
            BusinessProductPricing::where('product_id', $this->product->id)->get()
        ));

        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();
        $stored = OrderedProductsInventory::where('order_id', $order->id)->first();

        $this->assertEqualsWithDelta($expected, (float)$stored->price, 0.01,
            'the band price for quantity 10 is not what the order stored');

        $this->assertSame(10, (int)$stored->quantity);
    }

    // ------------------------------------------- the order must not move afterwards

    /**
     * Sections 16 and 19 of the brief, as one test: change everything the
     * catalogue can say about this product, then read the order back.
     */
    public function test_changing_the_catalogue_does_not_move_a_placed_order()
    {
        $customer = $this->customer('business');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);
        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();
        $before = $this->confirmation($order->id)['calculations'];

        // 1. the retail price, 2. the offer price
        Product::where('id', $this->product->id)->update([
            'selling' => (float)$this->product->selling + 400,
            'offered' => (float)$this->product->selling + 350,
        ]);

        // 3. the variant's own price
        UpdatedInventory::where('id', $this->inventory->id)
            ->update(['price' => (float)$this->inventory->price + 275]);

        // 4. every quantity band
        BusinessProductPricing::where('product_id', $this->product->id)
            ->update(['discount_value' => 400, 'final_price' => 1]);

        $after = $this->confirmation($order->id)['calculations'];

        $this->assertSame($before, $after,
            'a placed order changed because the catalogue did');
    }

    /**
     * Section 18. The entitlement decided the price; losing the entitlement
     * must not re-decide it.
     */
    public function test_suspending_the_account_does_not_move_a_placed_order()
    {
        $customer = $this->customer('business');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);
        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();
        $before = $this->confirmation($order->id)['calculations'];

        /*
         * Downgraded to a personal account: hasBusinessPricing() now answers
         * no, so anything re-pricing this order from the live account would
         * quote retail. Read back through the same signed-in session, because
         * a suspended account cannot sign in at all.
         */
        User::where('id', $customer->id)->update(['account_type' => 'personal']);

        $after = $this->confirmation($order->id)['calculations'];

        $this->assertSame($before, $after,
            'a placed order changed because the account type did');
    }

    /**
     * Section 11 and 17: the order remembers which kind of customer placed it.
     */
    public function test_the_order_keeps_the_account_it_was_placed_by()
    {
        $customer = $this->customer('business');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);
        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertSame('business', $order->account_type);
        $this->assertSame('Murphy IT Solutions Limited', $order->company_name);
        $this->assertSame('IE1234567B', $order->vat_number);
        $this->assertSame('123456', $order->cro_number);
        $this->assertSame('PO-2026-00125', $order->purchase_order_number);

        // Renaming the company does not rewrite the receipt.
        UserBusinessProfile::where('user_id', $customer->id)
            ->update(['company_name' => 'Something Else Ltd']);

        $confirmation = $this->confirmation($order->id);

        $this->assertSame('Murphy IT Solutions Limited', $confirmation['company_name']);
        $this->assertSame('business', $confirmation['account_type']);
        $this->assertSame('PO-2026-00125', $confirmation['purchase_order_number']);
    }

    /**
     * Section 17 again, from the other side: a personal account is charged
     * retail and its order says so.
     */
    public function test_a_personal_order_is_not_priced_at_the_business_band()
    {
        $customer = $this->customer('personal');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);

        $checkout = $this->checkoutSummary();

        $this->placeOrder();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a personal account could not check out');

        $shown = $this->confirmation($order->id)['calculations'];

        foreach (['subtotal', 'discount', 'tax', 'flash_discount', 'total'] as $row) {
            $this->assertEqualsWithDelta(
                $checkout[$row], (float)$shown[$row], 0.02,
                "a personal order's $row is not the one the customer approved"
            );
        }

        $stored = OrderedProductsInventory::where('order_id', $order->id)->first();

        $this->assertEqualsWithDelta(
            Pricing::retailUnitPrice(
                Product::pricingRecord($this->product->id), $this->inventory
            ),
            (float)$stored->price, 0.01,
            'a personal account was charged a business band price'
        );
    }

    // --------------------------------------------- the two screens must agree

    /**
     * The order detail screen and the order confirmation screen read two
     * different keys off the same payload. They used to be two different
     * calculations; they have to be one.
     */
    public function test_the_detail_screen_and_the_confirmation_screen_agree()
    {
        $customer = $this->customer('business');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);
        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $confirmation = $this->confirmation($order->id);

        // `calculations` feeds the confirmation, `calculated` the detail page.
        $this->assertEqualsWithDelta(
            (float)$confirmation['calculations']['total'],
            (float)$confirmation['calculated']['total_price'], 0.02,
            'the two screens show two different totals'
        );

        $this->assertEqualsWithDelta(
            (float)$confirmation['calculations']['subtotal'],
            (float)$confirmation['calculated']['original_subtotal'], 0.02,
            'the two screens show two different subtotals'
        );

        $this->assertEqualsWithDelta(
            (float)$confirmation['calculations']['discount'],
            (float)$confirmation['calculated']['discount'], 0.02,
            'the two screens show two different discounts'
        );
    }

    /**
     * Cash on delivery is unpaid until it is collected, and there is no
     * gateway reference to show. Section 9.
     */
    public function test_a_cash_order_carries_no_transaction_id()
    {
        $customer = $this->customer('business');

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket(10);
        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertSame(0, (int)$order->payment_done, 'cash on delivery is not paid');
        $this->assertEmpty($order->trans_id, 'a cash order invented a transaction id');
    }
}
