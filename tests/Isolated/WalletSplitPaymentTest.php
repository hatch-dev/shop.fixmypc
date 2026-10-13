<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\Helper\Refunder;
use App\Models\Helper\WalletSplit;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\IsolatedTestCase;

/**
 * Split payment: part of an order from the wallet, the rest by the chosen
 * method (App\Models\Helper\WalletSplit, 2026-09-26).
 *
 * What these pin:
 *  - the wallet part is taken by the server from the balance, never from
 *    the browser, and only when the shopper asked for it;
 *  - the order keeps its whole value; the method is charged the rest, and a
 *    card payment is verified against the rest;
 *  - an order that dies unpaid (card refused, bank transfer cancelled) gives
 *    the wallet part back, once;
 *  - a paid order is refunded card first, and the wallet is not touched when
 *    the card refund fails.
 */
class WalletSplitPaymentTest extends IsolatedTestCase
{
    private $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::findOrFail(Fixture::USER_PERSONAL_ID);

        Wallet::updateOrCreate(['user_id' => $this->customer->id], ['balance' => 100, 'currency' => 'EUR']);

        // One phone, collected in store: no address needed, nothing to post.
        Cart::where('user_id', $this->customer->id)->delete();
        Cart::create([
            'user_id' => $this->customer->id,
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => 1,
            'selected' => (int)config('constants.status.PUBLIC'),
            'shipping_place_id' => DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id'),
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);

        DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->update(['pickup_point' => 1]);
        DB::table('payments')->update(['bank' => 1, 'sumup' => 1]);
    }

    private function balance()
    {
        return round((float)Wallet::where('user_id', $this->customer->id)->value('balance'), 2);
    }

    private function placeOrder(int $method, array $extra = [])
    {
        $json = json_encode(array_merge([
            'user_token' => null,
            'order_method' => $method,
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ], $extra));

        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return $this->asCustomer($this->customer->id)->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC',
                hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
                hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => $method,
        ]);
    }

    private function latestOrder()
    {
        return Order::withTrashed()->where('user_id', $this->customer->id)->orderByDesc('id')->first();
    }

    // ---------------------------------------------------------------- placing

    public function test_a_ticked_wallet_pays_part_and_the_order_keeps_its_whole_value()
    {
        $response = $this->placeOrder((int)config('constants.paymentMethod.BANK'), ['use_wallet' => 1]);
        $this->assertApiOk($response);

        $order = $this->latestOrder();

        $this->assertEquals(100.00, (float)$order->wallet_amount, 'the wallet part');
        $this->assertGreaterThan(100, (float)$order->total_amount, 'total_amount is the whole order');
        $this->assertEquals(round($order->total_amount - 100, 2), WalletSplit::amountDue($order), 'the bank transfer is owed the rest');
        $this->assertSame(0.0, $this->balance(), 'the balance was used up');

        $this->assertSame(1, WalletTransaction::where('reference_id', $order->id)
            ->where('source', 'order')->where('type', 'debit')->where('amount', 100)->count());
    }

    public function test_the_wallet_is_not_touched_unless_the_shopper_ticked_it()
    {
        $this->assertApiOk($this->placeOrder((int)config('constants.paymentMethod.BANK')));

        $this->assertEquals(0, (float)$this->latestOrder()->wallet_amount);
        $this->assertSame(100.0, $this->balance());
    }

    public function test_a_guest_cannot_split_there_is_no_wallet_to_take_from()
    {
        $this->assertSame(0, WalletSplit::apply($this->latestOrder() ?: new Order(['id' => 0]), null, 500));
    }

    public function test_a_card_split_is_verified_against_the_rest_not_the_whole()
    {
        $total = $this->orderTotal();
        $rest = round($total - 100, 2);

        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => Http::response([
            'status' => 'PAID', 'amount' => $rest, 'currency' => 'EUR', 'transaction_code' => 'TX-SPLIT-1',
        ])]);

        $response = $this->placeOrder((int)config('constants.paymentMethod.SUMUP'), [
            'use_wallet' => 1, 'checkout_id' => 'chk-split-1',
        ]);
        $this->assertApiOk($response);

        $order = $this->latestOrder();
        $this->assertSame(1, (int)$order->payment_done, 'paid');
        $this->assertEquals(100.00, (float)$order->wallet_amount);
        $this->assertEquals($total, (float)$order->total_amount);
        $this->assertSame(0.0, $this->balance());
    }

    public function test_a_card_charge_for_the_whole_order_is_not_accepted_as_the_rest()
    {
        $total = $this->orderTotal();

        // SumUp took the whole order: that is not what a split order owes.
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => Http::response([
            'status' => 'PAID', 'amount' => $total, 'currency' => 'EUR', 'transaction_code' => 'TX-SPLIT-2',
        ])]);

        $response = $this->placeOrder((int)config('constants.paymentMethod.SUMUP'), [
            'use_wallet' => 1, 'checkout_id' => 'chk-split-2',
        ]);

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertNotSame(1, (int)optional($this->latestOrder())->payment_done);
    }

    public function test_a_refused_card_leaves_the_wallet_as_it_was()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => Http::response([
            'status' => 'FAILED', 'amount' => 1, 'currency' => 'EUR',
        ])]);

        $response = $this->placeOrder((int)config('constants.paymentMethod.SUMUP'), [
            'use_wallet' => 1, 'checkout_id' => 'chk-split-3',
        ]);

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertSame(100.0, $this->balance(), 'the wallet part came back with the rollback');
    }

    // ------------------------------------------------------------- cancelling

    public function test_cancelling_an_unpaid_split_order_gives_the_wallet_part_back_once()
    {
        $this->assertApiOk($this->placeOrder((int)config('constants.paymentMethod.BANK'), ['use_wallet' => 1]));
        $order = $this->latestOrder();
        $this->assertSame(0.0, $this->balance());

        $order->restoreStockAndRewards();
        $this->assertSame(100.0, $this->balance(), 'returned on cancellation');

        // A second cancel path, or a replay, must not pay it out again.
        WalletSplit::release($order->fresh());
        $order->fresh()->restoreStockAndRewards();
        $this->assertSame(100.0, $this->balance(), 'returned once only');

        $this->assertSame(1, WalletTransaction::where('reference_id', $order->id)
            ->where('source', 'order_release')->count());
    }

    public function test_a_paid_split_order_is_not_released_by_a_cancellation()
    {
        $this->assertApiOk($this->placeOrder((int)config('constants.paymentMethod.BANK'), ['use_wallet' => 1]));
        $order = $this->latestOrder();
        Order::where('id', $order->id)->update(['payment_done' => 1]);

        $order->fresh()->restoreStockAndRewards();

        $this->assertSame(0.0, $this->balance(), 'money on a paid order moves only through a refund');
    }

    // --------------------------------------------------------------- refunds

    private function paidCardSplit($total = 200.00, $wallet = 50.00)
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'order' => 'SPLIT-' . uniqid(),
            'status' => (int)config('constants.orderStatus.PENDING'),
            'total_amount' => $total,
            'order_method' => (int)config('constants.paymentMethod.SUMUP'),
            'currency' => 'EUR',
            'payment_done' => 1,
            'trans_id' => 'TX-REFUND-1',
        ]);
        Order::where('id', $order->id)->update(['wallet_amount' => $wallet]);

        WalletTransaction::create([
            'user_id' => $this->customer->id, 'type' => 'debit', 'amount' => $wallet,
            'balance_after' => 0, 'source' => 'order', 'reference_id' => $order->id, 'status' => 'success',
        ]);
        Wallet::where('user_id', $this->customer->id)->update(['balance' => 0]);

        return $order->fresh();
    }

    public function test_a_refund_goes_back_to_the_card_first_then_the_wallet()
    {
        $order = $this->paidCardSplit(200.00, 50.00);

        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);

        $result = Refunder::refund($order, 200.00);

        $this->assertTrue($result['ok'], (string)$result['reason']);

        $refundCalls = collect($this->takeBlockedRequests() ?: [])->merge(
            collect(Http::recorded())->map(function ($pair) { return $pair[0]; })
        )->filter(function ($request) { return strpos($request->url(), '/me/refund/') !== false; });

        $this->assertCount(1, $refundCalls, 'one card refund');
        $this->assertEquals(150.00, (float)$refundCalls->first()->data()['amount'], 'the card gets back what it paid');
        $this->assertSame(50.0, $this->balance(), 'the wallet gets back the rest');
    }

    public function test_a_failed_card_refund_leaves_the_wallet_untouched()
    {
        $order = $this->paidCardSplit(200.00, 50.00);

        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response(['message' => 'declined'], 500)]);

        $result = Refunder::refund($order, 200.00);

        $this->assertFalse($result['ok']);
        $this->assertSame(0.0, $this->balance());
    }

    public function test_a_partial_refund_within_the_card_part_does_not_touch_the_wallet()
    {
        $order = $this->paidCardSplit(200.00, 50.00);

        $this->fakeHttp(['api.sumup.com/v0.1/me/refund/*' => Http::response([], 204)]);

        $this->assertTrue(Refunder::refund($order, 40.00)['ok']);
        $this->assertSame(0.0, $this->balance());
    }

    // ---------------------------------------------------------------- helpers

    /** What this basket costs, placed once without the wallet and thrown away. */
    private function orderTotal()
    {
        $this->assertApiOk($this->placeOrder((int)config('constants.paymentMethod.BANK')));
        $order = $this->latestOrder();
        $total = round((float)$order->total_amount, 2);

        // Put the basket back for the real attempt.
        $order->restoreStockAndRewards();
        Cart::where('user_id', $this->customer->id)->delete();
        $this->setUpBasketAgain();

        return $total;
    }

    private function setUpBasketAgain()
    {
        Cart::create([
            'user_id' => $this->customer->id,
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => 1,
            'selected' => (int)config('constants.status.PUBLIC'),
            'shipping_place_id' => DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id'),
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);
    }
}
