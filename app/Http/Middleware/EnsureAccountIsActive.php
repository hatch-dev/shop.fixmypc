<?php

namespace App\Http\Middleware;

use App\Models\Helper\AccountStatus;
use App\Models\Helper\Validation;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Stop a blocked account using a token it already holds.
 *
 * Suspending an account revokes its tokens (AccountStatus::revokeAllSessions),
 * which is what actually ends the open sessions. This is the second line: a
 * token that escaped revocation — minted between the read and the write, or
 * belonging to a session row that had been pruned — must still not work.
 *
 * It answers on every authenticated customer request, so "log the customer out
 * at their next interaction" is a property of the server, not something the
 * browser is trusted to do.
 */
class EnsureAccountIsActive
{
    public function handle($request, Closure $next, $guard = 'user')
    {
        $user = Auth::guard($guard)->user() ?: Auth::user();

        $blocked = AccountStatus::authenticationBlock($user);

        if (!$blocked) {
            return $next($request);
        }

        /*
         * Revoke on the way past. Reaching here means a working token existed
         * for a blocked account, so leaving it alive would let the next
         * request try again.
         */
        AccountStatus::revokeAllSessions($user);

        /*
         * 401 so the browser can tell this apart from an ordinary refusal and
         * clear its own session. The body carries the same customer-facing
         * sentence sign-in gives, and never the internal status note.
         */
        return response()->json(
            Validation::error(null, $blocked, 'form', null, 401),
            401
        );
    }
}
