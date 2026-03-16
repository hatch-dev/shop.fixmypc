<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInventoryImagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('inventory_images', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('inventory_id');
            $table->unsignedInteger('product_image_id');

            $table->timestamps();

            $table->foreign('inventory_id')
                ->references('id')
                ->on('updated_inventories')
                ->onDelete('cascade');

            $table->foreign('product_image_id')
                ->references('id')
                ->on('product_images')
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
        Schema::dropIfExists('inventory_images');
    }
}
