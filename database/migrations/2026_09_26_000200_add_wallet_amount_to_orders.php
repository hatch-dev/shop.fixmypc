<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split payment: part of an order paid from the customer's wallet, the rest
 * by the chosen method (card, bank transfer, cash...). See
 * App\Models\Helper\WalletSplit.
 *
 *  - orders.wallet_amount: the wallet part. total_amount stays the whole
 *    order; the method is charged total_amount - wallet_amount.
 *  - wallet_transactions.source gains 'order_release': the wallet part
 *    given back when an order dies unpaid (card refused, bank transfer never
 *    arrived, cancelled). A paid order's money goes back as 'refund'.
 */
class AddWalletAmountToOrders extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'wallet_amount')) {
                $table->decimal('wallet_amount', 10, 2)->default(0)->after('total_amount');
            }
        });

        DB::statement("ALTER TABLE wallet_transactions MODIFY source
            ENUM('topup','order','refund','admin','gift_voucher','gift_card','order_release') NOT NULL");
    }

    public function down()
    {
        // Only safe once no release rows remain; they are money movements.
        if (!DB::table('wallet_transactions')->where('source', 'order_release')->exists()) {
            DB::statement("ALTER TABLE wallet_transactions MODIFY source
                ENUM('topup','order','refund','admin','gift_voucher','gift_card') NOT NULL");
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'wallet_amount')) {
                $table->dropColumn('wallet_amount');
            }
        });
    }
}
