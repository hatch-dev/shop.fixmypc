<?php

namespace Tests\Isolated;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Stripe at checkout (OrdersController::attemptPayment, 2026-09-29).
 *
 * What these pin:
 *  - the browser sends a PaymentMethod (pm_...) and the server charges it
 *    with a confirmed PaymentIntent for the order's own amount;
 *  - a card that needs 3-D Secure is answered with a 200 carrying the
 *    client_secret -- not paid, and no "payment failed" email;
 *  - the intent (pi_...) that comes back after the challenge is checked
 *    against the order before it marks anything paid;
 *  - a declined card can try again with another card (the idempotency key
 *    used to be the order alone, which Stripe refuses to reuse).
 *
 * Stripe's HTTP client is swapped for a fake, so no request leaves.
 */
class StripeCheckoutTest extends IsolatedTestCase
{
    /** @var FakeStripeClient */
    private $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('payments')->update([
            'stripe' => 1,
            'stripe_key' => 'pk_test_fake',
            'stripe_secret' => 'sk_test_fake',
            'card_gateway' => config('constants.paymentMethod.STRIPE'),
        ]);

        $this->stripe = new FakeStripeClient();
        \Stripe\ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function order(array $overrides = [])
    {
        return Order::create(array_merge([
            'order' => 'STRIPE' . uniqid(),
            'status' => config('constants.orderStatus.PENDING'),
            'order_method' => config('constants.paymentMethod.STRIPE'),
            'total_amount' => 149.99,
            'currency' => 'EUR',
            'payment_done' => 0,
            'user_token' => 'stripe-test-' . uniqid(),
            'contact_name' => 'Test Customer',
            'contact_email' => 'stripe.test@example.com',
            'contact_phone' => '+353871234567',
        ], $overrides));
    }

    private function pay(Order $order, string $token)
    {
        $json = json_encode([
            'id' => $order->id,
            'payment_token' => $token,
            'order_method' => config('constants.paymentMethod.STRIPE'),
            'user_token' => $order->user_token,
        ]);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return $this->postJson('/api/v1/order/payment-done', [
            'data' => openssl_encrypt($json, 'AES-128-CBC',
                hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
                hex2bin('abcdef1876343516abcdef9876543210')),
        ]);
    }

    private function intent(Order $order, string $status, array $extra = [])
    {
        return array_merge([
            'id' => 'pi_test_' . $order->id,
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => 14999,
            'currency' => 'eur',
            'client_secret' => 'pi_test_' . $order->id . '_secret_x',
            'metadata' => ['order_id' => (string)$order->id],
        ], $extra);
    }

    public function test_a_card_that_needs_no_challenge_is_charged_and_the_order_paid()
    {
        $order = $this->order();
        $this->stripe->queue(200, $this->intent($order, 'succeeded'));

        $this->assertApiOk($this->pay($order, 'pm_card_visa'));

        $sent = $this->stripe->requests[0];
        $this->assertSame('post', $sent['method']);
        $this->assertStringEndsWith('/v1/payment_intents', $sent['url']);
        $this->assertSame('pm_card_visa', $sent['params']['payment_method']);
        $this->assertSame(14999, (int)$sent['params']['amount'], 'the order amount, in cents');
        $this->assertSame('manual', $sent['params']['confirmation_method']);

        $order->refresh();
        $this->assertSame(1, (int)$order->payment_done);
        $this->assertSame('pi_test_' . $order->id, $order->trans_id);
    }

    public function test_a_3ds_card_gets_the_client_secret_and_is_not_paid_or_failed()
    {
        $order = $this->order();
        $this->stripe->queue(200, $this->intent($order, 'requires_action'));

        $response = $this->pay($order, 'pm_card_threeDSecure2Required');

        $response->assertJsonPath('status', 200);
        $response->assertJsonPath('data.requires_action', true);
        $response->assertJsonPath('data.client_secret', 'pi_test_' . $order->id . '_secret_x');

        $this->assertSame(0, (int)$order->fresh()->payment_done);
        $this->assertSame(0, DB::table('order_email_log')->where('order_id', $order->id)
            ->where('event', 'payment_failed')->count(), 'no "payment failed" email mid-challenge');
    }

    public function test_the_intent_back_from_the_challenge_is_confirmed_and_pays()
    {
        $order = $this->order();
        $this->stripe->queue(200, $this->intent($order, 'requires_confirmation'));
        $this->stripe->queue(200, $this->intent($order, 'succeeded'));

        $this->assertApiOk($this->pay($order, 'pi_test_' . $order->id));

        $this->assertSame('get', $this->stripe->requests[0]['method']);
        $this->assertStringEndsWith('/v1/payment_intents/pi_test_' . $order->id . '/confirm', $this->stripe->requests[1]['url']);
        $this->assertSame(1, (int)$order->fresh()->payment_done);
    }

    public function test_an_intent_for_another_order_is_refused()
    {
        $order = $this->order();
        $this->stripe->queue(200, $this->intent($order, 'succeeded', ['metadata' => ['order_id' => '999999']]));

        $this->assertApiRefused($this->pay($order, 'pi_test_' . $order->id), 201);
        $this->assertSame(0, (int)$order->fresh()->payment_done);
    }

    public function test_an_intent_for_less_than_the_order_is_refused()
    {
        $order = $this->order();
        $this->stripe->queue(200, $this->intent($order, 'succeeded', ['amount' => 100]));

        $this->assertApiRefused($this->pay($order, 'pi_test_' . $order->id), 201);
        $this->assertSame(0, (int)$order->fresh()->payment_done);
    }

    public function test_a_legacy_token_is_refused_without_calling_stripe()
    {
        $order = $this->order();

        $this->assertApiRefused($this->pay($order, 'tok_visa'), 201);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_declined_card_can_retry_with_another_card()
    {
        $order = $this->order();
        $this->stripe->queue(402, ['error' => [
            'type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.',
        ]]);
        $this->stripe->queue(200, $this->intent($order, 'succeeded'));

        $this->assertApiRefused($this->pay($order, 'pm_card_chargeDeclined'), 201, 'declined');
        $this->assertApiOk($this->pay($order, 'pm_card_visa'));

        $keys = array_map(function ($r) {
            return $r['headers']['Idempotency-Key'] ?? null;
        }, $this->stripe->requests);
        $this->assertNotSame($keys[0], $keys[1], 'a second card is a new request, not a replay');
        $this->assertSame(1, (int)$order->fresh()->payment_done);
    }

    // ------------------------------------------- one card option at checkout

    public function test_stripe_is_refused_while_sumup_takes_cards()
    {
        DB::table('payments')->update(['sumup' => 1, 'card_gateway' => config('constants.paymentMethod.SUMUP')]);
        $order = $this->order();

        $this->assertApiRefused($this->pay($order, 'pm_card_visa'), 201);
        $this->assertCount(0, $this->stripe->requests, 'Stripe was not called');
        $this->assertSame(0, (int)$order->fresh()->payment_done);
    }

    public function test_checkout_is_told_the_one_card_gateway()
    {
        DB::table('payments')->update(['sumup' => 1, 'stripe' => 1]);

        DB::table('payments')->update(['card_gateway' => config('constants.paymentMethod.STRIPE')]);
        $this->assertSame((int)config('constants.paymentMethod.STRIPE'), Payment::publicSettings()['card_gateway']);

        DB::table('payments')->update(['card_gateway' => config('constants.paymentMethod.SUMUP')]);
        $this->assertSame((int)config('constants.paymentMethod.SUMUP'), Payment::publicSettings()['card_gateway']);
    }

    public function test_unset_or_switched_off_falls_back_to_the_gateway_that_is_on()
    {
        DB::table('payments')->update(['sumup' => 1, 'stripe' => 1, 'card_gateway' => null]);
        $this->assertSame((int)config('constants.paymentMethod.SUMUP'), Payment::first()->cardGateway(), 'unset keeps the old rule');

        DB::table('payments')->update(['sumup' => 0, 'stripe' => 1, 'card_gateway' => config('constants.paymentMethod.SUMUP')]);
        $this->assertSame((int)config('constants.paymentMethod.STRIPE'), Payment::first()->cardGateway(), 'chosen but off');

        DB::table('payments')->update(['sumup' => 0, 'stripe' => 0]);
        $this->assertNull(Payment::first()->cardGateway(), 'no card option at all');
    }

    public function test_admin_cannot_hand_cards_to_another_gateway()
    {
        $settings = (array)DB::table('payments')->first();
        $settings['card_gateway'] = config('constants.paymentMethod.RAZORPAY');

        $this->asAdmin()->postJson('/api/admin/payment/save', $settings)
            ->assertJsonPath('message', 'Card payments can be handled by SumUp or Stripe only.');

        $this->assertSame((int)config('constants.paymentMethod.STRIPE'), (int)DB::table('payments')->value('card_gateway'));
    }

    public function test_gateways_the_store_does_not_use_are_saved_off()
    {
        $settings = (array)DB::table('payments')->first();
        foreach (Payment::RETIRED_GATEWAYS as $gateway) {
            $settings[$gateway] = 1;
        }

        $this->assertApiOk($this->asAdmin()->postJson('/api/admin/payment/save', $settings));

        $row = (array)DB::table('payments')->first();
        foreach (Payment::RETIRED_GATEWAYS as $gateway) {
            $this->assertSame(0, (int)$row[$gateway], $gateway);
        }
        $this->assertSame(1, (int)$row['stripe'], 'the gateways in use are left alone');
    }
}
