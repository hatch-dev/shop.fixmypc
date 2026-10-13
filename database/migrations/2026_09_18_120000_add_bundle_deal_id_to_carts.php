<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Essentials Bundle a basket line came in under.
 *
 * A REFERENCE, never an amount. carts.price is rewritten from the catalogue on
 * every pricing pass -- the basket read, add-to-cart, every quantity change,
 * the voucher preview, checkout twice and the sign-in merge -- so a discounted
 * figure written onto a line is undone by the next pass. The line may only
 * remember which rule it arrived under; the discount itself is re-derived from
 * this column wherever money is worked out.
 *
 * Nullable, because almost every line is an ordinary one. ON DELETE SET NULL
 * rather than CASCADE: deleting a bundle must retire the offer, not empty
 * somebody's basket.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('carts', 'bundle_deal_id')) {
            return;
        }

        Schema::table('carts', function (Blueprint $table) {
            // unsignedInteger, not unsignedBigInteger: bundle_deals.id is
            // int(10) unsigned, and MySQL refuses a foreign key whose types
            // do not match exactly ("Foreign key constraint is incorrectly
            // formed", errno 150).
            $table->unsignedInteger('bundle_deal_id')->nullable()->after('inventory_id');

            // The discount groups by it on every basket read.
            $table->index('bundle_deal_id');

            $table->foreign('bundle_deal_id')
                ->references('id')->on('bundle_deals')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('carts', 'bundle_deal_id')) {
            return;
        }

        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['bundle_deal_id']);
            $table->dropIndex(['bundle_deal_id']);
            $table->dropColumn('bundle_deal_id');
        });
    }
};
