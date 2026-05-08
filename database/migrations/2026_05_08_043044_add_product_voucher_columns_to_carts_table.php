<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProductVoucherColumnsToCartsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('voucher_code')
                ->nullable()
                ->after('price');

            $table->decimal('voucher_discount', 12, 2)
                ->default(0)
                ->after('voucher_code');

            $table->decimal('original_price', 12, 2)
                ->nullable()
                ->after('voucher_discount');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn([
                'voucher_code',
                'voucher_discount',
                'original_price'
            ]);
        });
    }
}
