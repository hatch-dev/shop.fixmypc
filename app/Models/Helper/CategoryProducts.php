<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Which products belong to a category, for the storefront listing and its
 * product counts - one rule, used by both.
 *
 * A product is filed under a category in either of two tables:
 *   product_categories     (product_id, category_id)        - the categories
 *                                                             ticked on the product
 *   product_subcategories  (product_id, subcategory_id, ...) - the subcategories
 *                                                             ticked under them
 *
 * The listing and the counts used to read product_categories alone, for the
 * one category asked for. A product put in "Laptops > MacBook" from the product
 * form lands in product_categories under Laptops and in product_subcategories
 * under MacBook, so MacBook listed nothing and counted 0, and a parent never
 * listed what was filed only under its subcategories.
 *
 * Now a category means itself and everything beneath it (CategoryTree), and a
 * product belongs if either table files it under any of those.
 */
class CategoryProducts
{
    /** @var array|null category id => public product count, per request */
    private static $counts = null;

    /** The category and every category beneath it, at any depth. */
    public static function branchIds($categoryId, $edges = null)
    {
        $ids = CategoryTree::descendantIds((int)$categoryId, $edges);
        array_unshift($ids, (int)$categoryId);

        return array_values(array_unique($ids));
    }

    /**
     * Limit a products query to products filed under any of $categoryIds,
     * through either table. A subquery rather than a join, so a product filed
     * twice (category and subcategory) is still one row.
     */
    public static function constrain($query, array $categoryIds, $column = 'products.id')
    {
        if (!$categoryIds) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, function ($q) use ($categoryIds) {
            $q->select('product_id')
                ->from('product_categories')
                ->whereIn('category_id', $categoryIds)
                ->union(
                    DB::table('product_subcategories')
                        ->select('product_id')
                        ->whereIn('subcategory_id', $categoryIds)
                );
        });
    }

    /**
     * Public products per category, each product counted once across the
     * category and its subcategories - the same set constrain() lists.
     */
    public static function counts()
    {
        if (self::$counts !== null) {
            return self::$counts;
        }

        $public = Config::get('constants.status.PUBLIC');
        $direct = [];

        $rows = DB::table('product_categories as pc')
            ->join('products as p', 'p.id', '=', 'pc.product_id')
            ->where('p.status', $public)
            ->select('pc.category_id as category_id', 'pc.product_id as product_id')
            ->get()
            ->concat(
                DB::table('product_subcategories as ps')
                    ->join('products as p', 'p.id', '=', 'ps.product_id')
                    ->where('p.status', $public)
                    ->select('ps.subcategory_id as category_id', 'ps.product_id as product_id')
                    ->get()
            );

        foreach ($rows as $row) {
            $direct[(int)$row->category_id][(int)$row->product_id] = true;
        }

        $edges = CategoryTree::edges();
        $counts = [];

        foreach (array_keys($edges) as $id) {
            $products = [];
            foreach (self::branchIds($id, $edges) as $branchId) {
                $products += $direct[$branchId] ?? [];
            }
            $counts[$id] = count($products);
        }

        return self::$counts = $counts;
    }

    /**
     * Set products_count from counts() on a category or a list of them, and on
     * the subcategories loaded under them (`child` / `children`), at any depth.
     */
    public static function applyCounts($categories)
    {
        $counts = self::counts();

        $walk = function ($node) use (&$walk, $counts) {
            if (!$node || !isset($node->id)) {
                return;
            }

            $node->products_count = $counts[(int)$node->id] ?? 0;

            foreach (['child', 'children'] as $relation) {
                if (method_exists($node, 'relationLoaded') && $node->relationLoaded($relation)) {
                    foreach ($node->getRelation($relation) as $sub) {
                        $walk($sub);
                    }
                }
            }
        };

        if (is_iterable($categories)) {
            foreach ($categories as $category) {
                $walk($category);
            }
        } else {
            $walk($categories);
        }

        return $categories;
    }

    /** For tests: forget the per-request counts. */
    public static function flush()
    {
        self::$counts = null;
    }
}
