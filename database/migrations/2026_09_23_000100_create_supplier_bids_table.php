<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a supplier answers with.
 *
 * The bid page has always collected a price and a grade per line and then
 * thrown them away -- submitBid() logged to the console and there was nowhere
 * for the answer to go. This is that somewhere.
 *
 * Keyed on the supplier_notifications row rather than on
 * (order, supplier, product, inventory): that row is what the WhatsApp link
 * names, so it is the only identity both ends already agree on, and it makes
 * "one live bid per line I was asked about" a database rule instead of
 * controller etiquette. A supplier revising a price updates in place.
 */
class CreateSupplierBidsTable extends Migration
{
    public function up()
    {
        Schema::create('supplier_bids', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('supplier_notification_id');

            /*
             * Denormalised from the notification so the admin side can filter
             * bids by order or supplier without a join, and so a bid survives
             * legibly if the notification row is ever cleaned up.
             */
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('inventory_id')->nullable();

            // What the supplier will sell it to us for, per unit.
            $table->decimal('price', 12, 2);

            $table->string('grade', 32);

            $table->timestamps();

            $table->unique('supplier_notification_id', 'supplier_bids_notification_unique');
            $table->index(['order_id', 'supplier_id'], 'supplier_bids_order_supplier_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('supplier_bids');
    }
}
