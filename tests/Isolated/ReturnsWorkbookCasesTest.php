<?php

namespace Tests\Isolated;

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
 * Cases from docs/qa/Returns_Exchanges_Test_Cases.xlsx that the returns
 * suites did not already pin (2026-10-22). Each test name starts with the
 * workbook case id. The rest of the workbook maps onto OrderReturnTest,
 * OrderExchangeTest, ReturnRequestAndPayLinkTest and MyOrdersTabsTest.
 */
class ReturnsWorkbookCasesTest extends IsolatedTestCase
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
        Mail::getSwiftMailer()->getTransport()->flush();
    }

    /** One EUR 500 phone, paid by card, delivered $deliveredDaysAgo days ago. */
    private function phoneOrder(array $over = [], $deliveredDaysAgo = 2)
    {
        $orderId = DB::table('orders')->insertGetId(array_merge([
            'order' => 'ORD' . random_int(1000000, 9999999), 'user_id' => Fixture::USER_PERSONAL_ID,
            'status' => (int)config('constants.orderStatus.DELIVERED'), 'order_method' => (int)config('constants.paymentMethod.SUMUP'),
            'payment_done' => 1, 'cancelled' => 2, 'total_amount' => 500, 'wallet_amount' => 0, 'currency' => 'EUR',
            'trans_id' => 'TX-' . random_int(1000, 9999), 'contact_name' => 'Fixture Customer',
            'contact_email' => 'customer' . random_int(1000, 9999) . '@fixture.test',
            'created_at' => now()->subDays($deliveredDaysAgo + 3), 'updated_at' => now(),
        ], $over));
        DB::table('ordered_products')->insert(['order_id' => $orderId, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID, 'quantity' => 1, 'selling' => 500, 'tax_price' => 0, 'tax_rate' => 23,
            'tax_inclusive' => 1, 'bundle_offer' => 0, 'shipping_place_id' => $this->shippingPlaceId, 'shipping_type' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('ordered_products_inventories')->insert(['order_id' => $orderId, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID, 'quantity' => 1, 'actual_price' => 500, 'price' => 500, 'shipping_price' => 0,
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

    private function returnOptions(Order $order, $lang = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->asCustomer()->withHeaders($lang ? ['language' => $lang] : [])
            ->postJson('/api/v1/order-return/options', ['order_id' => $order->id]);
    }

    private function outbox()
    {
        $m = Mail::getSwiftMailer()->getTransport()->messages();

        return is_array($m) ? $m : $m->all();
    }

    /** A received exchange of the 500 phone for the 600 one: 100 to pay. */
    private function dearerExchange()
    {
        $order = $this->phoneOrder();
        $line = $this->line($order);
        $return = OrderReturns::open($order, [$line => 1], 'changed_mind', null, null, 1, false, null, [$line => Fixture::PHONE_256_BLACK_ID]);

        return OrderReturns::receive($return, [$return->items->first()->id => true], null, 1);
    }

    private function fakeSumup($status)
    {
        $state = (object)['reference' => null, 'amount' => null, 'status' => $status];

        $this->fakeHttp([
            'api.sumup.com/v0.1/checkouts/*' => function () use ($state) {
                return Http::response(['status' => $state->status, 'amount' => $state->amount, 'currency' => 'EUR',
                    'transaction_code' => 'TX-LINK-1', 'checkout_reference' => $state->reference]);
            },
            'api.sumup.com/v0.1/checkouts' => function ($request) use ($state) {
                $state->reference = $request->data()['checkout_reference'];
                $state->amount = $request->data()['amount'];
                return Http::response(['id' => 'chk_' . uniqid()]);
            },
        ]);

        return $state;
    }

    // ------------------------------------------------------ customer request

    public function test_C_01_only_a_delivered_paid_order_can_be_asked_about()
    {
        $s = config('constants.orderStatus');

        $this->assertTrue($this->returnOptions($this->phoneOrder())->json('data.can_request'), 'delivered and paid');

        foreach (['pending' => ['status' => $s['PENDING']], 'on the way' => ['status' => $s['ON_THE_WAY']],
                     'cancelled' => ['status' => $s['CANCELLED'], 'cancelled' => 1], 'unpaid' => ['payment_done' => 0]] as $name => $over) {
            $response = $this->returnOptions($this->phoneOrder($over));

            $this->assertApiOk($response);
            $this->assertFalse($response->json('data.can_request'), $name);
            $this->assertNotEmpty($response->json('data.reason'), "$name says why");
        }
    }

    public function test_C_02_the_form_gets_the_days_left_each_item_and_the_reasons()
    {
        $data = $this->returnOptions($this->phoneOrder([], 5))->json('data');

        $this->assertSame(30, $data['window_days']);
        $this->assertSame(25, $data['days_left']);
        $this->assertCount(1, $data['lines']);
        $this->assertSame(1, $data['lines'][0]['returnable_quantity']);
        $this->assertSame(array_keys(OrderReturn::REASONS), array_keys($data['reasons']));
    }

    public function test_C_04_a_unit_already_asked_for_is_not_offered_again()
    {
        $order = $this->phoneOrder();
        OrderReturns::request($order, [$this->line($order) => 1], [], 'faulty', null);

        $this->assertSame(0, $this->returnOptions($order->fresh())->json('data.lines.0.returnable_quantity'));
    }

    public function test_C_05_past_the_window_the_form_is_replaced_by_the_message()
    {
        $order = $this->phoneOrder([], 31);

        $data = $this->returnOptions($order)->json('data');
        $this->assertFalse($data['can_request']);
        $this->assertStringStartsWith('Returns can be requested within 30 days of delivery', $data['reason']);
        $this->assertSame(0, $data['days_left']);

        $this->assertStringNotContainsString('Returns can be', $this->returnOptions($order, 'fr')->json('data.reason'), 'in French too');
    }

    public function test_C_07_only_variants_that_can_be_had_are_offered_with_their_price()
    {
        UpdatedInventory::where('id', Fixture::PHONE_256_WHITE_ID)->update(['quantity' => 0, 'is_active' => 0]);
        UpdatedInventory::where('id', Fixture::PHONE_128_WHITE_ID)->update(['quantity' => 0, 'is_active' => 1]);

        $line = $this->returnOptions($this->phoneOrder())->json('data.lines.0');
        $offered = collect($line['variants'])->keyBy('inventory_id');

        $this->assertFalse($offered->has(Fixture::PHONE_256_WHITE_ID), 'sold out, no backorder: not offered');
        $this->assertTrue($offered->has(Fixture::PHONE_128_WHITE_ID), 'backorder: offered');
        $this->assertTrue($offered->has(Fixture::PHONE_256_BLACK_ID));
        $this->assertFalse($offered->has(Fixture::PHONE_128_BLACK_ID), 'not the variant they already have');

        // The form words "EUR X more / less / same price" from these two figures.
        $this->assertEquals(500.0, $line['unit_price']);
        $this->assertGreaterThan($line['unit_price'], $offered[Fixture::PHONE_256_BLACK_ID]['unit_price']);
    }

    public function test_C_09_a_withdrawn_unit_is_offered_again()
    {
        $order = $this->phoneOrder();
        $return = OrderReturns::request($order, [$this->line($order) => 1], [], 'faulty', null);

        $this->asCustomer()->postJson('/api/v1/order-return/cancel', ['return_id' => $return->id]);

        $this->assertSame(OrderReturn::CANCELLED, $return->fresh()->status);
        $this->assertSame(1, $this->returnOptions($order->fresh())->json('data.lines.0.returnable_quantity'));
    }

    /**
     * Found by this pass: the refusals a customer can meet on the request
     * form (a unit already asked for, an order no longer returnable) were
     * fixed English strings, so the French, Arabic, Hindi and Turkish
     * storefronts showed them in English.
     */
    public function test_L_04_a_refused_request_answers_in_the_customers_language()
    {
        $order = $this->phoneOrder();
        $line = $this->line($order);
        OrderReturns::request($order, [$line => 1], [], 'faulty', null);

        $send = function ($lang, array $body) use ($order) {
            $this->app['auth']->forgetGuards();
            return $this->asCustomer()->withHeaders(['language' => $lang])
                ->postJson('/api/v1/order-return/request', array_merge(['order_id' => $order->id, 'reason' => 'faulty'], $body))
                ->json('message');
        };

        $this->assertSame(__('lang.return_too_many_units', [], 'fr'), $send('fr', ['items' => [$line => 1]]));
        $this->assertSame(__('lang.return_choose_items', [], 'tr'), $send('tr', ['items' => []]));
        $this->assertSame(__('lang.return_choose_reason', [], 'ar'), $send('ar', ['items' => [$line => 1], 'reason' => 'bored']));
        $this->assertNotSame(__('lang.return_too_many_units', [], 'en'), __('lang.return_too_many_units', [], 'fr'));

        $unpaid = $this->phoneOrder(['payment_done' => 0]);
        $this->assertSame(__('lang.return_not_paid', [], 'hi'), $this->returnOptions($unpaid, 'hi')->json('data.reason'));

        // Staff screens keep the English wording whatever the email language.
        try {
            OrderReturns::open($order->fresh(), [$line => 1], 'faulty', null, null, 1, false, 'fr');
            $this->fail('the same unit was opened twice');
        } catch (ReturnRefused $e) {
            $this->assertSame('More units chosen than are left to return on one of the items.', $e->getMessage());
        }
    }

    // --------------------------------------------------------- admin review

    public function test_A_01_the_returns_list_counts_filters_and_searches()
    {
        $order = $this->phoneOrder();
        $request = OrderReturns::request($order, [$this->line($order) => 1], [], 'faulty', null);
        $other = $this->phoneOrder();
        $opened = OrderReturns::open($other, [$this->line($other) => 1], 'faulty', null, null, 1, false);

        $this->asAdmin();
        $this->assertSame(1, $this->getJson('/api/admin/order-return/stats')->json('data.requested'), 'To review');

        $find = function (array $q) {
            return collect($this->getJson('/api/admin/order-return/all?' . http_build_query($q))->json('data.data'))->pluck('id')->all();
        };

        $this->assertSame([$request->id], $find(['q' => $request->rma_number]));
        $this->assertSame([$request->id], $find(['q' => $order->order]));
        $this->assertSame([$opened->id], $find(['q' => $other->contact_email]));
        $this->assertSame([$request->id], $find(['status' => OrderReturn::REQUESTED]));
        $this->assertEqualsCanonicalizing([$request->id, $opened->id], $find(['status' => 'open']));

        $row = collect($this->getJson('/api/admin/order-return/all')->json('data.data'))->firstWhere('id', $request->id);
        foreach (['rma_number', 'type', 'order_number', 'customer', 'status_label'] as $key) {
            $this->assertNotEmpty($row[$key], $key);
        }
    }

    public function test_A_02_approving_emails_the_rma_and_where_to_send_it()
    {
        DB::table('settings')->update(['address_1' => '1 Returns Lane', 'city' => 'Dublin']);
        $order = $this->phoneOrder();
        $return = OrderReturns::request($order, [$this->line($order) => 1], [], 'faulty', null);
        Mail::getSwiftMailer()->getTransport()->flush();

        $approved = OrderReturns::approve($return, null, Fixture::ADMIN_SUPER_ID, true);

        $this->assertSame(OrderReturn::AWAITING, $approved->status);
        // A signed-in customer is written to at their account address.
        $to = $order->contactDetails()['email'];
        $mail = collect($this->outbox())->first(function ($m) use ($to) { return array_key_exists($to, $m->getTo() ?: []); });
        $this->assertNotNull($mail, 'the customer is emailed');
        $this->assertStringContainsString($return->rma_number, $mail->getSubject() . $mail->getBody());
        $this->assertStringContainsString('1 Returns Lane', $mail->getBody());
    }

    public function test_A_04_staff_can_start_a_return_past_the_window_and_pick_the_language()
    {
        $order = $this->phoneOrder([], 45);

        $this->asAdmin();
        $data = $this->getJson('/api/admin/order-return/order/' . $order->id)->json('data');

        $this->assertTrue($data['eligibility']['ok']);
        $this->assertFalse($data['eligibility']['within_window'], 'the screen warns it is past the window');

        foreach (['en', 'fr', 'ar', 'hi', 'tr'] as $lang) {
            $this->assertNotSame('lang.mail_return_opened_title', __('lang.mail_return_opened_title', [], $lang), "$lang has the email");
        }

        $response = $this->postJson('/api/admin/order-return/create', [
            'order_id' => $order->id, 'items' => [$this->line($order) => 1], 'reason' => 'faulty', 'email_lang' => 'fr', 'notify' => 0,
        ]);
        $this->assertApiOk($response);
        $this->assertSame('fr', OrderReturn::where('order_id', $order->id)->value('lang'));
    }

    public function test_A_18_the_order_page_lists_each_case()
    {
        $order = $this->phoneOrder();
        $return = OrderReturns::open($order, [$this->line($order) => 1], 'faulty', null, null, 1, false);

        $this->asAdmin();
        $returns = $this->getJson('/api/admin/order-return/order/' . $order->id)->json('data.returns');

        $this->assertCount(1, $returns);
        $this->assertSame($return->rma_number, $returns[0]['rma_number']);
        $this->assertArrayHasKey('status', $returns[0]);
        $this->assertArrayHasKey('type', $returns[0]);
    }

    // ---------------------------------------------------------- pay links

    public function test_E_08_a_paid_link_reopened_shows_paid_and_charges_nothing()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);
        $this->fakeSumup('PAID');
        PayLinks::start($link);
        PayLinks::confirm($link->fresh());
        $orders = Order::count();

        $view = $this->asGuest()->getJson('/api/v1/pay-link/view?link=' . $link->token)->json('data');
        $this->assertSame(PayLink::PAID, $view['status']);
        $this->assertSame(Order::find($return->fresh()->replacement_order_id)->order, $view['replacement_order']);

        $again = $this->asGuest()->postJson('/api/v1/pay-link/start', ['link' => $link->token]);
        $this->assertNotSame(200, (int)$again->json('status'), 'no new checkout on a paid link');
        $this->asGuest()->postJson('/api/v1/pay-link/confirm', ['link' => $link->token]);
        $this->assertSame($orders, Order::count(), 'no second replacement order');
    }

    public function test_E_09_a_declined_card_leaves_the_link_open_and_a_retry_works()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);
        $state = $this->fakeSumup('FAILED');
        PayLinks::start($link);

        $declined = $this->asGuest()->postJson('/api/v1/pay-link/confirm', ['link' => $link->token]);
        $this->assertNotSame(200, (int)$declined->json('status'));
        $this->assertSame(OrderReturn::AWAITING_PAYMENT, $return->fresh()->status);

        // A new card on a new checkout.
        PayLink::where('id', $link->id)->update(['gateway_session' => null]);
        $state->status = 'PAID';
        $this->asGuest()->postJson('/api/v1/pay-link/start', ['link' => $link->token]);
        $paid = $this->asGuest()->postJson('/api/v1/pay-link/confirm', ['link' => $link->token]);

        $this->assertApiOk($paid);
        $this->assertSame(OrderReturn::EXCHANGED, $return->fresh()->status);
    }

    public function test_E_10_a_cancelled_link_says_it_was_withdrawn()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);
        PayLinks::cancel($return->fresh('items'), 1);

        $this->assertSame(PayLink::CANCELLED, $this->asGuest()->getJson('/api/v1/pay-link/view?link=' . $link->token)->json('data.status'));
        $start = $this->asGuest()->postJson('/api/v1/pay-link/start', ['link' => $link->token]);
        $this->assertSame(__('lang.pay_link_cancelled'), $start->json('message'));
    }

    public function test_E_11_an_expired_link_says_so_and_cannot_be_paid()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);
        PayLink::where('id', $link->id)->update(['expires_at' => now()->subDay()]);

        $this->assertSame('expired', $this->asGuest()->getJson('/api/v1/pay-link/view?link=' . $link->token)->json('data.status'));
        $this->assertSame(__('lang.pay_link_expired'),
            $this->asGuest()->postJson('/api/v1/pay-link/start', ['link' => $link->token])->json('message'));
    }

    public function test_E_12_a_link_with_one_character_changed_is_not_valid()
    {
        $link = PayLinks::create($this->dearerExchange(), 1);
        $t = $link->token;
        $wrong = substr($t, 0, -1) . ($t[47] === 'a' ? 'b' : 'a');

        $response = $this->asGuest()->getJson('/api/v1/pay-link/view?link=' . $wrong);

        $this->assertSame('This payment link is not valid.', $response->json('message'));
        $this->assertEmpty($response->json('data.rma_number'));
    }

    public function test_E_14_the_customer_sees_the_payment_waiting_and_the_link()
    {
        $return = $this->dearerExchange();
        $link = PayLinks::create($return, 1);

        $case = OrderReturns::forCustomer(Order::find($return->order_id))[0];

        $this->assertSame(OrderReturn::AWAITING_PAYMENT, $case['status']);
        $this->assertSame('Waiting for your payment', $case['status_label']);
        $this->assertStringEndsWith('/pay/' . $link->token, $case['pay_url']);
        $this->assertFalse($case['can_cancel'], 'the customer cannot withdraw once paying');
    }
}
