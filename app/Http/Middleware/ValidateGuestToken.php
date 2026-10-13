<?php

namespace App\Http\Middleware;

use App\Models\GuestUser;
use App\Models\Helper\Validation;
use Closure;

/**
 * Reject guest tokens the server never issued (QA audit SEC-02).
 *
 * A guest session is keyed on `user_token`. That token used to be generated in
 * the browser from Math.random() and accepted verbatim by every guest endpoint,
 * which made it a bearer credential anyone could forge or guess — good for the
 * holder's cart, addresses, orders and cancellations.
 *
 * Tokens are now minted by GuestUsersController::issueToken(). This middleware
 * enforces that: a `user_token` must name a row in guest_users. Tokens already
 * in the wild have rows, so existing guest baskets keep working while forged
 * ones stop.
 */
class ValidateGuestToken
{
    public function handle($request, Closure $next)
    {
        $token = $request->input('user_token') ?? $request->query('user_token');

        // A signed-in customer does not need one.
        if (empty($token) || $request->user('user')) {
            return $next($request);
        }

        if (!is_string($token) || strlen($token) > 191) {
            return response()->json(Validation::unauthorized());
        }

        if (!GuestUser::where('user_token', $token)->exists()) {
            return response()->json(Validation::unauthorized());
        }

        return $next($request);
    }
}
