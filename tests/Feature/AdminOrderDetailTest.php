<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessProductPricing;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserBusinessProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * What the admin order screens are allowed to say about an order.
 *
 * Two classes of defect these cover.
 *
 * The admin view was showing *less* than the customer's own confirmation. It
 * had no access to the per-line list price, so a business order's quantity-band
 * discount was invisible: the screen showed "€674" with nothing saying it had
 * been €699, and the summary jumped from a Subtotal to a Total with no
 * discount line between them. Whoever answered the phone about that order could
 * not see what the customer had been charged for.
 *
 * And it was showing the wrong thing about the customer. Name and phone were
 * read from the delivery address alone, so every click-and-collect order —
 * which has no address, because nothing is being posted — displayed "N/A" for a
 * customer whose name and number were sitting on their account.
 */
class AdminOrderDetailTest extends TestCase
{
    use DatabaseTransactions;

    private $product;
    private $inventory;
    private $place;

    // ------------------------------------------------------------- fixtures

    private function admin()
    {
        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    private function bandedProduct()
    {
        if ($this->product) {
            return $this->product;
        }

        $product = Product::whereHas('product_inventories', function ($query) {
                $query->where('price', '>', 0)->whereHas('inventory_attributes');
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

        UpdatedInventory::where('id', $this->inventory->id)->update(['quantity' => 5000]);
        $this->inventory = $this->inventory->fresh();

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $tier = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => Pricing::retailUnitPrice(
                Product::pricingRecord($product->id), $this->inventory
            ),
            'discount_type' => 'fixed',
            'discount_value' => 25,
        ]);
        $tier->final_price = $tier->computedFinalPrice();
        $tier->save();

        return $this->product = $product->fresh();
    }

    /**
     * A business customer with no delivery address, who collects.
     *
     * This is the shape that produced the "N/A" bug: the name and the phone
     * exist on the account and nowhere else.
     */
    private function collectingCustomer()
    {
        $user = User::create([
            'name' => 'Daniel J Murphy',
            'email' => 'admin.order.' . uniqid() . '@example.test',
            'phone' => '0871234567',
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'business',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();

        UserBusinessProfile::create([
            'user_id' => $user->id,
            'company_name' => 'Murphy IT Solutions Limited',
            'vat_number' => 'IE1234567B',
            'cro_number' => '123456',
            'sector' => 'Professional services / office',
            'requires_po' => true,
        ]);

        return $user;
    }

    private function deliveringCustomer()
    {
        $user = $this->collectingCustomer();

        $address = UserAddress::create([
            'user_id' => $user->id,
            'name' => 'Accounts Payable',
            'email' => $user->email,
            'phone' => '0879999999',
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

    /** Place an order as this customer and return it. */
    private function placeOrder($customer, $quantity = 3, $shippingType = 2)
    {
        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $this->bandedProduct()->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => $quantity,
            'shipping_place_id' => $this->place->id,
            'shipping_type' => $shippingType,
        ])->assertStatus(200);

        $json = json_encode([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
            'purchase_order_number' => 'PO-2026-00125',
        ]);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        $response = $this->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC',
                hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
                hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'checkout did not complete: '
            . json_encode($response->json('message')));

        return $order;
    }

    /** The order as the admin detail screen receives it. */
    private function adminDetail($orderId)
    {
        $this->admin();

        $response = $this->getJson("/api/admin/order/find/{$orderId}?time_zone=Europe/Dublin");

        $this->assertSame(200, $response->json('status'),
            'admin could not open the order: ' . json_encode($response->json('message')));

        return $response->json('data');
    }

    // ------------------------------------------- the customer must be named

    public function test_a_collection_order_names_the_customer_from_their_account()
    {
        $customer = $this->collectingCustomer();
        $order = $this->placeOrder($customer);

        $this->assertNull($order->user_address_id, 'a collection order has no address');

        $detail = $this->adminDetail($order->id);

        $this->assertSame('Daniel J Murphy', $detail['customer']['name'],
            'the name is on the account; the screen showed N/A');
        $this->assertSame('0871234567', $detail['customer']['phone'],
            'the phone is on the account; the screen showed N/A');
        $this->assertSame($customer->email, $detail['customer']['email']);
    }

    public function test_the_admin_payload_does_not_carry_the_whole_user_record()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $detail = $this->adminDetail($order->id);

        // The users row used to be serialised whole beside the order.
        $this->assertArrayNotHasKey('user', $detail);

        foreach (['password', 'two_factor_secret', 'code', 'remember_token'] as $secret) {
            $this->assertArrayNotHasKey($secret, $detail['customer'],
                "$secret must never reach the browser");
        }
    }

    // --------------------------------------------- account type and company

    public function test_the_account_type_comes_off_the_order_not_the_live_profile()
    {
        $customer = $this->collectingCustomer();
        $order = $this->placeOrder($customer);

        $detail = $this->adminDetail($order->id);

        $this->assertSame('business', $detail['customer']['account_type']);
        $this->assertSame('Murphy IT Solutions Limited', $detail['customer']['company_name']);
        $this->assertSame('IE1234567B', $detail['customer']['vat_number']);
        $this->assertSame('123456', $detail['customer']['cro_number']);
        $this->assertTrue($detail['customer']['from_order_snapshot']);

        // Downgrade the account and rename the company; the order must not move.
        User::where('id', $customer->id)->update(['account_type' => 'personal']);
        UserBusinessProfile::where('user_id', $customer->id)
            ->update(['company_name' => 'Something Else Ltd']);

        $after = $this->adminDetail($order->id);

        $this->assertSame('business', $after['customer']['account_type'],
            'a downgraded account rewrote a historical business order');
        $this->assertSame('Murphy IT Solutions Limited', $after['customer']['company_name']);
    }

    public function test_the_purchase_order_number_reaches_the_admin_screen()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $this->assertSame('PO-2026-00125',
            $this->adminDetail($order->id)['purchase_order_number']);
    }

    // ------------------------------------------------ the money on the order

    public function test_each_line_carries_its_list_price_and_its_discount()
    {
        $order = $this->placeOrder($this->collectingCustomer(), 3);

        $line = $this->adminDetail($order->id)['ordered_products'][0];

        $this->assertEqualsWithDelta((float)$this->inventory->price,
            (float)$line['actual_price'], 0.01, 'the list price the discount is measured against');

        $this->assertEqualsWithDelta((float)$this->inventory->price - 25,
            (float)$line['price'], 0.01, 'the band price actually charged');

        $this->assertEqualsWithDelta(75.0, (float)$line['line_discount'], 0.01,
            '3 units x 25 off; the admin screen showed no discount at all');

        $this->assertEqualsWithDelta(((float)$this->inventory->price - 25) * 3,
            (float)$line['line_total'], 0.01);

        $this->assertArrayHasKey('tax_total', $line);
    }

    public function test_the_admin_summary_is_the_customers_summary()
    {
        $order = $this->placeOrder($this->collectingCustomer(), 3);

        $admin = $this->adminDetail($order->id)['calculations'];

        // The same order, read back through the customer's own endpoint.
        Passport::actingAs($order->user, ['user'], 'user');

        $customer = $this->postJson('/api/v1/order/by-user', [
            'order_id' => $order->id,
            'time_zone' => 'Europe/Dublin',
        ])->json('data.calculations');

        $this->assertSame($customer, $admin,
            'the admin and the customer are looking at two different sums');
    }

    /**
     * The gross summary reconciles, in the order it is read on screen:
     *
     *   Products (Inc-VAT) - Discount        = Net Products (Inc-VAT)
     *   Net Products - order-wide discounts + shipping = Total (Inc-VAT)
     *
     * "VAT included" is deliberately absent from the second identity. It is
     * the tax contained in the goods, charged before the order-wide discounts
     * come off the gross — adding it to the total would count it twice, and
     * stating it against the total would overstate it by the VAT on whatever
     * the voucher took off.
     */
    public function test_the_gross_summary_reconciles()
    {
        $order = $this->placeOrder($this->collectingCustomer(), 3);

        $c = $this->adminDetail($order->id)['calculations'];

        $this->assertEqualsWithDelta(
            (float)$c['subtotal_inc_vat'] - (float)$c['discount_inc_vat'],
            (float)$c['inc_vat'],
            0.005,
            'Products (Inc-VAT) - Discount must equal Net Products (Inc-VAT)'
        );

        $this->assertEqualsWithDelta(
            (float)$c['inc_vat']
                - (float)$c['order_wide_discount']
                + (float)$c['shipping'],
            (float)$c['total'],
            0.02,
            'Net Products less the order-wide discounts must equal the Total'
        );

        // The VAT is inside the goods, so it can never exceed them.
        $this->assertLessThanOrEqual((float)$c['inc_vat'], (float)$c['vat']);
    }

    public function test_the_summary_rows_add_up_to_the_charged_total()
    {
        $order = $this->placeOrder($this->collectingCustomer(), 3);

        $c = $this->adminDetail($order->id)['calculations'];

        $reconciled = (float)$c['subtotal']
            - (float)$c['discount']
            - (float)$c['bundle_offer']
            + (float)$c['shipping']
            + (float)$c['tax']
            - (float)$c['voucher']
            - (float)$c['flash_discount']
            - (float)$c['loyalty_discount']
            - (float)$c['points_discount'];

        $this->assertEqualsWithDelta($reconciled, (float)$c['total'], 0.02,
            'the rows shown do not produce the total shown');

        $this->assertEqualsWithDelta((float)$order->total_amount, (float)$c['total'], 0.02,
            'the total shown is not what the order was charged');
    }

    public function test_a_discount_is_never_silently_dropped()
    {
        $order = $this->placeOrder($this->collectingCustomer(), 3);

        $c = $this->adminDetail($order->id)['calculations'];

        $this->assertGreaterThan(0, (float)$c['discount'],
            'the band discount vanished between checkout and the admin screen');

        $this->assertEqualsWithDelta(
            (float)$c['subtotal'] - (float)$c['discount'],
            (float)$this->adminDetail($order->id)['calculated']['subtotal'], 0.02,
            'the goods at list price less the discount is the goods as charged'
        );
    }

    // --------------------------------------------------- status and workflow

    public function test_the_screen_is_told_which_moves_are_legal()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $detail = $this->adminDetail($order->id);

        $this->assertSame(
            array_values(Order::allowedTransitions()[(int)$order->status]),
            $detail['allowed_transitions'],
            'the screen must not offer a move the server will refuse'
        );
    }

    public function test_an_illegal_status_move_is_refused()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $this->admin();

        // Pending straight to Delivered is not a legal move.
        $response = $this->postJson('/api/admin/order/update-status', [
            'id' => $order->id,
            'status' => (int)config('constants.orderStatus.DELIVERED'),
        ]);

        $this->assertNotSame(200, $response->json('status'),
            'an invalid transition was accepted');

        $this->assertSame((int)config('constants.orderStatus.PENDING'),
            (int)$order->fresh()->status, 'the order moved anyway');
    }

    public function test_the_audit_trail_records_who_changed_the_status()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $admin = $this->admin();

        $moved = $this->postJson('/api/admin/order/update-status', [
            'id' => $order->id,
            'status' => (int)config('constants.orderStatus.CONFIRMED'),
        ]);

        $this->assertSame(200, $moved->json('status'),
            'the admin could not confirm the order: ' . json_encode($moved->json('message')));

        $trail = $this->adminDetail($order->id)['status_trail'];

        // The order being placed, then the admin confirming it.
        $this->assertCount(2, $trail);

        $this->assertSame((int)config('constants.orderStatus.PENDING'), $trail[0]['to_status']);
        $this->assertSame('customer', $trail[0]['by']);

        $this->assertSame((int)config('constants.orderStatus.CONFIRMED'), $trail[1]['to_status']);
        $this->assertSame('admin', $trail[1]['by']);
        $this->assertSame($admin->name, $trail[1]['by_name']);
        $this->assertNotEmpty($trail[1]['at']);
    }

    // ------------------------------------------------------------- payments

    public function test_a_cash_order_reports_unpaid_with_no_transaction_id()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $detail = $this->adminDetail($order->id);

        $this->assertSame((int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            (int)$detail['order_method']);
        $this->assertSame(0, (int)$detail['payment_done']);
        $this->assertEmpty($detail['trans_id'], 'a cash order invented a transaction id');
    }

    // ------------------------------------------------------------------ VAT

    /**
     * The order records how it was treated for VAT, so the ex/VAT/inc split
     * can be made from what it charged rather than from the product's tax
     * rule as it stands today.
     */
    public function test_each_line_records_the_vat_treatment_it_was_charged_at()
    {
        $order = $this->placeOrder($this->collectingCustomer(), 3);

        $line = $this->adminDetail($order->id)['ordered_products'][0];

        $this->assertArrayHasKey('vat_known', $line);
        $this->assertTrue($line['vat_known'], 'the treatment was not recorded');

        $product = $this->bandedProduct();
        $inclusive = (int)$product->excludeVAT !== 1;

        $this->assertSame($inclusive, (bool)$line['vat_inclusive'],
            'the line disagrees with the product about which convention applied');

        // ex + VAT = inc, on the line the customer is billed for.
        $this->assertEqualsWithDelta(
            (float)$line['inc_vat'],
            (float)$line['ex_vat'] + (float)$line['vat'],
            0.01,
            'the line does not add up'
        );

        /*
         * And the split is the right way round: an ex-VAT price is its own net
         * figure, an inclusive one is smaller than what was charged.
         */
        if ($inclusive) {
            $this->assertLessThan((float)$line['line_total'], (float)$line['ex_vat']);
            $this->assertEqualsWithDelta((float)$line['line_total'], (float)$line['inc_vat'], 0.01);
        } else {
            $this->assertEqualsWithDelta((float)$line['line_total'], (float)$line['ex_vat'], 0.01);
            $this->assertGreaterThanOrEqual((float)$line['line_total'], (float)$line['inc_vat']);
        }
    }

    public function test_the_order_reports_its_vat_broken_down_by_rate()
    {
        $order = $this->placeOrder($this->collectingCustomer(), 3);

        $c = $this->adminDetail($order->id)['calculations'];

        $this->assertArrayHasKey('vat_breakdown', $c);
        $this->assertNotEmpty($c['vat_breakdown'], 'no rate rows for an order that has lines');

        $net = 0.0;
        $vat = 0.0;

        foreach ($c['vat_breakdown'] as $row) {
            $this->assertArrayHasKey('rate', $row);
            $net += (float)$row['net'];
            $vat += (float)$row['vat'];
        }

        // The per-rate rows are the order, not a sample of it.
        $this->assertEqualsWithDelta((float)$c['vat'], $vat, 0.02,
            'the rate rows do not sum to the order VAT');
        $this->assertEqualsWithDelta((float)$c['ex_vat'], $net, 0.02,
            'the rate rows do not sum to the order net');
    }

    /**
     * Section 24 and 25: an order's VAT is a historical fact. Changing the
     * rate, the rule or the product afterwards must not restate it.
     */
    public function test_changing_the_tax_rule_afterwards_does_not_move_a_placed_order()
    {
        $order = $this->placeOrder($this->collectingCustomer(), 3);

        $before = $this->adminDetail($order->id);

        $product = $this->bandedProduct();

        // Double the rate, and flip the product to the other convention.
        \App\Models\TaxRules::where('id', $product->tax_rule_id)->update(['price' => 46]);
        \App\Models\Product::where('id', $product->id)
            ->update(['excludeVAT' => (int)$product->excludeVAT === 1 ? 0 : 1]);

        $after = $this->adminDetail($order->id);

        $this->assertSame($before['calculations'], $after['calculations'],
            'a rate change rewrote a placed order');

        $this->assertSame(
            $before['ordered_products'][0]['vat'],
            $after['ordered_products'][0]['vat'],
            'a rate change rewrote the VAT on a placed line'
        );

        $this->assertSame(
            $before['ordered_products'][0]['vat_inclusive'],
            $after['ordered_products'][0]['vat_inclusive'],
            'a product-level change rewrote which convention a placed line used'
        );
    }

    // ------------------------------------------------- marking an order paid

    public function test_marking_an_order_paid_records_when()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $this->assertNull($order->paid_at, 'an unpaid order has no payment time');

        $this->admin();

        $response = $this->postJson('/api/admin/order/payment-status', [
            'id' => $order->id,
            'payment_done' => 1,
            'time_zone' => 'Europe/Dublin',
        ]);

        $this->assertSame(200, $response->json('status'),
            json_encode($response->json('message')));

        $fresh = $order->fresh();

        $this->assertSame(1, (int)$fresh->payment_done);
        $this->assertNotNull($fresh->paid_at, 'nothing recorded when it was paid');

        $this->assertNotEmpty($response->json('data.paid_at'),
            'the screen was not told the payment time');

        $this->assertNotEmpty($this->adminDetail($order->id)['paid_on']);
    }

    /**
     * Reversible on purpose: a mistaken mark, a bounced transfer and a
     * chargeback all need the flag moved back.
     */
    public function test_an_order_can_be_marked_unpaid_again_and_the_time_clears()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $this->admin();

        $this->postJson('/api/admin/order/payment-status', [
            'id' => $order->id, 'payment_done' => 1,
        ]);

        $response = $this->postJson('/api/admin/order/payment-status', [
            'id' => $order->id, 'payment_done' => 0,
        ]);

        $this->assertSame(200, $response->json('status'));

        // The stored value, so a screen binding to it cannot drift.
        $this->assertSame(0, $response->json('data.payment_done'));

        $fresh = $order->fresh();

        $this->assertSame(0, (int)$fresh->payment_done);
        $this->assertNull($fresh->paid_at,
            'an unpaid order kept a stale payment date');
    }

    public function test_a_cancelled_order_cannot_be_marked_paid()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        // `cancelled` is set by the cancellation flow, not mass-assigned.
        $order->forceFill(['cancelled' => 1])->save();

        $this->admin();

        $response = $this->postJson('/api/admin/order/payment-status', [
            'id' => $order->id, 'payment_done' => 1,
        ]);

        $this->assertNotSame(200, $response->json('status'),
            'money was recorded against a cancelled order');

        $this->assertSame(0, (int)$order->fresh()->payment_done);
    }

    /**
     * Cash is handed over at the door, so delivering a cash order is the moment
     * it is paid — and it should record when.
     */
    public function test_delivering_a_cash_order_records_the_payment_time()
    {
        $order = $this->placeOrder($this->collectingCustomer());

        $this->admin();

        foreach ([
            config('constants.orderStatus.CONFIRMED'),
            config('constants.orderStatus.PICKED_UP'),
            config('constants.orderStatus.DELIVERED'),
        ] as $status) {
            $this->postJson('/api/admin/order/update-status', [
                'id' => $order->id, 'status' => (int)$status,
            ]);
        }

        $fresh = $order->fresh();

        $this->assertSame((int)config('constants.orderStatus.DELIVERED'), (int)$fresh->status);
        $this->assertSame(1, (int)$fresh->payment_done, 'cash on delivery was not marked paid');
        $this->assertNotNull($fresh->paid_at, 'no payment time recorded on handover');
    }

    /**
     * Savers Club's Cherry Points tile, end to end: delivering a cash order
     * earns 1 point per whole euro paid, worth 1 cent each, and a refund
     * takes them back. Nothing else in the suite drove a real delivery, and
     * the dev database had never had a single points row.
     */
    public function test_delivering_an_order_earns_the_points_savers_club_shows_and_a_refund_takes_them_back()
    {
        $customer = $this->collectingCustomer();
        $order = $this->placeOrder($customer);
        $expected = (int)floor((float)$order->fresh()->total_amount);
        $this->assertGreaterThan(0, $expected);

        $points = function () use ($customer) {
            $this->app['auth']->forgetGuards();
            Passport::actingAs($customer, ['user'], 'user');
            return $this->getJson('/api/v1/user/points')->json();
        };

        $this->assertSame(0, $points()['points'], 'points before delivery');

        $move = function ($status) use ($order) {
            $this->app['auth']->forgetGuards();
            $this->admin();
            $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => (int)$status]);
        };

        $move(config('constants.orderStatus.CONFIRMED'));
        $move(config('constants.orderStatus.PICKED_UP'));
        $before = $points();
        $this->assertSame(0, $before['points'], 'points before the order is delivered');
        // Savers Club shows them as on the way, the same figure delivery will credit
        $this->assertSame($expected, $before['pending_points']);
        $this->assertSame(1, $before['pending_orders']);

        $move(config('constants.orderStatus.DELIVERED'));
        $after = $points();
        $this->assertSame($expected, $after['points']);
        $this->assertSame(number_format($expected * 0.01, 2, '.', ''), $after['value']);
        $this->assertSame(0, $after['pending_points'], 'delivered points are no longer on the way');

        // delivering twice must not pay twice
        $move(config('constants.orderStatus.DELIVERED'));
        $this->assertSame($expected, $points()['points']);

        $move(config('constants.orderStatus.REFUNDED'));
        $this->assertSame(0, $points()['points'], 'a refund should take the points back');
    }

    /**
     * Points earned on an order, then mostly spent on another before the first
     * is refunded: the refund takes back only what is left, and the rest is
     * forgiven. It used to leave a shortfall that showed as a negative
     * balance and quietly swallowed the next points earned.
     */
    public function test_a_refund_forgives_earned_points_already_spent_elsewhere()
    {
        $customer = $this->collectingCustomer();
        $order = $this->placeOrder($customer);
        $earned = (int)floor((float)$order->fresh()->total_amount);
        $this->assertGreaterThan(100, $earned);

        $move = function ($status) use ($order) {
            $this->app['auth']->forgetGuards();
            $this->admin();
            $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => (int)$status]);
        };
        $points = function () use ($customer) {
            $this->app['auth']->forgetGuards();
            Passport::actingAs($customer, ['user'], 'user');
            return $this->getJson('/api/v1/user/points')->json('points');
        };

        $move(config('constants.orderStatus.CONFIRMED'));
        $move(config('constants.orderStatus.PICKED_UP'));
        $move(config('constants.orderStatus.DELIVERED'));
        $this->assertSame($earned, $points());

        // all but 100 of them used on another order
        \App\Models\PointTransaction::create([
            'user_id' => $customer->id, 'type' => 'debit', 'points' => $earned - 100,
            'value' => ($earned - 100) * 0.01, 'source' => 'order', 'reference_id' => null,
        ]);
        $this->assertSame(100, $points());

        $move(config('constants.orderStatus.REFUNDED'));

        $reversal = \App\Models\PointTransaction::where('reference_id', $order->id)
            ->where('source', 'order_reversal')->get();
        $this->assertCount(1, $reversal);
        $this->assertSame('debit', $reversal[0]->type);
        $this->assertSame(100, (int)$reversal[0]->points, 'only what was left is taken back');
        $this->assertSame(0, $points());

        // the next points earned show straight away, not swallowed by a shortfall
        \App\Models\PointTransaction::create([
            'user_id' => $customer->id, 'type' => 'credit', 'points' => 50,
            'value' => 0.5, 'source' => 'order', 'reference_id' => null,
        ]);
        $this->assertSame(50, $points());
    }

    // -------------------------------------------------------- fulfilment

    public function test_a_delivered_order_carries_the_address_it_was_sent_to()
    {
        $customer = $this->deliveringCustomer();
        $order = $this->placeOrder($customer, 3, 1);

        $detail = $this->adminDetail($order->id);

        $this->assertNotNull($detail['address'], 'a delivery order must carry its address');
        $this->assertSame('Accounts Payable', $detail['address']['name']);

        // The account still names the person, even when the address names a desk.
        $this->assertSame('Daniel J Murphy', $detail['customer']['name']);
    }
}
