<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Something went wrong" on saving any product that is not on offer.
 *
 * ProductsController::action() writes null to vatInclusiveOfferedPrice when a
 * product has no offer price - deliberately, because the column previously held
 * 0.00 beside a real offered price and nothing could tell "no offer" from
 * "offer of nothing". QaAuditRemediate writes the same null. But the column was
 * left NOT NULL, and this app runs STRICT_TRANS_TABLES, so every one of those
 * writes was rejected at the database and surfaced to the admin as the generic
 * failure message. A product could only be created if it had an offer price.
 *
 * excludeVAT has the same shape of problem from the other direction: the
 * controller assigns $request->excludeVAT straight through, so a payload that
 * omits it sends null into a NOT NULL column. The column keeps NOT NULL - "is
 * this price quoted ex-VAT" has no third answer - and gains the default the
 * controller has always assumed.
 *
 * Every reader of vatInclusiveOfferedPrice already casts with (float), which
 * reads null as 0.0, so existing "> 0 ? offer : list" checks are unaffected.
 */
class FixProductPriceColumnNullability extends Migration
{
    public function up()
    {
        // Rows that mean "no offer" but were forced to 0.00 by the old column.
        DB::statement(
            'ALTER TABLE `products`
             MODIFY `vatInclusiveOfferedPrice` DECIMAL(10,2) NULL DEFAULT NULL'
        );

        DB::statement(
            'ALTER TABLE `products`
             MODIFY `excludeVAT` TINYINT(1) NOT NULL DEFAULT 0'
        );

        DB::table('products')
            ->where('offered', '<=', 0)
            ->orWhereNull('offered')
            ->update(['vatInclusiveOfferedPrice' => null]);
    }

    public function down()
    {
        DB::table('products')
            ->whereNull('vatInclusiveOfferedPrice')
            ->update(['vatInclusiveOfferedPrice' => 0]);

        DB::statement(
            'ALTER TABLE `products`
             MODIFY `vatInclusiveOfferedPrice` DECIMAL(10,2) NOT NULL DEFAULT 0.00'
        );
    }
}
