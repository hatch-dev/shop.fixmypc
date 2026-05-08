<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBusinessProductPricingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('business_product_pricing', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');

            $table->integer('min_qty');
            $table->integer('max_qty');

            $table->decimal('wholesale_price', 10, 2)->default(0);

            $table->enum('discount_type', ['percentage', 'fixed'])->default('fixed');
            $table->decimal('discount_value', 10, 2)->default(0);

            $table->decimal('final_price', 10, 2)->default(0);

            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('business_product_pricing');
    }
}
