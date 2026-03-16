<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUpsellCategoriesTable extends Migration
{
    public function up()
    {
        Schema::create('upsell_categories', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('upsell_id');
            $table->unsignedBigInteger('category_id');

            $table->timestamps();

            $table->foreign('upsell_id')
                ->references('id')
                ->on('upsells')
                ->onDelete('cascade');

            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('upsell_categories');
    }
}
