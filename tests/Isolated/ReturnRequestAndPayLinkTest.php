<?php

namespace Tests\Isolated;

use App\Models\Helper\OrderExchanges;
use App\Models\Helper\OrderReturns;
use App\Models\Helper\PayLinks;
use App\Models\Helper\ReturnRefused;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\PayLink;
use App\Models\UpdatedInventory;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatedTestCase;

/**
 * Customer return requests (phase 2) and pay-by-link for a dearer exchange
 * (2026-10-19).
 *
 * What these pin:
 *  - a customer may ask only about their own order, within the window, and
 *    not for a change-of-mind refund on a non-refundable product;
 *  - a request reserves its units, waits for staff, and can be withdrawn;
 *  - a pay link fixes the amount, holds the new units, and completes the
 *    exchange only when the GATEWAY says it was paid, for that amount, on a
 *    checkout this link made; cancelling it puts the units back.
 */
class ReturnRequestAndPayLinkTest extends IsolatedTestCase
{
    private $shippingPlaceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shippingPlaceId = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('payments')->update(['sumup' => 1, 'sumup_api_key' => 'sup_test_key', 'stripe' => 0,
            'card_gateway' => config('constants.paymentMethod.SUMUP')]);
        UpdatedInventory::whereIn('id', [Fixture::PHONE_128_BLACK_ID, Fixture::PHONE_128_WHITE_ID,
            Fixture::PHONE_256_BLACK_ID, Fixture::PHONE_256_WHITE_ID])->update(['quantity' => 10, 'is_active' => 0]);
    }

    private function phoneOrder($inventoryId = Fixture::PHONE_128_BLACK_ID, $unit = 500.00, $deliveredDaysAgo = 2, $userId = Fixture::USER_PERSONAL_ID)
    {
        $orderId = DB::table('orders')->insertGetId([
            'order' => 'ORD' . random_int(1000000, 9999999), 'user_id' => $userId,
            'status' => (int)config('constants.orderStatus.DELIVERED'), 'order_method' => (int)config('constants.paymentMethod.SUMUP'),
            'payment_done' => 1, 'cancelled' => 2, 'total_amount' => $unit, 'wallet_amount' => 0, 'currency' => 'EUR',
            'trans_id' => 'TX-' . random_int(1000, 9999), 'contact_name' => 'Fixture Customer', 'contact_email' => 'customer@fixture.test',
            'user_token' => $userId ? null : 'guest-order-token-1',
            'created_at' => now()->subDays($deliveredDaysAgo + 3), 'updated_at' => now(),
        ]);
        DB::table('ordered_products')->insert(['order_id' => $orderId, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => $inventoryId, 'quantity' => 1, 'selling' => $unit, 'tax_price' => 0, 'tax_rate' => 23,
            'tax_inclusive' => 1, 'bundle_offer' => 0, 'shipping_place_id' => $this->shippingPlaceId, 'shipping_type' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('ordered_products_inventories')->insert(['order_id' => $orderId, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => $inventoryId, 'quantity' => 1, 'actual_price' => $unit, 'price' => $unit, 'shipping_price' => 0,
            'product_voucher_discount_applied' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $h = OrderStatusHistory::create(['order_id' => $orderId, 'from_status' => 4,
            'to_status' => (int)config('constants.orderStatus.DELIVERED'), 'changed_by_type' => 'system']);
        DB::table('order_status_histories')->where('id', $h->id)->update(['created_at' => now()->subDays($deliveredDaysAgo)]);

        return Order::with('ordered_products')->find($orderId);
    }

    private function line(Order $order)
    {
        return OrderedProduct::where('order_id', $order->id)->value('id');
    }

    private function stock($id)
    {
        return (int)UpdatedInventory::where('id', $id)->value('quantity');
    }

    private function outbox()
    {
        $m = Mail::getSwiftMailer()->getTransport()->messages();

        return is_array($m) ? $m : $m->all();
    }

    // ---------------------------------------------------------- the request

    public function test_a_customer_requests_a_return_and_staff_approve_it()
    {
        $order = $this->phoneOrder();
        Mail::getSwiftMailer()->getTransport()->flush();

        $response = $this->asCustomer()->postJson('/api/v1/order-return/request', [
            'order_id' => $order->id, 'items' => [$this->line($order) => 1], 'reason' => 'faulty', 'note' => 'Screen flickers',
        ]);

        $this->assertApiOk($response);
        $return = OrderReturn::where('order_id', $order->id)->first();
        $this->assertSame(OrderReturn::REQUESTED, $return->status);
        $this->assertSame('Screen flickers', $return->customer_note);

        $subjects = collect($this->outbox())->map(function ($m) { return $m->getSubject(); })->implode(' | ');
        $this->assertStringContainsString('Return request received', $subjects, 'the customer is told');
        $this->assertStringContainsString('Return request ' . $return->rma_number, $subjects, 'the shop is told');

        $this->assertSame('Request received', OrderReturns::forCustomer($order->fresh())[0]['status_label']);

        $approved = OrderReturns::approve($return, null, Fixture::ADMIN_SUPER_ID, false);
        $this->assertSame(OrderReturn::AWAITING, $approved->status);
    }

    public function test_the_options_list_what_can_go_back_and_what_it_can_become()
    {
        $order = $this->phoneOrder();

        $response = $this->asCustomer()->postJson('/api/v1/order-return/options', ['order_id' => $order->id]);

        $this->assertApiOk($response);
        $this->assertTrue($response->json('data.can_request'));
        $this->assertSame(1, $response->json('data.lines.0.returnable_quantity'));
        $this->assertContains(Fixture::PHONE_256_BLACK_ID, collect($response->json('data.lines.0.variants'))->pluck('inventory_id')->all());
        $this->assertArrayHasKey('changed_mind', $response->json('data.reasons'));
    }

    public function test_a_customer_cannot_ask_about_someone_elses_order()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 500.00, 2, Fixture::USER_BUSINESS_ID);

        foreach (['options', 'request'] as $endpoint) {
            $this->app['auth']->forgetGuards();
            $response = $this->asCustomer()->postJson('/api/v1/order-return/' . $endpoint, [
                'order_id' => $order->id, 'items' => [$this->line($order) => 1], 'reason' => 'faulty',
            ]);
            $this->assertNotSame(200, (int)$response->json('status'), $endpoint);
        }

        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count());
    }

    public function test_a_guest_asks_with_the_order_token()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 500.00, 2, null);

        $wrong = OrderReturn::count();
        $refused = $this->asGuest()->postJson('/api/v1/order-return/options', ['order_id' => $order->id, 'user_token' => 'not-the-token']);
        $this->assertNotSame(200, (int)$refused->json('status'));

        // Straight to the helper with the token the guest holds: the guest.token
        // middleware only admits tokens the server issued, which the fixture's is not.
        $request = \Illuminate\Http\Request::create('/', 'POST', ['order_id' => $order->id, 'user_token' => 'guest-order-token-1']);
        $request->setUserResolver(function () { return null; });
        $controller = new \App\Http\Controllers\CustomerReturnsController();
        $this->assertSame(200, (int)json_decode($controller->options($request)->getContent(), true)['status']);
        $this->assertSame($wrong, OrderReturn::count());
    }

    public function test_a_customer_is_held_to_the_return_window_but_staff_are_not()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 500.00, 45);

        try {
            OrderReturns::request($order, [$this->line($order) => 1], [], 'faulty', null);
            $this->fail('a request past the window was accepted');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('within 30 days', $e->getMessage());
        }

        $staff = OrderReturns::open($order, [$this->line($order) => 1], 'faulty', null, null, 1, false);
        $this->assertSame(OrderReturn::AWAITING, $staff->status);
    }

    public function test_a_non_refundable_product_is_not_refunded_for_a_change_of_mind()
    {
        DB::table('products')->where('id', Fixture::PRODUCT_PHONE_ID)->update(['refundable' => 2]);
        $order = $this->phoneOrder();
        $line = $this->line($order);

        try {
            OrderReturns::request($order, [$line => 1], [], 'changed_mind', null);
            $this->fail('a change-of-mind refund on a non-refundable product was accepted');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('cannot be returned for a refund', $e->getMessage());
        }

        // A fault is still the shop's to put right, and an exchange is not a refund.
        $this->assertSame(OrderReturn::REQUESTED, OrderReturns::request($order, [$line => 1], [], 'faulty', null)->status);
        OrderReturn::where('order_id', $order->id)->delete();
        $this->assertSame(OrderReturn::TYPE_EXCHANGE,
            OrderReturns::request($order, [$line => 1], [$line => Fixture::PHONE_128_WHITE_ID], 'changed_mind', null)->type);
    }

    public function test_a_request_reserves_its_units_until_the_customer_withdraws_it()
    {
        $order = $this->phoneOrder();
        $line = $this->line($order);

        $first = OrderReturns::request($order, [$line => 1], [], 'faulty', null);

        try {
            OrderReturns::request($order, [$line => 1], [], 'faulty', null);
            $this->fail('the same unit was requested twice');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('More units', $e->getMessage());
        }

        $response = $this->asCustomer()->postJson('/api/v1/order-return/cancel', ['return_id' => $first->id]);
        $this->assertApiOk($response);
        $this->assertSame(OrderReturn::CANCELLED, $first->fresh()->status);

        $this->assertSame(OrderReturn::REQUESTED, OrderReturns::request($order->fresh(), [$line => 1], [], 'faulty', null)->status);
    }

    public function test_once_the_parcel_is_in_the_customer_cannot_withdraw()
    {
        $order = $this->phoneOrder();
        $return = OrderReturns::request($order, [$this->line($order) => 1], [], 'faulty', null);
        OrderReturns::receive($return, [], null, 1);

        $this->expectException(ReturnRefused::class);
        OrderReturns::cancelByCustomer($return->fresh());
    }

    // ------------------------------------------------------------- pay link

    /** A received exchange of the 500 phone for the 600 one: 100 to pay. */
    private function dearerExchange()
    {
        $order = $this->phoneOrder();
        $line = $this->line($order);
        $return = OrderReturns::open($order, [$line => 1], 'changed_mind', null, null, 1, false, null, [$line => Fixture::PHONE_256_BLACK_ID]);

        return OrderReturns::receive($return, [$return->items->first()->id => true], null, 1);
    }

    /** SumUp that makes checkouts and reports them paid (or not), echoing the reference it was given. */
    private function fakeSumup($paidStatus = 'PAID', $paidAmount = null)
    {
        $state = (object)['reference' => null, 'amount' => null, 'creates' => 0];

        $this->fakeHttp([
            'api.sumup.com/v0.1/checkouts/*' => function () use ($state, $paidStatus, $paidAmount) {
                return Http::response(['status' => $paidStatus, 'amount' => $paidAmount ?? $state->amount, 'currency' => 'EUR',
                    'transaction_code' => 'TX-LINK-1', 'checkout_reference' => $state->reference]);
            },
            'api.sumup.com/v0.1/checkouts' => function ($request) use ($state) {
                $state->creates++;
                $state->reference = $request->data()['checkout_reference'];
                $state->amount = $request->data()['amount'];
                return Http::response(['id' => 'chk_link_' . $state->creates]);
            },
        ]);

        return $state;
    }

    public function test_a_paid_link_completes_the_exchange_by_itself()
    {
        $return = $this->dearerExchange();
        $held = $this->stock(Fixture::PHONE_256_BLACK_ID);
        Mail::getSwiftMailer()->getTransport()->flush();

        $link = PayLinks::create($return, 1);

        $this->assertSame(OrderReturn::AWAITING_PAYMENT, $return->fresh()->status);
        $this->assertEquals(100.00, $link->amount);
        $this->assertSame($held - 1, $this->stock(Fixture::PHONE_256_BLACK_ID), 'the new unit is held');
        $this->assertSame(48, strlen($link->token));

        $mail = $this->outbox();
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('/pay/' . $link->token, $mail[0]->getBody(), 'the email carries the link');

        $state = $this->fakeSumup();
        $this->assertSame('chk_link_1', PayLinks::start($link)['checkout_id']);
        $this->assertEquals(100.0, $state->amount, 'the checkout is for the link amount');

        $paid = PayLinks::confirm($link->fresh());

        $this->assertSame(PayLink::PAID, $paid->status);
        $case = $return->fresh();
        $this->assertSame(OrderReturn::EXCHANGED, $case->status);
        $this->assertEquals(100.00, $case->amount_charged);
        $replacement = Order::find($case->replacement_order_id);
        $this->assertEquals(100.00, (float)$replacement->total_amount);
        $this->assertSame((int)config('constants.paymentMethod.SUMUP'), (int)$replacement->order_method);
        $this->assertSame($held - 1, $this->stock(Fixture::PHONE_256_BLACK_ID), 'taken once: held at the link, not again');

        $this->assertSame(PayLink::PAID, PayLinks::confirm($paid)->status, 'confirming again is harmless');
        $this->assertSame(1, Order::where('id', '>', $return->order_id)->count(), 'one replacement order');
    }

    public function test_an_unpaid_or_short_checkout_completes_nothing()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);

        foreach ([['PENDING', null], ['FAILED', null], ['PAID', 1.00]] as [$status, $amount]) {
            $this->fakeSumup($status, $amount);
            PayLink::where('id', $link->id)->update(['gateway_session' => null]);
            PayLinks::start($link->fresh());

            try {
                PayLinks::confirm($link->fresh());
                $this->fail("confirmed a $status checkout" . ($amount ? " for $amount" : ''));
            } catch (ReturnRefused $e) {
                $this->assertStringContainsString('not received this payment', $e->getMessage());
            }
        }

        $this->assertSame(OrderReturn::AWAITING_PAYMENT, $return->fresh()->status);
        $this->assertNull($return->fresh()->replacement_order_id);
    }

    public function test_a_paid_checkout_that_this_link_did_not_make_is_refused()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);

        // Someone else's paid checkout for the same amount.
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => Http::response(['status' => 'PAID', 'amount' => 100.0,
            'currency' => 'EUR', 'transaction_code' => 'TX-OTHER', 'checkout_reference' => 'ORDER-12345'])]);
        PayLink::where('id', $link->id)->update(['gateway_session' => 'chk_someone_else']);

        $this->expectException(ReturnRefused::class);
        PayLinks::confirm($link->fresh());
    }

    public function test_cancelling_a_link_puts_the_held_units_back()
    {
        $return = $this->dearerExchange();
        $before = $this->stock(Fixture::PHONE_256_BLACK_ID);

        PayLinks::create($return, 1);
        $this->assertSame($before - 1, $this->stock(Fixture::PHONE_256_BLACK_ID));

        $back = PayLinks::cancel($return->fresh('items'), 1);

        $this->assertSame(OrderReturn::RECEIVED, $back->status);
        $this->assertSame($before, $this->stock(Fixture::PHONE_256_BLACK_ID));
        $this->assertSame(PayLink::CANCELLED, PayLink::where('order_return_id', $return->id)->value('status'));

        // and staff can record the payment themselves now
        $this->assertSame(OrderReturn::EXCHANGED, OrderExchanges::complete($back, null, 'TILL-1', null, null, 1)->status);
    }

    public function test_staff_cannot_record_a_payment_while_a_link_is_open()
    {
        $return = $this->dearerExchange();
        PayLinks::create($return, 1);

        $this->expectException(ReturnRefused::class);
        $this->expectExceptionMessage('pay link is open');
        OrderExchanges::complete($return->fresh('items'), null, 'TILL-1', null, null, 1);
    }

    public function test_an_expired_link_cannot_be_started()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);
        PayLink::where('id', $link->id)->update(['expires_at' => now()->subDay()]);

        $this->expectException(ReturnRefused::class);
        $this->expectExceptionMessage('expired');
        PayLinks::start($link->fresh());
    }

    public function test_the_pay_page_api_shows_the_link_and_nothing_private()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);

        $response = $this->asGuest()->getJson('/api/v1/pay-link/view?link=' . $link->token);

        $this->assertApiOk($response);
        $this->assertEquals(100.00, $response->json('data.amount'));
        $this->assertSame('sumup', $response->json('data.gateway'));
        $this->assertSame($return->rma_number, $response->json('data.rma_number'));
        $body = $response->getContent();
        $this->assertStringNotContainsString('customer@fixture.test', $body);
        $this->assertStringNotContainsString('sup_test_key', $body);

        $this->assertNotSame(200, (int)$this->asGuest()->getJson('/api/v1/pay-link/view?link=' . str_repeat('x', 48))->json('status'));
    }

    public function test_a_link_is_refused_without_a_card_gateway_or_a_difference()
    {
        $same = $this->phoneOrder();
        $line = $this->line($same);
        $equal = OrderReturns::open($same, [$line => 1], 'changed_mind', null, null, 1, false, null, [$line => Fixture::PHONE_128_WHITE_ID]);
        $equal = OrderReturns::receive($equal, [], null, 1);

        try {
            PayLinks::create($equal, 1);
            $this->fail('a link for nothing to pay');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('nothing', $e->getMessage());
        }

        DB::table('payments')->update(['sumup' => 0, 'stripe' => 0]);

        $this->expectException(ReturnRefused::class);
        $this->expectExceptionMessage('No card gateway');
        PayLinks::create($this->dearerExchange(), 1);
    }

    public function test_stripe_links_check_the_intent_amount_and_owner()
    {
        DB::table('payments')->update(['stripe' => 1, 'stripe_key' => 'pk_test_fake', 'stripe_secret' => 'sk_test_fake',
            'card_gateway' => config('constants.paymentMethod.STRIPE')]);
        $fake = new FakeStripeClient();
        \Stripe\ApiRequestor::setHttpClient($fake);

        try {
            $return = $this->dearerExchange();
            $link = PayLinks::create($return, 1);

            $fake->queue(200, ['id' => 'pi_link', 'object' => 'payment_intent', 'status' => 'requires_payment_method',
                'client_secret' => 'pi_link_secret', 'amount' => 10000, 'currency' => 'eur', 'metadata' => ['pay_link_id' => $link->id]]);
            $this->assertSame('pi_link_secret', PayLinks::start($link)['client_secret']);
            $this->assertSame('10000', (string)$fake->requests[0]['params']['amount'], 'the intent is for the link amount');

            // paid, but for less: refused
            $fake->queue(200, ['id' => 'pi_link', 'object' => 'payment_intent', 'status' => 'succeeded',
                'amount' => 10000, 'amount_received' => 5000, 'currency' => 'eur', 'metadata' => ['pay_link_id' => $link->id]]);
            try {
                PayLinks::confirm($link->fresh());
                $this->fail('a short Stripe payment completed the exchange');
            } catch (ReturnRefused $e) {
                $this->assertSame(OrderReturn::AWAITING_PAYMENT, $return->fresh()->status);
            }

            $fake->queue(200, ['id' => 'pi_link', 'object' => 'payment_intent', 'status' => 'succeeded',
                'amount' => 10000, 'amount_received' => 10000, 'currency' => 'eur', 'metadata' => ['pay_link_id' => $link->id]]);
            $this->assertSame(PayLink::PAID, PayLinks::confirm($link->fresh())->status);
            $this->assertSame(OrderReturn::EXCHANGED, $return->fresh()->status);
            $this->assertSame((int)config('constants.paymentMethod.STRIPE'), (int)Order::find($return->fresh()->replacement_order_id)->order_method);

        } finally {
            \Stripe\ApiRequestor::setHttpClient(null);
        }
    }
}
