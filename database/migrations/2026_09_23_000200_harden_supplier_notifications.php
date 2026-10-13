<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * supplier_notifications had no key of any kind: no primary lookup index, no
 * foreign keys, and nothing stopping the same line being recorded twice.
 *
 * The duplicate guard in OrdersController is a read-then-write ("has this
 * supplier been told about this order?") which is exactly the shape that
 * loses a race, and a product reachable through two of one supplier's
 * categories used to write its row once per category. Both become
 * impossible with the index; notifySuppliers() uses firstOrCreate against it.
 *
 * NOTE on the unique key and NULL: inventory_id is nullable, and MySQL does
 * not consider two NULLs equal, so a variantless product can still record
 * twice. Nothing writes that path today -- every procurement line carries an
 * inventory_id -- and the alternative is a sentinel value that would have to
 * be understood by every reader of the table.
 */
class HardenSupplierNotifications extends Migration
{
    public function up()
    {
        /*
         * Existing duplicates first, or the index cannot be created. Keeps
         * the lowest id of each set, which is the row whose WhatsApp
         * actually went out.
         */
        $duplicateIds = DB::table('supplier_notifications as sn')
            ->join(DB::raw('(SELECT MIN(id) AS keep_id, supplier_id, product_id, inventory_id, order_id
                             FROM supplier_notifications
                             GROUP BY supplier_id, product_id, inventory_id, order_id
                             HAVING COUNT(*) > 1) AS dupes'), function ($join) {
                $join->on('sn.supplier_id', '=', 'dupes.supplier_id')
                     ->on('sn.product_id', '=', 'dupes.product_id')
                     ->on('sn.order_id', '=', 'dupes.order_id')
                     ->whereRaw('(sn.inventory_id <=> dupes.inventory_id)')
                     ->whereRaw('sn.id <> dupes.keep_id');
            })
            ->pluck('sn.id')
            ->all();

        if (!empty($duplicateIds)) {
            DB::table('supplier_bids')->whereIn('supplier_notification_id', $duplicateIds)->delete();
            DB::table('supplier_notifications')->whereIn('id', $duplicateIds)->delete();
        }

        Schema::table('supplier_notifications', function (Blueprint $table) {
            $table->unique(
                ['supplier_id', 'product_id', 'inventory_id', 'order_id'],
                'supplier_notifications_line_unique'
            );

            // The lookup every read of this table makes: "what was this
            // supplier asked about on this order?"
            $table->index(['order_id', 'supplier_id'], 'supplier_notifications_order_supplier_index');
        });
    }

    public function down()
    {
        Schema::table('supplier_notifications', function (Blueprint $table) {
            $table->dropUnique('supplier_notifications_line_unique');
            $table->dropIndex('supplier_notifications_order_supplier_index');
        });
    }
}
