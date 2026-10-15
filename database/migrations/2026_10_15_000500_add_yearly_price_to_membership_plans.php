<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan is offered monthly, yearly, or both (2026-10-15): the customer picks
 * the billing period when they join.
 *
 *   price          the monthly price, null when not offered monthly
 *   yearly_price   the yearly price, null when not offered yearly
 *
 * Each has its own Stripe Price. interval/interval_count stay on the table
 * for existing rows but no longer drive anything on the plan; what a member
 * pays is still copied onto user_memberships.
 */
class AddYearlyPriceToMembershipPlans extends Migration
{
    public function up()
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->change();

            if (!Schema::hasColumn('membership_plans', 'yearly_price')) {
                $table->decimal('yearly_price', 10, 2)->nullable()->after('price');
            }

            if (!Schema::hasColumn('membership_plans', 'stripe_yearly_price_id')) {
                $table->string('stripe_yearly_price_id')->nullable()->after('stripe_price_key');
                $table->string('stripe_yearly_price_key')->nullable()->after('stripe_yearly_price_id');
            }
        });
    }

    public function down()
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            foreach (['yearly_price', 'stripe_yearly_price_id', 'stripe_yearly_price_key'] as $column) {
                if (Schema::hasColumn('membership_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
