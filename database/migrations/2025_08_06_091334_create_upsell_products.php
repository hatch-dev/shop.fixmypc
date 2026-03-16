<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUpsellProducts extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
   /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('upsell_products', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('product_id')->unsigned();
            $table->integer('upsell_id')->unsigned();
            $table->float('price');
            $table->timestamps();
            $table->integer('admin_id')->unsigned();

            $table->foreign('product_id')
                ->references('id')
                ->on('products');

            $table->foreign('upsell_id')
                ->references('id')
                ->on('flash_sales');

            $table->foreign('admin_id')
                ->references('id')
                ->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('upsell_products');
    }
}
