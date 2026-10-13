<?php

namespace App\Models\Helper;

use App\Models\Payment;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send a refund back through whichever gateway took the payment.
 *
 * QA audit RFN-01. Refunding used to flip a boolean on the cancellation row and
 * nothing else — no gateway call for any of the seven online methods, no amount
 * recorded, no reference. The admin saw "Refunded"; the customer's money stayed
 * where it was.
 *
 * Returns ['ok' => bool, 'reason' => string|null, 'reference' => string|null].
 */
class Refunder
{
    const TIMEOUT_SECONDS = 30;


    private static function fail($reason)
    {
        return ['ok' => false, 'reason' => $reason, 'reference' => null];
    }

    private static function pass($reference)
    {
        return ['ok' => true, 'reason' => null, 'reference' => $reference];
    }


    public static function refund($order, $amount)
    {
        /*
         * A split payment (part wallet, part the order's own method) is
         * refunded card first: the method gets back up to what it was
         * charged, and only the rest returns to the wallet. A full wallet
         * payment (order_method WALLET) is not a split and goes through
         * refundWallet below as before.
         */
        $walletPaid = (float)($order->wallet_amount ?? 0);

        if ($walletPaid > 0
            && (int)$order->order_method !== (int)Config::get('constants.paymentMethod.WALLET')) {
            return self::refundSplit($order, $amount);
        }

        return self::refundByMethod($order, $amount);
    }


    private static function refundSplit($order, $amount)
    {
        $amount = Pricing::round($amount);
        $methodPart = Pricing::round(min($amount, WalletSplit::amountDue($order)));
        $walletPart = Pricing::round($amount - $methodPart);
        $references = [];

        if ($methodPart > 0) {
            $method = self::refundByMethod($order, $methodPart);

            // The wallet is not touched unless the card refund went through.
            if (!$method['ok']) {
                return $method;
            }

            $references[] = $method['reference'];
        }

        if ($walletPart > 0) {
            $returned = WalletSplit::credit($order, $walletPart, 'refund');

            if ($returned <= 0) {
                Log::error('Split refund: wallet part not returned', [
                    'order_id' => $order->id, 'wanted' => $walletPart,
                ]);

                return self::fail('card part refunded (' . implode(', ', $references)
                    . ') but the wallet part could not be returned');
            }

            $references[] = 'WALLET-REFUND-' . number_format($returned, 2, '.', '');
        }

        return self::pass(implode(' + ', $references));
    }


    private static function refundByMethod($order, $amount)
    {
        $payment = Payment::first();

        if (is_null($payment)) {
            return self::fail('no payment configuration');
        }

        if (empty($order->trans_id)) {
            return self::fail('order has no gateway transaction reference');
        }

        $method = (int)$order->order_method;
        $methods = Config::get('constants.paymentMethod');

        switch ($method) {
            case $methods['STRIPE']:
                return self::refundStripe($payment, $order, $amount);

            case $methods['RAZORPAY']:
                return self::refundRazorpay($payment, $order, $amount);

            case $methods['PAYPAL']:
                return self::refundPaypal($payment, $order, $amount);

            case $methods['SUMUP']:
                return self::refundSumup($payment, $order, $amount);

            case $methods['WALLET']:
                return self::refundWallet($order, $amount);

            default:
                /*
                 * Flutterwave, iyzico, PayFast and bank transfer have no refund
                 * integration here. Say so plainly rather than reporting a
                 * refund that did not happen — the admin refunds these in the
                 * gateway's own dashboard.
                 */
                return self::fail('no automated refund for payment method ' . $method
                    . '; refund manually in the gateway dashboard');
        }
    }


    /**
     * A wallet payment goes back to the wallet it came from. Never more than
     * was paid in total, however many times the refund is attempted.
     */
    private static function refundWallet($order, $amount)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($order, $amount) {

            $wallet = \App\Models\Wallet::where('user_id', $order->user_id)->lockForUpdate()->first();

            if (!$wallet) {
                return self::fail('customer has no wallet');
            }

            $paid = (float)\App\Models\WalletTransaction::where('user_id', $order->user_id)
                ->where('source', 'order')->where('type', 'debit')
                ->where('reference_id', $order->id)->sum('amount');

            $refunded = (float)\App\Models\WalletTransaction::where('user_id', $order->user_id)
                ->whereIn('source', WalletSplit::RETURN_SOURCES)->where('type', 'credit')
                ->where('reference_id', $order->id)->sum('amount');

            $amount = round(min((float)$amount, $paid - $refunded), 2);

            if ($amount <= 0) {
                return self::fail('nothing left to refund to the wallet for this order');
            }

            $newBalance = round((float)$wallet->balance + $amount, 2);
            $wallet->update(['balance' => $newBalance]);

            $txn = \App\Models\WalletTransaction::create([
                'user_id'       => $order->user_id,
                'type'          => 'credit',
                'amount'        => $amount,
                'balance_after' => $newBalance,
                'source'        => 'refund',
                'reference_id'  => $order->id,
                'status'        => 'success',
            ]);

            return self::pass('WALLET-REFUND-' . $txn->id);
        });
    }


    private static function refundStripe($payment, $order, $amount)
    {
        try {
            \Stripe\Stripe::setApiKey($payment->stripe_secret);

            $params = [
                'amount' => Pricing::toMinorUnits($amount),
                'reason' => 'requested_by_customer',
                'metadata' => ['order_id' => $order->id],
            ];

            // trans_id is a PaymentIntent id for anything charged after the
            // PAY-04 fix, and a Charge id for older orders.
            if (strpos((string)$order->trans_id, 'pi_') === 0) {
                $params['payment_intent'] = $order->trans_id;
            } else {
                $params['charge'] = $order->trans_id;
            }

            $refund = \Stripe\Refund::create($params, [
                'idempotency_key' => 'refund-order-' . $order->id
            ]);

            if (!in_array($refund->status, ['succeeded', 'pending'], true)) {
                return self::fail('stripe refund status ' . $refund->status);
            }

            return self::pass($refund->id);

        } catch (\Throwable $e) {
            Log::error('Stripe refund error', ['message' => $e->getMessage()]);
            return self::fail('stripe: ' . $e->getMessage());
        }
    }


    private static function refundRazorpay($payment, $order, $amount)
    {
        try {
            $api = new \Razorpay\Api\Api($payment->razorpay_key, $payment->razorpay_secret);

            $refund = $api->payment
                ->fetch($order->trans_id)
                ->refund(['amount' => Pricing::toMinorUnits($amount)]);

            return self::pass($refund->id);

        } catch (\Throwable $e) {
            Log::error('Razorpay refund error', ['message' => $e->getMessage()]);
            return self::fail('razorpay: ' . $e->getMessage());
        }
    }


    private static function refundPaypal($payment, $order, $amount)
    {
        try {
            $live = env('PAYPAL_MODE', 'live') === 'live';
            $base = $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

            $tokenResponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->withBasicAuth($payment->paypal_key, $payment->paypal_secret)
                ->post($base . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            if (!$tokenResponse->successful()) {
                return self::fail('paypal token request failed');
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($tokenResponse->json('access_token'))
                ->post($base . '/v2/payments/captures/' . urlencode($order->trans_id) . '/refund', [
                    'amount' => [
                        'value' => number_format((float)$amount, 2, '.', ''),
                        'currency_code' => strtoupper($order->currency)
                    ]
                ]);

            if (!$response->successful()) {
                return self::fail('paypal refund failed: ' . $response->body());
            }

            $body = $response->json();

            if (($body['status'] ?? null) !== 'COMPLETED') {
                return self::fail('paypal refund status ' . ($body['status'] ?? 'unknown'));
            }

            return self::pass($body['id'] ?? null);

        } catch (\Throwable $e) {
            Log::error('PayPal refund error', ['message' => $e->getMessage()]);
            return self::fail('paypal: ' . $e->getMessage());
        }
    }


    private static function refundSumup($payment, $order, $amount)
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($payment->sumup_api_key)
                ->post('https://api.sumup.com/v0.1/me/refund/' . urlencode($order->trans_id), [
                    'amount' => (float)$amount
                ]);

            if (!$response->successful()) {
                return self::fail('sumup refund failed: ' . $response->body());
            }

            return self::pass($order->trans_id);

        } catch (\Throwable $e) {
            Log::error('SumUp refund error', ['message' => $e->getMessage()]);
            return self::fail('sumup: ' . $e->getMessage());
        }
    }
}
