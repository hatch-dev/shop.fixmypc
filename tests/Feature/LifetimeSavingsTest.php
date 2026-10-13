<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderedVoucher;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * "Total Saved" on the Vouchers and Savers Club pages: GET /api/v1/user/savings.
 *
 * The Vouchers page used to add up the face value of every voucher in the
 * list -- unused, expired, and a 15% voucher counted as 15 euro -- so a
 * customer who had never used one was told they had saved 40 euro. Savers
 * Club read a field that did not exist and always said 0. Both now read this
 * one figure: the discount recorded on each order that stands.
 */
class LifetimeSavingsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    private $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->customer('saver');
        Passport::actingAs($this->customer, ['user'], 'user');
    }

    private function customer($tag)
    {
        return User::create([
            'name' => 'Savings ' . $tag,
            'email' => "fixmypc-savings-$tag-" . uniqid() . '@yopmail.com',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    /** A store voucher of our own, copied from an existing one for its shape. */
    private function voucher(array $overrides = [])
    {
        $v = Voucher::query()->whereNull('gift_value')->firstOrFail()->replicate();
        $v->fill(array_merge(['code' => 'SAVETEST' . strtoupper(uniqid()), 'user_id' => null], $overrides));
        $v->gift_value = $overrides['gift_value'] ?? null;
        $v->save();

        return $v;
    }

    /** An order that used the voucher, standing (paid) unless said otherwise. */
    private function orderUsing(Voucher $voucher, User $user, $discount, array $overrides = [])
    {
        $order = Order::create(array_merge([
            'order' => 'TEST' . uniqid(),
            'user_id' => $user->id,
            'voucher_id' => $voucher->id,
            'status' => config('constants.orderStatus.PENDING'),
            'order_method' => config('constants.paymentMethod.WALLET'),
            'total_amount' => 50,
            'currency' => 'EUR',
            'payment_done' => 1,
            'contact_name' => 'Aoife Murphy',
            'contact_email' => $user->email,
            'contact_phone' => '+353871234567',
        ], $overrides));

        OrderedVoucher::create(['order_id' => $order->id, 'voucher_id' => $voucher->id, 'discount' => $discount]);

        return $order;
    }

    private function saved()
    {
        $res = $this->getJson('/api/v1/user/savings');
        $res->assertOk();

        return $res->json('total_saved');
    }

    public function test_holding_vouchers_is_not_saving()
    {
        // the screenshot: vouchers in the list, none ever used
        $this->voucher(['type' => 1, 'price' => 10]);
        $this->voucher(['type' => 2, 'price' => 15]);

        $this->assertSame('0.00', $this->saved());
    }

    public function test_it_is_the_discount_each_order_got_not_the_voucher_rate()
    {
        $flat = $this->voucher(['type' => 1, 'price' => 10]);
        $percent = $this->voucher(['type' => 2, 'price' => 15]);

        $this->orderUsing($flat, $this->customer, 10);
        $this->orderUsing($percent, $this->customer, 7.35); // 15% of 49
        $this->orderUsing($flat, $this->customer, 10);

        $this->assertSame('27.35', $this->saved());
    }

    public function test_orders_that_do_not_stand_are_not_counted()
    {
        $v = $this->voucher();
        $status = function ($k) { return config("constants.orderStatus.$k"); };

        $this->orderUsing($v, $this->customer, 5);                                      // counts
        // the flag is not mass-assignable, so set it the way the app does
        $c = $this->orderUsing($v, $this->customer, 1);
        $c->cancelled = 1;
        $c->save();
        $this->orderUsing($v, $this->customer, 2, ['status' => $status('CANCELLED')]);
        $this->orderUsing($v, $this->customer, 3, ['status' => $status('RETURNED')]);
        $this->orderUsing($v, $this->customer, 4, ['status' => $status('REFUNDED')]);
        $this->orderUsing($v, $this->customer, 6, ['payment_done' => 0]);              // card never paid
        $this->orderUsing($v, $this->customer, 7)->delete();                            // soft-deleted

        // cash on delivery stands before it is paid, as it does for usage limits
        $this->orderUsing($v, $this->customer, 8, [
            'payment_done' => 0,
            'order_method' => config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);

        $this->assertSame('13.00', $this->saved());
    }

    public function test_spending_a_gift_card_is_paying_not_saving()
    {
        $card = $this->voucher(['gift_value' => 25, 'price' => 25, 'user_id' => $this->customer->id]);
        $this->orderUsing($card, $this->customer, 25);

        $this->assertSame('0.00', $this->saved());
    }

    public function test_only_the_callers_own_orders_count()
    {
        $v = $this->voucher();
        $this->orderUsing($v, $this->customer('someone-else'), 9);
        $this->orderUsing($v, $this->customer, 1.5);

        $this->assertSame('1.50', $this->saved());
    }

    public function test_a_guest_gets_no_figure()
    {
        $this->app['auth']->forgetGuards();
        $res = $this->withHeaders(['Authorization' => 'Bearer not-a-token'])->getJson('/api/v1/user/savings');

        $this->assertNull($res->json('total_saved'));
    }
}
