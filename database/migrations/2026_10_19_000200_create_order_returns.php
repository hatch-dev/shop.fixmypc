<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Returns, phase 1: a return case opened by staff against a delivered order.
 *
 * A return is its own record, never a change to the order. The order's money
 * is read only from its own rows (OrderSummary), so nothing here edits an
 * ordered line; what came back and what was paid back lives on these tables
 * and is netted off where it matters:
 *
 *   order_return_items.restocked_qty   Order::restoreStockAndRewards() skips it
 *   ordered_voucher.returned_amount    a gift card's balance gets it back
 *   point_transactions 'order_return'  the points side of a partial return
 */
class CreateOrderReturns extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('order_returns')) {
            Schema::create('order_returns', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('rma_number', 20)->unique();
                $table->unsignedInteger('order_id');
                $table->unsignedBigInteger('user_id')->nullable();

                // awaiting_return | received | refunding | refunded | closed | rejected
                $table->string('status', 20)->default('awaiting_return');
                $table->string('reason', 40);
                $table->text('customer_note')->nullable();
                $table->text('staff_note')->nullable();

                // What went back, written when the refund is made.
                $table->string('refund_method', 20)->nullable();   // original | manual | none
                $table->decimal('refund_amount', 12, 2)->nullable();
                $table->decimal('shipping_refund', 10, 2)->default(0);
                $table->decimal('gift_card_amount', 10, 2)->default(0);
                $table->integer('points_returned')->default(0);
                $table->integer('points_taken')->default(0);
                $table->string('refund_reference', 255)->nullable();
                $table->text('refund_error')->nullable();

                $table->unsignedInteger('created_by')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['order_id', 'status']);
                $table->index(['status', 'created_at']);

                $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('order_return_items')) {
            Schema::create('order_return_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_return_id');
                $table->unsignedInteger('ordered_product_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedInteger('inventory_id')->nullable();
                $table->integer('quantity');

                // Set when the parcel is received: null = not yet inspected.
                $table->boolean('restock')->nullable();
                $table->integer('restocked_qty')->default(0);

                // What this line paid back, money and gift card separately.
                $table->decimal('line_refund', 12, 2)->default(0);
                $table->decimal('gift_card_amount', 10, 2)->default(0);
                $table->timestamps();

                $table->index('ordered_product_id');

                $table->foreign('order_return_id')->references('id')->on('order_returns')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('order_return_history')) {
            Schema::create('order_return_history', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_return_id');
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->unsignedInteger('admin_id')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();

                $table->foreign('order_return_id')->references('id')->on('order_returns')->onDelete('cascade');
            });
        }

        if (!Schema::hasColumn('ordered_voucher', 'returned_amount')) {
            Schema::table('ordered_voucher', function (Blueprint $table) {
                $table->decimal('returned_amount', 10, 2)->default(0)->after('discount');
            });
        }

        DB::statement("
            ALTER TABLE point_transactions
            MODIFY COLUMN source
            ENUM('order','topup','referral','admin','order_reversal','gift_voucher','order_return')
            NOT NULL
        ");
    }

    public function down()
    {
        DB::table('point_transactions')->where('source', 'order_return')->update(['source' => 'admin']);

        DB::statement("
            ALTER TABLE point_transactions
            MODIFY COLUMN source
            ENUM('order','topup','referral','admin','order_reversal','gift_voucher')
            NOT NULL
        ");

        if (Schema::hasColumn('ordered_voucher', 'returned_amount')) {
            Schema::table('ordered_voucher', function (Blueprint $table) {
                $table->dropColumn('returned_amount');
            });
        }

        Schema::dropIfExists('order_return_history');
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
}
