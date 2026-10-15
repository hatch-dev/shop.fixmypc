<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Order numbers are now short and random (ORD + 7 digits,
 * Utils::generateOrderNumber), which checks before it uses one. This index
 * is the backstop for two orders drawing the same number at the same moment.
 *
 * A database that already holds a duplicate number is left without the index
 * (and says so in the log) rather than failing the deploy.
 */
class AddUniqueOrderNumberToOrders extends Migration
{
    public function up()
    {
        if ($this->hasIndex()) {
            return;
        }

        $duplicates = DB::table('orders')->whereNotNull('order')
            ->groupBy('order')->havingRaw('COUNT(*) > 1')->pluck('order');

        if ($duplicates->isNotEmpty()) {
            Log::warning('orders.order not made unique: duplicate numbers exist', ['orders' => $duplicates->all()]);
            echo "Skipped: duplicate order numbers exist (" . $duplicates->implode(', ') . ")\n";
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('order', 'orders_order_unique');
        });
    }

    public function down()
    {
        if ($this->hasIndex()) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_order_unique');
            });
        }
    }

    private function hasIndex()
    {
        return count(DB::select("SHOW INDEX FROM orders WHERE Key_name = 'orders_order_unique'")) > 0;
    }
}
