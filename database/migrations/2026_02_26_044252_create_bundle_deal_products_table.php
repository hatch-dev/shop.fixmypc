<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBundleDealProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bundle_deal_products', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('bundle_deal_id');

            $table->unsignedBigInteger('product_id');

            $table->timestamps();

            $table->foreign('bundle_deal_id')
                ->references('id')
                ->on('bundle_deals')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
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
        Schema::dropIfExists('bundle_deal_products');
    }
}
