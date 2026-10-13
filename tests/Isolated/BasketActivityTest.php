<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\Helper\BasketNotice;
use App\Models\Helper\CartHold;
use App\Models\Helper\CartMerge;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Basket activity, expiry at checkout, and telling the shopper (2026-10-13).
 *
 *  - any basket or checkout request restarts the 20 idle minutes, not only
 *    adding to the basket;
 *  - a basket whose 20 minutes have lapsed is emptied the moment it is
 *    priced, shipped or paid for - it can never be bought, whether or not
 *    the sweep has run - unless it has started paying;
 *  - an emptied basket, a trimmed line and guest items added at sign-in each
 *    leave a notice the basket read carries, and an emptied basket can be
 *    added back.
 */
class BasketActivityTest extends IsolatedTestCase
{
    private $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::findOrFail(Fixture::USER_PERSONAL_ID);
        Cart::whereNull('admin_id')->delete();
        DB::table('cart_reservations')->delete();
        DB::table('cart_holds')->delete();
        DB::table('basket_notices')->delete();
        DB::table('updated_inventories')->where('id', Fixture::PHONE_128_BLACK_ID)
            ->update(['quantity' => 10, 'is_active' => 0]);
    }

    private function stock()
    {
        return (int)DB::table('updated_inventories')->where('id', Fixture::PHONE_128_BLACK_ID)->value('quantity');
    }

    private function add($quantity = 2)
    {
        return $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => $quantity,
        ]);
    }

    private function ago($minutes)
    {
        return Carbon::parse(date('Y-m-d H:i:s'))->subMinutes($minutes)->format('Y-m-d H:i:s');
    }

    /** The basket was last active this many minutes ago. */
    private function idleFor($minutes)
    {
        DB::table('cart_holds')->where('owner_key', 'u:' . $this->customer->id)
            ->update(['last_activity_at' => $this->ago($minutes), 'reminded_at' => $this->ago($minutes)]);
    }

    private function lastActivity()
    {
        return Carbon::parse(DB::table('cart_holds')->where('owner_key', 'u:' . $this->customer->id)->value('last_activity_at'));
    }

    private function basketRead()
    {
        $response = $this->asCustomer($this->customer->id)->getJson('/api/v1/cart/by-user');
        $this->assertApiOk($response);

        return $response;
    }

    private function placeOrder()
    {
        $place = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('shipping_places')->where('id', $place)->update(['pickup_point' => 1]);
        Cart::where('user_id', $this->customer->id)->update([
            'shipping_place_id' => $place,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);

        $json = json_encode(['user_token' => null, 'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '', 'time_zone' => 'Europe/Dublin']);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return $this->asCustomer($this->customer->id)->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC', hex2bin('0123456470abcdef0123456789abcdef'),
                OPENSSL_ZERO_PADDING, hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);
    }

    // --------------------------------------------------------------- activity

    public function test_checkout_activity_restarts_the_twenty_minutes()
    {
        $this->assertApiOk($this->add());
        $this->idleFor(15);

        // Loading the basket or a checkout step is activity.
        $this->basketRead();
        $this->assertLessThan(5, $this->lastActivity()->diffInSeconds(Carbon::parse(date('Y-m-d H:i:s'))));

        // 15 minutes before plus 10 after is 25 since the add, but only 10 idle.
        $this->idleFor(10);
        CartHold::sweep();
        $this->assertSame(1, Cart::where('user_id', $this->customer->id)->count());
    }

    // ----------------------------------------------------- expiry at checkout

    public function test_a_lapsed_basket_cannot_be_paid_for_even_if_the_sweep_never_ran()
    {
        $this->assertApiOk($this->add());
        $this->assertSame(8, $this->stock());
        $this->idleFor(21);

        $ordersBefore = Order::where('user_id', $this->customer->id)->count();
        $response = $this->placeOrder();

        $this->assertNotSame(200, (int)$response->json('status'), 'Body: ' . $response->getContent());
        $this->assertSame($ordersBefore, Order::where('user_id', $this->customer->id)->count());
        $this->assertSame(0, Cart::where('user_id', $this->customer->id)->count(), 'the basket was emptied');
        $this->assertSame(10, $this->stock(), 'and its units released');
    }

    public function test_the_pre_payment_check_also_refuses_a_lapsed_basket()
    {
        $this->assertApiOk($this->add());
        $this->idleFor(21);

        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/checkout-check', []);

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertSame(0, Cart::where('user_id', $this->customer->id)->count());
    }

    public function test_a_basket_that_started_paying_keeps_its_grace()
    {
        $this->assertApiOk($this->add());
        $this->assertApiOk($this->asCustomer($this->customer->id)->postJson('/api/v1/cart/hold-for-payment', []));
        $this->idleFor(21);
        // idleFor leaves paying_until alone: the card is still being charged.

        $this->assertApiOk($this->placeOrder());
        $this->assertSame(8, $this->stock());
    }

    public function test_a_basket_inside_its_twenty_minutes_is_untouched()
    {
        $this->assertApiOk($this->add());
        $this->idleFor(19);

        $this->assertApiOk($this->placeOrder());
    }

    // ---------------------------------------------------------------- notices

    public function test_an_emptied_basket_is_explained_and_can_be_added_back()
    {
        $this->assertApiOk($this->add(2));
        $this->idleFor(21);

        // The next basket read empties it - and says so.
        $notices = $this->basketRead()->json('notices');
        $this->assertCount(1, $notices);
        $this->assertSame(BasketNotice::EXPIRED, $notices[0]['kind']);
        $this->assertSame(Fixture::PRODUCT_PHONE_ID, $notices[0]['lines'][0]['product_id']);
        $this->assertSame(2, $notices[0]['lines'][0]['quantity']);
        $this->assertSame(10, $this->stock());

        $restore = $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/notices/restore', ['id' => $notices[0]['id']]);
        $this->assertApiOk($restore);
        $this->assertCount(1, $restore->json('data.restored'));

        $this->assertSame(2, (int)Cart::where('user_id', $this->customer->id)->value('quantity'));
        $this->assertSame(8, $this->stock(), 'held again');
        $this->assertSame([], $this->basketRead()->json('notices'), 'shown once acted on');
    }

    public function test_a_line_that_cannot_come_back_is_reported_not_forced_in()
    {
        $this->assertApiOk($this->add(2));
        $this->idleFor(21);
        $notice = $this->basketRead()->json('notices.0');

        DB::table('updated_inventories')->where('id', Fixture::PHONE_128_BLACK_ID)->update(['quantity' => 1]);

        $restore = $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/notices/restore', ['id' => $notice['id']]);

        $this->assertCount(0, $restore->json('data.restored'));
        $this->assertCount(1, $restore->json('data.refused'));
        $this->assertSame(0, Cart::where('user_id', $this->customer->id)->count());
    }

    public function test_notices_can_be_dismissed_and_only_by_their_owner()
    {
        BasketNotice::add($this->customer->id, null, BasketNotice::EXPIRED, ['lines' => []]);
        BasketNotice::add(Fixture::USER_BUSINESS_ID, null, BasketNotice::EXPIRED, ['lines' => []]);
        $other = DB::table('basket_notices')->where('owner_key', 'u:' . Fixture::USER_BUSINESS_ID)->value('id');

        $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/notices/dismiss', ['ids' => [$other]]);
        $this->assertSame(1, DB::table('basket_notices')->where('id', $other)->count(), 'not theirs to dismiss');

        $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/notices/dismiss', []);
        $this->assertSame(0, DB::table('basket_notices')->where('owner_key', 'u:' . $this->customer->id)->count());
    }

    public function test_a_line_cut_back_for_want_of_stock_is_explained()
    {
        $this->assertApiOk($this->add(3));

        // The reservation ran out and only one unit is left for it.
        DB::table('cart_reservations')->delete();
        DB::table('cart_holds')->delete();
        DB::table('updated_inventories')->where('id', Fixture::PHONE_128_BLACK_ID)->update(['quantity' => 1]);

        CartHold::reconcile($this->customer->id, null);

        $notice = $this->basketRead()->json('notices.0');
        $this->assertSame(BasketNotice::TRIMMED, $notice['kind']);
        $this->assertSame(3, $notice['from']);
        $this->assertSame(1, $notice['to']);
    }

    public function test_signing_in_says_which_guest_items_joined_the_account()
    {
        $this->asGuest()->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => 1,
            'user_token' => Fixture::GUEST_TOKEN,
        ]);
        BasketNotice::add(null, Fixture::GUEST_TOKEN, BasketNotice::TRIMMED, ['title' => 'x', 'from' => 2, 'to' => 1]);

        CartMerge::forUser($this->customer, Fixture::GUEST_TOKEN);

        $kinds = collect($this->basketRead()->json('notices'))->pluck('kind')->all();
        $this->assertSame([BasketNotice::TRIMMED, BasketNotice::MERGED], $kinds, 'the guest notice came across too');
    }
}
