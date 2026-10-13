<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unit price a basket line had when the shopper last chose it (added it,
 * changed its quantity or variant) - the "price changed" notice.
 *
 * carts.price cannot do this: it is rewritten to the current price on every
 * basket read (CartsController::repriceForCustomer), so it never remembers
 * what the shopper saw. added_price only moves when the shopper acts, so a
 * line whose price has moved since then can say "was X, now Y" for as long
 * as it is in the basket. Null on lines from before this column; the first
 * read gives them a baseline without showing a notice.
 */
class AddAddedPriceToCarts extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('carts', 'added_price')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->decimal('added_price', 12, 2)->nullable()->after('original_price');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('carts', 'added_price')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropColumn('added_price');
            });
        }
    }
}
