<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a flash sale reduction is expressed, so it can scale across variants.
 *
 * flash_sale_products held one absolute `price` per PRODUCT, and a product
 * like the iPhone 15 Pro has 36 variants in three price tiers -- 699, 799
 * and 999. One price of 648 across all of them is a 7% reduction on the
 * cheapest and a 35% one on the dearest, and it simultaneously made a
 * genuine discount on the dearest impossible, because the ceiling was the
 * product-level price.
 *
 * A percentage or a per-unit amount scales by itself. `price` stays, both
 * as the headline figure and as the value for a 'fixed_price' row, which is
 * what every row that exists today becomes -- so a simple product behaves
 * exactly as it does now.
 *
 * clearances already stores discount_type/discount_value; this is the same
 * shape, deliberately.
 */
class AddDiscountShapeToFlashSaleProducts extends Migration
{
    public function up()
    {
        Schema::table('flash_sale_products', function (Blueprint $table) {
            $table->string('discount_type', 20)->default('fixed_price')->after('price');
            $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
        });

        /*
         * Every existing row is an absolute price, so say so explicitly
         * rather than leaving the column to its default and hoping.
         */
        DB::table('flash_sale_products')->update([
            'discount_type' => 'fixed_price',
            'discount_value' => DB::raw('`price`'),
        ]);
    }

    public function down()
    {
        Schema::table('flash_sale_products', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
}
