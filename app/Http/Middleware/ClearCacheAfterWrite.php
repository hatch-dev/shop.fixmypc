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