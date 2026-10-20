<?php

namespace App\Http\Controllers;

use App\Models\Attribute;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ContactUs;
use App\Models\CustomScript;
use App\Models\FlashSale;
use App\Models\UserSession;
use Laravel\Passport\Token;
use App\Models\FlashSaleProduct;
use App\Models\FooterImageLink;
use App\Models\AuthBanner;
use App\Models\Onboarding;
use App\Models\PointTransaction;
use App\Models\ProductSpecification;
use App\Models\GiftVoucher;
use App\Models\RecentlyViewedProduct;
use App\Models\FooterLink;
use App\Models\HeaderLink;
use App\Models\Helper\FileHelper;
use App\Models\Helper\ListingPrices;
use App\Models\Helper\FlashSaleAllowance;
use App\Models\Helper\FlashSaleWindow;
use App\Models\Helper\Pricing;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\HomeSlider;
use App\Models\Language;
use App\Models\BusinessProductPricing;
use App\Models\BundleDeal;
use App\Models\Clearance;
use App\Models\SubscriptionEmail;
use App\Models\Order;
use App\Models\Page;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\OrderedProductsInventory;
use App\Models\RatingReview;
use App\Models\Setting;
use App\Models\ShippingRule;
use App\Models\SiteFeature;
use App\Models\SiteSetting;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\Tag;
use App\Models\UpdatedInventory;
use App\Models\Cart;
use App\Services\BundleService;
use App\Services\CrossSellService;
use App\Models\UserFollowStore;
use App\Models\Voucher;
use App\Models\Upsell;
use App\Models\ProductTemplateCustomization;
use App\Models\ProductView;
use App\Models\UserWishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;


class FrontendController extends Controller
{

    public function all(Request $request)
    {
        try {

            $lang = $request->header('language');

            $query = Product::query();
            // withCount lets Pricing tell a real variation from a lone inventory
            // row without loading every attribute (QA: variation pricing).
            $query = $query->with(['product_inventories' => function ($q) {
                $q->withCount('inventory_attributes');
            }]);
            $query = $query->leftJoin('flash_sales', function ($join) {

                $join->on('products.id', '=', 'flash_sale_products.product_id');

                $join->leftJoin('flash_sale_products', function ($join) {
                    $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                });
                FlashSaleWindow::liveProduct($join);
            })
                ->groupBy('products.id');


            $allShipping = null;
            $allCollection = null;
            $allBrand = null;
            $sidebarData = $request->sidebar_data === 'true';


            if ($lang) {


                $queryCat = Category::query();

                $queryCat = $queryCat->withCount([
                    'products as products_count' => function ($q) {
                        $q->where('status', Config::get('constants.status.PUBLIC'));
                    }
                ]);

                // $queryCat = $queryCat->leftJoin('category_langs as cl', function ($join) use ($lang) {
                //     $join->on('cl.category_id', '=', 'categories.id');
                //     $join->where('cl.lang', $lang);
                // });
                // $queryCat = $queryCat->select('categories.*', 'cl.title');

                $queryCat = $queryCat->with([
                    'child' => function ($queryCat) use ($lang) {

                        $queryCat->leftJoin('category_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.category_id', '=', 'categories.id');
                            $join->where('cl.lang', $lang);
                        });

                        $queryCat->select('categories.*', 'cl.title')
                                ->withCount('products');
                    }
                ]);

                // $queryCat = $queryCat->with(['child' => function ($queryCat) use ($lang) {
                //     $queryCat->leftJoin('category_langs as cl', function ($join) use ($lang) {
                //         $join->on('cl.category_id', '=', 'categories.id');
                //         $join->where('cl.lang', $lang);
                //     });
                //     $queryCat->select('categories.*', 'cl.title');
                // }]);


                $queryCat = $queryCat->orderBy('categories.created_at', 'desc')
                    ->where('categories.status', Config::get('constants.status.PUBLIC'));
                $category = $queryCat->where('slug', $request->category)->first();


                $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });


                $query = $query->select('products.id', 'products.slug', 'pl.title', 'products.badge',
                    'products.selling', 'products.offered',
                    'products.image', 'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time','products.tax_rule_id',
                    'products.vatInclusivePrice','products.vatInclusiveOfferedPrice');


                if ($sidebarData) {

                    $allBrand = Brand::leftJoin('brand_langs as b', function ($join) use ($lang) {
                        $join->on('b.brand_id', '=', 'brands.id');
                        $join->where('b.lang', $lang);
                    })
                        ->where('brands.status', Config::get('constants.status.PUBLIC'))
                        ->select('brands.id', 'brands.title', 'b.title')
                        ->get();

                    $allCollection = ProductCollection::leftJoin('product_collection_langs as pcl',
                        function ($join) use ($lang) {
                            $join->on('pcl.product_collection_id', '=', 'product_collections.id');
                            $join->where('pcl.lang', $lang);
                        })
                        ->where('in_frontend',1 )
                        ->where('product_collections.status', Config::get('constants.status.PUBLIC'))
                        ->select('product_collections.id', 'product_collections.title', 'pcl.title')
                        ->get();

                    $allShipping = ShippingRule::leftJoin('shipping_rule_langs as srl',
                        function ($join) use ($lang) {
                            $join->on('srl.shipping_rule_id', '=', 'shipping_rules.id');
                            $join->where('srl.lang', $lang);
                        })
                        ->select('shipping_rules.id', 'shipping_rules.title', 'srl.title')
                        ->get();
                }

            } else {


                /*if ($request->sub_category) {

                    $subCategory = SubCategory::with('category')
                        ->where('slug', $request->sub_category)
                        ->first();

                    if ($subCategory) {
                        $query = $query->where('products.subcategory_id', $subCategory->id);
                    }


                } else */

                $category = Category::withCount([
                    'products as products_count' => function ($q) {
                        $q->where('status', Config::get('constants.status.PUBLIC'));
                    }
                ])
                    ->with([
                        'child' => function ($q) {
                            $q->withCount([
                                'products as products_count' => function ($query) {
                                    $query->where('status', Config::get('constants.status.PUBLIC'));
                                }
                            ]);
                        }
                    ])
                    ->orderBy('created_at', 'desc')
                    ->where('status', Config::get('constants.status.PUBLIC'))
                    ->where('in_frontend',1)
                    ->where('slug', $request->category)
                    ->first();


                //$query = $query->where('products.category_id', $category->id);

                $query = $query->select('products.id', 'products.title', 'products.slug',
                    'products.badge',
                    'products.selling', 'products.offered',
                    'products.image', 'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time');


                if ($sidebarData) {

                    $allBrand = Brand::where('status', Config::get('constants.status.PUBLIC'))
                        ->select('id', 'title')
                        ->get();

                    $allCollection = ProductCollection::where('status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend',1 )
                        ->select('id', 'title')
                        ->get();

                    $allShipping = ShippingRule::select('id', 'title')->get();

                }
            }


            if ($request->brand) {
                $query = $query->whereIn('products.brand_id', explode(',', $request->brand));
            }

            if ($request->collection) {
                $query = $query
                    ->rightJoin('collection_with_products as cwp', function ($join) {
                        $join->on('products.id', '=', 'cwp.product_id');
                    })
                    ->rightJoin('product_collections as pc', function ($join) use ($request) {
                        $join->on('pc.id', '=', 'cwp.product_collection_id');
                        $join->where('pc.status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend',1 )
                        ->whereIn('pc.id', explode(',', $request->collection));
                    });
            }


            if ($request->category) {

                $productCategories = ProductCategory::where('category_id', $category->id)->get();

                $productIds = [];
                foreach ($productCategories as $i) {
                    array_push($productIds, $i->product_id);
                }


                $query = $query->whereIn('products.id', $productIds);


            }


            if ($request->rating != 0) {
                $query = $query->where('products.rating', '>=', $request->rating);
            }

            if ($request->max > 0 || $request->min > 0) {
                if ($request->max == 0) {
                    $request->max = 999999;
                }

                $query = $query->where(function ($q) use ($request) {
                    $q->where(function ($qr) use ($request) {
                        $qr->whereNotNull('flash_sales.end_time');
                        $qr->whereBetween('flash_sale_products.price',
                        'flash_sale_products.discount_type as flash_discount_type',
                        'flash_sale_products.discount_value as flash_discount_value', [$request->min, $request->max]);

                    });
                    $q->orWhere(function ($qr) use ($request) {
                        $qr->whereNull('flash_sales.end_time');
                        $qr->where('products.offered', '=', 0);
                        $qr->whereBetween('products.selling', [$request->min, $request->max]);;

                    });
                    $q->orWhere(function ($qr) use ($request) {
                        $qr->whereNull('flash_sales.end_time');
                        $qr->where('products.offered', '>', 0);
                        $qr->whereBetween('products.offered', [$request->min, $request->max]);
                    });
                });
            }

            $query = $query->where('products.status', Config::get('constants.status.PUBLIC'));


            // Products are no longer assigned a shipping rule, so there is
            // nothing to filter by; an old ?shipping= link is ignored.

            // Availability filters — mirrors what the product tile shows:
            // "in stock" is any inventory row with stock left or back order
            // enabled, "on offer" is a reduced price or a live flash sale.
            if (filter_var($request->stock, FILTER_VALIDATE_BOOLEAN)) {
                $query = $query->whereHas('product_inventories', function ($q) {
                    // nested so the OR cannot escape the relation constraint
                    $q->where(function ($qr) {
                        $qr->where('updated_inventories.quantity', '>', 0)
                            ->orWhere('updated_inventories.is_active', 1);
                    });
                });
            }

            if (filter_var($request->offer, FILTER_VALIDATE_BOOLEAN)) {
                $query = $query->where(function ($q) {
                    $q->where('products.offered', '>', 0)
                        ->orWhereNotNull('flash_sales.end_time');
                });
            }

            if ($request->sortby) {
                if ($request->sortby == 'price_low_to_high') {

                    $query = $query
                        ->addSelect(DB::raw(
                            '(CASE
                        WHEN flash_sales.end_time IS NOT NULL
                            THEN flash_sale_products.price
                        WHEN products.offered=0 OR products.offered IS NULL
                            THEN products.selling
                        ELSE products.offered
                        END) AS current_price'
                        ))
                        ->orderBy('current_price', 'asc');

                } else if ($request->sortby == 'price_high_to_low') {

                    $query = $query
                        ->addSelect(DB::raw(
                            '(CASE
                        WHEN flash_sales.end_time IS NOT NULL
                            THEN flash_sale_products.price
                        WHEN products.offered=0 OR products.offered IS NULL
                            THEN products.selling
                        ELSE products.offered
                        END) AS current_price'
                        ))
                        ->orderBy('current_price', 'desc');

                } else if ($request->sortby == 'avg_customer_review') {
                    $query = $query->orderBy('products.rating', 'desc');
                } else {
                    $query = $query->orderBy('products.created_at', 'desc');
                }
            } else {
                $query = $query->orderBy('products.updated_at', 'desc');
            }

            $pagination = Config::get('constants.listing.PAGINATION');

            $data['result'] = $query->paginate($pagination);
            $this->applyClearanceToProducts($data['result']->getCollection());

            //$data['sub_category'] = $subCategory;
            $data['category'] = $category;
            $data['shipping'] = $allShipping;
            $data['shipping'] = $allShipping;
            $data['brands'] = $allBrand;
            $data['collections'] = $allCollection;

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }


    public function store(Request $request)
    {
        try {

            $lang = $request->header('language');
            $slug = $request->slug;


            if ($lang) {

                $store = Store::where('slug', $slug)
                    ->leftJoin('store_langs as sl', function ($join) use ($lang) {
                        $join->on('sl.store_id', '=', 'stores.id');
                        $join->where('sl.lang', $lang);
                    })
                    ->select('stores.*', 'sl.name', 'sl.meta_title', 'sl.meta_description', 'sl.meta_keywords')
                    ->first();


                $query = Product::query();
                $query = $query->leftJoin('flash_sales', function ($join) {

                    $join->on('products.id', '=', 'flash_sale_products.product_id');

                    $join->leftJoin('flash_sale_products', function ($join) {
                        $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                    });
                    FlashSaleWindow::liveProduct($join);
                })
                    ->groupBy('products.id');

                $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });

                $query = $query->where('products.admin_id', $store->admin_id);
                $query = $query->where('products.status', Config::get('constants.status.PUBLIC'));
                $query = $query->select('products.id', 'pl.title', 'products.slug', 'pl.badge',
                    'products.selling', 'products.offered',
                    'products.image', 'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time','products.tax_rule_id',
                    'products.vatInclusivePrice','products.vatInclusiveOfferedPrice');

            } else {


                $store = Store::where('slug', $slug)->first();
                $query = Product::query();
                $query = $query->leftJoin('flash_sales', function ($join) {

                    $join->on('products.id', '=', 'flash_sale_products.product_id');

                    $join->leftJoin('flash_sale_products', function ($join) {
                        $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                    });
                    FlashSaleWindow::liveProduct($join);
                })
                    ->groupBy('products.id');


                $query = $query->where('products.admin_id', $store->admin_id);
                $query = $query->where('products.status', Config::get('constants.status.PUBLIC'));
                $query = $query->select('products.id', 'products.title', 'products.slug', 'products.badge',
                    'products.selling', 'products.offered',
                    'products.image', 'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time','products.tax_rule_id',
                    'products.vatInclusivePrice','products.vatInclusiveOfferedPrice');
            }


            if ($request->sortby) {
                if ($request->sortby == 'price_low_to_high') {

                    $query = $query
                        ->addSelect(DB::raw(
                            '(CASE
                        WHEN flash_sales.end_time IS NOT NULL
                            THEN flash_sale_products.price
                        WHEN products.offered=0 OR products.offered IS NULL
                            THEN products.selling
                        ELSE products.offered
                        END) AS current_price'
                        ))
                        ->orderBy('current_price', 'asc');

                } else if ($request->sortby == 'price_high_to_low') {

                    $query = $query
                        ->addSelect(DB::raw(
                            '(CASE
                        WHEN flash_sales.end_time IS NOT NULL
                            THEN flash_sale_products.price
                        WHEN products.offered=0 OR products.offered IS NULL
                            THEN products.selling
                        ELSE products.offered
                        END) AS current_price'
                        ))
                        ->orderBy('current_price', 'desc');

                } else if ($request->sortby == 'avg_customer_review') {
                    $query = $query->orderBy('products.rating', 'desc');
                } else {
                    $query = $query->orderBy('products.created_at', 'desc');
                }
            } else {
                $query = $query->orderBy('products.updated_at', 'desc');
            }

            $pagination = Config::get('constants.listing.PAGINATION');

            $data['result'] = $query->paginate($pagination);
            $data['store'] = $store;
            $data['following'] = false;
            $data['review'] = 0;


            if ($request->required_rating) {
                $data['review'] = Product::where('admin_id', $store->admin_id)
                    ->where('rating', '>', 0)
                    ->avg('rating');
            }


            if (Auth::guard('user')->check()) {

                $user = Auth::guard('user')->user();

                $followed = UserFollowStore::where('user_id', $user->id)
                    ->where('store_id', $store->id)
                    ->first();

                if ($followed) {
                    $data['following'] = true;
                }
            }


            return response()->json(new Response($request->token, $data));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }


    public function countriesPhones(Request $request)
    {
        try {

            $cacheKey = 'countries-phones';

            $resp = Utils::cacheRemember($cacheKey, function () use ($request) {

                $countries = file_get_contents(base_path() . '/storage/resources/countries.json');
                $phones = file_get_contents(base_path() . '/storage/resources/phones.json');


                $data['countries'] = json_decode($countries, true);
                $data['phones'] = json_decode($phones, true);


                return $data;

            });

            return response()->json(new Response(null, $resp));


        } catch (\Exception $e) {

            return response()->json(Validation::error($request->token, $e->getMessage()));
        }
    }


    /**
     * The towns a county or state holds, for the address forms' town select.
     *
     * One state's list per call - the generated files are keyed by the state
     * codes in countries.json, and the largest of them is a quarter of a
     * megabyte, so the whole country is never sent to a browser. An unknown
     * country or state answers with an empty list, which is what tips the
     * form back to a free-text town rather than an error.
     */
    public function towns(Request $request)
    {
        try {

            $code = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $request->country));
            $state = (string) $request->state;

            if (strlen($code) !== 2) {
                return response()->json(new Response(null, []));
            }

            $cacheKey = 'towns-' . $code;

            $all = Utils::cacheRemember($cacheKey, function () use ($code) {

                $path = base_path() . '/storage/resources/cities/' . $code . '.json';

                if (!is_file($path)) {
                    return [];
                }

                return json_decode(file_get_contents($path), true) ?: [];
            });

            if ($state === '') {
                return response()->json(new Response(null, []));
            }

            return response()->json(new Response(null, $all[$state] ?? []));

        } catch (\Exception $e) {

            return response()->json(Validation::error($request->token, $e->getMessage()));
        }
    }


    public function resource(Request $request, $name)
    {
        try {

            $cacheKey = $name;

            $resp = Utils::cacheRemember($cacheKey, function () use ($request, $name) {

                $data = file_get_contents(base_path() . '/storage/resources/' . $name . '.json');

                return json_decode($data, true);

            });

            return response()->json(new Response(null, $resp));


        } catch (\Exception $e) {

            return response()->json(Validation::error($request->token, $e->getMessage()));
        }
    }


    public function localization(Request $request)
    {
        try {

            $langCode = $request->locale_code;

            $cacheKey = "frontend-lang" . $langCode;
            if (!$langCode) {
                $cacheKey = "frontend-lang";
            }

            $resp = Utils::cacheRemember($cacheKey, function () use ($request, $langCode) {

                $data = file_get_contents(base_path() . '/resources/lang/' . $langCode . '/frontend.json');

                return json_decode($data, true);

            });

            return response()->json(new Response(null, $resp));

        } catch (\Exception $e) {

            return response()->json(Validation::error($request->token, $e->getMessage()));
        }
    }


    public function localizationAdmin(Request $request)
    {
        try {

            $langCode = $request->locale_code;

            $cacheKey = "admin-lang" . $langCode;
            if (!$langCode) {
                $cacheKey = "admin-lang";
            }

            $resp = Utils::cacheRemember($cacheKey, function () use ($request, $langCode) {

                $data = file_get_contents(base_path() . '/resources/lang/' . $langCode . '/admin.json');

                return json_decode($data, true);

            });

            return response()->json(new Response(null, $resp));

        } catch (\Exception $e) {

            return response()->json(Validation::error($request->token, $e->getMessage()));
            //return response()->json(Validation::error($request->token, __('lang.lang_err_msg')));
        }
    }


    public function paymentGateway(Request $request)
    {
        try {
            // Never the whole row: it holds every gateway's secret key.
            $data = Payment::publicSettings();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    public function categories(Request $request)
    {
        try {
            $lang = $request->header('language');

            $query = Category::query()
            ->where("categories.in_frontend", 1)
            ->whereNull('parent')
            ->where('categories.status', Config::get('constants.status.PUBLIC'))
            ->withCount('products');

            // The listing card carries a row of subcategory tags. They used to
            // be four hard-coded strings repeated on every card; these are the
            // category's own children, busiest first.
            $query->with(['children' => function ($childQuery) use ($lang) {

                $childQuery
                    ->where('categories.in_frontend', 1)
                    ->where('categories.status', Config::get('constants.status.PUBLIC'));

                if ($lang) {

                    $childQuery->leftJoin('category_langs as ccl', function ($join) use ($lang) {
                        $join->on('ccl.category_id', '=', 'categories.id');
                        $join->where('ccl.lang', $lang);
                    });

                    // an untranslated child would otherwise render as a blank tag
                    $childQuery->select(
                        'categories.id',
                        'categories.parent',
                        'categories.slug',
                        DB::raw('COALESCE(ccl.title, categories.title) as title')
                    );

                } else {

                    $childQuery->select(
                        'categories.id',
                        'categories.parent',
                        'categories.slug',
                        'categories.title'
                    );
                }

                // withCount appends through addSelect, so it has to come after
                // the explicit select or that select drops the count column
                $childQuery
                    ->withCount('products')
                    ->orderByDesc('products_count')
                    ->orderBy('categories.title', 'ASC');
            }]);

            if ($lang) {

                $query = $query->leftJoin('category_langs as scl', function ($join) use ($lang) {
                    $join->on('scl.category_id', '=', 'categories.id');
                    $join->where('scl.lang', $lang);
                });

                $query = $query->addSelect(
                    'categories.id',
                    'categories.slug',
                    'categories.image',
                    'scl.title',
                    'scl.meta_title',
                    'scl.meta_description',
                    'scl.meta_keywords');

            } else {
                $query->addSelect('categories.id', 'categories.title', 'categories.image', 'categories.slug');
            }

            $sort = $request->sort;

            if ($sort === 'name_desc') {

                if ($lang) {
                    $query->orderBy('scl.title', 'DESC');
                } else {
                    $query->orderBy('categories.title', 'DESC');
                }

            } else {

                if ($lang) {
                    $query->orderBy('scl.title', 'ASC');
                } else {
                    $query->orderBy('categories.title', 'ASC');
                }
            }

            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', Config::get('constants.frontend.PAGINATION'));
            $data = $query->paginate($perPage, ['*'], 'page', $page);

            // Eloquent cannot LIMIT an eager load per parent, so the cut to the
            // handful the card has room for happens here.
            $topSubCategoryLimit = 4;

            $data->getCollection()->transform(function ($category) use ($topSubCategoryLimit) {

                $category->setRelation(
                    'children',
                    $category->children->take($topSubCategoryLimit)->values()
                );

                return $category;
            });

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $e) {
            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }


    public function brands(Request $request)
    {
        try {
            $lang = $request->header('language');


            $query = Brand::query();


            if ($lang) {

                $query = $query->leftJoin('brand_langs as b', function ($join) use ($lang) {
                    $join->on('b.brand_id', '=', 'brands.id');
                    $join->where('b.lang', $lang);
                });
                $query = $query->select('brands.id', 'brands.slug', 'brands.image', 'b.title');


            } else {
                $query = $query->select('title', 'image', 'id', 'slug');
            }


            $query = $query->where('brands.status', Config::get('constants.status.PUBLIC'));
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', Config::get('constants.frontend.PAGINATION'));
            $data = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    public function search(Request $request)
    {
        try {
            $lang = $request->header('language');


            $data['product'] = [];
            $data['suggested'] = [];
            $data['category'] = [];

            if ($request->q) {
                $queryC = Category::query();
                $queryP = Product::query();


                if ($lang) {
                    $queryC = $queryC->leftJoin('category_langs as cl', function ($join) use ($lang) {
                        $join->on('cl.category_id', '=', 'categories.id');
                        $join->where('cl.lang', $lang);
                    });
                    $queryC = $queryC->select('categories.id', 'categories.image', 'cl.title', 'categories.slug');

                    $queryP = $queryP->leftJoin('product_langs as pl', function ($join) use ($lang) {
                        $join->on('pl.product_id', '=', 'products.id');
                        $join->where('pl.lang', $lang);
                    });

                    $queryP = $queryP->select('products.id', 'pl.title', 'products.slug',
                        'products.selling', 'products.offered',
                        'products.image', 'flash_sale_products.price',
                        'flash_sale_products.discount_type as flash_discount_type',
                        'flash_sale_products.discount_value as flash_discount_value',
                        'flash_sales.end_time','products.tax_rule_id',
                    'products.vatInclusivePrice','products.vatInclusiveOfferedPrice');

                    $queryP = $queryP->where(function ($q) use ($request) {
                        $q->where('pl.title', 'LIKE', "%{$request->q}%")
                            ->orWhere('products.tags', 'LIKE', "%{$request->q}%");
                    });

                } else {

                    $queryC = $queryC->where('title', 'LIKE', "%{$request->q}%");
                    $queryC = $queryC->select('id', 'image', 'title', 'slug');

                    $queryP = $queryP->select('products.id', 'products.title', 'products.slug',
                        'products.selling', 'products.offered',
                        'products.image', 'flash_sale_products.price',
                        'flash_sale_products.discount_type as flash_discount_type',
                        'flash_sale_products.discount_value as flash_discount_value',
                        'flash_sales.end_time','products.tax_rule_id',
                        'products.vatInclusivePrice','products.vatInclusiveOfferedPrice');

                    $queryP = $queryP->where(function ($q) use ($request) {
                        $q->where('products.title', 'LIKE', "%{$request->q}%")
                            ->orWhere('products.tags', 'LIKE', "%{$request->q}%");
                    });
                }

                $queryC = $queryC->where('status', Config::get('constants.status.PUBLIC'));
                $queryC = $queryC->limit(Config::get('constants.pagination.FRONTEND_SEARCH'));


               // $queryC = $queryC->orderByRaw("CASE WHEN title LIKE '%$request->q%' THEN 1 ELSE 2 END");

                $data['category'] = $queryC->get();

                $queryT = Tag::query();
                $queryT = $queryT->where(function ($q) use ($request) {
                    $q->where('title', 'LIKE', "%{$request->q}%");
                });
                $queryT = $queryT->limit(Config::get('constants.pagination.FRONTEND_SEARCH'));
                $queryT = $queryT->select('id', 'title');
                $data['suggested'] = $queryT->get();

                $queryP = $queryP->leftJoin('flash_sales', function ($join) {
                    $join->on('products.id', '=', 'flash_sale_products.product_id');
                    $join->leftJoin('flash_sale_products', function ($join) {
                        $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                    });
                    FlashSaleWindow::liveProduct($join);
                });

                $queryP = $queryP->join(
                    'product_categories as pc',
                    'products.id',
                    '=',
                    'pc.product_id'
                );

                $queryP = $queryP->join(
                    'categories as c',
                    'pc.category_id',
                    '=',
                    'c.id'
                );

                if ($request->category) {

                    $queryP = $queryP->where(
                        'c.slug',
                        $request->category
                    );
                }

                $queryP = $queryP->orderByRaw("
                    CASE
                        WHEN LOWER(c.title) = LOWER(?) THEN 1
                        WHEN LOWER(products.title) = LOWER(?) THEN 2
                        WHEN LOWER(products.title) LIKE LOWER(?) THEN 3
                        ELSE 4
                    END
                ", [
                    $request->q,
                    $request->q,
                    $request->q . '%'
                ])
                ->orderBy('products.updated_at', 'DESC');

                $queryP = $queryP->limit(Config::get('constants.pagination.FRONTEND_SEARCH'));
                $queryP = $queryP->where('products.status', Config::get('constants.status.PUBLIC'));



                $data['product'] = $queryP->groupBy('products.id')->get();
                $this->applyClearanceToProducts($data['product']);
            }

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }


    public function products(Request $request)
    {
        try {

            $lang = $request->header('language');


            $query = Product::query();
            $query = $query->whereNotNull('products.slug');
            // withCount lets Pricing tell a real variation from a lone inventory
            // row without loading every attribute (QA: variation pricing).
            $query = $query->with(['product_inventories' => function ($q) {
                $q->withCount('inventory_attributes');
            }]);
            $query = $query->leftJoin('flash_sales', function ($join) {

                $join->on('products.id', '=', 'flash_sale_products.product_id');

                $join->leftJoin('flash_sale_products', function ($join) {
                    $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                });
                FlashSaleWindow::liveProduct($join);
            })
                ->groupBy('products.id');


            $sourceResponse = null;
            $allCategories = null;
            $category = null;
            $allShipping = null;
            $allCollection = null;
            $allBrand = null;
            $source = null;

            $sidebarData = $request->sidebar_data === 'true' ? true : false;
            $hasAllCategories = $request->all_categories === 'true' ? true : false;

            if ($lang) {


                if ($request->q) {
                    $query = $query->where(function ($q) use ($request) {
                        $q->where('pl.title', 'LIKE', "%{$request->q}%")
                            ->orWhere('products.tags', 'LIKE', "%{$request->q}%");
                    });
                } else if ($request->home_spm || $request->banner) {



                    if ($request->home_spm) {
                        // Products of slider
                        $source = HomeSlider::with('source_brands.brand')
                            ->leftJoin('home_slider_langs as cl', function ($join) use ($lang) {
                                $join->on('cl.home_slider_id', '=', 'home_sliders.id');
                                $join->where('cl.lang', $lang);
                            })
                            ->select('home_sliders.*', 'cl.title')
                            ->with(['source_categories.category'])
                            ->with('source_products.product')
                            ->find($request->home_spm);

                    } else {
                        $source = Banner::with('source_brands.brand')
                            ->leftJoin('banner_langs as cl', function ($join) use ($lang) {
                                $join->on('cl.banner_id', '=', 'banners.id');
                                $join->where('cl.lang', $lang);
                            })
                            ->select('banners.*', 'cl.title')
                            ->with(['source_categories.category'])
                            ->with('source_products.product')
                            ->find($request->banner);



                    }
                }


                $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });

                $query = $query->select(['products.id', 'products.slug', 'pl.badge', 'pl.title',
                    'products.selling', 'products.offered',
                    'products.image', 'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time','products.tax_rule_id',
                    'products.vatInclusivePrice','products.vatInclusiveOfferedPrice']);


                if ($hasAllCategories) {
                    $allCategories = Category::leftJoin('category_langs as cl', function ($join) use ($lang) {
                        $join->on('cl.category_id', '=', 'categories.id');
                        $join->where('cl.lang', $lang);
                    })
                        ->select('categories.id', 'cl.title', 'categories.slug')
                        ->where('parent', 0)
                        ->orWhere('parent', null)
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend',1)
                        ->get();
                }


                if ($sidebarData) {

                    $allBrand = Brand::leftJoin('brand_langs as b', function ($join) use ($lang) {
                        $join->on('b.brand_id', '=', 'brands.id');
                        $join->where('b.lang', $lang);
                    })
                        ->where('brands.status', Config::get('constants.status.PUBLIC'))
                        ->select('brands.id', 'brands.title', 'b.title')
                        ->get();

                    $allCollection = ProductCollection::leftJoin('product_collection_langs as pcl',
                        function ($join) use ($lang) {
                            $join->on('pcl.product_collection_id', '=', 'product_collections.id');
                            $join->where('pcl.lang', $lang);
                        })
                        ->where('product_collections.status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend', 1)
                        ->select('product_collections.id', 'product_collections.title', 'pcl.title')
                        ->get();

                    $allShipping = ShippingRule::leftJoin('shipping_rule_langs as srl',
                        function ($join) use ($lang) {
                            $join->on('srl.shipping_rule_id', '=', 'shipping_rules.id');
                            $join->where('srl.lang', $lang);
                        })
                        ->select('shipping_rules.id', 'shipping_rules.title', 'srl.title')
                        ->get();

                }


            } else {

                if ($request->q) {
                    $query = $query->where(function ($q) use ($request) {
                        $q->where('products.title', 'LIKE', "%{$request->q}%")
                            ->orWhere('products.tags', 'LIKE', "%{$request->q}%");
                    });
                } else if ($request->home_spm || $request->banner) {



                    if ($request->home_spm) {
                        // Products of slider
                        $source = HomeSlider::with('source_brands.brand')
                            ->with(['source_categories.category'])
                            ->with('source_products.product')
                            ->find($request->home_spm);


                    } else {
                        $source = Banner::with('source_brands.brand')
                            ->with(['source_categories.category'])
                            ->with('source_products.product')
                            ->find($request->banner);


                    }

                }


                $query = $query->select(['products.id', 'products.badge', 'products.title', 'products.slug',
                    'products.selling', 'products.offered',
                    'products.image', 'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time','products.tax_rule_id',
                    'products.vatInclusivePrice','products.vatInclusiveOfferedPrice']);


                if ($hasAllCategories) {
					
                    $allCategories = Category::where('status', Config::get('constants.status.PUBLIC'))
                        ->where('parent', 0)
                        ->select('id', 'title', 'slug')
                        ->orWhere('parent', null)
						->where('in_frontend',1)
                        ->get();
                }

                if ($sidebarData) {

                    $allBrand = Brand::where('status', Config::get('constants.status.PUBLIC'))->select('id', 'title')->get();
                    $allCollection = ProductCollection::where('status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend',1)
                        ->select('id', 'title')->get();

                    $allShipping = ShippingRule::select('id', 'title')->get();
                }

            }


            if (!is_null($source)) {

                $sourceResponse['id'] = $source->id;
                $sourceResponse['title'] = $source->title;



                if ((int)$source['source_type'] === Config::get('constants.sliderSourceType.CATEGORY')) {

                    $itemIds = [];
                    foreach ($source['source_categories'] as $item) {
                        array_push($itemIds, $item['category_id']);
                    }

                    $query = $query->leftJoin('product_categories as pc', function ($join) use ($itemIds) {
                        $join->on('products.id', '=', 'pc.product_id');
                    })->whereIn('pc.category_id', $itemIds);

                } else if ((int)$source['source_type'] === Config::get('constants.sliderSourceType.BRAND')) {
                    $itemIds = [];
                    foreach ($source['source_brands'] as $item) {
                        array_push($itemIds, $item['brand']['id']);
                    }
                    $query = $query->whereIn('products.brand_id', $itemIds);
                } else if ((int)$source['source_type'] === Config::get('constants.sliderSourceType.TAG')) {
                    foreach (explode(',', $source->tags) as $tag) {
                        if ($tag) {
                            $query = $query->where('products.tags', 'LIKE', "%{$tag}%");
                        }
                    }
                } else if ((int)$source['source_type'] === Config::get('constants.sliderSourceType.PRODUCT')) {
                    $itemIds = [];
                    foreach ($source['source_products'] as $item) {
                        array_push($itemIds, $item['product']['id']);
                    }
                    $query = $query->whereIn('products.id', $itemIds);
                }
            }


            $data['source'] = $sourceResponse;


            if ($request->brand) {
                $query = $query->whereIn('products.brand_id', explode(',', $request->brand));
            }

            if ($request->collection) {
                $query = $query
                    ->rightJoin('collection_with_products as cwp', function ($join) {
                        $join->on('products.id', '=', 'cwp.product_id');
                    })
                    ->rightJoin('product_collections as pc', function ($join) use ($request) {
                        $join->on('pc.id', '=', 'cwp.product_collection_id');
                        $join->where('pc.status', Config::get('constants.status.PUBLIC'))
                            ->where('in_frontend',1)
                            ->whereIn('pc.id', explode(',', $request->collection));
                    });
            }

            if ($request->rating != 0) {
                $query = $query->where('products.rating', '>=', $request->rating);
            }

            if ($request->max > 0 || $request->min > 0) {
                if ($request->max == 0) {
                    $request->max = 999999;
                }

                $query = $query->where(function ($q) use ($request) {
                    $q->where(function ($qr) use ($request) {
                        $qr->whereNotNull('flash_sales.end_time');
                        $qr->whereBetween('flash_sale_products.price',
                        'flash_sale_products.discount_type as flash_discount_type',
                        'flash_sale_products.discount_value as flash_discount_value', [$request->min, $request->max]);

                    });
                    $q->orWhere(function ($qr) use ($request) {
                        $qr->whereNull('flash_sales.end_time');
                        $qr->where('products.offered', '=', 0);
                        $qr->whereBetween('products.selling', [$request->min, $request->max]);;

                    });
                    $q->orWhere(function ($qr) use ($request) {
                        $qr->whereNull('flash_sales.end_time');
                        $qr->where('products.offered', '>', 0);
                        $qr->whereBetween('products.offered', [$request->min, $request->max]);
                    });
                });
            }

            $query = $query->where('products.status', Config::get('constants.status.PUBLIC'));

            if ($request->category) {

                $query = $query
                    ->join(
                        'product_categories as search_pc',
                        'products.id',
                        '=',
                        'search_pc.product_id'
                    )

                    ->join(
                        'categories as search_c',
                        'search_pc.category_id',
                        '=',
                        'search_c.id'
                    )

                    ->where(
                        'search_c.slug',
                        $request->category
                    )

                    ->groupBy('products.id');
            }

            // Products are no longer assigned a shipping rule, so there is
            // nothing to filter by; an old ?shipping= link is ignored.

            if ($request->sortby) {
                if ($request->sortby == 'price_low_to_high') {

                    $query = $query
                        ->addSelect(DB::raw(
                            '(CASE
                        WHEN flash_sales.end_time IS NOT NULL
                            THEN flash_sale_products.price
                        WHEN products.offered=0 OR products.offered IS NULL
                            THEN products.selling
                        ELSE products.offered
                        END) AS current_price'
                        ))
                        ->orderBy('current_price', 'asc');

                } else if ($request->sortby == 'price_high_to_low') {

                    $query = $query
                        ->addSelect(DB::raw(
                            '(CASE
                        WHEN flash_sales.end_time IS NOT NULL
                            THEN flash_sale_products.price
                        WHEN products.offered=0 OR products.offered IS NULL
                            THEN products.selling
                        ELSE products.offered
                        END) AS current_price'
                        ))
                        ->orderBy('current_price', 'desc');

                } else if ($request->sortby == 'avg_customer_review') {
                    $query = $query->orderBy('products.rating', 'DESC');
                } else {
                    $query = $query->orderBy('products.updated_at', 'DESC');
                }
            } else {
                $query = $query->orderBy('products.updated_at', 'DESC');
            }

            $pagination = Config::get('constants.listing.PAGINATION');
            if ($request->is_home_page) {
                $pagination = Config::get('constants.homeProduct.PAGINATION');
            }


            $data['result'] = $query->paginate($pagination);
            $this->applyClearanceToProducts($data['result']->getCollection());
            $data['category'] = $category;
            $data['all_categories'] = $allCategories;
            $data['shipping'] = $allShipping;
            $data['brands'] = $allBrand;
            $data['collections'] = $allCollection;

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    public function categoryProducts(Request $request, $categoryId)
    {
        try {

            $lang = $request->header('language');

            $query = Product::query();

            $query = $query->leftJoin('flash_sale_products', function ($join) {
                $join->on('products.id', '=', 'flash_sale_products.product_id');
            })
            ->leftJoin('flash_sales', function ($join) {
                $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                FlashSaleWindow::liveProduct($join);
            })
            ->groupBy('products.id');

            $query = $query->leftJoin('product_categories as pc', function ($join) {
                $join->on('products.id', '=', 'pc.product_id');
            })
            ->where('pc.category_id', $categoryId);

            if ($lang) {

                $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });

                $query = $query->select([
                    'products.id',
                    'products.slug',
                    'pl.title',
                    'pl.badge',
                    'products.selling',
                    'products.offered',
                    'products.image',
                    'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time',
                    'products.vatInclusivePrice',
                    'products.vatInclusiveOfferedPrice'
                ]);
            } else {

                $query = $query->select([
                    'products.id',
                    'products.slug',
                    'products.title',
                    'products.badge',
                    'products.selling',
                    'products.offered',
                    'products.image',
                    'flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
                    'flash_sales.end_time',
                    'products.vatInclusivePrice',
                    'products.vatInclusiveOfferedPrice'
                ]);
            }

            $query->where('products.status', Config::get('constants.status.PUBLIC'));

            if ($request->offers == 1) {
                $query->where(function ($q) {
                    $q->whereNotNull('flash_sales.end_time')
                    ->orWhere('products.offered', '>', 0);
                });
            }

            $query->inRandomOrder();

            $products = $query->get();
            $this->applyClearanceToProducts($products);

            return response()->json([
                'success' => true,
                'data' => $products
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function common(Request $request)
    {
        try {
            $lang = $request->header('lang');

            $cacheKey = "common" . $lang;
            if (!$lang) {
                $cacheKey = "common";
            }

            $commonData = Utils::cacheRemember($cacheKey, function () use ($request, $lang) {

                $languages = Language::where('status', Config::get('constants.status.PUBLIC'))
                    ->orderBy('default', 'DESC')
                    ->orderBy('created_at', 'DESC')
                    ->select('name', 'code', 'default', 'direction', 'predefined')
                    ->get();

                $data['languages'] = $languages;

                if (count($languages) > 0) {
                    $data['default_language'] = $languages[0];

                    if ($languages[0]->code == $lang) {
                        $lang = null;
                    }
                }

                // PAYMENT
                /*$paymentGateway = Payment::first();
                if ($paymentGateway) {
                    $data['payment_gateway'] = $paymentGateway;
                }*/

                $data['img_src_url'] = FileHelper::imgSrcUrl();
                $data['thumb_prefix'] = env('THUMB_PREFIX');
                $data['default_image'] = env('DEFAULT_IMAGE');

                if ($lang) {
                    // HEADER LINKS
                    $hl = HeaderLink::leftJoin('header_link_langs as hl', function ($join) use ($lang) {
                        $join->on('hl.header_link_id', '=', 'header_links.id');
                        $join->where('hl.lang', $lang);
                    })
                        ->orderBy('created_at', 'ASC')
                        ->select('header_links.*', 'hl.title')
                        ->get();

                    $headerLinks['left'] = [];
                    $headerLinks['right'] = [];

                    foreach ($hl as $i) {
                        if ((int)$i->type == Config::get('constants.headerLinkType.LEFT')) {
                            array_push($headerLinks['left'], $i);
                        } else {
                            array_push($headerLinks['right'], $i);
                        }
                    }
                    $data['header_links'] = $headerLinks;


                    // SETTING
                    $siteSetting = SiteSetting::leftJoin('site_setting_langs as cl', function ($join) use ($lang) {
                        $join->on('cl.site_setting_id', '=', 'site_settings.id');
                        $join->where('cl.lang', $lang);
                    })
                        ->select('site_settings.*', 'cl.site_name', 'cl.copyright_text', 'cl.meta_title',
                            'cl.meta_description', 'cl.meta_keywords')
                        ->first();


                    // FOOTER CATEGORIES
                    $query = Category::query();

                    $query = $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                        $join->on('cl.category_id', '=', 'categories.id');
                        $join->where('cl.lang', $lang);
                    });
                    $query = $query->select('categories.*', 'cl.title');

                    $query = $query->with(['in_footer_child' => function ($query) use ($lang) {
                        $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.category_id', '=', 'categories.id');
                            $join->where('cl.lang', $lang);
                        });
                        $query->select('categories.*', 'cl.title');
                    }]);


                    $query = $query->orderBy('categories.created_at', 'desc')
                        ->where('categories.status', Config::get('constants.status.PUBLIC'));

                    $query = $query->where('categories.in_footer', Config::get('constants.status.PUBLIC'));
                    $query = $query->where('categories.in_frontend', Config::get('constants.status.PUBLIC'));
                    $query = $query->where('parent', 0);
                    $query = $query->orWhere('parent', null);


                    $categories = $query->get();

                    // Top banner
                    $data['top_banner'] = Banner::where('type', Config::get('constants.banner.BANNER_8'))->first();
                    $data['popup_banner'] = Banner::where('type', Config::get('constants.banner.BANNER_9'))->first();


                    $footerLinks = FooterLink::with(['page' => function ($query) use ($lang) {

                        $query->leftJoin('page_langs as pl', function ($join) use ($lang) {
                            $join->on('pl.page_id', '=', 'pages.id');
                            $join->where('pl.lang', $lang);
                        })
                            ->select('pages.*', 'pl.title');
                    }])
                        ->orderBy('created_at', 'DESC')
                        ->get();

                } else {

                    // HEADER LINKS
                    $hl = HeaderLink::orderBy('created_at', 'ASC')
                        ->get();


                    $headerLinks['left'] = [];
                    $headerLinks['right'] = [];

                    foreach ($hl as $i) {
                        if ((int)$i->type == Config::get('constants.headerLinkType.LEFT')) {
                            array_push($headerLinks['left'], $i);
                        } else {
                            array_push($headerLinks['right'], $i);
                        }
                    }
                    $data['header_links'] = $headerLinks;


                    // SETTING
                    $siteSetting = SiteSetting::first();

                    // FOOTER CATEGORIES
                    $categories = Category::with('in_footer_child')
                        ->orderBy('created_at', 'desc')
                        ->where('in_footer', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend', 1)
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->select('id', 'title', 'slug')
                        ->where('parent', 0)
                        ->orWhere('parent', null)
                        ->get();

                    // Top banner
                    $data['top_banner'] = Banner::where('type', Config::get('constants.banner.BANNER_8'))->first();
                    $data['popup_banner'] = Banner::where('type', Config::get('constants.banner.BANNER_9'))->first();


                    $footerLinks = FooterLink::with('page')
                        ->orderBy('created_at', 'DESC')
                        ->get();
                }


                $data['custom_scripts'] = CustomScript::where('status', Config::get('constants.status.PUBLIC'))
                    ->get();


                if ($siteSetting) {
                    $data['site_setting'] = $siteSetting;
                }

                $data['site_setting']['api_base'] = url('/');


                // SETTING
                $setting = Setting::select('currency', 'currency_icon', 'currency_position', 'decimal_format',
                    'phone', 'whatsapp', 'email', 'address_1', 'address_2', 'city', 'state', 'zip', 'country',
                    'google_login', 'facebook_login', 'guest_checkout', 'enable_ga', 'ga_id',
                    'enable_pixel', 'pixel_id',
                    'default_state', 'default_country',
                    'vendor_registration', 'cookie_banner')
                    ->first();
                if ($setting) {
                    $data['setting'] = $setting;
                }


                $data['categories'] = $categories;

                // Nav categories. `categories` above is the footer set, and the
                // home payload is cached in the visitor's localStorage, so the
                // header and listing sidebar read these instead - they come
                // back fresh on every request.
                $data['nav_categories'] = Category::whereNull('parent')
                    ->select('id', 'title', 'slug', 'image', 'meta_description')
                    ->withCount(['products as products_count' => function ($q) {
                        $q->where('status', Config::get('constants.status.PUBLIC'));
                    }])
                    ->with(['child' => function ($q) {
                        $q->select('id', 'parent', 'title', 'slug', 'image')
                            ->where('status', Config::get('constants.status.PUBLIC'))
                            ->where('in_frontend', 1)
                            ->withCount(['products as products_count' => function ($qr) {
                                $qr->where('status', Config::get('constants.status.PUBLIC'));
                            }]);
                    }])
                    ->get();


                // FOOTER PAGES
                $data['services'] = [];
                $data['about'] = [];
                foreach ($footerLinks as $item) {
                    if ($item->page->title) {
                        $page['id'] = $item->id;
                        $page['title'] = $item->page->title;
                        $page['slug'] = $item->page->slug;

                        if ((int)$item->type === Config::get('constants.footerLinkType.SERVICE')) {
                            array_push($data['services'], $page);
                        } else {
                            array_push($data['about'], $page);
                        }
                    }
                }

                $footerImageLinks = FooterImageLink::orderBy('created_at', 'desc')
                    ->where('status', Config::get('constants.status.PUBLIC'))
                    ->get();

                $data['payment'] = [];
                $data['social'] = [];
                foreach ($footerImageLinks as $item) {
                    if ((int)$item->type === Config::get('constants.footerImageLinkType.PAYMENT')) {
                        array_push($data['payment'], $item);
                    } else {
                        array_push($data['social'], $item);
                    }
                }

                /* return response()->json(new Response(null, $data));*/
                return $data;
            });

            return response()->json(new Response(null, $commonData));


        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }


    public function home(Request $request)
    {
        
        try {

            $lang = $request->header('language');

            $cacheKey = "home" . $lang;

            if (!$lang) {
               $cacheKey = "home";
            }


            $homeData = Utils::cacheRemember($cacheKey . $lang, function () use ($request, $lang) {

                if ($lang) {

                    // SLIDER
                    $sliders = HomeSlider::where('status', Config::get('constants.status.PUBLIC'))
                        ->leftJoin('home_slider_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.home_slider_id', '=', 'home_sliders.id');
                            $join->where('cl.lang', $lang);
                        })
                        ->select('home_sliders.*', 'cl.title')
                        ->get();


                    // SITE FEATURES
                    $siteFeature = SiteFeature::where('status', Config::get('constants.status.PUBLIC'))
                        ->leftJoin('site_feature_langs as sf', function ($join) use ($lang) {
                            $join->on('sf.site_feature_id', '=', 'site_features.id');
                            $join->where('sf.lang', $lang);
                        })
                        ->select('site_features.*', 'sf.detail')
                        ->get();

                    // Banners

                    $data['banners'] = Banner::where('status', Config::get('constants.status.PUBLIC'))
                        ->leftJoin('banner_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.banner_id', '=', 'banners.id');
                            $join->where('cl.lang', $lang);
                        })
                        ->select('banners.*', 'cl.title')
                        ->get();

                    // FEATURED CATEGORIES
                    $featured_categories = Category::where('featured', Config::get('constants.status.PUBLIC'))
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->whereNull('parent')
                        ->where('in_frontend',1)
                        ->offset(0)
                        ->leftJoin('category_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.category_id', '=', 'categories.id');
                            $join->where('cl.lang', $lang);
                        })
                        ->select('categories.*', 'cl.title')
                        ->limit(Config::get('constants.homePagePagination.FEATURED_CATEGORIES'))
                        ->get();


                    // FLASH SALES
                    $flashSales = FlashSale::with(['public_products' => function ($query) use ($lang) {
                        $query->leftJoin('product_langs as avl',
                            function ($join) use ($lang) {
                                $join->on('p.id', '=', 'avl.product_id');
                                $join->where('avl.lang', $lang);
                            })
                            ->select('flash_sale_products.*', 'p.id', 'p.slug', 'p.selling', 'p.offered',
                                'p.image', 'avl.title', 'avl.badge');
                    }])
                        ->leftJoin('flash_sale_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                            $join->where('cl.lang', $lang);
                        })
                        ->select('flash_sales.*', 'cl.title')
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('end_time', '>=', date('Y-m-d H:i:s'))
                        ->get();


                    // PRODUCT COLLECTION
                    $collectionsQuery = ProductCollection::query()->where("in_frontend",1)->orderBy('sort_order', 'asc');
                    $totalRecordCount = $collectionsQuery->count();
                    $totalCollectionProduct = ($totalRecordCount + 1) * Config::get('constants.homePagePagination.COLLECTION');

                    $collectionsQuery = $collectionsQuery->leftJoin('product_collection_langs as pcl',
                        function ($join) use ($lang) {
                            $join->on('pcl.product_collection_id', '=', 'product_collections.id');
                            $join->where('in_frontend',1 );
                            $join->where('pcl.lang', $lang);
                        });
                    $collectionsQuery = $collectionsQuery->select('product_collections.*', 'pcl.title');

                     $data['collections'] = $collectionsQuery->with([
                        'product_collections' => function ($query) use ($totalCollectionProduct, $lang) {
                            $query->take($totalCollectionProduct);
                            $query->orderBy('updated_at', 'DESC');
                            $query->with(['product.product_inventories' => function ($q) {
                                $q->withCount('inventory_attributes');
                            }]);
                            $query->leftJoin('product_langs as avl',
                                function ($join) use ($lang) {
                                    $join->on('products.id', '=', 'avl.product_id');
                                    $join->where('avl.lang', $lang);
                                })
                                ->select('collection_with_products.*', 'products.id',
                                    'products.slug', 'avl.badge',
                                    'products.selling', 'products.offered',
                                    'products.image',
                                    'products.shipping_rule_id',
                                    'products.rating',
                                    'products.review_count',
                                    'flash_sale_products.price',
                                    'flash_sale_products.discount_type as flash_discount_type',
                                    'flash_sale_products.discount_value as flash_discount_value',
                                    'flash_sales.end_time', 'avl.title');

                        }
                    ])
                       ->where('in_frontend',1)
                       ->orderBy('sort_order', 'asc')
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->get(); 


                    $data['collections']->each(function ($collection) {
                        $collection->setRelation('product_collections',
                            $collection->product_collections->take(Config::get('constants.homePagePagination.COLLECTION')));
                    });


                    // FEATURED BRANDS
                    $featured_brands = Brand::leftJoin('brand_langs as bl', function ($join) use ($lang) {
                        $join->on('bl.brand_id', '=', 'brands.id');
                        $join->where('bl.lang', $lang);
                    })
                        ->select('brands.*', 'bl.title')
                        ->where('featured', Config::get('constants.status.PUBLIC'))
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->offset(0)
                        ->limit(Config::get('constants.homePagePagination.FEATURED_BRANDS'))
                        ->get();

                } else {

                    // SLIDER
                    $sliders = HomeSlider::where('status', Config::get('constants.status.PUBLIC'))
                        ->select('home_sliders.*')
                        ->get();

                    // SITE FEATURE
                    $siteFeature = SiteFeature::where('status', Config::get('constants.status.PUBLIC'))
                        ->get();

                    // Banners
                    $data['banners'] = Banner::where('status', Config::get('constants.status.PUBLIC'))
                        ->select('banners.*')
                        ->get();

                    // FEATURED CATEGORIES
                    $featured_categories = Category::where('featured', Config::get('constants.status.PUBLIC'))
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->whereNull('parent')
                        ->offset(0)
                        ->limit(Config::get('constants.homePagePagination.FEATURED_CATEGORIES'))
                        ->get();


                    // FLASH SALES
                    $flashSales = FlashSale::with('public_products')
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('end_time', '>=', date('Y-m-d H:i:s'))
                        ->get();


                    // PRODUCT COLLECTION
                    $collections = ProductCollection::query()->where('in_frontend',1)->orderBy('sort_order', 'asc');
                    $totalRecordCount = $collections->count();
                    $totalCollectionProduct = ($totalRecordCount + 1) * Config::get('constants.homePagePagination.COLLECTION');
                    $data['collections'] = $collections->with(['product_collections' =>
                        function ($query) use ($totalCollectionProduct) {
                            $query->take($totalCollectionProduct);
                            $query->orderBy('updated_at', 'DESC');
                            $query->with([
                                'product' => function ($q) {

                                    $q->select(
                                        'products.id',
                                        'products.title',
                                        'products.slug',
                                        'products.image',
                                        'products.selling',
                                        'products.offered',
                                        'products.rating',
                                        'products.review_count'
                                    );
                                },

                                'product.product_inventories' => function ($q) {
                                    $q->withCount('inventory_attributes');
                                }
                            ]);
                        }
                    ])
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend',1)
                        ->orderBy('sort_order', 'asc')
                        ->get();

                    $data['collections']->each(function ($collection) {
                        $collection->setRelation('product_collections',
                            $collection->product_collections->take(Config::get('constants.homePagePagination.COLLECTION')));
                    }); 

                    // FEATURED BRANDS
                    $featured_brands = Brand::where('featured', Config::get('constants.status.PUBLIC'))
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->select('brands.*')
                        ->offset(0)
                        ->limit(Config::get('constants.homePagePagination.FEATURED_BRANDS'))
                        ->get();
                }

                $sliderImages['main'] = [];
                foreach ($sliders as $item) {
                    if ((int)$item->type === Config::get('constants.homeSlider.MAIN')) {
                        array_push($sliderImages['main'], $item);
                    } else if ((int)$item->type === Config::get('constants.homeSlider.RIGHT_TOP')) {
                        $sliderImages['right_top'] = $item;
                    } else if ((int)$item->type === Config::get('constants.homeSlider.RIGHT_BOTTOM')) {
                        $sliderImages['right_bottom'] = $item;
                    }
                }

                $data['site_features'] = $siteFeature;
                $data['slider'] = $sliderImages;


                $data['featured_categories'] = $featured_categories;

                // The nav needs each top level category's sub categories and
                // product counts so the header mega menu and the listing
                // sidebar tree can render them without a second request.
                $data['categories'] = Category::whereNull('parent')
                ->select('id', 'title', 'slug', 'image', 'meta_description')
                ->withCount(['products as products_count' => function ($q) {
                    $q->where('status', Config::get('constants.status.PUBLIC'));
                }])
                ->with(['child' => function ($q) {
                    $q->select('id', 'parent', 'title', 'slug', 'image')
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend', 1)
                        ->withCount(['products as products_count' => function ($qr) {
                            $qr->where('status', Config::get('constants.status.PUBLIC'));
                        }]);
                }])
                ->get();


                $data['flash_sales'] = [];
                foreach ($flashSales as $item) {
                    if (count($item->public_products) > 0) {
                        array_push($data['flash_sales'], $item);
                    }
                }

                $data['time_zone'] = Carbon::now()->timezoneName;


                $data['featured_brands'] = $featured_brands;

                /*return response()->json(new Response(null, $data));*/
                return $data;
            });

            /*
             * Outside the cache closure, like the clearance pass: the headline
             * price depends on which variants are currently buyable, so it is
             * recomputed per request rather than frozen into the cached payload.
             */
            $this->decorateHomeProducts($homeData);

            return response()->json(new Response(null, $homeData));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }


    public function reviews(Request $request, $id)
    {
        try {
            if ($request->get_total && $request->get_total === 'true') {
                $data['total'] = RatingReview::select(DB::raw('count(id) as total'), DB::raw('rating'))
                    ->groupBy(DB::raw('rating'))
                    ->where('product_id', $id)
                    ->get();


                $data['banner'] = Banner::where('type', Config::get('constants.banner.BANNER_7'))
                    ->where('status', Config::get('constants.status.PUBLIC'))
                    ->first();
            }

            $data['all'] = RatingReview::with('user')
                ->with('guest_user')
                ->with('review_images')
                ->where('product_id', $id)
                ->orderBy($request->order_by, $request->type)
                ->paginate(Config::get('constants.pagination.FRONTEND_PRODUCT_RATING'));


            if ($request->time_zone) {
                foreach ($data['all'] as $item) {
                    $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                }

            } else {
                foreach ($data['all'] as $item) {
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    private function storeRecentlyViewed($productId)
    {
        $user = Auth::user();

        if (!$user) return;

        RecentlyViewedProduct::updateOrCreate(
            [
                'user_id' => $user->id,
                'product_id' => $productId
            ],
            [
                'updated_at' => now()
            ]
        );
    }

    /**
     * The caller's wishlist row id for a product, or null.
     *
     * Ownership follows the same rule as the cart: a signed-in customer owns
     * rows by user_id, a guest by the token the server issued it.
     */
    private function wishlistedIdFor(Request $request, $productId)
    {
        $query = UserWishlist::where('product_id', $productId);

        $userId = $request->user('user')->id ?? $request->user_id;

        if ($userId) {

            $query->where('user_id', $userId);

        } else if ($request->user_token) {

            $query->whereNull('user_id')->where('user_token', $request->user_token);

        } else {

            return null;
        }

        return $query->value('id');
    }

    /**
     * The stock of each variant of a product, as it stands now.
     *
     * Stock moves while a product page is open - adding to a basket takes
     * units off at once (CartHold), and so does every other shopper's basket
     * - so the page re-reads this rather than the whole product. The same
     * figures the product endpoint already publishes; nothing more.
     */
    public function productStock(Request $request, $id)
    {
        $published = Product::where('id', $id)
            ->where('status', \Illuminate\Support\Facades\Config::get('constants.status.PUBLIC'))
            ->exists();

        if (!$published) {
            return response()->json(Validation::nothingFoundLang($request->header('language')));
        }

        $rows = \App\Models\UpdatedInventory::where('product_id', $id)
            ->get(['id', 'quantity', 'is_active']);

        return response()->json(new Response($request->token, $rows));
    }


    public function product(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            $cacheKey = 'detail.' . $id . $lang;
            if (!$lang) {
                $cacheKey = 'detail.' . $id;
            }

            $productData = Utils::cacheRemember($cacheKey, function () use ($request, $id, $lang) {

                if ($lang) {

                    $query = Product::query();

                    $query = $query->with(['feature_images']);

                    $query = $query->with(['brand' => function ($query) use ($lang) {
                        $query->leftJoin('brand_langs as bl',
                            function ($join) use ($lang) {
                                $join->on('brands.id', '=', 'bl.brand_id');
                                $join->where('bl.lang', $lang);
                            })
                            ->select('brands.*', 'bl.title');
                    }])
                        ->with(['store' => function ($query) use ($lang) {

                            $query->leftJoin('store_langs as sl', function ($join) use ($lang) {
                                $join->on('sl.store_id', '=', 'stores.id');
                                $join->where('sl.lang', $lang);
                            })
                                ->select('stores.*', 'sl.name');

                        }])
                        ->with(['bundle_deal' => function ($query) use ($lang) {

                            $query->leftJoin('bundle_deal_langs as pcl', function ($join) use ($lang) {
                                $join->on('pcl.bundle_deal_id', '=', 'bundle_deals.id');
                                $join->where('pcl.lang', $lang);
                            })
                                ->select('bundle_deals.id', 'bundle_deals.buy', 'bundle_deals.free', 'pcl.title');

                        }])
                        ->with('product_image_names.attributes')
                        ->with('shipping_rule.shipping_places')
                        ->with(['shipping_rule' => function ($query) use ($lang) {


                            $query->leftJoin('shipping_rule_langs as srl', function ($join) use ($lang) {
                                $join->on('srl.shipping_rule_id', '=', 'shipping_rules.id');
                                $join->where('srl.lang', $lang);
                            })
                                ->select('shipping_rules.id', 'srl.title');

                        }]);


                    $query = $query->leftJoin('flash_sales', function ($join) {

                        $join->on('products.id', '=', 'flash_sale_products.product_id');

                        $join->leftJoin('flash_sale_products', function ($join) {
                            $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                        });
                        FlashSaleWindow::liveProduct($join);
                    });
					
					$query = $query->leftJoin('upsell_products', function ($join) {
						$join->on('products.id', '=', 'upsell_products.product_id');
					});
					

                    /*
                     * A guest's wishlist hangs off the server-issued token
                     * rather than a user id, so join on whichever the caller
                     * actually has. Without this the heart on the product page
                     * always came back empty for a signed-out shopper.
                     */
                    $query = $query->leftJoin('user_wishlists', function ($join) use ($request) {
                        $join->on('products.id', '=', 'user_wishlists.product_id');

                        if ($request->user_id) {
                            $join->where('user_wishlists.user_id', $request->user_id);
                        } else if ($request->user_token) {
                            $join->whereNull('user_wishlists.user_id')
                                ->where('user_wishlists.user_token', $request->user_token);
                        } else {
                            // Nobody to match: keep the left join empty rather
                            // than letting every row match.
                            $join->whereRaw('1 = 0');
                        }
                    });

                    $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                        $join->on('pl.product_id', '=', 'products.id');
                        $join->where('pl.lang', $lang);
                    });

                    $query = $query->select('products.*', 'pl.title',
                        'pl.description', 'pl.overview', 'pl.unit', 'pl.badge', 'pl.meta_title',
                        'pl.meta_description', 'pl.meta_keywords',  'flash_sales.title as flash_sale_title',
                        'flash_sale_products.price',
                        'flash_sale_products.discount_type as flash_discount_type',
                        'flash_sale_products.discount_value as flash_discount_value', 'flash_sales.end_time',
						'upsell_products.price',
                        'user_wishlists.id as wishlisted');


                    $query = $query->where('products.status', Config::get('constants.status.PUBLIC'));

                    $data = $query->find($id);

                    if (!$data) {
                        // Return null, not a JsonResponse: this runs inside the
                        // cache closure, so a response object here gets cached and
                        // then wrapped in a second envelope, producing a 200 whose
                        // body is a nested headers/original/exception blob with an
                        // empty message. The caller turns null into a real 404.
                        return null;
                    }


                    $productInventoryAttr = Attribute::whereHas('values', function ($q) use ($id) {
                        $q->join('inventory_attributes as ia', function ($join) {
                            $join->on('ia.attribute_value_id', '=', 'attribute_values.id');
                        })
                            ->join('updated_inventories as i', function ($join) use ($id) {
                                $join->on('i.id', '=', 'ia.inventory_id');
                                $join->where('i.product_id', $id);
                            });

                    })
                        ->leftJoin('attribute_langs as al', function ($join) use ($lang) {
                            $join->on('al.attribute_id', '=', 'attributes.id');
                            $join->where('al.lang', $lang);
                        })
                        ->select('attributes.*', 'al.title')
                        ->with(['values' => function ($q) use ($id, $lang) {
                            $q->join('inventory_attributes as ia', function ($join) {
                                $join->on('ia.attribute_value_id', '=', 'attribute_values.id');
                            })
                                ->join('updated_inventories as i', function ($join) use ($id) {
                                    $join->on('i.id', '=', 'ia.inventory_id');
                                    $join->where('i.product_id', $id);
                                })
                                ->groupBy('attribute_values.id')
                                ->leftJoin('attribute_value_langs as avl',
                                    function ($join) use ($lang) {
                                        $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                                        $join->where('avl.lang', $lang);
                                    })
                                ->select('attribute_values.*', 'i.*', 'ia.*', 'avl.title');
                        }])
                        ->get();


                    $data['inventory'] = UpdatedInventory::with('inventory_attributes')
                        ->where('product_id', $id)->get();

                    $currentTime = Carbon::now()->format('Y-m-d H:i:s');

                    $data['vouchers'] = Voucher::where('end_time', '>=', $currentTime)
                        ->where('start_time', '<=', $currentTime)
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('apply_type', 2)
                        ->whereHas('products', function ($q) use ($id) {
                            $q->where('products.id', $id);
                        })
                        ->select('id','title', 'price', 'type', 'code', 'min_spend', 'usage_limit', 'limit_per_customer', 'apply_type')
                        ->get();


                    $pcQuery = ProductCategory::query();

                    $primaryCategory = $pcQuery
                        ->with(['category.parent_data' => function ($q) use ($lang) {

                            $q->leftJoin('category_langs as cl', function ($join) use ($lang) {
                                $join->on('cl.category_id', '=', 'categories.id');
                                $join->where('cl.lang', $lang);
                            })
                                ->select('categories.id', 'categories.slug', 'categories.parent', 'cl.title');
                        }])
                        ->with(['category' => function ($pcQuery) use ($lang) {

                            $pcQuery->leftJoin('category_langs as cl', function ($join) use ($lang) {
                                $join->on('cl.category_id', '=', 'categories.id');
                                $join->where('cl.lang', $lang);
                            })
                                ->select('categories.id', 'categories.slug', 'categories.parent', 'cl.title');

                        }])
                        ->where('product_id', $id)
                        ->where('primary', Config::get('constants.status.PUBLIC'))
                        ->first();

                    $result = [];



                    if($primaryCategory) {
                        $treeData = $primaryCategory->category;

                        while ($treeData) {
                            array_push($result, [
                                'id' => $treeData->id,
                                'title' => $treeData->title,
                                'slug' => $treeData->slug,
                            ]);

                            $treeData = $treeData->parent_data;
                        }
                    }

                    $data['category_data'] = $result;


                    $currentCategories = Category::query();

                    if (count($result) > 1) {
                        $currentCategories = $currentCategories->where('parent', $result[count($result) - 1]);
                    }

                    $currentCategories = $currentCategories->leftJoin('category_langs as cl',
                        function ($join) use ($lang) {
                            $join->on('cl.category_id', '=', 'categories.id');
                            $join->where('cl.lang', $lang);
                        })
                        ->select('categories.id', 'categories.slug', 'cl.title');

                    $data['current_categories'] = $currentCategories->offset(0)
                        ->limit(10)
                        ->where('in_frontend', 1)
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->get();


                } else {

                    // $productData = Utils::cacheRemember('detail.' . $id, function () use ($request, $id) {
                    $query = Product::query();
                    $query = $query->with(['feature_images']);

                    $query = $query->with(['brand' => function ($query) {
                        $query->select('brands.id', 'brands.title', 'brands.image');
                    }])
                        ->with('store')
                        ->with('bundle_deal')
                        ->with('product_image_names.attributes')
                        ->with('shipping_rule.shipping_places');

                    $query = $query->leftJoin('flash_sales', function ($join) {

                        $join->on('products.id', '=', 'flash_sale_products.product_id');

                        $join->leftJoin('flash_sale_products', function ($join) {
                            $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                        });
                        FlashSaleWindow::liveProduct($join);
                    });
					$query = $query->leftJoin('upsell_products', function ($join) {
						$join->on('products.id', '=', 'upsell_products.product_id');
					});
					

                    /*
                     * A guest's wishlist hangs off the server-issued token
                     * rather than a user id, so join on whichever the caller
                     * actually has. Without this the heart on the product page
                     * always came back empty for a signed-out shopper.
                     */
                    $query = $query->leftJoin('user_wishlists', function ($join) use ($request) {
                        $join->on('products.id', '=', 'user_wishlists.product_id');

                        if ($request->user_id) {
                            $join->where('user_wishlists.user_id', $request->user_id);
                        } else if ($request->user_token) {
                            $join->whereNull('user_wishlists.user_id')
                                ->where('user_wishlists.user_token', $request->user_token);
                        } else {
                            // Nobody to match: keep the left join empty rather
                            // than letting every row match.
                            $join->whereRaw('1 = 0');
                        }
                    });

                    $query = $query->select('products.*',   'flash_sales.title as flash_sale_title','flash_sale_products.price',
                    'flash_sale_products.discount_type as flash_discount_type',
                    'flash_sale_products.discount_value as flash_discount_value',
					'flash_sales.end_time', 'upsell_products.price as upsell_price',
                        'user_wishlists.id as wishlisted');


                    $query = $query->where('products.status', Config::get('constants.status.PUBLIC'));

                    $data = $query->find($id);

                    /*
                     * Bail out before anything writes to $data.
                     *
                     * The existing "if (!$data)" guard sat below the
                     * $data['category_data'] assignment, and assigning an array
                     * offset on null turns null into an array — so by the time
                     * the guard ran, $data was a non-empty array and passed it.
                     * The request then died further down on $data->image with
                     * "Attempt to read property on array", which surfaced to the
                     * customer as a 200 carrying an internal PHP message
                     * instead of a clean not-found.
                     */
                    if (!$data) {
                        // Return null, not a JsonResponse: this runs inside the
                        // cache closure, so a response object here gets cached and
                        // then wrapped in a second envelope, producing a 200 whose
                        // body is a nested headers/original/exception blob with an
                        // empty message. The caller turns null into a real 404.
                        return null;
                    }

                    $primaryCategory = ProductCategory::with(['category.parent_data'])
                        ->where('product_id', $id)
                        ->where('primary', Config::get('constants.status.PUBLIC'))
                        ->first();


                    $result = [];


                    if($primaryCategory) {
                        $treeData = $primaryCategory->category;

                        while ($treeData) {
                            array_push($result, [
                                'id' => $treeData->id,
                                'title' => $treeData->title,
                                'slug' => $treeData->slug,
                            ]);

                            $treeData = $treeData->parent_data;
                        }
                    }

                    $data['category_data'] = $result;

                    $currentCategories = Category::query();

                    if (count($result) > 1) {
                        $currentCategories = $currentCategories->where('parent', $result[count($result) - 1]);
                    }

                    $data['current_categories'] = $currentCategories->offset(0)
                        ->limit(10)
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend', 1)
                        ->get();

                    if (!$data) {
                        // Return null, not a JsonResponse: this runs inside the
                        // cache closure, so a response object here gets cached and
                        // then wrapped in a second envelope, producing a 200 whose
                        // body is a nested headers/original/exception blob with an
                        // empty message. The caller turns null into a real 404.
                        return null;
                    }


                    $productInventoryAttr = Attribute::whereHas('values', function ($q) use ($id) {
                        $q->join('inventory_attributes as ia', function ($join) {
                            $join->on('ia.attribute_value_id', '=', 'attribute_values.id');
                        })
                            ->join('updated_inventories as i', function ($join) use ($id) {
                                $join->on('i.id', '=', 'ia.inventory_id');
                                $join->where('i.product_id', $id);
                            });

                    })
                        ->with(['values' => function ($q) use ($id) {
                            $q->join('inventory_attributes as ia', function ($join) {
                                $join->on('ia.attribute_value_id', '=', 'attribute_values.id');
                            })
                                ->join('updated_inventories as i', function ($join) use ($id) {
                                    $join->on('i.id', '=', 'ia.inventory_id');
                                    $join->where('i.product_id', $id);
                                })
                                ->groupBy('attribute_values.id');
                        }])
                        ->get();


                    $data['inventory'] = UpdatedInventory::with('inventory_attributes')
                        ->where('product_id', $id)->get();

                    $currentTime = Carbon::now()->format('Y-m-d H:i:s');

                    $data['vouchers'] = Voucher::where('end_time', '>=', $currentTime)
                        ->where('start_time', '<=', $currentTime)
                        ->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('apply_type', 2)
                        ->whereHas('products', function ($q) use ($id) {
                            $q->where('products.id', $id);
                        })
                        ->select('id','title', 'price', 'type', 'code', 'min_spend', 'usage_limit', 'limit_per_customer', 'apply_type')
                        ->get();
                }


                if (!$data->image) {
                    $data->image = Config::get('constants.media.DEFAULT_IMAGE');
                }

                if (count($data->product_image_names) > 0) {
                    $data['images'] = $data->product_image_names;
                }

                $data['time_zone'] = Carbon::now()->timezoneName;

                $data['in_stock'] = false;


                $data['attribute'] = $productInventoryAttr;

                if (count($data['inventory']) > 0) {
                    foreach ($data['inventory'] as $i) {
                        if ($i['quantity'] > 0) {
                            $data['in_stock'] = true;
                            break;
                        }
                    }
                }

                $data['customizations'] = ProductTemplateCustomization::with('template')
                ->where('product_id', $id)
                ->get();

                $specifications = ProductSpecification::with('group')
                    ->where('product_id', $id)
                    ->orderBy('sort_order')
                    ->get();

                $data['specifications'] = $specifications
                    ->groupBy('group.title')
                    ->map(function ($items, $group) {

                        return [
                            'group' => $group,
                            'items' => $items->map(function ($item) {
                                return [
                                    'id' => $item->id,
                                    'label' => $item->label,
                                    'value' => $item->value,
                                    'sort_order' => $item->sort_order,
                                ];
                            })->values()
                        ];
                    })->values();

                //return response()->json(new Response(null, $data));
                return $data;

            });

            if (!$productData) {
                // Never cache a miss, or a product published a moment later
                // stays "not found" for the whole cache window.
                Utils::cacheForget($cacheKey);

                return response()->json(Validation::frontendError());
            }

            /*
             * Count the view, not the cache miss.
             *
             * This sat inside the cacheRemember() closure above, so the counter
             * only moved when the payload was rebuilt: every visitor served
             * from the day-long cache was invisible. The Products grid shows
             * this number and its "high return" filter is views > 100, both
             * reading an arbitrary fraction of the traffic.
             */
            ProductView::updateOrCreate(
                ['product_id' => $id],
                ['views' => \DB::raw('views + 1')]
            );

            $this->storeRecentlyViewed($id);

            /*
             * The detail payload is cached for a day and shared by every
             * visitor, so the `wishlisted` alias baked into it belongs to
             * whoever happened to warm the cache. Recompute it per request —
             * otherwise a guest sees someone else's heart, and their own never
             * lights up.
             */
            $productData['wishlisted'] = $this->wishlistedIdFor($request, $id);

            // Apply any active clearance deal on top (fresh, not cached) so the
            // detail page shows the discounted price like a flash sale.
            $productData = $this->applyClearanceToDetail($productData, $id);

            // Same headline price the listing tile shows, so the two agree
            // before the customer picks a variation.
            $this->decorateDisplayPrices([$productData]);

            $productData = $this->hideCommercialFields($productData);

            return response()->json(new Response(null, $productData));


        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    public function contactUs(Request $request)
    {
        try {
            ContactUs::create($request->all());

            return response()->json(new Response('', true));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    /**
     * Public order tracking: anyone holding an order number can call this.
     *
     * It used to return the whole Order model, so the order number alone was
     * enough to read the customer's name, email, phone, user id, VAT number,
     * payment token and gateway transaction id. It now builds a public view
     * of the order: progress, dates, the items, how it is being fulfilled and
     * a town-level destination. Money and the full record are added only for
     * the signed-in owner, who also gets a link to the full order page.
     */
    public function trackOrder(Request $request)
    {
        try {
            $lang = $request->header('language');
            $trackingId = trim((string)$request->tracking_id);

            $order = $trackingId === '' ? null : Order::with([
                'ordered_products.shipping_place',
                'ordered_products.product',
                'ordered_products.updated_inventory.inventory_attributes.attribute_value.attribute',
                'address',
                'cancellation',
            ])->where('order', $trackingId)->first();

            if (is_null($order)) {
                return response()->json(Validation::nothing_found(201, null, 'form', $lang));
            }

            $status = (int)$order->status;
            $cancelled = (int)$order->cancelled === 1 || $status === 6;

            $state = 'active';
            if ($cancelled) {
                $state = 'cancelled';
            } elseif ($status === 7) {
                $state = 'returned';
            } elseif ($status === 8) {
                $state = 'refunded';
            } elseif ($status === 5) {
                $state = 'delivered';
            }

            $labels = [1 => 'Order placed', 2 => 'Confirmed', 3 => 'Picked up', 4 => 'On the way',
                5 => 'Delivered', 6 => 'Cancelled', 7 => 'Returned', 8 => 'Refunded'];

            /*
             * When each status was reached, from the audit table. It only
             * exists for recent changes, so a step with no row has no date
             * rather than a guessed one. The first time a status was reached
             * is the one shown.
             */
            $reached = [1 => Carbon::parse($order->created_at)];
            $history = DB::table('order_status_histories')
                ->where('order_id', $order->id)
                ->orderBy('created_at')
                ->get(['to_status', 'created_at']);

            foreach ($history as $h) {
                $to = (int)$h->to_status;
                if (!isset($reached[$to])) {
                    $reached[$to] = Carbon::parse($h->created_at);
                }
            }

            $lines = $order->ordered_products;
            $collection = (int)optional($lines->first())->shipping_type === 2;

            $items = $lines->map(function ($line) {
                $variant = collect(optional($line->updated_inventory)->inventory_attributes ?: [])
                    ->map(function ($ia) {
                        $value = $ia->attribute_value;
                        if (!$value || !$value->title) {
                            return null;
                        }
                        $name = optional($value->attribute)->title;
                        return $name ? $name . ': ' . $value->title : $value->title;
                    })
                    ->filter()
                    ->implode(', ');

                return [
                    'id'       => (int)$line->product_id,
                    'title'    => optional($line->product)->title,
                    'slug'     => optional($line->product)->slug,
                    'image'    => optional($line->product)->image,
                    'quantity' => (int)$line->quantity,
                    'variant'  => $variant ?: null,
                ];
            })->values();

            /*
             * Placed date plus the slowest line's day_needed. The old page
             * quietly moved a missed estimate to "tomorrow", so a late order
             * was never shown as late; it now says so instead.
             */
            $estimate = null;
            if ($state === 'active') {
                $days = (int)$lines->max(function ($l) {
                    return optional($l->shipping_place)->day_needed ?? 0;
                });
                $due = Carbon::parse($order->created_at)->addDays(max($days, 1))->startOfDay();
                $estimate = [
                    'date' => $due->toDateString(),
                    'late' => $due->lt(Carbon::now()->startOfDay()),
                ];
            }

            $destination = null;
            $collectionPoint = null;

            if ($collection) {
                // The shop's own address and phone, which are public already.
                $place = optional($lines->first())->shipping_place;
                if ($place) {
                    $collectionPoint = [
                        'address' => collect([$place->pickup_address_line_1, $place->pickup_address_line_2,
                            $place->pickup_city, $place->pickup_state, $place->pickup_zip, $place->pickup_country])
                            ->filter()->implode(', '),
                        'phone'   => $place->pickup_phone,
                    ];
                }
            } elseif ($order->address) {
                // Town and county only: enough to recognise, not enough to find the door.
                $destination = collect([$order->address->city, $order->address->state, $order->address->country])
                    ->filter()->implode(', ');
            }

            $methods = [1 => 'Razorpay', 2 => 'Cash on delivery', 3 => 'Stripe', 4 => 'PayPal', 5 => 'Flutterwave',
                6 => 'Iyzico', 7 => 'Bank transfer', 8 => 'Payfast', 9 => 'Card (SumUp)', 10 => 'Wallet'];

            ksort($reached);
            $timeline = [];
            foreach ($reached as $code => $at) {
                $timeline[] = [
                    'status' => (int)$code,
                    'label'  => $labels[$code] ?? null,
                    'at'     => $at->toIso8601String(),
                ];
            }

            $lastUpdate = collect($reached)->max();

            $refund = null;
            if ($order->cancellation && (int)$order->cancellation->refunded === 1) {
                $refund = [
                    'at' => $order->cancellation->refunded_at
                        ? Carbon::parse($order->cancellation->refunded_at)->toIso8601String()
                        : null,
                ];
            }

            $data = [
                'order'            => $order->order,
                'placed_at'        => Carbon::parse($order->created_at)->toIso8601String(),
                'status'           => $status,
                'state'            => $state,
                'fulfilment'       => $collection ? 'collection' : 'delivery',
                'payment_method'   => $methods[(int)$order->order_method] ?? null,
                'paid'             => (int)$order->payment_done === 1,
                'item_count'       => (int)$lines->sum('quantity'),
                'items'            => $items,
                'estimate'         => $estimate,
                'destination'      => $destination,
                'collection_point' => $collectionPoint,
                'last_update'      => $lastUpdate ? $lastUpdate->toIso8601String() : null,
                'timeline'         => $timeline,
                'refund'           => $refund,
                'is_owner'         => false,
            ];

            $user = Auth::user();
            if ($user && (int)$order->user_id === (int)$user->id) {
                $data['is_owner'] = true;
                $data['id'] = $order->id;
                $data['total_amount'] = $order->total_amount;
                $data['currency'] = $order->currency;
            }

            /*
             * The parcel (AfterShip). Anyone with the order number sees the
             * courier and where it has got to; the tracking number, the
             * checkpoints and the courier's link need the signed-in owner or
             * the signed link from the shipping email (`t`). See
             * OrderShipment::publicView().
             */
            $shipment = \App\Models\OrderShipment::where('order_id', $order->id)->first();
            $data['shipment'] = $shipment
                ? $shipment->publicView($data['is_owner']
                    || \App\Models\Helper\ShipmentTracking::verifySignature($order, $request->t))
                : null;

            return response()->json(new Response('', $data));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    public function page(Request $request, $slug)
    {
        try {

            $lang = $request->header('language');

            $cacheKey = 'pages.' . $slug . $lang;
            if (!$lang) {
                $cacheKey = 'pages.' . $slug;
            }

            $page = Utils::cacheRemember($cacheKey, function () use ($slug, $lang) {

                if ($lang) {

                    $query = Page::query();
                    $query = $query->where('slug', $slug);
                    $query = $query->leftJoin('page_langs as cl', function ($join) use ($lang) {
                        $join->on('cl.page_id', '=', 'pages.id');
                        $join->where('cl.lang', $lang);
                    });
                    $query = $query->select('pages.*', 'cl.description', 'cl.title');

                    $page = $query->first();

                    if ($page->description == 'Sitemap') {

                        $query = Category::query();

                        $query = $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                            $join->on('cl.category_id', '=', 'categories.id');
                            $join->where('cl.lang', $lang);
                        });


                        $page['categories'] = $query->where('categories.status', Config::get('constants.status.PUBLIC'))
                            ->select('categories.id', 'cl.title', 'categories.slug', 'categories.updated_at')
                            ->get();

                        $queryP = Product::query();

                        $queryP = $queryP->leftJoin('product_langs as pl', function ($join) use ($lang) {
                            $join->on('pl.product_id', '=', 'products.id');
                            $join->where('pl.lang', $lang);
                        });


                        $page['products'] = $queryP->where('products.status', Config::get('constants.status.PUBLIC'))
                            ->select('products.id', 'pl.title', 'products.slug', 'products.updated_at')
                            ->get();

                        $page['client_base'] = env('CLIENT_BASE_URL');
                    }
                } else {

                    $page = Page::where('slug', $slug)
                        ->select('slug', 'title', 'description', 'meta_title', 'meta_description', 'meta_keywords',
                            'page_from_component')
                        ->first();

                    if ($page->description == 'Sitemap') {


                        $query = Category::query();

                        $page['categories'] = $query->where('status', Config::get('constants.status.PUBLIC'))
                        ->where('in_frontend',1)    
                        ->select('id', 'title', 'slug', 'updated_at')
                            ->get();


                        $queryP = Product::query();
                        $page['products'] = $queryP->where('products.status', Config::get('constants.status.PUBLIC'))
                            ->select('products.id', 'products.title', 'products.slug', 'products.updated_at')
                            ->get();


                        $page['client_base'] = env('CLIENT_BASE_URL');
                    }
                }

                if (!is_null($page)) {

                    //return response()->json(new Response(null, $page));

                    return $page;
                }

                return [];


                //return response()->json(new Response(null, []));

            });

            return response()->json(new Response(null, $page));
        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    /**
     * Price a set of flash_sale_products rows the way every other product
     * listing is priced.
     *
     * Three things these rows do not arrive with, and all three used to be
     * missing from the screen:
     *
     * end_time, because it belongs to the SALE and Pricing reads it off the
     * product record to recognise the flash price as a live override.
     *
     * The stock behind each product, because displayRange derives in_stock
     * from the variants and a row with none looks sold out. The rail and the
     * sale page were the only listings not carrying it.
     *
     * And then ListingPrices, which is the one place the customer's quantity
     * bands and the VAT split are applied. Without it the rail quoted the
     * walk-up price to a business account whose own rate was lower, and
     * carried no vat_rate for the Inc-/Ex-VAT label the rest of the shop
     * shows.
     */
    /**
     * The sale window as an INSTANT, for whoever is reading it.
     *
     * flash_sales.start_time/end_time come out of the database as bare
     * strings -- "2026-09-18 13:07:00" -- with nothing to say which zone
     * they are in, while created_at beside them serialises as
     * "2026-09-18T07:37:56.000000Z". new Date() reads the first as LOCAL
     * time, so a shopper on +05:30 read a 13:07 UTC end as 13:07 their
     * own time: five and a half hours early. The countdown on the home
     * page then made the remaining time negative and clamped it to
     * 00:00:00:00 on a sale that still had two and a half hours to run.
     *
     * Done here rather than with a $casts entry on the model, because
     * FlashSalesController::all() writes a formatted DISPLAY string back
     * onto these same attributes for the admin list, and a cast then
     * tries to parse "01:07 pm, 17 Sep, 26" as a date.
     */
    private function asInstant($value)
    {
        return is_null($value) ? null : Carbon::parse($value)->toJSON();
    }


    private function priceFlashRows($rows, $endTime)
    {
        if (is_null($rows) || count($rows) === 0) {
            return $rows;
        }

        $productIds = collect($rows)->pluck('product_id')->filter()->unique()->values();

        // One query for the whole listing, not one per tile.
        $inventories = UpdatedInventory::whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        foreach ($rows as $row) {
            $row->product_inventories = $inventories->get($row->product_id, collect());

            /*
             * A product that has sold its allowance is still IN the
             * sale -- it is listed, it is buyable -- it just is not at
             * the sale price any more. Dropping end_time is how the row
             * says that: Pricing stops recognising a live override and
             * falls through to the normal ladder, which is exactly what
             * the basket and the product page do for the same row,
             * because the flash join stops matching it there.
             */
            $row->end_time = FlashSaleAllowance::isExhausted($row) ? null : $endTime;
            $row->quantity_remaining = FlashSaleAllowance::remaining($row);
        }

        ListingPrices::decorate($rows);

        return $rows;
    }


    public function flashSale(Request $request, $id = null)
    {
        try {

            $lang = $request->header('language');

            if (!is_null($id)) {
                $flashSaleQuery = FlashSale::query();

                if ($lang) {
                    $flashSaleQuery = $flashSaleQuery->leftJoin('flash_sale_langs as cl',
                        function ($join) use ($lang) {
                            $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                            $join->where('cl.lang', $lang);
                        });
                    $flashSaleQuery = $flashSaleQuery->select('flash_sales.*',
                        DB::raw('COALESCE(cl.title, flash_sales.title) as title'));
                }


                $flashSale = FlashSaleWindow::live(
                    $flashSaleQuery->where('flash_sales.id', $id)
                )->first();

                if (!is_null($flashSale)) {
                    $query = FlashSaleProduct::query();
                    $query = $query->where('flash_sale_id', $id);

                    if ($lang) {

                        $query = $query->join('products as p', function ($join) use ($lang) {
                            $join->on('p.id', '=', 'flash_sale_products.product_id');
                            $join->where('p.status', Config::get('constants.status.PUBLIC'));

                            $join->leftJoin('product_langs as avl',
                                function ($join) use ($lang) {
                                    $join->on('p.id', '=', 'avl.product_id');
                                    $join->where('avl.lang', $lang);
                                });

                        });

                        $query = $query->select('flash_sale_products.*', 'p.id', 'p.title',
                            'p.selling', 'p.offered', 'p.slug',
                            'p.image', 'avl.title', 'avl.badge');


                    } else {
                        $query = $query->join('products as p', function ($join) {
                            $join->on('p.id', '=', 'flash_sale_products.product_id');
                            $join->where('p.status', Config::get('constants.status.PUBLIC'));
                        });

                        $query = $query->select('flash_sale_products.*', 'p.id', 'p.title',
                            'p.selling', 'p.offered', 'p.badge', 'p.slug',
                            'p.image');
                    }


                    $data = $query->paginate(Config::get('constants.frontend.PAGINATION'));

                    $this->priceFlashRows($data->getCollection(),
                        $this->asInstant($flashSale->end_time));

                    $flashSale->start_time = $this->asInstant($flashSale->start_time);
                    $flashSale->end_time = $this->asInstant($flashSale->end_time);

                    return response()->json(new Response(null, $data));
                }

                return response()->json(Validation::frontendError());

            } else {

                $flashSaleQuery = FlashSale::query();
                if ($lang) {
                    $flashSaleQuery = $flashSaleQuery->leftJoin('flash_sale_langs as cl', function ($join) use ($lang) {
                        $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                        $join->where('cl.lang', $lang);
                    });
                    $flashSaleQuery = $flashSaleQuery->select('flash_sales.*',
                        DB::raw('COALESCE(cl.title, flash_sales.title) as title'));

                    $flashSaleQuery = $flashSaleQuery->with(['public_products' =>
                        function ($flashSaleQuery) use ($lang) {

                            $flashSaleQuery->leftJoin('product_langs as avl',
                                function ($join) use ($lang) {
                                    $join->on('flash_sale_products.product_id', '=', 'avl.product_id');
                                    $join->where('avl.lang', $lang);
                                })
                                ->select('flash_sale_products.*', 'p.id',
                                    'p.selling', 'p.offered',
                                    'p.image', 'avl.badge', 'avl.title');

                        }]);

                } else {
                    $flashSaleQuery = $flashSaleQuery->with('public_products');
                }


                $flashSales = FlashSaleWindow::live($flashSaleQuery)->get();

                /*
                 * The rail is a product listing like any other, and was the
                 * only one that never reached ListingPrices: its rows went
                 * out with a raw flash price, no account band, no VAT split
                 * and no stock flag, so a business account was quoted the
                 * walk-up price on the home page and its own rate
                 * everywhere else.
                 */
                foreach ($flashSales as $flashSale) {
                    $this->priceFlashRows($flashSale->public_products, $flashSale->end_time);

                    // The countdown reads these, so they carry a zone.
                    $flashSale->start_time = $this->asInstant($flashSale->start_time);
                    $flashSale->end_time = $this->asInstant($flashSale->end_time);
                }

                return response()->json(new Response(null, $flashSales));
            }

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    public function productSuggestion(Request $request, $id)
    {
        /*
         * The two rows under a product, each built to mean what its heading
         * says (they used to be "other sub-categories" and "same sub-category",
         * unsorted, whatever their stock):
         *
         *   suggestion_2  "People who viewed this item also viewed"
         *                 what signed-in customers who viewed this product
         *                 viewed too, most shared first; then this product's
         *                 own sub-category while there is little history.
         *   suggestion_1  "Recommended for you"
         *                 for a signed-in customer, products from the
         *                 categories they have been browsing; for anyone
         *                 else, this product's wider category. Never a
         *                 product the other row already shows.
         *
         * Within each row, products you can buy (in stock or on backorder)
         * come first, then the most viewed, then the newest. A short row is
         * topped up from the rest of the shop on every page alike, so the page
         * count matches what the pages hold.
         */
        try {
            $lang = $request->header('language');
            $perPage = (int)Config::get('constants.imageSlider.PAGINATION');
            $page = max(1, (int)$request->page);
            $id = (int)$id;

            if (!Product::select('id')->find($id)) {
                return response()->json(new Response(null, ['suggestion_1' => [], 'suggestion_2' => []]));
            }

            $rank = $this->suggestionRank();
            $order = function ($ids) use ($rank) {
                return collect($ids)->unique()->filter(function ($pid) use ($rank) {
                    return isset($rank[$pid]);
                })->sortBy(function ($pid) use ($rank) {
                    return $rank[$pid];
                })->values();
            };

            [$categoryId, $subCategoryId] = $this->suggestionCategories($id);
            $inCategory = function ($categoryIds) {
                return \DB::table('product_categories')->whereIn('category_id', (array)$categoryIds)
                    ->pluck('product_id')->map(function ($pid) { return (int)$pid; })->all();
            };

            // ---- People who viewed this item also viewed
            $coViewed = \DB::table('recently_viewed_products as a')
                ->join('recently_viewed_products as b', function ($join) {
                    $join->on('b.user_id', '=', 'a.user_id')->on('b.product_id', '!=', 'a.product_id');
                })
                ->where('a.product_id', $id)
                ->groupBy('b.product_id')
                ->selectRaw('b.product_id, COUNT(DISTINCT b.user_id) AS viewers')
                ->pluck('viewers', 'b.product_id');

            // most shared first; the stock/popularity order only breaks ties
            $alsoViewed = collect($coViewed->keys())->map(function ($pid) { return (int)$pid; })
                ->filter(function ($pid) use ($rank, $id) { return $pid !== $id && isset($rank[$pid]); })
                ->sort(function ($x, $y) use ($coViewed, $rank) {
                    return [$coViewed[$y], $rank[$x]] <=> [$coViewed[$x], $rank[$y]];
                })->values();

            $sameSub = $subCategoryId > 0 ? $inCategory($subCategoryId) : ($categoryId > 0 ? $inCategory($categoryId) : []);
            $alsoViewed = $alsoViewed->merge($order($sameSub))->unique()->reject(function ($pid) use ($id) {
                return $pid === $id;
            })->values();

            // ---- Recommended for you
            $user = $request->user('user');
            $browsed = collect();

            if ($user) {
                $recent = \DB::table('recently_viewed_products')->where('user_id', $user->id)
                    ->orderByDesc('updated_at')->limit(30)->pluck('product_id');

                // how often each category turns up in what they have been looking at
                $browsed = \DB::table('product_categories')->whereIn('product_id', $recent)
                    ->groupBy('category_id')->selectRaw('category_id, COUNT(*) AS hits')
                    ->orderByDesc('hits')->pluck('hits', 'category_id');
            }

            $taken = $alsoViewed->flip();
            $notTaken = function ($pid) use ($taken, $id) { return $pid !== $id && !isset($taken[$pid]); };

            $recommended = collect();
            if ($browsed->isNotEmpty()) {
                // categories they browse most first; within one, the usual order
                foreach ($browsed->keys() as $cat) {
                    $recommended = $recommended->merge($order($inCategory($cat))->filter($notTaken));
                }
            }
            $wider = $categoryId > 0 ? $inCategory($categoryId) : [];
            $recommended = $recommended->merge($order($wider)->filter($notTaken))->unique()->values();

            // ---- a short row is topped up from the rest of the shop, the same on every page
            $pad = function ($list, $exclude) use ($rank, $perPage, $id) {
                if ($list->count() >= $perPage) {
                    return $list;
                }
                $skip = collect($exclude)->merge($list)->push($id)->flip();
                $extra = collect(array_keys($rank))->reject(function ($pid) use ($skip) {
                    return isset($skip[$pid]);
                })->take($perPage - $list->count());
                return $list->merge($extra)->values();
            };

            $alsoViewed = $pad($alsoViewed, []);
            $recommended = $pad($recommended, $alsoViewed->all());

            $data['suggestion_2'] = $this->suggestionPage($alsoViewed, $page, $perPage, $lang, $request);
            $data['suggestion_1'] = $this->suggestionPage($recommended, $page, $perPage, $lang, $request);

            return response()->json(new Response(null, $data));

        } catch (\Exception $e) {

            if ($e instanceof \PDOException) {
                return response()->json(Validation::error(null, explode('.', $e->getMessage())[0]));
            } else {
                return response()->json(Validation::error(null, $e->getMessage()));
            }
        }
    }

    /**
     * Every product a suggestion row may show, mapped to its place in the
     * default order: buyable first (stock above zero, or backorder), then most
     * viewed, then newest. Published products with a slug only - the tile
     * links by slug.
     */
    private function suggestionRank()
    {
        $stock = \DB::table('updated_inventories')->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) AS qty');
        $views = \DB::table('product_views')->groupBy('product_id')
            ->selectRaw('product_id, SUM(views) AS views');

        return \DB::table('products as p')
            ->leftJoinSub($stock, 's', 's.product_id', '=', 'p.id')
            ->leftJoinSub($views, 'v', 'v.product_id', '=', 'p.id')
            ->where('p.status', Config::get('constants.status.PUBLIC'))
            ->whereNotNull('p.slug')
            ->orderByRaw('CASE WHEN p.back_order = 1 OR COALESCE(s.qty, 0) > 0 THEN 0 ELSE 1 END')
            ->orderByDesc(\DB::raw('COALESCE(v.views, 0)'))
            ->orderByDesc('p.id')
            ->pluck('p.id')
            ->values()
            ->flip()
            ->mapWithKeys(function ($position, $pid) { return [(int)$pid => $position]; })
            ->all();
    }

    /**
     * [category, sub-category] for a product. A product filed under a
     * sub-category belongs to that and its parent; otherwise its primary
     * category and no sub-category. This used to take whichever row sorted
     * first on `primary` ascending, i.e. the NON-primary one first.
     */
    private function suggestionCategories($productId)
    {
        $rows = ProductCategory::where('product_id', $productId)
            ->with('category.parent_data')->orderByDesc('primary')->get()
            ->filter(function ($row) { return $row->category; });

        $child = $rows->first(function ($row) { return $row->category->parent_data; });
        if ($child) {
            return [(int)$child->category->parent_data->id, (int)$child->category->id];
        }

        $top = $rows->first();
        return [$top ? (int)$top->category->id : -1, -1];
    }

    /** One page of a suggestion row, in the row's order, as the tiles expect it. */
    private function suggestionPage($ids, $page, $perPage, $lang, $request)
    {
        $pageIds = $ids->slice(($page - 1) * $perPage, $perPage)->values();

        $query = Product::query()
            ->whereIn('products.id', $pageIds->all())
            ->leftJoin('flash_sales', function ($join) {
                $join->on('products.id', '=', 'flash_sale_products.product_id');
                $join->leftJoin('flash_sale_products', function ($join) {
                    $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                });
                FlashSaleWindow::liveProduct($join);
            });

        $title = 'products.title';
        $badge = 'products.badge';
        if ($lang) {
            $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                $join->on('pl.product_id', '=', 'products.id');
                $join->where('pl.lang', $lang);
            });
            $title = \DB::raw('COALESCE(pl.title, products.title) as title');
            $badge = \DB::raw('COALESCE(pl.badge, products.badge) as badge');
        }

        $rows = $query->select('products.id', $title, $badge,
            'products.selling', 'products.offered', 'products.slug',
            'products.image', 'flash_sale_products.price',
            'flash_sale_products.discount_type as flash_discount_type',
            'flash_sale_products.discount_value as flash_discount_value',
            'flash_sales.end_time')
            ->get()
            // one row per product even if two live sales join, in the row's order
            ->unique('id')
            ->sortBy(function ($p) use ($pageIds) { return $pageIds->search((int)$p->id); })
            ->values();

        $this->applyClearanceToProducts($rows);

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $rows, $ids->count(), $perPage, $page,
            ['path' => $request->url()]
        );
    }

    /**
     * The quantity bands to advertise on a product page.
     *
     * Entitlement is User::hasBusinessPricing(), the same test checkout uses —
     * this endpoint used to check "account_type === 'business'" on its own,
     * which silently withheld the bands from approved trade accounts.
     *
     * Each band carries the price the band will actually charge, derived here
     * rather than left to the caller. The storefront was recomputing it from
     * products.offered instead of the band's own wholesale_price, so whenever
     * those two differed the page advertised a figure no part of the backend
     * would honour.
     */
    /**
     * What this customer pays for this product, at this quantity, for this
     * variant — and the bands behind that figure.
     *
     * The product page used to fetch the bands alone and work the price out in
     * the browser from products.offered, which is how a business account
     * buying 30 was still shown the retail price: the band's discount hangs
     * off its own wholesale_price, the page never priced the quantity the
     * customer had typed, and nothing capped the result against the variant
     * price checkout would actually charge.
     *
     * So the price is decided here, by the same helper the basket and the
     * order use. `unit_price` is the answer; `pricing` is shown as the rate
     * card, with the row that produced the answer flagged. It answers for
     * everyone — a guest and a personal account get the retail figure and an
     * empty rate card rather than a zero the page would have to interpret.
     */
    public function businessProduct(Request $request, $id){
        try{
            $user = Auth::user();

            $quantity = max(1, (int)$request->input('quantity', 1));

            $product = Product::pricingRecord($id);

            // Without a product there is no retail ladder to cap the bands
            // against, and quoting them uncapped would advertise 0.00.
            if (is_null($product)) {
                return response()->json(new Response(null, [
                    'product_id' => $id,
                    'quantity' => $quantity,
                    'has_business_pricing' => false,
                    'retail_price' => 0.0,
                    'unit_price' => 0.0,
                    'compare_price' => null,
                    'pricing' => []
                ]));
            }

            /*
             * The variant the shopper is looking at, when the page says which.
             * Checkout charges the selected variant's own price, so quoting
             * the product-level figure here would put the page and the till
             * back out of step on exactly the products where they differ.
             */
            $inventories = UpdatedInventory::withCount('inventory_attributes')
                ->where('product_id', $id)
                ->get();

            $inventory = $request->filled('inventory_id')
                ? $inventories->firstWhere('id', (int)$request->input('inventory_id'))
                : null;

            /*
             * Empty for guests, personal accounts, unapproved trade accounts
             * and suspended ones — the same test the basket and checkout use,
             * so the page cannot quote a rate the till will not honour.
             */
            $tiers = BusinessProductPricing::forCustomer($user, $id);

            if (is_null($inventory)) {
                /*
                 * No variant named, so quote what the page is already showing:
                 * the cheapest variant a customer can actually buy, which is
                 * how the headline price is decided. Taking the product-level
                 * figure instead would have the quantity box contradict the
                 * headline the moment the page loaded, on exactly the products
                 * where the two differ.
                 */
                $retail = Pricing::displayRange($product, $inventories, null)['min'];

            } else {
                $retail = Pricing::retailUnitPrice($product, $inventory);
            }

            $unitPrice = Pricing::businessPrice($retail, $tiers, $quantity);

            $bands = $tiers->sortBy('min_qty')->values();

            // The band that produced $unitPrice, so the rate card can show
            // which row the customer is on rather than leaving them to guess.
            $appliedPrice = Pricing::businessTier($bands, $quantity);
            $appliedFound = false;

            $pricing = $bands->map(function ($item) use ($retail, $quantity, $appliedPrice, &$appliedFound) {

                $applies = false;

                if (!$appliedFound
                    && !is_null($appliedPrice)
                    && $item->coversQuantity($quantity)
                    && abs($item->computedFinalPrice() - $appliedPrice) < 0.005
                ) {
                    $applies = true;
                    $appliedFound = true;
                }

                return [
                    'min' => $item->min_qty,
                    'max' => $item->max_qty,
                    'wholesale_price' => $item->wholesale_price,
                    'discount_type' => $item->discount_type,
                    'discount_value' => $item->discount_value,
                    // What the band charges once capped against the retail
                    // ladder, priced at its own floor — the figure the rate
                    // card advertises for that row.
                    'final_price' => Pricing::businessPrice(
                        $retail, [$item], $item->min_qty
                    ),
                    'applies' => $applies,
                ];
            })->values();

            /*
             * Which way round this product quotes its price, and the rate
             * its own rule charges. Never assumed: the catalogue runs both
             * conventions and two different rates.
             */
            $vatSplit = Pricing::vatBreakdown(
                $unitPrice,
                Pricing::vatRate($product->tax_rules ?? null),
                Pricing::isVatInclusive($product)
            );

            $data = [
                'product_id' => $id,
                'quantity' => $quantity,
                'has_business_pricing' => $bands->isNotEmpty(),
                'retail_price' => Pricing::round($retail),
                'unit_price' => Pricing::round($unitPrice),
                'compare_price' => Pricing::compareFor(
                    (float)($product->selling ?? 0),
                    (float)($product->offered ?? 0),
                    $retail,
                    $unitPrice
                ),
                'pricing' => $pricing,

                /*
                 * The quoted unit price split for VAT.
                 *
                 * The product page prices the exact quantity in the box, so
                 * it needs the split for that figure rather than for the
                 * quantity-1 tile price. Done here, by the same helper the
                 * basket, the order and the invoice use, so the page never
                 * divides by a rate of its own — and so a product quoted
                 * ex-VAT and one quoted inc-VAT are told apart correctly.
                 */
                'vat_rate' => $vatSplit['rate'],
                'vat_inclusive' => $vatSplit['inclusive'],
                'unit_ex_vat' => $vatSplit['ex'],
                'unit_vat' => $vatSplit['vat'],
                'unit_inc_vat' => $vatSplit['inc'],
            ];

            return response()->json(new Response(null, $data));

        } catch (\Exception $e) {
            return response()->json(Validation::error(null, $e->getMessage()));
        }
    }

    public function bundleDeals(Request $request){
        try{
            $data = BundleDeal::with('products')->get();

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
                $total = $item->products->sum(function ($p) {
                    $selling = $p->selling ?? 0;
                    $offered = $p->offered ?? 0;
                    return $offered > 0 ? $offered : $selling;
                });

                $discountAmount = 0;

                if ($item->discount_type === 'percentage') {
                    $discountAmount = ($total * $item->discount_value) / 100;
                } else {
                    $discountAmount = $item->discount_value;
                }

                $final = $total - $discountAmount;

                $item['total_price'] = round($total, 2);
                $item['discount_amount'] = round($discountAmount, 2);
                $item['final_price'] = round(max($final, 0), 2);
            }

            return response()->json(new Response(null, $data));

        } catch (\Exception $e) {
            return response()->json(Validation::error(null, $e->getMessage()));
        }
    }

    /**
     * Public clearance page payload.
     *
     * Returns the currently active hero deal (newest active clearance whose
     * timer, if set, has not expired) together with a grid of past/sold-out
     * deals (expired or de-activated clearances). All pricing, stock, rating
     * and media is read from the linked product so the storefront stays in
     * sync with the catalogue automatically.
     */
    public function clearance(Request $request)
    {
        try {
            $lang = $request->header('language');
            $now = date('Y-m-d H:i:s');
            $public = Config::get('constants.status.PUBLIC');

            // ---- Hero: newest active deal (status public, not expired) ----
            $heroQuery = Clearance::with('product')
                ->where('status', $public)
                ->where(function ($q) use ($now) {
                    $q->whereNull('end_time')->orWhere('end_time', '>=', $now);
                })
                ->orderBy('sort_order', 'asc')
                ->orderBy('created_at', 'desc');

            $hero = $heroQuery->first();

            if ($hero) {
                $hero = $this->decorateClearance($hero, $lang, true);
                // A hero whose product was removed/made private is not usable.
                if (is_null($hero['product'])) {
                    $hero = null;
                }
            }

            // ---- Past deals: expired or de-activated clearances ----
            $pastQuery = Clearance::with('product')
                ->where(function ($q) use ($now, $public) {
                    $q->where('status', '!=', $public)
                        ->orWhere('end_time', '<', $now);
                });

            if ($hero) {
                $pastQuery = $pastQuery->where('clearances.id', '!=', $hero['id']);
            }

            $pastDeals = $pastQuery->orderBy('updated_at', 'desc')
                ->limit(8)
                ->get()
                ->map(function ($item) use ($lang) {
                    return $this->decorateClearance($item, $lang, false);
                })
                ->filter(function ($item) {
                    return !is_null($item['product']);
                })
                ->values();

            return response()->json(new Response(null, [
                'hero'       => $hero,
                'past_deals' => $pastDeals,
                /*
                 * How many people have actually asked to hear about these
                 * deals. The page printed "Thousands of shoppers already
                 * signed up" as a fixed string, to every visitor, against an
                 * empty table -- so it only tells the truth by accident and
                 * it told none at all here. The page shows it once there is
                 * a number worth showing.
                 */
                'subscriber_count' => $this->clearanceSubscriberCount(),
            ]));

        } catch (\Exception $e) {
            return response()->json(Validation::error(null, $e->getMessage()));
        }
    }

    /**
     * People who asked this page to tell them about clearance deals.
     *
     * Counted for a whole page of visitors at a time rather than per request:
     * it is a headline number, not a figure anything is decided from, and it
     * moves slowly.
     */
    private function clearanceSubscriberCount()
    {
        return (int) Utils::cacheRemember('clearance-subscriber-count', function () {
            return SubscriptionEmail::where('source', SubscriptionEmail::SOURCE_CLEARANCE)->count();
        }, 600);
    }


    /**
     * Attach product-derived fields to a clearance record. When $withDetail is
     * true the hero also receives the data needed to drive the gallery and the
     * real add-to-cart / buy-now flow (images + inventory).
     */
    private function decorateClearance($clearance, $lang, $withDetail = false)
    {
        $product = $clearance->product;

        // Skip clearances whose product was removed or made private.
        if (is_null($product) || (int) $product->status !== (int) Config::get('constants.status.PUBLIC')) {
            $clearance->setRelation('product', null);
            return $clearance->toArray();
        }

        if ($lang) {
            $productLang = \DB::table('product_langs')
                ->where('product_id', $product->id)
                ->where('lang', $lang)
                ->first();
            if ($productLang) {
                $product->title = $productLang->title ?: $product->title;
                $product->short_heading = $productLang->short_heading ?? $product->short_heading;
                $product->description = $productLang->description ?? $product->description;
                $product->overview = $productLang->overview ?? $product->overview;
            }
        }

        /*
         * Everything this product is priced by, in one record: its own price,
         * any live flash sale, and the clearance itself. Pricing reads all
         * three and applies them in the shop's order of precedence.
         *
         * This page used to assert there was no flash sale -- it set `price`
         * to null and took the clearance off the shelf price by hand. So a
         * product under both promotions was advertised here at its clearance
         * price while every other surface, and the till, used the flash price.
         * Pointing this deal at the iPhone 15 Pro put EUR 629.10 on the page
         * against EUR 599.00 in the basket.
         */
        $pricingRecord = Product::pricingRecord($product->id);

        // The flash columns the storefront price helper reads. Null when no
        // sale is running, which is the shape the detail payload has.
        $product->price = $pricingRecord->price ?? null;
        $product->end_time = $pricingRecord->end_time ?? null;
        $product->flash_discount_type = $pricingRecord->flash_discount_type ?? null;
        $product->flash_discount_value = $pricingRecord->flash_discount_value ?? null;

        // And the clearance, as the two columns Pricing reads it from.
        $product->clearance_discount_type = $clearance->discount_type;
        $product->clearance_discount_value = $clearance->discount_value;


        // Admin description override (falls back to product copy on the client).
        if (!empty($clearance->description)) {
            $product->overview = $clearance->description;
        }

        $claimedPercent = 0;

        if ($withDetail) {
            $product->setRelation(
                'product_images',
                $product->product_images()->select(['id', 'product_id', 'image'])->get()
            );

            $inventory = UpdatedInventory::with('inventory_attributes')
                ->where('product_id', $product->id)->get();

            /*
             * The headline, and the "was" figure beside it, decided by Pricing
             * from the UNDISCOUNTED rows.
             *
             * This has to run before the rows are reduced below. Handing
             * Pricing rows that already carry the deal leaves it nothing to
             * compare against -- it returns a compare price of null, and the
             * page then renders the deal price with no struck-through figure
             * and no "You save" badge, which is the one thing a clearance page
             * exists to show.
             *
             * So the deal travels as the two columns Pricing reads, and the
             * rows it measures against stay at their catalogue price.
             */
            $product->setRelation(
                'product_inventories',
                $inventory->map(function ($inv) { return clone $inv; })
            );

            ListingPrices::decorate([$product]);

            /*
             * The rows the variation picker and the cart mixin read, priced by
             * the same method the basket prices them with -- so whichever
             * promotion is in charge is the one this page quotes, and the
             * variant Buy Now adds costs what the page said it would.
             */
            $inventory->each(function ($inv) use ($pricingRecord) {
                if ((float) $inv->price > 0) {
                    $inv->price = Pricing::unitPrice($pricingRecord, $inv, 1, null);
                }
            });

            $product->inventory = $inventory;

            /*
             * Whether this product can be bought without the customer making
             * a choice first -- the same test CrossSellService applies, for
             * the same reason: with more than one buyable variation, any
             * inventory_id this page picked would be a default standing in for
             * a decision, and on a clearance page the decision is the goods.
             * Colour, capacity and condition are what an ex-display listing is
             * distinguished by.
             */
            $purchasable = $inventory->filter(function ($inv) {
                return Pricing::isPurchasable($inv);
            })->values();

            $requiresOption = $purchasable->count() > 1;

            /*
             * The variation the headline is quoting: the cheapest one somebody
             * can actually buy, which is how Pricing::displayRange decides the
             * figure this page prints.
             */
            $pricedVariant = $purchasable
                ->sortBy(function ($inv) use ($product) {
                    $price = (float) $inv->price;
                    return $price > 0 ? $price : (float) $product->offered;
                })
                ->first();

            // Claimed % is a product-level figure -- it is measured against
            // products.purchased, which counts every variation ever sold -- so
            // it stays on the product's whole stock.
            $stock = (int) $inventory->sum('quantity');
            $product->stock = $stock;

            $purchased = (int) ($product->purchased ?? 0);
            $denom = $purchased + $stock;
            $claimedPercent = $denom > 0 ? (int) round(($purchased / $denom) * 100) : 0;

            /*
             * "N remaining at this price" is a claim about ONE price, so it
             * has to be that variation's stock. It was the sum of every
             * variation: the iPhone 15 Pro would have announced 3,591
             * remaining at a price only 99 of them carry, across 36 variations
             * spanning EUR 699 to EUR 999.
             */
            $pricedStock = $pricedVariant ? (int) $pricedVariant->quantity : $stock;

            // Ensure cartHelper never trips on an undefined attribute list.
            if (!isset($product->attribute)) {
                $product->attribute = [];
            }

        }
        /*
         * The product-level figure, last.
         *
         * Decided by Pricing from the record above, so whichever promotion is
         * actually in charge is the one quoted -- a flash sale beats the
         * clearance here exactly as it does at the till. `selling` is left
         * alone as the compare-at.
         *
         * After the headline rather than before it: Pricing reads `offered` as
         * the base a variant with no price of its own inherits, so reducing it
         * first would take the deal off that variant twice.
         */
        $product->offered = Pricing::round(
            Pricing::unitPrice($pricingRecord, null, 1, null)
        );


        $data = $clearance->toArray();

        /*
         * The units sold is a commercial figure, not a storefront one -- the
         * product endpoint strips it deliberately (hideCommercialFields), and
         * this page was publishing it beside the same product. It is read
         * above to work claimed_percent out and goes no further.
         */
        $data['product'] = $this->hideCommercialFields($product->toArray());

        // Highlights stored as newline-separated text -> trimmed array.
        $data['highlights'] = $this->parseHighlights($clearance->highlights);

        // Auto-derived urgency metrics (hero only).
        $data['units_left'] = $withDetail ? ($pricedStock ?? 0) : null;
        $data['claimed_percent'] = $claimedPercent;

        /*
         * Does buying this need a choice first, and does the headline stand
         * for a range rather than a price? The page reads both: it sends a
         * shopper who has a choice to make to the product page, where the
         * picker is, and prefixes the figure with "From" when the variations
         * are not all priced the same.
         */
        $data['requires_option'] = $withDetail ? ($requiresOption ?? false) : false;
        $data['price_from'] = $withDetail
            ? (bool) ($requiresOption ?? false) && (bool) ($product->is_variable ?? false)
            : false;

        // Why this deal is in the past grid: "expired" (timer ran out) vs
        // "sold_out" (de-activated / no longer public).
        $data['is_expired'] = !empty($clearance->end_time)
            && strtotime($clearance->end_time) < time();

        return $data;
    }

    /**
     * Apply the active clearance deal (if any) to a product-detail payload so
     * the storefront shows the discounted price — mirroring the flash-sale
     * fields (offered = deal price, end_time + flash_sale_title for the
     * countdown banner). Runs outside the detail cache so admin edits reflect
     * immediately. A live flash-sale price always takes precedence.
     */
    /**
     * Strip commercially sensitive columns from a storefront response.
     *
     * The product-detail query selects products.*, so the public payload
     * carried the cost price, the wholesale price and the wholesale discount
     * alongside the retail price — anyone could read the shop's margin straight
     * off the product page. The listing endpoints already select explicit
     * columns and were unaffected.
     */
    private function hideCommercialFields($productData)
    {
        if (!$productData) {
            return $productData;
        }

        $sensitive = [
            'purchased',
            'wholesale_price',
            'wholesale_discount_type',
            'wholesale_discount_value',
            'discount_type',
            'discount_value',
        ];

        if ($productData instanceof Product) {
            $productData->makeHidden($sensitive);
            return $productData;
        }

        if (is_array($productData)) {
            foreach ($sensitive as $key) {
                unset($productData[$key]);
            }
        }

        return $productData;
    }


    private function applyClearanceToDetail($productData, $id)
    {
        if (!($productData instanceof Product)) {
            return $productData;
        }

        // Respect an already-active flash-sale price.
        if (!is_null($productData->price)) {
            return $productData;
        }

        $now = date('Y-m-d H:i:s');
        $clearance = Clearance::where('product_id', $id)
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->where(function ($q) use ($now) {
                $q->whereNull('end_time')->orWhere('end_time', '>=', $now);
            })
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->first();

        if (!$clearance || $clearance->discount_value <= 0) {
            return $productData;
        }

        $applyDiscount = $this->clearanceDiscountFn($clearance);

        // Deal is applied on top of the current price (offered, else selling).
        $base = (float) $productData->offered > 0 ? (float) $productData->offered : (float) $productData->selling;
        $productData->offered = $applyDiscount($base);

        $this->discountVatInclusivePrice($productData, $base, $productData->offered);

        /*
         * Every variation's own price, on both relations that carry them.
         *
         * `inventory` is what the variation picker reads. `product_inventories`
         * is what Pricing::displayRange reads to work out display_price -- the
         * figure the page and every tile actually print. Only the first was
         * being discounted, so on a product whose variants carry their own
         * price the deal moved products.offered, which nothing renders, and
         * left the headline at full price under a CLEARANCE badge.
         */
        $this->discountInventoryPrices($productData->inventory ?? null, $applyDiscount);
        $this->discountInventoryPrices($this->loadedInventories($productData, true), $applyDiscount);

        // Reuse the flash-sale countdown banner when the deal has an end time.
        if ($clearance->end_time) {
            $productData->end_time = $clearance->end_time;
            $productData->flash_sale_title = $clearance->title ?: 'Clearance';
        }

        return $productData;
    }

    /**
     * Apply active clearance deals to a collection/array of listing products
     * (product tiles) so grids preview the discounted price — mirroring flash
     * sale. Batched (one query for all ids). A live flash price takes priority.
     * Each affected product gets `offered` + `vatInclusiveOfferedPrice`
     * discounted and a `clearance` flag set for an optional badge.
     */
    /**
     * Decorate a listing collection for display.
     *
     * Clearance first, because it rewrites `offered`, then the headline price —
     * which has to be derived from the clearance-adjusted figures.
     */
    private function applyClearanceToProducts($items)
    {
        $this->applyClearanceOnly($items);
        $this->decorateDisplayPrices($items);
    }


    /**
     * Attach the price a listing tile should actually show.
     *
     * For a variation product the tile used to print products.offered /
     * products.selling, but checkout charges the chosen variant's own price. A
     * product whose cheapest buyable variant is 500.00 therefore advertised
     * 450.00 and charged 500.00.
     *
     * display_price is the lowest price a customer can actually pay right now;
     * display_price_max and is_variable let the tile render "From €500".
     */
    /**
     * Decorate the products nested inside the home page payload.
     *
     * The home page ships products two levels down — collections hold pivot
     * rows which hold the product — and flash sales hold their own list, so
     * neither reached decorateDisplayPrices() and both kept showing the
     * product-level price with a struck-through figure identical to it.
     */
    private function decorateHomeProducts($data)
    {
        $products = [];
        $pairs = [];

        foreach (($data['collections'] ?? []) as $collection) {
            foreach (($collection->product_collections ?? []) as $pivot) {
                if (empty($pivot->product)) {
                    continue;
                }

                $product = $pivot->product;

                /*
                 * The flash-sale join lives on the pivot row, not on the nested
                 * product select, so a product on sale looked full price here
                 * while the shop listing showed the sale price — the same
                 * product priced two ways on two pages. Carry it across before
                 * the price is worked out.
                 */
                if (is_null($product->end_time ?? null) && !is_null($pivot->end_time ?? null)) {
                    $product->end_time = $pivot->end_time;
                    $product->price = $pivot->price;
                }

                $products[] = $product;

                /*
                 * ProductsSlider hands the tile the pivot row, not the nested
                 * product — the pivot carries flattened product columns
                 * (title, selling, offered, image) from its own select, which
                 * is why the tile renders at all. Decorating only the relation
                 * meant the tile never saw display_price and fell back to the
                 * raw offer price: a variation product whose cheapest buyable
                 * variant costs 500.00 still advertised 450.00 here.
                 */
                $pairs[] = [$pivot, $product];
            }
        }

        foreach (($data['flash_sales'] ?? []) as $sale) {
            foreach (($sale->public_products ?? []) as $saleProduct) {
                // Flash sale rows wrap the product; take whichever is a model.
                $products[] = $saleProduct->product ?? $saleProduct;
            }
        }

        if (count($products) > 0) {
            /*
             * Clearance as well as the headline price. The home page ran
             * neither, so a product on clearance advertised its full price
             * here and the discounted one on the shop listing — the same
             * product showing two prices on two pages of the same site.
             */
            $this->applyClearanceToProducts($products);
        }

        // Mirror the clearance-adjusted figures onto the row the tile reads,
        // then price it from the variants the nested relation carries.
        $tierMap = $this->entitledTierMap(array_map(function ($pair) {
            return $pair[0];
        }, $pairs));

        foreach ($pairs as [$pivot, $product]) {
            $pivot->offered = $product->offered;

            $range = Pricing::displayRange(
                $pivot, $product->product_inventories ?? null,
                $tierMap->get($pivot->id ?? null)
            );

            $pivot->display_price = $range['min'];
            $pivot->display_price_max = $range['max'];
            $pivot->display_compare_price = $range['compare'];
            $pivot->is_variable = $range['variable'];
            $pivot->in_stock = $range['in_stock'];
        }
    }


    /**
     * The price every tile in this response should advertise.
     *
     * The calculation lives in ListingPrices, which every other surface
     * showing a product card uses too — the wishlist, recently viewed, the
     * compare list and a flash sale among them. It was private to this
     * controller, so those four shipped products.offered and advertised the
     * retail price to accounts entitled to their own.
     */
    private function decorateDisplayPrices($items)
    {
        ListingPrices::decorate($items);
    }


    /**
     * The quantity bands the caller is entitled to, keyed by product id.
     *
     * Empty for guests, personal accounts, unapproved trade accounts and
     * suspended ones — BusinessProductPricing::tierMap answers that from
     * User::hasBusinessPricing(), the same test the basket and checkout use, so
     * a listing cannot advertise a rate the till will not honour.
     *
     * One query per listing rather than one per tile.
     */
    private function entitledTierMap($items)
    {
        return ListingPrices::tierMap($items);
    }


    private function applyClearanceOnly($items)
    {
        if (is_null($items)) {
            return;
        }

        $ids = collect($items)->pluck('id')->filter()->values()->all();
        if (empty($ids)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $clearances = Clearance::whereIn('product_id', $ids)
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->where(function ($q) use ($now) {
                $q->whereNull('end_time')->orWhere('end_time', '>=', $now);
            })
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('product_id');

        if ($clearances->isEmpty()) {
            return;
        }

        foreach ($items as $p) {
            // Flash-sale price wins.
            if (!is_null($p->price ?? null)) {
                continue;
            }

            $group = $clearances->get($p->id);
            $clearance = $group ? $group->first() : null;
            if (!$clearance || $clearance->discount_value <= 0) {
                continue;
            }

            $apply = $this->clearanceDiscountFn($clearance);

            $base = (float) ($p->offered ?? 0) > 0 ? (float) $p->offered : (float) ($p->selling ?? 0);
            $p->offered = $apply($base);

            if (isset($p->vatInclusiveOfferedPrice)) {
                $this->discountVatInclusivePrice($p, $base, $p->offered);
            }

            /*
             * The variants' own prices, which is what decorateDisplayPrices
             * works display_price out from a moment later. Without this the
             * tile printed the full price under a CLEARANCE badge on every
             * product whose variants carry their own price.
             */
            $this->discountInventoryPrices($this->loadedInventories($p), $apply);

            $p->clearance = true;
        }
    }

    /**
     * The variations a record is already carrying, or nothing.
     *
     * Reading ->product_inventories off a model that has not loaded it fires a
     * query, and a listing page holds thirty of them -- so touching the
     * relation speculatively would turn one page into thirty-one queries.
     * Every listing eager-loads it, and the ones that do not are skipped.
     *
     * $load is for the detail endpoint, which is one product and does not
     * eager-load it: decorateDisplayPrices reads the same relation a moment
     * later and would fetch it anyway, so loading it here costs nothing and
     * is the difference between discounting those rows and not.
     */
    private function loadedInventories($record, $load = false)
    {
        if ($record instanceof Product) {
            if ($load) {
                $record->loadMissing('product_inventories');
            }

            return $record->relationLoaded('product_inventories')
                ? $record->product_inventories
                : null;
        }

        return $record->product_inventories ?? null;
    }


    /**
     * Take a clearance off every variation price in a relation, in place.
     *
     * Accepts either models or arrays: the detail payload carries one of each
     * depending on which branch of the cached query built it.
     */
    private function discountInventoryPrices($rows, $applyDiscount)
    {
        foreach (($rows ?? []) as $row) {
            $price = is_array($row) ? ($row['price'] ?? 0) : ($row->price ?? 0);

            if ((float) $price <= 0) {
                continue;
            }

            $discounted = $applyDiscount($price);

            if (is_array($row)) {
                $row['price'] = $discounted;
            } else {
                $row->price = $discounted;
            }
        }
    }


    /**
     * Move the VAT-inclusive price in step with the ex-VAT one.
     *
     * The two are the same money at two rates, so the reduction has to be
     * proportional. Taking the same FLAT sum off each -- which is what a fixed
     * clearance used to do -- changes the rate between them: EUR 50 off
     * 689.99 / 848.69 left 639.99 / 798.69, an implied 24.8% where the product
     * is charged 23%.
     */
    private function discountVatInclusivePrice($productData, $exBase, $exDiscounted)
    {
        $vatBase = (float) $productData->vatInclusiveOfferedPrice > 0
            ? (float) $productData->vatInclusiveOfferedPrice
            : (float) $productData->vatInclusivePrice;

        if ($vatBase <= 0 || (float) $exBase <= 0) {
            return;
        }

        $productData->vatInclusiveOfferedPrice = round(
            $vatBase * ((float) $exDiscounted / (float) $exBase), 2
        );
    }


    /**
     * Build a closure that applies a clearance's discount to a given price.
     *
     * This is the DISPLAY side of a clearance: the payloads whose queries do
     * not join the deal in, and which therefore have to be reduced before
     * Pricing sees them. A record must be discounted by exactly one of the
     * two mechanisms -- this one, or the clearance columns Pricing reads --
     * never both, or the deal is taken off twice.
     *
     * @see AppModelsHelperClearanceWindow the other one
     */
    private function clearanceDiscountFn($clearance)
    {
        $type = $clearance->discount_type ?: 'percentage';
        $value = (float) $clearance->discount_value;

        return function ($price) use ($type, $value) {
            $price = (float) $price;
            if ($value <= 0) {
                return round($price, 2);
            }
            $discounted = $type === 'percentage'
                ? $price - ($price * $value / 100)
                : $price - $value;
            return round(max($discounted, 0), 2);
        };
    }

    /**
     * Split the stored highlight text into a clean list of bullet strings.
     */
    private function parseHighlights($highlights)
    {
        if (empty($highlights)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $highlights))));
    }

    public function productCrossellUpsell(Request $request, $id){
        try{

            $product = Product::where('id', $id)->firstOrFail();

            /*
             * The bundle block sits under a variation picker and a quantity
             * box, and it adds the featured product to the basket — so it has
             * to be quoting the variation and the quantity the customer has
             * actually chosen.
             *
             * It used to quote, and add, whichever variation happened to hold
             * the most stock, at quantity one. On a page set to 5 of the 512GB
             * model that advertised the 128GB model's single-unit price and
             * then put the 128GB model in the basket.
             */
            $quantity = max(1, (int)$request->input('quantity', 1));

            $inventories = UpdatedInventory::withCount('inventory_attributes')
                ->where('product_id', $product->id)
                ->orderByDesc('quantity')
                ->get();

            $mainInventory = null;

            if ($request->filled('inventory_id')) {
                $mainInventory = $inventories->firstWhere(
                    'id', (int)$request->input('inventory_id')
                );
            }

            // No variation named — the page has not settled on one yet, or the
            // product has none. Fall back to the best-stocked row, as before.
            if (is_null($mainInventory)) {
                $mainInventory = $inventories->first();
            }

            /*
             * Every price on this block is decided by Pricing, against the
             * caller's own entitlement.
             *
             * It used to quote products.offered to everybody. That was wrong
             * twice over: a business account was shown the retail price in the
             * "frequently bought together" total and in the sticky add-to-cart
             * bar, which both read this response — and for *any* customer a
             * product whose buyable variant costs more than the product-level
             * offer was under-quoted here and then charged the higher figure
             * at the basket.
             */
            $customer = Auth::user();

            /*
             * The Essentials Bundle, from BundleService.
             *
             * It used to be assembled here, and got three things wrong that a
             * block quoting a price cannot get wrong. It priced each item with
             * Pricing::retailUnitPrice($product) -- the PRODUCT-level figure --
             * while the basket charges the variant that was added, so a bundle
             * holding anything whose cheapest buyable variant costs more than
             * the product headline was under-quoted and then charged the
             * higher figure. It checked nothing about availability, so an
             * unpublished or sold-out item stayed in the bundle and in its
             * total. And it returned no inventory ids at all, so the block it
             * fed could not put the bundle in a basket even in principle --
             * which is why the section never did anything.
             *
             * Availability, the variant, and the price now come from one place
             * that the add-to-basket call also asks, so the label and the till
             * cannot disagree.
             */
            $bundleData = (new BundleService())->forProduct($product, $customer);

            /*
             * The "goes well with" block, from product_cross_sells.
             *
             * It used to read the `upsells` GROUP a product pointed at, and
             * quote `upsell_products.price` when one had been set. Both are
             * gone. A group meant every product sharing it got the same
             * accessories and none could be given its own, and a price banked
             * against a recommendation went stale the moment the catalogue,
             * a band or a flash sale moved -- so the block quoted one figure
             * and the basket charged another.
             *
             * CrossSellService prices each recommendation exactly the way
             * CartsController::serverSidePricing does, so the two agree by
             * construction rather than by being kept in step by hand.
             */
            $crossSellItems = (new CrossSellService())->forProduct(
                $product->id, $customer
            );


            /*
             * The featured product, priced for whoever is asking.
             *
             * Clearance still wins where there is one — it is a dated override
             * of the ladder, the same way a flash sale is — and a band applies
             * on top of whichever base that leaves, never raising it.
             */
            $mainTiers = BusinessProductPricing::forCustomer($customer, $product->id);

            /*
             * The pricing record carries any live clearance, so
             * retailUnitPrice answers with the deal price already applied --
             * per variant, which is what this block is quoting. It used to ask
             * a separate helper first, and that helper worked the discount out
             * from the product-level offer price, so on a product with priced
             * variants the sticky bar and the till gave different answers.
             */
            $mainBase = Pricing::retailUnitPrice(
                Product::pricingRecord($product->id) ?? $product,
                $mainInventory
            );

            /*
             * Priced at the customer's own quantity, so this block and the buy
             * box above it cannot disagree. Quoting quantity one here is what
             * left "€699" sitting under a "€649" headline: correct for buying
             * one, and not what the customer was buying.
             */
            $mainPrice = Pricing::round(
                Pricing::businessPrice($mainBase, $mainTiers, $quantity)
            );

            return response()->json([
                'main_product' => [
                    'id' => $product->id,
                    'inventory_id' => $mainInventory?->id,
                    'name' => $product->title,
                    // A unit price. The block multiplies by the quantity below.
                    'price' => $mainPrice,
                    'quantity' => $quantity,
                    'old_price' => $product->selling,
                    'image' => $product->image,
                ],
                'cross_sell' => $crossSellItems,
                'bundle_deal' => $bundleData
            ]);
        } catch (\Exception $e) {
            return response()->json(Validation::error(null, $e->getMessage()));
        }
    }


    /* ------------------------------------------------------------------ *
     *  Cross-sell
     * ------------------------------------------------------------------ */

    /**
     * The recommendations to show after a product is added to the basket.
     *
     * Open to everybody, guests included (section 28). Nobody is made to sign
     * in to be shown an accessory, and the basket a guest already has is
     * identified the same way every other basket read identifies it -- by the
     * user_token the storefront issued.
     *
     * Everything is decided here rather than on the page: which products are
     * eligible, what they cost this particular customer, and which variant --
     * if any -- is being offered. The page is told what to draw.
     *
     * It never fails the caller's add-to-basket. Section 27: the primary
     * product is already in the basket by the time this runs, so a failure
     * here answers with an empty list and a reason, and the basket is left
     * exactly as it was.
     */
    public function crossSellsForProduct(Request $request, $id)
    {
        try {
            $customer = $request->user('user') ?: Auth::user();

            $service = new CrossSellService();

            /*
             * Section 13: a recommendation the customer already has is not a
             * recommendation. Read once, here, rather than left to the page --
             * the page cannot be trusted about what is in the basket, and it
             * would have to ask the server anyway.
             */
            $inBasket = $this->basketProductIds($request, $customer);

            $items = $service->forProduct((int)$id, $customer, $inBasket);

            return response()->json(new Response($request->token, [
                'product_id' => (int)$id,
                'items' => $items,
            ]));

        } catch (\Exception $e) {
            /*
             * Answered as an empty recommendation list rather than as an
             * error. A cross-sell is an enhancement; a shopper whose basket
             * has just worked should not be shown a failure because the block
             * beneath it could not be built.
             */
            return response()->json(new Response($request->token, [
                'product_id' => (int)$id,
                'items' => [],
            ]));
        }
    }

    /**
     * The combined recommendations for everything in the basket.
     *
     * Section 13: a laptop, a monitor and a keyboard may all point at the same
     * mouse, and that is ONE recommendation. Deduplicated by the service,
     * which also drops anything the basket already holds.
     */
    public function crossSellsForCart(Request $request)
    {
        try {
            $customer = $request->user('user') ?: Auth::user();

            $primaryIds = $this->basketProductIds($request, $customer);

            if (empty($primaryIds)) {
                return response()->json(new Response($request->token, [
                    'items' => [],
                    ]));
            }

            $service = new CrossSellService();

            return response()->json(new Response($request->token, [
                'items' => $service->forProducts($primaryIds, $customer, $primaryIds),
            ]));

        } catch (\Exception $e) {
            return response()->json(new Response($request->token, [
                'items' => [],
            ]));
        }
    }

    /**
     * What is in this caller's basket, however they are identified.
     *
     * Scoped exactly the way CartsController scopes its own reads: a signed-in
     * shopper by user_id, a guest by the server-issued user_token. An
     * unidentified caller has no basket, which is not an error -- it is a
     * shopper who has not added anything yet.
     */
    private function basketProductIds(Request $request, $customer)
    {
        // The basket proper: a Buy Now checkout is not something to cross-sell against.
        $query = Cart::query()->inBasket();

        if ($customer) {
            $query = $query->where('user_id', $customer->id);

        } else if ($request->user_token) {
            $query = $query->where('user_token', $request->user_token);

        } else {
            return [];
        }

        return $query->pluck('product_id')
            ->filter()
            ->map(function ($id) { return (int)$id; })
            ->unique()
            ->values()
            ->all();
    }

    public function qualifyingProducts(Request $request)
    {
        $user = Auth::user();

        $points = PointTransaction::balanceFor($user->id);

        $conversionRate = 100; // 100 pts = €1
        $discountFactor = 0.2; // 20%

        $perPage = 4;

        /*
         * Cheapest first, in SQL. Points needed rise with price, so this is
         * "unlocked first, then closest to unlocking" across the whole
         * catalogue. It used to take the four newest products and sort only
         * those, so page 2 could hold products nearer than page 1.
         */
        $products = Product::select('id', 'title', 'slug', 'image')
            ->where('status', 1)
            ->whereNotNull('slug')
            ->whereRaw('CASE WHEN offered > 0 THEN offered ELSE selling END > 0')
            ->orderByRaw('CASE WHEN offered > 0 THEN offered ELSE selling END ASC')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        // The shop's own price: flash sale and clearance included, as the till charges.
        $records = Product::pricingRecords($products->getCollection()->pluck('id')->map(function ($id) {
            return (int)$id;
        }));

        $collection = $products->getCollection()->map(function ($product) use ($conversionRate, $discountFactor, $points, $records) {

            $record = $records[$product->id] ?? null;
            $price = Pricing::round(Pricing::retailUnitPrice($record));
            $requiredPoints = (int)round($price * $conversionRate * $discountFactor);

            // Labelled as the rest of the shop does: inc-VAT says so; ex-VAT also gives the gross
            $rate = $record ? Pricing::vatRate($record->tax_rules) : 0;
            $inclusive = Pricing::isVatInclusive($record);

            return [
                'id' => $product->id,
                'title' => $product->title,
                'price' => number_format($price, 2, '.', ''),
                'vat_side' => $rate > 0 ? ($inclusive ? 'inc' : 'ex') : null,
                'price_inc_vat' => $rate > 0 && !$inclusive
                    ? number_format(Pricing::vatBreakdown($price, $rate, false)['inc'], 2, '.', '')
                    : null,
                'slug' => $product->slug,
                'image' => $product->image,
                'required_points' => $requiredPoints,
                // what those points take off at checkout (100 pts = 1): 20% of the price
                'points_value' => number_format($requiredPoints / $conversionRate, 2, '.', ''),
                'is_unlocked' => $points >= $requiredPoints,
                'points_needed' => max(0, $requiredPoints - $points)
            ];
        });

        $products->setCollection($collection);

        return response()->json([
            'points' => $points,
            'data' => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
        ]);
    }

    public function getGiftVouchers(Request $request){
        try{
            // withdrawn designs stay in the admin, not the shop
            $vouchers = GiftVoucher::where('is_active', true)->orderBy('id')->get();

            return response()->json(new Response(null, $vouchers));

        } catch (\Exception $e) {
            return response()->json(Validation::error(null, $e->getMessage()));
        }
    }

    private function getTrendText($current, $previous)
    {
        if ($previous <= 0) {
            if ($current > 0) {
                return '+100% this week';
            }
            return '0% this week';
        }

        $percentage = (
            ($current - $previous) / $previous
        ) * 100;

        $percentage = round($percentage);

        if ($percentage > 0) {
            return '+' . $percentage . '% this week';
        }

        return $percentage . '% this week';
    }

    /**
     * Used when the weekly window was empty and the list fell back to
     * all-time ranking, where a week-on-week delta describes nothing.
     */
    private function getSoldText($units)
    {
        $units = (int) $units;

        if ($units <= 0) {
            return 'No sales yet';
        }

        return number_format($units) . ' sold';
    }

    public function categoryInsights(Request $request){

        $trendingQuery = OrderedProductsInventory::select(
                'product_id',
                DB::raw('SUM(quantity) as total_sales')
            )
            ->with([
                'product:id,title,slug,image,brand_id,created_at'
            ])
            ->whereDate(
                'created_at',
                '>=',
                Carbon::now()->subDays(7)
            )
            ->groupBy('product_id')
            ->orderByDesc('total_sales');

        $trending = $trendingQuery->take(4)->get();

        // The week on its own rarely fills the list, so the query below falls
        // back to all-time best sellers. The subtitle has to follow that
        // switch: measuring a window that was empty by definition is what made
        // every row read "0% this week".
        $trendingIsWeekly = $trending->count() >= 4;

        if (!$trendingIsWeekly) {

            $trending = OrderedProductsInventory::select(
                    'product_id',
                    DB::raw('SUM(quantity) as total_sales')
                )
                ->with([
                    'product:id,title,slug,image,brand_id,created_at'
                ])
                ->groupBy('product_id')
                ->orderByDesc('total_sales')
                ->take(4)
                ->get();
        }

        // one grouped lookup for the whole list, rather than a pair of
        // queries per row
        $trendingPreviousWeek = $trendingIsWeekly
            ? OrderedProductsInventory::select(
                    'product_id',
                    DB::raw('SUM(quantity) as total')
                )
                ->whereIn('product_id', $trending->pluck('product_id'))
                ->whereBetween(
                    'created_at',
                    [
                        Carbon::now()->subDays(14),
                        Carbon::now()->subDays(7)
                    ]
                )
                ->groupBy('product_id')
                ->get()
                ->pluck('total', 'product_id')
            : collect();

        $trending = $trending->map(function ($item) use (
            $trendingIsWeekly,
            $trendingPreviousWeek
        ) {

            return [
                'id' => $item->product?->id,
                'title' => $item->product?->title,
                'slug' => $item->product?->slug,
                'image' => $item->product?->image,
                // total_sales is already this week's units on the weekly path,
                // and lifetime units on the fallback
                'subtitle' => $trendingIsWeekly
                    ? $this->getTrendText(
                        $item->total_sales,
                        $trendingPreviousWeek[$item->product_id] ?? 0
                    )
                    : $this->getSoldText($item->total_sales),
                'icon' => 'fa-solid fa-arrow-trend-up'
            ];
        });

        $topBrandsQuery = OrderedProductsInventory::join(
                'products',
                'products.id',
                '=',
                'ordered_products_inventories.product_id'
            )
            ->join(
                'brands',
                'brands.id',
                '=',
                'products.brand_id'
            )
            ->select(
                'brands.id',
                'brands.title',
                'brands.slug',
                'brands.image',
                DB::raw('SUM(ordered_products_inventories.quantity) as total_sales')
            )
            ->whereDate(
                'ordered_products_inventories.created_at',
                '>=',
                Carbon::now()->subDays(7)
            )
            ->groupBy(
                'brands.id',
                'brands.title',
                'brands.slug',
                'brands.image'
            )
            ->orderByDesc('total_sales');

        // same fallback, same consequence for the subtitle
        $topBrands = $topBrandsQuery->take(4)->get();
        $topBrandsIsWeekly = $topBrands->count() >= 4;

        if (!$topBrandsIsWeekly) {

            $topBrands = OrderedProductsInventory::join(
                    'products',
                    'products.id',
                    '=',
                    'ordered_products_inventories.product_id'
                )
                ->join(
                    'brands',
                    'brands.id',
                    '=',
                    'products.brand_id'
                )
                ->select(
                    'brands.id',
                    'brands.title',
                    'brands.slug',
                    'brands.image',
                    DB::raw('SUM(ordered_products_inventories.quantity) as total_sales')
                )
                ->groupBy(
                    'brands.id',
                    'brands.title',
                    'brands.slug',
                    'brands.image'
                )
                ->orderByDesc('total_sales')
                ->take(4)
                ->get();
        }

        $brandsPreviousWeek = $topBrandsIsWeekly
            ? OrderedProductsInventory::join(
                    'products',
                    'products.id',
                    '=',
                    'ordered_products_inventories.product_id'
                )
                ->select(
                    'products.brand_id',
                    DB::raw('SUM(ordered_products_inventories.quantity) as total')
                )
                ->whereIn('products.brand_id', $topBrands->pluck('id'))
                ->whereBetween(
                    'ordered_products_inventories.created_at',
                    [
                        Carbon::now()->subDays(14),
                        Carbon::now()->subDays(7)
                    ]
                )
                ->groupBy('products.brand_id')
                ->get()
                ->pluck('total', 'brand_id')
            : collect();

        $topBrands = $topBrands->map(function ($item) use (
            $topBrandsIsWeekly,
            $brandsPreviousWeek
        ) {

            return [
                'id' => $item->id,
                'title' => $item->title,
                'slug' => $item->slug,
                'image' => $item->image,
                'subtitle' => $topBrandsIsWeekly
                    ? $this->getTrendText(
                        $item->total_sales,
                        $brandsPreviousWeek[$item->id] ?? 0
                    )
                    : $this->getSoldText($item->total_sales),
                'icon' => 'fa-solid fa-arrow-trend-up'
            ];
        });

        $newArrivals = Product::select(
            'id',
            'title',
            'slug',
            'image',
            'created_at'
        )
        ->whereNotNull('slug')
        ->latest()
        ->take(4)
        ->get();

       $response = [
            [
                'title' => 'Trending Now',
                'icon' => 'fa-solid fa-fire',
                'items' => $trending
            ],
            [
                'title' => 'Top Brands',
                'icon' => 'fa-regular fa-star',
                'items' => $topBrands
            ],
            [
                'title' => 'New Arrivals',
                'icon' => 'fa-solid fa-bolt',
                'items' => $newArrivals->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'slug' => $item->slug,
                        'image' => $item->image,
                        'subtitle' => Carbon::parse(
                            $item->created_at
                        )->diffForHumans(),
                        'icon' => 'fa-solid fa-clock'
                    ];
                })
            ]
        ];

        return response()->json(
            new Response(null, $response)
        );
    }

    public function priceRanges(Request $request)
    {
        $ranges = [

            [
                'title' => 'Under €25',
                'min' => 0,
                'max' => 25,
                'icon' => 'fa-solid fa-euro-sign',
                'description' => 'Budget-friendly options'
            ],

            [
                'title' => '€25 - €100',
                'min' => 25,
                'max' => 100,
                'icon' => 'fa-solid fa-tags',
                'description' => 'Popular everyday picks'
            ],

            [
                'title' => '€100 - €500',
                'min' => 100,
                'max' => 500,
                'icon' => 'fa-solid fa-gem',
                'description' => 'Premium quality products'
            ],

            [
                'title' => 'Above €500',
                'min' => 500,
                'max' => null,
                'icon' => 'fa-solid fa-crown',
                'description' => 'Luxury and flagship items'
            ]
        ];

        $response = collect($ranges)->map(function ($range) {

            $query = Product::query()
                ->whereNotNull('slug')->where('status', Config::get('constants.status.PUBLIC'));

            if ($range['max']) {
                $query->whereBetween(
                    'selling',
                    [$range['min'], $range['max']]
                );

            } else {
                $query->where(
                    'selling',
                    '>=',
                    $range['min']
                );
            }

            return [
                'title' => $range['title'],
                'icon' => $range['icon'],
                'description' => $range['description'],
                'products_count' => $query->count(),
                'min' => $range['min'],
                'max' => $range['max']
            ];
        });

        return response()->json(
            new Response(null, $response)
        );
    }

    public function onboarding(Request $request)
    {
        return response()->json([
            'data' => Onboarding::first()
        ]);
    }

    /**
     * Banners for the left side of the auth screens. `placement` is the screen
     * asking: login, signup, or auth for anything else.
     */
    public function authBanners(Request $request)
    {
        $banners = AuthBanner::live()
            ->forPlacement($request->query('placement'))
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get(AuthBanner::FEED_COLUMNS);

        return response()->json([
            'data' => $banners
        ]);
    }

    public function sessions(Request $request)
    {
        $currentSessionId = $request->header('Session-Id');
        $userId = null;
        $sessions = collect();
        $user = Auth::user();
        if($user){
            $userId = $user->id;
        }
        if($userId){
            $sessions =  UserSession::where(
                'user_id',
                $userId
            )
            ->where('is_active', true)
            ->orderByDesc('last_activity')
            ->get();
        }

        $sessions->map(function ($session) use ($currentSessionId) {
            $session->is_current = $session->session_id === $currentSessionId;
            return $session;
        });

        return response()->json([
            'data' => $sessions
        ]);
    }

    public function logoutDevice(Request $request)
    {
        $id = $request->session_id;
        $userId = null;
        $user = Auth::user();
        if($user){
            $userId = $user->id;
        }
        if($userId){
            $session = UserSession::where(
                'session_id',
                $id
            )->first();

            Token::where('id', $session->token_id)->update(['revoked' => true]);

            $session->update([
                'is_active' => false
            ]);
        }

        return response()->json([
            'message' => 'Device logged out'
        ]);
    }

    public function logoutOtherDevices(Request $request)
    {
        $id = $request->session_id;

        $userId = null;
        $user = Auth::user();
        if($user){
            $userId = $user->id;
        }
        if($userId){
            $sessions = UserSession::where(
                'user_id',
                $userId
            )
            ->where(
                'session_id',
                '!=',
                $id
            )->get();
            
            foreach ($sessions as $session) {

                Token::where('id', $session->token_id)
                    ->update(['revoked' => true]);

                $session->update([
                    'is_active' => false
                ]);
            }

        }

        return response()->json([
            'message' => 'Other devices logged out'
        ]);
    }
}
