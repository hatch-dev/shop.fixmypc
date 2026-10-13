<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the category hierarchy, and repair of the rows the old delete
 * path orphaned.
 *
 * No columns are added or changed: every field the admin form collects already
 * has one. This migration exists for two reasons.
 *
 * REPAIR. CategoriesController::delete() used to set the children of a deleted
 * category to `parent = 0`. Zero is not NULL — which is how a root category is
 * found, with whereNull('parent') — and it is not a real category id either,
 * so those rows belonged to nothing: they were not listed as roots, they were
 * not listed under any parent, and the only way to see them again was to query
 * the table directly. The same is true of a `parent` still pointing at a
 * category that has since been deleted. Both are moved to NULL, which puts
 * them back at the top level where an admin can find and re-file them.
 *
 * INDEXES. categories.parent carries no index at all, and it is now read on
 * every list request: the subcategory count is a correlated subquery per row,
 * the parent filter resolves a whole branch, and the cycle check reads the
 * edge list. `EXPLAIN` on "children of this parent" reported `type=ALL` — a
 * full table scan. The same is true of the status + root/sub filter, which
 * also fell back to a filesort for its ordering.
 *
 *   (parent, display_order) covers filtering by parent, counting children,
 *   and ordering a level — and its leftmost prefix serves a bare `parent`
 *   lookup, so no separate index on the column is needed.
 *
 *   (status, parent) serves the list's most common filter pair.
 *
 *   product_categories (category_id, product_id) turns the per-row product
 *   count into a covering index read; the existing index on category_id alone
 *   still has to visit the row to read product_id.
 *
 * Every step is guarded, so the migration is safe to re-run and safe on an
 * install where some of this already exists.
 */
class IndexAndRepairCategoryHierarchy extends Migration
{
    public function up()
    {
        // ---- repair ------------------------------------------------------

        $zeroed = DB::table('categories')->where('parent', 0)->update(['parent' => null]);

        $dangling = DB::table('categories as c')
            ->whereNotNull('c.parent')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))->from('categories as p')
                    ->whereRaw('p.id = c.parent');
            })
            ->update(['c.parent' => null]);

        /*
         * A cycle stored by the old re-parent endpoint, which checked nothing.
         * Any category that cannot reach a root within the table's own row
         * count is in one; breaking it at that category puts the branch back
         * at the top level rather than leaving a read of the tree to recurse
         * until it runs out of memory.
         */
        $broken = $this->breakCycles();

        echo "  categories repaired: parent=0 -> null: $zeroed, dangling: $dangling, cycles: $broken\n";

        // ---- indexes -----------------------------------------------------

        if (!$this->hasIndex('categories', 'categories_parent_order_index')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->index(['parent', 'display_order'], 'categories_parent_order_index');
            });
        }

        if (!$this->hasIndex('categories', 'categories_status_parent_index')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->index(['status', 'parent'], 'categories_status_parent_index');
            });
        }

        if (Schema::hasTable('product_categories')
            && !$this->hasIndex('product_categories', 'product_categories_category_product_index')
        ) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->index(['category_id', 'product_id'], 'product_categories_category_product_index');
            });
        }
    }

    public function down()
    {
        // The repaired rows are not restored — parent=0 was never a valid
        // state, and putting it back would re-hide the same categories.

        foreach ([
            'categories' => ['categories_parent_order_index', 'categories_status_parent_index'],
            'product_categories' => ['product_categories_category_product_index'],
        ] as $table => $indexes) {

            foreach ($indexes as $index) {

                if (!Schema::hasTable($table) || !$this->hasIndex($table, $index)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropIndex($index);
                });
            }
        }
    }

    /**
     * Detach any category that cannot reach the top level.
     *
     * @return int how many were detached
     */
    private function breakCycles()
    {
        $edges = DB::table('categories')->select('id', 'parent')->get()
            ->mapWithKeys(function ($row) {
                return [(int)$row->id => $row->parent === null ? null : (int)$row->parent];
            })->all();

        $limit = count($edges) + 1;
        $broken = 0;

        foreach ($edges as $id => $parent) {

            $steps = 0;
            $current = $parent;

            while ($current !== null && $steps <= $limit) {
                $current = $edges[$current] ?? null;
                $steps++;
            }

            if ($steps > $limit) {
                DB::table('categories')->where('id', $id)->update(['parent' => null]);
                $edges[$id] = null;
                $broken++;
            }
        }

        return $broken;
    }

    private function hasIndex($table, $name)
    {
        return count(DB::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$name])) > 0;
    }
}
