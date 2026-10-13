<?php

namespace App\Models\Helper;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Stripe at checkout: pay first, order second (2026-09-29).
 *
 * The order used to be created before the card form opened, so closing the
 * form left an unpaid order behind and an emptied basket. Now:
 *
 *  1. authorise()  the card is authorised for the basket - the money is held
 *                  on the card, not taken. No order exists yet.
 *  2. capture()    OrdersController::action creates the order, checks the
 *                  authorisation against the order's own total, and takes the
 *                  money. Anything wrong and the order is rolled back.
 *  3. cancel()     the authorisation is released when no order comes of it.
 *
 * A browser that dies between 1 and 2 costs nothing: Stripe drops an
 * uncaptured authorisation by itself after about seven days.
 *
 * Payments on an order that already exists (the order page's "pay now") keep
 * the immediate charge in OrdersController::attemptPayment.
 */
class StripeCheckout
{
    /** metadata.purpose on every intent made here; nothing else is captured or cancelled. */
    const PURPOSE = 'checkout';

    /** Above this, a basket authorisation is refused outright. */
    const MAX_AMOUNT = 50000;

    private static function client(Payment $payment)
    {
        \Stripe\Stripe::setApiKey($payment->stripe_secret);
    }

    private static function ours($intent)
    {
        return ($intent->metadata['purpose'] ?? null) === self::PURPOSE;
    }

    /**
     * Authorise a card for a basket.
     *
     * $token is a PaymentMethod (pm_...) from the card form, or the intent
     * (pi_...) coming back after a 3-D Secure challenge.
     *
     * Returns ['status' => 'authorised'|'requires_action'|'failed', 'intent' => id,
     * 'client_secret' => for requires_action, 'message' => for failed].
     */
    public static function authorise(Payment $payment, $token, $amount, $currency, array $metadata = [])
    {
        $token = (string)$token;

        try {
            self::client($payment);

            if (strpos($token, 'pi_') === 0) {

                $intent = \Stripe\PaymentIntent::retrieve($token);

                if (!self::ours($intent)) {
                    return self::failed('This payment cannot be used here.');
                }

                if ($intent->status === 'requires_confirmation') {
                    $intent = $intent->confirm();
                }

            } else if (strpos($token, 'pm_') === 0) {

                $minor = Pricing::toMinorUnits($amount);

                if ($minor < 50 || $amount > self::MAX_AMOUNT) {
                    return self::failed('This amount cannot be paid by card.');
                }

                $intent = \Stripe\PaymentIntent::create([
                    'amount' => $minor,
                    'currency' => strtolower($currency),
                    'payment_method' => $token,
                    'payment_method_types' => ['card'],
                    // Held on the card, taken only once the order exists.
                    'capture_method' => 'manual',
                    'confirmation_method' => 'manual',
                    'confirm' => true,
                    'metadata' => array_merge($metadata, ['purpose' => self::PURPOSE]),
                ], [
                    // The same card for the same amount is one authorisation,
                    // however often the button is pressed.
                    'idempotency_key' => 'basket-' . sha1($token . '|' . $minor . '|' . strtolower($currency)),
                ]);

            } else {
                return self::failed('Card details were not received. Please try again.');
            }

            if ($intent->status === 'requires_capture') {
                return ['status' => 'authorised', 'intent' => $intent->id];
            }

            if ($intent->status === 'requires_action') {
                return [
                    'status' => 'requires_action',
                    'intent' => $intent->id,
                    'client_secret' => $intent->client_secret,
                ];
            }

            Log::info('Stripe authorisation not completed', [
                'intent' => $intent->id,
                'status' => $intent->status,
            ]);

            return self::failed('Your card was not authorised. Please try another card.');

        } catch (\Stripe\Exception\CardException $e) {

            // A decline: the bank's own reason is the useful answer.
            return self::failed($e->getMessage());

        } catch (\Throwable $e) {

            Log::error('Stripe authorisation failed', ['message' => $e->getMessage()]);

            return self::failed('The card payment could not be started. Please try again.');
        }
    }

    /**
     * Take the money for an order the authorisation was made for.
     *
     * Same shape as GatewayVerifier: ['ok', 'reason', 'reference', 'charged'].
     * charged is false whenever this returns not-ok - nothing is taken unless
     * every check passes - so the caller can always roll the order back.
     */
    public static function capture(Payment $payment, $intentId, $expectedAmount, $currency)
    {
        try {
            self::client($payment);

            $intent = \Stripe\PaymentIntent::retrieve((string)$intentId);

            if (!self::ours($intent)) {
                return self::fail('intent was not made for checkout');
            }

            if (!GatewayVerifier::amountsMatch($intent->amount / 100, $expectedAmount)) {
                return self::fail('authorised ' . ($intent->amount / 100) . ', order is ' . $expectedAmount);
            }

            if (strtolower($intent->currency) !== strtolower($currency)) {
                return self::fail('currency ' . $intent->currency . ', order is ' . $currency);
            }

            // Captured already - a replay after the order write failed. The
            // caller's one-intent-one-order check stops a second order.
            if ($intent->status === 'succeeded') {
                return self::pass($intent->id);
            }

            if ($intent->status !== 'requires_capture') {
                return self::fail('intent is ' . $intent->status);
            }

            $captured = $intent->capture();

            if ($captured->status !== 'succeeded') {
                return self::fail('capture left the intent ' . $captured->status);
            }

            return self::pass($captured->id);

        } catch (\Throwable $e) {

            Log::error('Stripe capture failed', [
                'intent' => $intentId,
                'message' => $e->getMessage(),
            ]);

            return self::fail('capture error: ' . $e->getMessage());
        }
    }

    /**
     * Release an authorisation no order came of. Never throws: the hold
     * expires on its own if this cannot reach Stripe.
     */
    public static function cancel(Payment $payment, $intentId, $reason = 'abandoned')
    {
        if (strpos((string)$intentId, 'pi_') !== 0) {
            return false;
        }

        try {
            self::client($payment);

            $intent = \Stripe\PaymentIntent::retrieve((string)$intentId);

            $cancellable = ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'];

            if (!self::ours($intent) || !in_array($intent->status, $cancellable, true)) {
                return false;
            }

            $intent->cancel(['cancellation_reason' => $reason]);

            return true;

        } catch (\Throwable $e) {

            Log::warning('Stripe authorisation could not be released', [
                'intent' => $intentId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function failed($message)
    {
        return ['status' => 'failed', 'message' => $message];
    }

    private static function fail($reason)
    {
        return ['ok' => false, 'reason' => $reason, 'reference' => null, 'charged' => false];
    }

    private static function pass($reference)
    {
        return ['ok' => true, 'reason' => null, 'reference' => $reference, 'charged' => true];
    }
}
