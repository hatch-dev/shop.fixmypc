<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saved-for-later lines belong to a guest as well as to a signed-in customer.
 *
 * Same ownership rule as the cart and the wishlist: `user_id` when signed in,
 * otherwise the server-issued `user_token`. A guest's saved items used to go
 * into localStorage with nothing able to read them back.
 */
class AddUserTokenToSaveForLaterTable extends Migration
{
    public function up()
    {
        Schema::table('save_for_later', function (Blueprint $table) {
            if (!Schema::hasColumn('save_for_later', 'user_token')) {
                $table->string('user_token')->nullable()->after('user_id')->index();
            }
        });

        // A guest row has no user_id, and the column was created NOT NULL.
        // Raw SQL rather than ->change() so it runs without doctrine/dbal.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `save_for_later` MODIFY `user_id` BIGINT UNSIGNED NULL');
        }
    }

    public function down()
    {
        Schema::table('save_for_later', function (Blueprint $table) {
            if (Schema::hasColumn('save_for_later', 'user_token')) {
                $table->dropIndex(['user_token']);
                $table->dropColumn('user_token');
            }
        });
    }
}
