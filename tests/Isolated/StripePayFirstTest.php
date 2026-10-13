<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Stripe at checkout pays first (App\Models\Helper\StripeCheckout, 2026-09-29).
 *
 * The order used to be created before the card form opened, so closing the
 * form left an unpaid order and an emptied basket. What these pin:
 *  - the card is authorised (capture_method manual) for someone with a
 *    basket, while Stripe is the card gateway - never for anyone else;
 *  - the order is placed with the authorisation and the money is captured
 *    then, only if it matches the order the server prices;
 *  - any refusal leaves no order, keeps the basket, and releases the hold;
 *  - one authorisation pays for one order.
 */
class StripePayFirstTest extends IsolatedTestCase
{
    private $customer;

    /** @var FakeStripeClient */
    private $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('payments')->update([
            'stripe' => 1,
            'bank' => 1,
            'stripe_key' => 'pk_test_fake',
            'stripe_secret' => 'sk_test_fake',
            'card_gateway' => config('constants.paymentMethod.STRIPE'),
        ]);

        $this->customer = User::findOrFail(Fixture::USER_PERSONAL_ID);

        DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->update(['pickup_point' => 1]);
        Cart::where('user_id', $this->customer->id)->delete();
        $this->basket();

        $this->stripe = new FakeStripeClient();
        \Stripe\ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    // One phone, collected in store: no address needed.
    private function basket()
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

    private function basketCount()
    {
        return Cart::where('user_id', $this->customer->id)->count();
    }

    private function liveOrders()
    {
        return Order::where('user_id', $this->customer->id)->count();
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

    private function placeStripeOrder($intent)
    {
        return $this->placeOrder((int)config('constants.paymentMethod.STRIPE'), ['payment_intent' => $intent]);
    }

    // What the server prices this basket at: placed by bank, then undone.
    private function orderTotal()
    {
        $this->assertApiOk($this->placeOrder((int)config('constants.paymentMethod.BANK')));
        $order = Order::where('user_id', $this->customer->id)->orderByDesc('id')->first();
        $total = round((float)$order->total_amount, 2);

        $order->restoreStockAndRewards();
        $order->delete();
        Cart::where('user_id', $this->customer->id)->delete();
        $this->basket();

        return $total;
    }

    private function intent(string $status, $amount, array $extra = [])
    {
        return array_merge([
            'id' => 'pi_basket_1',
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => (int)round($amount * 100),
            'currency' => 'eur',
            'client_secret' => 'pi_basket_1_secret_x',
            'metadata' => ['purpose' => 'checkout'],
        ], $extra);
    }

    private function authorise(array $body = [])
    {
        return $this->asCustomer($this->customer->id)->postJson('/api/v1/order/stripe-authorise', array_merge([
            'payment_token' => 'pm_card_visa',
            'amount' => 100.00,
        ], $body));
    }

    // ------------------------------------------------------------- authorise

    public function test_the_card_is_authorised_not_charged_and_no_order_is_made()
    {
        $this->stripe->queue(200, $this->intent('requires_capture', 100));

        $response = $this->authorise();

        $this->assertApiOk($response);
        $response->assertJsonPath('data.status', 'authorised');
        $response->assertJsonPath('data.intent', 'pi_basket_1');

        $sent = $this->stripe->requests[0]['params'];
        $this->assertSame('manual', $sent['capture_method'], 'held, not taken');
        $this->assertSame(10000, (int)$sent['amount']);
        $this->assertSame('checkout', $sent['metadata']['purpose']);

        $this->assertSame(0, $this->liveOrders(), 'no order yet');
        $this->assertSame(1, $this->basketCount(), 'basket untouched');
    }

    public function test_a_3ds_card_gets_its_challenge_and_comes_back_authorised()
    {
        $this->stripe->queue(200, $this->intent('requires_action', 100));
        $this->authorise()->assertJsonPath('data.status', 'requires_action')
            ->assertJsonPath('data.client_secret', 'pi_basket_1_secret_x');

        $this->stripe->queue(200, $this->intent('requires_confirmation', 100));
        $this->stripe->queue(200, $this->intent('requires_capture', 100));
        $this->authorise(['payment_token' => 'pi_basket_1'])->assertJsonPath('data.status', 'authorised');
    }

    public function test_no_basket_no_authorisation()
    {
        Cart::where('user_id', $this->customer->id)->delete();

        $this->assertApiRefused($this->authorise(), 201, 'basket is empty');
        $this->assertCount(0, $this->stripe->requests, 'Stripe was not called');
    }

    public function test_no_authorisation_while_sumup_takes_cards()
    {
        DB::table('payments')->update(['sumup' => 1, 'card_gateway' => config('constants.paymentMethod.SUMUP')]);

        $this->assertApiRefused($this->authorise(), 201);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_declined_card_says_why()
    {
        $this->stripe->queue(402, ['error' => [
            'type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.',
        ]]);

        $this->assertApiRefused($this->authorise(), 201, 'declined');
    }

    // ----------------------------------------------------------- placing it

    public function test_the_order_is_placed_and_paid_with_the_authorisation()
    {
        $total = $this->orderTotal();

        $this->stripe->queue(200, $this->intent('requires_capture', $total));
        $this->stripe->queue(200, $this->intent('succeeded', $total));

        $response = $this->placeStripeOrder('pi_basket_1');
        $this->assertApiOk($response);

        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame(1, (int)$order->payment_done, 'paid');
        $this->assertSame('pi_basket_1', $order->trans_id);
        $this->assertStringEndsWith('/v1/payment_intents/pi_basket_1/capture', end($this->stripe->requests)['url']);
        $this->assertSame(0, $this->basketCount(), 'basket emptied only now');
    }

    public function test_an_authorisation_for_the_wrong_amount_places_nothing_and_is_released()
    {
        $total = $this->orderTotal();

        $this->stripe->queue(200, $this->intent('requires_capture', $total - 10));
        // The release: read back, then cancelled.
        $this->stripe->queue(200, $this->intent('requires_capture', $total - 10));
        $this->stripe->queue(200, $this->intent('canceled', $total - 10));

        $this->assertApiRefused($this->placeStripeOrder('pi_basket_1'), 201);

        $this->assertSame(0, $this->liveOrders(), 'rolled back');
        $this->assertSame(1, $this->basketCount(), 'basket kept');
        $urls = array_column($this->stripe->requests, 'url');
        $this->assertStringEndsWith('/pi_basket_1/cancel', end($urls));
        $this->assertEmpty(array_filter($urls, function ($u) {
            return substr($u, -8) === '/capture';
        }), 'never captured');
    }

    public function test_a_refusal_before_payment_releases_the_authorisation()
    {
        // Nothing to order: refused long before the payment step.
        Cart::where('user_id', $this->customer->id)->delete();

        $this->stripe->queue(200, $this->intent('requires_capture', 100));
        $this->stripe->queue(200, $this->intent('canceled', 100));

        $this->assertApiRefused($this->placeStripeOrder('pi_basket_1'), 201);
        $this->assertStringEndsWith('/pi_basket_1/cancel', end($this->stripe->requests)['url']);
    }

    public function test_a_stripe_order_without_an_authorisation_is_refused()
    {
        $this->assertApiRefused($this->placeStripeOrder(null), 201, 'not completed');

        $this->assertSame(0, $this->liveOrders());
        $this->assertSame(1, $this->basketCount());
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_one_authorisation_pays_for_one_order()
    {
        $total = $this->orderTotal();

        $this->stripe->queue(200, $this->intent('requires_capture', $total));
        $this->stripe->queue(200, $this->intent('succeeded', $total));
        $this->assertApiOk($this->placeStripeOrder('pi_basket_1'));

        // Same authorisation, a fresh basket.
        $this->basket();
        $this->stripe->queue(200, $this->intent('succeeded', $total));
        $this->stripe->queue(200, $this->intent('succeeded', $total));

        $this->assertApiRefused($this->placeStripeOrder('pi_basket_1'), 201);
        $this->assertSame(1, $this->liveOrders(), 'still one order');
        $this->assertSame(1, $this->basketCount(), 'second basket kept');
    }
}
