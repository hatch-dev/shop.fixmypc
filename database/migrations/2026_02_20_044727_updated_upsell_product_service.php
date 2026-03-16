<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatedUpsellProductService extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('updated_upsells_product_service', function (Blueprint $table) {
            $table->id();

            $table->foreignId('updated_upsells_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('type', ['product', 'service']);

            $table->string('title');

            $table->string('image');

            $table->text('description')->nullable();

            $table->decimal('service_price', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('updated_upsells_product_service');
    }
}
