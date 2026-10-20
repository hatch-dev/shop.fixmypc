<?php

/*
 * AfterShip Tracking API.
 *
 * Server-side only. The key and the webhook secret are read from .env and
 * never leave the API: no endpoint returns them, the admin panel and the
 * storefront are built without them, and AfterShipService never logs a
 * request header.
 */
return [

    'api_key' => env('AFTERSHIP_API_KEY'),

    /*
     * The dated API version. 2026-07 is what this was built and tested
     * against (2026-10-16); AfterShip keeps old versions alive, so moving
     * forward is a deliberate change, not something to float.
     */
    'api_version' => env('AFTERSHIP_API_VERSION', '2026-07'),

    'base_url' => 'https://api.aftership.com/tracking/',

    /*
     * Settings > Webhooks in the AfterShip admin. Empty means the webhook
     * answers 503 to everything: an unverifiable call is never processed.
     */
    'webhook_secret' => env('AFTERSHIP_WEBHOOK_SECRET'),

    /* Seconds to wait for AfterShip before giving the admin an answer. */
    'timeout' => 15,

    /*
     * Move the order to Delivered when the courier reports it delivered.
     * It goes through the same transition as the admin dropdown, so the
     * delivered email, loyalty points and the cash-on-delivery paid stamp
     * all happen exactly as they do by hand.
     */
    'auto_deliver' => env('AFTERSHIP_AUTO_DELIVER', true),

    /*
     * Couriers are not listed here: they come from AfterShip (GET /couriers),
     * with the ones activated in the AfterShip account (Settings > Couriers)
     * offered first. See App\Models\Helper\CourierDirectory. The list is
     * kept as JSON in this folder, outside the Laravel cache, which the admin
     * empties after every save.
     */
    'courier_store' => storage_path('app/aftership'),

    /*
     * AfterShip's simulated courier, for end-to-end tests without a real
     * parcel (numbers like ITD-1-123456789). It is in AfterShip's list like
     * any courier; it is offered only outside production unless switched
     * on, so a live order can never be "shipped" with it.
     */
    'allow_test_courier' => env('AFTERSHIP_ALLOW_TEST_COURIER', env('APP_ENV') !== 'production'),

    'test_courier_slug' => 'testing-courier',

    /*
     * Minutes a parcel may go unchecked before aftership:sync asks AfterShip.
     * Empty = automatic: 720 (12h) once a webhook secret is set, because
     * AfterShip then pushes every change; 60 without one. Each check is an
     * API call (the free plan allows 100 a day).
     */
    'sync_minutes' => env('AFTERSHIP_SYNC_MINUTES'),

    /*
     * Signs the customer's tracking link. Empty = APP_KEY, which signed every
     * link sent so far; setting this invalidates those links' full view (the
     * page still shows courier and status), so set it once, before go-live.
     */
    'link_secret' => env('AFTERSHIP_LINK_SECRET'),
];
