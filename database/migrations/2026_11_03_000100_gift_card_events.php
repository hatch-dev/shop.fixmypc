<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gift card stabilisation (2026-11-03).
 *
 * gift_card_events: what happened to a card that is not already a row
 * elsewhere - issued by staff, balance or expiry changed, purchase refunded,
 * card voided, email resent - with the amount, the reason, the admin and the
 * time. Orders that spent a card are read from ordered_voucher, not copied.
 *
 * A card staff issue need not belong to a customer account, so the purchase's
 * user_id and the code's buyer_user_id may now be empty. Existing rows all
 * have values and are not touched.
 *
 * A refunded wallet purchase is credited back as wallet_transactions source
 * 'gift_voucher_refund'. 'refund' is an order's refund, matched to an order
 * by reference_id, so it cannot be reused. The value is added to whatever the
 * column already allows; nothing existing is renamed or removed.
 */
class GiftCardEvents extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('gift_card_events')) {
            Schema::create('gift_card_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('gift_voucher_code_id')->nullable()->index();
                $table->unsignedBigInteger('gift_voucher_order_id')->nullable()->index();
                $table->unsignedBigInteger('voucher_id')->nullable()->index();
                // issued | balance | expiry | refund | void | resend
                $table->string('type', 16)->index();
                // the change in value: + or - for balance, the money returned for refund
                $table->decimal('amount', 10, 2)->nullable();
                $table->string('old_value', 64)->nullable();
                $table->string('new_value', 64)->nullable();
                $table->string('reason', 255)->nullable();
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->timestamps();
            });
        }

        DB::statement('ALTER TABLE gift_voucher_orders MODIFY user_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE gift_voucher_codes MODIFY buyer_user_id BIGINT UNSIGNED NULL');

        $source = DB::selectOne(
            "SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_transactions' AND COLUMN_NAME = 'source'"
        );
        if ($source && stripos($source->type, 'enum(') === 0 && strpos($source->type, "'gift_voucher_refund'") === false) {
            $type = substr($source->type, 0, -1) . ",'gift_voucher_refund')";
            DB::statement("ALTER TABLE wallet_transactions MODIFY source $type " . ($source->nullable === 'NO' ? 'NOT NULL' : 'NULL'));
        }
    }

    public function down()
    {
        Schema::dropIfExists('gift_card_events');

        // Only safe while no staff-issued card without an account exists.
        if (!DB::table('gift_voucher_orders')->whereNull('user_id')->exists()) {
            DB::statement('ALTER TABLE gift_voucher_orders MODIFY user_id BIGINT UNSIGNED NOT NULL');
        }
        if (!DB::table('gift_voucher_codes')->whereNull('buyer_user_id')->exists()) {
            DB::statement('ALTER TABLE gift_voucher_codes MODIFY buyer_user_id BIGINT UNSIGNED NOT NULL');
        }
    }
}
