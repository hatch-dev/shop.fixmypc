<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A wishlist belongs to a guest as well as to a signed-in customer.
 *
 * The cart already works this way: rows are owned either by `user_id` or by the
 * server-issued `user_token`, and the token's rows are adopted on login. The
 * wishlist is now the same, so adding to it no longer forces a sign-in.
 */
class AddUserTokenToUserWishlistsTable extends Migration
{
    public function up()
    {
        Schema::table('user_wishlists', function (Blueprint $table) {
            if (!Schema::hasColumn('user_wishlists', 'user_token')) {
                $table->string('user_token')->nullable()->after('user_id')->index();
            }
        });

        // A guest row has no user_id, and the column was created NOT NULL.
        // Done in raw SQL rather than ->change() so it works without
        // doctrine/dbal being installed.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `user_wishlists` MODIFY `user_id` BIGINT UNSIGNED NULL');
        }
    }

    public function down()
    {
        Schema::table('user_wishlists', function (Blueprint $table) {
            if (Schema::hasColumn('user_wishlists', 'user_token')) {
                $table->dropIndex(['user_token']);
                $table->dropColumn('user_token');
            }
        });
    }
}
