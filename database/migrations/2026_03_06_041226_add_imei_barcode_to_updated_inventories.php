<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddImeiBarcodeToUpdatedInventories extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('updated_inventories', function (Blueprint $table) {
            $table->string('imei')->nullable()->after('sku');
            $table->string('barcode')->nullable()->after('imei');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('updated_inventories', function (Blueprint $table) {
            $table->dropColumn(['imei', 'barcode']);
        });
    }
}
