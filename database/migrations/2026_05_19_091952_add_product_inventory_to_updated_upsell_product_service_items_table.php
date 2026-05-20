<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProductInventoryToUpdatedUpsellProductServiceItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('updated_upsell_product_service_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')
                ->nullable()
                ->after('price');

            $table->unsignedBigInteger('inventory_id')
                ->nullable()
                ->after('product_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('updated_upsell_product_service_items', function (Blueprint $table) {
            $table->dropColumn([
                'product_id',
                'inventory_id'
            ]);
        });
    }
}
