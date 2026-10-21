<?php

namespace Tests\Isolated;

use App\Models\Cancellation;
use App\Models\Helper\OrderReturns;
use App\Models\Helper\OrderState;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\IsolatedTestCase;

/**
 * My Orders tabs, and order / return / refund status kept apart (2026-10-21).
 *
 * The bug: the Cancelled tab listed refunded and returned orders, because the
 * page filtered on any "closed" status, and a refund moved a cancelled order
 * to REFUNDED. The tabs are now decided by the server (OrderState) and a
 * refund no longer rewrites the order's status.
 *
 * Scenarios A-H are the ones the user listed; each test says which.
 */
class MyOrdersTabsTest extends IsolatedTestCase
{
    private $shippingPlaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shippingPlaceId = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('payments')->update(['sumup' => 1, 'sumup_api_key' => 'sup_test_key']);
    }

    private function order(array $attrs = [], $userId = Fixture::USER_PERSONAL_ID)
    {
        $id = DB::table('orders')->insertGetId(array_merge([
            'order' => 'ORD' . random_int(1000000, 9999999), 'user_id' => $userId,
            'status' => (int)config('constants.orderStatus.CONFIRMED'), 'order_method' => (int)config('constants.paymentMethod.SUMUP'),
            'payment_done' => 1, 'cancelled' => 2, 'total_amount' => 500, 'wallet_amount' => 0, 'currency' => 'EUR',
            'trans_id' => 'TX-' . random_int(1000, 9999), 'contact_name' => 'Fixture Customer', 'contact_email' => 'customer@fixture.test',
            'created_at' => now()->subDays(3), 'updated_at' => now(),
        ], $attrs));

        DB::table('ordered_products')->insert(['order_id' => $id, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID, 'quantity' => 1, 'selling' => 500, 'tax_price' => 0, 'tax_rate' => 23,
            'tax_inclusive' => 1, 'bundle_offer' => 0, 'shipping_place_id' => $this->shippingPlaceId, 'shipping_type' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('ordered_products_inventories')->insert(['order_id' => $id, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID, 'quantity' => 1, 'actual_price' => 500, 'price' => 500, 'shipping_price' => 0,
            'product_voucher_discount_applied' => 0, 'created_at' => now(), 'updated_at' => now()]);

        if ((int)($attrs['status'] ?? 0) === (int)config('constants.orderStatus.DELIVERED')) {
            OrderStatusHistory::create(['order_id' => $id, 'from_status' => 4, 'to_status' => 5, 'changed_by_type' => 'system']);
        }

        return Order::with('ordered_products')->find($id);
    }

    private function delivered(array $attrs = [])
    {
        return $this->order(array_merge(['status' => (int)config('constants.orderStatus.DELIVERED')], $attrs));
    }

    private function cancelled(array $attrs = [])
    {
        return $this->order(array_merge(['status' => (int)config('constants.orderStatus.CANCELLED')], $attrs));
    }

    private function line(Order $order)
    {
        return OrderedProduct::where('order_id', $order->id)->value('id');
    }

    /** The customer's list for one tab, through the real endpoint. */
    private function tab($tab, array $extra = [])
    {
        /*
         * An earlier admin request in the same test left auth:admin's
         * shouldUse('admin') as the default guard (one app serves every
         * request in a test; a real request is a fresh process), and
         * OptionalAuth would then set the customer on the admin guard.
         */
        $this->app['config']->set('auth.defaults.guard', 'web');
        $this->app['auth']->forgetGuards();
        $response = $this->asCustomer()->postJson('/api/v1/order/by-user', array_merge(['tab' => $tab, 'time_zone' => 'Europe/Dublin'], $extra));
        $this->assertApiOk($response);

        return $response->json('data');
    }

    private function ids($data)
    {
        return collect($data['data'])->pluck('id')->all();
    }

    private function stateOf($data, Order $order)
    {
        return collect($data['data'])->firstWhere('id', $order->id)['state'] ?? null;
    }

    private function tabsHolding(Order $order)
    {
        $in = [];
        foreach (OrderState::TABS as $tab) {
            if (in_array($order->id, $this->ids($this->tab($tab)), true)) {
                $in[] = $tab;
            }
        }

        return $in;
    }

    // ---------------------------------------------------------- scenarios

    public function test_A_cancelled_before_dispatch_with_nothing_to_refund()
    {
        $order = $this->cancelled(['payment_done' => 0, 'order_method' => (int)config('constants.paymentMethod.BANK')]);

        $this->assertSame(['all', 'cancelled'], $this->tabsHolding($order));
        $state = $this->stateOf($this->tab('cancelled'), $order);
        $this->assertSame('cancelled', $state['order']);
        $this->assertNull($state['refund'], 'unpaid: no refund to speak of');
    }

    public function test_A2_a_paid_cancelled_order_shows_its_refund_as_pending_not_refunded()
    {
        $order = $this->cancelled();

        $state = $this->stateOf($this->tab('cancelled'), $order);
        $this->assertSame('cancelled', $state['order']);
        $this->assertSame('pending', $state['refund']['key']);
        $this->assertSame(['all', 'cancelled', 'returns'], $this->tabsHolding($order));
    }

    public function test_B_a_refund_on_a_cancelled_order_keeps_it_cancelled()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);
        $order = $this->order(['cancelled' => 1, 'status' => (int)config('constants.orderStatus.CANCELLED')]);
        $cancellation = Cancellation::create(['order_id' => $order->id, 'user_id' => $order->user_id, 'title' => 'Changed my mind', 'message' => 'x']);

        $this->app['auth']->forgetGuards();
        $response = $this->asAdmin()->postJson('/api/admin/cancellation/refund/' . $cancellation->id);
        $this->assertApiOk($response);

        $this->assertSame((int)config('constants.orderStatus.CANCELLED'), (int)$order->fresh()->status,
            'the refund does not overwrite the order status');
        $this->assertSame(1, (int)$cancellation->fresh()->refunded);

        $state = $this->stateOf($this->tab('cancelled'), $order);
        $this->assertSame('cancelled', $state['order']);
        $this->assertSame('completed', $state['refund']['key']);
        $this->assertEquals(500.0, $state['refund']['amount']);
        $this->assertSame(['all', 'cancelled', 'returns'], $this->tabsHolding($order));
    }

    public function test_B_legacy_an_order_the_old_refund_moved_to_refunded_still_counts_as_cancelled()
    {
        $order = $this->order(['status' => (int)config('constants.orderStatus.REFUNDED')]);
        OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => 2, 'to_status' => 6, 'changed_by_type' => 'admin']);
        OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => 6, 'to_status' => 8, 'changed_by_type' => 'admin']);

        $this->assertContains('cancelled', $this->tabsHolding($order));
        $this->assertSame('cancelled', $this->stateOf($this->tab('cancelled'), $order)['order']);
        $this->assertSame('completed', $this->stateOf($this->tab('cancelled'), $order)['refund']['key']);

        // and the admin's Cancelled chip finds it too
        $this->app['auth']->forgetGuards();
        $admin = $this->asAdmin()->getJson('/api/admin/order/all?orderby=created_at&type=desc&filter=cancelled');
        $this->assertContains($order->id, collect($admin->json('data.data'))->pluck('id')->all());
    }

    public function test_C_a_delivered_order_with_a_return_requested_stays_delivered()
    {
        $order = $this->delivered();
        OrderReturns::request($order, [$this->line($order) => 1], [], 'faulty', 'cracked');

        $this->assertSame(['all', 'delivered', 'returns'], $this->tabsHolding($order));
        $state = $this->stateOf($this->tab('returns'), $order);
        $this->assertSame('delivered', $state['order']);
        $this->assertSame('requested', $state['return']['key']);
        $this->assertNull($state['refund'], 'a request is not a refund');
    }

    public function test_D_an_approved_return_has_its_refund_pending_not_refunded()
    {
        $order = $this->delivered();
        OrderReturns::open($order, [$this->line($order) => 1], 'faulty', null, null, 1, false);

        $state = $this->stateOf($this->tab('returns'), $order);
        $this->assertSame('delivered', $state['order']);
        $this->assertSame('approved', $state['return']['key']);
        $this->assertSame('pending', $state['refund']['key']);
    }

    public function test_E_a_completed_return_with_a_confirmed_refund()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);
        $order = $this->delivered();
        $return = OrderReturns::open($order, [$this->line($order) => 1], 'faulty', null, null, 1, false);
        $return = OrderReturns::receive($return, [], null, 1);
        OrderReturns::refund($return, 'original', null, false, null, null, 1);

        $this->assertSame(['all', 'returns'], $this->tabsHolding($order), 'returned: not delivered, not cancelled');
        $state = $this->stateOf($this->tab('returns'), $order);
        $this->assertSame('returned', $state['order']);
        $this->assertSame('completed', $state['return']['key']);
        $this->assertSame('completed', $state['refund']['key']);
        $this->assertEquals(500.0, $state['refund']['amount']);
    }

    public function test_E2_returning_part_of_an_order_is_a_partial_refund()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);
        $order = $this->delivered(['total_amount' => 1000]);
        DB::table('ordered_products')->where('order_id', $order->id)->update(['quantity' => 2]);
        DB::table('ordered_products_inventories')->where('order_id', $order->id)->update(['quantity' => 2]);
        $order = Order::with('ordered_products')->find($order->id);

        $return = OrderReturns::open($order, [$this->line($order) => 1], 'faulty', null, null, 1, false);
        OrderReturns::refund(OrderReturns::receive($return, [], null, 1), 'original', null, false, null, null, 1);

        $state = $this->stateOf($this->tab('returns'), $order);
        $this->assertSame('delivered', $state['order']);
        $this->assertSame('partial', $state['refund']['key']);
    }

    public function test_F_a_failed_refund_shows_failed_and_keeps_the_order_status()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response(['message' => 'declined'], 400)]);
        $order = $this->delivered();
        $return = OrderReturns::receive(OrderReturns::open($order, [$this->line($order) => 1], 'faulty', null, null, 1, false), [], null, 1);

        try {
            OrderReturns::refund($return, 'original', null, false, null, null, 1);
        } catch (\App\Models\Helper\ReturnRefused $e) {
            // expected: the gateway refused
        }

        $state = $this->stateOf($this->tab('returns'), $order);
        $this->assertSame('delivered', $state['order']);
        $this->assertSame('item_received', $state['return']['key']);
        $this->assertSame('failed', $state['refund']['key']);

        // and on a cancelled order
        $cancelled = $this->cancelled();
        Cancellation::create(['order_id' => $cancelled->id, 'user_id' => $cancelled->user_id, 'title' => 't', 'message' => 'm']);
        Cancellation::where('order_id', $cancelled->id)->update(['refund_error' => 'declined']);
        $this->assertSame('failed', $this->stateOf($this->tab('cancelled'), $cancelled)['refund']['key']);
    }

    public function test_G_a_shipped_order_is_only_in_shipped()
    {
        $order = $this->order(['status' => (int)config('constants.orderStatus.ON_THE_WAY')]);

        $this->assertSame(['all', 'shipped'], $this->tabsHolding($order));
        $this->assertSame('shipped', $this->stateOf($this->tab('shipped'), $order)['order']);
    }

    public function test_H_a_delivered_order_without_a_return_is_only_in_delivered()
    {
        $order = $this->delivered();

        $this->assertSame(['all', 'delivered'], $this->tabsHolding($order));
        $state = $this->stateOf($this->tab('delivered'), $order);
        $this->assertNull($state['return']);
        $this->assertNull($state['refund']);
    }

    public function test_processing_holds_pending_and_confirmed_orders()
    {
        $pending = $this->order(['status' => (int)config('constants.orderStatus.PENDING')]);
        $confirmed = $this->order();

        $ids = $this->ids($this->tab('processing'));
        $this->assertContains($pending->id, $ids);
        $this->assertContains($confirmed->id, $ids);
    }

    // ------------------------------------------- counts, search, pagination

    public function test_each_count_matches_its_tab()
    {
        $this->cancelled();
        $this->cancelled(['payment_done' => 0]);
        $this->delivered();
        $this->order(['status' => (int)config('constants.orderStatus.ON_THE_WAY')]);
        $this->order();
        $withReturn = $this->delivered();
        OrderReturns::request($withReturn, [$this->line($withReturn) => 1], [], 'faulty', null);

        $counts = $this->tab('all')['counts'];

        foreach (OrderState::TABS as $tab) {
            $this->assertSame($counts[$tab], $this->tab($tab)['total'], "count for $tab");
        }

        $this->assertSame(['all' => 6, 'processing' => 1, 'shipped' => 1, 'delivered' => 2, 'cancelled' => 2, 'returns' => 2], $counts);
    }

    public function test_counts_cover_every_page_not_just_the_one_shown()
    {
        // one page is constants.frontend.PAGINATION (25) orders
        $per = (int)config('constants.frontend.PAGINATION');
        for ($i = 0; $i < $per + 3; $i++) {
            $this->cancelled(['payment_done' => 0]);
        }
        $this->delivered();

        $first = $this->tab('all');
        $this->assertSame($per + 4, $first['counts']['all']);
        $this->assertSame($per + 3, $first['counts']['cancelled']);
        $this->assertSame($per, count($first['data']), 'paginated');

        $cancelled = $this->tab('cancelled', ['page' => 2]);
        $this->assertSame($per + 3, $cancelled['total']);
        $this->assertSame(2, $cancelled['current_page']);
        $this->assertNotEmpty($cancelled['data']);
    }

    public function test_search_works_on_the_server_and_narrows_the_counts()
    {
        $a = $this->delivered(['order' => 'ORD1111111']);
        $this->delivered(['order' => 'ORD2222222']);

        $found = $this->tab('all', ['q' => '#ORD111']);
        $this->assertSame([$a->id], $this->ids($found));
        $this->assertSame(1, $found['counts']['all']);
        $this->assertSame(1, $found['counts']['delivered']);
    }

    public function test_a_customer_never_sees_another_customers_orders()
    {
        $mine = $this->delivered();
        $theirs = $this->order(['status' => (int)config('constants.orderStatus.DELIVERED')], Fixture::USER_BUSINESS_ID);
        $theirsUnpaid = $this->order(['payment_done' => 0], Fixture::USER_BUSINESS_ID);

        // paid=1&unpaid=1 used to read as "paid OR (unpaid AND mine)"
        foreach ([['paid' => 1, 'unpaid' => 1], ['card_payment' => 1, 'cash_on_delivery' => 1], []] as $filters) {
            $ids = $this->ids($this->tab('all', $filters));
            $this->assertContains($mine->id, $ids);
            $this->assertNotContains($theirs->id, $ids, json_encode($filters));
            $this->assertNotContains($theirsUnpaid->id, $ids, json_encode($filters));
        }
    }

    public function test_an_unknown_tab_is_all()
    {
        $this->delivered();
        $this->assertSame('all', $this->tab('anything')['tab']);
    }

    // ------------------------------------------------------- admin side

    public function test_the_admin_list_carries_each_status_separately()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);
        $order = $this->delivered();
        OrderReturns::open($order, [$this->line($order) => 1], 'faulty', null, null, 1, false);

        $response = $this->asAdmin()->getJson('/api/admin/order/all?orderby=created_at&type=desc');
        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertSame('delivered', $row['state']['order']);
        $this->assertSame('approved', $row['state']['return']['key']);
        $this->assertSame('pending', $row['state']['refund']['key']);

        $this->app['auth']->forgetGuards();
        $chip = $this->asAdmin()->getJson('/api/admin/order/all?orderby=created_at&type=desc&filter=returns');
        $this->assertContains($order->id, collect($chip->json('data.data'))->pluck('id')->all());
    }

    public function test_an_admin_status_change_does_not_touch_the_return_or_its_refund()
    {
        $order = $this->delivered();
        $return = OrderReturns::open($order, [$this->line($order) => 1], 'faulty', null, null, 1, false);

        // a legacy manual move the dropdown still allows
        Order::where('id', $order->id)->update(['status' => (int)config('constants.orderStatus.RETURNED')]);

        $this->assertSame(OrderReturn::AWAITING, $return->fresh()->status);
        $state = OrderState::forOrder($order->fresh());
        $this->assertSame('returned', $state['order']);
        $this->assertSame('approved', $state['return']['key']);
        $this->assertSame('pending', $state['refund']['key'], 'no refund appears just because the status moved');
    }
}
