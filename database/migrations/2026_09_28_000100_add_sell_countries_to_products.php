<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sell only in" for a product: ISO country codes, comma separated ("IE,GB").
 *
 * NULL means the product can be sold anywhere a shipping rule delivers to.
 * Replaces the product's own shipping rule as the way a product is limited to
 * a country; products.shipping_rule_id is left in place for history but is no
 * longer read when pricing or accepting an order (see Helper\Shipping).
 */
class AddSellCountriesToProducts extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('products', 'sell_countries')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('sell_countries')->nullable()->after('shipping_rule_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('products', 'sell_countries')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('sell_countries');
            });
        }
    }
}
