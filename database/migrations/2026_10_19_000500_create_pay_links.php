<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A secret link that lets a customer pay an amount the shop asked for by card
 * (App\Models\Helper\PayLinks). Today only for a dearer exchange: the token
 * is the credential, the amount is fixed here, and the gateway is asked
 * whether it was paid -- the browser is never believed.
 */
class CreatePayLinks extends Migration
{
    public function up()
    {
        if (Schema::hasTable('pay_links')) {
            return;
        }

        Schema::create('pay_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('order_return_id')->nullable();
            $table->unsignedInteger('order_id');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8);
            // pending | paid | cancelled
            $table->string('status', 12)->default('pending');
            // constants.paymentMethod: 9 SumUp, 3 Stripe -- the card gateway when the link was made
            $table->unsignedInteger('gateway');
            // SumUp checkout id or Stripe PaymentIntent id, once the customer starts paying
            $table->string('gateway_session', 255)->nullable();
            $table->string('gateway_reference', 255)->nullable();
            // The new units were taken from stock when the link was sent, and go back if it is cancelled.
            $table->boolean('stock_reserved')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['order_return_id', 'status']);
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pay_links');
    }
}
