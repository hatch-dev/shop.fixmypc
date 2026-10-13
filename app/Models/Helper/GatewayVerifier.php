<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server-side confirmation that a payment actually happened, for the gateways
 * that had none.
 *
 * QA audit PAY-01 and PAY-02. PayPal and SumUp were confirmed with a bare
 * "$paymentDone = true", and Razorpay by echoing back the order id the browser
 * already held. Each method here asks the gateway directly, over a server-to-
 * server call the customer cannot influence, and checks that the amount and
 * currency match the order.
 *
 * Every method returns ['ok' => bool, 'reason' => string, 'reference' => string]
 * so the caller can log why a verification failed without leaking it to the
 * customer.
 */
class GatewayVerifier
{
    /** How long to wait on a gateway before giving up. */
    const TIMEOUT_SECONDS = 20;

    /** Amounts are equal if they are within half a cent. */
    const EPSILON = 0.005;

    /** How many times to read a SumUp checkout back while it says PENDING. */
    const SUMUP_MAX_LOOKUPS = 3;

    /** How long to wait between those reads. */
    const SUMUP_RETRY_SECONDS = 2;


    /*
     * $charged says whether the gateway confirms it took the money. A refusal
     * with charged = false is a payment that did not happen and the order can
     * be dropped; charged = true is real money against an order that does not
     * match it, which has to be kept and looked at by a person.
     */
    private static function fail($reason, $charged = false)
    {
        return ['ok' => false, 'reason' => $reason, 'reference' => null, 'charged' => $charged];
    }

    private static function pass($reference)
    {
        return ['ok' => true, 'reason' => null, 'reference' => $reference, 'charged' => true];
    }


    public static function amountsMatch($charged, $expected)
    {
        if (is_null($charged)) {
            return false;
        }

        return abs((float)$charged - (float)$expected) < self::EPSILON;
    }


    private static function currenciesMatch($charged, $expected)
    {
        if (is_null($charged) || $charged === '') {
            // Not every gateway echoes the currency back; the amount check stands.
            return true;
        }

        return strtoupper((string)$charged) === strtoupper((string)$expected);
    }


    /**
     * PayPal Orders v2: fetch the order and require a completed capture whose
     * amount matches.
     */
    public static function verifyPaypal($payment, $paypalOrderId, $expectedAmount, $currency)
    {
        if (empty($paypalOrderId)) {
            return self::fail('no paypal order id supplied');
        }

        if (empty($payment->paypal_key) || empty($payment->paypal_secret)) {
            return self::fail('paypal credentials not configured');
        }

        $base = self::paypalBaseUrl();

        try {
            $tokenResponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->withBasicAuth($payment->paypal_key, $payment->paypal_secret)
                ->post($base . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            if (!$tokenResponse->successful()) {
                return self::fail('paypal token request failed');
            }

            $accessToken = $tokenResponse->json('access_token');

            $orderResponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($accessToken)
                ->get($base . '/v2/checkout/orders/' . urlencode($paypalOrderId));

            if (!$orderResponse->successful()) {
                return self::fail('paypal order lookup failed');
            }

            $body = $orderResponse->json();

            if (($body['status'] ?? null) !== 'COMPLETED') {
                return self::fail('paypal order status ' . ($body['status'] ?? 'unknown'));
            }

            $capture = $body['purchase_units'][0]['payments']['captures'][0] ?? null;

            if (!$capture || ($capture['status'] ?? null) !== 'COMPLETED') {
                return self::fail('paypal capture not completed');
            }

            $paidAmount = $capture['amount']['value'] ?? null;
            $paidCurrency = $capture['amount']['currency_code'] ?? null;

            if (!self::amountsMatch($paidAmount, $expectedAmount)) {
                return self::fail("paypal amount {$paidAmount} != {$expectedAmount}");
            }

            if (!self::currenciesMatch($paidCurrency, $currency)) {
                return self::fail("paypal currency {$paidCurrency} != {$currency}");
            }

            return self::pass($capture['id'] ?? $paypalOrderId);

        } catch (\Throwable $e) {
            Log::error('PayPal verification error', ['message' => $e->getMessage()]);
            return self::fail('paypal request threw: ' . $e->getMessage());
        }
    }


    private static function paypalBaseUrl()
    {
        $live = env('PAYPAL_MODE', 'live') === 'live';

        return $live
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }


    /**
     * Razorpay: verify the HMAC over "<order_id>|<payment_id>" with the key
     * secret, then re-fetch the payment and check it was captured for the right
     * amount. The signature alone proves the callback came from Razorpay; the
     * fetch proves the money moved.
     */
    public static function verifyRazorpay($payment, $razorpayOrderId, $razorpayPaymentId, $signature, $expectedAmount, $currency)
    {
        if (empty($razorpayPaymentId) || empty($signature)) {
            return self::fail('missing razorpay payment id or signature');
        }

        if (empty($payment->razorpay_key) || empty($payment->razorpay_secret)) {
            return self::fail('razorpay credentials not configured');
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $razorpayOrderId . '|' . $razorpayPaymentId,
            $payment->razorpay_secret
        );

        if (!hash_equals($expectedSignature, (string)$signature)) {
            return self::fail('razorpay signature mismatch');
        }

        try {
            $api = new \Razorpay\Api\Api($payment->razorpay_key, $payment->razorpay_secret);
            $fetched = $api->payment->fetch($razorpayPaymentId);

            if (!in_array($fetched->status, ['captured', 'authorized'], true)) {
                return self::fail('razorpay payment status ' . $fetched->status);
            }

            // Razorpay reports in the minor unit.
            $paidAmount = ((float)$fetched->amount) / 100;

            if (!self::amountsMatch($paidAmount, $expectedAmount)) {
                return self::fail("razorpay amount {$paidAmount} != {$expectedAmount}");
            }

            if (!self::currenciesMatch($fetched->currency, $currency)) {
                return self::fail("razorpay currency {$fetched->currency} != {$currency}");
            }

            return self::pass($razorpayPaymentId);

        } catch (\Throwable $e) {
            Log::error('Razorpay verification error', ['message' => $e->getMessage()]);
            return self::fail('razorpay request threw: ' . $e->getMessage());
        }
    }


    /**
     * SumUp: read the checkout back and require status PAID for the right
     * amount.
     */
    public static function verifySumup($payment, $checkoutId, $expectedAmount, $currency)
    {
        if (empty($checkoutId)) {
            return self::fail('no sumup checkout id supplied');
        }

        if (empty($payment->sumup_api_key)) {
            return self::fail('sumup api key not configured');
        }

        try {
            /*
             * The widget reports success as soon as the card clears, and the
             * checkout can still read PENDING for a moment after that. Refusing
             * on the first look would leave a genuinely paid order unpaid, so a
             * PENDING answer is given a little time to settle. Any other status
             * is an answer, not a delay, and is taken at once.
             */
            $body = null;
            $attempts = 0;

            do {
                if ($attempts > 0) {
                    sleep(self::SUMUP_RETRY_SECONDS);
                }

                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withToken($payment->sumup_api_key)
                    ->get('https://api.sumup.com/v0.1/checkouts/' . urlencode($checkoutId));

                if (!$response->successful()) {
                    /*
                     * The status separates the two faults that look identical
                     * from the outside: 401 is a key that SumUp will not accept
                     * (wrong key, or a live key against a test checkout), 404 is
                     * a checkout id this account does not own.
                     */
                    return self::fail('sumup checkout lookup failed with HTTP '
                        . $response->status() . ' - ' . Str::limit((string)$response->body(), 200));
                }

                $body = $response->json();
                $status = $body['status'] ?? null;
                $attempts++;

            } while ($status === 'PENDING' && $attempts < self::SUMUP_MAX_LOOKUPS);

            if (($body['status'] ?? null) !== 'PAID') {
                return self::fail('sumup checkout status ' . ($body['status'] ?? 'unknown')
                    . ' after ' . $attempts . ' lookup(s)');
            }

            if (!self::amountsMatch($body['amount'] ?? null, $expectedAmount)) {
                /*
                 * Worth the detail. A customer whose card has been charged is
                 * left holding an unpaid order, and the only way to tell a
                 * genuine tamper from the checkout and the order being priced
                 * on different terms is to see both figures.
                 */
                return self::fail(sprintf(
                    'sumup amount mismatch - checkout %s, order expects %s',
                    var_export($body['amount'] ?? null, true),
                    var_export($expectedAmount, true)
                ), true);
            }

            if (!self::currenciesMatch($body['currency'] ?? null, $currency)) {
                return self::fail('sumup currency mismatch', true);
            }

            // The checkout itself, for callers that need more than yes/no
            // (wallet top-ups check its description and reference).
            return self::pass($body['transaction_code'] ?? $checkoutId) + ['checkout' => $body];

        } catch (\Throwable $e) {
            Log::error('SumUp verification error', ['message' => $e->getMessage()]);
            return self::fail('sumup request threw: ' . $e->getMessage());
        }
    }
}
