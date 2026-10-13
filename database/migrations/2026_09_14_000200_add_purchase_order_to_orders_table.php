<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The purchase order number a business customer has to quote on its invoice.
 *
 * Registration has asked business accounts "do you need PO numbers on your
 * invoices?" since the multi-step signup shipped, stored the answer in
 * user_business_profiles.requires_po, and then done nothing with it: there was
 * nowhere to put a PO number, so checkout never asked for one and no invoice
 * ever carried one. The flag was a promise the rest of the system could not
 * keep.
 */
class AddPurchaseOrderToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('purchase_order_number')->nullable()->after('order');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('purchase_order_number');
        });
    }
}
