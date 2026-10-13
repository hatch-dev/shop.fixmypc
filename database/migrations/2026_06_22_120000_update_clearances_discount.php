<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateClearancesDiscount extends Migration
{
    /**
     * Run the migrations.
     *
     * Replaces the manual actual/deal price + units/claimed fields with a
     * single discount (fixed amount or percentage) that is applied to the
     * product's price and to every product variation. Units-left and the
     * claimed % are now derived automatically from stock + sales.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clearances', function (Blueprint $table) {
            $table->enum('discount_type', ['fixed', 'percentage'])->default('percentage')->after('subtitle');
            $table->decimal('discount_value', 10, 2)->default(0)->after('discount_type');
        });

        Schema::table('clearances', function (Blueprint $table) {
            $table->dropColumn(['actual_price', 'deal_price', 'units_left', 'claimed_percent']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clearances', function (Blueprint $table) {
            $table->decimal('actual_price', 10, 2)->nullable();
            $table->decimal('deal_price', 10, 2)->nullable();
            $table->integer('units_left')->nullable();
            $table->integer('claimed_percent')->nullable();
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
}
