<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest checkout now confirms the email address before the order can be paid
 * for, because that address is what the receipt, the tracking link and - once
 * the order is placed - the account credentials are sent to.
 *
 * Two things are needed for that:
 *
 *  - somewhere to record which address a guest proved, on guest_users. The
 *    address itself is stored alongside the timestamp rather than a plain
 *    "verified" flag, so editing the email on the checkout form drops the
 *    confirmation instead of silently carrying it over to a new address.
 *
 *  - a purpose on login_codes. That table already holds one-time codes, but
 *    every row in it could be spent at login-code/verify for a session. A
 *    code minted to confirm a checkout email must not be able to sign anyone
 *    in, so each flow now only sees its own rows.
 */
class AddGuestCheckoutEmailVerification extends Migration
{
    public function up()
    {
        Schema::table('guest_users', function (Blueprint $table) {
            $table->string('verified_email')->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('verified_email');
        });

        Schema::table('login_codes', function (Blueprint $table) {
            $table->string('purpose')->default('login')->after('email');
            $table->index(['email', 'purpose']);
        });
    }

    public function down()
    {
        Schema::table('guest_users', function (Blueprint $table) {
            $table->dropColumn(['verified_email', 'email_verified_at']);
        });

        Schema::table('login_codes', function (Blueprint $table) {
            $table->dropIndex(['email', 'purpose']);
            $table->dropColumn('purpose');
        });
    }
}
