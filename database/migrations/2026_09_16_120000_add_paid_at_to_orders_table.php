<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an order was paid.
 *
 * `payment_done` is a boolean, so the only thing an order could say was
 * "paid" or "not paid" — never when. The admin order screen is expected to
 * show a payment date beside the method and the amount, and there was nothing
 * to show; `updated_at` is not it, because any later edit moves that.
 *
 * It also gives a manual "mark as paid" somewhere to land. An admin asserting
 * that money arrived is a financial claim, and until now it left no trace at
 * all beyond flipping a bit.
 *
 * Nullable, and deliberately not backfilled: every order paid before this
 * migration genuinely has no recorded payment time, and inventing one from
 * updated_at would put a confident wrong date on a receipt. The screen shows
 * nothing rather than a guess.
 */
class AddPaidAtToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('payment_done');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
}
