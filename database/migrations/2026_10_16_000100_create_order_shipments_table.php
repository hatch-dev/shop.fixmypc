<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parcel an order went out in, and what AfterShip last said about it.
 *
 * One row per order: the admin screen records one courier and one tracking
 * number. aftership_id is not unique -- two orders packed in one parcel share
 * a tracking, and AfterShip answers the second create with the first one's id.
 */
class CreateOrderShipmentsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('order_shipments')) {
            return;
        }

        Schema::create('order_shipments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('order_id');
            $table->string('courier_slug', 64);
            $table->string('courier_name', 128)->nullable();
            $table->string('tracking_number', 64);
            $table->string('aftership_id', 128)->nullable();

            // AfterShip's branded page, when the account has one set up.
            $table->string('aftership_tracking_url', 500)->nullable();
            $table->string('courier_tracking_link', 500)->nullable();

            // InfoReceived, InTransit, Delivered ... (AfterShip delivery statuses)
            $table->string('tag', 32)->default('Pending');
            $table->string('subtag', 32)->nullable();
            $table->string('subtag_message', 191)->nullable();
            $table->string('expected_delivery', 40)->nullable();

            // Town-level checkpoints only; see OrderShipment::publicCheckpoints().
            $table->longText('checkpoints')->nullable();

            // AfterShip's own updated_at: an older update never overwrites a newer one.
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('order_id', 'order_shipments_order_unique');
            $table->index('aftership_id');
            $table->index(['tag', 'last_synced_at']);

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_shipments');
    }
}
