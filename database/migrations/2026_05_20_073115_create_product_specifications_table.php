<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductSpecificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_specifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('inventory_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('label');
            $table->text('value')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            $table->foreign('inventory_id')
                ->references('id')
                ->on('updated_inventories')
                ->onDelete('cascade');

            $table->foreign('group_id')
                ->references('id')
                ->on('specification_groups')
                ->onDelete('set null');

            $table->index('product_id');
            $table->index('inventory_id');
            $table->index('group_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('product_specifications');
    }
}
