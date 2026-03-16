<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUpsellSubcategoriesTable extends Migration
{
    public function up()
    {
        Schema::create('upsell_subcategories', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('upsell_id');
            $table->unsignedBigInteger('subcategory_id');

            $table->timestamps();

            $table->foreign('upsell_id')
                ->references('id')
                ->on('upsells')
                ->onDelete('cascade');

        });
    }

    public function down()
    {
        Schema::dropIfExists('upsell_subcategories');
    }
}
