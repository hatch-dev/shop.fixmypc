<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The AfterShip Tracking API, version config('aftership.api_version').
 *
 * Authentication is the `as-api-key` header with the key from .env. The key
 * is never logged: failures log the endpoint, the HTTP status and AfterShip's
 * meta block, nothing from the request.
 *
 * Every response is AfterShip's envelope {meta: {code, message, type}, data};
 * this returns `data` on success and throws AfterShipException otherwise.
 */
class AfterShipService
{
    public function isConfigured()
    {
        return trim((string)config('aftership.api_key')) !== '';
    }

    /**
     * Create a tracking. AfterShip refuses a courier + number it already
     * holds with meta code 4003 and hands back the existing record's id; that
     * is returned as success with 'existing' => true, so re-sending a
     * shipment never creates a second tracking and never fails.
     *
     * @return array AfterShip's tracking object, plus 'existing'.
     */
    public function createTracking(array $payload)
    {
        try {
            $data = $this->send('POST', 'trackings', $payload);
            $data['existing'] = false;

            return $data;

        } catch (AfterShipException $e) {

            if ($e->metaCode === 4003 && !empty($e->data['id'])) {
                $existing = $this->getTracking($e->data['id']);
                $existing['existing'] = true;

                return $existing;
            }

            throw $e;
        }
    }

    public function getTracking($id)
    {
        if (!preg_match('/^[0-9A-Za-z_-]{1,128}$/', (string)$id)) {
            throw new AfterShipException('The value of id is invalid.', 400, 4015, 'BadRequest');
        }

        return $this->send('GET', 'trackings/' . $id);
    }

    /**
     * Remove a tracking from the account, so it stops being polled and
     * stops sending webhooks. One that is already gone counts as removed.
     *
     * @return bool
     */
    public function deleteTracking($id)
    {
        if (!preg_match('/^[0-9A-Za-z_-]{1,128}$/', (string)$id)) {
            throw new AfterShipException('The value of id is invalid.', 400, 4015, 'BadRequest');
        }

        try {
            $this->send('DELETE', 'trackings/' . $id);
        } catch (AfterShipException $e) {
            if ($e->httpStatus === 404 || $e->metaCode === 4004) {
                return true;
            }
            throw $e;
        }

        return true;
    }

    /**
     * AfterShip's courier directory: every courier it supports (about 2,300),
     * or with $activeOnly the ones switched on in the account (Settings >
     * Couriers in the AfterShip admin). Each carries the extra fields that
     * courier needs to track (required_fields). See CourierDirectory, which
     * keeps a copy so this is not fetched on every screen.
     *
     * @return array The couriers.
     */
    public function couriers($activeOnly = false)
    {
        $data = $this->send('GET', 'couriers', null, $activeOnly ? ['active' => 'true'] : []);

        return is_array($data['couriers'] ?? null) ? $data['couriers'] : [];
    }

    /** One page of trackings; $query takes AfterShip's filters (limit, cursor, tag ...). */
    public function listTrackings(array $query = [])
    {
        return $this->send('GET', 'trackings', null, $query);
    }

    /**
     * One call, retried once on 429 after AfterShip's reset time (at most
     * two seconds: an admin is waiting on the other end).
     */
    private function send($method, $path, array $body = null, array $query = [])
    {
        if (!$this->isConfigured()) {
            throw new AfterShipException('AfterShip is not configured (AFTERSHIP_API_KEY is empty).', 401, 401, 'Unauthorized');
        }

        $url = rtrim(config('aftership.base_url'), '/') . '/'
            . config('aftership.api_version') . '/' . $path;

        for ($attempt = 1; ; $attempt++) {
            try {
                $request = Http::withHeaders([
                    'as-api-key' => config('aftership.api_key'),
                    'Accept' => 'application/json',
                ])->timeout((int)config('aftership.timeout', 15));

                if ($method === 'GET') {
                    $response = $request->get($url, $query);
                } elseif ($body) {
                    $response = $request->asJson()->send($method, $url, ['json' => $body]);
                } else {
                    // DELETE and other body-less calls.
                    $response = $request->send($method, $url);
                }

            } catch (\Illuminate\Http\Client\ConnectionException | \GuzzleHttp\Exception\TransferException $e) {
                // Only a failed connection is "unreachable"; a bug here must surface as itself.
                Log::warning('AfterShip unreachable', ['path' => $path, 'error' => get_class($e)]);
                throw new AfterShipException('AfterShip could not be reached.', 0, 0, 'Connection', $e);
            }

            // A busy second is worth one short retry; a spent daily allowance is not.
            if ($response->status() === 429 && $attempt === 1) {
                $reset = (int)$response->header('X-RateLimit-Reset');
                $daily = stripos((string)$response->body(), 'per day') !== false || ($reset - time() > 60);

                if (!$daily) {
                    usleep(max(1, min(2, $reset - time())) * 1000000);
                    continue;
                }
            }

            break;
        }

        $json = $response->json();
        $meta = is_array($json) && isset($json['meta']) && is_array($json['meta']) ? $json['meta'] : [];
        $code = (int)($meta['code'] ?? $response->status());

        if ($response->successful() && $code >= 200 && $code < 300) {
            return is_array($json['data'] ?? null) ? $json['data'] : [];
        }

        Log::warning('AfterShip refused a request', [
            'method' => $method,
            'path' => $path,
            'http' => $response->status(),
            'meta' => $meta,
        ]);

        $e = new AfterShipException(
            (string)($meta['message'] ?? ('HTTP ' . $response->status())),
            $response->status(),
            $code,
            $meta['type'] ?? null
        );
        $e->data = is_array($json['data'] ?? null) ? $json['data'] : [];

        if ($response->status() === 429) {
            $reset = (int)$response->header('X-RateLimit-Reset');
            if ($reset <= time() && preg_match('/reset at (\S+?Z)/i', (string)($meta['message'] ?? ''), $m)) {
                $reset = strtotime($m[1]) ?: 0;
            }
            $e->resetAt = $reset > time() ? $reset : null;
        }

        throw $e;
    }
}
