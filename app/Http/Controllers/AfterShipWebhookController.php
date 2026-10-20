<?php

namespace App\Http\Controllers;

use App\Models\Helper\ShipmentTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AfterShip's tracking webhook.
 *
 * Point an AfterShip webhook (Settings > Webhooks, latest version, event
 * "tracking_update") at /api/v1/aftership/webhook and put its secret in
 * AFTERSHIP_WEBHOOK_SECRET.
 *
 * The signature is checked before the body is even parsed. The body is then
 * used only to find which of OUR shipments it concerns and what the courier
 * says; it cannot name an order, a status or an email of ours. A replayed or
 * out-of-order event is harmless: ShipmentTracking ignores anything older than
 * what is stored, and an order already delivered cannot be delivered again.
 *
 * AfterShip retries any non-2xx up to 14 times, so: 401 for a bad signature
 * (its retries will fail the same way, which is the point), 503 while no
 * secret is configured, 500 for our own failure so the event is redelivered,
 * and 200 for everything handled -- including events about trackings we do
 * not hold, which retrying would never fix.
 */
class AfterShipWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = (string)config('aftership.webhook_secret');

        if (trim($secret) === '') {
            Log::warning('AfterShip webhook received but AFTERSHIP_WEBHOOK_SECRET is not set');

            return response()->json(['received' => false], 503);
        }

        $raw = $request->getContent();

        if (!ShipmentTracking::verifyWebhook($raw, $request->header('aftership-hmac-sha256'), $secret)) {
            Log::warning('AfterShip webhook signature rejected', ['ip' => $request->ip()]);

            return response()->json(['received' => false], 401);
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload) || !isset($payload['msg']) || !is_array($payload['msg'])) {
            return response()->json(['received' => true, 'updated' => 0]);
        }

        // edd_revise and tracking_pending_time carry the same tracking object.
        try {
            $updated = ShipmentTracking::applyRemote($payload['msg']);
        } catch (\Throwable $e) {
            Log::error('AfterShip webhook failed', [
                'event' => $payload['event'] ?? null,
                'event_id' => $payload['event_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['received' => false], 500);
        }

        return response()->json(['received' => true, 'updated' => $updated]);
    }
}
