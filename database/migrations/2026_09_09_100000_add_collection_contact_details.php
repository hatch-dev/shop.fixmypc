<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Click-and-collect orders have no delivery address.
 *
 * The address record was doing double duty: it carried the shipping address
 * *and* it was the only place a guest ever supplied a name, email and phone.
 * Dropping it for collection orders would leave the store with no way to tell
 * the customer their order is ready, so the contact details move onto the order
 * itself (and onto the guest record, so they survive from the shipping step to
 * the moment the order is created).
 */
class AddCollectionContactDetails extends Migration
{
    public function up()
    {
        // A collection order legitimately has no address to point at.
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_user_address_id_foreign');
        });

        DB::statement('ALTER TABLE `orders` MODIFY `user_address_id` BIGINT UNSIGNED NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('user_address_id')
                ->references('id')
                ->on('user_addresses');
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('user_address_id');
            }
            if (!Schema::hasColumn('orders', 'contact_email')) {
                $table->string('contact_email')->nullable()->after('contact_name');
            }
            if (!Schema::hasColumn('orders', 'contact_phone')) {
                $table->string('contact_phone')->nullable()->after('contact_email');
            }
        });

        // guest_users already carries name and email; a pickup needs a phone too.
        Schema::table('guest_users', function (Blueprint $table) {
            if (!Schema::hasColumn('guest_users', 'phone')) {
                $table->string('phone')->nullable()->after('name');
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_email', 'contact_phone']);
        });

        Schema::table('guest_users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_user_address_id_foreign');
        });

        DB::statement('ALTER TABLE `orders` MODIFY `user_address_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('user_address_id')
                ->references('id')
                ->on('user_addresses');
        });
    }
}
