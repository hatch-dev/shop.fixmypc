<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Basket stock reservations (App\Models\Helper\CartHold).
 *
 * Adding to the basket takes the stock off updated_inventories straight away,
 * and the basket then has 5 minutes before a reminder email and 20 before it
 * is emptied and the stock goes back.
 *
 *  - cart_reservations: how many units of an inventory row a basket is
 *    holding right now. Kept apart from carts on purpose - lines are deleted
 *    from a dozen places, many of them bulk deletes, and a count stored on
 *    the line would vanish with it and strand the stock. Here it survives
 *    until CartHold::reconcile() gives it back.
 *  - cart_holds: one per basket, the timer. last_activity_at restarts on
 *    every add; reminded_at stops a second reminder; paying_until keeps a
 *    basket that is mid-payment from being emptied under the card.
 *
 * A basket is identified by owner_key: "u:<user id>" or "g:<guest token>".
 */
class CreateCartHolds extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('cart_reservations')) {
            Schema::create('cart_reservations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('owner_key', 191);
                $table->unsignedInteger('inventory_id');
                $table->integer('quantity')->default(0);
                $table->timestamps();

                $table->unique(['owner_key', 'inventory_id']);
                $table->index('inventory_id');
            });
        }

        if (!Schema::hasTable('cart_holds')) {
            Schema::create('cart_holds', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('owner_key', 191)->unique();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('user_token', 191)->nullable();
                $table->dateTime('last_activity_at');
                $table->dateTime('reminded_at')->nullable();
                $table->dateTime('paying_until')->nullable();
                $table->timestamps();

                $table->index('last_activity_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('cart_holds');
        Schema::dropIfExists('cart_reservations');
    }
}
