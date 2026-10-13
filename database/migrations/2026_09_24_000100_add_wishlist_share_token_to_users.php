<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The secret behind a customer's shared-wishlist link
 * (/wishlist/shared/{token}). Null means the list is not shared; clearing it
 * is how "Stop sharing" kills a link. See WishlistShareController.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('users', 'wishlist_share_token')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('wishlist_share_token', 40)->nullable()->unique();
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('users', 'wishlist_share_token')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['wishlist_share_token']);
            $table->dropColumn('wishlist_share_token');
        });
    }
};
