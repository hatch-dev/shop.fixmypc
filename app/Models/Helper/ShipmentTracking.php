<?php

namespace App\Models\Helper;

use App\Http\Controllers\OrdersController;
use App\Models\Order;
use App\Models\OrderEmailLog;
use App\Models\OrderShipment;
use App\Services\AfterShipException;
use App\Services\AfterShipService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Shipment tracking, end to end: the admin records a courier and tracking
 * number, AfterShip is told, the customer is emailed a link, and AfterShip's
 * updates (webhook, sync command, admin refresh) land on the order.
 *
 * Duplicates are stopped at three levels:
 *  - the same courier + number saved again makes no API call and no email;
 *  - AfterShip itself refuses a second tracking (4003) and we adopt its id;
 *  - every customer email goes through OrderEmailDispatcher, whose unique
 *    (order_id, event) row means each event is sent once whatever retries.
 */
class ShipmentTracking
{
    /**
     * The extra fields a courier needs to track, as AfterShip lists them in
     * the courier's required_fields. Address-type fields are taken from the
     * order and the shop settings; account number and key come from the
     * admin ($manual).
     *
     * Always sent, town level only: the delivery town, county and country,
     * and the shop's own town, county and country from Settings. AfterShip
     * uses them for its destination/origin columns, analytics and delivery
     * estimates. Never sent unless a courier requires it: the Eircode /
     * postcode (in Ireland it identifies one property). Never sent at all:
     * name, email, phone, street (those would also make AfterShip email the
     * customer itself).
     *
     * @return array ['payload' => [field => value], 'missing' => [field => label]]
     */
    public static function trackingFields(Order $order, array $courier, array $manual = [])
    {
        $address = $order->address;
        $country = $address ? $address->country : null;
        $setting = \App\Models\Setting::first();

        $known = [
            'destination_country_region' => CountryCodes::alpha3($country),
            'destination_postal_code' => $address && trim((string)$address->zip) !== ''
                ? mb_substr(trim((string)$address->zip), 0, 10) : null,
            'destination_state' => $address ? CountryCodes::stateName($country, $address->state) : null,
            'origin_country_region' => $setting ? CountryCodes::alpha3($setting->country) : null,
            'tracking_ship_date' => Carbon::now()->format('Y-m-d'),
        ];

        $text = function ($value, $len = 100) {
            $value = trim(preg_replace('/\s+/', ' ', (string)$value));
            return $value === '' ? null : mb_substr($value, 0, $len);
        };

        $payload = array_filter([
            'destination_country_region' => $known['destination_country_region'],
            'destination_state' => $text($known['destination_state']),
            'destination_city' => $address ? $text($address->city) : null,
            'origin_country_region' => $known['origin_country_region'],
            'origin_state' => $setting ? $text($setting->state) : null,
            'origin_city' => $setting ? $text($setting->city) : null,
        ]);
        $missing = [];
        $plan = CourierDirectory::fieldPlan($courier);

        foreach ($plan['auto'] as $field => $label) {
            if (!empty($known[$field])) {
                $payload[$field] = $known[$field];
            } else {
                $missing[$field] = $label;
            }
        }

        foreach ($plan['manual'] as $field => $label) {
            $value = trim((string)($manual[$field] ?? ''));
            if ($value !== '') {
                $payload[$field] = mb_substr($value, 0, 512);
            } else {
                $missing[$field] = $label;
            }
        }

        return ['payload' => $payload, 'missing' => $missing];
    }

    /** Statuses at which an order can be given (or corrected to) a shipment. */
    public static function shippableStatuses()
    {
        $s = Config::get('constants.orderStatus');

        return [$s['CONFIRMED'], $s['PICKED_UP'], $s['ON_THE_WAY']];
    }

    /** Order statuses after which nothing about the parcel concerns the customer or staff. */
    public static function closedStatuses()
    {
        $s = Config::get('constants.orderStatus');

        return array_map('intval', [$s['DELIVERED'], $s['CANCELLED'], $s['RETURNED'], $s['REFUNDED']]);
    }

    public static function isCollection(Order $order)
    {
        $line = $order->ordered_products()->orderBy('id')->first();

        return $line && (int)$line->shipping_type === 2;
    }

    /** As printed on a label, without the spaces some couriers group digits with. */
    public static function normaliseNumber($number)
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string)$number)));
    }

    /**
     * One shipment save per order at a time. A double click used to send two
     * creates to AfterShip and answer the second with a database error.
     *
     * A MySQL named lock, not the Laravel cache: the admin's ClearCacheAfterWrite
     * middleware empties the cache after every admin save, lock files included.
     * Held by this request's connection, so a crashed request frees it.
     *
     * @return bool False when another request is saving this order's shipment.
     */
    public static function lockOrder($orderId)
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        $row = DB::selectOne('SELECT GET_LOCK(?, 0) AS got', ['aftership-order-' . (int)$orderId]);

        return (int)($row->got ?? 0) === 1;
    }

    public static function unlockOrder($orderId)
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', ['aftership-order-' . (int)$orderId]);
        }
    }

    /**
     * Record a shipment against an order and register it with AfterShip.
     *
     * Throws AfterShipException when AfterShip refuses or cannot be reached;
     * nothing is saved in that case, so the admin's retry starts clean.
     * Callers hold lockOrder() around this.
     *
     * @return array ['shipment' => OrderShipment, 'unchanged' => bool, 'corrected' => bool]
     *               'corrected': the order already had a shipment, so the customer
     *               already holds the tracking link, which now shows the new details.
     */
    public static function save(Order $order, $slug, $number, $adminId = null, AfterShipService $api = null, array $fields = [], $courierName = null)
    {
        $api = $api ?: app(AfterShipService::class);
        $number = static::normaliseNumber($number);
        $existing = OrderShipment::where('order_id', $order->id)->first();

        // The same details again: a double click, a retry after a timeout.
        if ($existing && $existing->aftership_id
            && $existing->courier_slug === $slug && $existing->tracking_number === $number) {
            return ['shipment' => $existing, 'unchanged' => true, 'corrected' => false];
        }

        /*
         * What AfterShip is given. No customer name, email or phone: those
         * would make AfterShip send its own notifications alongside ours, and
         * it has no need of them to track a parcel.
         */
        $remote = $api->createTracking(array_filter([
            'tracking_number' => $number,
            'slug' => $slug,
            'title' => (string)$order->order,
            'order_number' => (string)$order->order,
            'order_id' => (string)$order->id,
            'order_date' => $order->created_at ? Carbon::parse($order->created_at)->toIso8601String() : null,
        ]) + $fields);

        if (empty($remote['id'])) {
            throw new AfterShipException('AfterShip accepted the shipment but returned no tracking id.', 502, 502, 'BadResponse');
        }

        $shipment = $existing ?: new OrderShipment(['order_id' => $order->id]);
        $replacedId = $existing ? $existing->aftership_id : null;

        $shipment->fill([
            'courier_slug' => $slug,
            'courier_name' => $courierName ?: $slug,
            'tracking_number' => $number,
            'aftership_id' => (string)$remote['id'],
            'created_by' => $adminId,
            'last_error' => null,
            // The 48-hour no-scan check counts from here; alerts start over.
            'tracking_set_at' => Carbon::now(),
            'staff_alerts' => null,
            // A corrected number starts its history again.
            'tag' => 'Pending',
            'subtag' => null,
            'subtag_message' => null,
            'expected_delivery' => null,
            'aftership_tracking_url' => null,
            'courier_tracking_link' => null,
            'checkpoints' => [],
            'remote_updated_at' => null,
        ]);

        static::fillFromRemote($shipment, $remote);

        try {
            $shipment->save();
        } catch (\Illuminate\Database\QueryException $e) {
            // A save that slipped past the lock: the row is there, so this one is a repeat.
            if ((string)$e->getCode() === '23000' && ($row = OrderShipment::where('order_id', $order->id)->first())) {
                return ['shipment' => $row, 'unchanged' => true, 'corrected' => false];
            }
            throw $e;
        }

        // A corrected number: the old tracking is dead weight in AfterShip.
        if ($replacedId && $replacedId !== $shipment->aftership_id) {
            static::forget($replacedId, $api);
        }

        return ['shipment' => $shipment, 'unchanged' => false, 'corrected' => (bool)$existing];
    }

    /**
     * What the customer is told after a shipment was saved, decided by where
     * the ORDER stands rather than by whether the row is new. So a save that
     * stored the row and then failed to move the order is finished by the
     * admin's retry, instead of being answered "unchanged" for ever.
     *
     * @param  bool $firstShipment The order had no shipment before this save.
     * @return string 'shipped' | 'tracking_email' | 'shipped_email_pending' | 'unchanged' | 'corrected' | 'order_closed'
     */
    public static function settle(Order $order, OrderShipment $shipment, $firstShipment, $adminId = null, $lang = null)
    {
        $s = Config::get('constants.orderStatus');
        $status = (int)$order->status;

        // Cancelled (or otherwise closed) while AfterShip was being called.
        if (!in_array($status, static::shippableStatuses(), true)) {
            if ($status === (int)$s['CANCELLED']) {
                static::onOrderCancelled($order);
            }

            return 'order_closed';
        }

        // Not yet on its way: this save (or a retry of a half-finished one) ships it.
        if (in_array($status, [(int)$s['CONFIRMED'], (int)$s['PICKED_UP']], true)) {
            return static::announce($order, $adminId, $lang);
        }

        // Already on its way.
        if (!$firstShipment) {
            return 'corrected_or_unchanged';
        }

        return static::announce($order, $adminId, $lang);
    }

    /**
     * Tell the customer, once, after a shipment was saved.
     *
     * Confirmed / picked up -> On the way: the existing status change, whose
     * "on the way" email now carries the courier, number and tracking link.
     * Already on the way -> a one-off "tracking added" email, unless the "on
     * the way" email is still queued, in which case it will carry the
     * tracking itself when it is rendered. Any other status sends nothing.
     *
     * @return string 'shipped' | 'tracking_email' | 'shipped_email_pending' | 'order_closed'
     */
    public static function announce(Order $order, $adminId = null, $lang = null)
    {
        $s = Config::get('constants.orderStatus');
        $status = (int)$order->status;

        if (in_array($status, [(int)$s['CONFIRMED'], (int)$s['PICKED_UP']], true)
            && Order::canTransition($status, $s['ON_THE_WAY'])) {

            app(OrdersController::class)->applyStatusChange(
                $order, $s['ON_THE_WAY'], $adminId, 'admin', 'Shipment added', $lang);

            return 'shipped';
        }

        // Only an order actually on its way is told its tracking.
        if ($status !== (int)$s['ON_THE_WAY']) {
            return 'order_closed';
        }

        $shippedQueued = OrderEmailLog::where('order_id', $order->id)
            ->where('event', OrderEmailEvent::ORDER_SHIPPED)
            ->where('status', OrderEmailLog::STATUS_QUEUED)
            ->exists();

        if ($shippedQueued) {
            return 'shipped_email_pending';
        }

        OrderEmailDispatcher::dispatch($order, OrderEmailEvent::TRACKING_ADDED, $lang,
            ['status' => (int)$s['ON_THE_WAY']]);

        return 'tracking_email';
    }

    /**
     * Copy AfterShip's view of a tracking onto the row (not saved).
     *
     * @return bool False when the update is older than what is stored: a
     *              webhook retried after a newer one landed must not undo it.
     */
    public static function fillFromRemote(OrderShipment $shipment, array $t)
    {
        $remoteAt = static::remoteTime($t);

        if ($remoteAt && $shipment->remote_updated_at && $remoteAt->lt($shipment->remote_updated_at)) {
            return false;
        }

        $tag = (string)($t['tag'] ?? '');
        $newTag = array_key_exists($tag, OrderShipment::TAG_LABELS) ? $tag : ($shipment->tag ?: 'Pending');
        $tagChanged = $newTag !== $shipment->tag;

        $expected = $t['latest_estimated_delivery']['datetime']
            ?? $t['courier_estimated_delivery_date']['estimated_delivery_date']
            ?? $t['aftership_estimated_delivery_date']['estimated_delivery_date']
            ?? null;

        // A new status without its own detail must not keep the old status's detail.
        $keep = function ($key, $len) use ($t, $shipment, $tagChanged) {
            if (isset($t[$key]) && is_scalar($t[$key])) {
                return mb_substr((string)$t[$key], 0, $len);
            }
            return $tagChanged ? null : $shipment->{$key};
        };

        $shipment->fill([
            'tag' => $newTag,
            'subtag' => $keep('subtag', 32),
            'subtag_message' => $keep('subtag_message', 191),
            'expected_delivery' => is_scalar($expected) && $expected ? mb_substr((string)$expected, 0, 40) : $shipment->expected_delivery,
            'aftership_tracking_url' => static::url($t['aftership_tracking_url'] ?? null) ?: $shipment->aftership_tracking_url,
            'courier_tracking_link' => static::url($t['courier_tracking_link'] ?? null) ?: $shipment->courier_tracking_link,
            'remote_updated_at' => $remoteAt ?: $shipment->remote_updated_at,
            'last_synced_at' => Carbon::now(),
            'last_error' => null,
        ]);

        if (isset($t['checkpoints']) && is_array($t['checkpoints'])) {
            $shipment->checkpoints = OrderShipment::publicCheckpoints($t['checkpoints']);
        }

        return true;
    }

    /**
     * When AfterShip last changed the tracking: its updated_at, or failing
     * that the newest checkpoint, so an update without updated_at can still
     * be told apart from an older one.
     */
    private static function remoteTime(array $t)
    {
        $candidates = [];

        if (!empty($t['updated_at']) && is_string($t['updated_at'])) {
            $candidates[] = $t['updated_at'];
        } elseif (isset($t['checkpoints']) && is_array($t['checkpoints'])) {
            foreach ($t['checkpoints'] as $c) {
                if (is_array($c) && !empty($c['checkpoint_time']) && is_string($c['checkpoint_time'])) {
                    $candidates[] = $c['checkpoint_time'];
                }
            }
        }

        $latest = null;
        foreach ($candidates as $value) {
            try {
                $at = Carbon::parse($value);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$latest || $at->gt($latest)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /** https links only: this is a third party's value going into an href. */
    private static function url($value)
    {
        $value = is_scalar($value) ? trim((string)$value) : '';

        return $value !== '' && mb_strlen($value) <= 500 && preg_match('#^https://[^\s<>"]+$#i', $value)
            ? $value : null;
    }

    /**
     * Apply an AfterShip tracking object to every shipment it belongs to.
     * Used by the webhook, the sync command and the admin refresh.
     *
     * Each row is re-read under a lock, so a webhook and the sync arriving
     * together cannot let the older of the two overwrite the newer. What
     * follows (customer emails, staff alerts, delivering the order) is worked
     * out from the stored state on every update, not only when the status
     * changed: each of them happens at most once anyway, and a webhook that
     * failed half-way is put right by AfterShip's retry.
     *
     * @return int Shipments updated.
     */
    public static function applyRemote(array $t)
    {
        $id = is_scalar($t['id'] ?? null) ? (string)$t['id'] : '';

        $ids = $id !== ''
            ? OrderShipment::where('aftership_id', $id)->pluck('id')
            : collect();

        if ($ids->isEmpty() && !empty($t['slug']) && !empty($t['tracking_number'])
            && is_scalar($t['slug']) && is_scalar($t['tracking_number'])) {
            $ids = OrderShipment::where('courier_slug', (string)$t['slug'])
                ->where('tracking_number', static::normaliseNumber($t['tracking_number']))
                ->whereNotNull('aftership_id')
                ->pluck('id');
        }

        $updated = 0;

        foreach ($ids as $rowId) {
            $shipment = DB::transaction(function () use ($rowId, $t) {
                $row = OrderShipment::whereKey($rowId)->lockForUpdate()->first();

                if (!$row || !static::fillFromRemote($row, $t)) {
                    return null;
                }

                $row->save();

                return $row;
            });

            if (!$shipment) {
                continue;
            }

            $updated++;
            static::react($shipment);
        }

        return $updated;
    }

    /**
     * Everything that follows from where the parcel is now. Safe to repeat:
     * each email and alert is sent once, and the order moves only once.
     */
    public static function react(OrderShipment $shipment)
    {
        static::notifyCustomer($shipment);
        static::alertStaff($shipment, $shipment->attention());
        static::advanceOrder($shipment);
    }

    /**
     * The courier scans a customer acts on: out for delivery (be in), ready
     * for pickup (go and collect) and a failed attempt (rearrange). Once per
     * order each, through the dispatcher, and only while the order is on its
     * way: a cancelled order's parcel heading back to the shop must not tell
     * the customer to expect it.
     */
    public static function notifyCustomer(OrderShipment $shipment)
    {
        $events = [
            'OutForDelivery' => OrderEmailEvent::OUT_FOR_DELIVERY,
            'AvailableForPickup' => OrderEmailEvent::READY_FOR_PICKUP,
            'AttemptFail' => OrderEmailEvent::DELIVERY_ATTEMPT_FAILED,
        ];

        if (!isset($events[$shipment->tag])) {
            return false;
        }

        $order = Order::find($shipment->order_id);
        $onTheWay = (int)Config::get('constants.orderStatus.ON_THE_WAY');

        if (!$order || (int)$order->status !== $onTheWay) {
            return false;
        }

        return OrderEmailDispatcher::dispatch($order, $events[$shipment->tag], null, ['status' => $onTheWay]);
    }

    /**
     * Email the shop about a parcel that needs a person, once per reason per
     * tracking number (a corrected number starts over). Never throws: it runs
     * inside webhooks and the sync job, and a mail problem must not stop the
     * status being recorded.
     *
     * The reason is claimed with a compare-and-swap on staff_alerts, so two
     * overlapping runs cannot both send. If the email then fails, the claim is
     * released and the next run tries again.
     *
     * @param  string|null $reason A key of OrderShipment::ATTENTION.
     */
    public static function alertStaff(OrderShipment $shipment, $reason)
    {
        if (!$reason || !isset(OrderShipment::ATTENTION[$reason])) {
            return false;
        }

        // Every decision about a real problem is logged, so a missing alert can be explained.
        $log = function ($outcome) use ($shipment, $reason) {
            \Illuminate\Support\Facades\Log::info('Shipment staff alert ' . $outcome, [
                'order_id' => $shipment->order_id, 'reason' => $reason, 'tag' => $shipment->tag,
            ]);
        };

        $order = Order::find($shipment->order_id);

        // Nobody needs chasing about an order that is already closed, delivered included.
        if (!$order || in_array((int)$order->status, static::closedStatuses(), true)) {
            if (!$shipment->wasAlerted($reason)) {
                $log('skipped: order missing or closed (status ' . optional($order)->status . ')');
            }
            return false;
        }

        if (!static::claimAlert($shipment, $reason)) {
            return false;   // already sent for this tracking number, or another run is sending it
        }

        try {
            $sent = OrderMailer::staffShipmentAlert($order, $shipment, $reason);
            $log($sent ? 'sent' : 'skipped: no valid shop support address in Settings');

            return $sent;

        } catch (\Throwable $e) {
            static::releaseAlert($shipment, $reason);

            \Illuminate\Support\Facades\Log::warning('Shipment staff alert could not be sent; will retry', [
                'order_id' => $order->id, 'reason' => $reason, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Add $reason to staff_alerts only if the column still holds what we read. */
    private static function claimAlert(OrderShipment $shipment, $reason)
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = DB::table('order_shipments')->where('id', $shipment->id)->value('staff_alerts');
            $alerts = $raw ? (array)json_decode($raw, true) : [];

            if (in_array($reason, $alerts, true)) {
                $shipment->staff_alerts = $alerts;
                return false;
            }

            $next = array_values(array_merge($alerts, [$reason]));
            $query = DB::table('order_shipments')->where('id', $shipment->id);
            $query = is_null($raw) ? $query->whereNull('staff_alerts') : $query->where('staff_alerts', $raw);

            if ($query->update(['staff_alerts' => json_encode($next), 'updated_at' => Carbon::now()]) === 1) {
                $shipment->staff_alerts = $next;
                return true;
            }
        }

        return false;
    }

    private static function releaseAlert(OrderShipment $shipment, $reason)
    {
        $raw = DB::table('order_shipments')->where('id', $shipment->id)->value('staff_alerts');
        $alerts = array_values(array_diff($raw ? (array)json_decode($raw, true) : [], [$reason]));

        DB::table('order_shipments')->where('id', $shipment->id)
            ->update(['staff_alerts' => $alerts ? json_encode($alerts) : null, 'updated_at' => Carbon::now()]);

        $shipment->staff_alerts = $alerts ?: null;
    }

    /**
     * Parcels still without a courier scan NO_SCAN_HOURS after the number
     * went in, on orders still open. Run by aftership:sync every hour.
     *
     * @return int Alerts sent.
     */
    public static function checkUnscanned()
    {
        $cutoff = Carbon::now()->subHours(OrderShipment::NO_SCAN_HOURS);
        $sent = 0;

        $rows = OrderShipment::where('tag', 'Pending')
            ->whereNotNull('aftership_id')
            ->where('tracking_set_at', '<', $cutoff)
            ->whereHas('order', function ($q) {
                $q->whereNotIn('status', static::closedStatuses());
            })
            ->limit(500)
            ->get();

        foreach ($rows as $shipment) {
            if ($shipment->attention() === 'no_scan' && static::alertStaff($shipment, 'no_scan')) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Remove a tracking from AfterShip that no order uses any more, so it is
     * not polled and sends no webhooks. Best effort: a failure is logged and
     * never stops the admin's save or the cancellation.
     */
    public static function forget($aftershipId, AfterShipService $api = null)
    {
        if (!$aftershipId || OrderShipment::where('aftership_id', $aftershipId)->exists()) {
            return false;   // another order still points at it (one parcel, two orders)
        }

        try {
            return ($api ?: app(AfterShipService::class))->deleteTracking($aftershipId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AfterShip tracking could not be removed', [
                'aftership_id' => $aftershipId,
                'error' => $e instanceof AfterShipException ? $e->adminMessage() : get_class($e),
            ]);

            return false;
        }
    }

    /**
     * An order was cancelled. If its parcel never got a courier scan (no
     * status yet, or only a label created), it never left: the tracking is
     * removed from AfterShip and the row says so. A parcel the courier
     * already has keeps its tracking, because it is now on its way back and
     * someone will want to follow it.
     */
    public static function onOrderCancelled(Order $order)
    {
        $shipment = OrderShipment::where('order_id', $order->id)->first();

        if (!$shipment || !$shipment->aftership_id || !in_array($shipment->tag, ['Pending', 'InfoReceived'], true)) {
            return false;
        }

        $id = $shipment->aftership_id;

        DB::table('order_shipments')->where('id', $shipment->id)->update([
            'aftership_id' => null,
            'last_error' => 'Order cancelled before the courier scanned the parcel; tracking removed from AfterShip.',
            'updated_at' => Carbon::now(),
        ]);

        return static::forget($id);
    }

    /**
     * A courier's Delivered scan moves the order to Delivered, through the
     * same transition the admin dropdown uses. Only from Picked up / On the
     * way (canTransition), so a cancelled or refunded order is never revived
     * and a repeated webhook finds the order already delivered and stops.
     */
    public static function advanceOrder(OrderShipment $shipment)
    {
        if ($shipment->tag !== 'Delivered' || !config('aftership.auto_deliver')) {
            return false;
        }

        $order = Order::find($shipment->order_id);
        $delivered = (int)Config::get('constants.orderStatus.DELIVERED');

        if (!$order || !Order::canTransition((int)$order->status, $delivered)) {
            return false;
        }

        app(OrdersController::class)->applyStatusChange(
            $order, $delivered, null, 'aftership', 'Courier reported delivered (AfterShip)');

        return true;
    }

    /**
     * Re-read one shipment from AfterShip.
     *
     * A failure still counts as a check (last_synced_at), so the sync backs
     * off instead of retrying the same broken row every hour. A tracking
     * AfterShip no longer has (deleted or archived there) is let go: the row
     * says so, and saving the shipment again registers it afresh.
     */
    public static function sync(OrderShipment $shipment, AfterShipService $api = null)
    {
        $api = $api ?: app(AfterShipService::class);

        try {
            $remote = $api->getTracking($shipment->aftership_id);
        } catch (AfterShipException $e) {
            $gone = $e->httpStatus === 404 || $e->metaCode === 4004;

            DB::table('order_shipments')->where('id', $shipment->id)->update(array_filter([
                'last_error' => $gone
                    ? 'AfterShip no longer has this tracking. Save the shipment again to re-register it.'
                    : mb_substr($e->adminMessage(), 0, 500),
                'last_synced_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]) + ($gone ? ['aftership_id' => null] : []));

            throw $e;
        }

        return static::applyRemote($remote);
    }

    /**
     * The link the customer is emailed: our own tracking page, carrying a
     * signature that unlocks the tracking number and checkpoints for this
     * order only. Keyed by AFTERSHIP_LINK_SECRET when set, else APP_KEY (the
     * key every link already sent was signed with). It does not expire:
     * customers come back to a shipping email days later.
     */
    public static function trackUrl(Order $order)
    {
        $base = rtrim((string)(config('env.url.CLIENT_BASE_URL') ?: config('app.url')), '/');

        return $base . '/track-order?' . http_build_query([
            'order_id' => $order->order,
            't' => static::signature($order),
        ]);
    }

    public static function signature(Order $order)
    {
        $key = (string)(config('aftership.link_secret') ?: config('app.key'));

        return substr(hash_hmac('sha256', 'track|' . $order->id . '|' . $order->order, $key), 0, 32);
    }

    public static function verifySignature(Order $order, $signature)
    {
        $signature = is_scalar($signature) ? (string)$signature : '';

        return $signature !== '' && hash_equals(static::signature($order), $signature);
    }

    /**
     * Verify an AfterShip webhook: base64 HMAC-SHA256 of the raw body with
     * the account's webhook secret, in the aftership-hmac-sha256 header.
     */
    public static function verifyWebhook($rawBody, $header, $secret)
    {
        $secret = (string)$secret;
        $header = trim((string)$header);

        if ($secret === '' || $header === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', (string)$rawBody, $secret, true));

        return hash_equals($expected, $header);
    }

    /**
     * How long a parcel may go unchecked before the sync asks AfterShip.
     * With a webhook configured AfterShip pushes every change, so the sync is
     * only a safety net (12h); without one it is the only update path (1h).
     * AFTERSHIP_SYNC_MINUTES overrides both. Every check is an API call, and
     * the free plan allows 100 a day.
     */
    public static function syncMinutes()
    {
        $set = (int)config('aftership.sync_minutes');

        if ($set > 0) {
            return $set;
        }

        return trim((string)config('aftership.webhook_secret')) !== '' ? 720 : 60;
    }

    /**
     * Shipments worth asking AfterShip about, oldest check first.
     *
     * Skipped: parcels of orders that are delivered, returned or refunded
     * (nothing left to act on), and finished tags. A parcel that has sat
     * without a single courier scan for 3 days is checked once a day, not
     * every hour: it is almost always a wrong number, and staff have already
     * been alerted.
     */
    public static function dueForSync($staleMinutes = null, $limit = 200)
    {
        $minutes = $staleMinutes ?: static::syncMinutes();
        $before = Carbon::now()->subMinutes($minutes);
        $quietBefore = Carbon::now()->subDay();
        $quietSince = Carbon::now()->subDays(3);

        $s = Config::get('constants.orderStatus');
        $finished = array_map('intval', [$s['DELIVERED'], $s['RETURNED'], $s['REFUNDED']]);

        return OrderShipment::whereNotNull('aftership_id')
            ->whereNotIn('tag', OrderShipment::FINAL_TAGS)
            ->whereHas('order', function ($q) use ($finished) {
                $q->whereNotIn('status', $finished);
            })
            ->where(function ($q) use ($before) {
                $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $before);
            })
            ->where(function ($q) use ($quietBefore, $quietSince) {
                // Long-silent parcels: at most once a day.
                $q->where('tag', '!=', 'Pending')
                    ->orWhereNull('tracking_set_at')
                    ->orWhere('tracking_set_at', '>=', $quietSince)
                    ->orWhereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<', $quietBefore);
            })
            ->orderByRaw('last_synced_at IS NOT NULL, last_synced_at')
            ->limit($limit)
            ->get();
    }
}
