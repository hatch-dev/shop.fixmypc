<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Helper\CartHold;
use App\Models\Helper\CartMerge;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatedTestCase;

/**
 * Basket stock reservations (App\Models\Helper\CartHold, 2026-09-26).
 *
 *  - adding to the basket takes the units off stock at once;
 *  - changing or removing a line gives the difference back;
 *  - a basket's own units count as available to it, and to nobody else;
 *  - placing the order uses the held units - stock is not taken twice;
 *  - 5 minutes idle: one reminder email (if there is an address);
 *  - 20 minutes idle: the basket is emptied and the stock goes back,
 *    unless the shopper has started paying.
 */
class CartReservationTest extends IsolatedTestCase
{
    private $customer;

    protected function setUp(): void
    {
        parent::setUp();


        $this->customer = User::findOrFail(Fixture::USER_PERSONAL_ID);
        Cart::whereNull('admin_id')->delete();
        DB::table('cart_reservations')->delete();
        DB::table('cart_holds')->delete();
        $this->setStock(10);
    }

    /** Messages the array mail transport has taken (the suite never sends real mail). */
    private function sentMail()
    {
        return count(app('mailer')->getSwiftMailer()->getTransport()->messages());
    }

    private function setStock($quantity)
    {
        DB::table('updated_inventories')->where('id', Fixture::PHONE_128_BLACK_ID)
            ->update(['quantity' => $quantity, 'is_active' => 0]);
    }

    private function stock()
    {
        return (int)DB::table('updated_inventories')->where('id', Fixture::PHONE_128_BLACK_ID)->value('quantity');
    }

    private function add($quantity = 1, $userId = null)
    {
        return $this->asCustomer($userId ?: $this->customer->id)->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => $quantity,
        ]);
    }

    private function line($userId = null)
    {
        return Cart::where('user_id', $userId ?: $this->customer->id)->first();
    }

    private function ageBasket($minutes, $userId = null, $token = null)
    {
        DB::table('cart_holds')
            ->where('owner_key', CartHold::ownerKey($userId ?: ($token ? null : $this->customer->id), $token))
            ->update(['last_activity_at' => Carbon::parse(date('Y-m-d H:i:s'))->subMinutes($minutes)->format('Y-m-d H:i:s')]);
    }

    // ---------------------------------------------------------------- holding

    public function test_adding_to_the_basket_takes_the_units_off_stock()
    {
        $this->assertApiOk($this->add(2));

        $this->assertSame(8, $this->stock());
        $this->assertSame(2, CartHold::heldFor($this->customer->id, null, Fixture::PHONE_128_BLACK_ID));
        $this->assertSame(1, DB::table('cart_holds')->count(), 'one timer for the basket');
    }

    public function test_adding_more_takes_more_and_removing_the_line_gives_it_all_back()
    {
        $this->assertApiOk($this->add(1));
        $this->assertApiOk($this->add(2));
        $this->assertSame(7, $this->stock());

        $this->asCustomer($this->customer->id)->deleteJson('/api/v1/cart/delete/' . $this->line()->id);

        $this->assertSame(10, $this->stock());
        $this->assertSame(0, DB::table('cart_holds')->count(), 'no basket, no timer');
    }

    public function test_the_last_unit_held_by_one_shopper_cannot_be_added_by_another()
    {
        $this->setStock(1);
        $this->assertApiOk($this->add(1));
        $this->assertSame(0, $this->stock());

        $response = $this->add(1, Fixture::USER_BUSINESS_ID);

        $this->assertNotSame(200, (int)$response->json('status'), 'refused: someone is holding it');
        $this->assertNull($this->line(Fixture::USER_BUSINESS_ID));
    }

    public function test_a_shopper_can_still_see_their_own_held_unit_as_available()
    {
        $this->setStock(1);
        $this->assertApiOk($this->add(1));

        $response = $this->asCustomer($this->customer->id)->getJson('/api/v1/cart/by-user');
        $this->assertApiOk($response);

        $row = collect($response->json('data'))->firstWhere('inventory_id', Fixture::PHONE_128_BLACK_ID);
        $this->assertSame(1, (int)$row['updated_inventory']['quantity'], 'their own unit, not "sold out"');
    }

    public function test_placing_the_order_uses_the_held_units_instead_of_taking_more()
    {
        $this->assertApiOk($this->add(1));
        $this->assertSame(9, $this->stock());

        $place = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('shipping_places')->where('id', $place)->update(['pickup_point' => 1]);
        Cart::where('user_id', $this->customer->id)->update([
            'shipping_place_id' => $place,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);

        $json = json_encode(['user_token' => null, 'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '', 'time_zone' => 'Europe/Dublin']);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC', hex2bin('0123456470abcdef0123456789abcdef'),
                OPENSSL_ZERO_PADDING, hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);
        $this->assertApiOk($response);

        $this->assertSame(9, $this->stock(), 'the one unit the basket held - not a second one');
        $this->assertSame(0, CartHold::heldFor($this->customer->id, null, Fixture::PHONE_128_BLACK_ID));

        // The emptied basket must not hand the sold unit back.
        CartHold::sweep();
        $this->assertSame(9, $this->stock());
    }

    // ------------------------------------------------------------- the clock

    public function test_five_minutes_idle_sends_one_reminder()
    {
        $this->assertApiOk($this->add(1));
        $this->ageBasket(6);

        CartHold::sweep();
        CartHold::sweep();

        $this->assertSame(1, $this->sentMail(), 'one reminder');
        $this->assertSame(1, $this->line()->quantity, 'still in the basket');
        $this->assertSame(9, $this->stock(), 'still held');
    }

    public function test_adding_again_restarts_the_clock()
    {
        $this->assertApiOk($this->add(1));
        $this->ageBasket(19);
        $this->assertApiOk($this->add(1));

        CartHold::sweep();

        $this->assertSame(0, $this->sentMail(), 'no reminder');
        $this->assertNotNull($this->line());
        $this->assertSame(8, $this->stock());
    }

    public function test_twenty_minutes_idle_empties_the_basket_and_returns_the_stock()
    {
        $this->assertApiOk($this->add(3));
        $this->ageBasket(21);

        CartHold::sweep();

        $this->assertNull($this->line(), 'removed from the basket');
        $this->assertSame(10, $this->stock(), 'back on sale');
        $this->assertSame(0, DB::table('cart_holds')->count());
    }

    public function test_a_basket_being_paid_for_is_not_emptied()
    {
        $this->assertApiOk($this->add(1));
        $this->assertApiOk($this->asCustomer($this->customer->id)->postJson('/api/v1/cart/hold-for-payment', []));
        $this->ageBasket(25);

        CartHold::sweep();

        $this->assertNotNull($this->line());
        $this->assertSame(9, $this->stock());
    }

    public function test_a_guest_without_an_email_gets_no_reminder_but_still_expires()
    {
        GuestUser::where('user_token', Fixture::GUEST_TOKEN)->update(['email' => null]);

        $this->asGuest()->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => 1,
            'user_token' => Fixture::GUEST_TOKEN,
        ]);
        $this->assertSame(9, $this->stock());

        $this->ageBasket(6, null, Fixture::GUEST_TOKEN);
        CartHold::sweep();
        $this->assertSame(0, $this->sentMail(), 'no reminder');

        $this->ageBasket(21, null, Fixture::GUEST_TOKEN);
        CartHold::sweep();
        $this->assertSame(10, $this->stock());
        $this->assertSame(0, Cart::where('user_token', Fixture::GUEST_TOKEN)->whereNull('user_id')->count());
    }

    public function test_a_guest_with_an_email_is_reminded()
    {
        GuestUser::updateOrCreate(['user_token' => Fixture::GUEST_TOKEN], ['email' => 'guest@fixture.test', 'name' => 'Guest']);

        $this->asGuest()->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => 1,
            'user_token' => Fixture::GUEST_TOKEN,
        ]);

        $this->ageBasket(6, null, Fixture::GUEST_TOKEN);
        CartHold::sweep();

        $this->assertSame(1, $this->sentMail(), 'one reminder');
    }

    // ------------------------------------------------------------ stray lines

    public function test_a_line_deleted_behind_the_basket_is_released_by_the_sweep()
    {
        $this->assertApiOk($this->add(2));

        // e.g. an admin deleting the product: a bulk delete, no basket request.
        Cart::where('inventory_id', Fixture::PHONE_128_BLACK_ID)->delete();
        CartHold::sweep();

        $this->assertSame(10, $this->stock());
    }

    public function test_signing_in_moves_the_guest_hold_to_the_account_without_losing_stock()
    {
        $this->asGuest()->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => 2,
            'user_token' => Fixture::GUEST_TOKEN,
        ]);
        $this->assertSame(8, $this->stock());

        CartMerge::forUser($this->customer, Fixture::GUEST_TOKEN);

        $this->assertSame(8, $this->stock(), 'the same two units, now the account\'s');
        $this->assertSame(2, CartHold::heldFor($this->customer->id, null, Fixture::PHONE_128_BLACK_ID));
        $this->assertSame(0, CartHold::heldFor(null, Fixture::GUEST_TOKEN, Fixture::PHONE_128_BLACK_ID));
    }
}
