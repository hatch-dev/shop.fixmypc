<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

class ClearCacheAfterWrite
{
    /**
     * Methods that can actually have changed something.
     *
     * GET and HEAD were in this list, which meant four Artisan commands ran
     * after every read on all 440 routes this middleware is attached to —
     * around 120ms of pure overhead on a request whose own work is a handful
     * of indexed queries, and a cache that was emptied so often it could never
     * serve anything. A read cannot invalidate a cache, so it no longer tries.
     */
    const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /*
     * cache:clear flushes the default store. The rate limiter used to live
     * there, so every write reset every throttle (SEC-007); it now has its
     * own store (config/cache.php 'limiter'). The flush itself stays for now:
     * the 24h storefront caches (product detail with stock, flash price and
     * wishlist flag; home; common) have no targeted invalidation and rely on it.
     */

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (in_array($request->method(), self::WRITE_METHODS, true)) {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
        }

        return $response;
    }
}