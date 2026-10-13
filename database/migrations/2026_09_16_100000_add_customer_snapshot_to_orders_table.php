<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the customer was when the order was placed.
 *
 * The order confirmation has to say "Account Type: Business" beside a company
 * name, a VAT number and a CRO number, and the only place those live is
 * user_business_profiles — a table the customer can edit and staff can change
 * the status of. Reading it live means a renamed company, a corrected VAT
 * number or a trade approval that is later withdrawn silently rewrites every
 * receipt the customer has ever been sent.
 *
 * The same reasoning already froze the loyalty group's wording onto
 * ordered_loyalty_discounts.label, and the same reasoning freezes the prices
 * onto ordered_products_inventories. This is the customer half of that record.
 *
 * Nullable throughout: personal accounts and guests have none of it, and every
 * order placed before this migration has none of it either.
 */
class AddCustomerSnapshotToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('account_type')->nullable()->after('purchase_order_number');
            $table->string('company_name')->nullable()->after('account_type');
            $table->string('vat_number')->nullable()->after('company_name');
            $table->string('cro_number')->nullable()->after('vat_number');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'company_name', 'vat_number', 'cro_number']);
        });
    }
}
