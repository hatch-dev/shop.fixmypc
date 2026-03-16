<?php

namespace App\Http\Controllers;

use App\Models\BundleDeal;
use App\Models\BundleDealLang;
use App\Models\BundleDealProducts;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class BundleDealsController extends ControllerHelper
{
    public function all(Request $request)
    {

        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'bundle_deal.view')) {
                return $can;
            }


            $query = BundleDeal::with('products');
            $query = $query->orderBy('bundle_deals.' . $request->orderby, $request->type);


            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }


            if ($lang) {
                $query = $query->leftJoin('bundle_deal_langs as pcl', function ($join) use ($lang) {
                    $join->on('pcl.bundle_deal_id', '=', 'bundle_deals.id');
                    $join->where('pcl.lang', $lang);
                });
                $query = $query->select('bundle_deals.*', 'pcl.title');

                if ($request->q) {
                    $query = $query->where('pcl.title', 'LIKE', "%{$request->q}%");
                }

            } else {
                if ($request->q) {
                    $query = $query->where('bundle_deals.title', 'LIKE', "%{$request->q}%");
                }
            }



            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

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

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function allList(Request $request)
    {
        try {
            $lang = $request->header('language');


            $query = BundleDeal::query();

            if ($lang) {
                $query = $query->leftJoin('bundle_deal_langs as trl', function ($join) use ($lang) {
                    $join->on('trl.bundle_deal_id', '=', 'bundle_deals.id');
                    $join->where('trl.lang', $lang);
                });
                $query = $query->select('bundle_deals.id', 'trl.title');

            } else {

                $query = $query->select('bundle_deals.id', 'bundle_deals.title');
            }

            $query = $query->orderBy('bundle_deals.created_at');
            $data = $query->get();


            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

    }


    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'bundle_deal.view')) {
                return $can;
            }

            $query = BundleDeal::with('products');
            if ($lang) {
                $query = $query->leftJoin('bundle_deal_langs as trl', function ($join) use ($lang) {
                    $join->on('trl.bundle_deal_id', '=', 'bundle_deals.id');
                    $join->where('trl.lang', $lang);
                });
                $query = $query->select('bundle_deals.*', 'trl.title');
            }
            $data = $query->find($id);

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $data)) {
                return $isOwner;
            }

            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
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
            $products = $request->products;
            $now = date('Y-m-d H:i:s');
            $validate = Validation::bundleDeals($request);
            if ($validate) {
                return response()->json($validate);
            }

            if ($id) {
                if ($can = Utils::userCan($this->user, 'bundle_deal.edit')) {
                    return $can;
                }

                $existing = BundleDeal::find($id);
                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }

                $filtered = array_filter($request->except(['products', 'productsValidation']), function ($element) {
                    return '' !== trim($element);
                });

                BundleDeal::where('id', $id)->update([
                    'title' => $request->title,
                    'description'     => $request->description,
                    'discount_type'   => $request->discount_type,
                    'discount_value'  => $request->discount_value,
                    'buy'             => $request->buy,
                    'free'            => $request->free,
                ]);

                BundleDealProducts::where(['bundle_deal_id' => $id])->delete();
                foreach($products as $key => $value){
                    BundleDealProducts::create([
                        'bundle_deal_id' => $id,
                        'product_id' => $value['id'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($filtered, ['title']);
                    $existingLang = BundleDealLang::where('bundle_deal_id', $id)
                        ->where('lang', $lang)->first();
                    if (!$existingLang) {
                        $langData['bundle_deal_id'] = $id;
                        $langData['lang'] = $lang;
                        BundleDealLang::create($langData);

                    } else {
                        $existingLang->update($langData);
                    }
                } else {
                    BundleDeal::where('id', $id)->update($filtered);
                }


            } else {
                if ($can = Utils::userCan($this->user, 'bundle_deal.create')) {
                    return $can;
                }
                $request['admin_id'] = $request->user()->id;

                $bundleDeal = BundleDeal::create([
                    'title'          => $request->title,
                    'description'    => $request->description,
                    'discount_type'  => $request->discount_type,
                    'discount_value' => $request->discount_value,
                    'buy'            => $request->buy,
                    'free'           => $request->free,
                    'admin_id'       => $request->user()->id,
                ]);

                $id = $bundleDeal->id;

                BundleDealProducts::where(['bundle_deal_id' => $id])->delete();
                foreach($products as $key => $value){
                    BundleDealProducts::create([
                        'bundle_deal_id' => $id,
                        'product_id' => $value['id'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($request->all(), ['title']);
                    $langData['bundle_deal_id'] = $bundleDeal->id;
                    $langData['lang'] = $lang;
                    BundleDealLang::create($langData);
                }
            }


            $query = BundleDeal::with('products');
            if ($lang) {
                $query = $query->leftJoin('bundle_deal_langs as trl', function ($join) use ($lang) {
                    $join->on('trl.bundle_deal_id', '=', 'bundle_deals.id');
                    $join->where('trl.lang', $lang);
                });
                $query = $query->select('bundle_deals.*', 'trl.title');
            }
            $data = $query->find($id);


            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function delete(Request $request, $id)
    {
        try {
            $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'bundle_deal.delete')) {
                return $can;
            }

            $ids =  explode(",", $id);
            foreach ($ids as $i){
                $bundleDeal = BundleDeal::find($i);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $bundleDeal)) {
                    return $isOwner;
                }

                if (is_null($bundleDeal)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                $product = Product::where('bundle_deal_id', $i)->first();

                if ($product) {
                    return response()->json(Validation::error($request->token,
                        __('lang.unable_delete', ['message' => __('lang.deal_used', [], $lang)], $lang)
                    ));
                }

                BundleDealLang::where('bundle_deal_id', $i)->delete();
                $bundleDeal->delete();
            }

            return response()->json(new Response($request->token, true));
            //return response()->json(Validation::error($request->token, null, 'form', $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }


    }
}
