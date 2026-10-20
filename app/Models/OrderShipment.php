<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The parcel an order went out in. See the order_shipments migration.
 *
 * Never serialise this whole into a public response: the storefront gets
 * publicView(), which decides what an order number alone may see.
 */
class OrderShipment extends Model
{
    protected $table = 'order_shipments';

    protected $fillable = [
        'order_id', 'courier_slug', 'courier_name', 'tracking_number', 'aftership_id',
        'aftership_tracking_url', 'courier_tracking_link', 'tag', 'subtag', 'subtag_message',
        'expected_delivery', 'checkpoints', 'remote_updated_at', 'last_synced_at',
        'last_error', 'created_by', 'tracking_set_at', 'staff_alerts',
    ];

    protected $casts = [
        'checkpoints' => 'array',
        'staff_alerts' => 'array',
        'remote_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'tracking_set_at' => 'datetime',
    ];

    /** No courier scan this long after the number was entered: probably a typo or never collected. */
    const NO_SCAN_HOURS = 48;

    /**
     * What staff are told for each problem, keyed by the reason stored in
     * staff_alerts. 'no_scan' is ours; the rest are AfterShip tags.
     */
    const ATTENTION = [
        'no_scan' => 'No courier scan ' . self::NO_SCAN_HOURS . ' hours after the tracking number was entered. Check the number against the label, and that the courier collected the parcel.',
        'AttemptFail' => 'The courier tried to deliver and could not. The customer has been emailed; check whether they need help rearranging delivery.',
        'Exception' => 'The courier reports a delivery problem (address issue, held, damaged or returning). Check the courier\'s tracking page and contact the customer.',
        'Expired' => 'AfterShip has had no update from the courier for 30 days and has stopped tracking. Check with the courier directly.',
        'wrong_carrier' => 'AfterShip says this tracking number does not belong to the selected courier, so it will never track. Check the courier and number on the label, then Edit the shipment.',
        'unrecognized_carrier' => 'AfterShip cannot track this courier for this number. Check the courier and number on the label, then Edit the shipment.',
        'carrier_not_connected' => 'This courier needs your courier account connected in AfterShip before it can track (AfterShip > Settings > Courier accounts).',
    ];

    /**
     * AfterShip Pending sub-statuses that mean the shipment will never track
     * as entered (docs: Delivery sub-statuses). Raised at once, not after
     * the 48-hour no-scan wait.
     */
    const PENDING_PROBLEMS = [
        'Pending_004' => 'wrong_carrier',
        'Pending_006' => 'unrecognized_carrier',
        'Pending_002' => 'carrier_not_connected',
    ];

    /** AfterShip's nine delivery statuses, as a customer should read them. */
    const TAG_LABELS = [
        'Pending' => 'Awaiting courier scan',
        'InfoReceived' => 'Label created',
        'InTransit' => 'In transit',
        'OutForDelivery' => 'Out for delivery',
        'AttemptFail' => 'Delivery attempt failed',
        'Delivered' => 'Delivered',
        'AvailableForPickup' => 'Ready for pickup',
        'Exception' => 'Delivery problem',
        'Expired' => 'No recent updates',
    ];

    /** Tags after which AfterShip stops reporting; nothing left to sync. */
    const FINAL_TAGS = ['Delivered', 'Expired'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function tagLabel()
    {
        return self::TAG_LABELS[$this->tag] ?? $this->tag;
    }

    /**
     * AfterShip's finer wording (subtag_message), or null when it only
     * repeats the status: "Awaiting courier scan · Pending" said it twice.
     */
    public function detailText()
    {
        $msg = trim((string)$this->subtag_message);
        $squash = function ($v) { return preg_replace('/[^a-z]/', '', mb_strtolower((string)$v)); };

        if ($msg === '' || in_array($squash($msg), [$squash($this->tag), $squash($this->tagLabel())], true)) {
            return null;
        }

        return $msg;
    }

    public function isFinal()
    {
        return in_array($this->tag, self::FINAL_TAGS, true);
    }

    /**
     * Why this parcel needs a person, or null. Worked out from the row as it
     * stands, so the admin screen shows it even where the hourly job (which
     * sends the staff email) is not running.
     *
     * @return string|null A key of ATTENTION.
     */
    public function attention()
    {
        if (isset(self::ATTENTION[$this->tag]) && $this->tag !== 'no_scan') {
            return $this->tag;
        }

        $since = $this->tracking_set_at ?: $this->created_at;

        // An order delivered by hand, or closed, is not chased for a scan.
        $closed = \App\Models\Helper\ShipmentTracking::closedStatuses();
        if ($this->tag === 'Pending' && in_array((int)optional($this->order)->status, $closed, true)) {
            return null;
        }

        // A wrong or unrecognised courier will never produce a scan: say so now.
        if ($this->tag === 'Pending' && $this->aftership_id && isset(self::PENDING_PROBLEMS[(string)$this->subtag])) {
            return self::PENDING_PROBLEMS[(string)$this->subtag];
        }

        if ($this->tag === 'Pending' && $this->aftership_id && $since
            && $since->lt(\Carbon\Carbon::now()->subHours(self::NO_SCAN_HOURS))) {
            return 'no_scan';
        }

        return null;
    }

    public function wasAlerted($reason)
    {
        return in_array($reason, (array)($this->staff_alerts ?: []), true);
    }

    /**
     * Where the customer is sent to follow the parcel outside our page:
     * AfterShip's branded page when the account has one, else the courier's.
     * AfterShip answers a courier with no public page (its test courier, for
     * one) with its own courier directory, which tracks nothing; that is
     * dropped rather than shown as a tracking link.
     */
    public function externalUrl()
    {
        foreach ([$this->aftership_tracking_url, $this->courier_tracking_link] as $url) {
            if ($url && preg_match('#^https://#i', $url)
                && !preg_match('#^https://www\.aftership\.com/couriers/?$#i', $url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * The admin screen's view: everything, including the last sync error.
     */
    public function adminView()
    {
        return [
            'courier_slug' => $this->courier_slug,
            'courier_name' => $this->courier_name,
            'tracking_number' => $this->tracking_number,
            'aftership_id' => $this->aftership_id,
            'tag' => $this->tag,
            'tag_label' => $this->tagLabel(),
            'subtag_message' => $this->detailText(),
            'expected_delivery' => $this->expected_delivery,
            'external_url' => $this->externalUrl(),
            'checkpoints' => $this->checkpoints ?: [],
            'last_synced_at' => optional($this->last_synced_at)->toIso8601String(),
            'last_error' => $this->last_error,
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'attention' => ($reason = $this->attention())
                ? ['reason' => $reason, 'message' => self::ATTENTION[$reason]]
                : null,
        ];
    }

    /**
     * The storefront's view.
     *
     * Anyone holding an order number gets the courier and where the parcel
     * has got to. The tracking number, the checkpoint trail and the courier
     * link go only to the signed-in owner or to whoever holds the signed link
     * from the shipping email ($full): a tracking number opens the courier's
     * own page, which can show the recipient's name and full address.
     */
    public function publicView($full)
    {
        $view = [
            'courier' => $this->courier_name ?: $this->courier_slug,
            'tag' => $this->tag,
            'status' => $this->tagLabel(),
            'detail' => $this->detailText(),
            'expected_delivery' => $this->expected_delivery,
            'updated_at' => optional($this->remote_updated_at ?: $this->updated_at)->toIso8601String(),
            'full' => (bool)$full,
        ];

        if ($full) {
            $view['tracking_number'] = $this->tracking_number;
            $view['url'] = $this->externalUrl();
            $view['checkpoints'] = $this->checkpoints ?: [];
        } else {
            $view['tracking_number'] = self::mask($this->tracking_number);
        }

        return $view;
    }

    /** Last four characters only: enough to match a label, not to look it up. */
    public static function mask($number)
    {
        $number = (string)$number;

        if (mb_strlen($number) <= 4) {
            return str_repeat('*', mb_strlen($number));
        }

        return str_repeat('*', min(8, mb_strlen($number) - 4)) . mb_substr($number, -4);
    }

    /**
     * Reduce AfterShip checkpoints to what is shown: newest first, the
     * normalised message and a town-level place. AfterShip's `location` and
     * coordinates are dropped -- for the delivery scan they are the
     * customer's street address.
     */
    public static function publicCheckpoints($checkpoints)
    {
        return collect(is_array($checkpoints) ? $checkpoints : [])
            ->filter(function ($c) { return is_array($c); })
            ->map(function ($c) {
                $place = collect([
                    $c['city'] ?? null,
                    $c['state'] ?? null,
                    $c['country_region_name'] ?? ($c['country_region'] ?? null),
                ])->filter(function ($v) { return is_string($v) && trim($v) !== ''; })->unique()->implode(', ');

                return [
                    'at' => $c['checkpoint_time'] ?? null,
                    'tag' => $c['tag'] ?? null,
                    'status' => self::TAG_LABELS[$c['tag'] ?? ''] ?? ($c['subtag_message'] ?? null),
                    'message' => mb_substr(strip_tags((string)($c['message'] ?? '')), 0, 255),
                    'place' => $place ?: null,
                ];
            })
            // By instant, not by string: scans carry different UTC offsets.
            ->sortByDesc(function ($c) { return $c['at'] ? (strtotime($c['at']) ?: 0) : 0; })
            ->values()
            ->take(30)
            ->all();
    }
}
