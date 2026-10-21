<?php

namespace App\Models\Helper;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AfterShip settings from the admin (Settings > AfterShip), laid over
 * config('aftership').
 *
 * Every AfterShip class reads config('aftership.*'), so apply() at boot is the
 * whole integration: nothing downstream knows where a value came from. A
 * column left NULL keeps the .env value. The secrets stay encrypted in the
 * database and in the cache, and are decrypted only into this process's config.
 */
class AfterShipSettings
{
    const CACHE_KEY = 'aftership.settings.v1';

    /* The fields the admin can set, and the config key each one fills. */
    const SECRETS = ['api_key', 'webhook_secret'];

    /** The minimum and maximum the sync interval may be set to, in minutes. */
    const MIN_SYNC_MINUTES = 15;
    const MAX_SYNC_MINUTES = 1440;

    /** Values from .env, captured before the first apply() overwrote them. */
    private static $envDefaults = null;

    /**
     * Put the saved values into config. Safe before the migration has run, and
     * on a database that is down: then .env simply applies.
     */
    public static function apply()
    {
        if (static::$envDefaults === null) {
            static::$envDefaults = [
                'api_key' => config('aftership.api_key'),
                'webhook_secret' => config('aftership.webhook_secret'),
                'auto_deliver' => config('aftership.auto_deliver'),
                'sync_minutes' => config('aftership.sync_minutes'),
            ];
        }

        $row = static::row();

        foreach (static::$envDefaults as $key => $default) {
            $value = $row[$key] ?? null;

            if ($value !== null && in_array($key, static::SECRETS, true)) {
                $value = static::decrypt($value, $key);
            }

            config(['aftership.' . $key => $value !== null ? $value : $default]);
        }
    }

    /**
     * What the admin page shows: where each value comes from, and only the
     * last four characters of a secret.
     */
    public static function describe()
    {
        $row = static::row(false);
        $env = static::$envDefaults ?: [];

        $secret = function ($key) use ($row, $env) {
            $stored = isset($row[$key]) ? static::decrypt($row[$key], $key) : null;
            $value = $stored !== null ? $stored : ($env[$key] ?? null);
            $value = trim((string)$value);

            return [
                'set' => $value !== '',
                'source' => $stored !== null ? 'admin' : ($value !== '' ? 'env' : null),
                'hint' => $value !== '' ? substr($value, -4) : null,
            ];
        };

        return [
            'api_key' => $secret('api_key'),
            'webhook_secret' => $secret('webhook_secret'),
            'auto_deliver' => (bool)config('aftership.auto_deliver'),
            'sync_minutes' => isset($row['sync_minutes']) ? (int)$row['sync_minutes'] : null,
            'sync_minutes_env' => $env['sync_minutes'] ?? null,
            'sync_minutes_auto' => trim((string)config('aftership.webhook_secret')) !== '' ? 720 : 60,
            'min_sync_minutes' => static::MIN_SYNC_MINUTES,
            'max_sync_minutes' => static::MAX_SYNC_MINUTES,
            'webhook_url' => rtrim((string)config('app.url'), '/') . '/api/v1/aftership/webhook',
        ];
    }

    /**
     * Save what the admin sent. A secret is only replaced when a new one is
     * typed; clear_* puts it back to .env. Returns true when the API key
     * changed, so the caller can forget the old account's courier list.
     *
     * @param array $input api_key, clear_api_key, webhook_secret, clear_webhook_secret, auto_deliver, sync_minutes
     */
    public static function save(array $input, $adminId = null)
    {
        $current = DB::table('aftership_settings')->orderBy('id')->first();
        $data = [];
        $keyChanged = false;

        foreach (static::SECRETS as $key) {
            $typed = trim((string)($input[$key] ?? ''));

            if (!empty($input['clear_' . $key])) {
                $data[$key] = null;
                $keyChanged = $keyChanged || ($key === 'api_key' && $current && $current->api_key !== null);
            } elseif ($typed !== '') {
                $data[$key] = Crypt::encryptString($typed);
                $keyChanged = $keyChanged || $key === 'api_key';
            }
        }

        if (array_key_exists('auto_deliver', $input)) {
            $data['auto_deliver'] = $input['auto_deliver'] === null ? null : (bool)$input['auto_deliver'];
        }

        if (array_key_exists('sync_minutes', $input)) {
            $data['sync_minutes'] = $input['sync_minutes'] === null || $input['sync_minutes'] === ''
                ? null
                : (int)$input['sync_minutes'];
        }

        $data['updated_by'] = $adminId;
        $data['updated_at'] = now();

        if ($current) {
            DB::table('aftership_settings')->where('id', $current->id)->update($data);
        } else {
            $data['created_at'] = now();
            DB::table('aftership_settings')->insert($data);
        }

        static::forget();
        static::apply();

        return $keyChanged;
    }

    /** Read the row again on the next apply() (after a save, and in tests). */
    public static function forget()
    {
        Cache::forget(static::CACHE_KEY);
    }

    /**
     * The stored row as an array, secrets still encrypted. Cached, because
     * apply() runs on every request; the admin's ClearCacheAfterWrite and a
     * save both drop it.
     */
    private static function row($cached = true)
    {
        $read = function () {
            try {
                $row = DB::table('aftership_settings')->orderBy('id')->first();
            } catch (\Throwable $e) {
                // Before the migration, or no database: .env applies.
                return [];
            }

            return $row ? (array)$row : [];
        };

        if (!$cached) {
            return $read();
        }

        try {
            return Cache::rememberForever(static::CACHE_KEY, $read);
        } catch (\Throwable $e) {
            return $read();
        }
    }

    private static function decrypt($value, $key)
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            // APP_KEY changed since it was saved: fall back to .env rather than send rubbish.
            Log::warning('Stored AfterShip setting could not be decrypted; using .env', ['field' => $key]);

            return null;
        }
    }
}
