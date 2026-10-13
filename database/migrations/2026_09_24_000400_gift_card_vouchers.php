<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A redeemed gift card becomes a voucher in its owner's list, applied at
 * checkout like any other code. `gift_value` marks it as a gift card and
 * holds its face value; what is left is derived from the orders that used
 * it (Voucher::giftBalance), so a cancelled order gives the value back.
 */
class GiftCardVouchers extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('vouchers', 'gift_value')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->decimal('gift_value', 10, 2)->nullable()->after('price');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('vouchers', 'gift_value')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropColumn('gift_value');
            });
        }
    }
}
