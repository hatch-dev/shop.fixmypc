<?php

namespace App\Http\Controllers;

use App\Console\Commands\PublishDueCategories;
use Carbon\Carbon;
use App\Models\BannerSourceCategory;
use App\Models\BannerSourceSubCategory;
use App\Models\Category;
use App\Models\CategoryLang;
use App\Models\Helper\CategoryTree;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\HomeSliderSourceCategory;
use App\Models\HomeSliderSourceSubCategory;
use App\Models\ProductCategory;
use App\Models\SubCategory;
use App\Models\SubCategoryLang;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoriesController extends ControllerHelper
{
    /**
     * Columns the list may be sorted by, mapped to what the database is asked
     * for. A whitelist rather than the request's own string: `orderby` went
     * straight into orderBy(), so any column name — or expression — the caller
     * cared to name decided the sort.
     */
    const SORTABLE = [
        'title' => 'sort_title',
        'slug' => 'categories.slug',
        'parent' => 'parent_title',
        'parent_id' => 'categories.parent',
        'products_count' => 'products_count',
        'children_count' => 'children_count',
        'status' => 'categories.status',
        'display_order' => 'categories.display_order',
        'created_at' => 'categories.created_at',
        'updated_at' => 'categories.updated_at',
    ];

    /**
     * Everything the admin form is allowed to write.
     *
     * The previous code passed `$request->all()` to a query-builder update,
     * which bypasses $fillable entirely — so any column named in the request
     * body was written, `admin_id` and `id` included. That let a vendor hand
     * their category to another account, or take one.
     */
    const WRITABLE = [
        'title', 'slug', 'parent', 'status', 'featured', 'in_footer', 'in_frontend',
        'short_description', 'description', 'meta_title', 'meta_description',
        'meta_keywords', 'canonical_url', 'searchable', 'publish_at',
        'display_order', 'products_per_page', 'show_in_nav', 'enable_filters',
        'show_homepage', 'allow_promotions',
    ];

    /** Fields that live in category_langs rather than on the category. */
    const TRANSLATABLE = ['title', 'meta_title', 'meta_description', 'meta_keywords'];

    /** Columns the list needs. Selecting * dragged three TEXT bodies per row. */
    const LIST_COLUMNS = [
        'categories.id', 'categories.title', 'categories.slug', 'categories.parent',
        'categories.image', 'categories.status', 'categories.display_order',
        'categories.in_footer', 'categories.in_frontend', 'categories.featured',
        'categories.show_in_nav', 'categories.show_homepage',
        'categories.created_at', 'categories.updated_at',
    ];

    /**
     * How many categories the tree view will assemble in one request.
     *
     * The hierarchy cannot be paged at the database, because a page of roots
     * has to arrive with its branches attached — so the whole matching set is
     * read and arranged in PHP. That is fine for a catalogue's worth of
     * categories and would not be for a table of products, hence the ceiling
     * and the `truncated` flag that goes back with the response.
     */
    const TREE_LIMIT = 2000;

    // =====================================================================
    // Reading
    // =====================================================================

    /**
     * The admin category list: one page of rows, with the counts and the
     * parent name each row needs, and the summary figures for the cards.
     *
     * Rewritten from a version that hard-filtered `whereNull('parent')` and
     * eager-loaded `child` — which recursively eager-loads `child` again — so
     * the page could only ever show root categories, and paid for the entire
     * subtree of each one to render two levels of it. A subcategory could not
     * be searched for, filtered to, or sorted on, because it was never in the
     * result set.
     */
    public function all(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.view')) {
                return $can;
            }

            /*
             * Bring any category whose scheduled time has arrived live before
             * the list is drawn, so the admin is never shown a Draft that was
             * due hours ago. One UPDATE that matches nothing almost every
             * time; categories:publish-due runs the same sweep on a schedule,
             * and this covers installs where cron is not ticking.
             */
            PublishDueCategories::sweep();

            $limit = $request->limit;

            if ($limit === 'all') {
                // Still bounded: "all" is a convenience for a small tree and an
                // export, not an invitation to stream the table.
                $limit = 500;
            }

            $limit = (int)($limit ?: Config::get('constants.api.PAGINATION'));
            $limit = max(1, min($limit, 500));

            /*
             * Two shapes of the same list. `view=tree` groups each root with
             * the branch under it and pages over roots; anything else is the
             * flat page this endpoint has always returned, which is what the
             * export and any caller still on the previous bundle expect.
             */
            $payload = $request->input('view') === 'tree'
                ? $this->treePage($request, $lang, $limit)
                : $this->flatPage($request, $lang, $limit);

            $payload['stats'] = $this->stats($request, $lang);

            /*
             * The old response put the stat figures at the top level next to
             * the paginator's own keys. They are nested under `stats` now, and
             * the flat keys are kept alongside so an admin panel still running
             * the previous bundle does not lose its cards mid-deploy.
             */
            foreach ($payload['stats'] as $key => $value) {
                if (!array_key_exists($key, $payload)) {
                    $payload[$key] = $value;
                }
            }

            return response()->json(new Response($request->token, (object)$payload));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** One page of rows, in whatever order was asked for, parents and children alike. */
    private function flatPage(Request $request, $lang, $limit)
    {
        $query = $this->scopedQuery($request, $lang);

        $this->applyFilters($query, $request, $lang);
        $this->applySearch($query, $request, $lang);
        $this->applySort($query, $request);

        $data = $query->paginate($limit)->appends($request->query());

        foreach ($data as $item) {
            $this->decorateRow($item);
        }

        $payload = $data->toArray();
        $payload['view'] = 'flat';

        return $payload;
    }

    /**
     * The same list arranged as a hierarchy: each root category followed by
     * the branch beneath it, in pre-order, with the depth and the guide flags
     * a row needs to draw itself indented.
     *
     * Two things make this more than a sort.
     *
     * A page is a page of *roots*, not of rows — paginating the flat result
     * would put Mobile on page one and Samsung on page three, which is exactly
     * the problem the hierarchy is meant to solve. `total` therefore counts
     * top-level categories, and `row_count` says how many rows that came to.
     *
     * And a filter or a search matches individual categories, not branches. A
     * search for "Samsung" has to show Mobile too or the hit has nowhere to
     * hang, so every match pulls its ancestors in with it. Those ancestors are
     * marked `matched: false`, so the panel can show them as the context they
     * are rather than as hits of their own.
     */
    private function treePage(Request $request, $lang, $limit)
    {
        // ---- 1. which categories match, as ids ----------------------------

        $matchQuery = Category::query()->select('categories.id', 'categories.parent');

        if ($this->isVendor) {
            $matchQuery->where('categories.admin_id', $this->user->id);
        }

        if ($lang) {
            // applySearch reaches for cl.title when a language is set, so the
            // join has to be here too even though no translated column is read.
            $matchQuery->leftJoin('category_langs as cl', function ($join) use ($lang) {
                $join->on('cl.category_id', '=', 'categories.id')
                    ->where('cl.lang', $lang);
            });
        }

        $this->applyFilters($matchQuery, $request, $lang);
        $this->applySearch($matchQuery, $request, $lang);

        $matchedIds = [];

        foreach ($matchQuery->limit(self::TREE_LIMIT)->get() as $row) {
            $matchedIds[(int)$row->id] = true;
        }

        if (!$matchedIds) {
            return $this->emptyTreePage($limit);
        }

        // ---- 2. add each match's ancestors, so no hit is orphaned ---------

        $edges = CategoryTree::edges();
        $display = $matchedIds;

        foreach (array_keys($matchedIds) as $id) {

            $current = $edges[$id] ?? null;
            $seen = [];

            // $seen, not a depth count: a cycle that is already stored would
            // otherwise walk forever.
            while ($current !== null && !isset($seen[$current])) {
                $seen[$current] = true;
                $display[$current] = true;
                $current = $edges[$current] ?? null;
            }
        }

        // ---- 3. read those rows once, in the requested order --------------

        $rowQuery = $this->scopedQuery($request, $lang)
            ->whereIn('categories.id', array_keys($display));

        $this->applySort($rowQuery, $request);

        $rows = $rowQuery->limit(self::TREE_LIMIT)->get();

        $known = [];

        foreach ($rows as $row) {
            $known[(int)$row->id] = true;
        }

        /*
         * Siblings come out of the loop in the order the query returned them,
         * so the sort the admin picked still decides the order within a level.
         * It just cannot move a child away from its parent any more.
         */
        $byParent = [];

        foreach ($rows as $row) {

            $parent = CategoryTree::normaliseParent($row->parent);

            /*
             * A row whose parent is not in the result stands as a root of its
             * own. Only reachable if the edge list moved under us mid-request,
             * but the alternative is a row that is in the count and on no page.
             */
            $key = ($parent !== null && isset($known[$parent])) ? $parent : 0;

            $byParent[$key][] = $row;
        }

        // ---- 4. page over roots, then flatten each branch -----------------

        $roots = $byParent[0] ?? [];
        $total = count($roots);
        $lastPage = max(1, (int)ceil($total / $limit));

        $page = max(1, (int)$request->input('page', 1));
        $page = min($page, $lastPage);

        $offset = ($page - 1) * $limit;
        $pageRoots = array_slice($roots, $offset, $limit);

        $flat = [];
        $this->flattenBranch($pageRoots, $byParent, $matchedIds, 0, [], $flat);

        return [
            'view' => 'tree',
            'current_page' => $page,
            'data' => $flat,
            'from' => $total ? $offset + 1 : null,
            'to' => $total ? $offset + count($pageRoots) : null,
            'last_page' => $lastPage,
            'per_page' => $limit,
            // Top-level categories, so the pager counts what it pages over.
            'total' => $total,
            // What that came to on screen, and how much of it was a real hit.
            'row_count' => count($flat),
            'match_count' => count($matchedIds),
            'truncated' => $rows->count() >= self::TREE_LIMIT,
        ];
    }

    /**
     * Walks one level of siblings and everything under them, appending each
     * row to $out in the order it should be drawn.
     *
     * $ancestorsHaveMore carries one flag per level above this row: true where
     * that ancestor still has siblings below it. That is what tells the panel
     * whether to draw a vertical guide in the gutter at that level or leave it
     * blank, which is the difference between a tree and a list of indents.
     */
    private function flattenBranch(array $siblings, array $byParent, array $matchedIds, $depth, array $ancestorsHaveMore, array &$out)
    {
        $lastIndex = count($siblings) - 1;

        foreach ($siblings as $index => $row) {

            $isLast = $index === $lastIndex;
            $children = $byParent[(int)$row->id] ?? [];

            $this->decorateRow($row);

            $row['depth'] = $depth;
            $row['is_last_child'] = $isLast;
            $row['ancestor_lines'] = $ancestorsHaveMore;

            /*
             * children_count is the truth about the category; visible_children
             * is how much of it survived the filters. A parent with children
             * and none of them on screen says so, rather than claiming to have
             * no subcategories.
             */
            $row['visible_children'] = count($children);
            $row['matched'] = isset($matchedIds[(int)$row->id]);

            /*
             * Whether this row could take a subcategory at all, decided by the
             * same nesting cap that would refuse the save. The panel offers
             * "Add subcategory" on the strength of it, rather than keeping its
             * own copy of the rule and opening a form that can only be refused.
             *
             * A category at depth d is depth d + 1 as a parent, and a new child
             * would sit one below that.
             */
            $row['can_have_children'] = ($depth + 2) <= (CategoryTree::MAX_DEPTH - 1);

            $out[] = $row;

            if ($children) {
                $this->flattenBranch(
                    $children,
                    $byParent,
                    $matchedIds,
                    $depth + 1,
                    array_merge($ancestorsHaveMore, [!$isLast]),
                    $out
                );
            }
        }
    }

    /** The formatted dates and the resolved image URL every list row carries. */
    private function decorateRow($item)
    {
        $item['created'] = Utils::formatDate($item->created_at);
        $item['updated'] = Utils::formatDate($item->updated_at);
        $item['image_url'] = FileHelper::imageLink($item->image);
    }

    /** Nothing matched — still the tree shape, so the panel has one code path. */
    private function emptyTreePage($limit)
    {
        return [
            'view' => 'tree',
            'current_page' => 1,
            'data' => [],
            'from' => null,
            'to' => null,
            'last_page' => 1,
            'per_page' => $limit,
            'total' => 0,
            'row_count' => 0,
            'match_count' => 0,
            'truncated' => false,
        ];
    }

    /**
     * The base query: translated title where a language is set, the parent's
     * title, and the two counts the list shows.
     *
     * The counts are correlated subqueries rather than withCount() because the
     * list also sorts on them — an Eloquent count that is not part of the
     * select cannot be ordered by — and because two subqueries is two joins
     * fewer than counting through the pivot.
     */
    private function scopedQuery(Request $request, $lang)
    {
        $query = Category::query();

        $columns = self::LIST_COLUMNS;

        if ($lang) {

            $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                $join->on('cl.category_id', '=', 'categories.id')
                    ->where('cl.lang', $lang);
            });

            // COALESCE so a category with no row for this language keeps its
            // default title instead of rendering as an empty cell.
            $columns[] = DB::raw('COALESCE(cl.title, categories.title) as title');
            $columns[] = DB::raw('COALESCE(cl.title, categories.title) as sort_title');
            $columns[] = 'cl.meta_title';
            $columns[] = 'cl.meta_description';
            $columns[] = 'cl.meta_keywords';

            // categories.title is in LIST_COLUMNS and would win the alias.
            $columns = array_values(array_diff($columns, ['categories.title']));

            $parentTitle = DB::raw(
                '(SELECT COALESCE(pcl.title, p.title) FROM categories p'
                . ' LEFT JOIN category_langs pcl ON pcl.category_id = p.id AND pcl.lang = '
                . DB::connection()->getPdo()->quote($lang)
                . ' WHERE p.id = categories.parent) as parent_title'
            );

        } else {
            $columns[] = DB::raw('categories.title as sort_title');

            $parentTitle = DB::raw(
                '(SELECT p.title FROM categories p WHERE p.id = categories.parent) as parent_title'
            );
        }

        $columns[] = $parentTitle;

        $columns[] = DB::raw(
            '(SELECT COUNT(DISTINCT pc.product_id) FROM product_categories pc'
            . ' WHERE pc.category_id = categories.id) as products_count'
        );

        $columns[] = DB::raw(
            '(SELECT COUNT(*) FROM categories c2 WHERE c2.parent = categories.id) as children_count'
        );

        $query->select($columns);

        if ($this->isVendor) {
            $query->where('categories.admin_id', $this->user->id);
        }

        return $query;
    }

    /**
     * status / parent / root-or-sub, each optional and each combinable.
     *
     * None of these existed. `scope=root` reproduces the old hard-coded
     * behaviour for any caller that still wants only top-level categories.
     */
    private function applyFilters($query, Request $request, $lang)
    {
        if ($request->filled('status') && in_array((int)$request->status, [1, 2], true)) {
            $query->where('categories.status', (int)$request->status);
        }

        $scope = $request->input('scope');

        if ($scope === 'root') {
            $query->where(function ($q) {
                $q->whereNull('categories.parent')->orWhere('categories.parent', 0);
            });

        } else if ($scope === 'sub') {
            $query->whereNotNull('categories.parent')->where('categories.parent', '>', 0);
        }

        if ($request->filled('parent')) {

            $parent = $request->input('parent');

            if ($parent === 'none' || (int)$parent === 0) {
                $query->where(function ($q) {
                    $q->whereNull('categories.parent')->orWhere('categories.parent', 0);
                });

            } else {
                /*
                 * "Under this category" means the whole branch, not just the
                 * direct children — filtering to a top-level category and
                 * being shown nothing because its children hang off a
                 * subcategory is not what the filter appears to promise.
                 */
                $branch = CategoryTree::descendantIds((int)$parent);
                $branch[] = (int)$parent;

                $query->where(function ($q) use ($branch, $parent) {
                    $q->whereIn('categories.parent', $branch)
                        ->orWhere('categories.id', (int)$parent);
                });
            }
        }

        if ($request->filled('in_nav')) {
            $query->where('categories.show_in_nav', (int)$request->boolean('in_nav'));
        }

        if ($request->filled('has_products')) {

            $method = $request->boolean('has_products') ? 'whereExists' : 'whereNotExists';

            $query->{$method}(function ($q) {
                $q->select(DB::raw(1))->from('product_categories as pc')
                    ->whereRaw('pc.category_id = categories.id');
            });
        }
    }

    /**
     * Name, slug or parent name.
     *
     * Was title only, and against the translated title only when a language
     * header was present — so searching for a slug, which is what an admin
     * usually has to hand from a URL, found nothing.
     */
    private function applySearch($query, Request $request, $lang)
    {
        $term = trim((string)$request->input('q'));

        if ($term === '') {
            return;
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        $query->where(function ($q) use ($like, $lang) {

            $q->where('categories.title', 'LIKE', $like)
                ->orWhere('categories.slug', 'LIKE', $like)
                ->orWhereExists(function ($sub) use ($like) {
                    $sub->select(DB::raw(1))->from('categories as p')
                        ->whereRaw('p.id = categories.parent')
                        ->where('p.title', 'LIKE', $like);
                });

            if ($lang) {
                $q->orWhere('cl.title', 'LIKE', $like);
            }
        });
    }

    private function applySort($query, Request $request)
    {
        $orderBy = $request->input('orderby', 'created_at');
        $direction = strtolower($request->input('type', 'desc')) === 'asc' ? 'asc' : 'desc';

        $column = self::SORTABLE[$orderBy] ?? 'categories.created_at';

        $query->orderBy(DB::raw($column), $direction);

        // A stable tiebreaker, so paging through equal values (every category
        // with display_order 1, say) does not show the same row twice.
        if ($column !== 'categories.id') {
            $query->orderBy('categories.id', 'desc');
        }
    }

    /**
     * The summary cards, every figure counted from the database.
     *
     * The old version counted "total" as roots only while counting "active"
     * across every level, so Published could legitimately exceed Total and the
     * cards did not add up. Root and sub are now separate figures alongside a
     * genuine total, and one grouped query answers all of them.
     */
    private function stats(Request $request, $lang)
    {
        $base = Category::query();

        if ($this->isVendor) {
            $base->where('admin_id', $this->user->id);
        }

        $rows = (clone $base)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN parent IS NULL OR parent = 0 THEN 1 ELSE 0 END) as roots'),
                DB::raw('SUM(CASE WHEN parent IS NOT NULL AND parent > 0 THEN 1 ELSE 0 END) as subs'),
                DB::raw('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active'),
                DB::raw('SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as inactive'),
                DB::raw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent')
            )
            ->setBindings([now()->subDays(30)], 'select')
            ->first();

        $withProducts = (clone $base)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('product_categories as pc')
                    ->whereRaw('pc.category_id = categories.id');
            })->count();

        $total = (int)$rows->total;
        $recent = (int)$rows->recent;
        $previous = max($total - $recent, 0);

        return [
            'total' => $total,
            'total_categories' => (int)$rows->roots,
            'total_subcategories' => (int)$rows->subs,
            'root_categories' => (int)$rows->roots,
            'sub_categories' => (int)$rows->subs,
            'active_categories' => (int)$rows->active,
            'inactive_categories' => (int)$rows->inactive,
            // Kept under its old name: the card that reads it says "Draft".
            'draft_categories' => (int)$rows->inactive,
            'with_products' => $withProducts,
            'without_products' => max($total - $withProducts, 0),
            'new_last_30_days' => $recent,
            'growth' => $previous > 0
                ? (int)round($recent / $previous * 100)
                : ($recent > 0 ? 100 : 0),
        ];
    }

    /**
     * Flat id/title list for a parent-category picker.
     *
     * Two things were wrong with how the admin panel filled that dropdown: it
     * called the paginated list endpoint with limit=all (dragging every row,
     * every subtree and every count along for two columns of data), and it
     * offered every category including the one being edited and everything
     * beneath it — which is exactly the set that creates a loop.
     *
     * `exclude` takes the id being edited and removes it and its descendants.
     */
    public function allCategories(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.view')) {
                return $can;
            }

            $query = Category::query();

            if ($lang) {
                $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.category_id', '=', 'categories.id')
                        ->where('cl.lang', $lang);
                });

                $query->select(
                    'categories.id',
                    'categories.parent',
                    'categories.status',
                    DB::raw('COALESCE(cl.title, categories.title) as title')
                );

            } else {
                $query->select('categories.id', 'categories.parent', 'categories.status', 'categories.title');
            }

            /*
             * Deliberately not scoped to the vendor.
             *
             * The management list is (a vendor manages only their own
             * categories), but this endpoint also fills the category picker on
             * the product and POS screens, where a vendor legitimately files
             * their products under the shop's shared categories. Scoping it
             * would take those away. Ownership is enforced where it matters —
             * on the write endpoints — rather than by hiding names.
             */

            /*
             * Roots only by default, which is what the old endpoint returned
             * and what the picker wants when the tree is two levels deep.
             * `scope=all` is how the new picker asks for every level so it can
             * render an indented tree.
             */
            if ($request->input('scope') !== 'all') {
                $query->where(function ($q) {
                    $q->whereNull('categories.parent')->orWhere('categories.parent', 0);
                });
            }

            if ($request->filled('exclude')) {

                $excludeId = (int)$request->input('exclude');

                $excluded = CategoryTree::descendantIds($excludeId);
                $excluded[] = $excludeId;

                $query->whereNotIn('categories.id', $excluded);
            }

            if ($request->filled('q')) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($request->q)) . '%';

                $query->where(function ($q) use ($like, $lang) {
                    $q->where('categories.title', 'LIKE', $like);
                    if ($lang) {
                        $q->orWhere('cl.title', 'LIKE', $like);
                    }
                });
            }

            $query->orderBy('categories.display_order')->orderBy('categories.title');

            $data = $request->per_page
                ? $query->paginate((int)$request->per_page)
                : $query->get();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.view')) {
                return $can;
            }

            $query = Category::query();

            if ($lang) {
                $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.category_id', '=', 'categories.id')
                        ->where('cl.lang', $lang);
                });

                $query->select(
                    'categories.*',
                    DB::raw('COALESCE(cl.title, categories.title) as title'),
                    DB::raw('COALESCE(cl.meta_title, categories.meta_title) as meta_title'),
                    DB::raw('COALESCE(cl.meta_description, categories.meta_description) as meta_description'),
                    DB::raw('COALESCE(cl.meta_keywords, categories.meta_keywords) as meta_keywords')
                );
            }

            $category = $query->find($id);

            // Null-checked before the ownership test, which dereferences
            // ->admin_id: a vendor opening a deleted id got a 500, not a 404.
            if (is_null($category)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $category)) {
                return $isOwner;
            }

            // Everything the edit form needs that is not a column.
            $category->parent = CategoryTree::normaliseParent($category->parent);
            $category->image_url = FileHelper::imageLink($category->image);
            $category->banner_url = $category->banner ? FileHelper::imageLink($category->banner) : null;
            $category->children_count = Category::where('parent', $category->id)->count();
            $category->products_count = ProductCategory::where('category_id', $category->id)
                ->distinct()->count('product_id');

            /*
             * The whole branch, which is what the form's Danger Zone has to
             * quote: deleting a category affects everything under it, and the
             * direct count alone understates that.
             */
            $branch = CategoryTree::descendantIds($category->id);
            $branch[] = (int)$category->id;

            $category->descendants_count = count($branch) - 1;
            $category->products_in_branch = ProductCategory::whereIn('category_id', $branch)
                ->distinct()->count('product_id');

            return response()->json(new Response($request->token, $category));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Is this slug free?
     *
     * The form showed a permanently green "Slug is available" that was hard
     * coded into the template and checked nothing; a duplicate was only found
     * on save, after the admin had filled in the whole form.
     */
    public function slugAvailable(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'category.view')) {
                return $can;
            }

            $slug = Str::slug((string)$request->input('slug'));

            if ($slug === '') {
                return response()->json(new Response($request->token, [
                    'slug' => '', 'available' => false,
                ]));
            }

            $query = Category::where('slug', $slug);

            if ($request->filled('id')) {
                $query->where('id', '!=', $request->input('id'));
            }

            $taken = $query->exists();

            return response()->json(new Response($request->token, [
                'slug' => $slug,
                'available' => !$taken,
                'suggestion' => $taken ? $this->uniqueSlug($slug, $request->input('id')) : $slug,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * What deleting this category (or these categories) would affect.
     *
     * The confirmation dialog asked "are you sure?" over a name and nothing
     * else. An admin deleting a category with forty products and six
     * subcategories under it saw the same sentence as one deleting an empty
     * draft.
     */
    public function deleteImpact(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.delete')) {
                return $can;
            }

            $ids = $this->idList($id);

            $categories = Category::whereIn('id', $ids)->get();

            if ($categories->isEmpty()) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor) {
                foreach ($categories as $category) {
                    if ($isOwner = Utils::isDataOwner($this->user, $category)) {
                        return $isOwner;
                    }
                }
            }

            return response()->json(new Response(
                $request->token, CategoryTree::deleteImpact($ids)
            ));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    // =====================================================================
    // Writing
    // =====================================================================

    /**
     * Create or update a category.
     */
    public function action(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, $id ? 'category.edit' : 'category.create')) {
                return $can;
            }

            $existing = null;

            if ($id) {

                $existing = Category::find($id);

                if (is_null($existing)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }
            }

            /*
             * Slug before validation, so an admin who never touched the field
             * is not refused for a slug the form was supposed to derive. The
             * validator then still checks the shape of whatever it ends up as.
             */
            if (!$request->filled('slug') && $request->filled('title')) {
                $request->merge(['slug' => Str::slug($request->input('title'))]);
            } else if ($request->filled('slug')) {
                $request->merge(['slug' => Str::slug($request->input('slug'))]);
            }

            if ($validate = Validation::category($request)) {
                return response()->json($validate);
            }

            $parent = CategoryTree::normaliseParent($request->input('parent'));

            if ($problem = CategoryTree::parentProblem($id ? (int)$id : null, $parent, $lang)) {
                return response()->json(Validation::fieldError($request->token, 'parent', $problem, $lang));
            }

            /*
             * A vendor may only nest under a category they own. The picker
             * shows them the shop's shared categories so they can file
             * products, but filing a *category* under someone else's would put
             * their branch inside a tree they do not control.
             */
            if ($parent !== null && $this->isVendor) {

                $parentCategory = Category::find($parent);

                if ($isOwner = Utils::isDataOwner($this->user, $parentCategory)) {
                    return $isOwner;
                }
            }

            if ($this->slugTaken($request->input('slug'), $id)) {
                return response()->json(Validation::fieldError($request->token, 'slug',
                    __('lang.slug_exists', [], $lang), $lang));
            }

            $images = $this->resolveImages($request, $lang, $existing);

            if ($images instanceof \Illuminate\Http\JsonResponse) {
                return $images;
            }

            $attributes = $this->writableAttributes($request);
            $attributes['parent'] = $parent;
            $attributes = array_merge($attributes, $images);

            $category = DB::transaction(function () use ($id, $existing, $attributes, $lang, $request) {

                if ($id) {
                    return $this->updateCategory($existing, $attributes, $lang);
                }

                return $this->createCategory($attributes, $lang, $request);
            });

            return response()->json(new Response($request->token, $this->freshFor($category->id, $lang)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Only the columns the form owns, taken by name.
     *
     * Every value present in the request is written, including the empty ones.
     * The old code ran the payload through array_filter() first, which dropped
     * anything falsy — so a description could be written but never cleared,
     * and every 0/1 toggle (show_in_nav, searchable, enable_filters,
     * show_homepage, allow_promotions) could be switched on and never off.
     */
    private function writableAttributes(Request $request)
    {
        $attributes = [];

        foreach (self::WRITABLE as $field) {

            if (!$request->has($field)) {
                continue;
            }

            $value = $request->input($field);

            if (in_array($field, ['searchable', 'show_in_nav', 'enable_filters', 'show_homepage', 'allow_promotions'], true)) {
                $attributes[$field] = (int)$request->boolean($field);
                continue;
            }

            if (in_array($field, ['status', 'featured', 'in_footer', 'in_frontend', 'display_order', 'products_per_page'], true)) {
                $attributes[$field] = $value === null || $value === '' ? null : (int)$value;
                continue;
            }

            if ($field === 'parent') {
                continue; // decided by the caller, after the hierarchy check
            }

            /*
             * Stored as an instant in the application's timezone, which is
             * what the sweep compares against.
             *
             * A <input type="datetime-local"> has no timezone of its own, so
             * the form sends the admin's wall clock with their UTC offset
             * attached and the conversion is made here. A payload without an
             * offset - an older bundle - parses in the app's timezone, which
             * is exactly what used to happen, so nothing regresses.
             */
            if ($field === 'publish_at') {
                $attributes[$field] = ($value === null || $value === '')
                    ? null
                    : Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
                continue;
            }

            $attributes[$field] = $value === '' ? null : $value;
        }

        // NOT NULL columns with no sensible null.
        foreach (['status' => 2, 'display_order' => 1, 'products_per_page' => 12] as $field => $fallback) {
            if (array_key_exists($field, $attributes) && $attributes[$field] === null) {
                $attributes[$field] = $fallback;
            }
        }

        /*
         * Publishing now cancels any schedule, because it has been overtaken
         * by events: "publish it on Monday" means nothing once the category
         * is live. Leaving the date behind is not merely untidy - the sweep
         * would still be holding an instruction to publish, so an admin who
         * took the category back down a fortnight later would find it public
         * again within five minutes, and nothing on screen would say why.
         */
        if (array_key_exists('status', $attributes)
            && (int)$attributes['status'] === (int)Config::get('constants.status.PUBLIC')) {
            $attributes['publish_at'] = null;
        }

        if (array_key_exists('title', $attributes)) {
            $attributes['title'] = trim((string)$attributes['title']);
        }

        return $attributes;
    }

    /**
     * Decide what happens to the icon and the banner.
     *
     * Three cases per field, and only the first was handled before: a new
     * base64 upload, an explicit removal, and "leave it alone". Removal in
     * particular went nowhere — the form sent null, array_filter dropped it,
     * and the image stayed.
     *
     * @return array|\Illuminate\Http\JsonResponse the columns to write, or a refusal
     */
    private function resolveImages(Request $request, $lang, $existing)
    {
        $columns = [];

        $fields = [
            'icon' => 'image',
            'banner' => 'banner',
        ];

        foreach ($fields as $input => $column) {

            if (!$request->has($input) && !$request->has($column)) {
                continue;
            }

            $value = $request->input($input, $request->input($column));

            if (is_string($value) && str_contains($value, 'base64')) {

                if ($problem = Validation::base64ImageProblem($value, $lang)) {
                    return response()->json(Validation::fieldError($request->token, $input, $problem, $lang));
                }

                $saved = $this->saveBase64Image($value);

                if (!$saved) {
                    return response()->json(Validation::fieldError($request->token, $input,
                        __('lang.invalid_image', [], $lang), $lang));
                }

                $columns[$column] = $saved;

                // The file this replaces is no longer referenced.
                if ($existing && $existing->{$column}) {
                    FileHelper::deleteFile($existing->{$column});
                }

                continue;
            }

            if ($value === null || $value === '' || $value === 'null') {

                // Explicit removal.
                if ($existing && $existing->{$column}) {
                    FileHelper::deleteFile($existing->{$column});
                }

                $columns[$column] = $column === 'image'
                    ? (string)Config::get('constants.media.DEFAULT_IMAGE')
                    : null;

                continue;
            }

            /*
             * Anything else is the value the form was handed when it loaded —
             * often the full URL of the stored file. Left alone rather than
             * written back, so editing a category does not turn its image
             * column into an absolute URL.
             */
        }

        return $columns;
    }

    private function createCategory(array $attributes, $lang, Request $request)
    {
        $attributes['admin_id'] = $request->user()->id;
        $attributes['id'] = Utils::idGenerator(new Category());

        if (!array_key_exists('image', $attributes) || $attributes['image'] === null) {
            $attributes['image'] = (string)Config::get('constants.media.DEFAULT_IMAGE');
        }

        if ($lang) {

            [$langData, $mainData] = Utils::seperateLangData($attributes, self::TRANSLATABLE);

            /*
             * The default-language columns are written too, not only the
             * translation row. They are what every non-localised read falls
             * back to — the storefront menu, the export, the parent-name
             * subquery — and a category created while a language header was
             * set used to leave categories.title empty.
             */
            $category = Category::create(array_merge($mainData, $langData));

            CategoryLang::create(array_merge($langData, [
                'category_id' => $category->id,
                'lang' => $lang,
            ]));

            return $category;
        }

        return Category::create($attributes);
    }

    private function updateCategory(Category $existing, array $attributes, $lang)
    {
        if ($lang) {

            [$langData, $mainData] = Utils::seperateLangData($attributes, self::TRANSLATABLE);

            if ($mainData) {
                $existing->fill($mainData)->save();
            }

            if ($langData) {

                CategoryLang::updateOrCreate(
                    ['category_id' => $existing->id, 'lang' => $lang],
                    $langData
                );
            }

            return $existing->fresh();
        }

        // fill()+save() rather than a query-builder update, so $fillable is
        // still the last line of defence over what may be written.
        $existing->fill($attributes)->save();

        return $existing->fresh();
    }

    /**
     * Move a category to a different parent, or to the top level.
     *
     * This endpoint had no permission check, no ownership check, no null
     * check, no self-parent check and no cycle check — and it reassigned
     * admin_id to whoever called it, so re-parenting a category took it off
     * its owner. It is the same operation as editing the parent field on the
     * form, so it answers to the same rules.
     */
    public function updateParent(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.edit')) {
                return $can;
            }

            $category = Category::find($request->input('id'));

            if (is_null($category)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $category)) {
                return $isOwner;
            }

            $parent = CategoryTree::normaliseParent($request->input('parent_id'));

            if ($problem = CategoryTree::parentProblem((int)$category->id, $parent, $lang)) {
                return response()->json(Validation::error($request->token, $problem, 'parent'));
            }

            if ($parent !== null && $this->isVendor) {

                $parentCategory = Category::find($parent);

                if ($isOwner = Utils::isDataOwner($this->user, $parentCategory)) {
                    return $isOwner;
                }
            }

            $category->parent = $parent;
            $category->save();

            return response()->json(new Response($request->token, $this->freshFor($category->id, $lang)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Publish or unpublish, one category or several.
     *
     * The list had no way to do this at all — changing a category's status
     * meant opening the form and saving the whole record, which re-ran every
     * other validation rule and could fail for a reason unrelated to the
     * toggle the admin actually touched.
     *
     * Gated on category.edit: a status change is an edit, and hiding the
     * toggle in the UI is not a permission check.
     */
    public function updateStatus(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.edit')) {
                return $can;
            }

            $status = (int)$request->input('status');

            if (!in_array($status, [1, 2], true)) {
                return response()->json(Validation::errorLang($lang));
            }

            $ids = $this->idList($request->input('id'));

            if (!$ids) {
                return response()->json(Validation::noDataLang($lang));
            }

            $categories = Category::whereIn('id', $ids)->get();

            if ($categories->count() !== count($ids)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor) {
                foreach ($categories as $category) {
                    if ($isOwner = Utils::isDataOwner($this->user, $category)) {
                        return $isOwner;
                    }
                }
            }

            $affected = DB::transaction(function () use ($ids, $status, $request) {

                /*
                 * Publishing from the list cancels a pending schedule too, for
                 * the same reason the form does: an instruction to publish is
                 * spent once the category is live, and one left armed would
                 * quietly undo the next unpublish.
                 */
                $columns = ['status' => $status];

                if ($status === (int)Config::get('constants.status.PUBLIC')) {
                    $columns['publish_at'] = null;
                }

                $updated = Category::whereIn('id', $ids)->update($columns);

                /*
                 * Unpublishing a parent hides it from the storefront; leaving
                 * its children published leaves them reachable through a menu
                 * whose top level is gone. `cascade` is opt-in so the list's
                 * single-row toggle stays a single-row change.
                 */
                if ($status === 2 && $request->boolean('cascade')) {

                    $descendants = [];

                    foreach ($ids as $id) {
                        $descendants = array_merge($descendants, CategoryTree::descendantIds($id));
                    }

                    if ($descendants) {
                        $updated += Category::whereIn('id', array_unique($descendants))
                            ->update(['status' => $status]);
                    }
                }

                return $updated;
            });

            return response()->json(new Response($request->token, [
                'updated' => $affected,
                'status' => $status,
                'message' => __('lang.category_status_updated', [], $lang),
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Delete one or more categories.
     *
     * Two things were wrong and both lost data silently.
     *
     * The children of a deleted category were set to `parent = 0` — not NULL,
     * which is what "root" means here, and not a real id either. Those rows
     * were then invisible: the list looks for roots with whereNull('parent'),
     * so they were neither a root nor anybody's child, and the only way to
     * find them again was the database. They are re-parented onto the deleted
     * category's own parent now, which keeps the branch attached where it was.
     *
     * The whole thing also ran outside a transaction, one category at a time,
     * returning early on the first failure — so a bulk delete could remove
     * three categories' product links and then stop, leaving the fourth intact
     * and the first three's products uncategorised with no way back.
     */
    public function delete(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.delete')) {
                return $can;
            }

            $ids = $this->idList($id);

            if (!$ids) {
                return response()->json(Validation::noDataLang($lang));
            }

            $categories = Category::whereIn('id', $ids)->get();

            if ($categories->count() !== count($ids)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor) {
                foreach ($categories as $category) {
                    if ($isOwner = Utils::isDataOwner($this->user, $category)) {
                        return $isOwner;
                    }
                }
            }

            /*
             * What to do with the subcategories, stated by the caller rather
             * than assumed:
             *   reparent (default) - they move up to the deleted category's parent
             *   cascade            - they are deleted with it
             *   block              - the delete is refused while any exist
             */
            $mode = $request->input('children', 'reparent');

            if (!in_array($mode, ['reparent', 'cascade', 'block'], true)) {
                $mode = 'reparent';
            }

            $impact = CategoryTree::deleteImpact($ids);

            if ($mode === 'block' && $impact['subcategories'] > 0) {
                return response()->json(Validation::error($request->token,
                    __('lang.category_has_children', [], $lang), 'children'));
            }

            $removed = DB::transaction(function () use ($categories, $ids, $mode, $impact) {

                $toDelete = $ids;

                if ($mode === 'cascade') {
                    $toDelete = array_values(array_unique(
                        array_merge($ids, $impact['subcategory_ids'])
                    ));
                }

                $records = Category::whereIn('id', $toDelete)->get();

                foreach ($records as $category) {

                    if ($mode !== 'cascade') {
                        // Children move up to where their parent sat.
                        Category::where('parent', $category->id)
                            ->update(['parent' => CategoryTree::normaliseParent($category->parent)]);
                    }

                    HomeSliderSourceCategory::where('category_id', $category->id)->delete();
                    BannerSourceCategory::where('category_id', $category->id)->delete();
                    ProductCategory::where('category_id', $category->id)->delete();
                    CategoryLang::where('category_id', $category->id)->delete();

                    // Legacy sub_categories table, kept in step while it exists.
                    foreach (SubCategory::where('category_id', $category->id)->get() as $sub) {
                        HomeSliderSourceSubCategory::where('sub_category_id', $sub->id)->delete();
                        BannerSourceSubCategory::where('sub_category_id', $sub->id)->delete();
                        SubCategoryLang::where('sub_category_id', $sub->id)->delete();

                        $image = $sub->image;
                        $sub->delete();
                        FileHelper::deleteFile($image);
                    }
                }

                /*
                 * Files are deleted after the rows, and only once the whole
                 * transaction has something to show for it: deleting the file
                 * first and then failing the row leaves a category pointing at
                 * an image that is gone.
                 */
                $files = $records->pluck('image')->merge($records->pluck('banner'))
                    ->filter()->unique()->all();

                Category::whereIn('id', $toDelete)->delete();

                foreach ($files as $file) {
                    if ($file !== Config::get('constants.media.DEFAULT_IMAGE')) {
                        FileHelper::deleteFile($file);
                    }
                }

                return count($toDelete);
            });

            return response()->json(new Response($request->token, [
                'deleted' => $removed,
                'children_mode' => $mode,
                'message' => __('lang.category_deleted', [], $lang),
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Multipart image upload, for the callers that post a file rather than a
     * data URL.
     *
     * Was `Category::create($request->all())` and `$category->update(
     * $request->all())` — the whole request body written to the row, so a
     * caller posting a photo could also set admin_id, parent, slug or status
     * in the same call and bypass every check the form endpoint makes.
     */
    public function upload(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');

            if ($validate = Validation::image($request)) {
                return response()->json($validate);
            }

            $category = $id ? Category::find($id) : null;

            if ($id && is_null($category)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($can = Utils::userCan($this->user, $category ? 'category.edit' : 'category.create')) {
                return $can;
            }

            if ($category && $this->isVendor && $isOwner = Utils::isDataOwner($this->user, $category)) {
                return $isOwner;
            }

            $info = FileHelper::uploadImage($request['photo'], 'category');

            if ($category) {

                $previous = $category->image;

                $category->image = $info['name'];
                $category->save();

                if ($previous && $previous !== Config::get('constants.media.DEFAULT_IMAGE')) {
                    FileHelper::deleteFile($previous);
                }

            } else {

                $title = trim((string)$request->input('title'));

                $category = Category::create([
                    'id' => Utils::idGenerator(new Category()),
                    'admin_id' => $request->user()->id,
                    'title' => $title,
                    'slug' => $this->uniqueSlug(Str::slug($title ?: 'category')),
                    'image' => $info['name'],
                    'status' => Config::get('constants.status.PRIVATE'),
                ]);
            }

            return response()->json(new Response($request->token, $this->freshFor($category->id, $lang)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /** "1,2,3" or [1,2,3] to a clean list of ids. */
    private function idList($id)
    {
        $ids = is_array($id) ? $id : explode(',', (string)$id);

        return array_values(array_unique(array_filter(
            array_map('trim', $ids),
            function ($value) {
                return $value !== '' && ctype_digit((string)$value);
            }
        )));
    }

    private function slugTaken($slug, $excludeId = null)
    {
        $query = Category::where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /** The given slug, or the first free "-2", "-3"… after it. */
    private function uniqueSlug($slug, $excludeId = null)
    {
        $slug = $slug ?: 'category';

        if (!$this->slugTaken($slug, $excludeId)) {
            return $slug;
        }

        for ($suffix = 2; $suffix < 100; $suffix++) {

            $candidate = $slug . '-' . $suffix;

            if (!$this->slugTaken($candidate, $excludeId)) {
                return $candidate;
            }
        }

        return $slug . '-' . Str::lower(Str::random(6));
    }

    /** The row as `find` would return it, for a write endpoint's response. */
    private function freshFor($id, $lang)
    {
        $query = Category::query();

        if ($lang) {
            $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                $join->on('cl.category_id', '=', 'categories.id')
                    ->where('cl.lang', $lang);
            });

            $query->select(
                'categories.*',
                DB::raw('COALESCE(cl.title, categories.title) as title'),
                DB::raw('COALESCE(cl.meta_title, categories.meta_title) as meta_title'),
                DB::raw('COALESCE(cl.meta_description, categories.meta_description) as meta_description'),
                DB::raw('COALESCE(cl.meta_keywords, categories.meta_keywords) as meta_keywords')
            );
        }

        $category = $query->find($id);

        if ($category) {
            $category->parent = CategoryTree::normaliseParent($category->parent);
            $category->image_url = FileHelper::imageLink($category->image);
            $category->banner_url = $category->banner ? FileHelper::imageLink($category->banner) : null;
        }

        return $category;
    }

    /**
     * Write a data URL to the uploads folder.
     *
     * The type and size have already been passed by
     * Validation::base64ImageProblem; this only writes. The extension comes
     * from the whitelist there rather than from the payload, and the folder is
     * created 0755 rather than 0777.
     */
    private function saveBase64Image($base64, $folder = 'uploads')
    {
        if (!preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,/', $base64, $type)) {
            return null;
        }

        $extension = strtolower(explode('+', $type[1])[0]);

        if (!in_array($extension, Validation::IMAGE_TYPES, true)) {
            return null;
        }

        // Some browsers still do not encode AVIF the server can read back.
        if ($extension === 'avif') {
            $extension = 'png';
        }

        $binary = base64_decode(str_replace(' ', '+', substr($base64, strlen($type[0]))), true);

        if ($binary === false || $binary === '') {
            return null;
        }

        $path = base_path($folder);

        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $name = uniqid('cat_', true) . '.' . $extension;

        return file_put_contents($path . '/' . $name, $binary) === false ? null : $name;
    }
}
