<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        /*
         * RFC 8058 one-click unsubscribe. Gmail and Yahoo POST to the
         * List-Unsubscribe URL directly from their infrastructure, so there
         * is no session and no token to send. The request is authenticated
         * instead by Laravel's signed-URL signature, checked in the route.
         */
        'newsletter/unsubscribe',
    ];
}
