<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many units a flash sale product may be sold at its sale price.
 *
 * Nullable, and null means no cap -- every row that exists today carries on
 * exactly as it does now, and the allowance only engages once somebody enters
 * a number.
 *
 * quantity_sold is the tally that allowance is measured against. It is
 * derived from the orders, but it is stored because the alternative is a
 * correlated COUNT over ordered_products_inventories on all twenty of the
 * queries that decide whether a flash price applies -- including the basket
 * and the product page. Helper\FlashSaleAllowance recomputes it from the
 * orders whenever one is placed or cancelled rather than incrementing it, so
 * it cannot drift the way a running total does. products.purchased is the
 * same pattern.
 */
class AddQuantityLimitToFlashSaleProducts extends Migration
{
    public function up()
    {
        Schema::table('flash_sale_products', function (Blueprint $table) {
            $table->unsignedInteger('quantity_limit')->nullable()->after('price');
            $table->unsignedInteger('quantity_sold')->default(0)->after('quantity_limit');
        });

        /*
         * Seed the tally from the orders already placed, so a limit added to
         * an existing sale counts what that sale has already sold rather than
         * starting from zero.
         *
         * Cancelled is the flag OR status 6, which is what every other count
         * in this codebase means by it.
         */
        DB::statement('
            UPDATE flash_sale_products fsp
            JOIN flash_sales fs ON fs.id = fsp.flash_sale_id
            SET fsp.quantity_sold = COALESCE((
                SELECT SUM(opi.quantity)
                FROM ordered_products_inventories opi
                JOIN orders o ON o.id = opi.order_id
                WHERE opi.product_id = fsp.product_id
                  AND o.cancelled != 1
                  AND o.status != 6
                  AND o.created_at >= fs.start_time
                  AND o.created_at <= fs.end_time
            ), 0)
        ');
    }

    public function down()
    {
        Schema::table('flash_sale_products', function (Blueprint $table) {
            $table->dropColumn(['quantity_limit', 'quantity_sold']);
        });
    }
}
