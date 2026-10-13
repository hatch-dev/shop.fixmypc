<?php

namespace App\Models\Helper;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * The rules that govern the category hierarchy, in one place.
 *
 * categories.parent is a self-reference with no foreign key and no depth
 * limit, and until now nothing enforced what may point at what. The admin
 * panel checked "is the parent me?" on edit and nothing at all on the inline
 * re-parent dropdown, so A -> B -> A was one drag away — and a cycle is not a
 * cosmetic problem here: every read of the tree (`child()` eager-loads itself
 * recursively, the storefront menu walks parents upwards) recurses until PHP
 * runs out of memory.
 *
 * Reading the whole edge list once and walking it in PHP, rather than a
 * recursive CTE, because the table is small (tens of rows), MySQL 5.7 is still
 * supported here, and the same array answers "descendants", "would this
 * cycle?" and "how deep is this?" without three round trips.
 */
class CategoryTree
{
    /**
     * Hard cap on nesting.
     *
     * Not a database constraint — a product decision. The storefront menu
     * renders two levels and the admin list renders two, so anything deeper is
     * invisible to both and is far more likely a mistake than an intention.
     */
    const MAX_DEPTH = 3;

    /**
     * Every edge in the tree as [child id => parent id or null].
     *
     * Two columns of a small table; cheap enough to read per request and much
     * cheaper than the alternative of walking the tree with a query per level.
     */
    public static function edges()
    {
        return DB::table('categories')
            ->select('id', 'parent')
            ->get()
            ->mapWithKeys(function ($row) {
                return [(int)$row->id => self::normaliseParent($row->parent)];
            })
            ->all();
    }

    /**
     * A stored parent value that means "no parent".
     *
     * Historically the delete path set orphaned children to 0 rather than
     * NULL, which is neither a root (roots are found with whereNull) nor a
     * valid parent — those rows disappeared from the admin list entirely. The
     * migration normalises them, and this keeps anything still carrying a 0
     * readable.
     */
    public static function normaliseParent($parent)
    {
        return ($parent === null || (int)$parent === 0) ? null : (int)$parent;
    }

    /**
     * Ids of everything beneath $categoryId, at any depth.
     *
     * The set a category may not be moved into, and the set that has to be
     * dealt with when it is deleted.
     */
    public static function descendantIds($categoryId, $edges = null)
    {
        $edges = $edges ?? self::edges();

        $childrenOf = [];

        foreach ($edges as $id => $parent) {
            if ($parent !== null) {
                $childrenOf[$parent][] = $id;
            }
        }

        $found = [];
        $queue = [(int)$categoryId];

        while ($queue) {

            $current = array_shift($queue);

            foreach ($childrenOf[$current] ?? [] as $child) {

                // Guard against a cycle that is already stored: without it a
                // pre-existing A -> B -> A loops here forever.
                if (isset($found[$child])) {
                    continue;
                }

                $found[$child] = true;
                $queue[] = $child;
            }
        }

        return array_keys($found);
    }

    /**
     * Would making $parentId the parent of $categoryId create a loop?
     *
     * True when the proposed parent is the category itself or sits anywhere
     * beneath it.
     */
    public static function wouldCycle($categoryId, $parentId, $edges = null)
    {
        $parentId = self::normaliseParent($parentId);

        if ($parentId === null) {
            return false;
        }

        if ((int)$parentId === (int)$categoryId) {
            return true;
        }

        return in_array((int)$parentId, self::descendantIds($categoryId, $edges), true);
    }

    /**
     * How many levels sit above $parentId, counting $parentId itself.
     *
     * 0 for "no parent", so a root category is depth 0 and its children are
     * depth 1.
     */
    public static function depthOf($parentId, $edges = null)
    {
        $parentId = self::normaliseParent($parentId);

        if ($parentId === null) {
            return 0;
        }

        $edges = $edges ?? self::edges();

        $depth = 0;
        $current = (int)$parentId;
        $seen = [];

        while ($current !== null && !isset($seen[$current])) {

            $seen[$current] = true;
            $depth++;

            $current = $edges[$current] ?? null;
        }

        return $depth;
    }

    /**
     * The deepest level anywhere under $categoryId, relative to it.
     *
     * Needed so moving a branch cannot push its own children past MAX_DEPTH:
     * the category may fit, and everything hanging off it may not.
     */
    public static function heightOf($categoryId, $edges = null)
    {
        $edges = $edges ?? self::edges();

        $descendants = self::descendantIds($categoryId, $edges);

        if (!$descendants) {
            return 0;
        }

        $height = 0;

        $baseDepth = self::depthOf($edges[(int)$categoryId] ?? null, $edges) + 1;

        foreach ($descendants as $descendant) {
            $depth = self::depthOf($edges[$descendant] ?? null, $edges) + 1;
            $height = max($height, $depth - $baseDepth);
        }

        return $height;
    }

    /**
     * Why this parent cannot be used, or null when it can.
     *
     * One method so create, edit and the inline re-parent dropdown all refuse
     * the same moves for the same stated reasons — they were each deciding
     * separately, and two of the three decided nothing.
     *
     * @param int|null $categoryId null when the category does not exist yet
     */
    public static function parentProblem($categoryId, $parentId, $lang = null)
    {
        $parentId = self::normaliseParent($parentId);

        if ($parentId === null) {
            return null;
        }

        $edges = self::edges();

        if (!array_key_exists((int)$parentId, $edges)) {
            return __('lang.category_parent_missing', [], $lang);
        }

        if ($categoryId === null) {

            // A category being created has no descendants, so only the depth
            // of the chosen parent can rule it out.
            return self::depthOf($parentId, $edges) + 1 > self::MAX_DEPTH - 1
                ? __('lang.category_parent_too_deep', ['max' => self::MAX_DEPTH], $lang)
                : null;
        }

        if ((int)$parentId === (int)$categoryId) {
            return __('lang.category_parent_self', [], $lang);
        }

        if (self::wouldCycle($categoryId, $parentId, $edges)) {
            return __('lang.category_parent_cycle', [], $lang);
        }

        $resulting = self::depthOf($parentId, $edges) + 1 + self::heightOf($categoryId, $edges);

        if ($resulting > self::MAX_DEPTH - 1) {
            return __('lang.category_parent_too_deep', ['max' => self::MAX_DEPTH], $lang);
        }

        return null;
    }

    /**
     * What deleting these categories would take with them.
     *
     * The admin is shown this before confirming, and the delete itself reads
     * the same figures — so the warning and the outcome cannot disagree.
     */
    public static function deleteImpact(array $categoryIds)
    {
        $edges = self::edges();

        $ids = array_values(array_unique(array_map('intval', $categoryIds)));

        $descendants = [];

        foreach ($ids as $id) {
            foreach (self::descendantIds($id, $edges) as $descendant) {
                $descendants[$descendant] = true;
            }
        }

        // A selected category is not also "a descendant that comes with it".
        foreach ($ids as $id) {
            unset($descendants[$id]);
        }

        $descendantIds = array_keys($descendants);

        $affected = array_values(array_unique(array_merge($ids, $descendantIds)));

        $directProducts = DB::table('product_categories')
            ->whereIn('category_id', $ids)
            ->distinct()->count('product_id');

        $allProducts = DB::table('product_categories')
            ->whereIn('category_id', $affected)
            ->distinct()->count('product_id');

        return [
            'categories' => count($ids),
            'subcategories' => count($descendantIds),
            'subcategory_ids' => $descendantIds,
            'products' => $directProducts,
            'products_including_subcategories' => $allProducts,
            'names' => Category::whereIn('id', $ids)->pluck('title', 'id')->all(),
            'subcategory_names' => Category::whereIn('id', $descendantIds)
                ->pluck('title', 'id')->all(),
        ];
    }
}
