<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid membership, billed by Stripe Subscriptions (2026-10-15).
 *
 *   membership_plans          what can be bought, managed in the admin panel;
 *                             each plan is mirrored to a Stripe Product + Price
 *   membership_plan_benefits  what a plan gives: free delivery, a % off, or a
 *                             line of copy (display) for the storefront
 *   user_memberships          one row per subscription a customer has held,
 *                             or per membership an admin granted
 *   membership_invoices       what Stripe billed for each period
 *   membership_events         who did what to a membership, for the admin
 *
 * A member discount is written to ordered_loyalty_discount: an order takes
 * the bigger of the loyalty group and membership discounts, never both, so
 * the one slot every receipt already prints is enough. loyalty_group_id
 * becomes nullable and user_membership_id says which membership paid.
 */
class CreateMembershipTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('membership_plans')) {
            Schema::create('membership_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 120)->unique();
                $table->string('tagline', 255)->nullable();
                $table->decimal('price', 10, 2);
                $table->string('currency', 3)->default('EUR');
                $table->string('interval', 10)->default('month');
                $table->unsignedTinyInteger('interval_count')->default(1);
                $table->unsignedSmallInteger('trial_days')->default(0);
                $table->boolean('status')->default(false);
                $table->integer('sort_order')->default(0);
                $table->string('stripe_product_id')->nullable();
                $table->string('stripe_price_id')->nullable();
                // price|currency|interval|count the stored Stripe price was made for
                $table->string('stripe_price_key')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('membership_plan_benefits')) {
            Schema::create('membership_plan_benefits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('membership_plan_id')->constrained('membership_plans')->cascadeOnDelete();
                $table->string('type', 30)->default('display');
                $table->string('title', 100);
                $table->string('subtitle', 255)->nullable();
                $table->string('icon', 60)->nullable();
                $table->boolean('highlight')->default(false);
                $table->decimal('percent', 5, 2)->nullable();
                $table->decimal('min_order', 10, 2)->nullable();
                $table->decimal('max_discount', 10, 2)->nullable();
                $table->boolean('include_sale_items')->default(false);
                $table->boolean('include_business_prices')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_memberships')) {
            Schema::create('user_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('membership_plan_id')->constrained('membership_plans');
                $table->string('source', 10)->default('stripe');
                $table->string('status', 30);
                $table->string('stripe_subscription_id')->nullable()->unique();
                $table->string('stripe_customer_id')->nullable();
                $table->string('stripe_checkout_session_id')->nullable()->unique();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->string('interval', 10)->nullable();
                $table->unsignedTinyInteger('interval_count')->default(1);
                $table->dateTime('current_period_start')->nullable();
                $table->dateTime('current_period_end')->nullable();
                $table->dateTime('trial_ends_at')->nullable();
                $table->boolean('cancel_at_period_end')->default(false);
                $table->dateTime('canceled_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->unsignedInteger('granted_by_admin_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->dateTime('last_synced_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }

        if (!Schema::hasTable('membership_invoices')) {
            Schema::create('membership_invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_membership_id')->constrained('user_memberships')->cascadeOnDelete();
                $table->string('stripe_invoice_id')->unique();
                $table->string('number')->nullable();
                $table->string('status', 20);
                $table->decimal('amount_due', 10, 2)->default(0);
                $table->decimal('amount_paid', 10, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->dateTime('period_start')->nullable();
                $table->dateTime('period_end')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->string('hosted_invoice_url', 500)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('membership_events')) {
            Schema::create('membership_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_membership_id')->constrained('user_memberships')->cascadeOnDelete();
                $table->unsignedInteger('admin_id')->nullable();
                $table->string('action', 40);
                $table->string('detail', 255)->nullable();
                $table->timestamps();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->index();
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'stripe_webhook_secret')) {
                $table->string('stripe_webhook_secret')->nullable();
            }
        });

        Schema::table('ordered_loyalty_discount', function (Blueprint $table) {
            $table->unsignedBigInteger('loyalty_group_id')->nullable()->change();

            if (!Schema::hasColumn('ordered_loyalty_discount', 'user_membership_id')) {
                $table->unsignedBigInteger('user_membership_id')->nullable()->index();
            }
        });
    }

    public function down()
    {
        Schema::table('ordered_loyalty_discount', function (Blueprint $table) {
            if (Schema::hasColumn('ordered_loyalty_discount', 'user_membership_id')) {
                $table->dropColumn('user_membership_id');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'stripe_webhook_secret')) {
                $table->dropColumn('stripe_webhook_secret');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'stripe_customer_id')) {
                $table->dropColumn('stripe_customer_id');
            }
        });

        Schema::dropIfExists('membership_events');
        Schema::dropIfExists('membership_invoices');
        Schema::dropIfExists('user_memberships');
        Schema::dropIfExists('membership_plan_benefits');
        Schema::dropIfExists('membership_plans');
    }
}
