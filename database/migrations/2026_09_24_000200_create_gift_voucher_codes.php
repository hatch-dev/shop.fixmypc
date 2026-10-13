<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Gift vouchers become real codes.
 *
 * Buying a gift voucher used to leave only a gift_voucher_orders row: no code,
 * nothing to hand to anyone, nothing checkout could accept. Each voucher
 * bought is now one gift_voucher_codes row with its own code. Redeeming a
 * code creates a single-use flat voucher owned by whoever redeemed it
 * (vouchers.user_id), so it shows in their list and applies at checkout for
 * them alone.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('vouchers', 'user_id')) {
            Schema::table('vouchers', function (Blueprint $table) {
                // null = a store-wide voucher; set = only this customer may use it
                $table->unsignedBigInteger('user_id')->nullable()->index();
            });
        }

        if (!Schema::hasTable('gift_voucher_codes')) {
            Schema::create('gift_voucher_codes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gift_voucher_order_id')->index();
                $table->unsignedBigInteger('buyer_user_id')->index();
                $table->string('code', 32)->unique();
                $table->decimal('amount', 10, 2);
                $table->timestamp('expires_at')->nullable();
                // set when someone adds it to their account
                $table->unsignedBigInteger('redeemed_by')->nullable()->index();
                $table->timestamp('redeemed_at')->nullable();
                $table->unsignedBigInteger('voucher_id')->nullable();
                $table->timestamps();
            });
        }

        // Codes for purchases made before codes existed.
        $orders = DB::table('gift_voucher_orders')->where('status', 'success')->get();

        foreach ($orders as $order) {
            if (DB::table('gift_voucher_codes')->where('gift_voucher_order_id', $order->id)->exists()) {
                continue;
            }

            for ($i = 0; $i < max(1, (int)$order->quantity); $i++) {
                $code = \App\Models\GiftVoucherCode::newCode();

                DB::table('gift_voucher_codes')->insert([
                    'gift_voucher_order_id' => $order->id,
                    'buyer_user_id'         => $order->user_id,
                    'code'                  => $code,
                    'amount'                => $order->amount,
                    'expires_at'            => \Carbon\Carbon::parse($order->created_at)->addYears(5),
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('gift_voucher_codes');

        if (Schema::hasColumn('vouchers', 'user_id')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
