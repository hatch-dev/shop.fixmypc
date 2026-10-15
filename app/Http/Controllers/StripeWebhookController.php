<?php

namespace App\Http\Controllers;

use App\Models\Helper\StripeBilling;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stripe's webhook: membership subscriptions and their invoices.
 *
 * Point a Stripe webhook endpoint at /api/v1/stripe/webhook, with the events
 * in StripeBilling (customer.subscription.*,
 * invoice.*), and put its signing secret (whsec_...) in Payment Setting.
 *
 * Every event is verified against that secret before anything is read, and
 * the handler re-reads the subscription from Stripe rather than trusting the
 * event body. Anything but a 2xx makes Stripe retry, so a failure here is
 * answered 500 and a bad signature 400.
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = trim((string)optional(Payment::first())->stripe_webhook_secret);

        if ($secret === '') {
            Log::warning('Stripe webhook received but no signing secret is set in Payment Setting');

            return response()->json(['received' => false], 503);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                (string)$request->header('Stripe-Signature'),
                $secret
            );
        } catch (\UnexpectedValueException $e) {
            return response()->json(['received' => false], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature rejected');

            return response()->json(['received' => false], 400);
        }

        try {
            $result = StripeBilling::handleEvent($event);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook failed', ['event' => $event->id, 'type' => $event->type, 'message' => $e->getMessage()]);

            return response()->json(['received' => false], 500);
        }

        return response()->json(['received' => true, 'result' => $result]);
    }
}
