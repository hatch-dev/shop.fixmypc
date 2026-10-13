<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account status and verification status, kept apart from account type.
 *
 * The three were one field in everything but name: an account was "business"
 * or "trade", and that single word had to stand for what kind of customer they
 * are, whether the account may be used at all, and whether anyone has checked
 * their VAT number. There was no way to stop a customer buying without
 * deleting them, and no way to record that a business account's details had
 * been looked at.
 *
 *   account_type         what kind of customer this is        (never changes on its own)
 *   status               may this account be used             (active / suspended / ...)
 *   verification_status  have staff checked the details       (business + trade only)
 *   trade_status         is trade pricing granted             (pending / approved / ...)
 */
class AddAccountStatusToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {

            /*
             * Existing accounts are active: this column is new, and defaulting
             * anything else would lock out every customer already registered.
             */
            $table->enum('status', ['pending', 'active', 'suspended', 'rejected'])
                ->default('active')
                ->after('trade_review_note');

            $table->text('status_note')->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_note');

            /*
             * Personal accounts have nothing to verify, which is a different
             * thing from "not verified yet" — hence not_required rather than a
             * nullable column, so the distinction survives a query.
             */
            $table->enum('verification_status', ['not_required', 'pending', 'verified', 'rejected'])
                ->default('not_required')
                ->after('status_changed_at');

            $table->index('status');
            $table->index('account_type');
        });

        /*
         * Backfill. Business and trade accounts registered before this column
         * existed have details nobody has checked, so they start pending —
         * except approved trade accounts, where the approval *was* the check.
         */
        DB::table('users')
            ->whereIn('account_type', ['business', 'trade'])
            ->update(['verification_status' => 'pending']);

        DB::table('users')
            ->where('account_type', 'trade')
            ->where('trade_status', 'approved')
            ->update(['verification_status' => 'verified']);

        DB::table('users')
            ->where('account_type', 'trade')
            ->where('trade_status', 'rejected')
            ->update(['verification_status' => 'rejected']);
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['account_type']);
            $table->dropColumn([
                'status', 'status_note', 'status_changed_at', 'verification_status'
            ]);
        });
    }
}
