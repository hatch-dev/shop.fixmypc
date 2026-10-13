<?php

namespace App\Http\Controllers;

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Voucher;
use App\Models\VoucherLang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class VouchersController extends ControllerHelper
{
    public function validity(Request $request)
    {
        try {
            $lang = $request->header('language');
            $validate = Validation::voucherValidity($request);
            if ($validate) {
                return response()->json($validate);
            }

            $voucher = Voucher::where('code', $request->voucher)
                ->where('apply_type', 1)
                ->where('status', Config::get('constants.status.PUBLIC'))
                // someone else's gift voucher reads as not found
                ->usableBy(optional($request->user('user'))->id)
                ->first();

            if (is_null($voucher)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($voucher->isGiftCard() && $voucher->giftBalance() <= 0) {
                return response()->json(Validation::error(null, 'This gift card has been used up.'));
            }

            $q = Cart::with('product_inner');

            if ($this->user) {
                $q = $q->where('admin_id', $this->user->id);
            } else if ($request->user('user')) {
                $q = $q->where('user_id', $request->user('user')->id);
            }  else if($request->user_token){
                $q = $q->where('user_token', $request->user_token);
            } else {
                return response()->json(Validation::errorLang($lang));
            }

            $existingCart = $q->with('shipping_place')
                ->forCheckout()
                ->with('updated_inventory.inventory_attributes')
                ->get();

            /*
             * Nothing to price the code against.
             *
             * This used to fall straight through to the min_spend test below,
             * so a server that could not see the basket at all answered with a
             * sentence about the basket: "You have to spend at least <amount>"
             * against a basket holding EUR 756.95. That disguised a client-side
             * identity bug -- the voucher check was the one cart-reading call
             * that did not send the bearer, so the cart was scoped to a guest
             * token that owned nothing -- as a pricing bug, for as long as it
             * took to reproduce the request server-side.
             *
             * A refusal may only describe what the server actually saw.
             */
            if ($existingCart->isEmpty()) {
                return response()->json(Validation::error(null,
                    __('lang.no_cart', [], $lang)
                ));
            }

            /*
             * QA audit CPN-02. This used $request->price — a number the client
             * chose — as the cart total, so any min_spend could be cleared by
             * sending a big enough figure, and a percentage voucher previewed a
             * discount computed from the caller's own input.
             *
             * The subtotal is recomputed from the cart, on the same basis
             * checkout uses (goods only, excluding shipping), so the discount
             * shown is the discount applied.
             */
            $totalPriceWithoutShipping = 0;

            // Same bands checkout will charge, or the preview would test
            // min_spend against a subtotal the customer never pays.
            $businessTiers = BusinessProductPricing::tierMap(
                $request->user('user'),
                $existingCart->pluck('product_id')
            );

            foreach ($existingCart as $cart) {
                if (is_null($cart->product_inner)) {
                    continue;
                }

                $currentPrice = Pricing::unitPrice(
                    $cart->product_inner, $cart->updated_inventory, $cart->quantity,
                    $businessTiers->get($cart->product_inner->id)
                );
                $bundleQtyOffer = Pricing::bundleFreeQuantity($cart->product_inner, $cart->quantity);

                $totalPriceWithoutShipping += $currentPrice * ((int)$cart->quantity - $bundleQtyOffer);
            }

            $totalAmount = Pricing::round($totalPriceWithoutShipping);
            // foreach ($existingCart as $key => $cart) {
            //     if (!is_null($cart->product_inner)) {
            //     //if ($cart->shipping_place_id && !is_null($cart->product_inner)) {
            //         // Selling price calculation
            //         $inventoryPrice = (float)$cart->updated_inventory->price;
            //         $selling = (float)$cart->product_inner->selling;
            //         $offered = (float)$cart->product_inner->offered;
            //         $flashPrice = 0;
            //         if (!is_null($cart->product_inner->end_time)) {
            //             $flashPrice = (float)$cart->product_inner->price;
            //         }
            //         if ($inventoryPrice > 0) {
            //             $currentPrice = $inventoryPrice;
            //         } else if ($flashPrice > 0) {
            //             $currentPrice = $flashPrice;
            //         } else if ($flashPrice > 0) {
            //             $currentPrice = $offered;
            //         } else {
            //             $currentPrice = $selling;
            //         }
            //         // Bundle calculation
            //         $bundleQtyOffer = 0;
            //         $bundleDeal = $cart->product_inner->bundle_deal;
            //         if ($bundleDeal) {
            //             if ($cart->quantity >= $bundleDeal->buy) {
            //                 $bundleQtyOffer = $bundleDeal->free;
            //             }
            //         }
            //         $totalPriceWithoutShipping +=
            //             (float)$currentPrice * ((int)$cart->quantity - $bundleQtyOffer);
            //     }
            // }

            // if ($totalPriceWithoutShipping < $voucher->min_spend) {
            if ($totalAmount < $voucher->min_spend) {
                $setting = Setting::select('currency', 'currency_icon')->first();
                return response()->json(Validation::error(null,
                    __('lang.least_spend', ['amount' => $setting->currency_icon . $voucher->min_spend], $lang)
                ));
            }


            $totalOrdered = Voucher::redemptionQuery($voucher->id)->count();


            if ($totalOrdered >= $voucher->usage_limit) {
                return response()->json(Validation::error(null,
                    __('lang.limit_exceeded', [], $lang)
                ));
            }


            $OrderedByUserQuery =  Voucher::redemptionQuery($voucher->id);
            if ($request->user('user')) {

                $OrderedByUserQuery = $OrderedByUserQuery->where('user_id', $request->user('user')->id);

            } else if($request->user_token){

                $OrderedByUserQuery = $OrderedByUserQuery->where('user_token', $request->user_token);

            } else {

                return response()->json(Validation::errorLang($lang));
            }

            $totalOrderedByUser = $OrderedByUserQuery->count();


            if ($totalOrderedByUser >= $voucher->limit_per_customer) {
                return response()->json(Validation::error(null,
                    __('lang.maximum_time', [], $lang)
                ));
            }

            $start = new Carbon($voucher->start_time);
            $end = new Carbon($voucher->end_time);
            $now = Carbon::now();


            if ($start < $now && $now < $end) {

                /*
                 * QA audit CPN-04: the cap was applied with an (int) cast, so a
                 * 10.99 cap previewed as 10 and a 0.99 cap as nothing. Same
                 * method the checkout uses, so preview and charge agree.
                 */
                $offered = Pricing::voucherDiscount($voucher, $totalAmount);

                /*
                 * QA audit CPN-02: this endpoint used to write to the cart while
                 * claiming to validate a code. Clearing a previously applied
                 * code belongs to whatever applies one, not to a preview.
                 */

                return response()->json(new Response($request->token,
                    [
                        'offered' => number_format($offered, 2, '.', ''),
                        'voucher' => $request->voucher,
                        'apply_type' => $voucher->apply_type,
                        'subtotal' => number_format($totalAmount, 2, '.', ''),
                        'gift_balance' => $voucher->isGiftCard()
                            ? number_format($voucher->giftBalance(), 2, '.', '') : null,
                    ]));
            }

            return response()->json(Validation::error(null,
                __('lang.voucher_expired', [], $lang)
            ));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function all(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'voucher.view')) {
                return $can;
            }

            // Customers' redeemed gift cards live under Gift Card Sales, not here.
            $query = Voucher::query()->whereNull('vouchers.gift_value');
            $query = $query->orderBy('vouchers.' . $request->orderby, $request->type);

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }

            if ($lang) {
                $query = $query->leftJoin('voucher_langs as pcl', function ($join) use ($lang) {
                    $join->on('pcl.voucher_id', '=', 'vouchers.id');
                    $join->where('pcl.lang', $lang);
                });
                $query = $query->select('vouchers.*', 'pcl.title');

                if ($request->q) {
                    $query = $query->where('pcl.title', 'LIKE', "%{$request->q}%");
                }

            } else {

                if ($request->q) {
                    $query = $query->where('vouchers.title', 'LIKE', "%{$request->q}%");
                }
            }


            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            if ($request->time_zone) {
                foreach ($data as $item) {
                    $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                    $item['start_time'] = Utils::convertTimeToUSERzone($item->start_time, $request->time_zone);
                    $item['end_time'] = Utils::convertTimeToUSERzone($item->end_time, $request->time_zone);
                }
            } else {
                foreach ($data as $item) {
                    $item['created'] = Utils::formatDate($item->created_at);
                    $item['start_time'] = $item->start_time;
                    $item['end_time'] = $item->end_time;
                }
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'voucher.view')) {
                return $can;
            }

            $query = Voucher::query();
            if ($lang) {
                $query = $query->leftJoin('voucher_langs as trl', function ($join) use ($lang) {
                    $join->on('trl.voucher_id', '=', 'vouchers.id');
                    $join->where('trl.lang', $lang);
                });
                $query = $query->select('vouchers.*', 'trl.title');
            }
            $data = $query->with('products:id')->find($id);

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $data)) {
                return $isOwner;
            }

            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $data['product_ids'] = $data->products ? $data->products->pluck('id') : [];

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function action(Request $request, $id = null)
    {

        try {

            $lang = $request->header('language');


            $validate = Validation::voucherRules($request);
            if ($validate) {
                return response()->json($validate);
            }


            $endTime = date('Y-m-d H:i:s', strtotime($request->end_time));
            $startTime = date('Y-m-d H:i:s', strtotime($request->start_time));

            if ($endTime <= $startTime) {
                return response()->json(Validation::error(null,
                    __('lang.time_greater', [], $lang)
                ));
            }


            if ($request->time_zone) {
                if ($request->end_time) {
                    $request['end_time'] = Utils::convertTimeToUTCzone($request->end_time, $request->time_zone);
                }
                if ($request->start_time) {
                    $request['start_time'] = Utils::convertTimeToUTCzone($request->start_time, $request->time_zone);
                }
            }
            if ($id) {
                if ($can = Utils::userCan($this->user, 'voucher.edit')) {
                    return $can;
                }

                $existing = Voucher::find($id);
                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }

                // $filtered = array_filter($request->all(), function ($element) {
                //     return '' !== trim($element);
                // });

                $filtered = array_filter(
                    $request->all(),
                    function ($element) {

                        if (is_array($element)) {
                            return !empty($element);
                        }

                        return '' !== trim((string)$element);
                    }
                );


                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($filtered, ['title']);

                    Voucher::where('id', $id)->update($mainData);

                    $existingLang = VoucherLang::where('voucher_id', $id)
                        ->where('lang', $lang)->first();

                    if (!$existingLang) {
                        $langData['voucher_id'] = $id;
                        $langData['lang'] = $lang;
                        VoucherLang::create($langData);

                    } else {

                        VoucherLang::where('id', $existingLang->id)->update($langData);
                    }
                } else {
                    unset($filtered['product_ids']);
                    unset($filtered['products']);
                    Voucher::where('id', $id)->update($filtered);
                }

                if ($request->apply_type == 2) {
                    $existing->products()->sync(
                        $request->product_ids ?? []
                    );
                } else {
                    $existing->products()->detach();
                }


            } else {

                if ($can = Utils::userCan($this->user, 'voucher.create')) {
                    return $can;
                }

                $voucherFromDb = Voucher::where('code', $request->code)
                    ->get()
                    ->first();

                if (!is_null($voucherFromDb)) {
                    return response()->json(Validation::error(null,
                        __('lang.voucher_exists', [], $lang)
                    ));
                }

                $request['admin_id'] = $request->user()->id;


                if ($lang) {
                    $data = $request->except([
                        'product_ids',
                        'products'
                    ]);
                    [$langData, $mainData] = Utils::seperateLangData($data, ['title']);
                    $voucher = Voucher::create($mainData);

                    $langData['voucher_id'] = $voucher->id;
                    $langData['lang'] = $lang;
                    VoucherLang::create($langData);
                    $id = $voucher->id;

                } else {
                    $data = $request->except([
                        'product_ids',
                        'products'
                    ]);

                    $voucher = Voucher::create($data);
                    $id = $voucher->id;
                }
                if (
                    $request->apply_type == 2 &&
                    $request->product_ids
                ) {
                    $voucher->products()->sync(
                        $request->product_ids
                    );
                }
            }


            $query = Voucher::query();
            if ($lang) {
                $query = $query->leftJoin('voucher_langs as trl', function ($join) use ($lang) {
                    $join->on('trl.voucher_id', '=', 'vouchers.id');
                    $join->where('trl.lang', $lang);
                });
                $query = $query->select('vouchers.*', 'trl.title');
            }
            $data = $query->with('products:id')->find($id);

            $data['product_ids'] = $data->products ? $data->products->pluck('id') : [];
            if ($request->time_zone) {
                $data['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($data->created_at, $request->time_zone));
                $data['start_time'] = Utils::convertTimeToUSERzone($data->start_time, $request->time_zone);
                $data['end_time'] = Utils::convertTimeToUSERzone($data->end_time, $request->time_zone);

            } else {
                $data['created'] = Utils::formatDate($data->created_at);
                $data['start_time'] = $data->start_time;
                $data['end_time'] = $data->end_time;
            }
            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'voucher.delete')) {
                return $can;
            }

            $ids =  explode(",", $id);

            foreach ($ids as $i){
                $voucher = Voucher::find($i);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $voucher)) {
                    return $isOwner;
                }

                if (is_null($voucher)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                $order = Order::where('voucher_id', $i)->first();

                if ($order) {
                    return response()->json(Validation::error($request->token,
                        __('lang.used_in_order', [], $lang)
                    ));
                }

                VoucherLang::where('voucher_id', $i)->delete();

                $voucher->delete();
            }


            return response()->json(new Response($request->token, true));

            //return response()->json(Validation::errorTokenLang($request->token, $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }


    }
}
