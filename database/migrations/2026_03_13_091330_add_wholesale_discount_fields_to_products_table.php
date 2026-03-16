<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWholesaleDiscountFieldsToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('wholesale_price', 10, 2)
                  ->nullable()
                  ->after('selling');

            $table->enum('wholesale_discount_type', ['fixed', 'percentage'])
                  ->default('fixed')
                  ->after('wholesale_price');

            $table->decimal('wholesale_discount_value', 10, 2)
                  ->nullable()
                  ->after('wholesale_discount_type');

            $table->string('updated_upsell_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'wholesale_price',
                'wholesale_discount_type',
                'wholesale_discount_value',
                'updated_upsell_id'
            ]);
        });
    }
}
