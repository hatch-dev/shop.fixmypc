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
use App\Models\UpdatedUpsell;
use App\Models\FlashSaleProduct;
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
use App\Models\ProductImageAttribute;
use App\Models\ProductLang;
use App\Models\RatingReview;
use App\Models\ReviewImage;
use App\Models\ShippingRule;
use App\Models\SubCategory;
use App\Models\TaxRules;
use App\Models\UpdatedInventory;
use App\Models\UserWishlist;
use App\Models\WysiwygImage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str; 

class ProductsController extends ControllerHelper
{

    public function all(Request $request)
    {
        try {
            $lang = $request->header('language');

            $categoryId = $request->category_id;
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

                $query = $query->select('products.id', 'products.title', 'products.overview', 'products.description', 'products.procurement', 'products.slug', 'products.upsell_id', 'products.updated_upsell_id', 'products.bundle_deal_id', 'products.image',
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

                $query = $query->select('products.id', 'products.title', 'products.overview', 'products.description', 'products.procurement','products.slug', 'products.upsell_id', 'products.updated_upsell_id', 'products.bundle_deal_id', 'products.image',
                    'products.unit', 'products.tax_rule_id', 'products.shipping_rule_id',
                    'products.brand_id', 'products.purchased', 'products.selling', 'products.discount_type', 'products.discount_value',
                    'products.offered', 'products.status', 'products.created_at', 'products.meta_title', 'products.meta_description', 'products.meta_keywords', \DB::raw('COALESCE(product_views.views, 0) as views'));

                if ($categoryId) {
                    $query->whereHas('product_categories', function($q) use ($categoryId) {
                        $q->where('category_id', $categoryId);
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

            $res['updated_upsells'] = UpdatedUpsell::where(['status' => 1])->orderBy('created_at', 'desc')->get(['id', 'title']);


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


            $query = $query->with(['product_images.attributes']);

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


            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function action(Request $request, $id = null)
    {
        try {
            
            $lang = $request->header('language');

            $validate = Validation::productMain($request);
            if ($validate) {
                return response()->json($validate);
            }

            $primaryCategory = $request->primary_category_id;

            $bySlug = Product::where('slug', $request['slug'])->first();
            
            
            //// vat rule price calculations
            $tax_rule_id=$request->tax_rule_id;
            $selling_price=$request->selling;
            $offered_price=$request->offered;
            $vatInclusivePrice= $vatInclusiveOfferedPrice=0.00;

            if($tax_rule_id==1){
               $vatInclusivePrice=$selling_price + $selling_price*23/100;
               $vatInclusivePrice= number_format((float)$vatInclusivePrice, 2, '.', '');
               
               $vatInclusiveOfferedPrice=$offered_price + $offered_price*23/100;
               $vatInclusiveOfferedPrice= number_format((float)$vatInclusiveOfferedPrice, 2, '.', '');

            }else{
                $vatInclusivePrice=$selling_price;
                $vatInclusiveOfferedPrice=$offered_price;
            }

            $request['vatInclusivePrice']=$vatInclusivePrice;
            $request['vatInclusiveOfferedPrice']=$vatInclusiveOfferedPrice;

            $request['excludeVAT']=$request->excludeVAT;


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

                $filtered = array_filter($request->all(), function ($element) {
                    return !is_array($element);
                });
                unset($filtered['primary_category_id']);


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

                $request['image'] = Config::get('constants.media.DEFAULT_IMAGE');
                $request['admin_id'] = $request->user()->id;
                $request['id'] = Utils::idGenerator(new Product);


                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($request->all(), [
                        'description', 'title', 'overview', 'unit', 'badge', 'meta_title', 'meta_description', 'meta_keywords'
                    ]);
                    $product = Product::create($mainData);

                    $langData['product_id'] = $product->id;
                    $langData['lang'] = $lang;
                    ProductLang::create($langData);
                    $id = $product->id;

                } else {
                    $product = Product::create($request->all());
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


            $productQuery = Product::with(['product_images.attributes'])
                ->with('product_categories')
                ->with('product_collections');

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
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'product.delete')) {
                return $can;
            }


            $ids = explode(",", $id);

            foreach ($ids as $i) {

                $product = Product::find($i);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $product)) {
                    return $isOwner;
                }

                if (is_null($product)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                FlashSaleProduct::where('product_id', $i)->delete();

                OrderedProduct::where('product_id', $i)->delete();

                CollectionWithProduct::where('product_id', $i)->delete();

                HomeSliderSourceProduct::where('product_id', $i)->delete();


                $product_inventories = UpdatedInventory::where('product_id', $i)->get();

                ProductCategory::where('product_id', $i)->delete();

                foreach ($product_inventories as $inv) {
                    InventoryAttribute::where('inventory_id', $inv->id)->delete();
                }

                Cart::where('product_id', $i)->delete();
                CompareList::where('product_id', $i)->delete();


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



                UserWishlist::where('product_id', $i)->delete();

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


            return response()->json(new Response($request->token, null));

            //return response()->json(Validation::errorTokenLang($request->token, $lang));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function allImages(Request $request, $productId)
    {
        $data = ProductImage::orderBy('created_at', 'ASC')->where(['product_id' => $productId])->get();
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
        return response()->json($request->token, Validation::error());
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

                $request['admin_id'] = $request->user()->id;
                $request['id'] = Utils::idGenerator(new Product);
                $product = Product::create($request->all());
                $id = $product->id;

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

    public function duplicate(Product $product)
    {
        $newProduct = $product->replicate();
        $newProduct->title = $product->title . ' (Copy)';
        $newProduct->slug = $product->slug . '-copy-' . Str::random(4);
        $newProduct->rating = 0;
        $newProduct->save();

        // Duplicate relationships
        $newProduct->categories()->sync($product->categories->pluck('id'));

        return response()->json($newProduct);
    }
	
	public function allSimple(Request $request)
	{
		
		$query = Product::query();
		
		

		$data = $query->select('id', 'title')
					 ->orderBy('title')
					 ->get();

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

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('product_inventories', function ($qi) use ($search) {
                        $qi->where('sku', 'LIKE', "%{$search}%");
                    })
                    ->orWhere('pl.title', 'LIKE', "%{$search}%");
                });
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

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
            $updated_upsells = UpdatedUpsell::where('status', 1)->get(['id', 'title']);
            $bundles = BundleDeal::get(['id', 'title']);
            $products = Product::with('product_categories')
            ->when($categoryId, function ($q) use ($categoryId) {
                $q->whereHas('product_categories', function($q2) use ($categoryId) {
                    $q2->where('category_id', $categoryId);
                });
            })
            ->get(['id','title']);


            return response()->json([
                'token' => $request->token,
                'data' => $data,
                'categories' => $categories,
                'collections' => $productCollections,
                'brands' => $brands,
                'updated_upsells' => $updated_upsells,
                'bundles' => $bundles,
                'products' => $products
            ]);

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function bulkUpdate(Request $request){
        $products = $request->products;

        $products = json_decode($products, true);
        $productIds = collect($products)->pluck('id');

        $beforeProducts = Product::select([
                'id',
                'title',
                'slug',
                'upsell_id',
                'updated_upsell_id',
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

        foreach($products as $product){
            $exist = Product::where(['id' => $product['id']])->first();
            if($exist){

                if(isset($product['mode']) && $product['mode'] == 'merge'){
                    $exist->update([
                        'selling' => $product['selling'],
                        'discount_type' => $product['discount_type'],
                        'discount_value' => $product['discount_value'],
                        'offered' => $product['offered'],
                        'status' => $product['status'],
                        'procurement' => $product['procurement'] ?? $exist->procurement,
                        'overview' => $product['overview'] ?? $exist->overview,
                        'description' => $product['description'] ?? $exist->description,
                        'meta_title' => $product['meta_title'] ?? $exist->meta_title,
                        'meta_keywords' => $product['meta_keywords'] ?? $exist->meta_keywords,
                        'meta_description' => $product['meta_description'] ?? $exist->meta_description,
                        'upsell_id' => $product['upsell_id'],
                        'updated_upsell_id' => (string)$product['updated_upsell_id'] ?? null,
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
                        'status' => $product['status'],
                        'procurement' => $product['procurement'] ?? $exist->procurement,
                        'overview' => $product['overview'] ?? $exist->overview,
                        'description' => $product['description'] ?? $exist->description,
                        'meta_title' => $product['meta_title'] ?? $exist->meta_title,
                        'meta_keywords' => $product['meta_keywords'] ?? $exist->meta_keywords,
                        'meta_description' => $product['meta_description'] ?? $exist->meta_description,
                        'upsell_id' => $product['upsell_id'],
                        'updated_upsell_id' => (string)$product['updated_upsell_id'] ?? null,
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
                        $file = $request->file("images.".$product['id']);
                        $filename = time().'_'.$file->getClientOriginalName();
                        $thumbName = 'thumb-' . $filename;
                        $destinationPath = base_path('uploads');
                        $file->move($destinationPath, $filename);
                        copy(
                            $destinationPath . '/' . $filename,
                            $destinationPath . '/' . $thumbName
                        );
                        $exist->update([
                            'image' => $filename
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
                'updated_upsell_id',
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

        return response()->json(new Response($request->token, ['message' => 'Products updated successfully']));
    }

    public function setMainImage(Request $request){
        try {
            $imageId = $request->image_id;
            $productId = $request->product_id;

            $image = ProductImage::where(['id' => $imageId])->pluck('image')->first();
            if($image){
                Product::where(['id' => $productId])->update(['image' => $image]);
            }
            return response()->json(new Response($request->token, ['message' => 'Image updated successfully']));
        }catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function setVariantImages(Request $request){
        try {
            $inventoryId = $request->inventory_id;
            $imageIds = $request->image_ids;
            $now = Carbon::now();
            if(isset($imageIds)){

                InventoryImage::where('inventory_id', $inventoryId)->delete();

                $imageInsert = [];

                foreach($imageIds as $imgId){
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
}
