<?php

namespace Tests\Isolated;

use App\Models\Helper\OrderExchanges;
use App\Models\Helper\OrderReturns;
use App\Models\Helper\ReturnRefundCalculator;
use App\Models\Helper\ReturnRefused;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\UpdatedInventory;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatedTestCase;

/**
 * Exchanges (App\Models\Helper\OrderExchanges, 2026-10-19).
 *
 * Agreed with the user: same product, another variant; staff record any
 * payment for a dearer one; nothing ships until the old unit is back.
 *
 * What these pin:
 *  - the swap is a new, confirmed, paid order carrying only what was paid on
 *    top; the old order keeps its rows, its status and its revenue;
 *  - stock: the old unit back only if restocked, the new unit taken once;
 *  - a dearer swap needs a payment reference (or a waived 0), a cheaper one
 *    pays the difference back through Refunder, an equal one moves nothing;
 *  - an order whose every unit was exchanged is NOT returned;
 *  - out of stock, wrong product, same variant: refused, nothing recorded.
 */
class OrderExchangeTest extends IsolatedTestCase
{
    private $shippingPlaceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shippingPlaceId = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('payments')->update(['sumup' => 1, 'sumup_api_key' => 'sup_test_key']);
        UpdatedInventory::whereIn('id', [Fixture::PHONE_128_BLACK_ID, Fixture::PHONE_128_WHITE_ID,
            Fixture::PHONE_256_BLACK_ID, Fixture::PHONE_256_WHITE_ID])->update(['quantity' => 10, 'is_active' => 0]);
    }

    /** A delivered, paid SumUp order of one phone line. */
    private function phoneOrder($inventoryId, $qty, $unit)
    {
        $orderId = DB::table('orders')->insertGetId([
            'order' => 'ORD' . random_int(1000000, 9999999), 'user_id' => Fixture::USER_PERSONAL_ID,
            'status' => (int)config('constants.orderStatus.DELIVERED'), 'order_method' => (int)config('constants.paymentMethod.SUMUP'),
            'payment_done' => 1, 'cancelled' => 2, 'total_amount' => $qty * $unit, 'wallet_amount' => 0, 'currency' => 'EUR',
            'trans_id' => 'TX-' . random_int(1000, 9999), 'contact_name' => 'Fixture Customer', 'contact_email' => 'customer@fixture.test',
            'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5),
        ]);

        DB::table('ordered_products')->insert(['order_id' => $orderId, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => $inventoryId, 'quantity' => $qty, 'selling' => $unit, 'tax_price' => 0, 'tax_rate' => 23,
            'tax_inclusive' => 1, 'bundle_offer' => 0, 'shipping_place_id' => $this->shippingPlaceId, 'shipping_type' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('ordered_products_inventories')->insert(['order_id' => $orderId, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => $inventoryId, 'quantity' => $qty, 'actual_price' => $unit, 'price' => $unit, 'shipping_price' => 0,
            'product_voucher_discount_applied' => 0, 'created_at' => now(), 'updated_at' => now()]);

        OrderStatusHistory::create(['order_id' => $orderId, 'from_status' => 4,
            'to_status' => (int)config('constants.orderStatus.DELIVERED'), 'changed_by_type' => 'system']);

        return Order::with('ordered_products')->find($orderId);
    }

    private function lineId(Order $order)
    {
        return OrderedProduct::where('order_id', $order->id)->value('id');
    }

    private function stock($id)
    {
        return (int)UpdatedInventory::where('id', $id)->value('quantity');
    }

    /** Open an exchange of $qty units to $to and mark it received. */
    private function receivedExchange(Order $order, $to, $qty = 1, $restock = true)
    {
        $line = $this->lineId($order);
        $return = OrderReturns::open($order, [$line => $qty], 'changed_mind', null, null, Fixture::ADMIN_SUPER_ID, false, null, [$line => $to]);

        return OrderReturns::receive($return, [$return->items->first()->id => $restock], null, Fixture::ADMIN_SUPER_ID);
    }

    // ------------------------------------------------------------------ dearer

    public function test_a_dearer_variant_becomes_a_new_order_for_the_difference()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $old = $this->stock(Fixture::PHONE_128_BLACK_ID);
        $new = $this->stock(Fixture::PHONE_256_BLACK_ID);

        $return = $this->receivedExchange($order, Fixture::PHONE_256_BLACK_ID);
        $this->assertEquals(100.00, OrderExchanges::quote($return)['difference'], '600 - 500');

        $done = OrderExchanges::complete($return, null, 'TERMINAL-778', null, null, Fixture::ADMIN_SUPER_ID);

        $this->assertSame(OrderReturn::EXCHANGED, $done->status);
        $this->assertEquals(100.00, $done->amount_charged);
        $this->assertSame('TERMINAL-778', $done->payment_reference);

        $replacement = Order::with('ordered_products')->find($done->replacement_order_id);
        $this->assertSame((int)config('constants.orderStatus.CONFIRMED'), (int)$replacement->status, 'joins the fulfilment queue');
        $this->assertSame(1, (int)$replacement->payment_done);
        $this->assertEquals(100.00, (float)$replacement->total_amount);
        $this->assertSame(Fixture::PHONE_256_BLACK_ID, (int)$replacement->ordered_products->first()->inventory_id);
        $this->assertEquals(100.00, \App\Models\Helper\OrderSummary::for($replacement)['total'], 'its receipt adds up to what was paid');
        $this->assertEquals(600.00, DB::table('ordered_products_inventories')->where('order_id', $replacement->id)->value('actual_price'),
            'the line states what the variant is worth');

        $this->assertSame($old + 1, $this->stock(Fixture::PHONE_128_BLACK_ID), 'old unit back on the shelf');
        $this->assertSame($new - 1, $this->stock(Fixture::PHONE_256_BLACK_ID), 'new unit taken once');

        $fresh = $order->fresh();
        $this->assertSame((int)config('constants.orderStatus.DELIVERED'), (int)$fresh->status,
            'every unit was exchanged, but the sale stands: not returned');
        $this->assertEquals(500.00, ReturnRefundCalculator::moneyLeft($fresh), 'nothing came off the original order');
    }

    public function test_a_dearer_swap_needs_a_payment_reference_and_cannot_overcharge()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $return = $this->receivedExchange($order, Fixture::PHONE_256_BLACK_ID);

        foreach ([[null, null, 'where the payment was taken'], [150.00, 'REF', 'at most 100.00']] as [$amount, $ref, $msg]) {
            try {
                OrderExchanges::complete($return->fresh('items'), $amount, $ref, null, null, 1);
                $this->fail('accepted: ' . $msg);
            } catch (ReturnRefused $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }

        $this->assertSame(OrderReturn::RECEIVED, $return->fresh()->status, 'refused, nothing recorded');
        $this->assertNull($return->fresh()->replacement_order_id);
    }

    public function test_staff_can_waive_the_difference()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $done = OrderExchanges::complete($this->receivedExchange($order, Fixture::PHONE_256_BLACK_ID), 0, null, null, 'goodwill', 1);

        $this->assertEquals(0.0, $done->amount_charged);
        $this->assertEquals(0.0, (float)Order::find($done->replacement_order_id)->total_amount);
    }

    public function test_the_charge_is_shared_across_units_and_the_order_total_matches_it()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 3, 500.00);
        $return = $this->receivedExchange($order, Fixture::PHONE_256_BLACK_ID, 3);

        // 3 x 100 owed; staff took 100.00, which does not split evenly over 3 units.
        $done = OrderExchanges::complete($return, 100.00, 'TILL-9', null, null, 1);
        $replacement = Order::find($done->replacement_order_id);

        $this->assertEquals((float)$replacement->total_amount, $done->amount_charged, 'case and order agree');
        $this->assertEquals(\App\Models\Helper\OrderSummary::for($replacement->load('ordered_products'))['total'], (float)$replacement->total_amount);
    }

    // --------------------------------------------------------- same / cheaper

    public function test_an_equal_price_swap_moves_no_money()
    {
        Http::fake();
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $done = OrderExchanges::complete($this->receivedExchange($order, Fixture::PHONE_128_WHITE_ID), null, null, null, null, 1);

        $this->assertEquals(0.0, $done->exchange_difference);
        $this->assertEquals(0.0, $done->amount_charged);
        $this->assertEquals(0.0, $done->refund_amount);
        Http::assertNothingSent();
    }

    public function test_a_cheaper_variant_pays_the_difference_back()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);
        $order = $this->phoneOrder(Fixture::PHONE_256_BLACK_ID, 1, 600.00);

        $done = OrderExchanges::complete($this->receivedExchange($order, Fixture::PHONE_128_BLACK_ID), null, null, 'original', null, 1);

        $this->assertEquals(-100.00, $done->exchange_difference);
        $this->assertEquals(100.00, $done->refund_amount);
        $this->assertEquals(500.00, ReturnRefundCalculator::moneyLeft($order->fresh()), 'the refund is counted against the order');

        $calls = collect(Http::recorded())->filter(function ($p) { return strpos($p[0]->url(), '/me/refund/') !== false; });
        $this->assertCount(1, $calls);
        $this->assertEquals(100.0, (float)$calls->first()[0]->data()['amount']);
    }

    // ---------------------------------------------------------------- refusals

    public function test_an_out_of_stock_variant_is_refused_and_nothing_is_recorded()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $return = $this->receivedExchange($order, Fixture::PHONE_256_BLACK_ID);
        UpdatedInventory::where('id', Fixture::PHONE_256_BLACK_ID)->update(['quantity' => 0, 'is_active' => 0]);

        try {
            OrderExchanges::complete($return, 0, null, null, null, 1);
            $this->fail('swapped to a variant with no stock');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('out of stock', $e->getMessage());
        }

        $this->assertSame(OrderReturn::RECEIVED, $return->fresh()->status);
        $this->assertSame(0, $this->stock(Fixture::PHONE_256_BLACK_ID));
        $this->assertSame(1, Order::where('user_id', Fixture::USER_PERSONAL_ID)->where('id', '>=', $order->id)->count(), 'no replacement order');
    }

    public function test_a_backorder_variant_can_be_swapped_to()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $return = $this->receivedExchange($order, Fixture::PHONE_128_WHITE_ID);
        // qty 0 + is_active 1 is a backorder, which is sellable
        UpdatedInventory::where('id', Fixture::PHONE_128_WHITE_ID)->update(['quantity' => 0, 'is_active' => 1]);

        $this->assertSame(OrderReturn::EXCHANGED, OrderExchanges::complete($return, null, null, null, null, 1)->status);
    }

    public function test_the_target_must_be_another_variant_of_the_same_product()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $line = $this->lineId($order);

        foreach ([Fixture::PHONE_128_BLACK_ID, Fixture::REFURB_INVENTORY_ID] as $bad) {
            try {
                OrderReturns::open($order, [$line => 1], 'faulty', null, null, 1, false, null, [$line => $bad]);
                $this->fail('accepted target ' . $bad);
            } catch (ReturnRefused $e) {
                $this->assertStringContainsString('another variant of the same product', $e->getMessage());
            }
        }
    }

    public function test_an_exchange_completes_once_and_only_after_the_item_arrives()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $line = $this->lineId($order);
        $return = OrderReturns::open($order, [$line => 1], 'faulty', null, null, 1, false, null, [$line => Fixture::PHONE_128_WHITE_ID]);

        try {
            OrderExchanges::complete($return, null, null, null, null, 1);
            $this->fail('completed before the old item arrived');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('received first', $e->getMessage());
        }

        $return = OrderReturns::receive($return, [], null, 1);
        $done = OrderExchanges::complete($return, null, null, null, null, 1);

        $this->expectException(ReturnRefused::class);
        OrderExchanges::complete($done, null, null, null, null, 1);
    }

    public function test_an_exchange_can_still_be_refunded_instead()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $return = $this->receivedExchange($order, Fixture::PHONE_256_BLACK_ID);

        // the new variant ran out, so the customer takes their money instead
        $done = OrderReturns::refund($return, 'original', null, false, null, null, 1);

        $this->assertSame(OrderReturn::REFUNDED, $done->status);
        $this->assertEquals(500.00, $done->refund_amount);
        $this->assertSame((int)config('constants.orderStatus.RETURNED'), (int)$order->fresh()->status);
    }

    // ------------------------------------------------- email, customer, API

    public function test_the_customer_is_told_the_replacement_order()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $return = $this->receivedExchange($order, Fixture::PHONE_256_BLACK_ID);
        Mail::getSwiftMailer()->getTransport()->flush();

        $done = OrderExchanges::complete($return, null, 'TERM-1', null, null, 1);
        $replacement = Order::find($done->replacement_order_id);

        $messages = Mail::getSwiftMailer()->getTransport()->messages();
        $messages = is_array($messages) ? $messages : $messages->all();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Replacement on its way', $messages[0]->getSubject());
        $this->assertStringContainsString($replacement->order, $messages[0]->getBody());

        $view = OrderReturns::forCustomer($order->fresh())[0];
        $this->assertSame('exchange', $view['type']);
        $this->assertSame('Exchanged', $view['status_label']);
        $this->assertSame($replacement->order, $view['replacement_order']['order']);
        $this->assertEquals(100.00, $view['amount_charged']);
        $this->assertNull($view['refund_amount'], 'nothing was refunded');
    }

    public function test_staff_run_an_exchange_through_the_api()
    {
        $order = $this->phoneOrder(Fixture::PHONE_128_BLACK_ID, 1, 500.00);
        $line = $this->lineId($order);

        $view = $this->asAdmin()->getJson('/api/admin/order-return/order/' . $order->id);
        $this->assertApiOk($view);
        $variants = collect($view->json('data.lines.0.variants'));
        $this->assertNotContains(Fixture::PHONE_128_BLACK_ID, $variants->pluck('inventory_id')->all(), 'not the variant they have');
        $this->assertEquals(600.00, $variants->firstWhere('inventory_id', Fixture::PHONE_256_BLACK_ID)['unit_price']);

        $created = $this->asAdmin()->postJson('/api/admin/order-return/create', [
            'order_id' => $order->id, 'items' => [$line => 1], 'exchange' => [$line => Fixture::PHONE_256_BLACK_ID],
            'reason' => 'changed_mind', 'notify' => false,
        ]);
        $this->assertApiOk($created);
        $this->assertSame('exchange', $created->json('data.type'));
        $this->assertEquals(100.00, $created->json('data.exchange_quote.difference'));
        $id = $created->json('data.id');

        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/order-return/action/' . $id, [
            'action' => 'receive', 'restock' => [$created->json('data.items.0.id') => true],
        ]));

        $done = $this->asAdmin()->postJson('/api/admin/order-return/action/' . $id, [
            'action' => 'complete_exchange', 'reference' => 'TERM-55',
        ]);
        $this->assertApiOk($done);
        $this->assertSame('exchanged', $done->json('data.status'));
        $this->assertNotNull($done->json('data.replacement_order.order'));
    }
}
