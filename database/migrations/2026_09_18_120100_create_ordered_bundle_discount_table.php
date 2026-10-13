<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Essentials Bundle discount as it was actually granted on an order.
 *
 * An order is a historical fact. OrderSummary re-derives every order total
 * from the order's OWN rows and never from the live catalogue, so a discount
 * that comes off the amount charged but is recorded nowhere makes the
 * confirmation, the invoice and the admin order detail all add up to more than
 * was taken — silently, and for ever, because the bundle's percentage can be
 * edited or the bundle deleted the day after.
 *
 * Same shape and the same reason as ordered_flash_discount and
 * ordered_loyalty_discount, which sit beside it.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('ordered_bundle_discount')) {
            return;
        }

        Schema::create('ordered_bundle_discount', function (Blueprint $table) {
            $table->id();
            // unsignedInteger to match orders.id, which is int(10) unsigned.
            // MySQL rejects a foreign key whose types differ (errno 150).
            $table->unsignedInteger('order_id');

            /*
             * Nullable and ON DELETE SET NULL: deleting a bundle must not
             * delete the record of what a customer was charged under it.
             */
            $table->unsignedInteger('bundle_deal_id')->nullable();

            // Kept as text as well as by id, so the order still reads sensibly
            // once the bundle has been renamed or removed.
            $table->string('title')->nullable();

            $table->decimal('discount', 10, 2)->default(0);

            $table->timestamps();

            $table->index('order_id');

            $table->foreign('order_id')
                ->references('id')->on('orders')
                ->cascadeOnDelete();

            $table->foreign('bundle_deal_id')
                ->references('id')->on('bundle_deals')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ordered_bundle_discount');
    }
};
