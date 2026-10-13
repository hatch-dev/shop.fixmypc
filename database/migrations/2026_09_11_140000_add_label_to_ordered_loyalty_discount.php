<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The wording a past order was discounted under, frozen at the moment it was
 * redeemed.
 *
 * Reading the label back through the live loyalty_groups row would mean a
 * merchant renaming "Gold Member Discount" to "Autumn Promo" silently rewrites
 * every receipt, order email and invoice ever issued under it. A receipt is a
 * record of what happened, so it keeps its own copy.
 */
class AddLabelToOrderedLoyaltyDiscount extends Migration
{
    public function up()
    {
        Schema::table('ordered_loyalty_discount', function (Blueprint $table) {
            $table->string('label')->nullable()->after('loyalty_group_user_id');
        });

        // Backfill the few rows that predate this from their group's current copy.
        DB::statement("
            UPDATE ordered_loyalty_discount o
            JOIN loyalty_groups g ON g.id = o.loyalty_group_id
            SET o.label = COALESCE(NULLIF(TRIM(g.customer_label), ''), 'Loyalty Discount')
            WHERE o.label IS NULL
        ");
    }

    public function down()
    {
        Schema::table('ordered_loyalty_discount', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
}
