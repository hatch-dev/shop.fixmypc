<?php

namespace App\Http\Middleware;

use Closure;

/**
 * Gate for the installer endpoints (QA audit SEC-03).
 *
 * They run migrations, seeders and .env rewrites, so they may only be reached
 * while the application has not yet been installed. Once
 * SHARED_SERVER_CONFIGURED is true they 404 like any route that does not exist,
 * which also keeps them out of endpoint discovery.
 */
class InstallerOnly
{
    public function handle($request, Closure $next)
    {
        if (env('SHARED_SERVER_CONFIGURED', false)) {
            abort(404);
        }

        return $next($request);
    }
}
