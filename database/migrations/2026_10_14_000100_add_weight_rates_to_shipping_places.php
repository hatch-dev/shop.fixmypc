<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weight bands on a delivery row, after WooCommerce's Table Rate Shipping.
 *
 * JSON list, lightest first: [{"max": 2, "cost": 5, "per_kg": 0}, ...]. A band
 * covers the weight above the previous band's "max" (0 for the first) up to
 * and including its own; a null "max" on the last band means no upper limit.
 * The charge is cost + per_kg for every kg over the band's start.
 *
 * NULL or an empty list keeps the row's flat `price`, as before.
 */
class AddWeightRatesToShippingPlaces extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('shipping_places', 'weight_rates')) {
            Schema::table('shipping_places', function (Blueprint $table) {
                $table->text('weight_rates')->nullable()->after('price');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('shipping_places', 'weight_rates')) {
            Schema::table('shipping_places', function (Blueprint $table) {
                $table->dropColumn('weight_rates');
            });
        }
    }
}
