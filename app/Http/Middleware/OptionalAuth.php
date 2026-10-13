<?php

namespace App\Http\Middleware;

use App\Models\Helper\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Token;

class OptionalAuth
{
    /**
     * Sign the caller in when they present a usable token, and say so plainly
     * when they present one that has stopped working.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle($request, Closure $next)
    {
        try {
            /*
             * Read the bearer BEFORE resolving the guard, and use this copy
             * afterwards.
             *
             * Passport STRIPS the Authorization header off the request when it
             * refuses a token, so once the guard has returned null there is
             * nothing left to look at: `$request->bearerToken()` is empty and
             * the spent-token check below quietly finds nothing to check.
             */
            $bearer = $request->bearerToken();

            if ($bearer) {

                /*
                 * Resolving a bearer Passport no longer accepts throws rather
                 * than returning null, so this catch is scoped to the call
                 * itself. Wrapping the whole block instead sent a revoked
                 * token straight to the guest path.
                 */
                try {
                    $user = Auth::guard('user')->user();
                } catch (\Exception $e) {
                    $user = null;
                }

                /*
                 * A token that still works, but whose account has been blocked.
                 * Take the rest of its sessions with it and tell the browser:
                 * quietly downgrading to a guest leaves a signed-in page
                 * sitting over an account that can no longer be used.
                 *
                 * Suspension is not a ban from the shop front — once the
                 * browser acts on this and drops the token, the next request
                 * carries no bearer and they browse like any other visitor.
                 */
                if ($user && ($blocked = AccountStatus::authenticationBlock($user))) {

                    AccountStatus::revokeAllSessions($user);

                    return $this->sessionEnded($blocked);
                }

                if ($user) {
                    Auth::setUser($user);

                    return $next($request);
                }

                /*
                 * Nobody resolved. That is only worth reporting when this was
                 * a real token of ours that has since been revoked or expired
                 * — which is what suspension does to every one of them, and
                 * what the browser needs in order to stop showing a signed-in
                 * page over a dead session.
                 *
                 * Anything we cannot identify is left exactly as it was:
                 * treated as a guest. A stale "Bearer null" or "Bearer
                 * undefined" from a client that lost its session is common,
                 * and answering 401 to those would sign people out mid-visit
                 * for no reason at all.
                 */
                if ($this->tokenIsSpent($bearer)) {
                    return $this->sessionEnded(
                        'Your session has ended. Please sign in again.'
                    );
                }
            }
        } catch (\Exception $e) {
            // ignore if guest
        }

        return $next($request);
    }

    /**
     * Was this a token we issued, that is now revoked or expired?
     *
     * False for anything not recognisably one of ours, so an unparseable or
     * unknown bearer keeps the old guest behaviour.
     */
    private function tokenIsSpent($bearer)
    {
        $id = $this->tokenId($bearer);

        if (!$id) {
            return false;
        }

        $token = Token::find($id);

        if (!$token) {
            return false;
        }

        if ($token->revoked) {
            return true;
        }

        return $token->expires_at && $token->expires_at->isPast();
    }

    /**
     * The `jti` claim out of a Passport access token, or null.
     *
     * Read without verifying: this only decides whether to say "your session
     * ended". It never decides whether to trust anybody — authentication has
     * already run above and refused.
     */
    private function tokenId($bearer)
    {
        $parts = explode('.', (string)$bearer);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($payload === false) {
            return null;
        }

        $claims = json_decode($payload, true);

        return is_array($claims) && !empty($claims['jti'])
            ? $claims['jti']
            : null;
    }

    /**
     * "You are not signed in any more", in a shape the browser can act on.
     *
     * HTTP 401 on purpose. Most refusals in this API are carried in the body
     * with HTTP 201, which axios reads as success — so a refusal shaped that
     * way never reaches a response interceptor and the page carries on as if
     * nothing had happened. The body still carries the usual fields, so
     * anything reading `message` keeps working.
     */
    private function sessionEnded($message)
    {
        return response()->json([
            'data' => null,
            'status' => 401,
            'token' => null,
            'message' => $message,
            // A flag the client can key on without matching on wording.
            'session_ended' => true,
        ], 401);
    }
}
