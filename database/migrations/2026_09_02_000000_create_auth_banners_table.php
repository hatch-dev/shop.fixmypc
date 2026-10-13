<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuthBannersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('auth_banners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('eyebrow')->nullable();
            $table->string('title')->nullable();
            $table->text('sub')->nullable();
            $table->string('cta')->nullable();
            $table->string('href')->nullable();
            $table->string('image')->nullable();
            $table->string('theme')->default('dark');
            $table->string('placement')->default('auth');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->integer('sort')->default(0);
            $table->tinyInteger('status')->default(1);
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
        Schema::dropIfExists('auth_banners');
    }
}
