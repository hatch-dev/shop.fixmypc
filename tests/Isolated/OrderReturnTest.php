<?php

namespace Tests\Isolated;

use App\Models\Helper\OrderEmailEvent;
use App\Models\Helper\OrderReturns;
use App\Models\Helper\Refunder;
use App\Models\Helper\ReturnRefundCalculator;
use App\Models\Helper\ReturnRefused;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\PointTransaction;
use App\Models\UpdatedInventory;
use App\Models\Voucher;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatedTestCase;

/**
 * Returns, phase 1 (App\Models\Helper\OrderReturns, 2026-10-19).
 *
 * What these pin:
 *  - a returned item refunds what it actually cost: order-wide discounts are
 *    shared across the lines by what each paid, and every return of an order
 *    together refunds exactly what it took, to the cent;
 *  - stock goes back only for units staff restock, and never twice -- not
 *    when the order later moves to RETURNED either;
 *  - a gift card's share goes back on the card, points spent come back as
 *    points, points earned are taken back, none of it twice;
 *  - money moves once per case, through Refunder, and a failed gateway call
 *    records nothing;
 *  - the admin API is behind order.view / order.edit and closed to vendors.
 *
 * Orders are written directly (not through checkout) so every figure is known.
 */
class OrderReturnTest extends IsolatedTestCase
{
    private $shippingPlaceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shippingPlaceId = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('payments')->update(['sumup' => 1, 'sumup_api_key' => 'sup_test_key']);
    }

    /**
     * A delivered, paid SumUp order.
     *
     * @param  array  $lines  [[inventory_id, product_id, qty, unit price inc VAT], ...]
     */
    private function deliveredOrder(array $lines, array $opts = [])
    {
        $goods = 0;
        foreach ($lines as $l) {
            $goods += $l[2] * $l[3];
        }

        $shipping = $opts['shipping'] ?? 0;
        $discount = ($opts['voucher'] ?? 0) + ($opts['points_value'] ?? 0);
        $total = round($goods + $shipping - $discount, 2);

        $orderId = DB::table('orders')->insertGetId([
            'order' => 'ORD' . random_int(1000000, 9999999),
            'user_id' => $opts['user_id'] ?? Fixture::USER_PERSONAL_ID,
            'status' => (int)config('constants.orderStatus.DELIVERED'),
            'order_method' => $opts['method'] ?? (int)config('constants.paymentMethod.SUMUP'),
            'payment_done' => 1,
            'cancelled' => 2,
            'total_amount' => $total,
            'wallet_amount' => $opts['wallet'] ?? 0,
            'currency' => 'EUR',
            'trans_id' => 'TX-' . random_int(1000, 9999),
            'voucher_id' => $opts['voucher_id'] ?? null,
            'contact_name' => 'Fixture Customer',
            'contact_email' => 'customer@fixture.test',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        foreach ($lines as $i => $l) {
            DB::table('ordered_products')->insert([
                'order_id' => $orderId,
                'product_id' => $l[1],
                'inventory_id' => $l[0],
                'quantity' => $l[2],
                'selling' => $l[3],
                'tax_price' => 0,
                'tax_rate' => 23,
                'tax_inclusive' => 1,
                'bundle_offer' => 0,
                'shipping_place_id' => $this->shippingPlaceId,
                'shipping_type' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('ordered_products_inventories')->insert([
                'order_id' => $orderId,
                'product_id' => $l[1],
                'inventory_id' => $l[0],
                'quantity' => $l[2],
                'actual_price' => $l[3],
                'price' => $l[3],
                'shipping_price' => $i === 0 ? $shipping : 0,
                'product_voucher_discount_applied' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (!empty($opts['voucher'])) {
            DB::table('ordered_voucher')->insert([
                'order_id' => $orderId,
                'voucher_id' => $opts['voucher_id'],
                'discount' => $opts['voucher'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (!empty($opts['points_spent'])) {
            PointTransaction::create(['user_id' => Fixture::USER_PERSONAL_ID, 'type' => 'debit',
                'points' => $opts['points_spent'], 'value' => $opts['points_value'], 'source' => 'order', 'reference_id' => $orderId]);
        }

        if (!empty($opts['points_earned'])) {
            PointTransaction::create(['user_id' => Fixture::USER_PERSONAL_ID, 'type' => 'credit',
                'points' => $opts['points_earned'], 'value' => round($opts['points_earned'] * 0.01, 2), 'source' => 'order', 'reference_id' => $orderId]);
        }

        OrderStatusHistory::create(['order_id' => $orderId, 'from_status' => 4,
            'to_status' => (int)config('constants.orderStatus.DELIVERED'), 'changed_by_type' => 'system']);

        return Order::with('ordered_products')->find($orderId);
    }

    /** Two phones at 500 and one refurb at 300, with a 100 voucher off the order. */
    private function voucherOrder()
    {
        $voucherId = DB::table('vouchers')->insertGetId([
            'title' => 'TEN', 'code' => 'RET-' . random_int(1000, 9999), 'type' => 1, 'price' => 100,
            'status' => 1, 'usage_limit' => 100, 'limit_per_customer' => 10, 'min_spend' => 0,
            'admin_id' => Fixture::ADMIN_SUPER_ID, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->deliveredOrder([
            [Fixture::PHONE_128_BLACK_ID, Fixture::PRODUCT_PHONE_ID, 2, 500.00],
            [Fixture::REFURB_INVENTORY_ID, Fixture::PRODUCT_REFURB_ID, 1, 300.00],
        ], ['voucher' => 100.00, 'voucher_id' => $voucherId, 'shipping' => 16.00]);
    }

    private function line(Order $order, $inventoryId)
    {
        return OrderedProduct::where('order_id', $order->id)->where('inventory_id', $inventoryId)->value('id');
    }

    private function stock($inventoryId)
    {
        return (int)UpdatedInventory::where('id', $inventoryId)->value('quantity');
    }

    private function fakeRefunds()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);
    }

    private function refundCalls()
    {
        return collect(Http::recorded())->filter(function ($pair) {
            return strpos($pair[0]->url(), '/me/refund/') !== false;
        })->map(function ($pair) {
            return (float)($pair[0]->data()['amount'] ?? 0);
        })->values()->all();
    }

    /** Open, receive (restocking everything unless told otherwise) and refund. */
    private function returnAndRefund(Order $order, array $quantities, array $refund = [], $restock = true)
    {
        $return = OrderReturns::open($order, $quantities, 'changed_mind', null, null, Fixture::ADMIN_SUPER_ID, false);
        $return = OrderReturns::receive($return, $return->items->mapWithKeys(function ($i) use ($restock) {
            return [$i->id => $restock];
        })->all(), null, Fixture::ADMIN_SUPER_ID);

        return OrderReturns::refund($return, $refund['method'] ?? 'original', $refund['amount'] ?? null,
            $refund['shipping'] ?? false, $refund['reference'] ?? null, null, Fixture::ADMIN_SUPER_ID);
    }

    // ------------------------------------------------------------------ money

    public function test_one_unit_refunds_its_share_of_the_order_wide_voucher()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();

        $return = $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1]);

        // 1000 of 1300 paid by the phones takes 76.93 of the voucher; half of the rest.
        $this->assertSame(OrderReturn::REFUNDED, $return->status);
        $this->assertEquals(461.54, $return->refund_amount);
        $this->assertSame([461.54], $this->refundCalls(), 'the gateway was asked for the same figure');
        $this->assertSame((int)config('constants.orderStatus.DELIVERED'), (int)$order->fresh()->status,
            'a partial return leaves the order delivered');
    }

    public function test_every_return_together_refunds_exactly_what_the_order_took()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);
        $refurb = $this->line($order, Fixture::REFURB_INVENTORY_ID);

        $a = $this->returnAndRefund($order, [$phone => 1]);
        $b = $this->returnAndRefund($order, [$refurb => 1]);
        $c = $this->returnAndRefund($order, [$phone => 1], ['shipping' => true]);

        $this->assertEquals(1216.00, round($a->refund_amount + $b->refund_amount + $c->refund_amount, 2),
            'goods 1300 - voucher 100 + delivery 16');
        $this->assertEquals(0, ReturnRefundCalculator::moneyLeft($order->fresh()));
        $this->assertSame((int)config('constants.orderStatus.RETURNED'), (int)$order->fresh()->status,
            'the last unit back makes the order returned');
    }

    public function test_the_refund_can_be_lowered_but_never_raised_above_the_quote()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);

        $return = OrderReturns::open($order, [$phone => 1], 'changed_mind', null, null, Fixture::ADMIN_SUPER_ID, false);
        $return = OrderReturns::receive($return, [], null, Fixture::ADMIN_SUPER_ID);

        try {
            OrderReturns::refund($return, 'original', 461.55, false, null, null, Fixture::ADMIN_SUPER_ID);
            $this->fail('a refund above the quote was accepted');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('at most 461.54', $e->getMessage());
        }

        $done = OrderReturns::refund($return->fresh('items'), 'original', 440.00, false, null, 'restocking fee', Fixture::ADMIN_SUPER_ID);
        $this->assertEquals(440.00, $done->refund_amount);

        // The item still used up its full share, so the other phone is not given the deduction.
        $this->assertEquals(461.54, $done->items->first()->line_refund);
        $next = ReturnRefundCalculator::quote($order->fresh(), [$phone => 1]);
        $this->assertEquals(461.53, $next['refund']);
    }

    public function test_delivery_is_refunded_only_when_asked_and_only_once()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();

        $q = ReturnRefundCalculator::quote($order, [$this->line($order, Fixture::REFURB_INVENTORY_ID) => 1], true);
        $this->assertEquals(16.00, $q['shipping_refund']);

        $this->returnAndRefund($order, [$this->line($order, Fixture::REFURB_INVENTORY_ID) => 1], ['shipping' => true]);

        $again = ReturnRefundCalculator::quote($order->fresh(), [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1], true);
        $this->assertEquals(0.00, $again['shipping_refund']);
    }

    // ------------------------------------------------------------------ stock

    public function test_stock_comes_back_only_for_restocked_units_and_never_twice()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();
        $phones = $this->stock(Fixture::PHONE_128_BLACK_ID);
        $refurbs = $this->stock(Fixture::REFURB_INVENTORY_ID);

        // Both phones back on the shelf; the refurb arrived damaged.
        $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 2]);
        $this->returnAndRefund($order, [$this->line($order, Fixture::REFURB_INVENTORY_ID) => 1], [], false);

        $this->assertSame((int)config('constants.orderStatus.RETURNED'), (int)$order->fresh()->status);
        $this->assertSame($phones + 2, $this->stock(Fixture::PHONE_128_BLACK_ID), 'restocked once, not again by the order moving to returned');
        $this->assertSame($refurbs, $this->stock(Fixture::REFURB_INVENTORY_ID), 'the damaged unit stays off sale');
    }

    public function test_an_admin_returning_the_whole_order_later_skips_units_already_restocked()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();
        $phones = $this->stock(Fixture::PHONE_128_BLACK_ID);

        $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1]);

        // The old route: status straight to Returned from the order screen.
        Order::where('id', $order->id)->update(['status' => (int)config('constants.orderStatus.RETURNED')]);
        $order->fresh()->restoreStockAndRewards();

        $this->assertSame($phones + 2, $this->stock(Fixture::PHONE_128_BLACK_ID), 'one from the return, one from the order');
    }

    // --------------------------------------------------------------- quantity

    public function test_a_unit_on_an_open_case_cannot_be_returned_again()
    {
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);

        OrderReturns::open($order, [$phone => 2], 'faulty', null, null, Fixture::ADMIN_SUPER_ID, false);

        $this->expectException(ReturnRefused::class);
        OrderReturns::open($order, [$phone => 1], 'faulty', null, null, Fixture::ADMIN_SUPER_ID, false);
    }

    public function test_a_rejected_case_frees_its_units()
    {
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);

        $first = OrderReturns::open($order, [$phone => 2], 'faulty', null, null, Fixture::ADMIN_SUPER_ID, false);
        OrderReturns::reject($first, 'Outside the window', Fixture::ADMIN_SUPER_ID, false);

        $second = OrderReturns::open($order, [$phone => 2], 'faulty', null, null, Fixture::ADMIN_SUPER_ID, false);
        $this->assertSame(OrderReturn::AWAITING, $second->status);
    }

    public function test_an_order_that_is_not_delivered_cannot_be_returned()
    {
        $order = $this->voucherOrder();
        Order::where('id', $order->id)->update(['status' => (int)config('constants.orderStatus.ON_THE_WAY')]);

        $this->expectException(ReturnRefused::class);
        $this->expectExceptionMessage('Only a delivered order');
        OrderReturns::open($order->fresh(), [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1], 'faulty', null, null, 1, false);
    }

    // ---------------------------------------------------------- refund safety

    public function test_a_failed_gateway_refund_records_nothing_and_can_be_retried()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response(['message' => 'declined'], 400)]);
        $order = $this->voucherOrder();

        $return = OrderReturns::open($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1], 'faulty', null, null, 1, false);
        $return = OrderReturns::receive($return, [], null, 1);

        try {
            OrderReturns::refund($return, 'original', null, false, null, null, 1);
            $this->fail('a declined refund was reported as done');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('did not go through', $e->getMessage());
        }

        $fresh = $return->fresh('items');
        $this->assertSame(OrderReturn::RECEIVED, $fresh->status, 'back where it was, so it can be retried');
        $this->assertNotEmpty($fresh->refund_error);
        $this->assertEquals(0, $fresh->items->first()->line_refund);
        $this->assertEquals(1216.00, ReturnRefundCalculator::moneyLeft($order->fresh()), 'no money recorded as refunded');
    }

    public function test_a_case_refunds_once()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();
        $done = $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1]);

        $this->expectException(ReturnRefused::class);
        OrderReturns::refund($done, 'original', null, false, null, null, 1);
    }

    public function test_a_manual_refund_needs_a_reference_and_moves_no_money()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();

        try {
            $this->returnAndRefund($order, [$this->line($order, Fixture::REFURB_INVENTORY_ID) => 1], ['method' => 'manual']);
            $this->fail('a manual refund with no reference was accepted');
        } catch (ReturnRefused $e) {
            $this->assertStringContainsString('where the refund was made', $e->getMessage());
        }

        $return = OrderReturn::where('order_id', $order->id)->first();
        $done = OrderReturns::refund($return->fresh('items'), 'manual', null, false, 'BANK-123', null, 1);

        $this->assertSame(OrderReturn::REFUNDED, $done->status);
        $this->assertSame('BANK-123', $done->refund_reference);
        $this->assertSame([], $this->refundCalls(), 'no gateway call');
    }

    public function test_cash_on_delivery_cannot_be_refunded_through_the_gateway()
    {
        $order = $this->deliveredOrder([[Fixture::REFURB_INVENTORY_ID, Fixture::PRODUCT_REFURB_ID, 1, 300.00]],
            ['method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY')]);

        $this->expectException(ReturnRefused::class);
        $this->expectExceptionMessage('Cash on delivery');
        $this->returnAndRefund($order, [$this->line($order, Fixture::REFURB_INVENTORY_ID) => 1]);
    }

    public function test_closing_without_a_refund_still_uses_up_the_share()
    {
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);

        $closed = $this->returnAndRefund($order, [$phone => 1], ['method' => 'none']);
        $this->assertSame(OrderReturn::CLOSED, $closed->status);
        $this->assertEquals(0, $closed->refund_amount);

        $this->assertEquals(461.53, ReturnRefundCalculator::quote($order->fresh(), [$phone => 1])['refund'],
            'the second phone is not handed the first one\'s share');
    }

    public function test_a_second_partial_refund_on_a_split_payment_does_not_overpay_the_card()
    {
        $this->fakeRefunds();
        \App\Models\Wallet::updateOrCreate(['user_id' => Fixture::USER_PERSONAL_ID], ['balance' => 0, 'currency' => 'EUR']);

        // 600 order, 100 of it from the wallet: the card paid 500.
        $order = $this->deliveredOrder([[Fixture::PHONE_128_BLACK_ID, Fixture::PRODUCT_PHONE_ID, 1, 600.00]], ['wallet' => 100.00]);
        \App\Models\WalletTransaction::create(['user_id' => Fixture::USER_PERSONAL_ID, 'type' => 'debit', 'amount' => 100,
            'balance_after' => 0, 'source' => 'order', 'reference_id' => $order->id, 'status' => 'success']);

        // 450 already back on the card; another 100 is 50 to the card and 50 to the wallet.
        $result = Refunder::refund($order, 100.00, ['key' => 'refund-return-x', 'refunded' => 450.00]);

        $this->assertTrue($result['ok'], (string)$result['reason']);
        $this->assertSame([50.0], $this->refundCalls());
        $this->assertEquals(50.00, (float)\App\Models\Wallet::where('user_id', Fixture::USER_PERSONAL_ID)->value('balance'));
    }

    // -------------------------------------------------- gift cards and points

    public function test_a_gift_card_share_goes_back_on_the_card_not_to_the_bank()
    {
        $this->fakeRefunds();

        $voucherId = DB::table('vouchers')->insertGetId([
            'title' => 'Gift card', 'code' => 'GIFT-RET1-' . random_int(1000, 9999), 'type' => 1, 'price' => 200, 'gift_value' => 200,
            'status' => 1, 'usage_limit' => 1000000, 'limit_per_customer' => 1000000, 'min_spend' => 0,
            'user_id' => Fixture::USER_PERSONAL_ID, 'admin_id' => Fixture::ADMIN_SUPER_ID, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 1000 of goods, 200 of it paid with the gift card.
        $order = $this->deliveredOrder([[Fixture::PHONE_128_BLACK_ID, Fixture::PRODUCT_PHONE_ID, 2, 500.00]],
            ['voucher' => 200.00, 'voucher_id' => $voucherId]);

        $card = Voucher::find($voucherId);
        $this->assertEquals(0.00, $card->giftBalance());

        $return = $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1]);

        $this->assertEquals(400.00, $return->refund_amount, 'money: 500 less the card\'s 100');
        $this->assertEquals(100.00, $return->gift_card_amount);
        $this->assertEquals(100.00, $card->giftBalance(), 'the card has its share back');

        // The rest of the order: the card is whole again, and not over-filled.
        $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1]);
        $this->assertEquals(200.00, $card->giftBalance());
    }

    public function test_points_spent_come_back_and_points_earned_are_taken_back_once()
    {
        $this->fakeRefunds();
        PointTransaction::where('user_id', Fixture::USER_PERSONAL_ID)->delete();
        // The 1000 points the customer had before the order, and spent on it.
        PointTransaction::create(['user_id' => Fixture::USER_PERSONAL_ID, 'type' => 'credit', 'points' => 1000,
            'value' => 10.00, 'source' => 'admin', 'reference_id' => 'opening']);

        // 2 x 500, 10.00 paid in points (1000 points), 100 points earned on delivery.
        $order = $this->deliveredOrder([[Fixture::PHONE_128_BLACK_ID, Fixture::PRODUCT_PHONE_ID, 2, 500.00]],
            ['points_spent' => 1000, 'points_value' => 10.00, 'points_earned' => 100]);
        $start = PointTransaction::balanceFor(Fixture::USER_PERSONAL_ID);

        $first = $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1]);
        $this->assertEquals(495.00, $first->refund_amount, 'the points paid 5.00 of this phone');
        $this->assertSame(500, $first->points_returned);
        $this->assertSame(50, $first->points_taken);

        $second = $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1]);
        $this->assertSame((int)config('constants.orderStatus.RETURNED'), (int)$order->fresh()->status);

        // Net over both returns and the order moving to returned: +1000 spent back, -100 earned back,
        // which is exactly what the customer held before the order.
        $this->assertSame(100, $start);
        $this->assertSame(1000, PointTransaction::balanceFor(Fixture::USER_PERSONAL_ID));
        $this->assertSame(500, $second->points_returned);
    }

    // ------------------------------------------------------------------ email

    public function test_each_case_gets_its_own_email_rows()
    {
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);

        $a = OrderReturns::open($order, [$phone => 1], 'faulty', null, null, 1, true);
        $b = OrderReturns::open($order, [$phone => 1], 'faulty', null, null, 1, true);

        $events = DB::table('order_email_log')->where('order_id', $order->id)->pluck('event')->all();

        $this->assertContains('return_opened:' . $a->id, $events);
        $this->assertContains('return_opened:' . $b->id, $events, 'a second case on the same order is not swallowed by the first');
        $this->assertTrue(OrderEmailEvent::isKnown('return_refunded:' . $a->id));
        $this->assertFalse(OrderEmailEvent::isKnown('return_unknown:' . $a->id));
        $this->assertSame('return-opened', OrderEmailEvent::category('return_opened:' . $a->id));
    }

    // -------------------------------------------------- what the customer sees

    public function test_the_customer_sees_their_returns_without_staff_details()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);

        $open = OrderReturns::open($order, [$phone => 1], 'faulty', 'Screen flickers', 'Staff only: check box seal', 1, false);
        $done = $this->returnAndRefund($order, [$phone => 1]);

        $view = OrderReturns::forCustomer($order->fresh());
        $this->assertCount(2, $view);

        $byNumber = collect($view)->keyBy('rma_number');
        $waiting = $byNumber[$open->rma_number];
        $refunded = $byNumber[$done->rma_number];

        $this->assertSame('Awaiting your return', $waiting['status_label']);
        $this->assertNull($waiting['refund_amount'], 'no money shown before it goes back');
        $this->assertEquals(461.54, $refunded['refund_amount']);
        $this->assertSame(1, $refunded['items'][0]['quantity']);

        $json = json_encode($view);
        $this->assertStringNotContainsString('Staff only', $json);
        $this->assertStringNotContainsString('staff_note', $json);
        $this->assertStringNotContainsString('history', $json);
    }

    public function test_the_customer_view_is_translated()
    {
        $order = $this->voucherOrder();
        OrderReturns::open($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1], 'damaged', null, null, 1, false);

        $fr = OrderReturns::forCustomer($order, 'fr')[0];

        $this->assertSame('En attente de votre retour', $fr['status_label']);
        $this->assertSame('Arrivé endommagé', $fr['reason_label']);
    }

    public function test_the_storefront_order_page_carries_the_returns()
    {
        $order = $this->voucherOrder();
        OrderReturns::open($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1], 'faulty', null, 'internal', 1, false);

        $response = $this->asCustomer()->postJson('/api/v1/order/by-user', ['order_id' => $order->id, 'time_zone' => 'Europe/Dublin']);

        $this->assertApiOk($response);
        $this->assertCount(1, $response->json('data.returns'));
        $this->assertStringStartsWith('RMA', $response->json('data.returns.0.rma_number'));
        $this->assertStringNotContainsString('internal', json_encode($response->json('data.returns')));
    }

    public function test_return_emails_go_out_in_the_language_staff_chose()
    {
        $order = $this->voucherOrder();
        Mail::getSwiftMailer()->getTransport()->flush();

        $return = OrderReturns::open($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1], 'faulty', null, null, 1, true, 'fr');

        $this->assertSame('fr', $return->lang);

        $messages = Mail::getSwiftMailer()->getTransport()->messages();
        $messages = is_array($messages) ? $messages : $messages->all();
        $this->assertCount(1, $messages, 'one email for the opened return');

        $subject = $messages[0]->getSubject();
        $this->assertStringContainsString('Retour ouvert', $subject);
        $this->assertStringContainsString($return->rma_number, $subject);
        $this->assertStringContainsString('Numéro de retour', $messages[0]->getBody());
    }

    public function test_the_refunded_and_rejected_emails_are_actually_sent()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);
        $transport = Mail::getSwiftMailer()->getTransport();

        $subjects = function () use ($transport) {
            $m = $transport->messages();
            return collect(is_array($m) ? $m : $m->all())->map(function ($x) { return $x->getSubject(); })->all();
        };

        $refunded = $this->returnAndRefund($order, [$phone => 1]);
        $transport->flush();
        // The refund email was raised inside returnAndRefund; raise the next one on a clean outbox.
        $rejected = OrderReturns::open($order, [$phone => 1], 'faulty', null, null, 1, false);
        OrderReturns::reject($rejected, 'Outside the window', 1, true);

        $this->assertCount(1, $subjects());
        $this->assertStringContainsString('Return not accepted - ' . $rejected->rma_number, $subjects()[0]);

        $this->assertSame('sent', DB::table('order_email_log')->where('order_id', $order->id)
            ->where('event', 'return_refunded:' . $refunded->id)->value('status'), 'the refund email went out');
    }
    public function test_an_unknown_email_language_falls_back_to_the_default()
    {
        $order = $this->voucherOrder();
        $return = OrderReturns::open($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1], 'faulty', null, null, 1, false, 'xx');

        $this->assertNull($return->lang);
    }

    // -------------------------------------------------------------- dashboard

    public function test_the_dashboard_counts_sales_net_of_return_refunds()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();

        $read = function () {
            $this->app['auth']->forgetGuards();
            $r = $this->asAdmin()->getJson('/api/admin/dashboard?dashboard=false&range_preset=d30&time_zone=Europe/Dublin');
            $this->assertApiOk($r);
            return $r->json('data');
        };

        $before = $read();
        $this->returnAndRefund($order, [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1]);
        $after = $read();

        $summary = function ($d, $k) { return round((float)$d['chart_data']['summary'][$k], 2); };

        $this->assertEquals(461.54, round($summary($before, 'total') - $summary($after, 'total'), 2), 'period total');
        $this->assertEquals(461.54, round($summary($before, 'received') - $summary($after, 'received'), 2), 'money received');
        $this->assertEquals($summary($before, 'pending'), $summary($after, 'pending'), 'pending is untouched');
        $this->assertEquals(461.54, $summary($after, 'returns_refunded') - $summary($before, 'returns_refunded'));
        $this->assertTrue($after['chart_data']['summary']['reconciled'], 'the buckets still add up to the total');
        $this->assertTrue($after['chart_data']['summary']['balanced'], 'gross = received + pending');
        $this->assertEquals(461.54, round((float)$before['dashboard']['orders_amount'] - (float)$after['dashboard']['orders_amount'], 2), 'the order value card');
    }

    // -------------------------------------------------------------------- API

    public function test_staff_run_a_return_through_the_api()
    {
        $this->fakeRefunds();
        $order = $this->voucherOrder();
        $phone = $this->line($order, Fixture::PHONE_128_BLACK_ID);

        $view = $this->asAdmin()->getJson('/api/admin/order-return/order/' . $order->id);
        $this->assertApiOk($view);
        $this->assertTrue($view->json('data.eligibility.ok'));
        $this->assertSame(2, collect($view->json('data.lines'))->firstWhere('ordered_product_id', $phone)['returnable_quantity']);

        $created = $this->asAdmin()->postJson('/api/admin/order-return/create', [
            'order_id' => $order->id, 'items' => [$phone => 1], 'reason' => 'faulty', 'notify' => false,
        ]);
        $this->assertApiOk($created);
        $id = $created->json('data.id');
        $itemId = $created->json('data.items.0.id');
        $this->assertStringStartsWith('RMA', $created->json('data.rma_number'));
        $this->assertEquals(461.54, $created->json('data.quote.without_shipping.refund'));

        $received = $this->asAdmin()->postJson('/api/admin/order-return/action/' . $id, [
            'action' => 'receive', 'restock' => [$itemId => true],
        ]);
        $this->assertApiOk($received);
        $this->assertSame('received', $received->json('data.status'));

        $refunded = $this->asAdmin()->postJson('/api/admin/order-return/action/' . $id, [
            'action' => 'refund', 'method' => 'original', 'refund_shipping' => false,
        ]);
        $this->assertApiOk($refunded);
        $this->assertSame('refunded', $refunded->json('data.status'));
        $this->assertEquals(461.54, $refunded->json('data.refund_amount'));

        $list = $this->asAdmin()->getJson('/api/admin/order-return/all?status=refunded');
        $this->assertApiOk($list);
        $this->assertContains($id, collect($list->json('data.data'))->pluck('id')->all());
    }

    public function test_a_refusal_comes_back_as_a_message()
    {
        $order = $this->voucherOrder();

        $response = $this->asAdmin()->postJson('/api/admin/order-return/create', [
            'order_id' => $order->id, 'items' => [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 5], 'reason' => 'faulty',
        ]);

        $this->assertSame(201, (int)$response->json('status'));
        $this->assertStringContainsString('More units chosen', $response->getContent());
    }

    public function test_vendors_cannot_see_or_run_returns()
    {
        $order = $this->voucherOrder();

        $this->assertNotSame(200, (int)$this->asAdmin(Fixture::ADMIN_VENDOR_ID)->getJson('/api/admin/order-return/all')->json('status'));
        $this->app['auth']->forgetGuards();
        $this->assertNotSame(200, (int)$this->asAdmin(Fixture::ADMIN_VENDOR_ID)->postJson('/api/admin/order-return/create', [
            'order_id' => $order->id, 'items' => [$this->line($order, Fixture::PHONE_128_BLACK_ID) => 1], 'reason' => 'faulty',
        ])->json('status'));
        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count());
    }
}
