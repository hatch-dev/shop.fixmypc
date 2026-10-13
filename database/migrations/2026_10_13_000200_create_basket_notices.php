<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things that changed a basket without the shopper doing it, kept until the
 * shopper has seen them (2026-10-13): the basket emptied after 20 idle
 * minutes, a line cut back because its units could not be held, guest
 * items added at sign-in. See App\Models\Helper\BasketNotice.
 */
class CreateBasketNotices extends Migration
{
    public function up()
    {
        if (Schema::hasTable('basket_notices')) {
            return;
        }

        Schema::create('basket_notices', function (Blueprint $table) {
            $table->id();
            // u:<user id> or g:<guest token>, the same key CartHold uses.
            $table->string('owner_key', 191)->index();
            $table->string('kind', 20);
            $table->text('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('basket_notices');
    }
}
