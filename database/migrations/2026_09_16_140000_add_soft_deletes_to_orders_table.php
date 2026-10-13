<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make deleting an order recoverable.
 *
 * Deleting an order removed the row, its line items, its payment record, its
 * voucher and flash-discount rows and its whole status history. There was no
 * undo and nothing left afterwards to say the order had ever existed — a
 * mis-click on the wrong row in the list destroyed a financial record.
 *
 * The order now leaves the list but stays in the database. Everything that
 * reads orders through Eloquent gets the exclusion from the SoftDeletes global
 * scope; the reporting queries that join `orders` directly exclude it by hand
 * (see Order::withoutDeleted), so a deleted order cannot reappear in revenue.
 *
 * Indexed because it is now part of the predicate on every order query.
 */
class AddSoftDeletesToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('deleted_at');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
}
