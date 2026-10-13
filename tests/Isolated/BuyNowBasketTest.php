<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Helper\BasketUpkeep;
use App\Models\Helper\CartHold;
use App\Models\Helper\CartMerge;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * What was added beside basket reservations on 2026-10-09
 * (CartReservationTest pins the reservations themselves).
 *
 *  - Buy Now is a checkout of one item that never touches the basket;
 *  - the last unit cannot be taken twice by orders that interleave, and a
 *    failure part-way through an order loses no stock;
 *  - guest lines that never had a reservation do not live for ever;
 *  - each basket line says whether it can be bought ("Only N left",
 *    "Currently unavailable") and whether its price changed;
 *  - the payment page can ask before a pay-first gateway charges.
 */
class BuyNowBasketTest extends IsolatedTestCase
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
        DB::table('updated_inventories')->where('id', Fixture::LAPTOP_INVENTORY_ID)
            ->update(['quantity' => 100, 'is_active' => 0]);
    }

    private function setStock($quantity, $inventoryId = Fixture::PHONE_128_BLACK_ID, $backorder = 0)
    {
        DB::table('updated_inventories')->where('id', $inventoryId)
            ->update(['quantity' => $quantity, 'is_active' => $backorder]);
    }

    private function stock($inventoryId = Fixture::PHONE_128_BLACK_ID)
    {
        return (int)DB::table('updated_inventories')->where('id', $inventoryId)->value('quantity');
    }

    private function phone($quantity = 1)
    {
        return ['product_id' => Fixture::PRODUCT_PHONE_ID, 'inventory_id' => Fixture::PHONE_128_BLACK_ID, 'quantity' => $quantity];
    }

    private function laptop($quantity = 1)
    {
        return ['product_id' => Fixture::PRODUCT_LAPTOP_ID, 'inventory_id' => Fixture::LAPTOP_INVENTORY_ID, 'quantity' => $quantity];
    }

    private function add(array $line, $userId = null)
    {
        return $this->asCustomer($userId ?: $this->customer->id)->postJson('/api/v1/cart/action', $line);
    }

    private function buyNow(array $line, $userId = null)
    {
        return $this->asCustomer($userId ?: $this->customer->id)->postJson('/api/v1/cart/buy-now', $line);
    }

    private function basket($userId = null)
    {
        $response = $this->asCustomer($userId ?: $this->customer->id)->getJson('/api/v1/cart/by-user');
        $this->assertApiOk($response);

        return collect($response->json('data'));
    }

    /** Place a cash-on-delivery collection order for whatever this customer is checking out. */
    private function placeOrder($userId = null)
    {
        $userId = $userId ?: $this->customer->id;

        $place = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('shipping_places')->where('id', $place)->update(['pickup_point' => 1]);
        Cart::where('user_id', $userId)->update([
            'shipping_place_id' => $place,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);

        $json = json_encode(['user_token' => null, 'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '', 'time_zone' => 'Europe/Dublin']);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return $this->asCustomer($userId)->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC', hex2bin('0123456470abcdef0123456789abcdef'),
                OPENSSL_ZERO_PADDING, hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);
    }

    private function ago($minutes)
    {
        return Carbon::parse(date('Y-m-d H:i:s'))->subMinutes($minutes)->format('Y-m-d H:i:s');
    }


    /**
     * As if this basket's reservation had run out while its lines stayed:
     * the parked units are back on the shelf and nothing holds them. The only
     * way a basket line can go short under reservations.
     */
    private function reservationRanOut()
    {
        foreach (DB::table('cart_reservations')->get() as $row) {
            DB::table('updated_inventories')->where('id', $row->inventory_id)->increment('quantity', $row->quantity);
        }

        DB::table('cart_reservations')->delete();
        DB::table('cart_holds')->delete();
    }

    // ------------------------------------------------------------------ stock

    public function test_the_last_unit_cannot_be_taken_twice_when_two_orders_interleave()
    {
        $this->assertApiOk($this->add($this->phone(1)));
        $this->reservationRanOut();
        $this->setStock(1);

        /*
         * Another order takes the last unit after this one passed its stock
         * check but before it took the unit: while its first line is being
         * written. (Not on the orders insert itself - a query run there
         * replaces the id that insert is about to read back.)
         */
        $fired = false;
        DB::listen(function ($query) use (&$fired) {
            if (!$fired && stripos($query->sql, 'insert into `ordered_products_inventories`') === 0) {
                $fired = true;
                DB::table('updated_inventories')->where('id', Fixture::PHONE_128_BLACK_ID)->update(['quantity' => 0]);
            }
        });

        $ordersBefore = Order::count();
        $response = $this->placeOrder();

        $this->assertTrue($fired, 'the race was staged');
        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertStringContainsString('out of stock', strtolower(json_encode($response->json('data'))));
        $this->assertSame(0, $this->stock(), 'not -1');
        $this->assertSame($ordersBefore, Order::count(), 'the part-built order was rolled back');
        // Rolling back re-reserves the basket, and with nothing left to hold
        // the reservation trims the line - as it always has.
        $this->assertSame(0, Cart::where('user_id', $this->customer->id)->count());
    }

    public function test_a_failure_part_way_through_an_order_gives_back_the_stock_it_took()
    {
        $this->assertApiOk($this->add($this->phone(2)));
        $this->assertApiOk($this->add($this->laptop(3)));
        $this->reservationRanOut();

        // The second line's write fails after the first line's stock was taken.
        $seen = 0;
        DB::listen(function ($query) use (&$seen) {
            if (stripos($query->sql, 'insert into `ordered_products_inventories`') === 0 && ++$seen === 2) {
                throw new \RuntimeException('simulated failure');
            }
        });

        $response = $this->placeOrder();

        $this->assertNotSame(200, (int)$response->json('status'));

        // Everything came back, and the basket - still there - is held again.
        $held = CartHold::heldFor($this->customer->id, null, Fixture::PHONE_128_BLACK_ID);
        $this->assertSame(10, $this->stock() + $held, 'shelf plus reserved is what there was');
        $this->assertSame(100, $this->stock(Fixture::LAPTOP_INVENTORY_ID)
            + CartHold::heldFor($this->customer->id, null, Fixture::LAPTOP_INVENTORY_ID));
    }

    public function test_a_shopper_holding_the_last_unit_can_buy_it()
    {
        // The order's own check used to ignore the shopper's reserved units.
        $this->setStock(1);
        $this->assertApiOk($this->add($this->phone(1)));
        $this->assertSame(0, $this->stock(), 'reserved');

        $this->assertApiOk($this->placeOrder());
        $this->assertSame(0, $this->stock());
    }

    // --------------------------------------------------------------- lifetime

    private function guestLine($daysOld, $token = Fixture::GUEST_TOKEN)
    {
        $id = DB::table('carts')->insertGetId([
            'product_id' => Fixture::PRODUCT_PHONE_ID, 'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => 1, 'user_token' => $token, 'selected' => 1,
            'created_at' => $this->ago($daysOld * 1440), 'updated_at' => $this->ago($daysOld * 1440),
        ]);
        GuestUser::where('user_token', $token)->update(['updated_at' => $this->ago($daysOld * 1440)]);

        return $id;
    }

    public function test_an_old_guest_line_without_a_reservation_is_cleared()
    {
        $old = $this->guestLine(91);

        BasketUpkeep::sweep(true);

        $this->assertNull(Cart::find($old));
    }

    public function test_a_recent_or_revisited_guest_basket_is_kept()
    {
        $recent = $this->guestLine(89);
        BasketUpkeep::sweep(true);
        $this->assertNotNull(Cart::find($recent));

        Cart::where('id', $recent)->delete();
        $old = $this->guestLine(120);
        // Their browser asked for its token again today: a visit.
        $this->postJson('/api/v1/guest/token', ['user_token' => Fixture::GUEST_TOKEN]);
        BasketUpkeep::sweep(true);
        $this->assertNotNull(Cart::find($old));
    }

    // ---------------------------------------------------------------- Buy Now

    public function test_buy_now_leaves_the_basket_alone()
    {
        $this->assertApiOk($this->add($this->phone(3)));
        $this->assertApiOk($this->add($this->laptop(1)));

        $this->assertApiOk($this->buyNow($this->phone(1)));

        $basket = Cart::where('user_id', $this->customer->id)->where('buy_now', 0)->get()->keyBy('inventory_id');
        $this->assertSame(3, (int)$basket[Fixture::PHONE_128_BLACK_ID]->quantity, 'the basket line kept its quantity');
        $this->assertSame(1, (int)$basket[Fixture::LAPTOP_INVENTORY_ID]->selected, 'and nothing was unticked');
        $this->assertSame(1, Cart::where('user_id', $this->customer->id)->where('buy_now', 1)->count());
    }

    public function test_a_buy_now_order_buys_only_that_item_and_keeps_the_basket()
    {
        $this->assertApiOk($this->add($this->laptop(2)));
        $this->assertApiOk($this->buyNow($this->phone(1)));

        $this->assertApiOk($this->placeOrder());

        $this->assertSame(9, $this->stock(), 'the Buy Now unit, used from its reservation');
        $this->assertSame(98, $this->stock(Fixture::LAPTOP_INVENTORY_ID), 'the basket is still reserved, not ordered');
        $this->assertSame(2, CartHold::heldFor($this->customer->id, null, Fixture::LAPTOP_INVENTORY_ID));
        $this->assertSame(1, Cart::where('user_id', $this->customer->id)->count());
        $this->assertSame(2, (int)Cart::where('user_id', $this->customer->id)->value('quantity'));
    }

    public function test_the_basket_count_and_view_ignore_a_buy_now()
    {
        $this->assertApiOk($this->add($this->laptop(2)));
        $this->assertApiOk($this->buyNow($this->phone(1)));

        $lines = $this->basket();
        $this->assertCount(2, $lines, 'by-user returns both, flagged, for the checkout to pick from');
        $this->assertSame(1, $lines->where('buy_now', true)->count());

        $merge = $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/merge', ['user_token' => Fixture::GUEST_TOKEN]);
        $this->assertSame(2, (int)$merge->json('data.cart_count'), 'the count is the basket only');
    }

    public function test_a_new_buy_now_replaces_the_last_one()
    {
        $this->assertApiOk($this->buyNow($this->phone(1)));
        $this->assertApiOk($this->buyNow($this->laptop(1)));

        $this->assertSame([Fixture::LAPTOP_INVENTORY_ID],
            Cart::where('user_id', $this->customer->id)->where('buy_now', 1)->pluck('inventory_id')->all());
    }

    public function test_going_back_to_the_basket_cancels_the_buy_now()
    {
        $this->assertApiOk($this->add($this->laptop(1)));
        $this->assertApiOk($this->buyNow($this->phone(1)));

        $this->assertApiOk($this->asCustomer($this->customer->id)->postJson('/api/v1/cart/buy-now/cancel', []));

        $this->assertSame(0, Cart::where('user_id', $this->customer->id)->where('buy_now', 1)->count());
        $this->assertSame(1, Cart::where('user_id', $this->customer->id)->count(), 'the basket is untouched');

        // ...so the next checkout is the basket again.
        $this->assertApiOk($this->placeOrder());
        $this->assertSame(99, $this->stock(Fixture::LAPTOP_INVENTORY_ID));
        $this->assertSame(10, $this->stock());
    }

    public function test_an_abandoned_buy_now_is_cleared_after_30_minutes_unless_paying()
    {
        $this->assertApiOk($this->buyNow($this->phone(1)));
        Cart::where('buy_now', 1)->update(['updated_at' => $this->ago(31)]);

        // Starting to pay touches it.
        $this->assertApiOk($this->asCustomer($this->customer->id)->postJson('/api/v1/cart/hold-for-payment', []));
        BasketUpkeep::sweep();
        $this->assertSame(1, Cart::where('buy_now', 1)->count());

        Cart::where('buy_now', 1)->update(['updated_at' => $this->ago(31)]);
        BasketUpkeep::sweep();
        $this->assertSame(0, Cart::where('buy_now', 1)->count());
    }

    public function test_a_guest_buy_now_follows_them_into_their_account_without_joining_the_basket()
    {
        $this->asGuest()->postJson('/api/v1/cart/buy-now', $this->phone(1) + ['user_token' => Fixture::GUEST_TOKEN]);
        $this->assertSame(1, Cart::where('user_token', Fixture::GUEST_TOKEN)->where('buy_now', 1)->count());
        $this->assertApiOk($this->add($this->phone(2)));

        CartMerge::forUser($this->customer, Fixture::GUEST_TOKEN);

        $mine = Cart::where('user_id', $this->customer->id)->get();
        $this->assertSame(1, $mine->where('buy_now', true)->count(), 'still a Buy Now');
        $this->assertSame(2, (int)$mine->where('buy_now', false)->first()->quantity, 'not folded into the basket line');
    }

    // ------------------------------------------------- what each line says

    private function line()
    {
        return $this->basket()->firstWhere('inventory_id', Fixture::PHONE_128_BLACK_ID);
    }

    public function test_a_line_reports_its_availability()
    {
        $this->assertApiOk($this->add($this->phone(3)));
        $this->reservationRanOut();

        $this->setStock(50);
        $this->assertSame('in_stock', $this->line()['availability']);

        $this->setStock(4);
        $this->assertSame('low_stock', $this->line()['availability'], 'Only 4 left');
        $this->assertSame(4, $this->line()['available_quantity']);

        $this->setStock(2);
        $this->assertSame('short', $this->line()['availability'], 'asks for 3, only 2 available');

        $this->setStock(0);
        $this->assertSame('out_of_stock', $this->line()['availability']);

        $this->setStock(0, Fixture::PHONE_128_BLACK_ID, 1);
        $this->assertSame('backorder', $this->line()['availability']);
        $this->assertNull($this->line()['available_quantity']);

        $this->setStock(50);
        DB::table('products')->where('id', Fixture::PRODUCT_PHONE_ID)->update(['status' => 2]);
        $this->assertSame('unavailable', $this->line()['availability']);
    }

    public function test_a_price_change_is_reported_until_the_shopper_acts()
    {
        $this->assertApiOk($this->add($this->phone(1)));
        $this->assertNull($this->line()['price_changed_from']);

        DB::table('updated_inventories')->where('id', Fixture::PHONE_128_BLACK_ID)->update(['price' => 450]);

        $this->assertEquals(500, $this->line()['price_changed_from']);
        $this->assertEquals(450, $this->line()['unit_price']);
        $this->assertEquals(500, $this->line()['price_changed_from'], 'still there on the next read');

        // Changing the quantity is choosing it again at today's price.
        $this->assertApiOk($this->add($this->phone(1)));
        $this->assertNull($this->line()['price_changed_from']);
    }

    public function test_a_line_from_before_the_change_gets_a_baseline_without_a_notice()
    {
        $this->assertApiOk($this->add($this->phone(1)));
        Cart::where('user_id', $this->customer->id)->update(['added_price' => null]);

        $this->assertNull($this->line()['price_changed_from']);
        $this->assertEquals(500, (float)Cart::where('user_id', $this->customer->id)->value('added_price'));
    }

    public function test_the_payment_page_can_ask_before_charging()
    {
        $this->assertApiOk($this->add($this->phone(3)));
        $this->assertApiOk($this->asCustomer($this->customer->id)->postJson('/api/v1/cart/checkout-check', []));

        $this->reservationRanOut();
        $this->setStock(2);
        $refused = $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/checkout-check', []);

        $this->assertNotSame(200, (int)$refused->json('status'));
        $lineId = Cart::where('user_id', $this->customer->id)->value('id');
        // The same shape as the order's own refusal: {product: [{line id: [messages]}]}.
        $this->assertArrayHasKey($lineId, (array)$refused->json('data.product.0'), 'per line, like the order refusal');
    }
}
