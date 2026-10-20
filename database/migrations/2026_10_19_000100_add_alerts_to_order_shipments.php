<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the current tracking number was entered (the 48-hour "no courier scan"
 * check counts from it, and a corrected number restarts it), and which staff
 * alerts have already gone out for that number, so each is sent once.
 */
class AddAlertsToOrderShipments extends Migration
{
    public function up()
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('order_shipments', 'tracking_set_at')) {
                $table->timestamp('tracking_set_at')->nullable()->after('tracking_number');
            }
            if (!Schema::hasColumn('order_shipments', 'staff_alerts')) {
                $table->string('staff_alerts', 255)->nullable()->after('last_error');
            }
        });

        DB::table('order_shipments')->whereNull('tracking_set_at')
            ->update(['tracking_set_at' => DB::raw('updated_at')]);
    }

    public function down()
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropColumn(['tracking_set_at', 'staff_alerts']);
        });
    }
}
