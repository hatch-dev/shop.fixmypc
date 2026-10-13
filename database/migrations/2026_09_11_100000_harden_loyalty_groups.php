<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The loyalty group feature shipped as an admin-only CRUD: a group carried a
 * discount and a list of users, and nothing ever spent it. This migration adds
 * the columns the discount actually needs to be applied, capped, expired and
 * reversed.
 *
 *  - status / min_cart_value / max_discount: a percentage group with no cap
 *    and no floor can discount an order to zero, and there was no way to park
 *    a group without deleting it.
 *  - joined_at: "30 days from joining" had nothing to count from. The pivot's
 *    created_at was the only candidate, and action() deleted and re-inserted
 *    every pivot row on each save, so editing a group silently restarted every
 *    member's window.
 *  - used_at / used_order_id: "valid for next order only" was unenforceable
 *    without recording the redemption.
 *  - ordered_loyalty_discount: mirrors ordered_flash_discount so a cancelled
 *    or refunded order can give the redemption back.
 */
class HardenLoyaltyGroups extends Migration
{
    public function up()
    {
        Schema::table('loyalty_groups', function (Blueprint $table) {
            $table->boolean('status')->default(1)->after('title');
            $table->decimal('min_cart_value', 10, 2)->nullable()->after('discount_value');
            $table->decimal('max_discount', 10, 2)->nullable()->after('min_cart_value');
        });

        Schema::table('loyalty_group_users', function (Blueprint $table) {
            $table->timestamp('joined_at')->nullable()->after('user_id');
            $table->timestamp('expires_at')->nullable()->after('joined_at');
            $table->timestamp('used_at')->nullable()->after('expires_at');
            $table->unsignedBigInteger('used_order_id')->nullable()->after('used_at');

            $table->unique(['loyalty_group_id', 'user_id'], 'loyalty_group_user_unique');
            $table->index(['user_id', 'used_at'], 'loyalty_group_user_lookup');
        });

        // Existing members joined when their pivot row was written.
        DB::statement('UPDATE loyalty_group_users SET joined_at = created_at WHERE joined_at IS NULL');

        Schema::create('ordered_loyalty_discount', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('loyalty_group_id');
            $table->unsignedBigInteger('loyalty_group_user_id')->nullable();
            $table->decimal('discount', 10, 2)->default(0);
            $table->timestamps();

            $table->index('order_id');
            $table->index('loyalty_group_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ordered_loyalty_discount');

        Schema::table('loyalty_group_users', function (Blueprint $table) {
            $table->dropUnique('loyalty_group_user_unique');
            $table->dropIndex('loyalty_group_user_lookup');
            $table->dropColumn(['joined_at', 'expires_at', 'used_at', 'used_order_id']);
        });

        Schema::table('loyalty_groups', function (Blueprint $table) {
            $table->dropColumn(['status', 'min_cart_value', 'max_discount']);
        });
    }
}
