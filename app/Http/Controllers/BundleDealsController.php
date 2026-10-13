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
use App\Services\BundleService;
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

            /*
             * Priced by BundleService, which is what the product page and the
             * basket use.
             *
             * This used to add up `products.offered ?: products.selling` and
             * take the percentage off that. That is a product-LEVEL figure,
             * while the shop charges the cheapest purchasable VARIANT of each
             * item -- so on any product whose variants carry their own prices
             * the list quoted a total the shop never charges. One batched
             * call for the whole page, not a query per bundle.
             */
            $totals = (new BundleService())->totalsFor($data->getCollection());

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);

                $priced = $totals[(int)$item->id] ?? [
                    'total_price' => 0.0,
                    'discount_amount' => 0.0,
                    'final_price' => 0.0,
                    'sellable' => false,
                ];

                $item['total_price'] = $priced['total_price'];
                $item['discount_amount'] = $priced['discount_amount'];
                $item['final_price'] = $priced['final_price'];

                /*
                 * Whether the storefront would offer it at all. A bundle is
                 * all or nothing, so one unpublished or sold-out item hides
                 * the whole thing -- which is worth saying on the list rather
                 * than leaving an administrator to wonder why a live-looking
                 * deal never appears.
                 */
                $item['sellable'] = $priced['sellable'];
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

            /*
             * Deliberately NOT gated on `bundle_deal.view`, in line with every
             * other allList in this codebase. It returns id and title only,
             * and it is what fills the bundle dropdown on the product editor:
             * gating it would take that dropdown away from a role that may
             * edit products but not administer the deals themselves.
             */
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


    /**
     * The fields a bundle owns and an administrator may edit.
     *
     * Named explicitly, because the only other caller of this data is a
     * query-builder `update()`, which does not honour $fillable. `admin_id`
     * is deliberately absent: ownership is the server's to set, once, at
     * creation. Blank strings are dropped so an untouched optional field does
     * not overwrite a stored value with ''.
     */
    private static function editableFields(Request $request)
    {
        $allowed = ['title', 'description', 'discount_type', 'discount_value', 'buy', 'free'];

        $fields = [];

        foreach ($allowed as $key) {
            if (!$request->has($key)) {
                continue;
            }

            $value = $request->input($key);

            if (is_array($value) || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $fields[$key] = $value;
        }

        return $fields;
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

                /*
                 * The bundle's OWN editable fields, named, rather than
                 * whatever the request happened to carry.
                 *
                 * This was `$request->except(['products','productsValidation'])`
                 * handed to a query-builder `update()`, where $fillable does
                 * not apply. Two things went wrong with that:
                 *
                 *   - `scope:admin` re-issues the caller's access token INTO
                 *     the request input whenever the old one is within a
                 *     minute of expiring, so the save named a `token` column
                 *     that does not exist and died. The rows above it were
                 *     already written and there is no transaction, so the
                 *     administrator was told the save had failed on a save
                 *     that had gone through.
                 *   - Any column named in the body was written, `admin_id`
                 *     included -- which is what scopes a vendor's bundles to
                 *     the vendor.
                 *
                 * Ownership is not on this list. It is set once, by the
                 * server, when the bundle is created.
                 */
                $filtered = self::editableFields($request);

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
                }
                /*
                 * No `else` branch: the explicit update above has already
                 * written every field a bundle owns. The second update this
                 * used to do was the unfiltered one, and it wrote nothing the
                 * first had not already written correctly.
                 */


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

                /*
                 * `products.bundle_deal_id` is a comma-separated VARCHAR --
                 * that is how a product offers more than one bundle, and how
                 * BundleService reads it.
                 *
                 * This was an equality test against the whole string, so it
                 * only ever matched a product offering this bundle AND NO
                 * OTHER. Assign a second bundle and the guard stopped seeing
                 * either of them: the deal was deleted out from under a live
                 * product, leaving bundle_deal_id pointing at an id that no
                 * longer exists.
                 *
                 * FIND_IN_SET matches one member of the list. Spaces are
                 * stripped first because the column is hand-edited and
                 * "16, 160" is a list FIND_IN_SET would otherwise read as
                 * "16" and " 160".
                 */
                $product = Product::whereRaw(
                    "FIND_IN_SET(?, REPLACE(COALESCE(bundle_deal_id, ''), ' ', ''))",
                    [(int)$i]
                )->first();

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
