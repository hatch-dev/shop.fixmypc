<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turning a quote into a decision, and a decision into a payable.
 *
 * Until now a bid was only ever a number on a screen: nothing recorded which
 * supplier was chosen, so nothing downstream could say what a procured line
 * actually cost, and nobody could tell a settled invoice from an open one.
 *
 * Three facts, kept apart on purpose:
 *
 *   status/decided_*  which quote was taken, by whom, when
 *   cost_before       what ordered_products.purchased held before accepting,
 *                     so the decision can be undone without losing the
 *                     figure the sale was booked against
 *   paid_*            settlement, which is a separate event from the
 *                     decision and often days later
 *
 * Deliberately NOT a payment integration. The gateways configured here take
 * money from customers; paying a supplier is a bank transfer made outside
 * this system, so what belongs in the database is the record of it.
 */
class AddDecisionAndPaymentToSupplierBids extends Migration
{
    public function up()
    {
        Schema::table('supplier_bids', function (Blueprint $table) {
            $table->string('status', 16)->default('pending')->after('grade');

            $table->timestamp('decided_at')->nullable()->after('status');
            $table->unsignedBigInteger('decided_by')->nullable()->after('decided_at');
            $table->string('decline_reason', 255)->nullable()->after('decided_by');

            /*
             * The line's recorded cost before this bid was accepted. Accepting
             * writes the agreed price onto ordered_products.purchased so profit
             * reporting uses what the line really cost; this keeps the previous
             * value so rejecting afterwards can put it back.
             */
            $table->decimal('cost_before', 12, 2)->nullable()->after('decline_reason');

            $table->timestamp('paid_at')->nullable()->after('cost_before');
            $table->unsignedBigInteger('paid_by')->nullable()->after('paid_at');
            $table->decimal('paid_amount', 12, 2)->nullable()->after('paid_by');
            $table->string('payment_method', 40)->nullable()->after('paid_amount');
            $table->string('payment_reference', 120)->nullable()->after('payment_method');

            /*
             * "Which quotes are still open?" and "what is unpaid?" are the two
             * questions this table will be asked.
             */
            $table->index(['status', 'paid_at'], 'supplier_bids_status_paid_index');

            /*
             * One supplier per line. MySQL has no partial unique index, so
             * "only one ACCEPTED bid per line" is enforced in the controller
             * inside a transaction -- this index is what makes finding the
             * competing bids for a line cheap.
             */
            $table->index(['order_id', 'product_id', 'inventory_id'], 'supplier_bids_line_index');
        });
    }

    public function down()
    {
        Schema::table('supplier_bids', function (Blueprint $table) {
            $table->dropIndex('supplier_bids_status_paid_index');
            $table->dropIndex('supplier_bids_line_index');

            $table->dropColumn([
                'status', 'decided_at', 'decided_by', 'decline_reason', 'cost_before',
                'paid_at', 'paid_by', 'paid_amount', 'payment_method', 'payment_reference',
            ]);
        });
    }
}
