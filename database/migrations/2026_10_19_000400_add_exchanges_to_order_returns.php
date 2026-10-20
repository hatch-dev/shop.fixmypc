<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchanges (phase 3): a return case whose items are swapped for another
 * variant of the same product instead of refunded.
 *
 * The swap is a new order (replacement_order_id), never an edit of the old
 * one: an order's money is read only from its own rows. The replacement
 * order carries only the difference the customer paid.
 */
class AddExchangesToOrderReturns extends Migration
{
    public function up()
    {
        Schema::table('order_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('order_returns', 'type')) {
                // return | exchange
                $table->string('type', 10)->default('return')->after('rma_number');
            }
            if (!Schema::hasColumn('order_returns', 'replacement_order_id')) {
                $table->unsignedInteger('replacement_order_id')->nullable()->after('order_id');
            }
            if (!Schema::hasColumn('order_returns', 'exchange_difference')) {
                // What the swap was worth at the time: > 0 the customer owed it, < 0 they were owed it.
                $table->decimal('exchange_difference', 12, 2)->nullable()->after('refund_amount');
            }
            if (!Schema::hasColumn('order_returns', 'amount_charged')) {
                $table->decimal('amount_charged', 12, 2)->nullable()->after('exchange_difference');
            }
            if (!Schema::hasColumn('order_returns', 'payment_reference')) {
                $table->string('payment_reference', 255)->nullable()->after('amount_charged');
            }
        });

        Schema::table('order_return_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_return_items', 'exchange_inventory_id')) {
                $table->unsignedInteger('exchange_inventory_id')->nullable()->after('inventory_id');
            }
            if (!Schema::hasColumn('order_return_items', 'exchange_unit_price')) {
                // The new variant's price, as charged gross, when the swap was made.
                $table->decimal('exchange_unit_price', 12, 2)->nullable()->after('exchange_inventory_id');
            }
        });
    }

    public function down()
    {
        Schema::table('order_return_items', function (Blueprint $table) {
            $table->dropColumn(['exchange_inventory_id', 'exchange_unit_price']);
        });

        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropColumn(['type', 'replacement_order_id', 'exchange_difference', 'amount_charged', 'payment_reference']);
        });
    }
}
