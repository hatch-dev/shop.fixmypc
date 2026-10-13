<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the product dimension, weight and purchase-note fields somewhere to live.
 *
 * The admin product form has always rendered "Length (cm)", "Width (cm)",
 * "Height (cm)", "Weight (kg)" and a purchase note, bound to component-local
 * `uiDims` / `uiPurchaseNote` state that was never submitted and never loaded.
 * Anything typed into them was discarded the moment the page closed.
 */
class AddShippingDimensionsToProducts extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('length', 10, 2)->nullable()->after('unit');
            $table->decimal('width', 10, 2)->nullable()->after('length');
            $table->decimal('height', 10, 2)->nullable()->after('width');
            $table->decimal('weight', 10, 3)->nullable()->after('height');
            $table->text('purchase_note')->nullable()->after('weight');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['length', 'width', 'height', 'weight', 'purchase_note']);
        });
    }
}
