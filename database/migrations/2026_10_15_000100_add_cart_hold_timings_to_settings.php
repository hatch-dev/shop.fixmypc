<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Basket reservation timings, set from the admin panel (Settings > Basket
 * reservation) instead of being fixed in CartHold.
 *
 * Both count from the last time something was added to the basket:
 * cart_remind_minutes sends the reminder email, cart_expire_minutes empties
 * the basket and puts the units back on sale. The defaults are what the
 * code did before.
 */
class AddCartHoldTimingsToSettings extends Migration
{
    public function up()
    {
        Schema::table('settings', function (Blueprint $table) {
            if (!Schema::hasColumn('settings', 'cart_remind_minutes')) {
                $table->unsignedSmallInteger('cart_remind_minutes')->default(5);
            }

            if (!Schema::hasColumn('settings', 'cart_expire_minutes')) {
                $table->unsignedSmallInteger('cart_expire_minutes')->default(20);
            }
        });
    }

    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['cart_remind_minutes', 'cart_expire_minutes'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
