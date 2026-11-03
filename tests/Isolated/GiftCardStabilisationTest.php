<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\GiftCardEvent;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherCode;
use App\Models\GiftVoucherOrder;
use App\Models\Helper\GiftCardLock;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Wallet;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatedTestCase;

/**
 * Gift card stabilisation (2026-11-03).
 *
 *  - a card's balance cannot be spent twice: checkouts with the same card take
 *    turns (GiftCardLock), and a bank transfer awaiting payment holds its share;
 *  - partial use, cancellation, roll-back and returns keep the balance right;
 *  - card purchases settle from SumUp itself (browser, notification or sweep)
 *    and issue their codes exactly once, only when SumUp says paid;
 *  - staff can issue, adjust, re-date and refund, and every change is logged;
 *  - the general voucher API cannot change a gift card;
 *  - a corrected resend address changes where one card goes, nothing else;
 *  - customer messages come back in the shopper's language;
 *  - a card's history is visible to its owner and to staff.
 */
class GiftCardStabilisationTest extends IsolatedTestCase
{
    private $a;
    private $b;
    private $design;
    private $lastOrderId;
    private $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = User::findOrFail(Fixture::USER_PERSONAL_ID);
        $this->b = User::findOrFail(Fixture::USER_BUSINESS_ID);

        // What this test adds is taken away again in tearDown: other classes
        // count the fixture customers' orders and expect their own state.
        $this->lastOrderId = (int)DB::table('orders')->max('id');
        $this->wallet = DB::table('wallets')->where('user_id', $this->a->id)->first();


        Cart::whereNull('admin_id')->delete();
        DB::table('cart_reservations')->delete();
        DB::table('cart_holds')->delete();
        DB::table('gift_card_events')->delete();
        GiftVoucherCode::query()->delete();
        GiftVoucherOrder::query()->delete();
        DB::table('ordered_voucher')->whereIn('voucher_id', Voucher::whereNotNull('gift_value')->pluck('id'))->delete();
        Voucher::whereNotNull('gift_value')->delete();
        DB::table('wallet_transactions')->whereIn('user_id', [$this->a->id, $this->b->id])->delete();
        DB::table('gift_voucher')->delete();
        DB::table('updated_inventories')->where('id', Fixture::LAPTOP_INVENTORY_ID)->update(['quantity' => 100]);

        $this->design = GiftVoucher::create([
            'title' => 'Fixture card', 'amounts' => json_encode([25, 50, 100, 2000]),
            'min_quantity' => 1, 'max_quantity' => 5, 'is_active' => true,
        ]);
        Wallet::updateOrCreate(['user_id' => $this->a->id], ['balance' => 500]);
        GiftCardLock::$waitSeconds = 1;
        Mail::getSwiftMailer()->getTransport()->flush();
    }

    protected function tearDown(): void
    {
        GiftCardLock::$waitSeconds = GiftCardLock::WAIT_SECONDS;

        // Orders placed here, and every row that points at them.
        $placed = DB::table('orders')->where('id', '>', $this->lastOrderId)->pluck('id')->all();
        if ($placed) {
            $tables = DB::select("SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'order_id' AND TABLE_NAME <> 'orders'");
            foreach ($tables as $row) {
                DB::table($row->t)->whereIn('order_id', $placed)->delete();
            }
            DB::table('wallet_transactions')->whereIn('reference_id', $placed)->whereIn('source', ['order', 'refund', 'order_release'])->delete();
            DB::table('orders')->whereIn('id', $placed)->delete();
        }
        Cart::whereIn('user_id', [$this->a->id, $this->b->id])->delete();
        DB::table('cart_reservations')->delete();
        DB::table('cart_holds')->delete();

        // The gift cards, their vouchers and their log.
        DB::table('gift_card_events')->delete();
        GiftVoucherCode::query()->delete();
        GiftVoucherOrder::query()->delete();
        Voucher::whereNotNull('gift_value')->delete();
        DB::table('gift_voucher')->delete();
        DB::table('wallet_transactions')->whereIn('source', ['gift_voucher', 'gift_voucher_refund'])->delete();

        // The wallet as it was.
        if ($this->wallet) {
            DB::table('wallets')->where('id', $this->wallet->id)->update(['balance' => $this->wallet->balance]);
        } else {
            DB::table('wallets')->where('user_id', $this->a->id)->delete();
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    /** A paid purchase of $quantity cards bought by $user (wallet by default). */
    private function buy(User $user = null, $amount = 50, $quantity = 1, $method = 'wallet', array $extra = [])
    {
        $order = GiftVoucherOrder::create(array_merge([
            'user_id' => optional($user)->id, 'voucher_id' => $this->design->id,
            'amount' => $amount, 'quantity' => $quantity, 'total' => $amount * $quantity,
            'payment_method' => $method, 'status' => 'success',
        ], $extra));
        return [$order, GiftVoucherCode::issueFor($order)];
    }

    /** A card worth $amount already in $user's vouchers. */
    private function card(User $user, $amount = 2000)
    {
        [, $codes] = $this->buy($user, $amount);
        [$ok, , $voucher] = GiftVoucherCode::redeem($codes[0]->code, $user);
        $this->assertTrue($ok);
        return [$codes[0]->fresh(), $voucher];
    }

    private function addLaptop(User $user)
    {
        $this->assertApiOk($this->asCustomer($user->id)->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_LAPTOP_ID, 'inventory_id' => Fixture::LAPTOP_INVENTORY_ID, 'quantity' => 1,
        ]));
    }

    private function placeOrder(User $user, $voucherCode, $method = null)
    {
        $method = $method ?: (int)config('constants.paymentMethod.CASH_ON_DELIVERY');
        $place = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('shipping_places')->where('id', $place)->update(['pickup_point' => 1]);
        Cart::where('user_id', $user->id)->update([
            'shipping_place_id' => $place,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);
        $json = json_encode(['user_token' => null, 'order_method' => $method, 'voucher' => $voucherCode, 'time_zone' => 'Europe/Dublin']);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return $this->asCustomer($user->id)->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC', hex2bin('0123456470abcdef0123456789abcdef'),
                OPENSSL_ZERO_PADDING, hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => $method,
        ]);
    }

    private function balance(Voucher $voucher)
    {
        return (float)Voucher::find($voucher->id)->giftBalance();
    }

    private function spentOn(Order $order)
    {
        return (float)DB::table('ordered_voucher')->where('order_id', $order->id)->value('discount');
    }

    private function lastOrder(User $user)
    {
        return Order::where('user_id', $user->id)->orderByDesc('id')->first();
    }

    private function mails()
    {
        return Mail::getSwiftMailer()->getTransport()->messages();
    }

    /** SumUp as a test double: checkout creation and lookup. */
    private function fakeSumup($status, $amount, $description = 'Gift Voucher Purchase #GV1', array $more = [])
    {
        $seen = (object)['created' => []];
        $this->fakeHttp(array_merge($more, [
            'api.sumup.com/v0.1/checkouts/*' => Http::response([
                'status' => $status, 'amount' => $amount, 'currency' => 'EUR', 'description' => $description,
                'transaction_code' => 'TXGC1', 'transactions' => [['id' => 'txn_gc_1', 'status' => 'SUCCESSFUL']],
            ]),
            'api.sumup.com/v0.1/checkouts' => function ($request) use ($seen) {
                $seen->created[] = $request->data();
                return Http::response(['id' => 'chk_gc_' . count($seen->created)]);
            },
        ]));
        return $seen;
    }

    private function pendingCardPurchase(User $user, $reference = 'chk_gc_pending', $quantity = 2, $ageMinutes = 0)
    {
        $order = GiftVoucherOrder::create([
            'user_id' => $user->id, 'voucher_id' => $this->design->id, 'amount' => 50, 'quantity' => $quantity,
            'total' => 50 * $quantity, 'payment_method' => 'sumup', 'reference' => $reference, 'status' => 'pending',
        ]);
        if ($ageMinutes) {
            DB::table('gift_voucher_orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes($ageMinutes)]);
        }
        return $order->fresh();
    }

    // ------------------------------------------------- 1. spending the balance

    public function test_a_card_is_spent_in_parts_and_then_refused_when_used_up()
    {
        [, $voucher] = $this->card($this->a, 2000);

        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));
        $first = $this->spentOn($this->lastOrder($this->a));
        $this->assertGreaterThan(0, $first);
        $this->assertLessThan(2000, $first, 'one laptop costs less than the card');
        $this->assertEqualsWithDelta(2000 - $first, $this->balance($voucher), 0.001, 'the rest stays on the card');

        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));
        $second = $this->spentOn($this->lastOrder($this->a));
        $this->assertEqualsWithDelta(2000 - $first, $second, 0.001, 'the second order takes only what was left');
        $this->assertEqualsWithDelta(0, $this->balance($voucher), 0.001);

        $this->addLaptop($this->a);
        $refused = $this->placeOrder($this->a, $voucher->code);
        $this->assertNotSame(200, (int)$refused->json('status'));
        $this->assertStringContainsString('used up', json_encode($refused->json()));
    }

    public function test_a_bank_transfer_awaiting_payment_holds_the_card_until_cancelled()
    {
        [, $voucher] = $this->card($this->a, 2000);
        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code, (int)config('constants.paymentMethod.BANK')));
        $order = $this->lastOrder($this->a);
        $this->assertSame(0, (int)$order->payment_done, 'a transfer is placed unpaid');

        $held = $this->spentOn($order);
        $this->assertGreaterThan(0, $held);
        $this->assertEqualsWithDelta(2000 - $held, $this->balance($voucher), 0.001,
            'unpaid, but the value is held: it cannot be spent a second time');

        Order::where('id', $order->id)->update(['cancelled' => 1]);
        $this->assertEqualsWithDelta(2000, $this->balance($voucher), 0.001, 'cancelling gives it back');
    }

    public function test_a_second_checkout_with_the_same_card_waits_for_the_first()
    {
        [, $voucher] = $this->card($this->a, 2000);
        $this->addLaptop($this->a);
        $orders = Order::count();

        // another checkout (another connection) is holding the card
        config(['database.connections.other' => config('database.connections.' . config('database.default'))]);
        $other = DB::connection('other');
        $this->assertSame(1, (int)$other->selectOne('SELECT GET_LOCK(?, 0) AS got', ['fixmypc_gift_card_' . $voucher->id])->got);

        $busy = $this->placeOrder($this->a, $voucher->code);
        $this->assertNotSame(200, (int)$busy->json('status'));
        $this->assertStringContainsString('another checkout', json_encode($busy->json()));
        $this->assertSame($orders, Order::count(), 'nothing was placed while the card was busy');
        $this->assertEqualsWithDelta(2000, $this->balance($voucher), 0.001);

        $other->select('SELECT RELEASE_LOCK(?)', ['fixmypc_gift_card_' . $voucher->id]);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));
        $this->assertSame($orders + 1, Order::count());

        // and the lock was let go afterwards
        $this->assertSame(1, (int)$other->selectOne('SELECT IS_FREE_LOCK(?) AS free', ['fixmypc_gift_card_' . $voucher->id])->free);
        DB::purge('other');
    }

    public function test_cancelled_rolled_back_and_returned_orders_do_not_spend_the_card()
    {
        [, $voucher] = $this->card($this->a, 2000);

        // cash on delivery, then cancelled
        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));
        $cod = $this->lastOrder($this->a);
        Order::where('id', $cod->id)->update(['cancelled' => 1]);
        $this->assertEqualsWithDelta(2000, $this->balance($voucher), 0.001);

        // a card payment SumUp never took: the order is rolled back, voucher row and all
        $this->addLaptop($this->a);
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => Http::response(['status' => 'FAILED', 'amount' => 1, 'currency' => 'EUR'])]);
        $json = json_encode(['user_token' => null, 'order_method' => (int)config('constants.paymentMethod.SUMUP'),
            'voucher' => $voucher->code, 'time_zone' => 'Europe/Dublin', 'checkout_id' => 'chk_order_failed']);
        $before = DB::table('ordered_voucher')->where('voucher_id', $voucher->id)->count();
        $this->placeOrder($this->a, $voucher->code, (int)config('constants.paymentMethod.SUMUP'));
        $this->assertSame($before, DB::table('ordered_voucher')->where('voucher_id', $voucher->id)->count(),
            'a rolled-back order leaves nothing on the card');
        $this->assertEqualsWithDelta(2000, $this->balance($voucher), 0.001);

        // part of a standing order returned: its gift card share goes back
        $this->fakeHttp();
        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));
        $kept = $this->lastOrder($this->a);
        $spent = $this->spentOn($kept);
        DB::table('ordered_voucher')->where('order_id', $kept->id)->update(['returned_amount' => 100]);
        $this->assertEqualsWithDelta(2000 - $spent + 100, $this->balance($voucher), 0.001);
    }

    // --------------------------------------------------- 2. card purchases

    public function test_a_card_purchase_issues_its_codes_once_however_often_it_is_settled()
    {
        $seen = $this->fakeSumup('PAID', 100.0);
        $start = $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher/start', [
            'voucher_id' => $this->design->id, 'amount' => 50, 'quantity' => 2,
        ]);
        $start->assertStatus(200);
        $this->assertStringEndsWith('/api/v1/sumup/gift-card-notify', $seen->created[0]['return_url'] ?? '',
            'SumUp is asked to tell the server, not only the browser');
        $order = GiftVoucherOrder::where('reference', $start->json('checkout_id'))->firstOrFail();
        $this->assertSame('pending', $order->status);

        // SumUp's notification arrives first: the customer closed the tab
        $this->postJson('/api/v1/sumup/gift-card-notify', ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => $order->reference])
            ->assertStatus(200);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(2, GiftVoucherCode::where('gift_voucher_order_id', $order->id)->count());
        $this->assertCount(1, $this->mails(), 'emailed once');

        // then the notification again, the browser, and the sweep
        $this->postJson('/api/v1/sumup/gift-card-notify', ['id' => $order->reference])->assertStatus(200);
        $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher/confirm', ['checkout_id' => $order->reference])
            ->assertStatus(200);
        Artisan::call('gift-cards:reconcile-payments');

        $this->assertSame(2, GiftVoucherCode::where('gift_voucher_order_id', $order->id)->count(), 'no duplicate codes');
        $this->assertCount(1, $this->mails(), 'and no second email');
    }

    public function test_failed_and_expired_card_payments_close_without_codes()
    {
        $failed = $this->pendingCardPurchase($this->a, 'chk_gc_failed');
        $this->fakeSumup('FAILED', 100.0);
        $this->postJson('/api/v1/sumup/gift-card-notify', ['id' => 'chk_gc_failed'])->assertStatus(200);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame(0, GiftVoucherCode::where('gift_voucher_order_id', $failed->id)->count());

        $expired = $this->pendingCardPurchase($this->a, 'chk_gc_expired');
        $this->fakeSumup('EXPIRED', 100.0);
        $reply = $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher/confirm', ['checkout_id' => 'chk_gc_expired']);
        $reply->assertStatus(422);
        $this->assertStringContainsString('nothing was charged', $reply->json('message'));
        $this->assertSame('cancelled', $expired->fresh()->status);
        $this->assertSame(0, GiftVoucherCode::where('gift_voucher_order_id', $expired->id)->count());
    }

    public function test_a_payment_still_pending_or_for_another_amount_issues_nothing()
    {
        $pending = $this->pendingCardPurchase($this->a, 'chk_gc_wait');
        $this->fakeSumup('PENDING', 100.0);
        $reply = $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher/confirm', ['checkout_id' => 'chk_gc_wait']);
        $reply->assertStatus(202);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(0, GiftVoucherCode::count());

        $wrong = $this->pendingCardPurchase($this->a, 'chk_gc_wrong');
        $this->fakeSumup('PAID', 1.00);
        $reply = $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher/confirm', ['checkout_id' => 'chk_gc_wrong']);
        $reply->assertStatus(422);
        $this->assertStringContainsString('may have been charged', $reply->json('message'));
        $this->assertSame('pending', $wrong->fresh()->status, 'money was taken: left for a person, not failed');
        $this->assertSame(0, GiftVoucherCode::count());

        // a notification for a checkout we never made is harmless
        $this->postJson('/api/v1/sumup/gift-card-notify', ['id' => 'chk_nobody'])->assertStatus(200)->assertExactJson(['ok' => true]);
    }

    public function test_the_sweep_settles_purchases_nobody_else_settled()
    {
        $old = $this->pendingCardPurchase($this->a, 'chk_gc_old', 1, 10);
        $new = $this->pendingCardPurchase($this->a, 'chk_gc_new', 1, 0);
        $this->fakeSumup('PAID', 50.0);

        Artisan::call('gift-cards:reconcile-payments');

        $this->assertSame('success', $old->fresh()->status);
        $this->assertSame(1, GiftVoucherCode::where('gift_voucher_order_id', $old->id)->count());
        $this->assertSame('pending', $new->fresh()->status, 'the first two minutes belong to the browser and the notification');
    }

    // ------------------------------------------------------ 3. staff changes

    public function test_staff_issue_cards_with_a_reason_and_without_a_purchase()
    {
        $reply = $this->asAdmin()->postJson('/api/admin/gift-card/issue', [
            'design_id' => $this->design->id, 'amount' => 30, 'quantity' => 2,
            'customer_email' => $this->b->email, 'reason' => 'Goodwill after a late delivery',
            'expires_at' => now()->addYear()->format('Y-m-d'),
        ]);
        $this->assertApiOk($reply);
        $order = GiftVoucherOrder::findOrFail($reply->json('data.order_id'));
        $this->assertSame('admin', $order->payment_method);
        $this->assertEquals(0, (float)$order->total, 'nothing charged');
        $codes = GiftVoucherCode::where('gift_voucher_order_id', $order->id)->get();
        $this->assertCount(2, $codes);
        $this->assertTrue($codes->every(function ($c) { return (int)$c->buyer_user_id === (int)$this->b->id && $c->expires_at->isSameDay(now()->addYear()); }));
        $events = GiftCardEvent::where('gift_voucher_order_id', $order->id)->where('type', 'issued')->get();
        $this->assertCount(2, $events);
        $this->assertSame(Fixture::ADMIN_SUPER_ID, (int)$events[0]->admin_id);
        $this->assertSame('Goodwill after a late delivery', $events[0]->reason);

        // to someone with no account at all
        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/issue', [
            'design_id' => $this->design->id, 'amount' => 25, 'recipient_email' => 'friend@example.test', 'reason' => 'Prize',
        ]));
        $this->assertNull(GiftVoucherCode::orderByDesc('id')->first()->buyer_user_id);

        foreach ([
            ['amount' => 30],                                      // no reason
            ['amount' => 0, 'reason' => 'x'],                      // no value
            ['amount' => 30, 'reason' => 'x', 'expires_at' => '2020-01-01'],
            ['amount' => 30, 'reason' => 'x', 'customer_email' => 'nobody@example.test'],
        ] as $bad) {
            $this->assertNotSame(200, (int)$this->asAdmin()->postJson('/api/admin/gift-card/issue', ['design_id' => $this->design->id] + $bad)->json('status'));
        }
    }

    public function test_staff_change_a_balance_but_never_below_what_was_spent()
    {
        // (customer steps first: within one test process the admin guard
        // would otherwise still be signed in for the next customer request)
        [$code, $voucher] = $this->card($this->a, 2000);
        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));
        $spent = 2000 - $this->balance($voucher);

        // not yet redeemed: the code's value changes
        [, $codes] = $this->buy($this->a, 50);
        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/adjust/' . $codes[0]->id, ['amount' => 10, 'reason' => 'Price match']));
        $this->assertEquals(60, (float)$codes[0]->fresh()->amount);

        // redeemed and partly spent: the voucher's value changes, the spent part is protected

        $tooMuch = $this->asAdmin()->postJson('/api/admin/gift-card/adjust/' . $code->id, ['amount' => -1990, 'reason' => 'Mistake']);
        $this->assertNotSame(200, (int)$tooMuch->json('status'));
        $this->assertStringContainsString('below zero', $tooMuch->json('message'));
        $this->assertEquals(2000, (float)Voucher::find($voucher->id)->gift_value);

        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/adjust/' . $code->id, ['amount' => -100, 'reason' => 'Partial chargeback']));
        $this->assertEquals(1900, (float)Voucher::find($voucher->id)->gift_value);
        $this->assertEqualsWithDelta(1900 - $spent, $this->balance($voucher), 0.001);

        $event = GiftCardEvent::where('gift_voucher_code_id', $code->id)->where('type', 'balance')->firstOrFail();
        $this->assertEquals(-100, (float)$event->amount);
        $this->assertSame('2000.00', $event->old_value);
        $this->assertSame('1900.00', $event->new_value);
        $this->assertSame('Partial chargeback', $event->reason);
        $this->assertSame(Fixture::ADMIN_SUPER_ID, (int)$event->admin_id);

        $this->assertNotSame(200, (int)$this->asAdmin()->postJson('/api/admin/gift-card/adjust/' . $code->id, ['amount' => 5])->json('status'), 'a reason is required');
    }

    public function test_staff_move_an_expiry_date_into_the_future_only()
    {
        [$code, $voucher] = $this->card($this->a, 100);
        $date = now()->addYears(6)->format('Y-m-d');
        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/expiry/' . $code->id, ['expires_at' => $date, 'reason' => 'Customer request']));
        $this->assertSame($date, $code->fresh()->expires_at->format('Y-m-d'));
        $this->assertSame($date, substr((string)Voucher::find($voucher->id)->end_time, 0, 10), 'the voucher it became moves too');
        $this->assertSame(1, GiftCardEvent::where('gift_voucher_code_id', $code->id)->where('type', 'expiry')->count());

        $past = $this->asAdmin()->postJson('/api/admin/gift-card/expiry/' . $code->id, ['expires_at' => '2020-01-01', 'reason' => 'x']);
        $this->assertNotSame(200, (int)$past->json('status'));
    }

    public function test_refunding_a_wallet_purchase_returns_the_unredeemed_cards_only()
    {
        [$order, $codes] = $this->buy($this->a, 50, 2, 'wallet');
        GiftVoucherCode::redeem($codes[1]->code, $this->a);
        $before = (float)Wallet::where('user_id', $this->a->id)->value('balance');

        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/refund/' . $order->id, ['reason' => 'Bought twice by mistake']));

        $this->assertEqualsWithDelta($before + 50, (float)Wallet::where('user_id', $this->a->id)->value('balance'), 0.001);
        $this->assertSame('void', $codes[0]->fresh()->status);
        $this->assertSame('redeemed', $codes[1]->fresh()->status, 'the card in someone\'s vouchers is left alone');
        $this->assertSame('partially_refunded', $order->fresh()->status);
        $this->assertSame(1, DB::table('wallet_transactions')->where('source', 'gift_voucher_refund')->where('reference_id', $order->id)->count());
        $this->assertEquals(50, (float)GiftCardEvent::where('gift_voucher_order_id', $order->id)->where('type', 'refund')->sum('amount'));

        $again = $this->asAdmin()->postJson('/api/admin/gift-card/refund/' . $order->id, ['reason' => 'again']);
        $this->assertNotSame(200, (int)$again->json('status'), 'nothing left to refund');

        // a card staff issued was never paid for
        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/issue', ['design_id' => $this->design->id, 'amount' => 25, 'reason' => 'Prize']));
        $issued = GiftVoucherOrder::where('payment_method', 'admin')->firstOrFail();
        $this->assertNotSame(200, (int)$this->asAdmin()->postJson('/api/admin/gift-card/refund/' . $issued->id, ['reason' => 'x'])->json('status'));
    }

    public function test_refunding_a_card_purchase_goes_through_sumup_or_changes_nothing()
    {
        [$order, $codes] = $this->buy($this->a, 50, 1, 'sumup', ['reference' => 'chk_gc_paid']);

        // SumUp refuses: the card is put back exactly as it was
        $this->fakeSumup('PAID', 50.0, 'Gift Voucher Purchase #GV1', ['api.sumup.com/v0.1/me/refund/*' => Http::response(['message' => 'declined'], 400)]);
        $refused = $this->asAdmin()->postJson('/api/admin/gift-card/refund/' . $order->id, ['reason' => 'Disputed']);
        $this->assertNotSame(200, (int)$refused->json('status'));
        $this->assertSame('active', $codes[0]->fresh()->status);
        $this->assertNull($codes[0]->fresh()->void_reason);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(0, GiftCardEvent::where('type', 'refund')->count());

        // SumUp refunds the transaction behind the checkout
        $this->fakeSumup('PAID', 50.0, 'Gift Voucher Purchase #GV1', ['api.sumup.com/v0.1/me/refund/*' => Http::response(null, 204)]);
        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/refund/' . $order->id, ['reason' => 'Disputed']));
        $this->assertSame('void', $codes[0]->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('txn_gc_1', GiftCardEvent::where('type', 'refund')->value('new_value'));
    }

    // --------------------------------------------------- 4. voucher API guard

    public function test_the_voucher_api_cannot_read_change_or_delete_a_gift_card()
    {
        [, $voucher] = $this->card($this->a, 100);

        $this->assertNotSame(200, (int)$this->asAdmin()->getJson('/api/admin/voucher/find/' . $voucher->id)->json('status'));
        $this->assertNotSame(200, (int)$this->asAdmin()->postJson('/api/admin/voucher/action/' . $voucher->id, [
            'title' => 'Hijacked', 'price' => 5000, 'gift_value' => 5000, 'end_time' => now()->addYears(9)->toDateTimeString(),
        ])->json('status'));
        $this->assertNotSame(200, (int)$this->asAdmin()->deleteJson('/api/admin/voucher/delete/' . $voucher->id)->json('status'));

        $fresh = Voucher::find($voucher->id);
        $this->assertNotNull($fresh, 'not deleted');
        $this->assertEquals(100, (float)$fresh->gift_value, 'not changed');

        // an ordinary promotion is untouched by the guard
        $promo = Voucher::create([
            'title' => 'Promo', 'type' => 1, 'status' => 1, 'usage_limit' => 10, 'limit_per_customer' => 1, 'price' => 5,
            'min_spend' => 0, 'code' => 'PROMO-GUARD', 'start_time' => now()->subDay(), 'end_time' => now()->addDay(),
            'admin_id' => Fixture::ADMIN_SUPER_ID, 'apply_type' => 1,
        ]);
        $this->assertApiOk($this->asAdmin()->getJson('/api/admin/voucher/find/' . $promo->id));
        $this->asAdmin()->deleteJson('/api/admin/voucher/delete/' . $promo->id);
        $this->assertNull(Voucher::find($promo->id));
    }

    // ------------------------------------------------------------ 5. resend

    public function test_a_corrected_resend_address_changes_where_one_card_goes_and_nothing_else()
    {
        [$order, $codes] = $this->buy($this->a, 50, 2);   // bought "for me"

        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/resend/' . $codes[0]->id, ['email' => 'corrected@example.test']));

        $this->assertNull($order->fresh()->recipient_email, 'still a purchase for the buyer, not a gift');
        $this->assertSame('corrected@example.test', $codes[0]->fresh()->sent_to);
        $this->assertNull($codes[1]->fresh()->sent_to, 'the other card in the purchase is not redirected');
        $mail = $this->mails()[0];
        $this->assertArrayHasKey('corrected@example.test', $mail->getTo());
        $this->assertStringNotContainsString('sent you', $mail->getSubject(), 'it still reads as the buyer\'s own card');
        $this->assertStringContainsString($codes[0]->code, $mail->getBody());
        $this->assertStringNotContainsString($codes[1]->code, $mail->getBody());
        $this->assertSame(1, GiftCardEvent::where('gift_voucher_code_id', $codes[0]->id)->where('type', 'resend')->count());

        // a gift keeps its recipient on the purchase, too
        [$gift, $giftCodes] = $this->buy($this->a, 50, 1, 'wallet', ['recipient_email' => 'friend@example.test', 'recipient_name' => 'Friend']);
        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/resend/' . $giftCodes[0]->id, ['email' => 'friend2@example.test']));
        $this->assertSame('friend@example.test', $gift->fresh()->recipient_email);
        $this->assertStringContainsString('sent you', $this->mails()[1]->getSubject());
    }

    // ------------------------------------------------------ 6. language, history

    public function test_customer_messages_come_back_in_each_language()
    {
        [, $codes] = $this->buy($this->a, 50);
        $english = null;
        foreach (['en', 'fr', 'ar', 'hi', 'tr'] as $lang) {
            $missing = $this->asCustomer($this->a->id)->withHeaders(['language' => $lang])
                ->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => 'GIFT-ZZZZ-ZZZZ']);
            $missing->assertStatus(422);
            $this->assertSame(__('lang.gc_not_found', [], $lang), $missing->json('message'), $lang);
            $english = $english ?: $missing->json('message');
            if ($lang !== 'en') {
                $this->assertNotSame($english, $missing->json('message'), "$lang is translated");
            }
        }
        $this->assertSame('We could not find that gift card code. Check it and try again.', $english);

        $ok = $this->asCustomer($this->a->id)->withHeaders(['language' => 'ar'])
            ->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => $codes[0]->code]);
        $ok->assertStatus(200);
        $this->assertSame(__('lang.gc_redeemed', ['amount' => '€50.00'], 'ar'), $ok->json('message'));

        $again = $this->asCustomer($this->a->id)->withHeaders(['language' => 'tr'])
            ->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => $codes[0]->code]);
        $this->assertSame(__('lang.gc_already_yours', [], 'tr'), $again->json('message'));
    }

    public function test_a_card_history_shows_its_life_to_its_owner_and_to_staff()
    {
        [$code, $voucher] = $this->card($this->a, 2000);
        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));
        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));
        Order::where('id', $this->lastOrder($this->a)->id)->update(['cancelled' => 1]);
        $this->asAdmin()->postJson('/api/admin/gift-card/adjust/' . $code->id, ['amount' => 50, 'reason' => 'Internal note']);

        $mine = $this->asCustomer($this->a->id)->getJson('/api/v1/user/gift-card-history?voucher_id=' . $voucher->id);
        $mine->assertStatus(200);
        $types = collect($mine->json('data.events'))->pluck('type')->all();
        foreach (['purchased', 'redeemed', 'spent', 'released', 'balance'] as $type) {
            $this->assertContains($type, $types, $type);
        }
        $this->assertSame(number_format($this->balance($voucher), 2, '.', ''), $mine->json('data.balance'));
        $this->assertStringNotContainsString('Internal note', $mine->getContent(), 'staff reasons stay with staff');

        $this->asCustomer($this->b->id)->getJson('/api/v1/user/gift-card-history?voucher_id=' . $voucher->id)->assertStatus(404);

        $staff = $this->asAdmin()->getJson('/api/admin/gift-card/history/' . $code->id);
        $this->assertApiOk($staff);
        $balance = collect($staff->json('data.events'))->firstWhere('type', 'balance');
        $this->assertSame('Internal note', $balance['reason']);
        $this->assertNotEmpty($balance['by']);
    }
}
