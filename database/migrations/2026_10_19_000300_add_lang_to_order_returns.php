<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language a return's emails are written in. Nothing records a
 * customer's language, and staff open the case, so staff choose it; null
 * means the store's default.
 */
class AddLangToOrderReturns extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('order_returns', 'lang')) {
            Schema::table('order_returns', function (Blueprint $table) {
                $table->string('lang', 8)->nullable()->after('reason');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('order_returns', 'lang')) {
            Schema::table('order_returns', function (Blueprint $table) {
                $table->dropColumn('lang');
            });
        }
    }
}
