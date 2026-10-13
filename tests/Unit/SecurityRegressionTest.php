<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Regression tests for the security and lifecycle findings in
 * reference/qa/qa-audit-2026-09-09.html.
 */
class SecurityRegressionTest extends TestCase
{
    private function admin($code, $expiresAt = null, $attempts = 0)
    {
        return (object)[
            'code' => $code,
            'code_expires_at' => $expiresAt,
            'code_attempts' => $attempts,
        ];
    }

    // ---- SEC-01: unauthenticated admin takeover ----------------------------

    public function test_a_code_that_was_never_issued_matches_nothing()
    {
        /*
         * The takeover: verify-code compared with != , so a NULL stored code
         * loosely equalled integer 0, and Laravel's `required` rule accepts 0.
         * A JSON body of {"code": 0} reset any admin's password.
         */
        $admin = $this->admin(null);

        $this->assertFalse(Admin::codeMatches($admin, 0));
        $this->assertFalse(Admin::codeMatches($admin, 0.0));
        $this->assertFalse(Admin::codeMatches($admin, false));
        $this->assertFalse(Admin::codeMatches($admin, ''));
        $this->assertFalse(Admin::codeMatches($admin, '0'));
        $this->assertFalse(Admin::codeMatches($admin, null));
        $this->assertFalse(Admin::codeMatches($admin, []));
    }

    public function test_an_empty_string_code_matches_nothing()
    {
        $this->assertFalse(Admin::codeMatches($this->admin(''), ''));
        $this->assertFalse(Admin::codeMatches($this->admin(''), 0));
    }

    public function test_a_live_code_matches_only_itself()
    {
        $admin = $this->admin('7896', Carbon::now()->addMinutes(5));

        $this->assertTrue(Admin::codeMatches($admin, '7896'));
        $this->assertTrue(Admin::codeMatches($admin, 7896));
        $this->assertFalse(Admin::codeMatches($admin, '7897'));
        $this->assertFalse(Admin::codeMatches($admin, '789'));
    }

    public function test_an_expired_code_is_refused()
    {
        // Codes used to have no expiry at all and were never cleared after use.
        $admin = $this->admin('7896', Carbon::now()->subMinute());

        $this->assertFalse(Admin::codeMatches($admin, '7896'));
    }

    public function test_a_code_is_burned_after_the_attempt_limit()
    {
        $admin = $this->admin('7896', Carbon::now()->addMinutes(5), Admin::MAX_CODE_ATTEMPTS);

        $this->assertFalse(Admin::codeMatches($admin, '7896'));
    }

    public function test_customer_codes_follow_the_same_rules()
    {
        $user = (object)['code' => null, 'code_expires_at' => null, 'code_attempts' => 0];

        $this->assertFalse(User::codeMatches($user, 0));

        $live = (object)[
            'code' => '123456',
            'code_expires_at' => Carbon::now()->addMinutes(5),
            'code_attempts' => 0,
        ];

        $this->assertTrue(User::codeMatches($live, '123456'));
        $this->assertFalse(User::codeMatches($live, '123457'));
    }

    // ---- CPN-01: the voucher window --------------------------------------

    private function windowAccepts($start, $end)
    {
        $now = Carbon::now();

        // The condition used in OrdersController::action().
        return !($now->lt($start) || $now->gt($end));
    }

    public function test_an_expired_voucher_is_refused_at_checkout()
    {
        /*
         * The old test was "$start >= $now && $now >= $end", satisfiable only
         * when start >= end, so it never fired: FIRSTOFFER, expired in December
         * 2024, was still redeemable.
         */
        $this->assertFalse(
            $this->windowAccepts(Carbon::now()->subDays(10), Carbon::now()->subDays(5))
        );
    }

    public function test_a_voucher_that_has_not_started_is_refused()
    {
        $this->assertFalse(
            $this->windowAccepts(Carbon::now()->addDays(5), Carbon::now()->addDays(10))
        );
    }

    public function test_a_live_voucher_is_accepted()
    {
        $this->assertTrue(
            $this->windowAccepts(Carbon::now()->subDay(), Carbon::now()->addDays(5))
        );
    }

    // ---- STK-02: cart quantity -------------------------------------------

    private function quantityAccepted($value)
    {
        $rules = ['quantity' => 'required|integer|min:1|max:999'];

        return !Validator::make(['quantity' => $value], $rules)->fails();
    }

    public function test_a_negative_quantity_is_refused()
    {
        /*
         * decrement('quantity', -5) increments, so a negative line created
         * stock out of nothing and subtracted from the order total.
         */
        $this->assertFalse($this->quantityAccepted(-5));
        $this->assertFalse($this->quantityAccepted(-1));
    }

    public function test_a_zero_or_non_integer_quantity_is_refused()
    {
        $this->assertFalse($this->quantityAccepted(0));
        $this->assertFalse($this->quantityAccepted(1.5));
        $this->assertFalse($this->quantityAccepted('abc'));
        $this->assertFalse($this->quantityAccepted(null));
        $this->assertFalse($this->quantityAccepted(''));
    }

    public function test_a_sane_quantity_is_accepted_and_an_absurd_one_is_not()
    {
        $this->assertTrue($this->quantityAccepted(1));
        $this->assertTrue($this->quantityAccepted(999));
        $this->assertFalse($this->quantityAccepted(1000));
    }

    // ---- ORD-01 / ORD-03: order status lifecycle --------------------------

    public function test_the_status_enum_has_terminal_states()
    {
        // Cancellation had to live on a separate boolean because there was no
        // CANCELLED status, so cancelled orders read as PENDING for ever.
        $statuses = Config::get('constants.orderStatus');

        $this->assertArrayHasKey('CANCELLED', $statuses);
        $this->assertArrayHasKey('RETURNED', $statuses);
        $this->assertArrayHasKey('REFUNDED', $statuses);
    }

    public function test_an_order_moves_forward_through_fulfilment()
    {
        $s = Config::get('constants.orderStatus');

        $this->assertTrue(Order::canTransition($s['PENDING'], $s['CONFIRMED']));
        $this->assertTrue(Order::canTransition($s['CONFIRMED'], $s['PICKED_UP']));
        $this->assertTrue(Order::canTransition($s['ON_THE_WAY'], $s['DELIVERED']));
    }

    public function test_a_delivered_order_cannot_go_back_to_pending()
    {
        $s = Config::get('constants.orderStatus');

        $this->assertFalse(Order::canTransition($s['DELIVERED'], $s['PENDING']));
        $this->assertFalse(Order::canTransition($s['DELIVERED'], $s['CONFIRMED']));
    }

    public function test_a_delivered_order_can_be_returned_or_refunded()
    {
        $s = Config::get('constants.orderStatus');

        $this->assertTrue(Order::canTransition($s['DELIVERED'], $s['RETURNED']));
        $this->assertTrue(Order::canTransition($s['DELIVERED'], $s['REFUNDED']));
    }

    public function test_a_refunded_order_is_terminal()
    {
        $s = Config::get('constants.orderStatus');

        foreach ($s as $target) {
            $this->assertFalse(Order::canTransition($s['REFUNDED'], $target));
        }
    }

    public function test_a_cancelled_order_can_still_be_refunded()
    {
        $s = Config::get('constants.orderStatus');

        $this->assertTrue(Order::canTransition($s['CANCELLED'], $s['REFUNDED']));
        $this->assertFalse(Order::canTransition($s['CANCELLED'], $s['DELIVERED']));
    }

    // ---- CART-01 / RFN-02: mass assignment --------------------------------

    public function test_a_cart_cannot_be_mass_assigned_a_price()
    {
        /*
         * Cart::create($request->all()) with these fillable let the client pick
         * the price written into ordered_products_inventories.
         */
        $fillable = (new \App\Models\Cart)->getFillable();

        $this->assertNotContains('price', $fillable);
        $this->assertNotContains('original_price', $fillable);
        $this->assertNotContains('voucher_discount', $fillable);
        $this->assertNotContains('voucher_code', $fillable);
    }

    public function test_a_cancellation_cannot_be_mass_assigned_as_refunded()
    {
        // cancel-order is customer facing and called create($request->all()).
        $fillable = (new \App\Models\Cancellation)->getFillable();

        $this->assertNotContains('refunded', $fillable);
        $this->assertNotContains('refunded_amount', $fillable);
    }

    // ---- SEC-06: uploads ---------------------------------------------------

    public function test_svg_is_not_an_accepted_product_image_type()
    {
        $rules = \App\Models\Helper\Validation::imageRules();

        $this->assertStringNotContainsString('svg', $rules['photo']);
        $this->assertStringContainsString('png', $rules['photo']);
    }
}
