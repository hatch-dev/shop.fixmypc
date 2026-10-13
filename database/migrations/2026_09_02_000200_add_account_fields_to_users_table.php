<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAccountFieldsToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {

            // Trade accounts are reviewed by staff before trade pricing applies.
            $table->string('trade_status')->nullable()->after('account_type');
            $table->timestamp('trade_reviewed_at')->nullable()->after('trade_status');
            $table->text('trade_review_note')->nullable()->after('trade_reviewed_at');

            // Marketing preferences captured at signup.
            $table->boolean('pref_email_offers')->default(true);
            $table->boolean('pref_sms_offers')->default(false);
            $table->boolean('pref_recommendations')->default(true);
            $table->boolean('pref_trade_list')->default(false);

            // Two-factor authentication.
            $table->string('two_factor_method')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'trade_status',
                'trade_reviewed_at',
                'trade_review_note',
                'pref_email_offers',
                'pref_sms_offers',
                'pref_recommendations',
                'pref_trade_list',
                'two_factor_method',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at'
            ]);
        });
    }
}
