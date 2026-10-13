<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each order line was treated for VAT, frozen at checkout.
 *
 * `ordered_products.tax_price` records the VAT *added* to a line, which is
 * enough only for the products whose prices are quoted ex-VAT. For the 111
 * products whose prices are quoted inc-VAT it is zero — not because there is
 * no VAT, but because the VAT is already inside the price and nothing had to
 * be added. Those two cases are indistinguishable after the fact.
 *
 * Without knowing which it was, a screen showing "Ex-VAT / VAT / Inc-VAT"
 * would have to read products.excludeVAT and the tax rule as they stand today
 * — and an admin changing either would silently restate the VAT on every
 * invoice this shop has ever issued. The rate and the convention therefore go
 * onto the line, next to the price they describe.
 *
 * Both nullable: an order placed before this migration genuinely does not
 * record them, and the summary falls back to "the price as charged, plus
 * whatever tax was added" rather than inventing a rate it never knew.
 */
class AddVatTreatmentToOrderedProductsTable extends Migration
{
    public function up()
    {
        Schema::table('ordered_products', function (Blueprint $table) {
            // Percentage, e.g. 23.00. Null when the order predates this.
            $table->decimal('tax_rate', 8, 2)->nullable()->after('tax_price');

            // 1 when the charged price already contained the VAT.
            $table->boolean('tax_inclusive')->nullable()->after('tax_rate');
        });
    }

    public function down()
    {
        Schema::table('ordered_products', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'tax_inclusive']);
        });
    }
}
