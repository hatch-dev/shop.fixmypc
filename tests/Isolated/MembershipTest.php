<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\Helper\Membership;
use App\Models\Helper\OrderSummary;
use App\Models\LoyaltyGroup;
use App\Models\LoyaltyGroupUser;
use App\Models\MembershipInvoice;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanBenefit;
use App\Models\Order;
use App\Models\OrderedLoyaltyDiscount;
use App\Models\Product;
use App\Models\User;
use App\Models\UserMembership;
use Carbon\Carbon;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Paid membership (2026-10-15): Helper\Membership, Helper\StripeBilling,
 * MembershipController, the admin controllers and the webhook.
 *
 * What these pin:
 *  - a member is charged the plan's discount, recorded in the loyalty slot
 *    with the plan's wording, and the basket preview shows the same figure;
 *  - lines already reduced (business band, flash sale) are left out unless
 *    the benefit says otherwise; loyalty and membership never stack, the
 *    bigger wins;
 *  - member delivery is free in the quote, the basket and the order;
 *  - a lapsed, cancelled or suspended membership gives nothing;
 *  - joining goes to Stripe Checkout for the plan's own price, the return is
 *    checked to be the caller's, the webhook is signature-checked and
 *    re-reads the subscription, cancel goes to Stripe;
 *  - admins can build plans, grant memberships, and cannot delete a plan
 *    someone holds.
 *
 * Stripe's HTTP client is swapped for FakeStripeClient; nothing leaves.
 */
class MembershipTest extends IsolatedTestCase
{
    /** @var User */
    private $customer;

    /** @var MembershipPlan */
    private $plan;

    /** @var FakeStripeClient */
    private $stripe;

    const WEBHOOK_SECRET = 'whsec_test_fake';

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::findOrFail(Fixture::USER_PERSONAL_ID);

        DB::table('payments')->update([
            'bank' => 1,
            'stripe_key' => 'pk_test_fake',
            'stripe_secret' => 'sk_test_fake',
            'stripe_webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $this->plan = MembershipPlan::create([
            'name' => 'Premium',
            'slug' => 'premium-test',
            'price' => 9.99,
            'currency' => 'EUR',
            'interval' => 'month',
            'interval_count' => 1,
            'status' => 1,
        ]);

        MembershipPlanBenefit::create([
            'membership_plan_id' => $this->plan->id,
            'type' => MembershipPlanBenefit::FREE_SHIPPING,
            'title' => 'Free Shipping',
            'sort_order' => 0,
        ]);

        MembershipPlanBenefit::create([
            'membership_plan_id' => $this->plan->id,
            'type' => MembershipPlanBenefit::PERCENT_DISCOUNT,
            'title' => 'Member discount',
            'percent' => 10,
            'sort_order' => 1,
        ]);

        $this->basket($this->customer->id, Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID, 1);

        $this->stripe = new FakeStripeClient();
        \Stripe\ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** One line, collected in store unless $delivery names a shipping place. */
    private function basket($userId, $productId, $inventoryId, $quantity, $deliveryPlaceId = null)
    {
        Cart::where('user_id', $userId)->delete();

        Cart::create([
            'user_id' => $userId,
            'product_id' => $productId,
            'inventory_id' => $inventoryId,
            'quantity' => $quantity,
            'selected' => (int)config('constants.status.PUBLIC'),
            'shipping_place_id' => $deliveryPlaceId ?: DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id'),
            'shipping_type' => $deliveryPlaceId
                ? (int)config('constants.shippingTypeIn.LOCATION')
                : (int)config('constants.shippingTypeIn.PICKUP'),
        ]);
    }

    private function member($userId = null, array $attributes = [])
    {
        return UserMembership::create(array_merge([
            'user_id' => $userId ?? $this->customer->id,
            'membership_plan_id' => $this->plan->id,
            'source' => UserMembership::SOURCE_ADMIN,
            'status' => 'active',
            'price' => 0,
            'currency' => 'EUR',
            'current_period_start' => Carbon::now()->subDay(),
            'current_period_end' => Carbon::now()->addMonth(),
        ], $attributes));
    }

    private function placeOrder(int $method = null)
    {
        $method = $method ?? (int)config('constants.paymentMethod.BANK');

        $json = json_encode([
            'user_token' => null,
            'order_method' => $method,
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ]);
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
        return Order::where('user_id', $this->customer->id)->orderByDesc('id')->first();
    }

    private function subscription(array $overrides = [])
    {
        $start = Carbon::now()->subDay()->timestamp;
        $end = Carbon::now()->addDays(29)->timestamp;

        return array_replace_recursive([
            'id' => 'sub_test_1',
            'object' => 'subscription',
            'status' => 'active',
            'customer' => 'cus_test_1',
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'trial_end' => null,
            'metadata' => ['purpose' => 'membership', 'user_id' => (string)$this->customer->id, 'plan_id' => (string)$this->plan->id],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_test_1',
                'object' => 'subscription_item',
                // 2025-03 API shape: the period lives on the item
                'current_period_start' => $start,
                'current_period_end' => $end,
                'price' => [
                    'id' => 'price_test_1',
                    'object' => 'price',
                    'unit_amount' => 999,
                    'currency' => 'eur',
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                    'metadata' => ['purpose' => 'membership', 'plan_id' => (string)$this->plan->id],
                ],
            ]]],
        ], $overrides);
    }

    private function stripeMember(array $attributes = [])
    {
        return $this->member(null, array_merge([
            'source' => UserMembership::SOURCE_STRIPE,
            'stripe_subscription_id' => 'sub_test_1',
            'stripe_customer_id' => 'cus_test_1',
            'price' => 9.99,
            'interval' => 'month',
        ], $attributes));
    }

    private function webhook(array $event, $secret = self::WEBHOOK_SECRET)
    {
        $payload = json_encode(array_merge(['id' => 'evt_test_' . uniqid(), 'object' => 'event'], $event));
        $time = time();
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, $secret);

        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    // ------------------------------------------------------- discount at the till

    public function test_a_member_is_charged_the_plan_discount_and_the_receipt_names_the_plan()
    {
        $membership = $this->member();

        $this->assertApiOk($this->placeOrder());

        $order = $this->latestOrder();
        $row = OrderedLoyaltyDiscount::where('order_id', $order->id)->first();

        $this->assertNotNull($row, 'the member discount is recorded on the order');
        $this->assertSame($membership->id, (int)$row->user_membership_id);
        $this->assertNull($row->loyalty_group_id);
        $this->assertEquals(50.00, (float)$row->discount, '10% of the 500 phone');
        $this->assertSame('Premium member discount', $row->label);
        $this->assertEquals(450.00, (float)$order->total_amount);

        $summary = OrderSummary::for($order->load(OrderSummary::SUMMARY_RELATIONS));
        $this->assertEquals(50.00, $summary['loyalty_discount'], 'every receipt reads the same slot');
        $this->assertSame('Premium member discount', $summary['loyalty_label']);
    }

    public function test_a_non_member_pays_full_price()
    {
        $this->assertApiOk($this->placeOrder());

        $order = $this->latestOrder();

        $this->assertEquals(500.00, (float)$order->total_amount);
        $this->assertSame(0, OrderedLoyaltyDiscount::where('order_id', $order->id)->count());
    }

    public function test_the_basket_preview_shows_the_discount_the_order_takes()
    {
        $this->member();

        $response = $this->asCustomer($this->customer->id)->getJson('/api/v1/user/loyalty-discount?subtotal=500');

        $response->assertJsonPath('active', true);
        $response->assertJsonPath('kind', 'membership');
        $response->assertJsonPath('label', 'Premium member discount');
        $this->assertEquals(50.00, $response->json('amount'));
    }

    public function test_a_lapsed_cancelled_or_suspended_membership_gives_nothing()
    {
        $this->stripeMember(['current_period_end' => Carbon::now()->subDays(2)]);
        $this->assertNull(Membership::currentFor($this->customer), 'two days past the period, with no renewal');

        UserMembership::query()->update(['current_period_end' => Carbon::now()->subHours(2)]);
        $this->assertNotNull(Membership::currentFor($this->customer), 'a renewal still on its way is honoured');

        UserMembership::query()->update(['status' => 'canceled']);
        $this->assertNull(Membership::currentFor($this->customer));

        $suspended = User::findOrFail(Fixture::USER_SUSPENDED_ID);
        $this->member($suspended->id);
        $this->assertNull(Membership::currentFor($suspended), 'a suspended account has no benefits');
    }

    public function test_band_priced_lines_are_left_out_unless_the_benefit_includes_them()
    {
        $business = User::findOrFail(Fixture::USER_BUSINESS_ID);
        $this->member($business->id);
        $this->basket($business->id, Fixture::PRODUCT_LAPTOP_ID, Fixture::LAPTOP_INVENTORY_ID, 10);

        $lines = Membership::checkoutLines($business);
        $this->assertEquals(9000.00, $lines['subtotal'], '10 laptops at the 900 band');
        $this->assertNull(Membership::discountFor($business, $lines['lines']), 'already discounted by the band');

        $benefit = $this->plan->benefits()->where('type', MembershipPlanBenefit::PERCENT_DISCOUNT)->first();
        $benefit->update(['include_business_prices' => true]);
        $this->assertEquals(900.00, Membership::discountFor($business, $lines['lines'])['discount']);

        $benefit->update(['max_discount' => 100]);
        $this->assertEquals(100.00, Membership::discountFor($business, $lines['lines'])['discount'], 'capped');
    }

    public function test_a_flash_sale_line_is_left_out_unless_the_benefit_includes_it()
    {
        $product = new Product();
        $product->setRawAttributes([
            'id' => 1, 'selling' => 500, 'offered' => 500,
            'end_time' => '2099-01-01 00:00:00', 'price' => 400,
        ]);

        $benefit = new MembershipPlanBenefit(['type' => MembershipPlanBenefit::PERCENT_DISCOUNT, 'percent' => 10]);

        $this->assertSame(0.0, Membership::lineAmount($product, null, 1, 400, $benefit));

        $benefit->include_sale_items = true;
        $this->assertSame(400.0, Membership::lineAmount($product, null, 1, 400, $benefit));
    }

    public function test_loyalty_and_membership_never_stack_the_bigger_wins()
    {
        $this->member();

        $group = LoyaltyGroup::create([
            'title' => 'VIP', 'status' => 1, 'discount_type' => 'fixed', 'discount_value' => 80,
            'validity' => 'one_time',
        ]);
        LoyaltyGroupUser::create(['loyalty_group_id' => $group->id, 'user_id' => $this->customer->id, 'joined_at' => Carbon::now()]);

        $lines = Membership::checkoutLines($this->customer);

        $best = Membership::bestOrderDiscount($this->customer, 500, $lines['lines']);
        $this->assertSame('loyalty', $best['kind']);
        $this->assertEquals(80.00, $best['discount']);

        $group->update(['discount_value' => 20]);
        $best = Membership::bestOrderDiscount($this->customer, 500, $lines['lines']);
        $this->assertSame('membership', $best['kind']);
        $this->assertEquals(50.00, $best['discount']);
    }

    // ---------------------------------------------------------------- delivery

    private function quote()
    {
        return $this->asCustomer($this->customer->id)->postJson('/api/v1/cart/shipping-quote', [
            'shipping_type' => (int)config('constants.shippingTypeIn.LOCATION'),
            'country' => 'IE',
        ]);
    }

    public function test_member_delivery_is_free_in_the_quote_and_a_minimum_order_is_respected()
    {
        $this->assertEquals(24.00, $this->quote()->json('data.price'), 'a non-member pays the Ireland row');

        $this->member();
        $response = $this->quote();
        $this->assertEquals(0.0, $response->json('data.price'));
        $this->assertTrue($response->json('data.member_free_shipping'));

        $this->plan->benefits()->where('type', MembershipPlanBenefit::FREE_SHIPPING)->update(['min_order' => 600]);
        $this->assertEquals(24.00, $this->quote()->json('data.price'), 'the 500 basket is under the 600 minimum');
    }

    public function test_a_member_order_is_not_charged_for_delivery()
    {
        $this->member();

        $addressId = DB::table('user_addresses')->insertGetId([
            'user_id' => $this->customer->id, 'country' => 'IE', 'state' => 'D', 'city' => 'Dublin',
            'zip' => 'D01', 'address_1' => '1 Test Street', 'name' => 'Test Customer', 'phone' => '+353871234567',
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $this->customer->update(['default_address' => $addressId]);
        $this->basket($this->customer->id, Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID, 1, 559);

        $this->assertApiOk($this->placeOrder());

        $order = $this->latestOrder();
        $this->assertEquals(0.0, (float)DB::table('ordered_products_inventories')->where('order_id', $order->id)->sum('shipping_price'));
        $this->assertEquals(450.00, (float)$order->total_amount, 'free delivery and the 10% off');
    }

    // ------------------------------------------------------------------ Stripe

    /** Stripe's answers for a join, up to and including the new subscription. */
    private function queueJoinUpTo(array $subscription, $priceIds = ['price_test_1'])
    {
        $this->stripe->queue(200, ['id' => 'prod_test_1', 'object' => 'product']);
        foreach ($priceIds as $id) {
            $this->stripe->queue(200, ['id' => $id, 'object' => 'price']);
        }
        $this->stripe->queue(200, ['id' => 'cus_test_1', 'object' => 'customer']);
        $this->stripe->queue(200, ['id' => 'pm_card_visa', 'object' => 'payment_method']);
        $this->stripe->queue(200, ['id' => 'pm_card_visa', 'object' => 'payment_method', 'customer' => 'cus_test_1']);
        $this->stripe->queue(200, $subscription);
    }

    /** A subscription as create returns it, with its first invoice's payment. */
    private function withPayment(array $subscription, string $intentStatus, array $intent = [])
    {
        $subscription['latest_invoice'] = [
            'id' => 'in_test_1', 'object' => 'invoice',
            'payment_intent' => array_merge(['id' => 'pi_test_1', 'object' => 'payment_intent', 'status' => $intentStatus,
                'client_secret' => 'pi_test_1_secret_x'], $intent),
        ];

        return $subscription;
    }

    private function join(array $body = [])
    {
        return $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/subscribe', array_merge([
            'plan_id' => $this->plan->id,
            'payment_method' => 'pm_card_visa',
        ], $body));
    }

    public function test_joining_charges_the_card_on_the_page_for_the_plan_price()
    {
        $this->queueJoinUpTo($this->withPayment($this->subscription(), 'succeeded'));
        $this->stripe->queue(200, $this->subscription());   // mirrored

        $response = $this->join();

        $this->assertApiOk($response);
        $response->assertJsonPath('data.requires_action', false);
        $response->assertJsonPath('data.membership.status', 'active');

        [$product, $price, $customer, , $attach, $create] = $this->stripe->requests;
        $this->assertSame(999, (int)$price['params']['unit_amount']);
        $this->assertSame((string)$this->customer->id, $customer['params']['metadata']['user_id']);
        $this->assertStringEndsWith('/v1/payment_methods/pm_card_visa/attach', $attach['url']);
        $this->assertSame('cus_test_1', $attach['params']['customer']);

        $this->assertStringEndsWith('/v1/subscriptions', $create['url']);
        $this->assertSame('price_test_1', $create['params']['items'][0]['price']);
        $this->assertSame('pm_card_visa', $create['params']['default_payment_method']);
        $this->assertSame('allow_incomplete', $create['params']['payment_behavior']);
        $this->assertSame('membership', $create['params']['metadata']['purpose']);
        $this->assertSame('2024-06-20', $create['headers']['Stripe-Version'], 'the version that still has latest_invoice.payment_intent');
        $this->assertNotEmpty($create['headers']['Idempotency-Key']);

        $this->assertSame('cus_test_1', $this->customer->fresh()->stripe_customer_id);
        $this->assertNotNull(Membership::currentFor($this->customer));
    }

    public function test_a_card_that_needs_3ds_is_challenged_on_the_page_then_joins()
    {
        $this->queueJoinUpTo($this->withPayment($this->subscription(['status' => 'incomplete']), 'requires_action'));

        $response = $this->join();

        $this->assertApiOk($response);
        $response->assertJsonPath('data.requires_action', true);
        $response->assertJsonPath('data.client_secret', 'pi_test_1_secret_x');
        $response->assertJsonPath('data.subscription', 'sub_test_1');
        $this->assertSame(0, UserMembership::count(), 'not a member until the bank says yes');

        // the page ran the challenge; the payment went through
        $this->stripe->queue(200, $this->withPayment($this->subscription(), 'succeeded'));
        $this->stripe->queue(200, $this->subscription());

        $finish = $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/subscribe/finish', ['subscription' => 'sub_test_1']);

        $this->assertApiOk($finish);
        $finish->assertJsonPath('data.membership.status', 'active');
        $this->assertSame(1, UserMembership::count());
    }

    public function test_a_member_cannot_join_twice()
    {
        $this->member();

        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/subscribe', ['plan_id' => $this->plan->id]);

        $this->assertApiRefused($response, 201, 'already have a membership');
        $this->assertEmpty($this->stripe->requests);
    }

    public function test_a_declined_card_says_why_and_leaves_nothing_behind()
    {
        $this->queueJoinUpTo($this->withPayment($this->subscription(['status' => 'incomplete']), 'requires_payment_method', [
            'last_payment_error' => ['message' => 'Your card has insufficient funds.'],
        ]));
        $this->stripe->queue(200, $this->subscription(['status' => 'incomplete']));
        $this->stripe->queue(200, $this->subscription(['status' => 'incomplete_expired']));

        $response = $this->join();

        $this->assertApiRefused($response, 201, 'insufficient funds');
        $this->assertSame(0, UserMembership::count());
        $cancel = end($this->stripe->requests);
        $this->assertSame('delete', $cancel['method'], 'the unpaid subscription is cancelled so it never bills');
        $this->assertStringEndsWith('/v1/subscriptions/sub_test_1', $cancel['url']);
    }

    public function test_someone_elses_subscription_cannot_be_finished()
    {
        $this->stripe->queue(200, $this->withPayment(
            $this->subscription(['metadata' => ['user_id' => '999999']]), 'succeeded'));

        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/subscribe/finish', ['subscription' => 'sub_test_1']);

        $this->assertApiRefused($response, 201, 'could not be found');
        $this->assertSame(0, UserMembership::count());
    }

    public function test_a_join_still_waiting_for_the_bank_is_not_a_member_even_via_the_webhook()
    {
        $this->stripe->queue(200, $this->subscription(['status' => 'incomplete']));

        $this->webhook(['type' => 'customer.subscription.created', 'data' => ['object' => $this->subscription(['status' => 'incomplete'])]])
            ->assertStatus(200)
            ->assertJsonPath('result', 'ignored');

        $this->assertSame(0, UserMembership::count());
    }

    public function test_the_card_is_changed_on_the_page_and_checked_with_the_bank()
    {
        $this->stripeMember();
        $this->stripe->queue(200, ['id' => 'seti_test_1', 'object' => 'setup_intent', 'status' => 'succeeded',
            'customer' => 'cus_test_1', 'payment_method' => 'pm_card_new']);
        $this->stripe->queue(200, $this->subscription());                       // default_payment_method set
        $this->stripe->queue(200, ['id' => 'cus_test_1', 'object' => 'customer']); // invoice default set
        $this->stripe->queue(200, $this->subscription());                       // mirrored

        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/card', ['payment_method' => 'pm_card_new']);

        $this->assertApiOk($response);
        $response->assertJsonPath('data.requires_action', false);

        [$setup, $subscription, $customer] = $this->stripe->requests;
        $this->assertStringEndsWith('/v1/setup_intents', $setup['url']);
        $this->assertSame('off_session', $setup['params']['usage']);
        $this->assertSame('cus_test_1', $setup['params']['customer']);
        $this->assertSame('pm_card_new', $subscription['params']['default_payment_method']);
        $this->assertSame('pm_card_new', $customer['params']['invoice_settings']['default_payment_method']);
        $this->assertSame('card_updated', UserMembership::firstOrFail()->events()->reorder()->orderBy('id')->value('action'));
    }

    public function test_the_manage_panel_shows_the_card_and_the_payments_read_from_stripe()
    {
        // no webhook ever reached this site: the history comes from Stripe itself
        $this->stripeMember();
        $this->stripe->queue(200, ['object' => 'list', 'data' => [[
            'id' => 'in_test_1', 'object' => 'invoice', 'number' => 'A-1', 'status' => 'paid',
            'amount_due' => 999, 'amount_paid' => 999, 'currency' => 'eur', 'subscription' => 'sub_test_1',
            'status_transitions' => ['paid_at' => time()],
            'lines' => ['object' => 'list', 'data' => [['period' => ['start' => time(), 'end' => time() + 2592000]]]],
        ]], 'has_more' => false]);
        $this->stripe->queue(200, $this->subscription(['default_payment_method' => [
            'id' => 'pm_card_visa', 'object' => 'payment_method',
            'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
        ]]));

        $response = $this->asCustomer($this->customer->id)->getJson('/api/v1/user/membership/billing');

        $this->assertApiOk($response);
        $this->assertStringContainsString('subscription=sub_test_1', $this->stripe->requests[0]['url'] . '?' . http_build_query($this->stripe->requests[0]['params'] ?? []));
        $response->assertJsonPath('data.card.last4', '4242');
        $response->assertJsonPath('data.card.brand', 'visa');
        $this->assertEquals(9.99, $response->json('data.invoices.0.amount'));
        $response->assertJsonPath('data.invoices.0.status', 'paid');
        $this->assertSame(1, MembershipInvoice::count(), 'kept for the admin page too');
    }
    public function test_the_payment_history_is_paged_and_later_pages_do_not_call_stripe()
    {
        $membership = $this->stripeMember();
        for ($i = 0; $i < 8; $i++) {
            MembershipInvoice::create([
                'user_membership_id' => $membership->id, 'stripe_invoice_id' => 'in_page_' . $i, 'status' => 'paid',
                'amount_due' => 9.99, 'amount_paid' => 9.99, 'currency' => 'EUR',
                'period_start' => Carbon::now()->subMonths($i), 'period_end' => Carbon::now()->subMonths($i)->addMonth(),
            ]);
        }
        $this->stripe->queue(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->stripe->queue(200, $this->subscription());

        $first = $this->asCustomer($this->customer->id)->getJson('/api/v1/user/membership/billing');
        $this->assertCount(6, $first->json('data.invoices'));
        $first->assertJsonPath('data.pagination.total', 8);
        $first->assertJsonPath('data.pagination.last_page', 2);

        $calls = count($this->stripe->requests);
        $second = $this->asCustomer($this->customer->id)->getJson('/api/v1/user/membership/billing?page=2');
        $this->assertCount(2, $second->json('data.invoices'));
        $second->assertJsonPath('data.pagination.current_page', 2);
        $this->assertSame($calls, count($this->stripe->requests), 'paging reads what page 1 stored');
    }
    public function test_a_signed_webhook_re_reads_the_subscription_and_a_forged_one_is_refused()
    {
        $membership = $this->stripeMember();

        $forged = $this->webhook(['type' => 'customer.subscription.updated', 'data' => ['object' => $this->subscription(['status' => 'canceled'])]], 'whsec_wrong');
        $forged->assertStatus(400);
        $this->assertEmpty($this->stripe->requests, 'nothing is read for an unsigned event');
        $this->assertSame('active', $membership->fresh()->status);

        // The event body says active; Stripe, asked again, says past_due and ending.
        $this->stripe->queue(200, $this->subscription(['status' => 'past_due', 'cancel_at_period_end' => true]));

        $this->webhook(['type' => 'customer.subscription.updated', 'data' => ['object' => $this->subscription()]])
            ->assertStatus(200)
            ->assertJsonPath('result', 'synced');

        $membership->refresh();
        $this->assertSame('past_due', $membership->status);
        $this->assertTrue($membership->cancel_at_period_end);
        $this->assertSame(['status', 'cancel_scheduled'], $membership->events()->reorder()->orderBy("id")->pluck('action')->all());
        $this->assertNotNull(Membership::currentFor($this->customer), 'benefits continue while Stripe retries');
    }

    public function test_a_paid_invoice_is_recorded_against_the_membership()
    {
        $membership = $this->stripeMember();
        $this->stripe->queue(200, $this->subscription());

        $this->webhook(['type' => 'invoice.paid', 'data' => ['object' => [
            'id' => 'in_test_1', 'object' => 'invoice', 'number' => 'ABC-0001', 'status' => 'paid',
            'amount_due' => 999, 'amount_paid' => 999, 'currency' => 'eur',
            // 2025-03 API shape: no top-level subscription
            'parent' => ['subscription_details' => ['subscription' => 'sub_test_1']],
            'status_transitions' => ['paid_at' => time()],
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/test',
            'lines' => ['object' => 'list', 'data' => [['period' => ['start' => time(), 'end' => time() + 2592000]]]],
        ]]])->assertStatus(200);

        $invoice = MembershipInvoice::firstOrFail();
        $this->assertSame($membership->id, (int)$invoice->user_membership_id);
        $this->assertEquals(9.99, $invoice->amount_paid);
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_cancel_is_sent_to_stripe_and_can_be_taken_back()
    {
        $this->stripeMember();
        $this->stripe->queue(200, $this->subscription(['cancel_at_period_end' => true]));

        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/cancel');

        $this->assertApiOk($response);
        $sent = $this->stripe->requests[0];
        $this->assertStringEndsWith('/v1/subscriptions/sub_test_1', $sent['url']);
        $this->assertSame('true', (string)$sent['params']['cancel_at_period_end']);
        $response->assertJsonPath('data.membership.cancel_at_period_end', true);
        $response->assertJsonPath('data.membership.can_resume', true);
        $response->assertJsonPath('data.membership.entitled', true);

        $this->stripe->queue(200, $this->subscription());
        $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/resume')
            ->assertJsonPath('data.membership.cancel_at_period_end', false);
    }

    // ---------------------------------------------------------- monthly / yearly

    public function test_the_customer_can_join_yearly_at_the_yearly_price()
    {
        $this->plan->update(['yearly_price' => 99]);
        $this->queueJoinUpTo($this->withPayment($this->subscription(), 'succeeded'), ['price_test_month', 'price_test_year']);
        $this->stripe->queue(200, $this->subscription());

        $this->assertApiOk($this->join(['interval' => 'year']));

        [, $monthly, $yearly, , , , $create] = $this->stripe->requests;

        $this->assertSame(999, (int)$monthly['params']['unit_amount']);
        $this->assertSame('month', $monthly['params']['recurring']['interval']);
        $this->assertSame(9900, (int)$yearly['params']['unit_amount']);
        $this->assertSame('year', $yearly['params']['recurring']['interval']);
        $this->assertSame('price_test_year', $create['params']['items'][0]['price'], 'the yearly price, not the monthly one');
        $this->assertSame('year', $create['params']['metadata']['interval']);

        $plan = $this->plan->fresh();
        $this->assertSame('price_test_month', $plan->stripe_price_id);
        $this->assertSame('price_test_year', $plan->stripe_yearly_price_id);
        $this->assertSame(17, $plan->yearlySaving(), '99 a year against 9.99 x 12');
    }

    public function test_a_period_the_plan_does_not_offer_is_refused()
    {
        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/subscribe', [
            'plan_id' => $this->plan->id,
            'interval' => 'year',
        ]);

        $this->assertApiRefused($response, 201, 'not offered yearly');
        $this->assertEmpty($this->stripe->requests);
    }

    public function test_a_monthly_member_can_switch_to_yearly()
    {
        $this->plan->update(['yearly_price' => 99]);
        // already mirrored to Stripe, so the switch makes no new prices
        $this->plan->update([
            'stripe_product_id' => 'prod_test_1',
            'stripe_price_id' => 'price_test_1',
            'stripe_price_key' => \App\Models\Helper\StripeBilling::priceKey($this->plan, 'month'),
            'stripe_yearly_price_id' => 'price_test_year',
            'stripe_yearly_price_key' => \App\Models\Helper\StripeBilling::priceKey($this->plan, 'year'),
        ]);
        $this->stripeMember();

        $before = $this->asCustomer($this->customer->id)->getJson('/api/v1/user/membership');
        $before->assertJsonPath('data.membership.switch_to.interval', 'year');
        $before->assertJsonPath('data.membership.switch_to.saving', 17);

        $this->stripe->queue(200, ['id' => 'prod_test_1', 'object' => 'product']);   // still there
        $this->stripe->queue(200, ['id' => 'prod_test_1', 'object' => 'product']);   // name kept up to date
        $this->stripe->queue(200, $this->subscription());
        $this->stripe->queue(200, $this->subscription(['items' => ['data' => [['price' => [
            'id' => 'price_test_year', 'unit_amount' => 9900, 'recurring' => ['interval' => 'year', 'interval_count' => 1],
        ]]]]]));

        $response = $this->asCustomer($this->customer->id)->postJson('/api/v1/user/membership/change', ['interval' => 'year']);

        $this->assertApiOk($response);
        $update = $this->stripe->requests[3];
        $this->assertSame('price_test_year', $update['params']['items'][0]['price']);
        $this->assertSame('si_test_1', $update['params']['items'][0]['id'], 'the same subscription item, not a second one');

        $membership = UserMembership::firstOrFail();
        $this->assertSame('year', $membership->interval);
        $this->assertEquals(99.00, $membership->price);
        $this->assertSame('billing_changed', $membership->events()->reorder()->orderByDesc('id')->value('action'));
        $response->assertJsonPath('data.membership.switch_to.interval', 'month');
    }

    public function test_ids_from_other_stripe_keys_are_replaced_not_reused()
    {
        // synced while the site ran on test keys; the keys are now live
        $this->plan->update([
            'stripe_product_id' => 'prod_from_test_keys',
            'stripe_price_id' => 'price_from_test_keys',
            'stripe_price_key' => \App\Models\Helper\StripeBilling::priceKey($this->plan, 'month'),
        ]);

        $this->stripe->queue(404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing',
            'message' => "No such product: 'prod_from_test_keys'"]]);
        $this->stripe->queue(200, ['id' => 'prod_live_1', 'object' => 'product']);
        $this->stripe->queue(200, ['id' => 'price_live_1', 'object' => 'price']);

        \App\Models\Helper\StripeBilling::syncPlan($this->plan->fresh());

        $plan = $this->plan->fresh();
        $this->assertSame('prod_live_1', $plan->stripe_product_id);
        $this->assertSame('price_live_1', $plan->stripe_price_id);
        $this->assertStringEndsWith('/v1/products', $this->stripe->requests[1]['url'], 'a new product, not an update of the old one');
        $this->assertCount(3, $this->stripe->requests, 'the old test price is not touched under the new keys');
    }
    public function test_a_plan_needs_a_monthly_or_a_yearly_price()
    {
        MembershipPlan::query()->delete();

        $response = $this->asAdmin()->postJson('/api/admin/membership-plans/action', $this->planInput(['price' => '', 'yearly_price' => '']));
        $this->assertApiRefused($response, 201, 'monthly price, a yearly price, or both');

        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/membership-plans/action', $this->planInput(['price' => '', 'yearly_price' => 49])));
        $plan = MembershipPlan::firstOrFail();
        $this->assertSame(['year'], $plan->intervals(), 'yearly only');
    }

    // ------------------------------------------------------------------- admin

    private function planInput(array $overrides = [])
    {
        return array_merge([
            'name' => 'Gold',
            'price' => 4.99,
            'interval' => 'month',
            'interval_count' => 1,
            'status' => 0,
            'benefits' => [
                ['type' => 'free_shipping', 'title' => 'Free Shipping', 'subtitle' => 'On all orders', 'highlight' => true],
                ['type' => 'percent_discount', 'title' => '5% off', 'percent' => 5, 'max_discount' => 50],
                ['type' => 'display', 'title' => 'Early Access', 'icon' => 'fa-star'],
            ],
        ], $overrides);
    }

    public function test_an_admin_builds_a_plan_with_its_benefits()
    {
        MembershipPlan::query()->delete(); // only one plan may exist

        $response = $this->asAdmin()->postJson('/api/admin/membership-plans/action', $this->planInput());

        $this->assertApiOk($response);

        $plan = MembershipPlan::where('name', 'Gold')->firstOrFail();
        $this->assertSame('gold', $plan->slug);
        $this->assertSame(['free_shipping', 'percent_discount', 'display'], $plan->benefits->pluck('type')->all());
        $this->assertEquals(50.0, $plan->benefit('percent_discount')->max_discount);
        $this->assertNull($plan->benefit('free_shipping')->percent, 'only a discount carries a percentage');
        $this->assertEmpty($this->stripe->requests, 'a plan that is switched off is not sent to Stripe');
    }

    public function test_only_one_plan_can_exist()
    {
        // setUp made one already
        $this->assertApiRefused(
            $this->asAdmin()->postJson('/api/admin/membership-plans/action', $this->planInput()),
            201, 'Only one membership plan'
        );
        $this->assertSame(1, MembershipPlan::count());

        // editing the one there is still works
        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/membership-plans/action/' . $this->plan->id,
            $this->planInput(['name' => 'Premium Plus'])));
        $this->assertSame('Premium Plus', $this->plan->fresh()->name);
    }

    public function test_a_plan_cannot_have_two_discounts_or_a_price_stripe_cannot_bill()
    {
        MembershipPlan::query()->delete(); // room for the one plan this test tries to make
        $input = $this->planInput();
        $input['benefits'][] = ['type' => 'percent_discount', 'title' => 'Another', 'percent' => 3];

        $this->assertApiRefused($this->asAdmin()->postJson('/api/admin/membership-plans/action', $input), 201, 'only one percentage discount');
        $this->assertApiRefused($this->asAdmin()->postJson('/api/admin/membership-plans/action', $this->planInput(['price' => 0.2])), 201);
        $this->assertSame(0, MembershipPlan::where('name', 'Gold')->count());
    }

    public function test_an_admin_grants_a_membership_that_pays_out()
    {
        $response = $this->asAdmin()->postJson('/api/admin/memberships/grant', [
            'user_id' => $this->customer->id,
            'plan_id' => $this->plan->id,
            'ends_at' => Carbon::now()->addMonths(3)->toDateString(),
            'note' => 'Apology for a late delivery',
        ]);

        $this->assertApiOk($response);
        $response->assertJsonPath('data.source', 'admin');
        $this->assertSame('granted', UserMembership::firstOrFail()->events()->value('action'));
        $this->assertEquals(50.00, Membership::discountFor($this->customer, Membership::checkoutLines($this->customer)['lines'])['discount']);

        $this->assertApiRefused($this->asAdmin()->postJson('/api/admin/memberships/grant', [
            'user_id' => $this->customer->id, 'plan_id' => $this->plan->id,
        ]), 201, 'already has a membership');
    }

    public function test_a_plan_someone_holds_is_switched_off_not_deleted()
    {
        $this->member();

        $response = $this->asAdmin()->deleteJson('/api/admin/membership-plans/delete/' . $this->plan->id);

        $this->assertApiOk($response);
        $this->assertNotNull($this->plan->fresh());
        $this->assertFalse($this->plan->fresh()->status);
        $this->assertNotNull(Membership::currentFor($this->customer), 'its members keep their membership');
    }
}
