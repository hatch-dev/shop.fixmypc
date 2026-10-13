<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock rows that were saved with a negative quantity.
 *
 * UpdatedInventoriesController::action() validated nothing beyond "the request
 * has an inventories key", so a negative quantity was written as typed. The
 * storefront reads quantity < 1 as out of stock, so these rows were already
 * unsellable; clamping them to 0 says the same thing in a value the rest of
 * the system can add up. Validation::updatedInventory now rejects new ones.
 */
class ClampNegativeInventoryQuantities extends Migration
{
    public function up()
    {
        $affected = DB::table('updated_inventories')
            ->where('quantity', '<', 0)
            ->update(['quantity' => 0]);

        // Negative prices have never been observed, but the same gap allowed
        // them and a negative price reaches the cart rather than hiding.
        $affected += DB::table('updated_inventories')
            ->where('price', '<', 0)
            ->update(['price' => 0]);

        if ($affected) {
            echo "  clamped {$affected} inventory row(s)\n";
        }
    }

    public function down()
    {
        // The original negative values are not recoverable, and restoring them
        // would only put unsellable rows back.
    }
}
