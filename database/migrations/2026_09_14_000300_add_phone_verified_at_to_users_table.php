<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the mobile number on an account was last proved to belong to it.
 *
 * Registration collects a number, checks its shape and enforces one account
 * per number, but nothing ever proved the customer could receive a message
 * at it. That matters once the number is a second factor: SMS 2FA texts a
 * code to whatever is stored, so an unproved number is a second factor
 * pointing at a stranger's phone.
 *
 * Nullable with no default: existing accounts are simply "not proved", which
 * is the truth about them, and nothing is gated on it.
 */
class AddPhoneVerifiedAtToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {

            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone');
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {

            if (Schema::hasColumn('users', 'phone_verified_at')) {
                $table->dropColumn('phone_verified_at');
            }
        });
    }
}
