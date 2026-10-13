<?php

namespace App\Http\Controllers;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\BundleDeal;
use App\Models\Cart;
use App\Models\InventoryImage;
use App\Models\Category;
use App\Models\CollectionWithProduct;
use App\Models\CompareList;
use App\Models\FlashSaleProduct;
use App\Models\ProductSpecification;
use App\Models\SpecificationGroup;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\HomeSliderSourceProduct;
use App\Models\Inventory;
use App\Models\InventoryAttribute;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\ProductLog;
use App\Models\ProductCollection;
use App\Models\ProductImage;
use App\Models\ProductFeatureImage;
use App\Models\ProductImageAttribute;
use App\Models\ProductLang;
use App\Models\RatingReview;
use App\Models\ReviewImage;
use App\Models\ShippingRule;
use App\Models\SubCategory;
use App\Models\Helper\Pricing;
use App\Models\Helper\Shipping;
use App\Models\TaxRules;
use App\Models\UpdatedInventory;
use App\Models\UserWishlist;
use App\Models\WysiwygImage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str; 

class ProductsController extends ControllerHelper
{

    public function all(Request $request)
    {
        try {
            $lang = $request->header('language');

            $categoryId = $request->category_id;
            // The Products grid has always rendered a Subcategories dropdown
            // and put this in the query string; nothing here ever read it, so
            // choosing one left the grid unchanged and looking filtered.
            $subcategoryId = $request->subcategory_id;
            $status     = $request->status;
            $search     = $request->q;
            $orderBy = $request->orderby ?? 'created_at';
            $type    = $request->type ?? 'desc';
            $stock = $request->stock;
            $brand = $request->brand;
            $productId = $request->product_id;
            $collectionId = $request->collection_id;
            $crosssellId = $request->crosssell_id;
            $onlyVariants = $request->onlyVariants;
            $recentlyAdded = $request->recentlyAdded;
            $highReturn = $request->highReturn;
            $deadStock = $request->deadStock;


            if ($can = Utils::userCan($this->user, 'product.view')) {
                return $can;
            }

            $query = Product::query();

            /*
             * This used to open with whereNotNull('slug'). The slug is now
             * required on save, but the filter stays gone: it was hiding nine
             * live products from the only screen that could have fixed them,
             * with no message and no filter the admin could switch off. A row
             * with something wrong with it belongs in the list, not out of it.
             */

            $query->leftJoin('product_views', 'products.id', '=', 'product_views.product_id');

            $query->orderBy('products.' . $orderBy, $type);

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }

            // if ($request->categories) {
            //     $ids = explode(",", $request->categories);

            //     $query = $query->join('product_categories as pc', function ($join) use ($ids) {
            //         $join->on('pc.product_id', '=', 'products.id');
            //         $join->whereIn('pc.category_id', $ids);
            //     });
            // }


            // if ($request->brands) {
            //     $ids = explode(",", $request->brands);

            //     $query = $query->whereIn('products.brand_id', $ids);
            // }

            if ($brand) {
                $query = $query->where('products.brand_id', $brand);
            }

            if($productId){
                $query = $query->where('products.id', $productId);
            }

            if($crosssellId){
                $query = $query->where('products.upsell_id', $crosssellId);
            }

            if ($recentlyAdded == 'true' || $recentlyAdded === true) {
                $query->where('products.created_at', '>=', now()->subDays(7));
            }

            if ($lang) {
                $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });

                $query = $query->with(['brand' => function ($query) use ($lang) {
                    $query->leftJoin('brand_langs as bl',
                        function ($join) use ($lang) {
                            $join->on('brands.id', '=', 'bl.brand_id');
                            $join->where('bl.lang', $lang);
                        })
                        ->select('brands.title', 'brands.id', 'bl.title');
                }]);

                $query = $query->with(['tax_rules' => function ($query) use ($lang) {
                    $query->leftJoin('tax_rule_langs as trl',
                        function ($join) use ($lang) {
                            $join->on('tax_rules.id', '=', 'trl.tax_rule_id');
                            $join->where('trl.lang', $lang);
                        })
                        ->select('tax_rules.title', 'tax_rules.id', 'trl.*');
                }]);

                $query = $query->with(['product_categories' => function($query) use ($lang){
                    $query->with(['category' => function($query) use ($lang) {
                        $query->leftJoin('category_langs as cl',
                            function ($join) use ($lang) {
                                $join->on('categories.id', '=', 'cl.category_id');
                                $join->where('cl.lang', $lang);
                            })
                            ->select('categories.*', 'cl.title', 'cl.meta_title', 'cl.meta_description', 'cl.meta_keywords');
                    }]);
                }]);

                $query->with([
                    'collection_with_products.product_collection'
                ]);

                if ($collectionId) {
                    $query->whereHas('collection_with_products.product_collection', function ($q) use ($collectionId) {
                        $q->where('id', $collectionId);
                    });
                }

                $query = $query->with(['product_inventories' => function($query) use ($lang){
                    $query->with(['inventory_attributes' => function($query) use ($lang) {
                        $query->with(['attribute_value' => function($query) use ($lang) {
                            $query->with(['attribute' => function($query) use ($lang){

                                $query->leftJoin('attribute_langs as al',
                                    function ($join) use ($lang) {
                                        $join->on('attributes.id', '=', 'al.attribute_id');
                                        $join->where('al.lang', $lang);
                                    })
                                    ->select('attributes.*', 'al.title');
                            }]);
                            $query->leftJoin('attribute_value_langs as avl',
                                function ($join) use ($lang) {
                                    $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                                    $join->where('avl.lang', $lang);
                                })
                                ->select('attribute_values.*', 'avl.title');
                        }]);
                    }, 'images.image']);
                }]);

                $query = $query->select('products.id', 'products.title', 'products.overview', 'products.description', 'products.procurement', 'products.slug', 'products.upsell_id', 'products.bundle_deal_id', 'products.image',
                    'products.unit', 'products.tax_rule_id', 'products.shipping_rule_id',
                    'products.brand_id', 'products.purchased',
                    'products.selling', 'products.discount_type', 'products.discount_value', 'products.offered', 'products.status',
                    'products.created_at', 'pl.title', 'pl.description',
                    'pl.overview', 'pl.unit', 'pl.badge',
                    'pl.meta_title', 'pl.meta_description', 'pl.meta_keywords', \DB::raw('COALESCE(product_views.views, 0) as views'));

                if ($categoryId) {
                    $query->whereHas('product_categories', function($q) use ($categoryId) {
                        $q->where('category_id', $categoryId);
                    });
                }

                if ($subcategoryId) {
                    $query->whereHas('subcategories', function ($q) use ($subcategoryId) {
                        $q->where('subcategory_id', $subcategoryId);
                    });
                }

                if (!is_null($status)) {
                    $query->where('products.status', $status);
                }

                if ($stock === 'in') {
                    $query->whereHas('product_inventories', function ($q) {
                        $q->selectRaw('SUM(quantity)')
                        ->groupBy('product_id')
                        ->havingRaw('SUM(quantity) > 0');
                    });
                }

                if ($stock === 'out') {
                    $query->where(function ($q) {
                        $q->whereDoesntHave('product_inventories')
                        ->orWhereHas('product_inventories', function ($qi) {
                            $qi->selectRaw('SUM(quantity)')
                                ->groupBy('product_id')
                                ->havingRaw('SUM(quantity) <= 0');
                        });
                    });
                }

                if ($search) {
                    $search = trim(strtolower($search));
                    $words = preg_split('/[\s\-]+/', $search);

                    $query->where(function ($q) use ($words) {
                        foreach ($words as $word) {
                            $q->whereRaw(
                                "LOWER(REPLACE(REPLACE(products.title, '-', ' '), '_', ' ')) LIKE ?",
                                ["%{$word}%"]
                            );
                        }
                    });
                }

                if ($onlyVariants == 'true' || $onlyVariants === true) {
                    $query->whereHas('product_inventories');
                }

                if ($highReturn == 'true' || $highReturn === true) {
                    $query->whereRaw('COALESCE(product_views.views, 0) > 100');
                }

                if ($deadStock == 'true' || $deadStock === true) {
                    $query->where(function ($q) {
                        $q->whereDoesntHave('product_inventories')
                        ->orWhereHas('product_inventories', function ($qi) {
                            $qi->selectRaw('SUM(quantity)')
                                ->groupBy('product_id')
                                ->havingRaw('SUM(quantity) <= 0');
                        });
                    });
                }

            } else {

                $query = $query->with(['product_inventories' => function($query) use ($lang){
                    $query->with(['inventory_attributes' => function($query) use ($lang) {
                        $query->with(['attribute_value' => function($query) use ($lang) {
                            $query->with(['attribute']);
                        }]);
                    }, 'images.image']);
                }]);


                $query = $query->with(['product_categories' => function($query){
                    $query->with(['category']);
                }]);

                $query->with([
                    'collection_with_products.product_collection'
                ]);

                if ($collectionId) {
                    $query->whereHas('collection_with_products.product_collection', function ($q) use ($collectionId) {
                        $q->where('id', $collectionId);
                    });
                }

                $query = $query->with('brand');
                $query = $query->with('tax_rules');

                $query = $query->select('products.id', 'products.title', 'products.overview', 'products.description', 'products.procurement','products.slug', 'products.upsell_id', 'products.bundle_deal_id', 'products.image',
                    'products.unit', 'products.tax_rule_id', 'products.shipping_rule_id',
                    'products.brand_id', 'products.purchased', 'products.selling', 'products.discount_type', 'products.discount_value',
                    'products.offered', 'products.status', 'products.created_at', 'products.meta_title', 'products.meta_description', 'products.meta_keywords', \DB::raw('COALESCE(product_views.views, 0) as views'));

                if ($categoryId) {
                    $query->whereHas('product_categories', function($q) use ($categoryId) {
                        $q->where('category_id', $categoryId);
                    });
                }

                if ($subcategoryId) {
                    $query->whereHas('subcategories', function ($q) use ($subcategoryId) {
                        $q->where('subcategory_id', $subcategoryId);
                    });
                }

                if (!is_null($status)) {
                    $query->where('products.status', $status);
                }

                if ($stock === 'in') {
                    $query->whereHas('product_inventories', function ($q) {
                        $q->selectRaw('SUM(quantity)')
                        ->groupBy('product_id')
                        ->havingRaw('SUM(quantity) > 0');
                    });
                }

                if ($stock === 'out') {
                    $query->where(function ($q) {
                        $q->whereDoesntHave('product_inventories')
                        ->orWhereHas('product_inventories', function ($qi) {
                            $qi->selectRaw('SUM(quantity)')
                                ->groupBy('product_id')
                                ->havingRaw('SUM(quantity) <= 0');
                        });
                    });
                }

                if ($search) {
                    $search = trim(strtolower($search));
                    $words = preg_split('/[\s\-]+/', $search);

                    $query->where(function ($q) use ($words) {
                        foreach ($words as $word) {
                            $q->whereRaw(
                                "LOWER(REPLACE(REPLACE(products.title, '-', ' '), '_', ' ')) LIKE ?",
                                ["%{$word}%"]
                            );
                        }
                    });
                }

                if ($onlyVariants == 'true' || $onlyVariants === true) {
                    $query->whereHas('product_inventories');
                }

                if ($highReturn == 'true' || $highReturn === true) {
                    $query->whereRaw('COALESCE(product_views.views, 0) > 100');
                }

                if ($deadStock == 'true' || $deadStock === true) {
                    $query->where(function ($q) {
                        $q->whereDoesntHave('product_inventories')
                        ->orWhereHas('product_inventories', function ($qi) {
                            $qi->selectRaw('SUM(quantity)')
                                ->groupBy('product_id')
                                ->havingRaw('SUM(quantity) <= 0');
                        });
                    });
                }
            }



            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            $this->withoutStorefrontAppends($data);

            foreach ($data as $item) {
                if ($request->time_zone) {
                    $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                }else{
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function dropDownData(Request $request)
    {

        try {
            $lang = $request->header('language');

            if ($lang) {

                $res['brands'] = Brand::leftJoin('brand_langs as bl',
                    function ($join) use ($lang) {
                        $join->on('brands.id', '=', 'bl.brand_id');
                        $join->where('bl.lang', $lang);
                    })->select('brands.title', 'brands.id', 'bl.title')
                    ->orderBy('brands.created_at', 'ASC')->get();

                $res['categories'] = Category::whereNull('categories.parent')
                ->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('categories.id', '=', 'cl.category_id');
                    $join->where('cl.lang', $lang);
                })
                ->with(['child' => function ($query) use ($lang) {
                    $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                        $join->on('categories.id', '=', 'cl.category_id');
                        $join->where('cl.lang', $lang);
                    })
                    ->select('categories.id', 'categories.parent', 'categories.title', 'cl.title')
                    ->orderBy('categories.created_at', 'ASC');
                }])
                ->select('categories.id', 'categories.title', 'cl.title')
                ->orderBy('categories.created_at', 'ASC')
                ->get();

                $res['tax_rules'] = TaxRules::leftJoin('tax_rule_langs as trl',
                    function ($join) use ($lang) {
                        $join->on('tax_rules.id', '=', 'trl.tax_rule_id');
                        $join->where('trl.lang', $lang);
                    })->select('tax_rules.title', 'tax_rules.id', 'trl.title')
                    ->orderBy('tax_rules.created_at', 'ASC')->get();

                $res['shipping_rules'] = ShippingRule::leftJoin('shipping_rule_langs as srl',
                    function ($join) use ($lang) {
                        $join->on('shipping_rules.id', '=', 'srl.shipping_rule_id')->where('srl.lang', $lang);
                    })->select('shipping_rules.title', 'shipping_rules.id', 'srl.title')
                    ->orderBy('shipping_rules.created_at', 'ASC')->get();

                $res['product_collections'] = ProductCollection::leftJoin('product_collection_langs as pcl',
                    function ($join) use ($lang) {
                        $join->on('product_collections.id', '=', 'pcl.product_collection_id')
                            ->where('pcl.lang', $lang);
                    })->select('product_collections.title', 'product_collections.id', 'pcl.title')
                    ->orderBy('product_collections.created_at', 'ASC')->get();

                $res['bundle_deals'] = BundleDeal::leftJoin('bundle_deal_langs as bdl',
                    function ($join) use ($lang) {
                        $join->on('bundle_deals.id', '=', 'bdl.bundle_deal_id')
                            ->where('bdl.lang', $lang);
                    })->select('bundle_deals.title', 'bundle_deals.id', 'bdl.title')
                    ->orderBy('bundle_deals.created_at', 'ASC')->get();


                $res['attributes'] = Attribute::with(['values' => function ($query) use ($lang) {
                    $query->leftJoin('attribute_value_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('attribute_values.*', 'avl.title');
                }])->leftJoin('attribute_langs as al',
                    function ($join) use ($lang) {
                        $join->on('attributes.id', '=', 'al.attribute_id')
                            ->where('al.lang', $lang);
                    })->select('attributes.title', 'attributes.id', 'al.title')
                    ->orderBy('attributes.created_at', 'ASC')->get();

            } else {
                $res['brands'] = Brand::orderBy('created_at', 'ASC')->get(['id', 'title']);
                $res['categories'] = Category::whereNull('parent')->with(['child' => function ($query) {
                    $query->select('id', 'title', 'parent')->orderBy('created_at', 'ASC');
                }])
                ->select('id', 'title')
                ->orderBy('created_at', 'ASC')
                ->get();
                $res['tax_rules'] = TaxRules::orderBy('created_at', 'ASC')->get(['id', 'title']);
                $res['shipping_rules'] = ShippingRule::orderBy('created_at', 'ASC')->get(['id', 'title']);
                $res['product_collections'] = ProductCollection::orderBy('created_at', 'ASC')->get(['id', 'title']);
                $res['bundle_deals'] = BundleDeal::orderBy('created_at', 'ASC')->get(['id', 'title']);
                $res['attributes'] = Attribute::with('values')
                    ->orderBy('created_at', 'ASC')->get(['id', 'title']);
            }

            return response()->json(new Response($request->token, $res));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

    }


    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.view')) {
                return $can;
            }

            $query = Product::query();
		 
            $query = $query->with('product_categories.category');


            $query = $query->with(['product_images.attributes', 'specifications.group', 'feature_images']);

            $query = $query->with('flash_sale_product.flash_sale');
            $query = $query->with('product_collections');

            if ($lang) {
                $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });
                $query = $query->select('products.*', 'pl.title', 'pl.description',
                    'pl.overview', 'pl.unit', 'pl.badge',
                    'pl.meta_title', 'pl.meta_description', 'pl.meta_keywords');
            }

            $data = $query->find($id);

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $data)) {
                return $isOwner;
            }

            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }


            $primaryProductCat = ProductCategory::where('primary', true)->where('product_id', $id)->first();

            if ($primaryProductCat) {
                $data['primary_category_id'] = $primaryProductCat->category_id;
            } else {
                $data['primary_category_id'] = null;
            }

            $subcategories = ProductSubcategory::where('product_id', $id)
            ->leftJoin('categories', 'categories.id', '=', 'product_subcategories.subcategory_id')
            ->select('categories.id', 'categories.title', 'categories.parent')
            ->get();

            $subGrouped = [];

            foreach ($subcategories as $sub) {
                $subGrouped[$sub->parent][] = $sub;
            }

            foreach ($data->product_categories as $pc) {
                $catId = $pc->category_id;
                $pc->category->child = $subGrouped[$catId] ?? [];
            }

            $groupedSpecifications = [];
            foreach ($data->specifications as $specification) {
                $groupTitle = optional($specification->group)->title;
                if (!$groupTitle) {
                    continue;
                }

                $groupIndex = collect($groupedSpecifications)->search(function ($group) use ($groupTitle) {
                    return $group['group'] === $groupTitle;
                });

                if ($groupIndex === false) {
                    $groupedSpecifications[] = [
                        'group' => $groupTitle,
                        'items' => []
                    ];
                    $groupIndex =
                        count($groupedSpecifications) - 1;
                }

                $groupedSpecifications[$groupIndex]['items'][] = [
                    'id' => $specification->id,
                    'value' => $specification->value,
                    'sort_order' => $specification->sort_order
                ];
            }

            $data->setRelation(
                'specifications',
                collect($groupedSpecifications)
            );

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * The product columns a save is allowed to write.
     *
     * The edit path used to hand array_filter($request->all()) straight to
     * Product::where(...)->update(), and a query-builder update honours no
     * $fillable, so every scalar the client sent became a column write. That
     * was two problems at once. Anything the request happened to carry that is
     * not a column killed the whole save - CheckForAllScopes merges a fresh
     * `token` into the request whenever the admin's access token is within a
     * minute of expiring, and the update then died on "Unknown column 'token'",
     * losing the edit. And the columns that are real but not the admin's to set
     * - rating, review_count, admin_id, the timestamps - were writable from the
     * browser.
     *
     * Everything is intersected with the model's own $fillable, then the fields
     * the server owns are removed. Values are set by the caller after this, so
     * removing them here cannot drop a legitimate write.
     */
    private function writableProductData(Request $request)
    {
        $scalars = array_filter($request->all(), function ($element) {
            return !is_array($element);
        });

        $writable = array_intersect_key(
            $scalars,
            array_flip((new Product)->getFillable())
        );

        // Server-owned: identity, ownership, aggregates, timestamps.
        unset(
            $writable['id'],
            $writable['admin_id'],
            $writable['rating'],
            $writable['review_count'],
            $writable['created_at'],
            $writable['updated_at']
        );

        return $writable;
    }

    /**
     * Drop the three appended attributes from an admin listing.
     *
     * Product declares $appends = ['is_wishlisted',
     * 'collection_background_image', 'is_new'], and each accessor runs once
     * per serialised row: collection_background_image always queries, is_new
     * queries whenever created_at was not selected. They exist for the
     * storefront, and no admin screen reads any of them, so on a list they are
     * pure cost -- 302 queries for one page of the bulk editor.
     *
     * makeHidden() stops the accessor being called at all, not just its
     * output being printed.
     */
    private function withoutStorefrontAppends($products)
    {
        $appends = ['is_wishlisted', 'collection_background_image', 'is_new'];

        foreach ($products as $product) {
            $product->makeHidden($appends);
        }

        return $products;
    }


    /**
     * The two VAT-inclusive display columns, derived from a tax rule.
     *
     * Shared by the product save and the bulk editor. It used to live only in
     * action(), so a price changed through the bulk editor left both columns
     * describing the old price -- the exact drift QA audit TAX-02 was raised
     * to clear, reopened by a different door. One copy means the two entry
     * points cannot disagree.
     *
     * A product with no offer has no VAT-inclusive offer price: the column is
     * null, not 0.00.
     */
    private function vatInclusiveColumns($taxRule, $selling, $offered)
    {
        $selling = (float)$selling;
        $offered = (float)$offered;

        $inclusive = Pricing::round(
            $selling + Pricing::unitTax($taxRule, $selling, true)
        );

        $inclusiveOffer = $offered > 0
            ? Pricing::round($offered + Pricing::unitTax($taxRule, $offered, true))
            : null;

        return [
            'vatInclusivePrice' => number_format($inclusive, 2, '.', ''),
            'vatInclusiveOfferedPrice' => is_null($inclusiveOffer)
                ? null
                : number_format($inclusiveOffer, 2, '.', ''),
        ];
    }

    public function action(Request $request, $id = null)
    {
        try {
            $request->request->remove('is_wishlisted');
            $request->request->remove('collection_background_image');
            $request->request->remove('is_new');


            $lang = $request->header('language');

            $validate = Validation::productMain($request);
            if ($validate) {
                return response()->json($validate);
            }

            $primaryCategory = $request->primary_category_id;

            /*
             * Only a real slug can collide with a real slug.
             *
             * Laravel turns where('slug', null) into `slug IS NULL`, and there
             * are slugless rows in this table, so a save that omitted the slug
             * was answered with "Slug already exists." -- a wrong diagnosis of
             * a field the server had never said was required. It is required
             * now, and this only looks for a clash when there is something to
             * clash with.
             */
            $bySlug = filled($request['slug'])
                ? Product::where('slug', $request['slug'])->first()
                : null;
            
            
            /*
             * VAT-inclusive display prices.
             *
             * QA audit TAX-02. This used to be "if ($tax_rule_id == 1) { price
             * + 23% }" — the rate hardcoded and tied to one row id, so the
             * tax_rules table was effectively unused. Adding a 13.5% or 9% Irish
             * rate produced wrong figures, and editing rule 1 recalculated
             * nothing.
             *
             * The rate and its type now come from the selected rule.
             */
            $vatColumns = $this->vatInclusiveColumns(
                TaxRules::find($request->tax_rule_id),
                $request->selling,
                $request->offered ?? 0
            );

            $request['vatInclusivePrice'] = $vatColumns['vatInclusivePrice'];
            $request['vatInclusiveOfferedPrice'] = $vatColumns['vatInclusiveOfferedPrice'];

            /*
             * Only touch excludeVAT when the request actually carries it.
             *
             * This was an unconditional assignment, so a client that left the
             * field out wrote null into a NOT NULL column: under
             * STRICT_TRANS_TABLES the whole save died with a generic
             * "Something went wrong", and without it the flag silently reset
             * to 0 and an ex-VAT product started being sold inc-VAT. Leaving
             * the key out of the payload lets writableProductData() drop it,
             * and the column keeps what it had.
             */
            /*
             * "Sell only in": the countries this product may be sold to, sent
             * as a list of codes. Stored as "GB,IE", or NULL for anywhere;
             * codes the country list does not know are dropped. Checked at
             * checkout by Helper\Shipping, never by the page alone.
             */
            if ($request->has('sell_countries')) {
                $request['sell_countries'] = Shipping::normaliseCountries($request->input('sell_countries'));
            }

            if ($request->has('excludeVAT') && !is_null($request->excludeVAT)) {
                $request['excludeVAT'] = $request->excludeVAT;
            } else {
                $request->request->remove('excludeVAT');
            }


            if ($id) {
                if ($can = Utils::userCan($this->user, 'product.edit')) {
                    return $can;
                }

                $existing = Product::find($id);
                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }

                if ($bySlug && $bySlug['id'] != $id) {
                    return response()->json(Validation::error($request->token,
                        __('lang.slug_exists', [], $lang)));
                }

                $filtered = $this->writableProductData($request);

                /*
                 * A product save is five writes - the row, its categories, its
                 * collections, its specifications, and then the inventory the
                 * form posts separately. None of them shared a transaction, so
                 * a failure partway through left the product half-updated:
                 * most visibly, categories are deleted before they are
                 * reinserted, so a failure between the two published a product
                 * that belonged to no category at all.
                 *
                 * Opened here rather than at the top of the method so that the
                 * permission, ownership and duplicate-slug refusals above stay
                 * outside it.
                 */
                DB::beginTransaction();


                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($filtered, [
                        'description', 'title', 'overview', 'unit', 'badge', 'meta_title', 'meta_description', 'meta_keywords'
                    ]);
                    Product::where('id', $id)->update($mainData);
                    $existingLang = ProductLang::where('product_id', $id)
                        ->where('lang', $lang)->first();

                    if (!$existingLang) {
                        $langData['product_id'] = $id;
                        $langData['lang'] = $lang;
                        ProductLang::create($langData);

                    } else {

                        ProductLang::where('id', $existingLang->id)->update($langData);
                    }
                } else {
                    Product::where('id', $id)->update($filtered);
                }

            } else {

                if ($can = Utils::userCan($this->user, 'product.create')) {
                    return $can;
                }

                if ($bySlug) {
                    return response()->json(Validation::error($request->token,
                        __('lang.slug_exists', [], $lang)));
                }

                DB::beginTransaction();

                /*
                 * The featured image is uploaded before the product exists, so
                 * the form already holds a filename by the time it saves. This
                 * used to overwrite it with the placeholder unconditionally,
                 * which is why a product whose image had been chosen first
                 * still came out blank.
                 */
                $request['image'] = $request->filled('image')
                    ? $request->image
                    : Config::get('constants.media.DEFAULT_IMAGE');
                $request['admin_id'] = $request->user()->id;
                $request['id'] = Utils::idGenerator(new Product);


                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData(array_merge(
                        $this->writableProductData($request),
                        [
                            'id' => $request['id'],
                            'admin_id' => $request['admin_id'],
                        ]
                    ), [
                        'description', 'title', 'overview', 'unit', 'badge', 'meta_title', 'meta_description', 'meta_keywords'
                    ]);
                    $product = Product::create($mainData);

                    $langData['product_id'] = $product->id;
                    $langData['lang'] = $lang;
                    ProductLang::create($langData);
                    $id = $product->id;

                } else {
                    $product = Product::create(array_merge(
                        $this->writableProductData($request),
                        [
                            'id' => $request['id'],
                            'admin_id' => $request['admin_id'],
                        ]
                    ));
                    $id = $product->id;
                }
            }

            //Product categories

            if (!is_null($request['product_categories'])) {
                ProductCategory::where('product_id', $id)->delete();
                $productCategoriesIds = $request['product_categories'];
                $now = Carbon::now();
                $productCategories = [];
                foreach ($productCategoriesIds as $i) {

                    $pc = [
                        'category_id' => $i,
                        'product_id' => $id,
                        'updated_at' => $now,
                        'created_at' => $now
                    ];

                    if ($primaryCategory && $i == $primaryCategory) {
                        $pc['primary'] = true;
                    } else {
                        $pc['primary'] = false;

                    }

                    array_push($productCategories, $pc);
                }
                ProductCategory::insert($productCategories);
            }

            //Product collection
            if (!is_null($request['product_collections'])) {
                CollectionWithProduct::where('product_id', $id)->delete();
                $productCollectionIds = $request['product_collections'];
                $now = Carbon::now();
                $productCollections = [];
                foreach ($productCollectionIds as $i) {
                    array_push($productCollections,
                        [
                            'product_collection_id' => $i,
                            'product_id' => $id,
                            'updated_at' => $now,
                            'created_at' => $now
                        ]);
                }
                CollectionWithProduct::insert($productCollections);
            }

            /*
             * Product subcategories.
             *
             * find() reads these and hangs them under each category as `child`,
             * and the bulk editor writes them -- but action(), the only save
             * the product form uses, ignored the key entirely, so subcategories
             * chosen on the product page were silently dropped. Rewritten
             * wholesale like the categories and collections above, and inside
             * the same transaction.
             *
             * Each row carries its parent category as well as the subcategory,
             * matching the shape the bulk editor already sends:
             * [['id' => <subcategory>, 'category_id' => <parent>], ...]
             */
            if (!is_null($request['subcategories'])) {
                ProductSubcategory::where('product_id', $id)->delete();

                $now = Carbon::now();
                $subcategories = [];

                foreach ($request['subcategories'] as $subcategory) {

                    if (empty($subcategory['id'])) {
                        continue;
                    }

                    $subcategories[] = [
                        'product_id' => $id,
                        'category_id' => $subcategory['category_id'] ?? null,
                        'subcategory_id' => $subcategory['id'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ];
                }

                if (count($subcategories) > 0) {
                    ProductSubcategory::insert($subcategories);
                }
            }


            $productQuery = Product::with(['product_images.attributes'])
                ->with('product_categories')
                ->with('product_collections');

            if ($request->filled('specifications')) {

                ProductSpecification::where(
                    'product_id',
                    $id
                )->delete();

                foreach ($request->specifications as $groupIndex => $group) {
                    if (
                        empty($group['group']) ||
                        empty($group['items'])
                    ) {
                        continue;
                    }

                    $specificationGroup = SpecificationGroup::firstOrCreate([
                        'title' => trim($group['group'])
                    ], [
                        'sort_order' => $groupIndex
                    ]);

                    foreach ($group['items'] as $itemIndex => $item) {
                        if (empty($item['value'])) {
                            continue;
                        }
                        ProductSpecification::create([
                            'product_id' => $id,
                            'group_id' => $specificationGroup->id,
                            'label' => trim($item['value']),
                            'value' => trim($item['value']),
                            'sort_order' => $itemIndex
                        ]);
                    }
                }
            }

            DB::commit();

            if ($lang) {
                $productQuery = $productQuery->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });
                $productQuery = $productQuery->select('products.*', 'pl.title', 'pl.description',
                    'pl.overview', 'pl.unit', 'pl.badge',
                    'pl.meta_title', 'pl.meta_description', 'pl.meta_keywords');
            }

            $product = $productQuery->find($id);

            $primaryProductCat = ProductCategory::where('primary', true)->where('product_id', $id)->first();

            if ($primaryProductCat) {
                $product['primary_category_id'] = $primaryProductCat->category_id;
            } else {
                $product['primary_category_id'] = null;
            }

            return response()->json(new Response($request->token, $product));

        } catch (\Exception $ex) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * A product on a past order is retired, not destroyed.
     *
     * This used to open with OrderedProduct::where('product_id', $id)->delete()
     * so that the FK on ordered_products would let the product row go. Those
     * rows ARE the order: its lines, their quantities and the price actually
     * charged. Deleting a discontinued product silently rewrote history --
     * order totals stopped reconciling with their lines, and an invoice
     * reprinted afterwards was missing items.
     *
     * The stock save already settled this question (QA audit STK-04): a variant
     * that appears on an order is retired rather than deleted, because the
     * history has to keep resolving. A product is the same, one level up. It is
     * unpublished and pulled out of every storefront surface -- flash sales,
     * collections, the home slider, baskets, wishlists, compare lists -- so it
     * disappears for customers exactly as a delete would, and the row stays so
     * the orders that reference it still make sense.
     *
     * The response names what was retired, so the admin is told rather than
     * left to notice.
     */
    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'product.delete')) {
                return $can;
            }


            $ids = array_values(array_filter(array_map('trim', explode(",", $id)), 'strlen'));

            /*
             * Resolve the whole selection before touching any of it.
             *
             * This loop used to find each product as it went and return
             * "nothing found" the moment one id did not resolve -- with no
             * transaction open and everything before it already destroyed. The
             * admin saw an error and reasonably assumed nothing had happened.
             */
            $products = Product::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($ids as $i) {

                if (!$products->has($i)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $products->get($i))) {
                    return $isOwner;
                }
            }

            /*
             * The transaction covers the database. Image and video files are
             * removed from disk inside it and a rollback cannot bring them
             * back -- no file operation in this codebase is transactional. The
             * rows are what has to stay consistent.
             */
            DB::beginTransaction();

            $retired = [];

            foreach ($ids as $i) {

                $product = $products->get($i);

                /*
                 * Anything a customer can still see is removed either way. The
                 * difference between retiring and deleting is only what happens
                 * to the product's own records below.
                 */
                FlashSaleProduct::where('product_id', $i)->delete();

                CollectionWithProduct::where('product_id', $i)->delete();

                HomeSliderSourceProduct::where('product_id', $i)->delete();

                Cart::where('product_id', $i)->delete();
                CompareList::where('product_id', $i)->delete();
                UserWishlist::where('product_id', $i)->delete();

                if (OrderedProduct::where('product_id', $i)->exists()) {

                    // Off sale, out of every listing, still joinable from
                    // ordered_products.
                    Product::where('id', $i)->update([
                        'status' => Config::get('constants.status.PRIVATE'),
                    ]);

                    $retired[] = $product->title;

                    continue;
                }

                $product_inventories = UpdatedInventory::where('product_id', $i)->get();

                ProductCategory::where('product_id', $i)->delete();

                foreach ($product_inventories as $inv) {
                    InventoryAttribute::where('inventory_id', $inv->id)->delete();
                }


                $product_images = ProductImage::where(['product_id' => $i])->get();

                foreach ($product_images as $img) {

                    ProductImageAttribute::where('product_image_id', $img->id)->delete();

                    $img->delete();

                    FileHelper::deleteFile($img->image);
                }

                UpdatedInventory::where('product_id', $i)->delete();

                $description_images = WysiwygImage::where('item_id', $i)->get();
                foreach ($description_images as $di) {
                    $di->delete();
                    FileHelper::deleteFile($di->image);
                }

                $reviewImages = ReviewImage::leftJoin('rating_reviews', 'review_images.rating_review_id', '=', 'rating_reviews.id')
                    ->where('rating_reviews.product_id', $i);

                $rimages = $reviewImages->get();
                foreach ($rimages as $img) {
                    FileHelper::deleteFile($img->image);
                }

                $reviewImages->delete();

                RatingReview::where('product_id', $i)->delete();
                ProductLang::where('product_id', $i)->delete();

                if ($product->delete()) {
                    FileHelper::deleteFile($product->image);
                    FileHelper::deleteFile($product->video);
                    FileHelper::deleteFile($product->video_thumb);
                }
            }

            DB::commit();

            $message = '';

            if (count($retired) > 0) {
                $message = __('lang.product_retired_not_deleted', [
                    'products' => implode(', ', $retired),
                ], $lang);
            }

            return response()->json(new Response($request->token, [
                'retired' => $retired,
            ], 200, $message));

        } catch (\Exception $ex) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function allImages(Request $request, $productId)
    {
        // Its two neighbours (upload-images, delete-image) both require
        // product.edit; reading the gallery required nothing.
        if ($can = Utils::userCan($this->user, 'product.view')) {
            return $can;
        }

        /*
         * id breaks the tie. Every gallery row written before this was fixed
         * has created_at NULL, and rows written in one batch share a second,
         * so created_at alone left the order to MySQL.
         */
        $data = ProductImage::where(['product_id' => $productId])
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        return response()->json(new Response($request->token, $data));
    }


    public function deleteProductImage(Request $request, $productImageId)
    {

        $lang = $request->header('language');

        if ($can = Utils::userCan($this->user, 'product.edit')) {
            return $can;
        }

        $product_image = ProductImage::find($productImageId);

        if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product_image)) {
            return $isOwner;
        }

        if (is_null($product_image)) {
            return response()->json(Validation::nothingFoundLang($lang));
        }

        ProductImageAttribute::where('product_image_id', $product_image->id)->delete();


        if ($product_image->delete()) {
            if (config('env.media.STORAGE') != config('env.media.URL')) {
                FileHelper::deleteFile($product_image->image);
            }

            $images = ProductImage::with('attributes')
                ->where('product_id', $product_image->product_id)
                ->get();
            return response()->json(new Response($request->token, $images));
        }
        // json($data, $status) - the two arguments were the wrong way round,
        // so this branch handed a Response object in as the HTTP status code.
        return response()->json(Validation::error($request->token));
    }


    public function multipleImageUpload(Request $request, $productId)
    {
        try {
            $lang = $request->header('language');


            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $product = Product::find($productId);

            if (is_null($product)) {
                return response()->json(Validation::noData(201, null, 'multiple_image'));
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                return $isOwner;
            }

            // Checking if the image resource is URL
            if (config('env.media.STORAGE') == config('env.media.URL')) {
                $validate = Validation::image($request, 'multiple_image');
                if ($validate) {
                    return response()->json($validate);
                }

                $image_info = FileHelper::uploadImage($request['photo'], 'product');

                $product_image['image'] = $image_info['name'];
                $product_image['admin_id'] = $request->user()->id;
                $product_image['product_id'] = $productId;

                ProductImage::create($product_image);
                $images = ProductImage::where('product_id', $productId)->get();

                return response()->json(new Response($request->token, $images));
            }


            if ($request->hasFile('images')) {
                $images_arr = [];

                foreach ($request->images as $img) {

                    $validate = Validation::multipleImages(['photo' => $img], $request->token);
                    if ($validate) {
                        return response()->json($validate);
                    }

                    $image_info = FileHelper::uploadImage($img, 'product');

                    $product_image['image'] = $image_info['name'];
                    $product_image['admin_id'] = $request->user()->id;
                    $product_image['product_id'] = $productId;
                    /*
                     * ProductImage::insert() bypasses Eloquent's timestamps, so
                     * every one of the 231 gallery rows in this database has
                     * created_at NULL -- and allImages() reads them back with
                     * orderBy('created_at','ASC'). Gallery order was whatever
                     * MySQL felt like, which is why dragging images into an
                     * order never stuck. Set explicitly, since the bulk insert
                     * is worth keeping.
                     */
                    $product_image['created_at'] = Carbon::now();
                    $product_image['updated_at'] = Carbon::now();

                    array_push($images_arr, $product_image);
                }

                ProductImage::insert($images_arr);
                $images = ProductImage::with('attributes')
                    ->where('product_id', $productId)
                    ->get();

                return response()->json(new Response($request->token, $images));
            }

            return response()->json(Validation::error($request->token,
                __('lang.invalid_parameter', [], $lang),
                'multiple_image'));
            // return response()->json(Validation::invalid_parameter($request->token));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage(), 'multiple_image'));
        }
    }

    public function uploadVideo(Request $request, $id = null)
    {

        try {
            $lang = $request->header('language');


            $validate = Validation::video($request);
            if ($validate) {
                return response()->json($validate);
            }

            $image_info = FileHelper::uploadImage($request['video_file'], 'product-video', false);
            $thumb_info = FileHelper::uploadImage($request['thumb'], 'product-video-thumb', false);
            $request['video'] = null;


            $product = $id ? Product::with('product_images.attributes')

                ->with('flash_sale_product.flash_sale')
                ->with('product_collections')
                ->find($id) : null;

            if (is_null($product)) {

                if ($can = Utils::userCan($this->user, 'product.create')) {
                    return $can;
                }

                $request['admin_id'] = $request->user()->id;
                $request['id'] = Utils::idGenerator(new Product);
                $request['video'] = $image_info['name'];
                $request['video_thumb'] = $thumb_info['name'];

                $product = Product::create($request->all());

                $id = $product->id;

            } else {

                if ($can = Utils::userCan($this->user, 'product.edit')) {
                    return $can;
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                    return $isOwner;
                }

                $video = $product->video;
                $thumb = $product->video_thumb;
                if ($product->update([
                    'video' => $image_info['name'],
                    'video_thumb' => $thumb_info['name']
                ])) {
                    FileHelper::deleteFile($video);
                    FileHelper::deleteFile($thumb);
                }
            }


            $query = Product::query();
            $query = $query->with(['product_images.attributes']);
            $query = $query->with('product_categories.category');
            $query = $query->with('flash_sale_product.flash_sale');
            $query = $query->with('product_collections');

            if ($lang) {
                $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });
                $query = $query->select('products.*', 'pl.title', 'pl.description',
                    'pl.overview', 'pl.unit', 'pl.badge',
                    'pl.meta_title', 'pl.meta_description', 'pl.meta_keywords');
            }

            $data = $query->find($id);

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function upload(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');


            $validate = Validation::image($request);
            if ($validate) {
                return response()->json($validate);
            }

            $image_info = FileHelper::uploadImage($request['photo'], 'product');
            $request['image'] = $image_info['name'];

            $product = $id ? Product::with('product_images')
                ->with('flash_sale_product.flash_sale')
                ->with('product_collections')
                ->find($id) : null;

            if (is_null($product)) {

                if ($can = Utils::userCan($this->user, 'product.create')) {
                    return $can;
                }

                /*
                 * Choosing the featured image before the product has been
                 * saved once.
                 *
                 * This used to create the product here and now, from whatever
                 * the upload form carried - which is the product id, the tax
                 * rule and the shipping rule, all of them empty strings on a
                 * product being added. Those are NOT NULL columns, so the
                 * insert was rejected and the admin got "Something went wrong"
                 * for picking an image. The uploaded file stayed on disk.
                 *
                 * Even when it did insert it was wrong: the caller never read
                 * the returned id back into the form, so saving the product
                 * afterwards created a second row and abandoned the first.
                 *
                 * Nothing is created now. The filename goes back to the form,
                 * which sends it with the save, and action() keeps it instead
                 * of overwriting it with the placeholder.
                 */
                return response()->json(new Response($request->token, [
                    'image' => $image_info['name'],
                ]));

            } else {

                if ($can = Utils::userCan($this->user, 'product.edit')) {
                    return $can;
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                    return $isOwner;
                }

                $image = $product->image;
                if ($product->update($request->all())) {
                    FileHelper::deleteFile($image);
                }
            }


            $query = Product::query();
            $query = $query->with(['product_images.attributes']);
            $query = $query->with('product_categories.category');
            $query = $query->with('flash_sale_product.flash_sale');
            $query = $query->with('product_collections');

            if ($lang) {
                $query = $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('pl.product_id', '=', 'products.id');
                    $join->where('pl.lang', $lang);
                });
                $query = $query->select('products.*', 'pl.title', 'pl.description',
                    'pl.overview', 'pl.unit', 'pl.badge',
                    'pl.meta_title', 'pl.meta_description', 'pl.meta_keywords');
            }

            $data = $query->find($id);


            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Copy a product as a starting point for a new one.
     *
     * Two things were wrong. It ran no Utils::userCan() check, so the
     * $can('product','create') gate on the Products grid was decoration -- the
     * endpoint answered anyone signed in. And what it produced was not a copy:
     * it replicated the row and synced the categories, leaving stock, gallery
     * images, specifications, collections, subcategories and feature images
     * behind, so the admin landed on what looked like a broken product.
     *
     * Everything that describes the product is copied. Promotional placements
     * are not -- flash sales, clearances, cross-sell and upsell assignments,
     * bundles -- because those are campaigns that were aimed at the original,
     * and a copy silently joining a live promotion is not something anyone
     * asked for. Reviews, ratings and view counts belong to the original too.
     *
     * The copy is always private. A product called "... (Copy)" appearing on
     * the storefront the moment the button is pressed is never what was meant.
     */
    public function duplicate(Request $request, Product $product)
    {
        try {
            if ($can = Utils::userCan($this->user, 'product.create')) {
                return $can;
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                return $isOwner;
            }

            DB::beginTransaction();

            $newProduct = $product->replicate();
            $newProduct->title = $product->title . ' (Copy)';
            $newProduct->slug = ($product->slug ?: Str::slug($product->title) ?: 'product')
                . '-copy-' . Str::random(4);
            $newProduct->rating = 0;
            $newProduct->review_count = 0;
            $newProduct->status = Config::get('constants.status.PRIVATE');
            $newProduct->admin_id = $this->user->id;
            $newProduct->save();

            $now = Carbon::now();
            $newId = $newProduct->id;

            // Categories, keeping which one is primary.
            foreach (ProductCategory::where('product_id', $product->id)->get() as $row) {
                ProductCategory::insert([
                    'product_id' => $newId,
                    'category_id' => $row->category_id,
                    'primary' => $row->primary,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (ProductSubcategory::where('product_id', $product->id)->get() as $row) {
                ProductSubcategory::insert([
                    'product_id' => $newId,
                    'category_id' => $row->category_id,
                    'subcategory_id' => $row->subcategory_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (CollectionWithProduct::where('product_id', $product->id)->get() as $row) {
                CollectionWithProduct::insert([
                    'product_id' => $newId,
                    'product_collection_id' => $row->product_collection_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (ProductSpecification::where('product_id', $product->id)->get() as $row) {
                ProductSpecification::create([
                    'product_id' => $newId,
                    'group_id' => $row->group_id,
                    'label' => $row->label,
                    'value' => $row->value,
                    'sort_order' => $row->sort_order,
                ]);
            }

            foreach (ProductFeatureImage::where('product_id', $product->id)->get() as $row) {
                ProductFeatureImage::create([
                    'product_id' => $newId,
                    'image' => $row->image,
                    'sort_order' => $row->sort_order,
                ]);
            }

            foreach (ProductLang::where('product_id', $product->id)->get() as $row) {
                $copy = $row->replicate();
                $copy->product_id = $newId;
                $copy->title = $row->title . ' (Copy)';
                $copy->save();
            }

            /*
             * Gallery images share the file on disk rather than copying it.
             * The row is what the product owns. Worth knowing if image
             * deletion ever starts removing files for products it was not
             * asked about.
             */
            $imageMap = [];
            foreach (ProductImage::where('product_id', $product->id)->get() as $row) {
                $copy = ProductImage::create([
                    'product_id' => $newId,
                    'image' => $row->image,
                    'admin_id' => $this->user->id,
                ]);

                $imageMap[$row->id] = $copy->id;
            }

            // Stock, with each variant's attribute values and image mapping.
            foreach (UpdatedInventory::where('product_id', $product->id)->get() as $row) {

                $copy = UpdatedInventory::create([
                    'product_id' => $newId,
                    'sku' => $row->sku . '-COPY-' . Str::upper(Str::random(4)),
                    'price' => $row->price,
                    'quantity' => $row->quantity,
                    'imei' => $row->imei,
                    'barcode' => $row->barcode,
                    'is_active' => $row->is_active,
                ]);

                $attributes = InventoryAttribute::where('inventory_id', $row->id)
                    ->get(['attribute_value_id']);

                foreach ($attributes as $attribute) {
                    InventoryAttribute::insert([
                        'inventory_id' => $copy->id,
                        'attribute_value_id' => $attribute->attribute_value_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                foreach (InventoryImage::where('inventory_id', $row->id)->get() as $mapping) {

                    if (!isset($imageMap[$mapping->product_image_id])) {
                        continue;
                    }

                    InventoryImage::insert([
                        'inventory_id' => $copy->id,
                        'product_image_id' => $imageMap[$mapping->product_image_id],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::commit();

            // The raw model, not the Response envelope: the Products grid reads
            // response.data.id straight off this.
            return response()->json($newProduct->fresh());

        } catch (\Exception $ex) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
	
    /**
     * Every product as id and title, for a picker.
     *
     * Read through the query builder rather than Eloquent on purpose. Product
     * declares $appends = ['is_wishlisted', 'collection_background_image',
     * 'is_new'], and those accessors run once per serialised row --
     * collection_background_image always, and is_new goes back for created_at
     * because it is not in this select. That made a 122-row list 372 queries.
     * Nothing here needs a model.
     */
    public function allSimple(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'product.view')) {
            return $can;
        }

        $query = DB::table('products')->select('id', 'title');

        if ($this->isVendor) {
            $query->where('admin_id', $this->user->id);
        }

        $data = $query->orderBy('title')->get();

        return response()->json(new Response($request->token, $data));
    }

    public function allProductsWithCategory(Request $request)
    {
       try {
            $lang = $request->header('language');
            $search = $request->q;
            $categoryId = $request->category_id;

            $query = Product::query();

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }

            $query->with([
                'product_inventories.inventory_attributes.attribute_value.attribute',
                'product_categories.category',
                'brand',
                'tax_rules'
            ]);

           $query->select(
                'products.id', 
                'products.title', 
                'products.image',
                'products.unit', 
                'products.tax_rule_id', 
                'products.shipping_rule_id',
                'products.brand_id', 
                'products.purchased', 
                'products.selling',
                'products.offered', 
                'products.status', 
                'products.created_at',
                'products.category_id',
            );

            if ($categoryId) {
                $query->whereHas('product_categories', function($q) use ($categoryId) {
                    $q->where('category_id', $categoryId);
                });
            }

            /*
             * Search by SKU or by title.
             *
             * The title half used to read `pl.title`, an alias for
             * product_langs that this query never joins, so MySQL rejected the
             * whole statement with "Unknown column 'pl.title'" and the endpoint
             * answered "Something went wrong" -- every time anything was typed
             * into the box. The title lives on products here, and the language
             * override is matched through the relation rather than an alias
             * that has to be kept in step with a join somewhere else.
             */
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('product_inventories', function ($qi) use ($search) {
                        $qi->where('sku', 'LIKE', "%{$search}%");
                    })
                    ->orWhere('products.title', 'LIKE', "%{$search}%")
                    ->orWhereHas('langs', function ($ql) use ($search) {
                        $ql->where('title', 'LIKE', "%{$search}%");
                    });
                });
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            $this->withoutStorefrontAppends($data);

            if ($request->time_zone) {
                foreach ($data as $item) {
                    $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                }
            } else {
                foreach ($data as $item) {
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }

            $categories = Category::whereNull('parent')->with(['child'])->get(['id', 'title']);
            $productCollections = ProductCollection::get(['id', 'title']);
            $brands = Brand::get(['id', 'title']);
            $bundles = BundleDeal::get(['id', 'title']);
            /*
             * The picker list: every product as id and title. Read through the
             * query builder because a model here would fire Product's three
             * $appends once per row -- this one list was 290 of the 302 queries
             * this endpoint used to cost, and five admin pages call it on
             * mount.
             */
            $products = DB::table('products')
                ->select('products.id', 'products.title')
                ->when($categoryId, function ($q) use ($categoryId) {
                    $q->whereExists(function ($sub) use ($categoryId) {
                        $sub->selectRaw(1)
                            ->from('product_categories')
                            ->whereColumn('product_categories.product_id', 'products.id')
                            ->where('product_categories.category_id', $categoryId);
                    });
                })
                ->orderBy('products.title')
                ->get();


            return response()->json([
                'token' => $request->token,
                'data' => $data,
                'categories' => $categories,
                'collections' => $productCollections,
                'brands' => $brands,
                'bundles' => $bundles,
                'products' => $products
            ]);

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Publish or unpublish products from the list.
     *
     * The Products grid has carried a "Draft" action all along, and it pushed
     * the router at the product's edit page -- the same thing Edit and View
     * already did. The menu item claimed to change the product's state and
     * changed nothing.
     *
     * Modelled on CategoriesController::updateStatus so the two lists behave
     * the same way, including taking a comma-separated selection.
     */
    public function updateStatus(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $status = (int)$request->input('status');

            if (!in_array($status, [
                (int)Config::get('constants.status.PUBLIC'),
                (int)Config::get('constants.status.PRIVATE'),
            ], true)) {
                return response()->json(Validation::errorLang($lang));
            }

            $ids = array_values(array_unique(array_filter(
                array_map('trim', is_array($request->input('id'))
                    ? $request->input('id')
                    : explode(',', (string)$request->input('id'))),
                function ($value) {
                    return $value !== '' && ctype_digit((string)$value);
                }
            )));

            if (!$ids) {
                return response()->json(Validation::noDataLang($lang));
            }

            $products = Product::whereIn('id', $ids)->get();

            if ($products->count() !== count($ids)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor) {
                foreach ($products as $product) {
                    if ($isOwner = Utils::isDataOwner($this->user, $product)) {
                        return $isOwner;
                    }
                }
            }

            $updated = Product::whereIn('id', $ids)->update(['status' => $status]);

            return response()->json(new Response($request->token, [
                'updated' => $updated,
                'status' => $status,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * The rules the bulk editor has to satisfy before anything is written.
     *
     * It used to call no Validation:: anything, so every guard the product
     * form enforces was simply absent here: an offer above the selling price
     * (refused on the product page since QA audit PRC-02), a negative price, a
     * status with no meaning, a duplicate slug that breaks storefront routing.
     * The grid writes the same columns as the form, so it answers to the same
     * rules.
     *
     * Returns a map of product id => list of messages. Empty means the batch
     * is good.
     */
    private function validateBulkRows($rows, $existing)
    {
        $errors = [];

        $add = function (&$errors, $id, $message) {
            $errors[$id][] = $message;
        };

        foreach ($rows as $row) {

            $id = $row['id'] ?? null;

            if (!$id || !$existing->has($id)) {
                $add($errors, (string)$id, 'This product no longer exists.');
                continue;
            }

            $product = $existing->get($id);
            $label = $row['title'] ?? $product->title;
            $merge = isset($row['mode']) && $row['mode'] == 'merge';

            $selling = $row['selling'] ?? null;
            $offered = $row['offered'] ?? 0;

            if (!is_numeric($selling) || (float)$selling <= 0) {
                $add($errors, $id, $label . ': a selling price above zero is required.');
            }

            if ($offered !== null && $offered !== '' && !is_numeric($offered)) {
                $add($errors, $id, $label . ': the offer price must be a number.');
            } elseif ((float)$offered < 0) {
                $add($errors, $id, $label . ': the offer price cannot be negative.');
            } elseif ((float)$offered > 0 && is_numeric($selling) && (float)$offered > (float)$selling) {
                $add($errors, $id, $label . ': the offer price cannot be above the selling price.');
            }

            if (array_key_exists('discount_type', $row) && !is_null($row['discount_type'])
                && !in_array($row['discount_type'], ['fixed', 'percentage'], true)) {
                $add($errors, $id, $label . ': the discount type must be fixed or percentage.');
            }

            if (array_key_exists('discount_value', $row) && !is_null($row['discount_value'])) {
                if (!is_numeric($row['discount_value']) || (float)$row['discount_value'] < 0) {
                    $add($errors, $id, $label . ': the discount cannot be negative.');
                } elseif (($row['discount_type'] ?? null) === 'percentage'
                    && (float)$row['discount_value'] > 100) {
                    $add($errors, $id, $label . ': a percentage discount cannot be above 100.');
                }
            }

            if (array_key_exists('status', $row) && !is_null($row['status'])
                && !in_array((int)$row['status'], [
                    (int)Config::get('constants.status.PUBLIC'),
                    (int)Config::get('constants.status.PRIVATE'),
                ], true)) {
                $add($errors, $id, $label . ': that is not a status a product can have.');
            }

            /*
             * Title and slug are only written by the full save, not by a merge.
             * The slug is what the storefront routes on, so a duplicate is not
             * a cosmetic problem.
             */
            if (!$merge) {

                if (trim((string)($row['title'] ?? '')) === '') {
                    $add($errors, $id, 'A product cannot be saved without a title.');
                }

                $slug = trim((string)($row['slug'] ?? ''));

                if ($slug === '') {
                    $add($errors, $id, $label . ': a slug is required.');
                } elseif (Product::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                    $add($errors, $id, $label . ': that slug already belongs to another product.');
                }
            }

            foreach (($row['product_inventories'] ?? []) as $inventory) {

                $sku = trim((string)($inventory['sku'] ?? ''));

                if ($sku === '') {
                    $add($errors, $id, $label . ': every stock row needs a SKU.');
                }

                if (!is_numeric($inventory['price'] ?? null) || (float)$inventory['price'] < 0) {
                    $add($errors, $id, $label . ' (' . $sku . '): the price cannot be negative.');
                }

                if (!is_numeric($inventory['quantity'] ?? null) || (int)$inventory['quantity'] < 0) {
                    $add($errors, $id, $label . ' (' . $sku . '): the quantity cannot be negative.');
                }
            }
        }

        return $errors;
    }

    /**
     * The bulk product editor.
     *
     * Ran no Utils::userCan() check of any kind, so any signed-in admin could
     * rewrite titles, slugs, prices, status and categories across the whole
     * catalogue whatever their role granted - and, being a vendor-visible
     * controller, across products belonging to other vendors.
     *
     * Gated on product.edit: it is an edit of products and nothing else. (The
     * seeded product_bulk_update.* permissions are referenced by neither the
     * admin panel nor the API, so gating on them would grant nobody anything.)
     */
    public function bulkUpdate(Request $request){

        if ($can = Utils::userCan($this->user, 'product.edit')) {
            return $can;
        }

        $products = $request->products;

        $products = json_decode($products, true);
        $productIds = collect($products)->pluck('id');

        // A vendor edits their own catalogue. Ids they do not own are dropped
        // rather than refused, so one stray row cannot block the whole save.
        if ($this->isVendor) {
            $ownIds = Product::whereIn('id', $productIds)
                ->where('admin_id', $this->user->id)
                ->pluck('id')
                ->map(function ($id) { return (string)$id; })
                ->all();

            $products = array_values(array_filter((array)$products, function ($row) use ($ownIds) {
                return in_array((string)($row['id'] ?? ''), $ownIds, true);
            }));

            $productIds = collect($products)->pluck('id');
        }

        $beforeProducts = Product::select([
                'id',
                'title',
                'slug',
                'upsell_id',
                'bundle_deal_id',
                'image',
                'unit',
                'tax_rule_id',
                'shipping_rule_id',
                'brand_id',
                'purchased',
                'selling',
                'discount_type',
                'discount_value',
                'offered',
                'status',
                'procurement',
                'created_at',
            ])->with([
            'product_categories.category',
            'collection_with_products.product_collection',
            'brand',
            'tax_rules',
            'product_inventories.inventory_attributes.attribute_value.attribute'
        ])->whereIn('id', $productIds)->get();

        if (!$products) {
            return response()->json([
                'message' => 'Invalid products data'
            ], 400);
        }

        /*
         * Nothing is written until every row passes. The grid submits the whole
         * page at once, and applying 40 of 41 rows and reporting success is how
         * a screen like this stops being trusted.
         */
        $existing = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $rowErrors = $this->validateBulkRows($products, $existing);

        if (count($rowErrors) > 0) {

            $flat = [];
            foreach ($rowErrors as $messages) {
                foreach ($messages as $message) {
                    $flat[] = $message;
                }
            }

            return response()->json(new Response($request->token, [
                'form' => $flat,
                'rows' => $rowErrors,
            ], 201, __('lang.bulk_update_refused', [], $request->header('language'))));
        }

        DB::beginTransaction();

        try {

        foreach($products as $product){
            $exist = Product::where(['id' => $product['id']])->first();
            if($exist){

                /*
                 * The VAT-inclusive display columns follow the price, wherever
                 * the price is changed from. This is the same derivation the
                 * product form uses; bulk edits used to skip it entirely and
                 * leave both columns describing the old price.
                 */
                $vatColumns = $this->vatInclusiveColumns(
                    TaxRules::find($exist->tax_rule_id),
                    $product['selling'],
                    $product['offered'] ?? 0
                );

                if(isset($product['mode']) && $product['mode'] == 'merge'){
                    $exist->update([
                        'selling' => $product['selling'],
                        'discount_type' => $product['discount_type'],
                        'discount_value' => $product['discount_value'],
                        'offered' => $product['offered'],
                        'vatInclusivePrice' => $vatColumns['vatInclusivePrice'],
                        'vatInclusiveOfferedPrice' => $vatColumns['vatInclusiveOfferedPrice'],
                        'status' => $product['status'],
                        'procurement' => $product['procurement'] ?? $exist->procurement,
                        'overview' => $product['overview'] ?? $exist->overview,
                        'description' => $product['description'] ?? $exist->description,
                        'meta_title' => $product['meta_title'] ?? $exist->meta_title,
                        'meta_keywords' => $product['meta_keywords'] ?? $exist->meta_keywords,
                        'meta_description' => $product['meta_description'] ?? $exist->meta_description,
                        'upsell_id' => $product['upsell_id'],
                        'bundle_deal_id' => $product['bundle_deal_id'] ?? null,
                    ]);

                    if(isset($product['categories'])){
                        $categories = [];
                        foreach($product['categories'] as $catId){
                            $catExists = ProductCategory::where([
                                'product_id' => $product['id'],
                                'category_id' => $catId
                            ])->exists();

                            if($catExists) continue;

                            $categories[] = [
                                'category_id' => $catId,
                                'primary' => $product['primary_category_id'] == $catId ? true : false,
                                'product_id' => $product['id'],
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                        }

                        if(!empty($categories)){
                            ProductCategory::insert($categories);
                        }
                    }

                    if(isset($product['subcategories'])){
                        $subcategories = [];
                        foreach($product['subcategories'] as $subcategory){
                            $subcatExists = ProductSubcategory::where([
                                'product_id' => $product['id'],
                                'category_id' => $subcategory['category_id'],
                                'subcategory_id' => $subcategory['id'],
                            ])->exists();

                            if($subcatExists) continue;

                            $subcategories[] = [
                                'product_id' => $product['id'],
                                'category_id' => $subcategory['category_id'],
                                'subcategory_id' => $subcategory['id'],
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                        }

                        if(!empty($subcategories)){
                            ProductSubcategory::insert($subcategories);
                        }
                        
                    }

                    if(isset($product['collections'])){
                        $collections = [];
                        foreach($product['collections'] as $colId){
                            $collectionExists = CollectionWithProduct::where([
                                'product_id' => $product['id'],
                                'product_collection_id' => $colId,
                            ])->exists();

                            if($collectionExists) continue;
                            $collections[] = [
                                'product_collection_id' => $colId,
                                'product_id' => $product['id'],
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                        }
                        
                        if(!empty($collections)){
                            CollectionWithProduct::insert($collections);
                        }
                        
                    }

                }else{
                    $exist->update([
                        'title' => $product['title'],
                        'slug' => $product['slug'],
                        'selling' => $product['selling'],
                        'discount_type' => $product['discount_type'],
                        'discount_value' => $product['discount_value'],
                        'offered' => $product['offered'],
                        'vatInclusivePrice' => $vatColumns['vatInclusivePrice'],
                        'vatInclusiveOfferedPrice' => $vatColumns['vatInclusiveOfferedPrice'],
                        'status' => $product['status'],
                        'procurement' => $product['procurement'] ?? $exist->procurement,
                        'overview' => $product['overview'] ?? $exist->overview,
                        'description' => $product['description'] ?? $exist->description,
                        'meta_title' => $product['meta_title'] ?? $exist->meta_title,
                        'meta_keywords' => $product['meta_keywords'] ?? $exist->meta_keywords,
                        'meta_description' => $product['meta_description'] ?? $exist->meta_description,
                        'upsell_id' => $product['upsell_id'],
                        'bundle_deal_id' => $product['bundle_deal_id'] ?? null,
                    ]);

                    if(isset($product['categories'])){
                        ProductCategory::where('product_id', $product['id'])->delete();
                        $categories = [];
                        foreach($product['categories'] as $catId){
                            $categories[] = [
                                'category_id' => $catId,
                                'primary' => $product['primary_category_id'] == $catId ? true : false,
                                'product_id' => $product['id'],
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                        }
                        ProductCategory::insert($categories);
                    }

                    if(isset($product['subcategories'])){
                        ProductSubcategory::where('product_id', $product['id'])->delete();
                        $subcategories = [];
                        foreach($product['subcategories'] as $subcategory){
                            $subcategories[] = [
                                'product_id' => $product['id'],
                                'category_id' => $subcategory['category_id'],
                                'subcategory_id' => $subcategory['id'],
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                        }
                        ProductSubcategory::insert($subcategories);
                    }

                    if(isset($product['collections'])){
                        CollectionWithProduct::where('product_id', $product['id'])->delete();
                        $collections = [];
                        foreach($product['collections'] as $colId){
                            $collections[] = [
                                'product_collection_id' => $colId,
                                'product_id' => $product['id'],
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                        }
                        CollectionWithProduct::insert($collections);
                    }

                    if(isset($product['product_inventories'])){
                        foreach($product['product_inventories'] as $inv){
                            $existInv = UpdatedInventory::where(['id' => $inv['id'], 'product_id' => $product['id']])->first();
                            if($existInv){
                                $existInv->update([
                                    'price' => $inv['price'],
                                    'sku' => $inv['sku'],
                                    'quantity' => $inv['quantity'],
                                    'imei' => $inv['imei'],
                                    'barcode' => $inv['barcode'],
                                    'is_active' => $inv['is_active'],
                                ]);
                            }
                        }
                    }

                    if ($request->hasFile("images.".$product['id'])) {

                        /*
                         * Through FileHelper, not $file->move().
                         *
                         * This used to build the name from
                         * $file->getClientOriginalName() -- entirely under the
                         * caller's control -- and move the file straight into
                         * base_path('uploads'), which is served directly and
                         * has no .htaccess. Nothing checked what the file was,
                         * so a POST here could write an executable .php into
                         * the web root. FileHelper::safeFilename() exists for
                         * this: a UUID name and an extension whitelist,
                         * "so nothing that PHP would execute can ever be
                         * written". It generates the thumbnail too.
                         */
                        $file = $request->file("images.".$product['id']);

                        $validate = Validation::multipleImages(
                            ['photo' => $file], $request->token
                        );

                        if ($validate) {
                            throw new \Exception(
                                $product['title'] . ': that image was not accepted.'
                            );
                        }

                        $image_info = FileHelper::uploadImage($file, 'product');

                        $exist->update([
                            'image' => $image_info['name']
                        ]);
                    }
                }
            }
        }

        $afterProducts = Product::select([
                'id',
                'title',
                'slug',
                'upsell_id',
                'bundle_deal_id',
                'image',
                'unit',
                'tax_rule_id',
                'shipping_rule_id',
                'brand_id',
                'purchased',
                'selling',
                'discount_type',
                'discount_value',
                'offered',
                'status',
                'created_at',
            ])->with([
            'product_categories.category',
            'collection_with_products.product_collection',
            'brand',
            'tax_rules',
            'product_inventories.inventory_attributes.attribute_value.attribute'
        ])->whereIn('id', $productIds)->get();

        ProductLog::create([
            'admin_id' => $request->user()->id,
            'products_before' => $beforeProducts->toJson(),
            'products_after' => $afterProducts->toJson(),
        ]);

        DB::commit();

        } catch (\Exception $ex) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

        return response()->json(new Response($request->token, ['message' => 'Products updated successfully']));
    }

    /**
     * Point a product's featured image at one of its own gallery images.
     *
     * Ran no permission check and never checked that the image belonged to the
     * product, so any signed-in admin could give any product any other
     * product's photo. Both halves are closed here: product.edit as its
     * neighbours require, and the image is looked up scoped to the product.
     */
    public function setMainImage(Request $request){
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $imageId = $request->image_id;
            $productId = $request->product_id;

            $product = Product::find($productId);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                return $isOwner;
            }

            $image = ProductImage::where('id', $imageId)
                ->where('product_id', $productId)
                ->value('image');

            if (!$image) {
                // noDataLang, not nothingFoundLang: the latter answers 200, which the
                // admin store reads as success and reports with a cheerful toast.
                return response()->json(Validation::noDataLang($lang));
            }

            Product::where(['id' => $productId])->update(['image' => $image]);

            return response()->json(new Response($request->token, ['message' => 'Image updated successfully']));
        }catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Which of a product's gallery images belong to one of its variants.
     *
     * Also ran unguarded, and took the inventory id and the image ids on
     * trust - a mapping could be written between any variant and any image in
     * the catalogue. Everything is now resolved through the product that owns
     * the variant, and images from anywhere else are dropped rather than
     * written.
     */
    public function setVariantImages(Request $request){
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $inventoryId = $request->inventory_id;
            $imageIds = $request->image_ids;
            $now = Carbon::now();

            $inventory = UpdatedInventory::find($inventoryId);

            if (is_null($inventory)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $product = Product::find($inventory->product_id);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                return $isOwner;
            }

            if(isset($imageIds)){

                // Only this product's own images can be mapped to its variant.
                $ownImageIds = ProductImage::where('product_id', $product->id)
                    ->whereIn('id', (array)$imageIds)
                    ->pluck('id');

                InventoryImage::where('inventory_id', $inventoryId)->delete();

                $imageInsert = [];

                foreach($ownImageIds as $imgId){
                    $imageInsert[] = [
                        'inventory_id' => $inventoryId,
                        'product_image_id' => $imgId,
                        'created_at' => $now,
                        'updated_at' => $now
                    ];
                }

                if($imageInsert){
                    InventoryImage::insert($imageInsert);
                }
            }

            return response()->json(new Response($request->token, ['message' => 'Image updated successfully']));
        }catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function setProductFeatureImages(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $product = Product::find($id);

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                return $isOwner;
            }

            $images = [];
            if ($request->hasFile('images') && $id) {
                foreach ($request->file('images') as $index => $image) {

                    /*
                     * Through FileHelper, like every other image this admin
                     * accepts. It used to build its own name from time() --
                     * identical for every file in the loop, so a batch uploaded
                     * within the same second all resolved to one name: on Linux
                     * each move overwrote the last, on Windows the second
                     * rename was refused and the request died halfway through.
                     * And it moved the file into the web root with no check on
                     * what it was. safeFilename() gives a UUID name off a
                     * whitelisted extension, which settles both.
                     */
                    $validate = Validation::multipleImages(
                        ['photo' => $image], $request->token
                    );

                    if ($validate) {
                        return response()->json($validate);
                    }

                    $image_info = FileHelper::uploadImage($image, 'product');

                    $featureImage =
                        ProductFeatureImage::create([
                            'product_id' => $id,
                            'image' => $image_info['name'],
                            'sort_order' => $index
                        ]);

                    $images[] = $featureImage;
                }
            }
            return response()->json(new Response($request->token, ['message' => 'Image uploaded successfully']));
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
