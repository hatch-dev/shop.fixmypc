<?php

namespace App\Models\Helper;

use App\Services\AfterShipException;
use App\Services\AfterShipService;
use Illuminate\Support\Facades\Log;

/**
 * AfterShip's courier list, read from its API (GET /couriers), never typed in.
 * The admin is offered only the couriers activated in the AfterShip account;
 * see offered().
 *
 * Kept as JSON files under storage/app, not in the Laravel cache: the admin's
 * ClearCacheAfterWrite middleware empties the cache after every admin save,
 * and the full list is ~650 KB. The full list is refreshed daily, the
 * account's activated couriers hourly (someone may switch one on in the
 * AfterShip admin), and both on demand from the order screen.
 *
 * If AfterShip cannot be reached, the last copy is used, however old: a
 * courier list that is a week stale is far better than no way to ship.
 */
class CourierDirectory
{
    const ALL_TTL = 86400;
    const ACTIVE_TTL = 3600;

    /** Required fields we fill from the order; anything else the admin types. */
    const AUTO_FIELDS = [
        'destination_postal_code' => 'Delivery postcode',
        'destination_country_region' => 'Delivery country',
        'destination_state' => 'Delivery county / state',
        'origin_country_region' => 'Shop country',
        'tracking_ship_date' => 'Ship date (today)',
    ];

    /** Fields only the admin can supply, with the label the form shows. */
    const MANUAL_FIELDS = [
        'tracking_account_number' => 'Courier account number',
        'tracking_key' => 'Courier tracking key',
    ];

    private static $memo = [];

    /** Every courier AfterShip supports, keyed by slug. */
    public static function all($refresh = false, AfterShipService $api = null)
    {
        return static::load('all', static::ALL_TTL, $refresh, $api);
    }

    /** The couriers activated in the AfterShip account, keyed by slug. */
    public static function active($refresh = false, AfterShipService $api = null)
    {
        return static::load('active', static::ACTIVE_TTL, $refresh, $api);
    }

    /**
     * The couriers the admin may choose: the ones activated in the AfterShip
     * account (Settings > Couriers there), plus AfterShip's test courier
     * where it is allowed (it is never "activated"). The full list is only
     * read for that test courier, so production fetches the activated list
     * alone.
     *
     * @return array Keyed by slug.
     */
    public static function offered($refresh = false)
    {
        $list = [];

        foreach (static::active($refresh) as $slug => $c) {
            if (static::allowed($slug)) {
                $list[$slug] = $c;
            }
        }

        $test = config('aftership.test_courier_slug', 'testing-courier');

        if (config('aftership.allow_test_courier') && !isset($list[$test])) {
            $all = static::all($refresh);
            if (isset($all[$test])) {
                $list[$test] = $all[$test];
            }
        }

        return $list;
    }

    /** One courier the admin may choose, or null (not activated in AfterShip, or the hidden test courier). */
    public static function find($slug)
    {
        $offered = static::offered();

        return $offered[(string)$slug] ?? null;
    }

    /**
     * AfterShip's simulated courier is a real entry in its list; it is
     * offered only where config allows (off in production by default), so a
     * live order can never be "shipped" with it.
     */
    public static function allowed($slug)
    {
        return $slug !== config('aftership.test_courier_slug', 'testing-courier')
            || config('aftership.allow_test_courier');
    }

    /** When the activated list was fetched, for the admin screen. */
    public static function fetchedAt()
    {
        $file = static::read('active');

        return $file ? date(DATE_ATOM, (int)$file['fetched_at']) : null;
    }

    /** Only the fields the screen needs: AfterShip also sends phones, credentials, languages. */
    public static function normalise(array $c)
    {
        return [
            'slug' => (string)($c['slug'] ?? ''),
            'name' => (string)($c['name'] ?? ($c['slug'] ?? '')),
            'other_name' => isset($c['other_name']) ? (string)$c['other_name'] : null,
            'required_fields' => array_values(array_filter((array)($c['required_fields'] ?? []), 'is_string')),
            'countries' => array_values(array_filter((array)($c['service_from_country_regions'] ?? []), 'is_string')),
            'web_url' => isset($c['web_url']) ? (string)$c['web_url'] : null,
        ];
    }

    /** Required fields split into what we fill and what the admin must type. */
    public static function fieldPlan(array $courier)
    {
        $auto = [];
        $manual = [];

        foreach ($courier['required_fields'] as $field) {
            if (isset(static::AUTO_FIELDS[$field])) {
                $auto[$field] = static::AUTO_FIELDS[$field];
            } else {
                $manual[$field] = static::MANUAL_FIELDS[$field] ?? ucfirst(str_replace('_', ' ', $field));
            }
        }

        return ['auto' => $auto, 'manual' => $manual];
    }

    /** The admin screen's view of a courier. */
    public static function view(array $courier, $active = false)
    {
        $plan = static::fieldPlan($courier);

        return [
            'slug' => $courier['slug'],
            'name' => $courier['name'],
            'other_name' => $courier['other_name'],
            'countries' => $courier['countries'],
            'active' => (bool)$active,
            'auto_fields' => $plan['auto'],
            'manual_fields' => $plan['manual'],
        ];
    }

    private static function load($kind, $ttl, $refresh, AfterShipService $api = null)
    {
        if (!$refresh && isset(static::$memo[$kind])) {
            return static::$memo[$kind];
        }

        $file = static::read($kind);

        // Fresh, or a recent refresh failed and is backing off: use the stored copy.
        if (!$refresh && $file && ((time() - (int)$file['fetched_at']) < $ttl
                || (int)($file['retry_after'] ?? 0) > time())) {
            return static::$memo[$kind] = $file['couriers'];
        }

        try {
            $list = ($api ?: app(AfterShipService::class))->couriers($kind === 'active');

        } catch (AfterShipException $e) {
            if ($file) {
                Log::warning('AfterShip courier list could not be refreshed; using the stored copy', [
                    'kind' => $kind, 'error' => $e->adminMessage(),
                ]);

                /*
                 * Back off before asking again. Without this every request
                 * after a failure called AfterShip anew: up to 15 seconds'
                 * wait each, and the daily allowance spent on refusals.
                 */
                $wait = $e instanceof AfterShipException && $e->isQuotaExhausted() && $e->resetAt
                    ? max(600, $e->resetAt - time())
                    : 600;
                static::write($kind, $file['couriers'], (int)$file['fetched_at'], time() + $wait);

                return static::$memo[$kind] = $file['couriers'];
            }

            throw $e;
        }

        $keyed = [];
        foreach ($list as $c) {
            $n = static::normalise((array)$c);
            if ($n['slug'] !== '') {
                $keyed[$n['slug']] = $n;
            }
        }

        // An empty answer for the full list is a fault, not a fact: keep the old copy.
        if ($kind === 'all' && !$keyed && $file) {
            return static::$memo[$kind] = $file['couriers'];
        }

        static::write($kind, $keyed);

        return static::$memo[$kind] = $keyed;
    }

    public static function path($kind)
    {
        return rtrim(config('aftership.courier_store', storage_path('app/aftership')), '/\\')
            . DIRECTORY_SEPARATOR . 'couriers-' . $kind . '.json';
    }

    private static function read($kind)
    {
        $path = static::path($kind);

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($path), true);

        return is_array($data) && isset($data['fetched_at'], $data['couriers']) && is_array($data['couriers']) ? $data : null;
    }

    /**
     * Write-then-rename, so a reader never sees half a file. Never throws: a
     * list that could not be stored is still returned for this request.
     */
    private static function write($kind, array $couriers, $fetchedAt = null, $retryAfter = null)
    {
        $path = static::path($kind);
        $tmp = $path . '.' . getmypid() . '.' . mt_rand() . '.tmp';

        try {
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('cannot create ' . dirname($path));
            }

            $data = ['fetched_at' => $fetchedAt ?: time(), 'couriers' => $couriers];
            if ($retryAfter) {
                $data['retry_after'] = $retryAfter;
            }

            if (@file_put_contents($tmp, json_encode($data)) === false) {
                throw new \RuntimeException('cannot write ' . $tmp);
            }

            // Windows refuses to rename over a file another request has open: copy instead.
            if (!@rename($tmp, $path) && !(@copy($tmp, $path))) {
                throw new \RuntimeException('cannot replace ' . $path);
            }
        } catch (\Throwable $e) {
            Log::warning('AfterShip courier list could not be stored', ['kind' => $kind, 'error' => $e->getMessage()]);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** For tests and the refresh button: forget the in-process copy. */
    public static function flush()
    {
        static::$memo = [];
    }
}
