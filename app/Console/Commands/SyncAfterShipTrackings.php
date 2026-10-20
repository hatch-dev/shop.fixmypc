<?php

namespace App\Console\Commands;

use App\Models\Helper\ShipmentTracking;
use App\Services\AfterShipException;
use App\Services\AfterShipService;
use Illuminate\Console\Command;

/**
 * Re-read shipments AfterShip has not told us about lately.
 *
 * The net under the webhook: catches events lost while the site was down,
 * and is the only update path until a webhook is configured. Paced under
 * AfterShip's GET /trackings/:id limit (5 per second).
 */
class SyncAfterShipTrackings extends Command
{
    protected $signature = 'aftership:sync
        {--stale= : Minutes a parcel may go unchecked (default: 720 with a webhook secret, 60 without)}
        {--limit=200 : At most this many per run}';

    protected $description = 'Refresh in-flight shipment statuses from AfterShip';

    public function handle(AfterShipService $api)
    {
        if (!$api->isConfigured()) {
            $this->warn('AFTERSHIP_API_KEY is empty; nothing to do.');
            return 0;
        }

        $due = ShipmentTracking::dueForSync((int)$this->option('stale'), (int)$this->option('limit'));
        $ok = 0;
        $failed = 0;

        foreach ($due as $shipment) {
            try {
                ShipmentTracking::sync($shipment, $api);
                $ok++;
            } catch (AfterShipException $e) {
                $failed++;
                $this->warn("Order {$shipment->order_id}: " . $e->adminMessage());

                // A bad key or a spent daily allowance fails every call the same way: stop.
                if ($e->httpStatus === 401 || $e->isQuotaExhausted()) {
                    break;
                }
            }

            usleep(250000);
        }

        // After the refresh, so a parcel scanned in the last hour is not reported.
        $unscanned = ShipmentTracking::checkUnscanned();

        $this->info("AfterShip sync: {$ok} refreshed, {$failed} failed, " . $due->count() . " due; {$unscanned} no-scan alert(s) sent.");

        return 0;
    }
}
