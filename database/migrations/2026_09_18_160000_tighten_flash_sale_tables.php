<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Constraints and indexes the flash sale tables never had.
 *
 * Three things, all of them things the application was relying on and none of
 * them enforced:
 *
 * A product appears at most once in a sale. The admin form deduplicated in
 * the browser and the API did not, so any direct call stored the same product
 * twice and the storefront joined to both rows.
 *
 * Every read of a flash sale asks the same question -- is this one live right
 * now -- across status, start_time and end_time. Twenty query sites, no index.
 *
 * And `lang` was TEXT, which MySQL will not index without a prefix length, on
 * a column that only ever holds a locale code and is in the join condition of
 * every translated read.
 */
class TightenFlashSaleTables extends Migration
{
    public function up()
    {
        /*
         * Any duplicate already stored has to go before the unique index can
         * exist. The lowest id wins -- it is the row the admin screen shows,
         * because the storefront and the editor both read them in id order.
         */
        $duplicates = DB::table('flash_sale_products')
            ->select('flash_sale_id', 'product_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('flash_sale_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('flash_sale_products')
                ->where('flash_sale_id', $duplicate->flash_sale_id)
                ->where('product_id', $duplicate->product_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('flash_sale_products', function (Blueprint $table) {
            $table->unique(['flash_sale_id', 'product_id'], 'flash_sale_products_sale_product_unique');
        });

        Schema::table('flash_sales', function (Blueprint $table) {
            $table->index(['status', 'start_time', 'end_time'], 'flash_sales_window_index');
        });

        // Long enough for any locale code this app stores ('en', 'pt-BR').
        DB::statement('ALTER TABLE `flash_sale_langs` MODIFY `lang` VARCHAR(20) NOT NULL DEFAULT ""');

        Schema::table('flash_sale_langs', function (Blueprint $table) {
            $table->unique(['flash_sale_id', 'lang'], 'flash_sale_langs_sale_lang_unique');
        });
    }

    public function down()
    {
        Schema::table('flash_sale_langs', function (Blueprint $table) {
            $table->dropUnique('flash_sale_langs_sale_lang_unique');
        });

        DB::statement('ALTER TABLE `flash_sale_langs` MODIFY `lang` TEXT NOT NULL');

        Schema::table('flash_sales', function (Blueprint $table) {
            $table->dropIndex('flash_sales_window_index');
        });

        Schema::table('flash_sale_products', function (Blueprint $table) {
            $table->dropUnique('flash_sale_products_sale_product_unique');
        });
    }
}
