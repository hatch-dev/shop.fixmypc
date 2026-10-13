<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ContactUs;
use App\Models\Helper\FileHelper;
use App\Models\Helper\MailBrand;
use App\Models\Helper\MailDeliverability;
use App\Models\Helper\MailHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Language;
use App\Models\Licence;
use App\Models\Order;
use App\Models\Permission;
use App\Models\OrderedProduct;
use App\Models\Plugin;
use App\Models\PosSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SiteSetting;
use App\Models\Store;
use App\Models\StoreLang;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalAccount;
use Carbon\Carbon;
use Faker\Extension\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\QueryException;
use Laravel\Passport\Passport;
use Mail;


class AdminsController extends Controller
{

    public $user;
    public $isVendor = false;
    public $isSuperAdmin = false;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->user = Auth::guard('admin')->user();
            if ($this->user) {
                foreach ($this->user->roles->pluck('name') as $i) {
                    if ($i == 'vendor') {
                        $this->isVendor = true;
                        break;
                    } else if ($i == 'superadmin') {
                        $this->isSuperAdmin = true;
                        break;
                    }
                }
            }
            return $next($request);
        });
    }


    public function clearCache(Request $request)
    {
        Artisan::call('config:cache');
        Artisan::call('config:clear');
        // Artisan::call('route:cache');
        Artisan::call('route:clear');
        Artisan::call('cache:clear');
       
        return response()->json(new Response($request->token, true))->header('Cache-Control', 'no-cache, must-revalidate');
    }


    /**
     * Inventory alerts for the dashboard, and for the full alerts page.
     *
     * Three distinct business states, not one "below a number" bucket:
     *
     *   negative - a tracked variant is oversold. More units have been sold
     *              than the inventory record holds, which is a data problem to
     *              investigate, not a restocking task.
     *   out      - a tracked variant sits at exactly zero.
     *   low      - a tracked variant is between 1 and the threshold.
     *
     * Two rules make the difference between this and the old query, which
     * simply summed every inventory row and reported anything <= 5:
     *
     *  - Back-order variants are excluded. `updated_inventories.is_active = 1`
     *    means back-order is allowed for that variant: the cart deliberately
     *    lets those lines go negative and the storefront lists them as in
     *    stock. Alerting on them reported the shop's own policy as a fault -
     *    54 of the 107 inventory rows here are back-order, 3 of them already
     *    negative by design.
     *
     *  - Severity comes from the WORST variant, never from the sum. A product
     *    with one variant at -13 and another at +50 sums to a healthy 37 while
     *    being oversold; summing hid exactly the row that needed attention.
     *
     * A product with no inventory rows at all counts as out of stock, which is
     * the rule the products list already applies for `stock=out`.
     */
    public function lowStockProducts(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'dashboard.view')) {
                return $can;
            }

            $threshold = $request->filled('threshold')
                ? max(0, (int)$request->threshold)
                : (int)Config::get('constants.inventory.lowStockThreshold');

            $limit = $request->filled('limit') ? (int)$request->limit : 5;
            $limit = max(1, min($limit, 200));

            $filter = in_array($request->filter, ['low', 'out', 'negative'], true)
                ? $request->filter
                : 'all';

            // Tracked variants only: a back-order row is not an alert.
            $trackedRows = DB::table('updated_inventories')
                ->select('product_id', 'quantity', 'sku')
                ->where('is_active', '!=', Config::get('constants.status.PUBLIC'));

            $productQuery = Product::query()
                ->where('products.status', Config::get('constants.status.PUBLIC'));

            if (!$this->isSuperAdmin) {
                $productQuery->where('products.admin_id', $this->user->id);
            }

            $products = $productQuery
                ->select('products.id', 'products.title', 'products.image')
                ->get()
                ->keyBy('id');

            if ($products->isEmpty()) {
                return response()->json(new Response($request->token, [
                    'items' => [],
                    'total' => 0,
                    'counts' => ['negative' => 0, 'out' => 0, 'low' => 0, 'all' => 0],
                    'threshold' => $threshold,
                    'counts_by' => 'product',
                ]));
            }

            $rowsByProduct = $trackedRows
                ->whereIn('product_id', $products->keys()->all())
                ->get()
                ->groupBy('product_id');

            $alerts = [];

            foreach ($products as $productId => $product) {
                $rows = $rowsByProduct->get($productId);

                /*
                 * No tracked inventory row at all. Either the product has no
                 * inventory records (the products list calls that out of
                 * stock) or every one of its variants is back-order, in which
                 * case it is deliberately unlimited and not an alert.
                 */
                if (is_null($rows) || $rows->isEmpty()) {
                    $hasAnyRow = DB::table('updated_inventories')
                        ->where('product_id', $productId)->exists();

                    if ($hasAnyRow) {
                        continue; // back-order only: intentionally unlimited
                    }

                    $alerts[] = [
                        'id' => $productId,
                        'title' => $product->title,
                        'image' => $product->image,
                        'severity' => 'out',
                        'stock' => 0,
                        'oversold_by' => 0,
                        'sku' => null,
                        'variant_count' => 0,
                        'affected_variants' => 0,
                        'total_stock' => 0,
                    ];
                    continue;
                }

                // The worst variant decides the product's state.
                $worst = null;
                $totalStock = 0;
                $affected = 0;

                foreach ($rows as $row) {
                    $qty = (int)$row->quantity;
                    $totalStock += $qty;

                    $severity = null;
                    if ($qty < 0) {
                        $severity = 'negative';
                    } else if ($qty === 0) {
                        $severity = 'out';
                    } else if ($qty <= $threshold) {
                        $severity = 'low';
                    }

                    if (is_null($severity)) {
                        continue;
                    }

                    $affected++;

                    $rank = ['low' => 1, 'out' => 2, 'negative' => 3];
                    if (is_null($worst)
                        || $rank[$severity] > $rank[$worst['severity']]
                        || ($rank[$severity] === $rank[$worst['severity']] && $qty < $worst['quantity'])) {
                        $worst = ['severity' => $severity, 'quantity' => $qty, 'sku' => $row->sku];
                    }
                }

                if (is_null($worst)) {
                    continue;
                }

                $alerts[] = [
                    'id' => $productId,
                    'title' => $product->title,
                    'image' => $product->image,
                    'severity' => $worst['severity'],
                    // The worst variant's level - what the row reports on.
                    'stock' => $worst['quantity'],
                    'oversold_by' => $worst['quantity'] < 0 ? abs($worst['quantity']) : 0,
                    'sku' => $worst['sku'],
                    'variant_count' => $rows->count(),
                    'affected_variants' => $affected,
                    // Kept for context: a product can be oversold on one
                    // variant while holding stock on another.
                    'total_stock' => $totalStock,
                ];
            }

            $counts = [
                'negative' => count(array_filter($alerts, fn($a) => $a['severity'] === 'negative')),
                'out' => count(array_filter($alerts, fn($a) => $a['severity'] === 'out')),
                'low' => count(array_filter($alerts, fn($a) => $a['severity'] === 'low')),
            ];
            $counts['all'] = count($alerts);

            if ($filter !== 'all') {
                $alerts = array_values(array_filter($alerts, fn($a) => $a['severity'] === $filter));
            }

            /*
             * Severity first - an oversell is worse than an empty shelf, which
             * is worse than a thin one - then the lowest stock within a band,
             * then id so the order cannot shuffle between refreshes.
             */
            $rank = ['negative' => 0, 'out' => 1, 'low' => 2];
            usort($alerts, function ($a, $b) use ($rank) {
                if ($rank[$a['severity']] !== $rank[$b['severity']]) {
                    return $rank[$a['severity']] - $rank[$b['severity']];
                }
                if ($a['stock'] !== $b['stock']) {
                    return $a['stock'] - $b['stock'];
                }
                return $a['id'] - $b['id'];
            });

            $total = $filter === 'all' ? $counts['all'] : $counts[$filter];

            return response()->json(new Response($request->token, [
                'items' => array_slice($alerts, 0, $limit),
                // The badge counts PRODUCTS needing attention, matching the
                // rows the panel lists. `counts` breaks the same set down by
                // severity so a filter tab never has to guess.
                'total' => $total,
                'counts' => $counts,
                'threshold' => $threshold,
                'counts_by' => 'product',
                'filter' => $filter,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function statistic(Request $request)
    {
        try {

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'dashboard.view')) {
                return $can;
            }

            $data = [];
            $time = null;
            $endTime = null;
            $appliedRange = null;

            /*
             * Same resolver the Sales Overview uses, so one date-range control
             * can drive both widgets and "last 30 days" cannot come to mean two
             * different windows on one screen. Both bounds are UTC and the upper
             * one is exclusive, so consecutive periods never double-count an
             * order and the whole of the end day is included.
             */
            if ($request->range_preset || ($request->range_start && $request->range_end)) {
                $appliedRange = $this->resolveDateRange($request);

                if ($appliedRange) {
                    $time = $appliedRange['start_utc'];
                    $endTime = $appliedRange['end_utc'];
                }
            } else {
                // Legacy shape: a raw ISO instant, optionally with an end.
                if ($request->time_zone && $request->time) {
                    $time = Utils::convertTimeToUTCzone($request->time, $request->time_zone);
                }

                if ($request->time_zone && $request->end_time) {
                    $endTime = Utils::convertTimeToUTCzone($request->end_time, $request->time_zone);
                }
            }

            if (!$this->isSuperAdmin) {
                $cancelled = Order::join('ordered_products as op', function ($join) {
                    $join->on('op.order_id', '=', 'orders.id');
                    $join->join('products as p', function ($join2) {
                        $join2->on('p.id', '=', 'op.product_id');
                        $join2->where('p.admin_id', $this->user->id);
                    });
                })
                    ->where('orders.cancelled', Config::get('constants.status.PUBLIC'));

                $statistics = Order::join('ordered_products as op', function ($join) {
                    $join->on('op.order_id', '=', 'orders.id');
                    $join->join('products as p', function ($join2) {
                        $join2->on('p.id', '=', 'op.product_id');
                        $join2->where('p.admin_id', $this->user->id);
                    });
                })
                    ->where('orders.cancelled', '!=', Config::get('constants.status.PUBLIC'))
                    ->select(
                        "orders.status",
                        // distinct: the ordered_products join repeats an order once
                        // per line item the vendor owns, which inflated every count
                        DB::raw("(count(distinct orders.id)) as total")
                    )
                    ->orderBy('orders.status')
                    ->groupBy('orders.status');

            } else {
                $cancelled = Order::where('cancelled', Config::get('constants.status.PUBLIC'));

                $statistics = Order::where('cancelled', '!=', Config::get('constants.status.PUBLIC'))
                    ->select(
                        "status",
                        DB::raw("(count(distinct id)) as total")
                    )
                    ->orderBy('status')
                    ->groupBy('status');
            }


            // Top sellers must not count cancelled orders: the Order Statistics
            // block reports those separately, so counting them here made the
            // category/brand/product tables disagree with it.
            /*
             * Fetching category list.
             *
             * Exactly one category per product, so a sale is counted once.
             * Joining product_categories directly credited the full amount to
             * every category a product sits in, so a phone filed under both
             * "Mobile" and its "Apple" subcategory turned one 699 order into
             * two 699 rows and the AMOUNT column summed to more than the shop
             * had taken. 19 of the catalogue's 69 categorised products carry
             * two categories, so this was not a rare shape.
             *
             * The category chosen is the one the app already treats as
             * authoritative - product_categories.primary, the same flag the
             * storefront uses for breadcrumbs. Seven products have no primary
             * set; those fall back to their lowest category id, which is
             * arbitrary but stable, so the panel cannot reorder between loads.
             */
            $primaryCategoryPerProduct = DB::table('product_categories')
                ->selectRaw('product_id, COALESCE(MAX(CASE WHEN `primary` = 1 THEN category_id END), MIN(category_id)) as category_id')
                ->groupBy('product_id');

            $q = OrderedProduct::joinSub($primaryCategoryPerProduct, 'pc', function ($join) {
                $join->on('pc.product_id', '=', 'ordered_products.product_id');
            })
                ->join('products as p', function ($join) {
                    $join->on('p.id', '=', 'ordered_products.product_id');
                })
                ->join('categories as c', function ($join) {
                    $join->on('c.id', '=', 'pc.category_id');
                })
                ->join('orders as o', function ($join) {
                    $join->on('o.id', '=', 'ordered_products.order_id');
                    $join->where('o.cancelled', '!=', Config::get('constants.status.PUBLIC'));
                    // A deleted order is not a sale.
                    $join->whereNull('o.deleted_at');
                });

            if (!$this->isSuperAdmin) {
                $q = $q->where('p.admin_id', $this->user->id);
            }

            $categoryQuery = ['ordered_products.product_id',
                'ordered_products.selling as price',
                'ordered_products.created_at',
                'p.id',
                'pc.category_id',
                'c.id',
                'c.image',
                'c.title',
                DB::raw("(SUM(ordered_products.quantity)) as total"),
                DB::raw("(SUM(ordered_products.selling * ordered_products.quantity)) as total_price")];


            if ($lang) {
                $q = $q->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.category_id', '=', 'c.id');
                    $join->where('cl.lang', $lang);
                });

                array_push($categoryQuery, 'cl.title');
            }


            if (!is_null($time)) {
                $cancelled = $cancelled->where('orders.created_at', '>=', $time);
                $statistics = $statistics->where('orders.created_at', '>=', $time);
            }

            if (!is_null($endTime)) {
                $cancelled = $cancelled->where('orders.created_at', '<', $endTime);
                $statistics = $statistics->where('orders.created_at', '<', $endTime);
            }


            // Fetching brands list
            $brandQuery = OrderedProduct::join('products as p', function ($join) {
                $join->on('p.id', '=', 'ordered_products.product_id');
            })
                ->join('brands as b', function ($join) {
                    $join->on('b.id', '=', 'p.brand_id');
                })->join('orders as o', function ($join) {
                    $join->on('o.id', '=', 'ordered_products.order_id');
                    $join->where('o.cancelled', '!=', Config::get('constants.status.PUBLIC'));
                    $join->whereNull('o.deleted_at');
                });


            if (!$this->isSuperAdmin) {
                $brandQuery = $brandQuery->where('p.admin_id', $this->user->id);
            }


            $brandSelect = ['ordered_products.product_id',
                'ordered_products.selling as price',
                'ordered_products.created_at',
                'p.id',
                'p.brand_id',
                'b.id',
                'b.image',
                'b.title',
                DB::raw("(SUM(ordered_products.quantity)) as total"),
                DB::raw("(SUM(ordered_products.selling * ordered_products.quantity)) as total_price")];


            if ($lang) {
                $brandQuery = $brandQuery->leftJoin('brand_langs as bl', function ($join) use ($lang) {
                    $join->on('bl.brand_id', '=', 'b.id');
                    $join->where('bl.lang', $lang);
                });

                array_push($brandSelect, 'bl.title');
            }

            $brandQuery = $brandQuery->select($brandSelect);

            // Fetching product list
            $productQuery = OrderedProduct::join('products as p', function ($join) {
                $join->on('p.id', '=', 'ordered_products.product_id');

                if (!$this->isSuperAdmin) {
                    $join->where('p.admin_id', $this->user->id);
                }
            })->join('orders as o', function ($join) {
                $join->on('o.id', '=', 'ordered_products.order_id');
                $join->where('o.cancelled', '!=', Config::get('constants.status.PUBLIC'));
                $join->whereNull('o.deleted_at');
            });

            if (!$this->isSuperAdmin) {
                $productQuery = $productQuery->where('p.admin_id', $this->user->id);
            }


            $productSelect = ['ordered_products.product_id',
                'ordered_products.selling as price',
                'ordered_products.created_at',
                'p.id',
                'p.image',
                'p.title',
                /*
                 * The product's first gallery image, as a second chance at a
                 * picture. `products.image` points at a file that is simply
                 * absent for part of this catalogue, and where that happens a
                 * gallery row sometimes still names one that is on disk. The
                 * thumbnail tries this before giving up and drawing initials.
                 */
                DB::raw("(SELECT pi.image FROM product_images pi
                          WHERE pi.product_id = p.id
                          ORDER BY pi.id ASC LIMIT 1) as fallback_image"),
                DB::raw("(SUM(ordered_products.quantity)) as total"),
                DB::raw("(SUM(ordered_products.selling * ordered_products.quantity)) as total_price"),
                /*
                 * Money actually taken for this product, and money still owed
                 * on it. "Top Products by Revenue" ranks on revenue, and an
                 * uncollected cash-on-delivery line is not revenue yet - the
                 * panel credited the full order value the moment the order
                 * was placed.
                 */
                DB::raw("(SUM(CASE WHEN o.payment_done = 1
                                   THEN ordered_products.selling * ordered_products.quantity
                                   ELSE 0 END)) as received_price"),
                DB::raw("(SUM(CASE WHEN o.payment_done = 1
                                   THEN 0
                                   ELSE ordered_products.selling * ordered_products.quantity END)) as pending_price")];


            $posPlugin = Plugin::where('name', 'pos')->first();

            $posLicenceValid = false;

            $baseURL = $request->url('/');
            $parse = parse_url($baseURL);
            $domain = $parse['host'];

            $isLocalhost = strpos($domain, "localhost") !== false || strpos($domain, "127.0.0.1") !== false;

            if($isLocalhost) {
                $posLicenceValid = true;
            } else if($posPlugin) {
                $validLicence = Utils::decryptLicence($posPlugin->secret_key,
                    $posPlugin->encrypt_key, $posPlugin->encrypt_iv);

                if ($validLicence && $validLicence->d === $domain) {
                    $posLicenceValid = true;
                }
            }



            if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                array_push($categoryQuery, 'o.pos_order_id');
                array_push($brandSelect, 'o.pos_order_id');
                array_push($productSelect, 'o.pos_order_id');
            }

            if ($lang) {
                $productQuery = $productQuery->leftJoin('product_langs as trl', function ($join) use ($lang) {
                    $join->on('trl.product_id', '=', 'p.id');
                    $join->where('trl.lang', $lang);
                });

                array_push($productSelect, 'trl.title');
            }


            $productQuery = $productQuery->select($productSelect);


            if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                if (!$request->order_type || ($request->order_type && $request->order_type == 'website')) {
                    $q = $q->where('o.pos_order_id', null);
                    $brandQuery = $brandQuery->where('o.pos_order_id', null);
                    $productQuery = $productQuery->where('o.pos_order_id', null);
                    $cancelled = $cancelled->where('pos_order_id', null);
                    $statistics = $statistics->where('pos_order_id', null);

                } else if ($request->order_type && $request->order_type == 'pos') {
                    $q = $q->where('o.pos_order_id', '!=', null);
                    $brandQuery = $brandQuery->where('o.pos_order_id', '!=', null);
                    $productQuery = $productQuery->where('o.pos_order_id', '!=', null);
                    $cancelled = $cancelled->where('pos_order_id', '!=', null);
                    $statistics = $statistics->where('pos_order_id', '!=', null);
                }
            }


            $categories = $q->select($categoryQuery)
                ->orderBy('total_price', 'DESC')
                // tie-breaker: equal-revenue rows were free to swap places
                // between refreshes, so the tables reshuffled on every load
                ->orderBy('c.id')
                ->groupBy('c.id')
                ->limit(Config::get('constants.pagination.DASHBOARD'));


            if (!is_null($time)) {
                $categories = $categories->where('ordered_products.created_at', '>=', $time);
            }

            if (!is_null($endTime)) {
                $categories = $categories->where('ordered_products.created_at', '<', $endTime);
            }


            $brands = $brandQuery->orderBy('total_price', 'DESC')
                // tie-breaker: equal-revenue rows were free to swap places
                // between refreshes, so the tables reshuffled on every load
                ->orderBy('b.id')
                ->groupBy('b.id')
                ->limit(Config::get('constants.pagination.DASHBOARD'));

            if (!is_null($time)) {
                $brands = $brands->where('ordered_products.created_at', '>=', $time);
            }

            if (!is_null($endTime)) {
                $brands = $brands->where('ordered_products.created_at', '<', $endTime);
            }


            $products = $productQuery->orderBy('total_price', 'DESC')
                // tie-breaker: equal-revenue rows were free to swap places
                // between refreshes, so the tables reshuffled on every load
                ->orderBy('p.id')
                ->groupBy('p.id')
                ->limit(Config::get('constants.pagination.DASHBOARD'));

            if (!is_null($time)) {
                $products = $products->where('ordered_products.created_at', '>=', $time);
            }

            if (!is_null($endTime)) {
                $products = $products->where('ordered_products.created_at', '<', $endTime);
            }

            $data['statistics'] = $statistics->get();
            $data['cancelled'] = $cancelled->distinct()->count('orders.id');
            $data['categories'] = $categories->get();

            $data['brands'] = $brands->get();


            $data['products'] = $products->get();

            // Period-over-period growth per order status + cancelled.
            //
            // The badge has to compare the same window the cards are showing. With a
            // range selected (Today / Last week / ...) the cards count [$time, now],
            // so the comparison window is the equally long stretch immediately
            // before it. With no range selected there is no natural previous window,
            // so fall back to this month vs last month.
            $curStart = date('Y-m-01 00:00:00');
            $curEnd = null;
            $prevStart = date('Y-m-01 00:00:00', strtotime('first day of last month'));

            if (!is_null($time)) {
                $curStart = $time;
                $curEnd = $endTime;

                $startTs = strtotime($time . ' UTC');
                $endTs = is_null($endTime) ? time() : strtotime($endTime . ' UTC');
                $span = max(1, $endTs - $startTs);
                $prevStart = gmdate('Y-m-d H:i:s', $startTs - $span);
            }

            $statusCountBase = function ($from, $to, $cancelledFlag) use ($posLicenceValid, $posPlugin, $request) {
                if (!$this->isSuperAdmin) {
                    $query = Order::join('ordered_products as sop', function ($join) {
                        $join->on('sop.order_id', '=', 'orders.id');
                        $join->join('products as sp', function ($join2) {
                            $join2->on('sp.id', '=', 'sop.product_id');
                            $join2->where('sp.admin_id', $this->user->id);
                        });
                    });
                } else {
                    $query = Order::query();
                }

                if ($cancelledFlag) {
                    $query->where('orders.cancelled', Config::get('constants.status.PUBLIC'));
                } else {
                    $query->where('orders.cancelled', '!=', Config::get('constants.status.PUBLIC'));
                }

                $query->where('orders.created_at', '>=', $from);
                if ($to) {
                    $query->where('orders.created_at', '<', $to);
                }

                if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                    if (!$request->order_type || $request->order_type == 'website') {
                        $query->where('orders.pos_order_id', null);
                    } else if ($request->order_type == 'pos') {
                        $query->where('orders.pos_order_id', '!=', null);
                    }
                }

                return $query;
            };

            $curStatus = (clone $statusCountBase($curStart, $curEnd, false))
                ->select('orders.status', DB::raw('count(distinct orders.id) as total'))
                ->groupBy('orders.status')->pluck('total', 'orders.status');
            $prevStatus = (clone $statusCountBase($prevStart, $curStart, false))
                ->select('orders.status', DB::raw('count(distinct orders.id) as total'))
                ->groupBy('orders.status')->pluck('total', 'orders.status');

            // Always include every order status (1..5) so each card shows a badge,
            // even when there were no orders in either period.
            $statusKeys = collect([1, 2, 3, 4, 5])
                ->merge($curStatus->keys())
                ->merge($prevStatus->keys())
                ->unique();
            $statusGrowth = [];
            foreach ($statusKeys as $sk) {
                $statusGrowth[$sk] = $this->growthPercent($curStatus[$sk] ?? 0, $prevStatus[$sk] ?? 0);
            }

            $statusGrowth['cancelled'] = $this->growthPercent(
                $statusCountBase($curStart, $curEnd, true)->distinct()->count('orders.id'),
                $statusCountBase($prevStart, $curStart, true)->distinct()->count('orders.id')
            );

            $data['growth'] = $statusGrowth;

            // Bounds for the custom-range date pickers: no point offering a day
            // before the first order or after today.
            $tzForBounds = $appliedRange['tz'] ?? ($request->time_zone ?: config('app.timezone'));
            try {
                $boundsTz = new \DateTimeZone($tzForBounds);
            } catch (\Exception $e) {
                $boundsTz = new \DateTimeZone('UTC');
            }

            $boundsQuery = Order::query();
            if (!$this->isSuperAdmin) {
                $boundsQuery = Order::whereIn('id', OrderedProduct::join('products as bp', function ($join) {
                    $join->on('bp.id', '=', 'ordered_products.product_id');
                    $join->where('bp.admin_id', $this->user->id);
                })->select('ordered_products.order_id'));
            }
            $bounds = $boundsQuery->selectRaw('MIN(created_at) as first_at, MAX(created_at) as last_at')->first();

            $toLocalDate = function ($utcString) use ($boundsTz) {
                if (empty($utcString)) {
                    return null;
                }
                try {
                    return (new \DateTime($utcString, new \DateTimeZone('UTC')))
                        ->setTimezone($boundsTz)->format('Y-m-d');
                } catch (\Exception $e) {
                    return null;
                }
            };

            $data['data_bounds'] = [
                'first_order' => $toLocalDate($bounds->first_at ?? null),
                'last_order' => $toLocalDate($bounds->last_at ?? null),
                'today' => (new \DateTime('now', $boundsTz))->format('Y-m-d'),
            ];

            // Echo the window back so the UI can label what it is showing, and
            // so a mismatch between control and query is visible rather than
            // having to be inferred from the numbers.
            $data['range'] = $appliedRange ? [
                'preset' => $appliedRange['preset'],
                'start' => $appliedRange['start'],
                'end' => $appliedRange['end'],
                'span_days' => $appliedRange['span_days'],
                'time_zone' => $appliedRange['tz'],
                'utc_from' => $appliedRange['start_utc'],
                'utc_to' => $appliedRange['end_utc'],
            ] : null;

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }



    /**
     * Presets the dashboard date-range filter offers, as [unit, count].
     *
     * Day presets are rolling windows ending today; month presets are whole
     * calendar months including the current one, which is what the chart labels
     * ("Mar ... Sep") have always implied.
     */
    private function rangePresets()
    {
        return [
            // The short list the dashboard offers.
            'today' => ['rolling', 1],
            'yesterday' => ['single_past', 1],
            'week' => ['this_week', 0],
            'month' => ['this_month', 0],
            'd30' => ['rolling', 30],
            'year' => ['this_year', 0],

            /*
             * Retired keys, kept resolvable so a cached admin bundle from
             * before the picker was simplified still charts something sensible
             * instead of silently falling back to the default.
             */
            'd7' => ['rolling', 7],
            'd14' => ['rolling', 14],
            'd90' => ['rolling', 90],
            'm3' => ['calendar_months', 3],
            'm6' => ['calendar_months', 6],
            'm7' => ['calendar_months', 7],
            'm12' => ['calendar_months', 12],
            'm24' => ['calendar_months', 24],
        ];
    }

    /**
     * Resolve a preset key to [start, end] local dates, both inclusive.
     *
     * "This week" starts on Monday so it lines up with the week buckets the
     * chart builds; "this month" and "this year" run from their first day up to
     * today rather than to the end of the period, because the rest of the
     * period has not happened yet.
     */
    private function applyPreset($key, \DateTime $today)
    {
        $presets = $this->rangePresets();
        if (!isset($presets[$key])) {
            return null;
        }

        [$type, $count] = $presets[$key];
        $start = clone $today;
        $end = clone $today;

        switch ($type) {
            case 'rolling':
                // A window of $count days ending today, today included.
                $start->modify('-' . ($count - 1) . ' days');
                break;

            case 'single_past':
                $start->modify('-' . $count . ' days');
                $end = clone $start;
                break;

            case 'this_week':
                // format('N') is 1 on Monday
                $start->modify('-' . ((int)$start->format('N') - 1) . ' days');
                break;

            case 'this_month':
                $start->modify('first day of this month');
                break;

            case 'this_year':
                $start->setDate((int)$start->format('Y'), 1, 1);
                break;

            case 'calendar_months':
                $start->modify('first day of this month');
                $start->modify('-' . ($count - 1) . ' months');
                break;
        }

        return [$start, $end];
    }

    /**
     * Turn the request's range parameters into everything the queries need.
     *
     * One resolver for the whole dashboard, because the Sales Overview chart and
     * the Order Statistics block have to agree on what "last 30 days" means. It
     * returns the window twice over: as local calendar dates for labelling, and
     * as a half-open UTC pair for the WHERE clause.
     *
     * The upper bound is exclusive and sits at local midnight of the day AFTER
     * the end date, so an order placed at 23:59:59 on the end date is inside the
     * window - an inclusive "<= end 23:59:59" bound would drop anything with
     * sub-second precision.
     *
     * @return array|null null when the request asks for no range at all
     */
    /**
     * Strict Y-m-d parse in the given zone.
     *
     * createFromFormat happily rolls "2026-13-45" forward into 2027, so reject
     * anything the parser had to correct rather than silently charting a window
     * nobody asked for.
     */
    private function parseLocalDate($value, \DateTimeZone $tz)
    {
        $value = (string)$value;
        if ($value === '') {
            return null;
        }

        $d = \DateTime::createFromFormat('Y-m-d|', $value, $tz);
        if (!$d) {
            return null;
        }

        $errors = \DateTime::getLastErrors();
        if (!empty($errors['warning_count']) || !empty($errors['error_count'])) {
            return null;
        }

        return $d->format('Y-m-d') === $value ? $d : null;
    }

    private function resolveDateRange(Request $request, $default = null)
    {
        $tzName = $request->time_zone ?: config('app.timezone');
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $utc = new \DateTimeZone('UTC');

        $today = new \DateTime('now', $tz);
        $today->setTime(0, 0, 0);

        $presets = $this->rangePresets();
        $preset = $request->range_preset;
        $start = null;
        $end = null;

        $wantsCustom = $preset === 'custom'
            || (!$preset && $request->range_start && $request->range_end);

        if ($wantsCustom) {
            $start = $this->parseLocalDate($request->range_start, $tz);
            $end = $this->parseLocalDate($request->range_end, $tz);

            if ($start && $end) {
                $preset = 'custom';
            } else {
                /*
                 * Unparseable or impossible dates ("2026-13-45", a stray string)
                 * must not take the whole dashboard down with them. Drop back to
                 * the caller's default window; the response echoes the range that
                 * was actually used, so the UI can tell it did not get what it
                 * asked for.
                 */
                $start = null;
                $end = null;
                $preset = null;
            }
        }

        if ($start && $end) {
            // already resolved above

        } else if ($preset === 'all') {
            /*
             * An explicit "no window". Only callers that can run unbounded get
             * null; anything that needs a window falls through to its default.
             */
            if (!$default) {
                return null;
            }
            $preset = null;
        }

        if ($start && $end) {
            // already resolved above

        } else if ($preset && isset($presets[$preset])) {
            [$start, $end] = $this->applyPreset($preset, $today);

        } else if ($request->revenue_unit || $request->revenue_count || $request->revenue_months) {
            // Legacy shape: "<count> units back from now".
            $unit = in_array($request->revenue_unit, ['day', 'month', 'year'], true)
                ? $request->revenue_unit
                : 'month';
            $count = (int)($request->revenue_count ?: $request->revenue_months ?: 0);
            if ($count < 1) {
                $count = ['day' => 14, 'month' => 7, 'year' => 5][$unit];
            }

            $end = clone $today;
            $start = clone $today;
            if ($unit === 'day') {
                $start->modify('-' . ($count - 1) . ' days');
            } else if ($unit === 'month') {
                $start->modify('first day of this month')->modify('-' . ($count - 1) . ' months');
            } else {
                $start->modify('first day of January this year')->modify('-' . ($count - 1) . ' years');
            }
            $preset = 'legacy';

        } else if ($default) {
            [$start, $end] = $this->applyPreset($default, $today);
            $preset = $default;

        } else {
            return null;
        }

        // A range may not run into the future - there is no data there - and may
        // not run backwards.
        if ($end > $today) {
            $end = clone $today;
        }
        if ($start > $end) {
            $start = clone $end;
        }
        // Hard ceiling so a hand-typed year cannot ask for 10,000 buckets.
        $floor = (clone $today)->modify('-20 years');
        if ($start < $floor) {
            $start = $floor;
        }

        $start->setTime(0, 0, 0);
        $end->setTime(0, 0, 0);

        $spanDays = (int)$start->diff($end)->days + 1;

        /*
         * Bucket size follows the span so the axis stays readable: a fortnight
         * gets a bar a day, a quarter a bar a week, a year a bar a month. An
         * explicit revenue_unit still wins for callers that want to force one.
         */
        if (in_array($request->revenue_unit, ['day', 'week', 'month', 'year'], true)
            && $request->range_preset) {
            $unit = $request->revenue_unit;
        } else if ($spanDays <= 31) {
            $unit = 'day';
        } else if ($spanDays <= 92) {
            $unit = 'week';
        } else if ($spanDays <= 1100) {
            $unit = 'month';
        } else {
            $unit = 'year';
        }

        // Half-open UTC bounds covering the whole of both end days.
        $startUtc = (clone $start)->setTimezone($utc)->format('Y-m-d H:i:s');
        $endExclusiveUtc = (clone $end)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

        /*
         * Bucketing happens in SQL, and this MySQL has no named-timezone tables
         * loaded (mysql.time_zone_name is empty), so CONVERT_TZ has to be given a
         * numeric offset. Take it at the end of the window. A range straddling a
         * DST change can therefore misplace an hour's worth of orders at the
         * boundary; everything else is exact.
         */
        $offset = (new \DateTime($end->format('Y-m-d') . ' 12:00:00', $tz))->format('P');

        return [
            'preset' => $preset,
            'unit' => $unit,
            'span_days' => $spanDays,
            'start_local' => $start,
            'end_local' => $end,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'start_utc' => $startUtc,
            'end_utc' => $endExclusiveUtc,
            'tz' => $tz->getName(),
            'tz_offset' => $offset,
        ];
    }

    /**
     * SQL expression for the bucket a row falls in, as a DATE.
     *
     * Every unit resolves to the date the bucket starts on, so the PHP side can
     * iterate and match with one format regardless of granularity.
     */
    private function bucketExpression($column, $unit, $offset)
    {
        $local = "CONVERT_TZ($column, '+00:00', '" . $offset . "')";

        switch ($unit) {
            case 'day':
                return "DATE($local)";
            case 'week':
                // WEEKDAY() is 0 on Monday, so this is the Monday of that week
                return "DATE(DATE_SUB($local, INTERVAL WEEKDAY($local) DAY))";
            case 'year':
                return "DATE(DATE_FORMAT($local, '%Y-01-01'))";
            case 'month':
            default:
                return "DATE(DATE_FORMAT($local, '%Y-%m-01'))";
        }
    }

    /**
     * Every bucket in the window, including the ones with no orders.
     *
     * Gaps have to be emitted, not skipped: a chart that silently drops quiet
     * days draws a misleadingly smooth line and mislabels every bar after the
     * gap. Each bucket also carries its own clamped start/end so a partial first
     * or last week can describe itself honestly.
     */
    private function buildBuckets($range)
    {
        $unit = $range['unit'];
        $start = clone $range['start_local'];
        $end = clone $range['end_local'];

        $cursor = clone $start;
        if ($unit === 'week') {
            $cursor->modify('-' . ((int)$cursor->format('N') - 1) . ' days');
        } else if ($unit === 'month') {
            $cursor->modify('first day of this month');
        } else if ($unit === 'year') {
            $cursor->setDate((int)$cursor->format('Y'), 1, 1);
        }

        $step = ['day' => '+1 day', 'week' => '+7 days', 'month' => '+1 month', 'year' => '+1 year'][$unit];
        $labelFmt = ['day' => 'j M', 'week' => 'j M', 'month' => 'M', 'year' => 'Y'][$unit];

        $buckets = [];
        $guard = 0;

        while ($cursor <= $end && $guard++ < 2000) {
            $bucketEnd = (clone $cursor)->modify($step)->modify('-1 day');

            // The first and last bucket can hang outside the requested window.
            $visibleStart = $cursor < $start ? clone $start : clone $cursor;
            $visibleEnd = $bucketEnd > $end ? clone $end : clone $bucketEnd;

            $buckets[] = [
                /*
                 * `date` is the record's identity: the calendar day the bucket
                 * begins on, in the viewer's timezone, as a plain Y-m-d string.
                 * Axis label, tooltip, value, order count and the DOM key all
                 * derive from this one field, so nothing downstream has to
                 * infer a date from an array position. `key` is kept as an
                 * alias for callers written against the older shape.
                 */
                'date' => $visibleStart->format('Y-m-d'),
                'bucket_start' => $cursor->format('Y-m-d'),
                'key' => $cursor->format('Y-m-d'),
                /*
                 * Label from the visible start, not the bucket start. A weekly
                 * range beginning mid-week would otherwise put "29 Dec" on the
                 * axis of a window that opens on 1 Jan.
                 */
                'label' => $visibleStart->format($labelFmt),
                'start' => $visibleStart->format('Y-m-d'),
                'end' => $visibleEnd->format('Y-m-d'),
                'full_label' => $unit === 'month'
                    ? $cursor->format('M Y')
                    : ($unit === 'year'
                        ? $cursor->format('Y')
                        : ($visibleStart->format('Y-m-d') === $visibleEnd->format('Y-m-d')
                            ? $visibleStart->format('j M Y')
                            : $visibleStart->format('j M') . ' - ' . $visibleEnd->format('j M Y'))),
                'value' => 0.0,
                'orders' => 0,
            ];

            $cursor->modify($step);
        }

        return $buckets;
    }

    /**
     * Percentage change between two periods.
     * Positive => growth, negative => decline.
     */
    /**
     * Period-over-period change, or null when there is nothing to compare.
     *
     * A baseline of zero has no percentage: growth from nothing is not 100%,
     * it is undefined, and reporting it as "+100%" put the same confident green
     * badge on a shop's first ever sale as on a genuine doubling. Zero to zero
     * is a real answer though - nothing changed - so that still returns 0.
     *
     * Callers must treat null as "no comparable period" and render it as such
     * rather than coercing it to a number; null casts to 0 in PHP and to 0 in
     * JavaScript, and both would read as "flat" instead of "unknown".
     */
    private function growthPercent($current, $previous)
    {
        $current = (float)$current;
        $previous = (float)$previous;

        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Month-to-date, against the same elapsed stretch of the previous month.
     *
     * The growth badges used to measure the current month so far against the
     * whole of the previous month, so every month looked like a collapse until
     * its final day - on the 3rd, three days of trade were being compared with
     * thirty-one. Both windows are the same length now.
     *
     * Boundaries are taken in the viewer's timezone (the same one the revenue
     * chart uses) and handed back in UTC, because created_at is stored in UTC.
     * They are half-open, [start, end), so a record written exactly at midnight
     * on the first of the month lands in one window and not in both.
     */
    private function monthToDateWindow(Request $request)
    {
        $tzName = $request->time_zone ?: config('app.timezone');
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $utc = new \DateTimeZone('UTC');

        $now = new \DateTime('now', $tz);
        $curStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $prevStart = (clone $curStart)->modify('-1 month');

        $elapsed = $now->getTimestamp() - $curStart->getTimestamp();
        $prevEnd = (clone $prevStart)->modify('+' . $elapsed . ' seconds');

        // A shorter previous month must not spill into this one: month-to-date
        // on the 31st of March would otherwise reach past the 1st of March.
        if ($prevEnd > $curStart) {
            $prevEnd = clone $curStart;
        }

        $asUtc = function (\DateTime $d) use ($utc) {
            return (clone $d)->setTimezone($utc)->format('Y-m-d H:i:s');
        };

        return [
            'cur_start' => $asUtc($curStart),
            'cur_end' => $asUtc($now),
            'prev_start' => $asUtc($prevStart),
            'prev_end' => $asUtc($prevEnd),
        ];
    }

    /**
     * The orders that count as sales.
     *
     * Two things cancel an order and they are written in different places: the
     * customer-facing flow sets the legacy `cancelled` flag AND the status,
     * while an admin changing the status from the order screen writes only
     * `status`. Filtering on the flag alone - as every aggregate here used to -
     * left admin-cancelled orders counting as revenue for ever.
     */
    private function excludeCancelled($query, $alias = 'orders')
    {
        return $query
            ->where($alias . '.cancelled', '!=', Config::get('constants.status.PUBLIC'))
            ->where($alias . '.status', '!=', Config::get('constants.orderStatus.CANCELLED'));
    }

    /** The mirror of excludeCancelled(), for the figure shown as excluded. */
    private function onlyCancelled($query, $alias = 'orders')
    {
        return $query->where(function ($q) use ($alias) {
            $q->where($alias . '.cancelled', Config::get('constants.status.PUBLIC'))
                ->orWhere($alias . '.status', Config::get('constants.orderStatus.CANCELLED'));
        });
    }

    public function dashboard(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'dashboard.view')) {
                return $can;
            }
            $posPlugin = Plugin::where('name', 'pos')->first();

            $posLicenceValid = false;

            $baseURL = $request->url('/');
            $parse = parse_url($baseURL);
            $domain = $parse['host'];

            $isLocalhost = strpos($domain, "localhost") !== false || strpos($domain, "127.0.0.1") !== false;

            if($isLocalhost) {
                $posLicenceValid = true;
            } else if($posPlugin) {
                $validLicence = Utils::decryptLicence($posPlugin->secret_key,
                    $posPlugin->encrypt_key, $posPlugin->encrypt_iv);

                if ($validLicence && $validLicence->d === $domain) {
                    $posLicenceValid = true;
                }
            }


            $data = [];
            if ($request['dashboard'] === 'false') {
                $dashboard['users'] = User::count();

                if (!$this->isSuperAdmin) {

                    $dashboard['products'] = Product::where('admin_id', $this->user->id)->count();

                    $query = Order::join('ordered_products as op', function ($join) {
                        $join->on('op.order_id', '=', 'orders.id');
                        $join->join('products as p', function ($join2) {
                            $join2->on('p.id', '=', 'op.product_id');
                            $join2->where('p.admin_id', $this->user->id);
                        });
                    });


                    if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                        if (!$request->order_type || ($request->order_type && $request->order_type == 'website')) {

                            $query = $query->where('pos_order_id', null);

                        } else if ($request->order_type && $request->order_type == 'pos') {

                            $query = $query->where('pos_order_id', '!=', null);
                        }

                    }


                    // distinct: the ordered_products join above repeats an order
                    // once per line item this vendor owns
                    $dashboard['orders'] = $this->excludeCancelled($query)->distinct()->count('orders.id');

                    // Joins orders purely so cancelled ones can be excluded -
                    // without it this summed the line items of cancelled orders
                    // into the vendor's revenue for ever.
                    $vendorAmounts = $this->excludeCancelled(
                        OrderedProduct::join('products as p', function ($join) {
                            $join->on('p.id', '=', 'ordered_products.product_id');
                            $join->where('p.admin_id', $this->user->id);
                        })->join('orders as ao', 'ao.id', '=', 'ordered_products.order_id')
                            ->whereNull('ao.deleted_at'),
                        'ao'
                    )
                        ->selectRaw('COALESCE(SUM(ordered_products.selling * ordered_products.quantity), 0) as gross,
                                     COALESCE(SUM(CASE WHEN ao.payment_done = 1 THEN ordered_products.selling * ordered_products.quantity ELSE 0 END), 0) as received,
                                     COALESCE(SUM(CASE WHEN ao.payment_done = 1 THEN 0 ELSE ordered_products.selling * ordered_products.quantity END), 0) as pending')
                        ->first();

                    $dashboard['orders_amount'] = $vendorAmounts->gross;
                    $dashboard['orders_received'] = $vendorAmounts->received;
                    $dashboard['orders_pending'] = $vendorAmounts->pending;

                } else {
                    $dashboard['products'] = Product::count();


                    $query = Order::query();
                    if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                        if (!$request->order_type || ($request->order_type && $request->order_type == 'website')) {

                            $query = $query->where('pos_order_id', null);

                        } else if ($request->order_type && $request->order_type == 'pos') {

                            $query = $query->where('pos_order_id', '!=', null);
                        }
                    }

                    // Cancelled orders are not sales. The revenue chart has
                    // always excluded them and reported them separately; these
                    // two cards counted them, so the card and the chart
                    // disagreed the moment anything was cancelled.
                    $dashboard['orders'] = $this->excludeCancelled($query)->count();

                    $query_total = Order::query();


                    if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                        if (!$request->order_type || ($request->order_type && $request->order_type == 'website')) {

                            $query_total = $query_total->where('pos_order_id', null);

                        } else if ($request->order_type && $request->order_type == 'pos') {

                            $query_total = $query_total->where('pos_order_id', '!=', null);
                        }
                    }

                    /*
                     * Order value, and the two halves it splits into. The card
                     * keeps reporting what was sold - hiding an unpaid COD sale
                     * would be just as wrong as calling it takings - but the
                     * collected and outstanding figures travel with it so the
                     * dashboard can say which is which.
                     */
                    $amounts = $this->excludeCancelled($query_total)
                        ->selectRaw('COALESCE(SUM(orders.total_amount), 0) as gross,
                                     COALESCE(SUM(CASE WHEN orders.payment_done = 1 THEN orders.total_amount ELSE 0 END), 0) as received,
                                     COALESCE(SUM(CASE WHEN orders.payment_done = 1 THEN 0 ELSE orders.total_amount END), 0) as pending')
                        ->first();

                    $dashboard['orders_amount'] = $amounts->gross;
                    $dashboard['orders_received'] = $amounts->received;
                    $dashboard['orders_pending'] = $amounts->pending;
                }

                /*
                 * Period-over-period growth: this month so far, against the
                 * same stretch of last month. Both windows are half-open, so a
                 * record created exactly at the month boundary belongs to one
                 * window only - whereBetween is inclusive at both ends and was
                 * counting such a record in the current AND the previous period.
                 */
                $growthWindow = $this->monthToDateWindow($request);
                $curStart = $growthWindow['cur_start'];
                $curEnd = $growthWindow['cur_end'];
                $prevStart = $growthWindow['prev_start'];
                $prevEnd = $growthWindow['prev_end'];

                $inCurrent = function ($query, $column = 'created_at') use ($curStart, $curEnd) {
                    return $query->where($column, '>=', $curStart)->where($column, '<', $curEnd);
                };
                $inPrevious = function ($query, $column = 'created_at') use ($prevStart, $prevEnd) {
                    return $query->where($column, '>=', $prevStart)->where($column, '<', $prevEnd);
                };

                $usersGrowth = $this->growthPercent(
                    $inCurrent(User::query())->count(),
                    $inPrevious(User::query())->count()
                );

                if (!$this->isSuperAdmin) {
                    $productsCur = $inCurrent(Product::where('admin_id', $this->user->id))->count();
                    $productsPrev = $inPrevious(Product::where('admin_id', $this->user->id))->count();

                    // Count orders, not line items, so the badge matches the
                    // card - and join orders so cancelled ones drop out, the
                    // same rule the revenue figures use.
                    $vendorOrders = function () {
                        return $this->excludeCancelled(
                            OrderedProduct::join('products as gp', function ($join) {
                                $join->on('gp.id', '=', 'ordered_products.product_id');
                                $join->where('gp.admin_id', $this->user->id);
                            })->join('orders as go', 'go.id', '=', 'ordered_products.order_id')
                                ->whereNull('go.deleted_at'),
                            'go'
                        );
                    };

                    $ordersCur = $inCurrent($vendorOrders(), 'ordered_products.created_at')
                        ->distinct()->count('ordered_products.order_id');

                    $ordersPrev = $inPrevious($vendorOrders(), 'ordered_products.created_at')
                        ->distinct()->count('ordered_products.order_id');

                    $vendorReceived = DB::raw('COALESCE(SUM(CASE WHEN go.payment_done = 1 THEN ordered_products.selling * ordered_products.quantity ELSE 0 END), 0)');
                    $sellsCur = (float)$inCurrent($vendorOrders(), 'ordered_products.created_at')
                        ->selectRaw("$vendorReceived as v")->value('v');

                    $sellsPrev = (float)$inPrevious($vendorOrders(), 'ordered_products.created_at')
                        ->selectRaw("$vendorReceived as v")->value('v');
                } else {
                    $productsCur = $inCurrent(Product::query())->count();
                    $productsPrev = $inPrevious(Product::query())->count();

                    $orderWindow = function ($scope) use ($posLicenceValid, $posPlugin, $request) {
                        $q = $this->excludeCancelled(Order::query());

                        if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                            if (!$request->order_type || $request->order_type == 'website') {
                                $q->where('orders.pos_order_id', null);
                            } else if ($request->order_type == 'pos') {
                                $q->where('orders.pos_order_id', '!=', null);
                            }
                        }

                        return $scope($q, 'orders.created_at');
                    };

                    $ordersCur = $orderWindow($inCurrent)->count();
                    $ordersPrev = $orderWindow($inPrevious)->count();
                    // The card reports money received, so its badge measures
                    // money received. Comparing takings against order value
                    // would put a percentage on two different quantities.
                    $receivedSum = 'COALESCE(SUM(CASE WHEN orders.payment_done = 1 THEN orders.total_amount ELSE 0 END), 0)';
                    $sellsCur = (float)$orderWindow($inCurrent)->selectRaw("$receivedSum as v")->value('v');
                    $sellsPrev = (float)$orderWindow($inPrevious)->selectRaw("$receivedSum as v")->value('v');
                }

                $dashboard['growth'] = [
                    'products' => $this->growthPercent($productsCur, $productsPrev),
                    'users' => $usersGrowth,
                    'orders' => $this->growthPercent($ordersCur, $ordersPrev),
                    'sells' => $this->growthPercent($sellsCur, $sellsPrev),
                ];

                $data['dashboard'] = $dashboard;
            }

            // date('M') is a month NAME ("Sep"); whereMonth compares it numerically,
            // so the unfiltered default silently matched no rows at all.
            $m = date('n');
            $y = date('Y');
            if ($request['month']) {
                $m = $request['month'];
            }
            if ($request['year']) {
                $y = $request['year'];
            }

            if (!$this->isSuperAdmin) {
                $dateStr = 'DATE(ordered_products.created_at) as  time';
                if ($request->time_zone) {

                    $time = new \DateTime('now', new \DateTimeZone($request->time_zone));
                    $timezoneOffset = $time->format('P');

                    $dateStr = "DATE(CONVERT_TZ(ordered_products.created_at, '+00:00', '" . $timezoneOffset . "')) as  time";
                }

                $monthly_order_q = OrderedProduct::join('products as p', function ($join) {
                    $join->on('p.id', '=', 'ordered_products.product_id');
                    $join->where('p.admin_id', $this->user->id);

                })->join('orders', function ($join) {
                    $join->on('orders.id', '=', 'ordered_products.order_id');
                    $join->where('p.admin_id', $this->user->id);
                    $join->whereNull('orders.deleted_at');
                });


                $monthly_order_q->whereMonth('ordered_products.created_at', $m)->whereYear('ordered_products.created_at', $y)
                    ->select(
                        "ordered_products.id",
                        DB::raw("(count(ordered_products.selling)) as total"),
                        DB::raw("(sum(ordered_products.selling)) as value"),
                        DB::raw($dateStr)
                    )
                    ->orderBy('time')
                    ->groupBy('time');


                $chartData['monthly_order'] = $monthly_order_q->get();
            } else {

                $dateStr = 'DATE(created_at) as time';
                if ($request->time_zone) {

                    $time = new \DateTime('now', new \DateTimeZone($request->time_zone));
                    $timezoneOffset = $time->format('P');

                    $dateStr = "DATE(CONVERT_TZ(created_at, '+00:00', '" . $timezoneOffset . "')) as  time";
                }


                $monthly_order_q = Order::whereMonth('created_at', $m)->whereYear('created_at', $y);

                if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                    if (!$request->order_type || ($request->order_type && $request->order_type == 'website')) {

                        $monthly_order_q = $monthly_order_q->where('pos_order_id', null);

                    } else if ($request->order_type && $request->order_type == 'pos') {

                        $monthly_order_q = $monthly_order_q->where('pos_order_id', '!=', null);
                    }
                }


                $chartData['monthly_order'] = $monthly_order_q
                    ->select(
                        "id",
                        DB::raw("(count(total_amount)) as total"),
                        DB::raw("(sum(total_amount)) as value"),
                        DB::raw($dateStr)
                    )
                    ->orderBy('time')
                    ->groupBy('time')
                    ->get();
            }

            /*
             * Sales Overview.
             *
             * Driven entirely by resolveDateRange(), so the chart, the period
             * total beside the heading and the Order Statistics block below all
             * mean the same thing by "last 30 days".
             *
             * Cancelled orders are left out, matching the rule statistic() has
             * always applied to its status counts - they are reported back
             * separately in the summary rather than silently dropped.
             */
            // Last 30 days when the caller says nothing - the same window the
            // dashboard opens on, so a bare request and a fresh page agree.
            $range = $this->resolveDateRange($request, 'd30');

            $bucketExpr = $this->bucketExpression(
                $this->isSuperAdmin ? 'orders.created_at' : 'ordered_products.created_at',
                $range['unit'],
                $range['tz_offset']
            );

            if (!$this->isSuperAdmin) {
                $scope = function () use ($range) {
                    return OrderedProduct::join('products as rp', function ($join) {
                        $join->on('rp.id', '=', 'ordered_products.product_id');
                        $join->where('rp.admin_id', $this->user->id);
                    })
                        ->join('orders as ro', 'ro.id', '=', 'ordered_products.order_id')
                        ->whereNull('ro.deleted_at')
                        ->where('ordered_products.created_at', '>=', $range['start_utc'])
                        ->where('ordered_products.created_at', '<', $range['end_utc']);
                };

                $lineValue = 'ordered_products.selling * ordered_products.quantity';
                $valueExpr = "SUM($lineValue)";
                $countExpr = 'COUNT(DISTINCT ordered_products.order_id)';

                /*
                 * Order value and money received are not the same figure. A
                 * cash-on-delivery order is a real sale the moment it is
                 * placed, but nothing has been collected until the courier
                 * hands the cash over - which is when payment_done is set.
                 * Reporting only the sum made an uncollected COD order look
                 * like takings.
                 */
                $receivedExpr = "SUM(CASE WHEN ro.payment_done = 1 THEN $lineValue ELSE 0 END)";
                $pendingExpr = "SUM(CASE WHEN ro.payment_done = 1 THEN 0 ELSE $lineValue END)";
                $figures = "$valueExpr as value, $receivedExpr as received, $pendingExpr as pending, $countExpr as orders";

                $rows = $this->excludeCancelled($scope(), 'ro')
                    ->selectRaw("$bucketExpr as bucket, $figures")
                    ->groupBy('bucket')
                    ->get();

                $totals = $this->excludeCancelled($scope(), 'ro')
                    ->selectRaw($figures)
                    ->first();

                $cancelledTotals = $this->onlyCancelled($scope(), 'ro')
                    ->selectRaw("$valueExpr as value, $countExpr as orders")
                    ->first();

            } else {
                $scope = function () use ($range, $posLicenceValid, $posPlugin, $request) {
                    $q = Order::query()
                        ->where('orders.created_at', '>=', $range['start_utc'])
                        ->where('orders.created_at', '<', $range['end_utc']);

                    if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                        if (!$request->order_type || $request->order_type == 'website') {
                            $q->where('orders.pos_order_id', null);
                        } else if ($request->order_type == 'pos') {
                            $q->where('orders.pos_order_id', '!=', null);
                        }
                    }

                    return $q;
                };

                $valueExpr = 'SUM(orders.total_amount)';
                $countExpr = 'COUNT(DISTINCT orders.id)';
                // See the note in the vendor branch: placed is not collected.
                $receivedExpr = 'SUM(CASE WHEN orders.payment_done = 1 THEN orders.total_amount ELSE 0 END)';
                $pendingExpr = 'SUM(CASE WHEN orders.payment_done = 1 THEN 0 ELSE orders.total_amount END)';
                $figures = "$valueExpr as value, $receivedExpr as received, $pendingExpr as pending, $countExpr as orders";

                $rows = $this->excludeCancelled($scope())
                    ->selectRaw("$bucketExpr as bucket, $figures")
                    ->groupBy('bucket')
                    ->get();

                $totals = $this->excludeCancelled($scope())
                    ->selectRaw($figures)
                    ->first();

                $cancelledTotals = $this->onlyCancelled($scope())
                    ->selectRaw("$valueExpr as value, $countExpr as orders")
                    ->first();
            }

            $byBucket = [];
            foreach ($rows as $r) {
                $byBucket[(string)$r->bucket] = $r;
            }

            $buckets = $this->buildBuckets($range);
            $bucketSum = 0.0;
            $bucketOrders = 0;

            foreach ($buckets as $i => $b) {
                if (isset($byBucket[$b['key']])) {
                    $row = $byBucket[$b['key']];
                    $buckets[$i]['value'] = (float)$row->value;
                    $buckets[$i]['orders'] = (int)$row->orders;
                    // Same split per bucket, so the chart can show collected
                    // against outstanding for any day the reader picks.
                    $buckets[$i]['received'] = round((float)($row->received ?? 0), 2);
                    $buckets[$i]['pending'] = round((float)($row->pending ?? 0), 2);
                    $bucketSum += $buckets[$i]['value'];
                    $bucketOrders += $buckets[$i]['orders'];
                }
            }

            $periodTotal = (float)($totals->value ?? 0);
            $periodOrders = (int)($totals->orders ?? 0);
            $periodReceived = (float)($totals->received ?? 0);
            $periodPending = (float)($totals->pending ?? 0);

            /*
             * Optional comparison against the period immediately before this
             * one, of exactly the same length. Computed from the same queries
             * over a shifted window - never from a stored or assumed figure -
             * so the percentages move with the data.
             *
             * Off by default: it costs two more aggregates and most loads do
             * not need it.
             */
            $comparison = null;

            if (filter_var($request->compare, FILTER_VALIDATE_BOOLEAN)) {
                $spanSeconds = strtotime($range['end_utc'] . ' UTC') - strtotime($range['start_utc'] . ' UTC');
                $prevFrom = gmdate('Y-m-d H:i:s', strtotime($range['start_utc'] . ' UTC') - $spanSeconds);
                $prevTo = $range['start_utc'];

                // Local dates purely for labelling the compared window.
                $prevStartLocal = (clone $range['start_local'])->modify('-' . $range['span_days'] . ' days');
                $prevEndLocal = (clone $range['start_local'])->modify('-1 day');

                if (!$this->isSuperAdmin) {
                    $prevRow = $this->excludeCancelled(
                        OrderedProduct::join('products as pp', function ($join) {
                            $join->on('pp.id', '=', 'ordered_products.product_id');
                            $join->where('pp.admin_id', $this->user->id);
                        })
                            ->join('orders as po', 'po.id', '=', 'ordered_products.order_id')
                            ->whereNull('po.deleted_at')
                            ->where('ordered_products.created_at', '>=', $prevFrom)
                            ->where('ordered_products.created_at', '<', $prevTo),
                        'po'
                    )
                        ->selectRaw('SUM(ordered_products.selling * ordered_products.quantity) as value,
                                     SUM(CASE WHEN po.payment_done = 1 THEN ordered_products.selling * ordered_products.quantity ELSE 0 END) as received,
                                     COUNT(DISTINCT ordered_products.order_id) as orders')
                        ->first();
                } else {
                    $prevQuery = $this->excludeCancelled(Order::query())
                        ->where('orders.created_at', '>=', $prevFrom)
                        ->where('orders.created_at', '<', $prevTo);

                    if ($posLicenceValid && $posPlugin && $posPlugin->active) {
                        if (!$request->order_type || $request->order_type == 'website') {
                            $prevQuery->where('orders.pos_order_id', null);
                        } else if ($request->order_type == 'pos') {
                            $prevQuery->where('orders.pos_order_id', '!=', null);
                        }
                    }

                    $prevRow = $prevQuery
                        ->selectRaw('SUM(orders.total_amount) as value,
                                     SUM(CASE WHEN orders.payment_done = 1 THEN orders.total_amount ELSE 0 END) as received,
                                     COUNT(DISTINCT orders.id) as orders')
                        ->first();
                }

                $prevTotal = (float)($prevRow->value ?? 0);
                $prevReceived = (float)($prevRow->received ?? 0);
                $prevOrders = (int)($prevRow->orders ?? 0);
                $prevAvg = $prevOrders > 0 ? $prevTotal / $prevOrders : 0;
                $curAvg = $periodOrders > 0 ? $periodTotal / $periodOrders : 0;

                $comparison = [
                    'start' => $prevStartLocal->format('Y-m-d'),
                    'end' => $prevEndLocal->format('Y-m-d'),
                    'utc_from' => $prevFrom,
                    'utc_to' => $prevTo,
                    'total' => round($prevTotal, 2),
                    'received' => round($prevReceived, 2),
                    'orders' => $prevOrders,
                    'average_order' => round($prevAvg, 2),
                    'change' => [
                        'total' => $this->growthPercent($periodTotal, $prevTotal),
                        'received' => $this->growthPercent($periodReceived, $prevReceived),
                        'orders' => $this->growthPercent($periodOrders, $prevOrders),
                        'average_order' => $this->growthPercent($curAvg, $prevAvg),
                    ],
                    // Distinguishes "flat" from "nothing to compare against":
                    // a 100% rise off zero is not the same as a real doubling.
                    'baseline_empty' => $prevOrders === 0,
                ];
            }

            $chartData['revenue'] = $buckets;
            // Older bundles read monthly_revenue; same array, same order.
            $chartData['monthly_revenue'] = $buckets;
            $chartData['revenue_unit'] = $range['unit'];
            $chartData['revenue_count'] = count($buckets);
            $chartData['range'] = [
                'preset' => $range['preset'],
                'unit' => $range['unit'],
                'start' => $range['start'],
                'end' => $range['end'],
                'span_days' => $range['span_days'],
                'time_zone' => $range['tz'],
                'utc_from' => $range['start_utc'],
                'utc_to' => $range['end_utc'],
            ];
            /*
             * Refunds are reported beside the period rather than inside it.
             * A refund can only be issued against an order whose payment has
             * been taken, and approving the cancellation that carries it sets
             * orders.cancelled - so the order has already left `total` and
             * `received` entirely. This figure says how much money went back
             * out, which the takings alone cannot show.
             */
            $refundQuery = DB::table('cancellations as rc')
                ->join('orders as ro2', 'ro2.id', '=', 'rc.order_id')
                ->whereNull('ro2.deleted_at')
                ->where('rc.refunded', 1)
                ->where('rc.created_at', '>=', $range['start_utc'])
                ->where('rc.created_at', '<', $range['end_utc']);

            if (!$this->isSuperAdmin) {
                $refundQuery->whereIn('rc.order_id', OrderedProduct::join('products as rp2', function ($join) {
                    $join->on('rp2.id', '=', 'ordered_products.product_id');
                    $join->where('rp2.admin_id', $this->user->id);
                })->select('ordered_products.order_id'));
            }

            $refunds = $refundQuery
                ->selectRaw('COALESCE(SUM(rc.refunded_amount), 0) as amount, COUNT(*) as count')
                ->first();

            $chartData['summary'] = [
                // `total` is order value - what was sold. Kept under its
                // original name so nothing reading this API breaks.
                'total' => round($periodTotal, 2),
                'gross' => round($periodTotal, 2),
                /*
                 * Money actually collected, and money still owed. An unpaid
                 * cash-on-delivery order sits entirely in `pending` until the
                 * cash is handed over. By construction gross = received +
                 * pending; `balanced` says so rather than asking for trust.
                 */
                'received' => round($periodReceived, 2),
                'pending' => round($periodPending, 2),
                'balanced' => abs(($periodReceived + $periodPending) - $periodTotal) < 0.01,
                'refunded_amount' => round((float)($refunds->amount ?? 0), 2),
                'refunded_orders' => (int)($refunds->count ?? 0),
                'orders' => $periodOrders,
                // Average ORDER value: what a customer orders, not what has
                // been collected from them yet.
                'average_order' => $periodOrders > 0 ? round($periodTotal / $periodOrders, 2) : 0,
                'cancelled_orders' => (int)($cancelledTotals->orders ?? 0),
                'cancelled_amount' => round((float)($cancelledTotals->value ?? 0), 2),
                /*
                 * The bucket sum is computed independently of the period total.
                 * They can only diverge if a row in the window failed to land in
                 * any bucket, which would mean the chart is quietly under-
                 * reporting - so expose it rather than trusting it.
                 */
                'bucket_total' => round($bucketSum, 2),
                'bucket_orders' => $bucketOrders,
                'reconciled' => abs($bucketSum - $periodTotal) < 0.01 && $bucketOrders === $periodOrders,
                'previous' => $comparison,
            ];

            // Bounds for the custom-range date pickers.
            $boundsQuery = Order::query();
            if (!$this->isSuperAdmin) {
                $boundsQuery = Order::whereIn('id', OrderedProduct::join('products as bp', function ($join) {
                    $join->on('bp.id', '=', 'ordered_products.product_id');
                    $join->where('bp.admin_id', $this->user->id);
                })->select('ordered_products.order_id'));
            }
            $bounds = $boundsQuery->selectRaw('MIN(created_at) as first_at, MAX(created_at) as last_at')->first();

            $toLocalDate = function ($utcString) use ($range) {
                if (empty($utcString)) {
                    return null;
                }
                try {
                    return (new \DateTime($utcString, new \DateTimeZone('UTC')))
                        ->setTimezone(new \DateTimeZone($range['tz']))
                        ->format('Y-m-d');
                } catch (\Exception $e) {
                    return null;
                }
            };

            $chartData['data_bounds'] = [
                'first_order' => $toLocalDate($bounds->first_at ?? null),
                'last_order' => $toLocalDate($bounds->last_at ?? null),
                'today' => (new \DateTime('now', new \DateTimeZone($range['tz'])))->format('Y-m-d'),
            ];

            $data['chart_data'] = $chartData;

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }



    /**
     * Merged activity feed for the dashboard's Live Activity panel.
     *
     * The panel used to call the orders and reviews list endpoints and stitch
     * them together in the browser, which meant it could only ever say "new
     * order" - nothing surfaced when an order was confirmed, packed, shipped,
     * delivered or cancelled. Those transitions are recorded in
     * order_status_histories, so read them here and merge every source
     * server-side, already sorted and already scoped to what this admin may
     * see.
     */
    /**
     * The activity feed behind the dashboard's Live Activity panel.
     *
     * Every entry is a row that already exists in the database - an order, a
     * status transition, a cancellation, a review, a registration, a trade
     * decision, a product edit, a stock change. Nothing here is synthesised,
     * and there is no event type without a table behind it. In particular there
     * is no "payment completed" event: `payments` holds gateway credentials,
     * not transactions, and orders carry only a `payment_done` boolean with no
     * timestamp of its own, so such an event could not be placed on a timeline.
     *
     * Two things make the feed safe to poll:
     *
     *  - every event carries a stable `id` ("order:85", "status:12"), unique
     *    for the life of the row, so a client can merge repeated responses
     *    without ever showing the same event twice;
     *  - `since` (an ISO-8601 instant) returns only what happened after it, so
     *    a client that has been away - a dropped connection, a sleeping tab -
     *    can catch up without refetching the whole list.
     */
    public function activity(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'dashboard.view')) {
                return $can;
            }

            $limit = (int)($request->limit ?: 10);
            $limit = max(1, min($limit, 50));

            // Pull as many from each source as we return: the newest few
            // overall may all come from just one of them.
            $perSource = $limit;
            $tz = $request->time_zone;

            /*
             * Incremental cursor. Anything at or before this instant the client
             * already has. Parsed defensively - a malformed cursor must not
             * take the panel down, it just means a full page instead.
             */
            $since = null;
            if ($request->filled('since')) {
                try {
                    $since = Carbon::parse($request->since)->utc();
                } catch (\Exception $e) {
                    $since = null;
                }
            }

            $applySince = function ($query, $column) use ($since) {
                if ($since) {
                    $query->where($column, '>', $since->format('Y-m-d H:i:s'));
                }
                return $query;
            };

            $stamp = function ($utc) use ($tz) {
                if (empty($utc)) {
                    return ['at' => null, 'at_label' => ''];
                }
                return [
                    'at' => Carbon::parse($utc)->toIso8601ZuluString('microsecond'),
                    'at_label' => $tz
                        ? Utils::formatDate(Utils::convertTimeToUSERzone($utc, $tz))
                        : Utils::formatDate($utc),
                ];
            };

            // Orders this admin may see. A vendor only sees orders that
            // contain one of their own products.
            $visibleOrderIds = null;
            if (!$this->isSuperAdmin) {
                $visibleOrderIds = OrderedProduct::join('products as ap', function ($join) {
                    $join->on('ap.id', '=', 'ordered_products.product_id');
                    $join->where('ap.admin_id', $this->user->id);
                })->distinct()->pluck('ordered_products.order_id');
            }

            $customerOf = function ($order) {
                $candidates = [
                    optional($order->address)->name,
                    $order->contact_name,
                    optional($order->user)->name,
                    optional($order->address)->email,
                    $order->contact_email,
                    optional($order->user)->email,
                ];

                foreach ($candidates as $c) {
                    if (!empty($c)) {
                        return $c;
                    }
                }

                return '';
            };

            $events = [];

            // ---------------------------------------------------------- orders
            $orderQuery = Order::query()
                ->with(['address', 'user'])
                ->orderByDesc('created_at')
                ->limit($perSource);

            $applySince($orderQuery, 'created_at');

            if (!is_null($visibleOrderIds)) {
                $orderQuery->whereIn('id', $visibleOrderIds);
            }

            foreach ($orderQuery->get() as $o) {
                $events[] = array_merge($stamp($o->created_at), [
                    'id' => 'order:' . $o->id,
                    'type' => 'order_placed',
                    'order_id' => $o->id,
                    'order_no' => $o->order,
                    'status' => (int)$o->status,
                    'cancelled' => (int)$o->cancelled === Config::get('constants.status.PUBLIC'),
                    'amount' => $o->total_amount,
                    'paid' => (int)$o->payment_done === 1,
                    'customer' => $customerOf($o),
                ]);
            }

            // ------------------------------------------------ status transitions
            $historyQuery = DB::table('order_status_histories as h')
                ->join('orders as o', 'o.id', '=', 'h.order_id')
                ->whereNull('o.deleted_at')
                ->leftJoin('admins as a', 'a.id', '=', 'h.changed_by')
                ->select(
                    'h.id', 'h.order_id', 'h.from_status', 'h.to_status', 'h.note',
                    'h.changed_by_type', 'h.created_at', 'a.name as actor',
                    'o.order as order_no', 'o.total_amount', 'o.cancelled', 'o.payment_done',
                    'o.contact_name'
                )
                ->orderByDesc('h.created_at')
                ->limit($perSource);

            $applySince($historyQuery, 'h.created_at');

            if (!is_null($visibleOrderIds)) {
                $historyQuery->whereIn('h.order_id', $visibleOrderIds);
            }

            foreach ($historyQuery->get() as $h) {
                $events[] = array_merge($stamp($h->created_at), [
                    'id' => 'status:' . $h->id,
                    'type' => 'order_status',
                    'order_id' => $h->order_id,
                    'order_no' => $h->order_no,
                    'status' => (int)$h->to_status,
                    'from_status' => is_null($h->from_status) ? null : (int)$h->from_status,
                    'cancelled' => (int)$h->cancelled === Config::get('constants.status.PUBLIC'),
                    'amount' => $h->total_amount,
                    'paid' => (int)$h->payment_done === 1,
                    'actor' => $h->actor,
                    'note' => $h->note,
                    'customer' => (string)$h->contact_name,
                ]);
            }

            // Customer-initiated cancellations, which do not always leave a
            // status-history row behind.
            $cancelQuery = DB::table('cancellations as c')
                ->join('orders as o', 'o.id', '=', 'c.order_id')
                ->whereNull('o.deleted_at')
                ->select('c.id', 'c.order_id', 'c.title', 'c.refunded', 'c.refunded_amount',
                    'c.created_at', 'o.order as order_no', 'o.total_amount', 'o.contact_name')
                ->orderByDesc('c.created_at')
                ->limit($perSource);

            $applySince($cancelQuery, 'c.created_at');

            if (!is_null($visibleOrderIds)) {
                $cancelQuery->whereIn('c.order_id', $visibleOrderIds);
            }

            foreach ($cancelQuery->get() as $c) {
                $events[] = array_merge($stamp($c->created_at), [
                    'id' => 'cancel:' . $c->id,
                    'type' => 'order_cancelled',
                    'order_id' => $c->order_id,
                    'order_no' => $c->order_no,
                    'amount' => $c->total_amount,
                    'refunded' => (int)$c->refunded === 1,
                    'refunded_amount' => $c->refunded_amount,
                    'note' => $c->title,
                    'customer' => (string)$c->contact_name,
                ]);
            }

            // --------------------------------------------------------- reviews
            $reviewQuery = DB::table('rating_reviews as r')
                ->join('products as rp', 'rp.id', '=', 'r.product_id')
                ->select('r.id', 'r.rating', 'r.created_at', 'r.product_id',
                    'rp.title as product', 'rp.image as product_image')
                ->orderByDesc('r.created_at')
                ->limit($perSource);

            $applySince($reviewQuery, 'r.created_at');

            if (!$this->isSuperAdmin) {
                $reviewQuery->where('rp.admin_id', $this->user->id);
            }

            foreach ($reviewQuery->get() as $r) {
                $events[] = array_merge($stamp($r->created_at), [
                    'id' => 'review:' . $r->id,
                    'type' => 'review',
                    'review_id' => $r->id,
                    'product_id' => $r->product_id,
                    'product' => $r->product,
                    'product_image' => $r->product_image,
                    'rating' => (int)$r->rating,
                ]);
            }

            /*
             * The remaining sources are shop-wide rather than per-vendor, so a
             * vendor is not shown other people's customers or catalogue edits.
             */
            if ($this->isSuperAdmin) {
                // ------------------------------------------- registrations
                $userQuery = DB::table('users')
                    ->select('id', 'name', 'email', 'account_type', 'created_at')
                    ->orderByDesc('created_at')
                    ->limit($perSource);

                $applySince($userQuery, 'created_at');

                foreach ($userQuery->get() as $u) {
                    $events[] = array_merge($stamp($u->created_at), [
                        'id' => 'user:' . $u->id,
                        'type' => 'customer_registered',
                        'user_id' => $u->id,
                        'customer' => (string)($u->name ?: $u->email),
                        'account_type' => $u->account_type,
                    ]);
                }

                // ------------------------------- trade decisions (approve/reject)
                $tradeQuery = DB::table('users')
                    ->select('id', 'name', 'email', 'trade_status', 'trade_reviewed_at', 'trade_review_note')
                    ->whereNotNull('trade_reviewed_at')
                    ->whereNotNull('trade_status')
                    ->orderByDesc('trade_reviewed_at')
                    ->limit($perSource);

                $applySince($tradeQuery, 'trade_reviewed_at');

                foreach ($tradeQuery->get() as $t) {
                    $events[] = array_merge($stamp($t->trade_reviewed_at), [
                        // The decision can be revisited, so the status is part
                        // of the identity: an approval and a later suspension
                        // of the same account are two events, not one.
                        'id' => 'trade:' . $t->id . ':' . $t->trade_status,
                        'type' => 'trade_reviewed',
                        'user_id' => $t->id,
                        'customer' => (string)($t->name ?: $t->email),
                        'trade_status' => $t->trade_status,
                        'note' => $t->trade_review_note,
                    ]);
                }

                // ------------------------------------------ catalogue edits
                $productLogQuery = DB::table('product_logs')
                    ->select('id', 'admin_id', 'products_before', 'products_after', 'created_at')
                    ->orderByDesc('created_at')
                    ->limit($perSource);

                $applySince($productLogQuery, 'created_at');

                foreach ($productLogQuery->get() as $pl) {
                    // The log stores whole product snapshots; the identity of
                    // the product is all this panel needs from them.
                    $after = json_decode((string)$pl->products_after, true);
                    $first = is_array($after) && isset($after[0]) ? $after[0] : null;
                    $created = empty($pl->products_before) || $pl->products_before === 'null';

                    $events[] = array_merge($stamp($pl->created_at), [
                        'id' => 'product:' . $pl->id,
                        'type' => 'product_changed',
                        'created' => $created,
                        'product_id' => $first['id'] ?? null,
                        'product' => $first['title'] ?? null,
                    ]);
                }

                // ---------------------------------------------- stock changes
                $stockQuery = DB::table('updated_inventories as ui')
                    ->leftJoin('products as sp', 'sp.id', '=', 'ui.product_id')
                    ->select('ui.id', 'ui.product_id', 'ui.quantity', 'ui.price', 'ui.sku',
                        'ui.created_at', 'sp.title as product')
                    ->orderByDesc('ui.created_at')
                    ->limit($perSource);

                $applySince($stockQuery, 'ui.created_at');

                foreach ($stockQuery->get() as $s) {
                    $events[] = array_merge($stamp($s->created_at), [
                        'id' => 'stock:' . $s->id,
                        'type' => 'inventory_changed',
                        'product_id' => $s->product_id,
                        'product' => $s->product,
                        'quantity' => (int)$s->quantity,
                        'sku' => $s->sku,
                    ]);
                }
            }

            /*
             * Newest first, by the instant the thing actually happened. The
             * client sorts again on the same field: neither side may rely on
             * arrival order, because a catch-up fetch and a routine poll can
             * interleave.
             */
            usort($events, function ($a, $b) {
                return strcmp((string)$b['at'], (string)$a['at']);
            });

            $events = array_slice($events, 0, $limit);

            return response()->json(new Response($request->token, [
                'events' => $events,
                // What the client should send back as `since` next time. Taken
                // from the newest event rather than the clock, so an event
                // written while this request was in flight is not skipped.
                'cursor' => $events ? $events[0]['at'] : ($since ? $since->toIso8601ZuluString('microsecond') : null),
                'server_time' => Carbon::now('UTC')->toIso8601ZuluString('microsecond'),
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
    public function login(Request $request)
    {
        try {

            $lang = $request->header('language');

            $validator = Validation::admin_login($request);
            if ($validator) {
                return response()->json($validator);
            }

            $admin = Admin::where('email', request('email'))
                ->first();


            if (is_null($admin)) {
                return response()->json(Validation::error(null,
                    __('lang.wrong_email', [], $lang)));
            }

            $temp_admin = clone $admin;


            $password_check = Validation::password_check($admin, request('password'));
            if ($password_check) {
                return response()->json($password_check);
            }

            if ((!$admin->active || !$admin->verified) && !$this->isSuperAdmin) {
                return response()->json(Validation::error(null,
                    __('lang.contact_admin', [], $lang)));
            }

            Auth::login($admin);


            $data['expires_in'] = Carbon::now()
                ->addHours(Config::get('constants.auth.EXPIRATION_IN_HOURS'));


            if (request('remember_token')) {
                $data['expires_in'] = Carbon::now()->addMonths(12);
            }

            Auth::user()->tokens->each(function ($token, $key) {
                // if ($token->name === "admin") $token->delete();
            });

            Passport::personalAccessTokensExpireIn($data['expires_in']);

            $data['token'] = Auth::user()->createToken('admin', ['admin'])->accessToken;
            $data['admin'] = $temp_admin;

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function update(Request $request)
    {
        try {
            $lang = $request->header('language');


            if ($can = Utils::userCan($this->user, 'profile.edit')) {
                return $can;
            }

            $validator = Validation::admin_signup($request);
            if ($validator) {
                return response()->json($validator);
            }

            $admin = Admin::where('email', $request->email)
                ->first();

            $adminExists = false;
            if (!is_null($admin)) {
                if ($this->user->id != $admin->id) {
                    $adminExists = true;
                }
            }

            if ($adminExists) {
                return response()->json(Validation::error($request->token,
                    __('lang.email_exists', [], $lang)));
            }

            $password_check = Validation::password_check($this->user,
                request('password'), null, 'form', $lang);

            if ($password_check) {
                return response()->json($password_check);
            }

            if (Admin::where('id', $this->user->id)->update([
                'name' => $request['name'],
                'username' => $request['username'],
                'email' => $request['email'],
            ])) {

                return response()->json(new Response($request->token, Admin::find($this->user->id)));
            }

            return response()->json(Validation::errorLang($lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

    }


    public function updateProfileImage(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'profile.edit')) {
                return $can;
            }

            $validate = Validation::image($request);
            if ($validate) {
                return response()->json($validate);
            }

            $image_info = FileHelper::uploadImage($request['photo'], 'admin');

            Admin::where('id', $this->user->id)->update([
                'image' => $image_info['name'],
            ]);

            return response()->json(new Response($request->token, Admin::find($this->user->id)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function updatePassword(Request $request)
    {
        try {

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'profile.edit')) {
                return $can;
            }

            $validator = Validation::admin_password($request);
            if ($validator) {
                return response()->json($validator);
            }

            $current_admin = $request->user();

            $admin = Admin::where('email', $current_admin->email)->first();

            $password_check = Validation::password_check($admin,
                request('password'), null, 'form', $lang);

            if ($password_check) {
                return response()->json($password_check);
            }

            $new_admin['password'] = Hash::make(request('new_password'));

            if ($admin->update($new_admin)) {
                Auth::user()->tokens->each(function ($token, $key) {
                    // if ($token->name === "admin") $token->delete();
                });

                return response()->json(new Response($request->token, $admin));
            }

            return response()->json(Validation::errorLang());


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

    }


    public function forgotPassword(Request $request)
    {
        try {

            $lang = $request->header('language');


            $validator = Validation::forgotPassword($request);
            if ($validator) {
                return response()->json($validator);
            }

            $existingAdmin = Admin::where('email', $request->email)->first();

            /*
             * Always report success. Telling the caller the address is unknown
             * turns this endpoint into an admin-account enumerator.
             */
            $genericSuccess = response()->json(
                new Response(
                    $request->token, true, 200, __('lang.success_email', [], $lang)
                ));

            if (is_null($existingAdmin)) {
                return $genericSuccess;
            }

            // At most a handful of codes per address per window.
            $throttleKey = 'admin-forgot:' . sha1(strtolower($existingAdmin->email));

            if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
                return $genericSuccess;
            }

            RateLimiter::hit($throttleKey, 900);

            Admin::where('id', $existingAdmin->id)
                ->update([
                    // $lang used to be passed positionally into $subject, which
                    // sent the reset email with a subject line of "en".
                    'code' => MailHelper::codeSender($existingAdmin, 'forgot_password', null, $lang),
                    'code_expires_at' => now()->addMinutes(Admin::CODE_LIFETIME_MINUTES),
                    'code_attempts' => 0
                ]);

            return $genericSuccess;

        } catch (\Exception $ex) {
            return response()->json(Validation::error(null, explode('.', $ex->getMessage())[0]));
        }

    }


    public function verifyCode(Request $request)
    {
        try {

            $lang = $request->header('language');


            $validator = Validation::verifyCode($request);
            if ($validator) {
                return response()->json($validator);
            }

            $existingAdmin = Admin::where('email', $request->email)->first();

            /*
             * Same response whether or not the address is known, so this cannot
             * be used to enumerate admin accounts.
             */
            $genericFailure = response()->json(Validation::error($request->token,
                __('lang.code_not_match', [], $lang)));

            if (is_null($existingAdmin)) {
                return $genericFailure;
            }

            /*
             * A code that was never issued must never match. The previous check
             * used a loose !=, so a NULL code compared equal to integer 0 and
             * handed out a password reset on any admin address (QA audit SEC-01).
             */
            if (!Admin::codeMatches($existingAdmin, $request->code)) {

                $existingAdmin->increment('code_attempts');

                if ($existingAdmin->code_attempts + 1 >= Admin::MAX_CODE_ATTEMPTS) {
                    // Burn the code rather than let it be ground down by guesses.
                    Admin::where('id', $existingAdmin->id)->update([
                        'code' => null,
                        'code_expires_at' => null,
                        'code_attempts' => 0
                    ]);
                }

                return $genericFailure;
            }

            Admin::where('id', $existingAdmin->id)
                ->update([
                    'password' => Hash::make($request->password),
                    // Single use: the code dies with the reset it authorised.
                    'code' => null,
                    'code_expires_at' => null,
                    'code_attempts' => 0
                ]);

            // A password reset is usually meant to lock someone else out.
            $existingAdmin->tokens->each(function ($token) {
                $token->revoke();
            });

            return response()->json(
                new Response(
                    $request->token, true, 200, __('lang.password_updated', [], $lang)
                ));


        } catch (\Exception $ex) {
            return response()->json(Validation::error(null, explode('.', $ex->getMessage())[0]));
        }
    }


    public function signup(Request $request)
    {
        try {
            $validator = Validation::admin_signup($request);

            if ($validator) {
                return response()->json($validator);

            }

            $request['password'] = Hash::make(request('password'));

            return response()->json(new Response($request->token, Admin::create($request->all())));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

    }

    public function logout(Request $request)
    {
        try {
            $lang = $request->header('language');


            Auth::user()->tokens->each(function ($token, $key) {
                if ($token->name === "admin") $token->delete();
            });

            return response()->json(new Response($request->token, [], 200,
                __('lang.logged_out', [], $lang)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function profile(Request $request)
    {
        try {

            $lang = $request->lang;
            $user = new User();
            $authUser = Auth::user();
            $user->id = $authUser->id;
            $user->name = $authUser->name;
            $user->username = $authUser->username;
            $user->email = $authUser->email;


            $data['activated'] = false;
            $licence = Licence::first();

            $baseURL = $request->url('/');
            // $baseURL = "https://admin.ishop.com";//

            $parse = parse_url($baseURL);
            $domain = $parse['host'];

            $isLocalhost = strpos($domain, "localhost") !== false || strpos($domain, "127.0.0.1") !== false;

            // $isLocalhost = false;//

            if ($isLocalhost) {

                $data['activated'] = true;
                // $data['public_key'] = $licence->public_key;


            } else if ($licence) {
                $validLicence = Utils::decryptLicence($licence->secret_key,
                    $licence->encrypt_key, $licence->encrypt_iv);


                if ($validLicence && $validLicence->d === $domain) {
                    $data['activated'] = true;
                    $data['public_key'] = $licence->public_key;
                }
            }
            //$data['activated'] = false;


            $data['media'] = [
                'file' => Config::get('constants.media.MAX_FILE_SIZE'),
                'image' => Config::get('constants.media.MAX_IMAGE_SIZE'),
                'video' => Config::get('constants.media.MAX_VIDEO_SIZE'),
            ];


            $data['user'] = $user;
            $data['super_admin'] = $this->isSuperAdmin;
            $data['vendor'] = $this->isVendor;
            $data['store'] = Store::where('id', $authUser->id)->first();
            $data['setting'] = Setting::select()->first();
            $data['site_setting'] = SiteSetting::select()->first();
            $data['permissions'] = $this->user->getAllPermissions()->pluck('name');
            $data['message_count'] = ContactUs::where('viewed', '!=', Config::get('constants.status.PUBLIC'))
                ->count();

            $data['media_storage'] = env('MEDIA_STORAGE');
            $languages = Language::where('status', Config::get('constants.status.PUBLIC'))
                ->orderBy('default', 'DESC')
                ->orderBy('created_at', 'DESC')
                ->select('name', 'code', 'default', 'direction', 'predefined')
                ->get();
            $data['languages'] = $languages;

            if (count($languages) > 0) {
                $data['default_language'] = $languages[0];
            }

            $data['img_src_url'] = FileHelper::imgSrcUrl();
            $data['thumb_prefix'] = env('THUMB_PREFIX');
            $data['default_image'] = env('DEFAULT_IMAGE');


            $posPlugin = Plugin::where('name', 'pos')->first();
            $posSetting = null;

            $data['pos_public_key'] = null;

            if ($posPlugin && $posPlugin->active) {

                $data['pos_public_key'] = $posPlugin->public_key;

                if ($lang != $data['default_language']->code) {

                    $posSetting = PosSetting::where('admin_id', $authUser->id)
                        ->leftJoin('pos_setting_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.pos_setting_id', '=', 'pos_settings.id');
                            $join->where('cl.lang', $lang);
                        })
                        ->select('pos_settings.*', 'cl.address', 'cl.header_text', 'cl.footer_text')
                        ->first();

                    if (!$posSetting) {
                        $posSetting = PosSetting::leftJoin('pos_setting_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.pos_setting_id', '=', 'pos_settings.id');
                            $join->where('cl.lang', $lang);
                        })
                            ->select('pos_settings.*', 'cl.address', 'cl.header_text', 'cl.footer_text')
                            ->where('is_default', 1)
                            ->first();
                    }

                } else {

                    $posSetting = PosSetting::where('admin_id', $authUser->id)->first();
                    if (!$posSetting) {
                        $posSetting = PosSetting::where('is_default', 1)->first();
                    }
                }
            }


            $data['pos_setting'] = $posSetting;

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

    }


    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'admin.view')) {
                return $can;
            }

            if ($request->q) {
                $data = Admin::with('roles')
                    ->orderBy($request->orderby, $request->type)
                    ->where('name', 'LIKE', "%{$request->q}%")
                    ->where('email', 'LIKE', "%{$request->q}%")
                    ->paginate(Config::get('constants.api.PAGINATION'));
            } else {
                $data = Admin::with('roles')
                    ->orderBy($request->orderby, $request->type)
                    ->paginate(Config::get('constants.api.PAGINATION'));
            }


            $ids = [];

            foreach ($data as $item) {
                array_push($ids, $item->id);
                $item['created'] = Utils::formatDate($item->created_at);
            }


            Admin::whereIn('id', $ids)->where('viewed', false)->update([
                'viewed' => true
            ]);

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');


            if ($can = Utils::userCan($this->user, 'admin.view')) {
                return $can;
            }

            $data = Admin::with('roles')->find($id);
            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }
            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function action(Request $request, Admin $admin)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::admin($request);
            if ($validate) {
                return response()->json($validate);
            }

            if ($request->roles[0] == 'vendor' && !is_numeric($request->commission)) {
                return response()->json(Validation::error($request->token,
                    __('lang.commission_must', [], $lang)));
            }

            $existingAdmin = Admin::where('email', $request->email)
                ->orWhere('username', $request->username)
                ->first();

            $adminExists = false;
            if (!is_null($existingAdmin)) {
                if ($admin->id) {
                    if ($admin->id != $existingAdmin->id) {
                        $adminExists = true;
                    }
                } else {
                    $adminExists = true;
                }
            }

            if ($adminExists) {
                return response()->json(Validation::error($request->token,
                    __('lang.already_exists', [], $lang)));
            }

            if ($request->password) {
                $request->merge([
                    'password' => Hash::make($request->password),
                ]);
            }

            $request['created_at'] = $request['updated_at'] = '';

            $active = $request->active;

            $filtered = array_filter($request->all(), function ($element) {
                return !is_array($element) && '' !== trim($element);
            });

            $filtered['active'] = $active;

            if ($admin->id) {
                if ($can = Utils::userCan($this->user, 'admin.edit')) {
                    return $can;
                }

                if (Auth::user()->id == $admin->id) {
                    return response()->json(Validation::error($request->token,
                        __('lang.own_role', [], $lang)));
                }
                $changedActivation = false;


                if (!$admin->active && $admin->verified && $filtered['active'] && $request->roles[0] == 'vendor') {
                    $changedActivation = true;
                }

                Admin::where('id', $admin->id)->update($filtered);
                $admin = Admin::find($admin->id);


                if ($changedActivation) {


                    $adminUrl = env('APP_URL');


                    if (env('APP_URL') == env('CLIENT_BASE_URL')) {
                        $adminUrl = $adminUrl . '/admin';
                    }

                    $brand = MailBrand::make($admin->name, $lang);

                    $objDemo = new \stdClass();
                    $objDemo->receiver = $admin->name;
                    $objDemo->address = $brand->address;
                    $objDemo->phone = $brand->phone;
                    $objDemo->store_name = $brand->store_name;
                    $objDemo->admin_url = $adminUrl;
                    $objDemo->commission = $admin->commission . '%';

                    $sellerSubject = MailDeliverability::subject(__('lang.acc_active', [], $lang));

                    Mail::send(
                        [
                            'html' => 'mail_templates.seller_activation',
                            'text' => 'mail_templates.text.seller_activation',
                        ],
                        ['data' => $objDemo, 'brand' => $brand, 'lang' => $lang],
                        function ($message) use ($admin, $brand, $sellerSubject) {
                            $message->to($admin->email, $admin->name)
                                ->subject($sellerSubject);

                            MailDeliverability::transactional($message, $brand, 'vendor-activation');
                        });
                }

            } else {
                if ($can = Utils::userCan($this->user, 'admin.create')) {
                    return $can;
                }

                $admin = Admin::create($filtered);

                $store['name'] = $admin->name;
                $store['slug'] = $admin->username;
                $store['admin_id'] = $admin->id;

                $existingSlug = Store::where('slug', $store['slug'])->first();
                if ($existingSlug) {
                    $store['slug'] = $admin->username . Utils::generateRandomString(5);
                }

                Store::create($store);
            }

            if ($request->roles) {
                $admin->roles()->detach();
                $admin->assignRole($request->roles);
            }

            return response()->json(new Response($request->token, $admin));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'admin.delete')) {
                return $can;
            }

            $data = Admin::find($id);

            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }


            if (Auth::user()->id == $data->id) {
                return response()->json(Validation::error($request->token,
                    __('lang.delete_account', [], $lang)));
            }

            $product = Product::where('admin_id', $id)->get()->first();
            if ($product) {
                return response()->json(Validation::error($request->token,
                    __('lang.has_products', [], $lang)));
            }

            $store = Store::where('admin_id', $id)->get();

            foreach ($store as $s) {
                StoreLang::where('store_id', $s->id)->delete();
            }

            Withdrawal::where('admin_id', $id)->delete();

            WithdrawalAccount::where('admin_id', $id)->delete();


            Store::where('admin_id', $id)->delete();
            Language::where('admin_id', $id)->delete();

            if ($data->delete()) {
                return response()->json(new Response($request->token, $data));
            }

            return response()->json(Validation::error($request->token, null, 'form', $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function deactivate(Request $request)
    {
        try {

            $existing = Licence::first();


            if (is_null($existing)) {
                return response()->json(Validation::nothing_found());
            }


            if (Licence::where('id', $existing->id)->delete()) {

                return response()->json(new Response(null, true));
            }

            return response()->json(Validation::error());


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function manualActivation(Request $request)
    {
        try {
            //$url = env('LICENCE_SERVER') . "/api/activate-ishop/{$request->code}";

            //$body = Utils::getRequest($url, $request);

            if ($request->public_key && $request->secret_key) {

                $result = [
                    'public_key' => $request->public_key,
                    'secret_key' => $request->secret_key,
                    'encrypt_key' => $request->encrypt_key,
                    'encrypt_iv' => $request->encrypt_iv,
                    'valid' => true
                ];

                $existingLicence = Licence::first();


                $baseURL = $request->url('/');
                $parse = parse_url($baseURL);
                $domain = $parse['host'];

                $isLocalhost = strpos($domain, "localhost") !== false || strpos($domain, "127.0.0.1") !== false;

                // $isLocalhost = false;//

                if ($isLocalhost) {
                    return response()->json(new Response($request->token, $result));

                }

                if ($existingLicence) {
                    Licence::where('id', $existingLicence->id)->update($result);

                } else {
                    Licence::create($result);

                }

                return response()->json(new Response($request->token, $result));

            }

            return response()->json(Validation::error(null,
                __('lang.went_wrong')
            ));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function activate(Request $request)
    {
        try {
            $url = env('LICENCE_SERVER') . "/api/activate-ishop/{$request->code}";

            $body = Utils::getRequest($url, $request);

            if ($body->data->public_key && $body->data->secret_key) {

                $result = [
                    'public_key' => $body->data->public_key,
                    'secret_key' => $body->data->secret_key,
                    'encrypt_key' => $body->data->encrypt_key,
                    'encrypt_iv' => $body->data->encrypt_iv,
                    'valid' => true
                ];

                $existingLicence = Licence::first();


                $baseURL = $request->url('/');
                $parse = parse_url($baseURL);
                $domain = $parse['host'];

                $isLocalhost = strpos($domain, "localhost") !== false || strpos($domain, "127.0.0.1") !== false;

                // $isLocalhost = false;//

                if ($isLocalhost) {
                    return response()->json(new Response($request->token, $result));
                }

                if ($existingLicence) {
                    Licence::where('id', $existingLicence->id)->update($result);

                } else {
                    Licence::create($result);

                }

                return response()->json(new Response($request->token, $result));

            }

            return response()->json(Validation::error(null,
                __('lang.went_wrong')
            ));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

}
