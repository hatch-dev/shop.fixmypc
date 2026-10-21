<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AfterShip settings entered in the admin (Settings > AfterShip).
 *
 * One row. A NULL column means "not set here": the value from .env applies,
 * so a site that has never opened the page behaves exactly as before. The API
 * key and webhook secret are stored encrypted with APP_KEY and never sent back
 * to the browser. Kept out of `settings`, whose row several public endpoints
 * serialise whole.
 */
class CreateAftershipSettingsTable extends Migration
{
    public function up()
    {
        Schema::create('aftership_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('api_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('auto_deliver')->nullable();
            $table->unsignedSmallInteger('sync_minutes')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('aftership_settings');
    }
}
