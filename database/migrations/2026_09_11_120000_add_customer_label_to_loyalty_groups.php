<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group's `title` is what the admin calls it in the back office -- "Group 1",
 * "test", "trade tier B". It was being printed straight onto the shopper's
 * order summary, which is how "Group 1  - EUR 250.00" ended up in the basket.
 *
 * `customer_label` is the line the shopper reads. It is separate on purpose:
 * renaming a group for the admin's own filing should never change the
 * storefront, and an internal name should never leak to a customer. When it is
 * left empty the storefront falls back to a neutral "Loyalty Discount" rather
 * than to the internal title.
 */
class AddCustomerLabelToLoyaltyGroups extends Migration
{
    public function up()
    {
        Schema::table('loyalty_groups', function (Blueprint $table) {
            $table->string('customer_label')->nullable()->after('title');
        });
    }

    public function down()
    {
        Schema::table('loyalty_groups', function (Blueprint $table) {
            $table->dropColumn('customer_label');
        });
    }
}
