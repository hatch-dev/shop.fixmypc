<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buy Now gets a line of its own (2026-10-09).
 *
 * Buy Now used to write an ordinary basket row and untick everything else,
 * so an abandoned Buy Now stayed in the basket, overwrote the quantity of a
 * line the shopper already had, and followed them into their account at
 * sign-in. A carts row with buy_now = 1 is a checkout of one item: the
 * basket, its count and the sign-in merge never see it.
 *
 * The owner+product+variant unique keys gain the flag, so the same variant
 * can sit in the basket and in a Buy Now checkout at the same time.
 */
class AddBuyNowToCarts extends Migration
{
    private $indexes = [
        'user_id' => 'carts_user_line_unique',
        'user_token' => 'carts_token_line_unique',
        'admin_id' => 'carts_admin_line_unique',
    ];

    public function up()
    {
        if (!Schema::hasColumn('carts', 'buy_now')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->boolean('buy_now')->default(0)->after('selected');
            });
        }

        foreach ($this->indexes as $owner => $name) {
            $this->replaceIndex($name, [$owner, 'product_id', 'inventory_id', 'buy_now']);
        }
    }

    public function down()
    {
        DB::table('carts')->where('buy_now', 1)->delete();

        foreach ($this->indexes as $owner => $name) {
            $this->replaceIndex($name, [$owner, 'product_id', 'inventory_id']);
        }

        if (Schema::hasColumn('carts', 'buy_now')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropColumn('buy_now');
            });
        }
    }

    private function replaceIndex($name, array $columns)
    {
        $exists = collect(DB::select('SHOW INDEX FROM carts WHERE Key_name = ?', [$name]))->isNotEmpty();

        Schema::table('carts', function (Blueprint $table) use ($name, $columns, $exists) {
            if ($exists) {
                $table->dropUnique($name);
            }

            $table->unique($columns, $name);
        });
    }
}
