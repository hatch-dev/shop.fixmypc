<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Bring existing customer sign-ins into line with "Keep me signed in"
 * (2026-10-13).
 *
 * Until now every customer sign-in was issued for twelve months, ticked or
 * not. New ones last 30 days when ticked and a day when not
 * (UsersController::issueUserSession). Sign-ins already out there would
 * otherwise run on for the rest of their year, so any customer token still
 * valid beyond 30 days from now is cut to 30 days - the longest a ticked
 * sign-in can last. Nobody is signed out today.
 *
 * Customer tokens only (scope "user"); admin tokens are left as they are.
 * Times are PHP times, never SQL NOW() - the two clocks differ here.
 */
class CapCustomerSignInLifetime extends Migration
{
    public function up()
    {
        $cap = Carbon::parse(date('Y-m-d H:i:s'))
            ->addDays(Config::get('constants.auth.EXPIRATION_IN_DAYS', 30))
            ->format('Y-m-d H:i:s');

        DB::table('oauth_access_tokens')
            ->where('revoked', 0)
            ->where('scopes', 'like', '%"user"%')
            ->where('expires_at', '>', $cap)
            ->update(['expires_at' => $cap]);
    }

    public function down()
    {
        // The old expiry dates are not kept; there is nothing to restore.
    }
}
