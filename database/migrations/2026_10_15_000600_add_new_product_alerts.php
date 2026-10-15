<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New-product emails for members (2026-10-15).
 *
 * Members whose plan has the early_access benefit are emailed when a new
 * product goes on sale (Helper\NewProductAlerts). Everyone can buy it as
 * normal; the email is the benefit.
 *
 *   products.first_published_at    when it first went on sale
 *   products.members_notified_at   when members were emailed about it
 *   users.member_digest_opt_out    the member turned these emails off
 *   new_product_alerts             one row per email run, for the record
 *
 * Products already on sale are marked published and notified, so going live
 * does not email members about the whole catalogue.
 */
class AddNewProductAlerts extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'first_published_at')) {
                $table->dateTime('first_published_at')->nullable();
            }
            if (!Schema::hasColumn('products', 'members_notified_at')) {
                $table->dateTime('members_notified_at')->nullable()->index();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'member_digest_opt_out')) {
                $table->boolean('member_digest_opt_out')->default(false);
            }
        });

        if (!Schema::hasTable('new_product_alerts')) {
            Schema::create('new_product_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('products_count')->default(0);
                $table->unsignedInteger('recipients_count')->default(0);
                $table->string('product_ids', 1000)->nullable();
                $table->timestamps();
            });
        }

        DB::table('products')
            ->where('status', 1)
            ->whereNull('first_published_at')
            ->update([
                'first_published_at' => DB::raw('COALESCE(created_at, NOW())'),
                'members_notified_at' => DB::raw('NOW()'),
            ]);

        // The "Early Access" wording the plan started with becomes the real benefit.
        DB::table('membership_plan_benefits')
            ->where('type', 'display')
            ->where('title', 'like', 'Early Access%')
            ->update(['type' => 'early_access']);
    }

    public function down()
    {
        Schema::dropIfExists('new_product_alerts');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'member_digest_opt_out')) {
                $table->dropColumn('member_digest_opt_out');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            foreach (['members_notified_at', 'first_published_at'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        DB::table('membership_plan_benefits')->where('type', 'early_access')->update(['type' => 'display']);
    }
}
