<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

class ClearCacheAfterWrite
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (in_array($request->method(), ['GET','POST', 'PUT', 'PATCH', 'DELETE']) ) {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
        }

        return $response;
    }
}