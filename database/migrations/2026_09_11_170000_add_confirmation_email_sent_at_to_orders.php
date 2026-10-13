<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the order confirmation was emailed, so it goes out exactly once.
 *
 * The confirmation is now sent as soon as the order exists, for every payment
 * method and for guests as well as signed-in customers. Two things could
 * otherwise send it twice: the creation branch, and markOrderPaid() when the
 * gateway later confirms the payment. Recording the moment it went out lets
 * both call sites stay where they are and still produce one email.
 *
 * Existing orders are backfilled as already-sent. They were placed under the
 * old behaviour and their customers have had whatever mail they were going to
 * get; leaving the column null would mean any later status change re-sent a
 * confirmation for an order from weeks ago.
 */
class AddConfirmationEmailSentAtToOrders extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('confirmation_email_sent_at')->nullable()->after('viewed');
        });

        DB::statement('UPDATE orders SET confirmation_email_sent_at = created_at');
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('confirmation_email_sent_at');
        });
    }
}
