<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which gateway takes card payments at checkout: SumUp (9) or Stripe (3).
 *
 * Checkout shows one "Credit / Debit Card" option; this decides who is behind
 * it. Null keeps the old behaviour - SumUp when it is on, else Stripe - see
 * Payment::cardGateway().
 */
class AddCardGatewayToPayments extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('card_gateway')->nullable()->after('default');
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('card_gateway');
        });
    }
}
