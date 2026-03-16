<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatedUpsellProductServiceItems extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('updated_upsell_product_service_items', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('updated_upsell_product_service_id');

            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);

            $table->timestamps();

            $table->foreign(
                'updated_upsell_product_service_id',
                'fk_upsell_service_item'
            )
            ->references('id')
            ->on('updated_upsells_product_service')
            ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
       Schema::dropIfExists('updated_upsell_product_service_items');
    }
}
