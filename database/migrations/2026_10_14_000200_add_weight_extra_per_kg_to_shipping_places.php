<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One charge per extra kg for orders heavier than a row's last weight band.
 *
 * The bands (weight_rates) are flat prices. An order above the heaviest one
 * pays that band's cost plus this for every kg over its limit; NULL means
 * such an order cannot be delivered to the row's destination.
 */
class AddWeightExtraPerKgToShippingPlaces extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('shipping_places', 'weight_extra_per_kg')) {
            Schema::table('shipping_places', function (Blueprint $table) {
                $table->decimal('weight_extra_per_kg', 10, 2)->nullable()->after('weight_rates');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('shipping_places', 'weight_extra_per_kg')) {
            Schema::table('shipping_places', function (Blueprint $table) {
                $table->dropColumn('weight_extra_per_kg');
            });
        }
    }
}
