<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an order was charged under a flash discount, protected.
 *
 * Two things, both of which let the promotion table rewrite order history:
 *
 * ordered_flash_discount.flash_discount_id was ON DELETE CASCADE, so deleting
 * a finished promotion deleted the row every affected order's total is
 * rebuilt from -- OrderSummary sums this table. The order silently gained back
 * the discount. ordered_bundle_discount already uses ON DELETE SET NULL for
 * exactly this reason, and says so in its own migration; this matches it.
 *
 * And nothing stopped a second row landing against the same order. A retried
 * checkout wrote two, and because OrderSummary sums them the discount doubled.
 *
 * Indexes on flash_discounts as well: scopeActive runs on every basket page
 * load and every order placed, against three columns with no index between
 * them.
 */
class ProtectOrderedFlashDiscount extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('ordered_flash_discount')) {
            return;
        }

        /*
         * Any duplicate already stored has to go before the unique index can
         * exist. The lowest id wins: it is the row the original checkout
         * wrote, the later ones are the retry.
         */
        $duplicates = DB::table('ordered_flash_discount')
            ->select('order_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('ordered_flash_discount')
                ->where('order_id', $duplicate->order_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('ordered_flash_discount', function (Blueprint $table) {
            $table->dropForeign(['flash_discount_id']);
        });

        // Nullable is what ON DELETE SET NULL needs somewhere to put.
        DB::statement('ALTER TABLE `ordered_flash_discount`
            MODIFY `flash_discount_id` BIGINT UNSIGNED NULL');

        Schema::table('ordered_flash_discount', function (Blueprint $table) {

            $table->foreign('flash_discount_id')
                ->references('id')->on('flash_discounts')
                ->nullOnDelete();

            $table->unique('order_id', 'ordered_flash_discount_order_unique');
        });

        if (Schema::hasTable('flash_discounts')) {
            Schema::table('flash_discounts', function (Blueprint $table) {
                $table->index(['is_active', 'start_time', 'end_time'], 'flash_discounts_live_index');
            });
        }
    }

    public function down()
    {
        if (!Schema::hasTable('ordered_flash_discount')) {
            return;
        }

        Schema::table('ordered_flash_discount', function (Blueprint $table) {
            $table->dropUnique('ordered_flash_discount_order_unique');
            $table->dropForeign(['flash_discount_id']);
        });

        DB::statement('ALTER TABLE `ordered_flash_discount`
            MODIFY `flash_discount_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('ordered_flash_discount', function (Blueprint $table) {
            $table->foreign('flash_discount_id')
                ->references('id')->on('flash_discounts')
                ->onDelete('cascade');
        });

        if (Schema::hasTable('flash_discounts')) {
            Schema::table('flash_discounts', function (Blueprint $table) {
                $table->dropIndex('flash_discounts_live_index');
            });
        }
    }
}
