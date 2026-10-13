<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * point_transactions.source was enum('order','topup','referral','admin'), but
 * the application writes two values that are not in it:
 *
 *   'order_reversal'  Order::restoreStockAndRewards() -- every cancellation,
 *                     return and refund of an order that earned or spent points
 *   'gift_voucher'    WalletController::purchaseGiftVoucher()
 *
 * On a strict-mode connection the insert throws; otherwise MySQL truncates the
 * value to '' and the movement is written unattributable. Either way the
 * reversal is lost.
 *
 * This matters beyond points: restoreStockAndRewards() reverses the points
 * first and releases the loyalty group redemption last, so a throw on the
 * points row meant a cancelled order never gave the customer their one-time
 * loyalty discount back either.
 */
class AddMissingPointTransactionSources extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE point_transactions
            MODIFY COLUMN source
            ENUM('order','topup','referral','admin','order_reversal','gift_voucher')
            NOT NULL
        ");
    }

    public function down()
    {
        // Anything written under the new values would not survive the revert.
        DB::table('point_transactions')
            ->whereIn('source', ['order_reversal', 'gift_voucher'])
            ->update(['source' => 'admin']);

        DB::statement("
            ALTER TABLE point_transactions
            MODIFY COLUMN source
            ENUM('order','topup','referral','admin')
            NOT NULL
        ");
    }
}
