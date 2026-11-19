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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatedTestCase;

/**
 * Gift card QA (2026-11-17): the gaps GiftCardStabilisationTest and the
 * legacy Feature\GiftCardTest leave open.
 *
 *  - customer: designs on sale, "my gift cards" states, redeem refusals,
 *    double redeem, double wallet purchase, using another person's card;
 *  - SumUp: a lookup SumUp could not answer never closes a purchase, SumUp
 *    unreachable when a checkout starts, duplicate and unknown notifications;
 *  - admin: void, designs, search and filters, permissions for staff without
 *    gift card rights, customers and guests;
 *  - codes: unique and well formed;
 *  - money: wallets agree with their transactions, nothing goes negative.
 */
class GiftCardQaTest extends IsolatedTestCase
{
    private $a;
    private $b;
    private $design;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = User::findOrFail(Fixture::USER_PERSONAL_ID);
        $this->b = User::findOrFail(Fixture::USER_BUSINESS_ID);

        // Inside the test's transaction: rolled back afterwards.
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
            'title' => 'QA card', 'amounts' => [25, 50, 100], 'min_quantity' => 1, 'max_quantity' => 3, 'is_active' => true,
        ]);
        Wallet::updateOrCreate(['user_id' => $this->a->id], ['balance' => 200]);
        Wallet::updateOrCreate(['user_id' => $this->b->id], ['balance' => 0]);
        GiftCardLock::$waitSeconds = 1;
        Mail::getSwiftMailer()->getTransport()->flush();
    }

    protected function tearDown(): void
    {
        GiftCardLock::$waitSeconds = GiftCardLock::WAIT_SECONDS;
        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    private function buy(User $user = null, $amount = 50, $quantity = 1, $method = 'wallet', array $extra = [])
    {
        $order = GiftVoucherOrder::create(array_merge([
            'user_id' => optional($user)->id, 'voucher_id' => $this->design->id,
            'amount' => $amount, 'quantity' => $quantity, 'total' => $amount * $quantity,
            'payment_method' => $method, 'status' => 'success',
        ], $extra));
        return [$order, GiftVoucherCode::issueFor($order)];
    }

    private function card(User $user, $amount = 50)
    {
        [, $codes] = $this->buy($user, $amount);
        [$ok, , $voucher] = GiftVoucherCode::redeem($codes[0]->code, $user);
        $this->assertTrue($ok);
        return [$codes[0]->fresh(), $voucher];
    }

    private function redeem(User $user, $code)
    {
        return $this->asCustomer($user->id)->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => $code]);
    }

    private function addLaptop(User $user)
    {
        $this->assertApiOk($this->asCustomer($user->id)->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_LAPTOP_ID, 'inventory_id' => Fixture::LAPTOP_INVENTORY_ID, 'quantity' => 1,
        ]));
    }

    private function placeOrder(User $user, $voucherCode)
    {
        $method = (int)config('constants.paymentMethod.CASH_ON_DELIVERY');
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

    private function pendingCardPurchase($reference, $ageHours = 0)
    {
        $order = GiftVoucherOrder::create([
            'user_id' => $this->a->id, 'voucher_id' => $this->design->id, 'amount' => 50, 'quantity' => 1,
            'total' => 50, 'payment_method' => 'sumup', 'reference' => $reference, 'status' => 'pending',
        ]);
        if ($ageHours) {
            DB::table('gift_voucher_orders')->where('id', $order->id)->update(['created_at' => now()->subHours($ageHours)]);
        }
        return $order->fresh();
    }

    private function walletBalance(User $user)
    {
        return (float)Wallet::where('user_id', $user->id)->value('balance');
    }

    /**
     * Refused at the door: an HTTP 401/403, or this API's in-body refusal
     * ({"status": 204, "message": "Unauthenticated."} from the admin guard,
     * 403 from a permission check), and no data either way.
     */
    private function assertNotLetIn($reply, $what)
    {
        $refused = in_array($reply->status(), [401, 403], true)
            || in_array((int)$reply->json('status'), [204, 401, 403], true);
        $this->assertTrue($refused, "$what -> HTTP {$reply->status()} " . substr($reply->getContent(), 0, 160));
        $this->assertEmpty($reply->json('data.data') ?? null, "$what returned data");
    }

    private function mails()
    {
        return Mail::getSwiftMailer()->getTransport()->messages();
    }

    // ------------------------------------------------------- customer side

    /** GC-01 */
    public function test_the_shop_lists_designs_on_sale_with_their_values_and_limits()
    {
        GiftVoucher::create(['title' => 'Withdrawn', 'amounts' => [10], 'min_quantity' => 1, 'max_quantity' => 2, 'is_active' => false]);

        $reply = $this->asGuest()->getJson('/api/v1/user/gift-vouchers');

        $this->assertApiOk($reply);
        $designs = collect($reply->json('data'));
        $this->assertSame(['QA card'], $designs->pluck('title')->all(), 'only designs on sale');
        $this->assertEquals([25, 50, 100], $designs[0]['amounts']);
        $this->assertSame(1, (int)$designs[0]['min_quantity']);
        $this->assertSame(3, (int)$designs[0]['max_quantity']);
    }

    /** GC-02 */
    public function test_my_gift_cards_lists_only_mine_each_in_its_state()
    {
        [, $codes] = $this->buy($this->a, 50, 3);
        $this->buy($this->a, 25, 1, 'wallet');
        [, $other] = $this->buy($this->b, 100);

        GiftVoucherCode::redeem($codes[0]->code, $this->a);
        $codes[1]->update(['status' => GiftVoucherCode::VOID]);
        $codes[2]->update(['expires_at' => now()->subDay()]);

        $reply = $this->asCustomer($this->a->id)->getJson('/api/v1/user/gift-voucher-codes');

        $reply->assertStatus(200);
        $rows = collect($reply->json('data'))->keyBy('code');
        $this->assertCount(4, $rows);
        $this->assertFalse($rows->has($other[0]->code), "another customer's card is not listed");
        $this->assertSame('redeemed', $rows[$codes[0]->code]['state']);
        $this->assertTrue($rows[$codes[0]->code]['redeemed_by_me']);
        $this->assertSame('void', $rows[$codes[1]->code]['state']);
        $this->assertSame('expired', $rows[$codes[2]->code]['state']);
        $this->assertSame(['available'], $rows->except([$codes[0]->code, $codes[1]->code, $codes[2]->code])->pluck('state')->values()->all());
    }

    /** GC-03 */
    public function test_redeem_refuses_unknown_expired_void_and_used_codes_and_accepts_loose_typing()
    {
        [, $codes] = $this->buy($this->a, 25, 3);
        $codes[0]->update(['expires_at' => now()->subMinute()]);
        $codes[1]->update(['status' => GiftVoucherCode::VOID]);

        $this->redeem($this->a, 'GIFT-ZZZZ-ZZZZ')->assertStatus(422);
        $this->redeem($this->a, $codes[0]->code)->assertStatus(422);
        $this->redeem($this->a, $codes[1]->code)->assertStatus(422);

        // "gift abcd 2345" in lower case with spaces is the same code
        $loose = strtolower(str_replace('-', ' ', $codes[2]->code));
        $reply = $this->redeem($this->a, $loose);
        $reply->assertStatus(200);
        $this->assertSame($codes[2]->code, $reply->json('voucher_code'));

        $this->assertSame(1, Voucher::whereNotNull('gift_value')->count(), 'only the good code became a voucher');
        $this->assertSame(GiftVoucherCode::ACTIVE, $codes[0]->fresh()->status, 'a refused code is unchanged');
    }

    /** GC-04 */
    public function test_a_code_is_redeemed_once_whoever_tries_again()
    {
        [, $codes] = $this->buy($this->a, 50);

        $this->redeem($this->a, $codes[0]->code)->assertStatus(200);
        $again = $this->redeem($this->a, $codes[0]->code);
        $again->assertStatus(422);
        $other = $this->redeem($this->b, $codes[0]->code);
        $other->assertStatus(422);
        $this->assertNotSame($again->json('message'), $other->json('message'), 'the owner and a stranger are told different things');

        $this->assertSame(1, Voucher::where('code', $codes[0]->code)->count());
        $this->assertSame($this->a->id, (int)Voucher::where('code', $codes[0]->code)->value('user_id'));
    }

    /** GC-05 */
    public function test_a_repeated_wallet_purchase_charges_each_purchase_once_and_never_overdraws()
    {
        $buy = ['voucher_id' => $this->design->id, 'amount' => 50, 'quantity' => 1, 'payment_method' => 'wallet'];

        $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher', $buy)->assertStatus(200);
        $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher', $buy)->assertStatus(200);
        $this->assertEqualsWithDelta(100, $this->walletBalance($this->a), 0.001);
        $this->assertSame(2, GiftVoucherOrder::where('user_id', $this->a->id)->count());
        $this->assertSame(2, DB::table('wallet_transactions')->where('user_id', $this->a->id)->where('source', 'gift_voucher')->count());

        // 3 x 50 = 150 against 100 left: refused, nothing changes
        $reply = $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher', ['quantity' => 3] + $buy);
        $reply->assertStatus(422);
        $this->assertEqualsWithDelta(100, $this->walletBalance($this->a), 0.001);
        $this->assertSame(2, GiftVoucherOrder::where('user_id', $this->a->id)->count());

        // the last 100 exactly: allowed, down to zero and not below
        $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher', ['amount' => 100] + $buy)->assertStatus(200);
        $this->assertEqualsWithDelta(0, $this->walletBalance($this->a), 0.001);
        $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher', ['amount' => 25] + $buy)->assertStatus(422);
        $this->assertEqualsWithDelta(0, $this->walletBalance($this->a), 0.001);
    }

    /** GC-06 */
    public function test_a_card_worth_less_than_the_goods_is_used_up_and_the_rest_is_still_owed()
    {
        [, $voucher] = $this->card($this->a, 100);

        $this->addLaptop($this->a);
        $this->assertApiOk($this->placeOrder($this->a, $voucher->code));

        $order = Order::where('user_id', $this->a->id)->orderByDesc('id')->first();
        $this->assertEqualsWithDelta(100, (float)DB::table('ordered_voucher')->where('order_id', $order->id)->value('discount'), 0.001);
        $this->assertEqualsWithDelta(0, (float)Voucher::find($voucher->id)->giftBalance(), 0.001);
        $this->assertGreaterThan(0, (float)$order->total_amount, 'the card does not pay for more than it holds');
    }

    /** GC-07 */
    public function test_nobody_else_can_spend_my_card()
    {
        [, $voucher] = $this->card($this->a, 100);

        $check = $this->asCustomer($this->b->id)->postJson('/api/v1/voucher/validity', ['voucher' => $voucher->code]);
        $this->assertNotSame(200, (int)$check->json('status'), 'the voucher check refuses it');

        $this->addLaptop($this->b);
        $before = DB::table('ordered_voucher')->where('voucher_id', $voucher->id)->count();
        $this->placeOrder($this->b, $voucher->code);
        $this->assertSame($before, DB::table('ordered_voucher')->where('voucher_id', $voucher->id)->count(), 'no order spent it');
        $this->assertEqualsWithDelta(100, (float)Voucher::find($voucher->id)->giftBalance(), 0.001);
    }

    // ------------------------------------------------------------- SumUp

    /** GC-08 */
    public function test_a_day_old_purchase_is_only_closed_on_an_answer_from_sumup()
    {
        // SumUp refuses the key (401) or cannot be reached: we do not know
        // whether the customer paid, so the purchase must stay open.
        $unauthorised = $this->pendingCardPurchase('chk_qa_401', 25);
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => Http::response(['message' => 'invalid token'], 401)]);
        Artisan::call('gift-cards:reconcile-payments');
        $this->assertSame('pending', $unauthorised->fresh()->status, 'a 401 is not an answer about the payment');

        $unreachable = $this->pendingCardPurchase('chk_qa_down', 25);
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);
        Artisan::call('gift-cards:reconcile-payments');
        $this->assertSame('pending', $unreachable->fresh()->status, 'an outage is not an answer about the payment');

        // SumUp has no such checkout (404) after a day: abandoned, closed
        $gone = $this->pendingCardPurchase('chk_qa_404', 25);
        $this->fakeHttp([
            'api.sumup.com/v0.1/checkouts/chk_qa_404' => Http::response(['message' => 'not found'], 404),
            'api.sumup.com/v0.1/checkouts/*' => Http::response(['message' => 'invalid token'], 401),
        ]);
        Artisan::call('gift-cards:reconcile-payments');
        $this->assertSame('cancelled', $gone->fresh()->status);
        $this->assertSame('pending', $unauthorised->fresh()->status);
        $this->assertSame('pending', $unreachable->fresh()->status);

        // and once SumUp answers PAID, the purchase that was kept open is issued
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => Http::response([
            'status' => 'PAID', 'amount' => 50, 'currency' => 'EUR', 'description' => 'Gift Voucher Purchase #QA',
        ])]);
        Artisan::call('gift-cards:reconcile-payments');
        $this->assertSame('success', $unauthorised->fresh()->status);
        $this->assertSame(1, GiftVoucherCode::where('gift_voucher_order_id', $unauthorised->id)->count());
    }

    /** GC-09 */
    public function test_sumup_unreachable_when_a_card_purchase_starts_is_a_clean_refusal()
    {
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts' => function () {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }]);

        $reply = $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher/start', [
            'voucher_id' => $this->design->id, 'amount' => 50, 'quantity' => 1,
        ]);

        $reply->assertStatus(422);
        $this->assertNotEmpty($reply->json('message'));
        $this->assertSame(0, GiftVoucherOrder::count(), 'no purchase recorded without a checkout');
    }

    /** GC-10 */
    public function test_repeated_and_unknown_notifications_change_nothing_twice()
    {
        $order = $this->pendingCardPurchase('chk_qa_paid');
        $this->fakeHttp(['api.sumup.com/v0.1/checkouts/*' => Http::response([
            'status' => 'PAID', 'amount' => 50, 'currency' => 'EUR', 'description' => 'Gift Voucher Purchase #QA',
        ])]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/sumup/gift-card-notify', ['id' => 'chk_qa_paid'])->assertStatus(200);
        }
        $this->postJson('/api/v1/sumup/gift-card-notify', ['checkout_id' => 'chk_qa_paid'])->assertStatus(200);
        $this->postJson('/api/v1/sumup/gift-card-notify', ['id' => 'chk_never_made'])->assertStatus(200);
        $this->postJson('/api/v1/sumup/gift-card-notify', [])->assertStatus(200);

        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(1, GiftVoucherCode::where('gift_voucher_order_id', $order->id)->count(), 'one card, not four');
        $this->assertCount(1, $this->mails(), 'one email');
        $this->assertSame(1, GiftVoucherOrder::count(), 'an unknown checkout creates nothing');
    }

    // ---------------------------------------------------------- admin side

    /** GC-11 */
    public function test_staff_void_an_unused_card_once_and_never_a_redeemed_one()
    {
        [, $codes] = $this->buy($this->a, 50, 2);
        GiftVoucherCode::redeem($codes[1]->code, $this->a);

        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/void', ['id' => $codes[0]->id, 'reason' => 'Lost in post']));
        $this->assertSame(GiftVoucherCode::VOID, $codes[0]->fresh()->status);
        $this->assertSame(1, GiftCardEvent::where('gift_voucher_code_id', $codes[0]->id)->where('type', GiftCardEvent::VOID)->count());

        $this->assertApiRefused($this->asAdmin()->postJson('/api/admin/gift-card/void', ['id' => $codes[0]->id]), 201, 'already void');
        $this->assertApiRefused($this->asAdmin()->postJson('/api/admin/gift-card/void', ['id' => $codes[1]->id]), 201, 'redeemed');
        $this->assertSame(GiftVoucherCode::REDEEMED, $codes[1]->fresh()->status);
        $this->assertSame(1, GiftCardEvent::where('type', GiftCardEvent::VOID)->count());

        // a void card can no longer be added by the customer
        $this->redeem($this->a, $codes[0]->code)->assertStatus(422);
    }

    /** GC-12 */
    public function test_staff_manage_designs_and_a_design_with_sales_is_withdrawn_not_deleted()
    {
        $made = $this->asAdmin()->postJson('/api/admin/gift-voucher/action', [
            'title' => 'Birthday', 'amounts' => [20, 10, 20, 5.555], 'min_quantity' => 1, 'max_quantity' => 4, 'is_active' => true,
        ]);
        $this->assertApiOk($made);
        $id = $made->json('data.id');
        $this->assertEquals([5.56, 10, 20], GiftVoucher::find($id)->amounts, 'sorted, de-duplicated, two decimals');

        $this->assertApiOk($this->asAdmin()->postJson("/api/admin/gift-voucher/action/$id", [
            'title' => 'Birthday', 'amounts' => [10, 20], 'min_quantity' => 1, 'max_quantity' => 4, 'is_active' => false,
        ]));
        $this->assertFalse(GiftVoucher::find($id)->is_active);

        // validation: max below min, no values, an amount over 5000
        foreach ([
            ['amounts' => [10], 'min_quantity' => 5, 'max_quantity' => 2],
            ['amounts' => [], 'min_quantity' => 1, 'max_quantity' => 2],
            ['amounts' => [6000], 'min_quantity' => 1, 'max_quantity' => 2],
        ] as $bad) {
            $this->assertApiRefused($this->asAdmin()->postJson('/api/admin/gift-voucher/action', ['title' => 'Bad'] + $bad), 201);
        }
        $this->assertSame(0, GiftVoucher::where('title', 'Bad')->count());

        // no sales: deleted; with sales: taken off sale instead
        $this->assertApiOk($this->asAdmin()->deleteJson("/api/admin/gift-voucher/delete/$id"));
        $this->assertNull(GiftVoucher::find($id));
        $this->buy($this->a, 50);
        $this->assertApiOk($this->asAdmin()->deleteJson('/api/admin/gift-voucher/delete/' . $this->design->id));
        $this->assertNotNull(GiftVoucher::find($this->design->id));
        $this->assertFalse(GiftVoucher::find($this->design->id)->is_active);
    }

    /** GC-13, GC-14 */
    public function test_staff_without_gift_card_rights_customers_and_guests_are_refused()
    {
        [, $codes] = $this->buy($this->a, 50, 2);
        [$order] = $this->buy($this->a, 25);
        $before = [
            GiftVoucherCode::orderBy('id')->get(['id', 'status', 'amount', 'expires_at', 'sent_to'])->toArray(),
            GiftVoucher::orderBy('id')->get()->toArray(),
            GiftVoucherOrder::orderBy('id')->get(['id', 'status'])->toArray(),
        ];

        $calls = [
            ['get', '/api/admin/gift-card/all', []],
            ['get', '/api/admin/gift-card/history/' . $codes[0]->id, []],
            ['get', '/api/admin/gift-card/designs', []],
            ['post', '/api/admin/gift-card/resend/' . $codes[0]->id, []],
            ['post', '/api/admin/gift-card/void/' . $codes[0]->id, ['reason' => 'x']],
            ['post', '/api/admin/gift-card/issue', ['design_id' => $this->design->id, 'amount' => 30, 'quantity' => 1, 'reason' => 'x']],
            ['post', '/api/admin/gift-card/adjust/' . $codes[1]->id, ['amount' => 500, 'reason' => 'x']],
            ['post', '/api/admin/gift-card/expiry/' . $codes[1]->id, ['expires_at' => now()->addYears(6)->toDateString(), 'reason' => 'x']],
            ['post', '/api/admin/gift-card/refund/' . $order->id, ['reason' => 'x']],
            ['get', '/api/admin/gift-voucher/all', []],
            ['get', '/api/admin/gift-voucher/find/' . $this->design->id, []],
            ['post', '/api/admin/gift-voucher/action/' . $this->design->id, ['title' => 'Hijacked', 'amounts' => [1], 'min_quantity' => 1, 'max_quantity' => 1]],
            ['post', '/api/admin/gift-voucher/action', ['title' => 'Planted', 'amounts' => [1], 'min_quantity' => 1, 'max_quantity' => 1]],
            ['delete', '/api/admin/gift-voucher/delete/' . $this->design->id, []],
        ];

        foreach ($calls as [$method, $url, $body]) {
            // staff without gift card rights: refused in the body
            $reply = $this->asAdmin(Fixture::ADMIN_VENDOR_ID)->json($method, $url, $body);
            $this->assertSame(403, (int)$reply->json('status'), "vendor admin: $method $url -> " . substr($reply->getContent(), 0, 200));

            // a customer's token and no token at all: never let in
            $customer = $this->asCustomer($this->a->id)->json($method, $url, $body);
            $this->assertNotLetIn($customer, "customer: $method $url");
            $this->assertNotLetIn($this->asGuest()->json($method, $url, $body), "guest: $method $url");
        }

        $after = [
            GiftVoucherCode::orderBy('id')->get(['id', 'status', 'amount', 'expires_at', 'sent_to'])->toArray(),
            GiftVoucher::orderBy('id')->get()->toArray(),
            GiftVoucherOrder::orderBy('id')->get(['id', 'status'])->toArray(),
        ];
        $this->assertSame($before, $after, 'nothing changed');
        $this->assertSame(0, GiftCardEvent::count());
        $this->assertCount(0, $this->mails());
    }

    /** GC-15 */
    public function test_staff_search_and_filter_cards_and_the_summary_adds_up()
    {
        [, $active] = $this->buy($this->a, 50, 1, 'wallet', ['recipient_email' => 'friend-qa@fixture.test', 'recipient_name' => 'Friend QA']);
        [, $void] = $this->buy($this->a, 25);
        $void[0]->update(['status' => GiftVoucherCode::VOID]);
        [, $expired] = $this->buy($this->a, 25);
        $expired[0]->update(['expires_at' => now()->subDay()]);
        [$inVouchers] = $this->card($this->b, 100);
        [$partly, $partlyVoucher] = $this->card($this->a, 100);
        [$usedUp, $usedUpVoucher] = $this->card($this->a, 50);

        // standing orders: 30 off one card, all 50 off another (one card per order)
        foreach ([[$partlyVoucher, 30], [$usedUpVoucher, 50]] as [$v, $discount]) {
            $orderId = DB::table('orders')->insertGetId([
                'order' => 'QA' . uniqid(), 'user_id' => $this->a->id, 'voucher_id' => $v->id,
                'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
                'payment_done' => 1, 'status' => 1, 'cancelled' => 0, 'total_amount' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ordered_voucher')->insert([
                'order_id' => $orderId, 'voucher_id' => $v->id, 'discount' => $discount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $codes = function ($query) {
            $reply = $this->asAdmin()->getJson('/api/admin/gift-card/all?' . http_build_query($query));
            $this->assertApiOk($reply);
            return collect($reply->json('data.data'))->pluck('code')->sort()->values()->all();
        };
        $sorted = function (...$cards) {
            return collect($cards)->pluck('code')->sort()->values()->all();
        };

        $this->assertSame($sorted($active[0]), $codes(['status' => 'available']));
        $this->assertSame($sorted($void[0]), $codes(['status' => 'void']));
        $this->assertSame($sorted($expired[0]), $codes(['status' => 'expired']));
        $this->assertSame($sorted($inVouchers), $codes(['status' => 'in_vouchers']));
        $this->assertSame($sorted($partly), $codes(['status' => 'partly_used']));
        $this->assertSame($sorted($usedUp), $codes(['status' => 'used_up']));
        $this->assertSame($sorted($inVouchers, $partly, $usedUp), $codes(['status' => 'redeemed']));
        $this->assertSame($sorted($active[0]), $codes(['q' => 'friend-qa']), 'search finds the recipient');
        $this->assertSame($sorted($active[0]), $codes(['q' => substr($active[0]->code, -4)]), 'search finds the code');
        $this->assertCount(6, $codes([]));

        $summary = $this->asAdmin()->getJson('/api/admin/gift-card/all')->json('data.summary');
        $this->assertSame(6, $summary['cards']);
        $this->assertSame('350.00', $summary['sold']);        // 50 + 25 + 25 + 100 + 100 + 50
        $this->assertSame(3, $summary['added']);
        $this->assertSame('80.00', $summary['spent']);        // 30 + 50
        $this->assertSame('220.00', $summary['outstanding']); // 50 active + 100 + 70 left; void and expired excluded
    }

    // ----------------------------------------------------- codes and money

    /** GC-16 */
    public function test_issued_codes_are_unique_and_easy_to_read()
    {
        $all = [];
        for ($i = 0; $i < 67; $i++) {
            [, $codes] = $this->buy($this->a, 25, 3);
            foreach ($codes as $c) {
                $all[] = $c->code;
            }
        }

        $this->assertCount(201, $all);
        $this->assertCount(201, array_unique($all), 'no duplicates');
        foreach ($all as $code) {
            $this->assertMatchesRegularExpression('/^GIFT-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
        }
    }

    /** GC-17 */
    public function test_wallets_agree_with_their_transactions_after_buying_and_refunding()
    {
        DB::table('wallet_transactions')->insert([
            'user_id' => $this->a->id, 'type' => 'credit', 'amount' => 200, 'balance_after' => 200,
            'source' => 'topup', 'reference_id' => null, 'status' => 'success', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $buy = ['voucher_id' => $this->design->id, 'quantity' => 1, 'payment_method' => 'wallet'];
        $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher', ['amount' => 100] + $buy)->assertStatus(200);
        $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher', ['amount' => 50, 'quantity' => 2] + $buy)->assertStatus(200);
        $this->asCustomer($this->a->id)->postJson('/api/v1/user/purchase-gift-voucher', ['amount' => 25] + $buy)->assertStatus(422);

        // refund the two-card purchase after one card was added to vouchers
        $twoCards = GiftVoucherOrder::where('user_id', $this->a->id)->where('quantity', 2)->firstOrFail();
        GiftVoucherCode::redeem($twoCards->codes()->first()->code, $this->a);
        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/gift-card/refund/' . $twoCards->id, ['reason' => 'QA refund']));
        $this->assertApiRefused($this->asAdmin()->postJson('/api/admin/gift-card/refund/' . $twoCards->id, ['reason' => 'again']), 201);

        $credits = (float)DB::table('wallet_transactions')->where('user_id', $this->a->id)->where('type', 'credit')->sum('amount');
        $debits = (float)DB::table('wallet_transactions')->where('user_id', $this->a->id)->where('type', 'debit')->sum('amount');
        $this->assertEqualsWithDelta($credits - $debits, $this->walletBalance($this->a), 0.001, 'wallet = its transactions');
        $this->assertEqualsWithDelta(50, $this->walletBalance($this->a), 0.001, '200 - 100 - 100 + 50 refunded for the unused card');
        $this->assertGreaterThanOrEqual(0, $this->walletBalance($this->a));

        $last = DB::table('wallet_transactions')->where('user_id', $this->a->id)->orderByDesc('id')->first();
        $this->assertEqualsWithDelta($this->walletBalance($this->a), (float)$last->balance_after, 0.001, 'balance_after is the real balance');

        $refunded = (float)GiftCardEvent::where('gift_voucher_order_id', $twoCards->id)->where('type', GiftCardEvent::REFUND)->sum('amount');
        $this->assertLessThanOrEqual((float)$twoCards->total, abs($refunded), 'never more back than was paid');
        $this->assertSame(1, DB::table('wallet_transactions')->where('source', 'gift_voucher_refund')->count(), 'one refund entry');
    }
}
