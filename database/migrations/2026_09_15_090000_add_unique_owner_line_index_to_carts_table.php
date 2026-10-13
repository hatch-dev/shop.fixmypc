<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One basket line per owner, product and variant — enforced by the database.
 *
 * Every write path already keys on that triple (add-to-cart folds a repeat add
 * into the existing row, buy-now does the same, and the sign-in merge folds the
 * guest side into the account side), so a duplicate pair can only come from two
 * of them racing each other. The index is what makes "no duplicate active cart
 * items" a property of the data rather than a promise made by four call sites.
 *
 * MySQL treats NULLs in a unique index as distinct, which is exactly the
 * behaviour wanted here: the three owner columns are mutually exclusive, so the
 * user index constrains only rows that belong to a signed-in customer, the
 * token index only guest rows, and the admin index only admin baskets.
 */
class AddUniqueOwnerLineIndexToCartsTable extends Migration
{
    /**
     * Owner column => index name.
     */
    private $indexes = [
        'user_id' => 'carts_user_line_unique',
        'user_token' => 'carts_token_line_unique',
        'admin_id' => 'carts_admin_line_unique',
    ];

    public function up()
    {
        foreach ($this->indexes as $owner => $name) {

            $this->consolidate($owner);

            if ($this->hasIndex($name)) {
                continue;
            }

            Schema::table('carts', function (Blueprint $table) use ($owner, $name) {
                $table->unique([$owner, 'product_id', 'inventory_id'], $name);
            });
        }
    }

    public function down()
    {
        foreach ($this->indexes as $name) {

            if (!$this->hasIndex($name)) {
                continue;
            }

            Schema::table('carts', function (Blueprint $table) use ($name) {
                $table->dropUnique($name);
            });
        }
    }

    /**
     * Fold any duplicate pair already in the table into its oldest row before
     * the index refuses to be created over it.
     *
     * The quantities are summed rather than one row being picked, because a
     * duplicate pair is a basket somebody built: dropping the newer row would
     * quietly take units out of it.
     */
    private function consolidate($owner)
    {
        $duplicates = DB::table('carts')
            ->select($owner, 'product_id', 'inventory_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull($owner)
            ->groupBy($owner, 'product_id', 'inventory_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {

            $rows = DB::table('carts')
                ->where($owner, $duplicate->{$owner})
                ->where('product_id', $duplicate->product_id)
                ->where('inventory_id', $duplicate->inventory_id)
                ->orderBy('id')
                ->get();

            $keep = $rows->shift();

            DB::table('carts')->where('id', $keep->id)->update([
                'quantity' => $rows->sum('quantity') + $keep->quantity,
            ]);

            DB::table('carts')->whereIn('id', $rows->pluck('id'))->delete();
        }
    }

    private function hasIndex($name)
    {
        return count(DB::select("SHOW INDEX FROM carts WHERE Key_name = ?", [$name])) > 0;
    }
}
