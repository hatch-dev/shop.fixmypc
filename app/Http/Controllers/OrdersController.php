<?php

namespace App\Http\Controllers;

use App\Models\BusinessProductPricing;
use App\Models\Cancellation;
use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SupplierCategory;
use App\Http\Controllers\BidController;
use App\Models\SupplierBid;
use App\Models\SupplierNotification;
use App\Exceptions\StockRanOut;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Loyalty;
use App\Models\Helper\Membership;
use App\Models\Helper\MailBrand;
use App\Models\Helper\MailDeliverability;
use App\Models\Helper\MailHelper;
use App\Models\Helper\OrderEmailDispatcher;
use App\Models\Helper\OrderEmailEvent;
use App\Models\Helper\OrderMailer;
use App\Models\Helper\OrderSummary;
use App\Models\Helper\Response;
use App\Models\Helper\WalletSplit;
use App\Models\Helper\CartHold;
use App\Models\Helper\CheckoutStock;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Helper\ShipmentTracking;
use App\Models\IyzicoPayment;
use App\Models\Licence;
use App\Models\Order;
use App\Models\OrderedVoucher;
use App\Models\OrderedProductsInventory;
use App\Models\OrderedFlashDiscount;
use App\Models\FlashDiscount;
use App\Models\OrderedProduct;
use App\Models\Payment;
use App\Models\Plugin;
use App\Models\Setting;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\Helper\BundleDiscount;
use App\Models\Helper\FlashSaleAllowance;
use App\Models\Helper\Pricing;
use App\Models\Helper\Shipping;
use App\Models\OrderedBundleDiscount;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Console\Commands\CancelUnpaidBankTransfers;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Razorpay\Api\Api;
use PDF;
use Mail;
use App\Models\PayFast;
use App\Models\Supplier;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Crypt;

class OrdersController extends ControllerHelper
{
    /**
     * [order id => [[inventory id, units], ...]]: what placing that order has
     * taken off stock so far in this request, so rollbackOrder() gives back
     * exactly that -- including units taken before the order's lines were
     * written, which restoring from ordered_products used to miss.
     */
    private $stockTaken = [];

    public function vendorAll(Request $request)
    {
        try {
            $lang = $request->header('language');

            if (!$this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.view')) {
                return $can;
            }

            $adminId = $this->user->id;
            $query = Order::query();

            $query = $query->with('address');
            $query = $query->with('user_info');
            $query = $query->with('cancellation');
            $query = $query->with('address');
            // businessProfile as well, so the invoice can show the company being
            // billed and its VAT number on a business or trade order.
            $query = $query->with('user.businessProfile');
            $query = $query->with('guest_user');

            $query = $query->with('ordered_products.shipping_place');



            $query = $query->orderBy('orders.' . $request->orderby, $request->type);


            if($request->start_time || $request->end_time) {

                $startTime = $endTime = null;
                if ($request->time_zone && $request->start_time) {
                    $startTime = $request->start_time;
                }

                if ($request->time_zone && $request->end_time) {
                    $endTime = $request->end_time;
                }

                if($startTime && $endTime) {
                    if($startTime > $endTime) {
                        $temptEnd = $endTime;
                        $endTime = $startTime;
                        $startTime = $temptEnd;
                    }

                    $startOfDay = Carbon::parse($startTime)->startOfDay();
                    $endOfDay = Carbon::parse($endTime)->endOfDay();

                } else if($startTime) {
                    $startOfDay = Carbon::parse($startTime)->startOfDay();
                    $endOfDay = Carbon::parse($startTime)->endOfDay();

                } else if($endTime) {

                    $startOfDay = Carbon::parse($endTime)->startOfDay();
                    $endOfDay = Carbon::parse($endTime)->endOfDay();
                }

                if ($request->time_zone) {
                    $startOfDay = Utils::convertTimeToUTCzone($startOfDay, $request->time_zone);
                    $endOfDay = Utils::convertTimeToUTCzone($endOfDay, $request->time_zone);
                }

                $query = $query->where('created_at', '>=', $startOfDay);
                $query = $query->where('created_at', '<=', $endOfDay);
            }

            if ($request->filter) {

                foreach (explode(',', $request->filter) as $i) {
                    if ($i == 'cancelled') {
                        $query = $query->orWhere('cancelled', 1)
                            ->whereHas('ordered_products.product', function ($query) use ($adminId) {
                                $query->where('admin_id', $adminId);
                            });
                    }
                    if ($i == 'paid') {
                        $query = $query->orWhere('payment_done', 1)
                            ->whereHas('ordered_products.product', function ($query) use ($adminId) {
                                $query->where('admin_id', $adminId);
                            });
                    }
                    if ($i == 'unpaid') {
                        $query = $query->orWhere('payment_done', 0)
                            ->whereHas('ordered_products.product', function ($query) use ($adminId) {
                            $query->where('admin_id', $adminId);
                        });
                    }
                    if ($i == 'card_payment') {
                        $query = $query
                            ->orWhere('order_method', Config::get('constants.paymentMethod.RAZORPAY'))
                            ->orWhere('order_method', Config::get('constants.paymentMethod.STRIPE'))
                            ->orWhere('order_method', Config::get('constants.paymentMethod.FLUTTERWAVE'))
                            ->orWhere('order_method', Config::get('constants.paymentMethod.IYZICO_PAYMENT'))
                            ->whereHas('ordered_products.product', function ($query) use ($adminId) {
                                $query->where('admin_id', $adminId);
                            });
                    }
                    if ($i == 'paypal') {
                        $query = $query
                            ->orWhere('order_method', Config::get('constants.paymentMethod.PAYPAL'))
                            ->whereHas('ordered_products.product', function ($query) use ($adminId) {
                                $query->where('admin_id', $adminId);
                            });
                    }
                    if ($i == 'cash_on_delivery') {
                        $query = $query->orWhere('order_method',
                            Config::get('constants.paymentMethod.CASH_ON_DELIVERY'))
                            ->whereHas('ordered_products.product', function ($query) use ($adminId) {
                                $query->where('admin_id', $adminId);
                            });
                    }
                }
            } else {


                $query = $query->whereHas('ordered_products.product', function ($query) use ($adminId) {
                    $query->where('admin_id', $adminId);
                });


            }


            if ($lang) {

                $query = $query->with(['ordered_products.product' => function ($query) use ($lang, $adminId) {
                    $query->where('products.admin_id', $adminId)
                        ->leftJoin('product_langs as pl',
                            function ($join) use ($lang) {
                                $join->on('products.id', '=', 'pl.product_id');

                                $join->where('pl.lang', $lang);
                            })
                        ->where('products.admin_id', $adminId)
                        ->select('products.id', 'products.title', 'products.image', 'products.selling',
                            'products.offered', 'products.shipping_rule_id',
                            'products.bundle_deal_id', 'products.unit', 'pl.title');
                }]);


                $query = $query->with(['voucher' => function ($query) use ($lang) {
                    $query->leftJoin('voucher_langs as vl',
                        function ($join) use ($lang) {
                            $join->on('vouchers.id', '=', 'vl.voucher_id');
                            $join->where('vl.lang', $lang);
                        })
                        ->select('vouchers.*', 'vl.title');
                }]);


                $query = $query->with(['ordered_products.updated_inventory.inventory_attributes.attribute_value' =>
                    function ($query) use ($lang) {
                        $query->leftJoin('attribute_value_langs as avl',
                            function ($join) use ($lang) {
                                $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                                $join->where('avl.lang', $lang);
                            })
                            ->with(['attribute' => function ($query) use ($lang) {

                                $query->leftJoin('attribute_langs as al',
                                    function ($join) use ($lang) {
                                        $join->on('attributes.id', '=', 'al.attribute_id');
                                        $join->where('al.lang', $lang);
                                    })
                                    ->select('attributes.id',
                                        \DB::raw('COALESCE(al.title, attributes.title) as title'));
                            }])
                            ->select('attribute_values.*',
                                \DB::raw('COALESCE(avl.title, attribute_values.title) as title'));
                    }]);


            } else {

                $query = $query->with(['ordered_products.product' => function ($subQuery) use ($adminId) {
                    $subQuery->where('products.admin_id', $adminId)
                        ->select('products.id', 'products.title', 'products.image', 'products.selling',
                            'products.offered', 'products.shipping_rule_id',
                            'products.bundle_deal_id', 'products.unit', 'products.title');
                }]);

                $query = $query->with('voucher')
                    ->with('ordered_products.updated_inventory.inventory_attributes.attribute_value')
                    ->with('ordered_products.updated_inventory.inventory_attributes.attribute_value.attribute');
            }


            $query = $query->select('orders.*');
            $data = $query->paginate(Config::get('constants.api.PAGINATION'));


            /*
             * The page's summaries in a fixed number of queries; see
             * OrderSummary::inventoriesFor(). Each order used to fetch its own
             * line prices and its own voucher, flash, loyalty and points rows
             * while being summarised.
             */
            $data->loadMissing(OrderSummary::SUMMARY_RELATIONS);

            $inventories = OrderSummary::inventoriesFor($data->pluck('id'));

            $orderIds = [];

            // One loop, not two near-identical ones differing only in whether
            // the date is converted.
            foreach ($data as $item) {

                array_push($orderIds, $item->id);

                $orderedProducts = [];

                foreach ($item->ordered_products as $j) {
                    if ($j->product) {
                        array_push($orderedProducts, $j);
                    }
                }

                $item['calculated'] = Utils::calcPrice(
                    $item, $inventories->get($item->id, collect())
                );

                unset($item['ordered_products']);
                $item['ordered_products'] = $orderedProducts;

                $item['created'] = Utils::formatDate($request->time_zone
                    ? Utils::convertTimeToUSERzone($item->created_at, $request->time_zone)
                    : $item->created_at);
            }


            Order::whereIn('id', $orderIds)->where('viewed', false)->update([
                'viewed' => true
            ]);




            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function all(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.view')) {
                return $can;
            }

            /*
             * Before the list is read, so an overdue unpaid bank transfer never
             * shows as pending. The schedule does the same; this covers the
             * host where cron is not ticking. It must never cost the list.
             */
            try {
                CancelUnpaidBankTransfers::sweep();
            } catch (\Throwable $e) {
                Log::error('Unpaid bank transfer sweep failed', ['message' => $e->getMessage()]);
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



            $query = Order::query();

            $query = $query->with('address');
            $query = $query->with('user_info');
            $query = $query->orderBy('orders.' . $request->orderby, $request->type);


            if($posLicenceValid && $posPlugin && $posPlugin->active){
                if(!$request->order_type || ($request->order_type && $request->order_type == 'website')){

                    $query = $query->where('pos_order_id', null);

                } else if($request->order_type && $request->order_type == 'pos'){

                    $query = $query->where('pos_order_id', '!=', null);
                }
            }


            if($request->start_time || $request->end_time) {

                $startTime = $endTime = null;
                if ($request->time_zone && $request->start_time) {
                   $startTime = $request->start_time;
                }

                if ($request->time_zone && $request->end_time) {
                    $endTime = $request->end_time;
                }

                if($startTime && $endTime) {
                    if($startTime > $endTime) {
                        $temptEnd = $endTime;
                        $endTime = $startTime;
                        $startTime = $temptEnd;
                    }

                    $startOfDay = Carbon::parse($startTime)->startOfDay();
                    $endOfDay = Carbon::parse($endTime)->endOfDay();

                } else if($startTime) {
                    $startOfDay = Carbon::parse($startTime)->startOfDay();
                    $endOfDay = Carbon::parse($startTime)->endOfDay();

                } else if($endTime) {

                    $startOfDay = Carbon::parse($endTime)->startOfDay();
                    $endOfDay = Carbon::parse($endTime)->endOfDay();
                }

                if ($request->time_zone) {
                    $startOfDay = Utils::convertTimeToUTCzone($startOfDay, $request->time_zone);
                    $endOfDay = Utils::convertTimeToUTCzone($endOfDay, $request->time_zone);
                }

                $query = $query->where('created_at', '>=', $startOfDay);
                $query = $query->where('created_at', '<=', $endOfDay);
            }


            /*
             * One customer's orders.
             *
             * Section 28: an admin looking at a customer should be able to get
             * to their orders and back. ListPage forwards the whole query
             * string to this endpoint, so the customer screen only has to link
             * to /orders?user_id=N.
             */
            if ($request->user_id) {
                $query = $query->where('user_id', (int)$request->user_id);
            }

            if ($request->filter) {

                /*
                 * The chips are three independent questions, not one list.
                 *
                 * Every chip used to add an orWhere to a single group, so
                 * ticking "Paid" and "Cash on delivery" asked for
                 * "payment_done = 1 OR order_method = 2" — every paid order of
                 * any method, plus every cash order including the unpaid ones.
                 * The admin ticked Paid and got unpaid orders back, which is
                 * exactly what a payment filter must never do.
                 *
                 * Chips within one dimension still widen the result (Paid or
                 * Unpaid; card or PayPal or cash). Dimensions narrow it.
                 */
                $chips = array_filter(array_map('trim', explode(',', $request->filter)));

                $methods = [];

                if (in_array('card_payment', $chips, true)) {
                    $methods = array_merge($methods, [
                        Config::get('constants.paymentMethod.RAZORPAY'),
                        Config::get('constants.paymentMethod.STRIPE'),
                        Config::get('constants.paymentMethod.FLUTTERWAVE'),
                        Config::get('constants.paymentMethod.IYZICO_PAYMENT'),
                        // A card taken through SumUp is a card payment. It was
                        // missing, so this filter hid every SumUp order.
                        Config::get('constants.paymentMethod.SUMUP'),
                    ]);
                }

                if (in_array('paypal', $chips, true)) {
                    $methods[] = Config::get('constants.paymentMethod.PAYPAL');
                }

                if (in_array('cash_on_delivery', $chips, true)) {
                    $methods[] = Config::get('constants.paymentMethod.CASH_ON_DELIVERY');
                }

                if (in_array('bank_transfer', $chips, true)) {
                    $methods[] = Config::get('constants.paymentMethod.BANK');
                }

                if ($methods) {
                    $query = $query->whereIn('order_method', $methods);
                }

                /*
                 * The bank transfers still to be checked off against the bank
                 * account: unpaid and not cancelled, whether or not the
                 * customer has sent a reference. The unpaid-transfer sweep
                 * leaves those with a reference alone, so this is where they
                 * are found.
                 */
                if (in_array('awaiting_transfer', $chips, true)) {
                    $query = $query->where('order_method', Config::get('constants.paymentMethod.BANK'))
                        ->where('payment_done', 0)
                        ->where('cancelled', '!=', 1)
                        ->whereNotIn('status', [
                            Config::get('constants.orderStatus.CANCELLED'),
                            Config::get('constants.orderStatus.REFUNDED'),
                        ]);
                }

                $paid = in_array('paid', $chips, true);
                $unpaid = in_array('unpaid', $chips, true);

                // Both ticked is the same question as neither: show everything.
                if ($paid xor $unpaid) {
                    $query = $query->where('payment_done', $paid ? 1 : 0);
                }

                /*
                 * The flag alone is only the customer's own cancellations; a
                 * cancellation by staff or the unpaid-transfer sweep moves the
                 * status and leaves the flag at 0, and the chip hid them all.
                 */
                if (in_array('cancelled', $chips, true)) {
                    $query = $query->where(function ($q) {
                        $q->where('cancelled', 1)
                            ->orWhere('status', Config::get('constants.orderStatus.CANCELLED'));
                    });
                }
            }


            $query = $query->with('cancellation');
            $query = $query->with('address');
            // businessProfile as well, so the invoice can show the company being
            // billed and its VAT number on a business or trade order.
            $query = $query->with('user.businessProfile');
            $query = $query->with('guest_user');

            $query = $query->with('ordered_products.shipping_place');


            if ($lang) {

                $query = $query->with(['ordered_products.product' => function ($query) use ($lang) {
                    $query->leftJoin('product_langs as pl',
                        function ($join) use ($lang) {
                            $join->on('products.id', '=', 'pl.product_id');
                            $join->where('pl.lang', $lang);
                        })
                        ->select('products.id', 'products.title', 'products.image', 'products.selling',
                            'products.offered', 'products.shipping_rule_id',
                            'products.bundle_deal_id', 'products.unit', 'pl.title');
                }]);


                $query = $query->with(['voucher' => function ($query) use ($lang) {
                    $query->leftJoin('voucher_langs as vl',
                        function ($join) use ($lang) {
                            $join->on('vouchers.id', '=', 'vl.voucher_id');
                            $join->where('vl.lang', $lang);
                        })
                        ->select('vouchers.*', 'vl.title');
                }]);


                $query = $query->with(['ordered_products.updated_inventory.inventory_attributes.attribute_value' =>
                    function ($query) use ($lang) {
                        $query->leftJoin('attribute_value_langs as avl',
                            function ($join) use ($lang) {
                                $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                                $join->where('avl.lang', $lang);
                            })
                            ->with(['attribute' => function ($query) use ($lang) {

                                $query->leftJoin('attribute_langs as al',
                                    function ($join) use ($lang) {
                                        $join->on('attributes.id', '=', 'al.attribute_id');
                                        $join->where('al.lang', $lang);
                                    })
                                    ->select('attributes.id',
                                        \DB::raw('COALESCE(al.title, attributes.title) as title'));
                            }])
                            ->select('attribute_values.*',
                                \DB::raw('COALESCE(avl.title, attribute_values.title) as title'));
                    }]);


            } else {

                $query = $query->with('ordered_products.product');
                $query = $query->with('voucher')
                    ->with('ordered_products.updated_inventory.inventory_attributes.attribute_value')
                    ->with('ordered_products.updated_inventory.inventory_attributes.attribute_value.attribute');
            }




            $query = $query->select('orders.*');
            $data = $query->paginate(Config::get('constants.api.PAGINATION'));


            /*
             * Everything the page's summaries need, in a fixed number of
             * queries rather than five per row.
             *
             * Each order used to fetch its own line prices, voucher, flash
             * discount, loyalty discount and points while being summarised —
             * about fifty extra statements on a full page, to print a table.
             */
            $data->loadMissing(OrderSummary::SUMMARY_RELATIONS);

            $inventories = OrderSummary::inventoriesFor($data->pluck('id'));

            $orderIds = [];

            foreach ($data as $item) {

                array_push($orderIds, $item->id);

                $lineInventories = $inventories->get($item->id, collect());

                /*
                 * The list's rows are what the bulk "Print invoice" action
                 * renders, and an invoice prints the per-line list price,
                 * discount and line total. Without decorating here those rows
                 * carried only `selling`, so a bulk-printed invoice showed
                 * blank original prices and no discount while the same order
                 * printed correctly from its own screen.
                 */
                OrderSummary::decorate($item, $lineInventories);

                $item['calculated'] = Utils::calcPrice($item, $lineInventories);

                /*
                 * The customer, by the same rules the detail screen uses.
                 *
                 * The list read `address.name` and fell back to
                 * `address.email`, so every click-and-collect order — which has
                 * no address, because nothing is being posted — showed a blank
                 * Customer column, and the whole users row was being serialised
                 * beside it to no purpose.
                 */
                $item['customer'] = $this->customerSummary($item);

                unset($item['user']);

                $item['created'] = Utils::formatDate($request->time_zone
                    ? Utils::convertTimeToUSERzone($item->created_at, $request->time_zone)
                    : $item->created_at);
            }

            Order::whereIn('id', $orderIds)->where('viewed', false)->update([
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

            if ($can = Utils::userCan($this->user, 'order.view')) {
                return $can;
            }

            $adminId = $this->user->id;

            $query = Order::query();
            $query = $query->with('cancellation');
            $query = $query->with('address');
            // businessProfile as well, so the invoice can show the company being
            // billed and its VAT number on a business or trade order.
            $query = $query->with('user.businessProfile');
            $query = $query->with('guest_user');

            $query = $query->with('ordered_products.shipping_place');

            if ($lang) {

                if (!$this->isSuperAdmin) {

                    $query = $query->with(['ordered_products.product' => function ($query) use ($lang, $adminId) {

                        $query->with(['product_images' => function($query){
                            $query->with(['attributes' => function($query){}]);
                        }]);

                        $query->where('products.admin_id', $adminId)
                            ->leftJoin('product_langs as pl',
                                function ($join) use ($lang) {
                                    $join->on('products.id', '=', 'pl.product_id');
                                    $join->where('pl.lang', $lang);
                                })
                            ->select('products.id', 'products.title', 'products.image', 'products.selling',
                                'products.offered', 'products.shipping_rule_id',
                                'products.bundle_deal_id', 'products.unit', 'pl.title');
                    }]);


                } else {
                    $query = $query->with(['ordered_products.product' => function ($query) use ($lang) {

                        $query->with(['product_images' => function($query){
                            $query->with(['attributes' => function($query){}]);
                        }]);

                        $query->leftJoin('product_langs as pl',
                            function ($join) use ($lang) {
                                $join->on('products.id', '=', 'pl.product_id');
                                $join->where('pl.lang', $lang);
                            })
                            ->select('products.id', 'products.title', 'products.image', 'products.selling',
                                'products.offered', 'products.shipping_rule_id',
                                'products.bundle_deal_id', 'products.unit', 'pl.title');
                    }]);

                }


                $query = $query->with(['voucher' => function ($query) use ($lang) {
                    $query->leftJoin('voucher_langs as vl',
                        function ($join) use ($lang) {
                            $join->on('vouchers.id', '=', 'vl.voucher_id');
                            $join->where('vl.lang', $lang);
                        })
                        ->select('vouchers.*', 'vl.title');
                }]);


                $query = $query->with(['ordered_products.updated_inventory.inventory_attributes.attribute_value' =>
                    function ($query) use ($lang) {
                        $query->leftJoin('attribute_value_langs as avl',
                            function ($join) use ($lang) {
                                $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                                $join->where('avl.lang', $lang);
                            })
                            ->with(['attribute' => function ($query) use ($lang) {

                                $query->leftJoin('attribute_langs as al',
                                    function ($join) use ($lang) {
                                        $join->on('attributes.id', '=', 'al.attribute_id');
                                        $join->where('al.lang', $lang);
                                    })
                                    ->select('attributes.id',
                                        \DB::raw('COALESCE(al.title, attributes.title) as title'));
                            }])

                            ->select('attribute_values.*',
                                \DB::raw('COALESCE(avl.title, attribute_values.title) as title'));
                    }]);


            } else {


                if (!$this->isSuperAdmin) {

                    $query = $query->with(['ordered_products.product' => function ($query) use ($adminId) {

                        $query->with(['product_images' => function($query){
                            $query->with(['attributes' => function($query){}]);
                        }]);

                        $query->where('products.admin_id', $adminId)
                            ->select('products.id', 'products.title', 'products.image', 'products.selling',
                                'products.offered', 'products.shipping_rule_id',
                                'products.bundle_deal_id', 'products.unit', 'products.title');
                    }]);


                } else {
                    $query = $query->with(['ordered_products.product' => function($query){

                        $query->with(['product_images' => function($query){
                            $query->with(['attributes' => function($query){}]);
                        }]);
                    }]);
                }


                $query = $query->with('voucher')
                    ->with('ordered_products.updated_inventory.inventory_attributes.attribute_value.attribute');
            }

            if (!$this->isSuperAdmin) {
                $query = $query->whereHas('ordered_products.product', function ($query) use ($adminId) {
                    $query->where('admin_id', $adminId);
                });
            }

            $order = $query->find($id);

            if (is_null($order)) {
                return response()->json(Validation::nothingFoundLang($lang));
            }

            /*
             * The same summary, off the same persisted rows, that the customer
             * sees on their order confirmation.
             *
             * decorate() also stamps each line's frozen figures onto the
             * ordered_products being serialised — actual_price, line_discount,
             * line_total, tax_total — which the admin screen had no access to
             * at all. It could show only `selling`, the price charged, so a
             * business order's quantity-band discount was invisible to whoever
             * was answering the phone about it.
             *
             * `calculated` stays for the invoice component, which reads it.
             */
            $summary = OrderSummary::decorate($order);

            $order['calculations'] = [
                'subtotal' => number_format($summary['subtotal'], 2, '.', ''),
                'discount' => number_format($summary['discount'], 2, '.', ''),
                'bundle_offer' => number_format($summary['bundle_offer'], 2, '.', ''),
                'flash_discount' => number_format($summary['flash_discount'], 2, '.', ''),
                // The Essentials Bundle saving, so the invoice can state it
                // instead of leaving it as an unexplained gap in the total.
                'bundle_discount' => number_format($summary['bundle_discount'], 2, '.', ''),
                'shipping' => number_format($summary['shipping'], 2, '.', ''),
                'tax' => number_format($summary['tax'], 2, '.', ''),
                'voucher' => number_format($summary['voucher'], 2, '.', ''),
                'loyalty_discount' => number_format($summary['loyalty_discount'], 2, '.', ''),
                'loyalty_label' => $summary['loyalty_label'],
                /*
                 * The same order as a VAT document states it: the goods net of
                 * VAT, the tax on them, and the two together. Split by
                 * OrderSummary from what the order recorded - never from the
                 * product tax rules as they stand today.
                 */
                'ex_vat' => number_format($summary['ex_vat'], 2, '.', ''),
                'vat' => number_format($summary['vat'], 2, '.', ''),
                'inc_vat' => number_format($summary['inc_vat'], 2, '.', ''),
                /*
                 * The same goods stated gross, for a summary that leads with
                 * the inc-VAT figure:
                 *   subtotal_inc_vat - discount_inc_vat = inc_vat
                 */
                'subtotal_inc_vat' => number_format($summary['subtotal_inc_vat'], 2, '.', ''),
                'discount_inc_vat' => number_format($summary['discount_inc_vat'], 2, '.', ''),
                // The same goods stated net: subtotal_ex_vat - discount_ex_vat = ex_vat
                'subtotal_ex_vat' => number_format($summary['subtotal_ex_vat'], 2, '.', ''),
                'discount_ex_vat' => number_format($summary['discount_ex_vat'], 2, '.', ''),
                'order_wide_discount' => number_format($summary['order_wide_discount'], 2, '.', ''),
                // One row per rate actually charged; an invoice has to state them.
                'vat_breakdown' => $summary['vat_breakdown'],
                'points_discount' => number_format($summary['points_discount'], 2, '.', ''),
                'total' => number_format($summary['total'], 2, '.', ''),
                // split payment: wallet part, and what the order's method took
                'wallet_paid' => number_format($summary['wallet_paid'], 2, '.', ''),
                'amount_due' => number_format($summary['amount_due'], 2, '.', ''),
            ];

            $order['calculated'] = Utils::calcPrice($order);

            $orderedProducts = [];
            foreach ($order->ordered_products as $j) {
                if ($j->product) {
                    array_push($orderedProducts, $j);
                }
            }
            unset($order['ordered_products']);
            $order['ordered_products'] = $orderedProducts;

            /*
             * The customer, as an order screen needs them: who to contact and
             * what kind of account placed this.
             *
             * `user` used to be serialised whole. That is the entire users row
             * — the password hash and the two-factor secret are only kept out
             * of it by $hidden, and the phone-verification timestamps, the
             * marketing preferences and the social account ids all went to the
             * browser for no reason (the same class of leak as ADM-04). Named
             * fields only.
             */
            $order['customer'] = $this->customerSummary($order);

            // What this order is allowed to become, decided by the same map
            // updateStatus() enforces — so the screen cannot offer a move the
            // server will refuse.
            $order['allowed_transitions'] = array_values(
                Order::allowedTransitions()[(int)$order->status] ?? []
            );

            /*
             * Built before the key is written, and the relation dropped after.
             * Model::toArray() merges the relations over the attributes, so a
             * key that collides with a loaded relation name is silently
             * replaced by the raw relation rows on the way out.
             */
            $trail = $this->statusTrail($order, $request->time_zone);

            unset($order['status_trail']);

            $order['status_trail'] = $trail;

            /*
             * One date formatter for the three dates this screen shows, in the
             * caller's timezone when it names one. Null stays null: an order
             * paid before paid_at existed has no recorded payment time, and the
             * screen omits the row rather than printing a guess.
             */
            $date = function ($timestamp) use ($request) {
                if (is_null($timestamp)) {
                    return null;
                }

                return Utils::formatDate($request->time_zone
                    ? Utils::convertTimeToUSERzone($timestamp, $request->time_zone)
                    : $timestamp);
            };

            $order['created'] = $date($order->created_at);
            $order['updated'] = $date($order->updated_at);
            $order['paid_on'] = $date($order->paid_at);

            /*
             * Shipment tracking (AfterShip). Not keyed `shipment`: that is the
             * relation's name, and toArray() would put the raw row over it.
             */
            $shipment = \App\Models\OrderShipment::where('order_id', $order->id)->first();
            $order['shipment_tracking'] = $shipment ? $shipment->adminView() : null;
            // Couriers come from AfterShip through shipmentCouriers(), so a slow
            // AfterShip never holds up opening an order.
            $order['shipment_allowed'] = in_array((int)$order->status, ShipmentTracking::shippableStatuses(), true)
                && !ShipmentTracking::isCollection($order);

            unset($order['user']);

            return response()->json(new Response($request->token, $order));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }



    /**
     * The customer on an order, as the admin order screen needs them.
     *
     * Two separate problems this answers.
     *
     * The screen showed "N/A" for the name and the phone on orders that had
     * them, because it read only `address.name` and `address.phone`. A
     * click-and-collect order has no address at all — nothing is being posted —
     * so those were always empty, and the account holding the real name and
     * number was never consulted. Precedence here matches Order::contactDetails:
     * the account first, then the contact captured at checkout, then the
     * address, then the guest record.
     *
     * The account type, company, VAT and CRO come off the *order*, which froze
     * them at checkout, and fall back to the live profile only for orders
     * placed before that snapshot existed. A customer downgraded from business
     * to personal next month must not turn a business order into a personal
     * one on screen.
     */
    private function customerSummary($order)
    {
        $user = $order->user;
        $guest = $order->guest_user;
        $address = $order->address;
        $profile = $user ? $user->businessProfile : null;

        $first = function (...$values) {
            foreach ($values as $value) {
                if (!is_null($value) && trim((string)$value) !== '') {
                    return $value;
                }
            }
            return null;
        };

        // Frozen on the order; the live profile only backfills older orders.
        $accountType = $order->account_type
            ?: ($user ? ($user->account_type ?: 'personal') : 'guest');

        $snapshot = !is_null($order->account_type);

        return [
            'id' => $user ? $user->id : null,
            'name' => $first(
                $user ? $user->name : null,
                $order->contact_name,
                $address ? $address->name : null,
                $guest ? $guest->name : null
            ),
            'email' => $first(
                $user ? $user->email : null,
                $order->contact_email,
                $address ? $address->email : null,
                $guest ? $guest->email : null
            ),
            'phone' => $first(
                $user ? $user->phone : null,
                $order->contact_phone,
                $address ? $address->phone : null,
                $guest ? $guest->phone : null
            ),
            'is_guest' => is_null($user),
            'account_type' => $accountType,
            /*
             * Whether the four fields below came off the order or off the live
             * profile. The screen says so, because "Business" read from a
             * profile that has since changed is a weaker claim than "Business"
             * frozen at checkout, and support should be able to tell them apart.
             */
            'from_order_snapshot' => $snapshot,
            'company_name' => $snapshot
                ? $order->company_name
                : ($profile ? $profile->company_name : null),
            'vat_number' => $snapshot
                ? $order->vat_number
                : ($profile ? $profile->vat_number : null),
            'cro_number' => $snapshot
                ? $order->cro_number
                : ($profile ? $profile->cro_number : null),
            /*
             * Current-profile facts that were never part of the order and are
             * not claimed to be historical: what kind of business this is, and
             * whether they are invoiced against a PO. Useful context for the
             * person handling the order; clearly not order data.
             */
            /*
             * The registered address a business account is invoiced at, which
             * is not necessarily where the goods went. Held on the profile as
             * one free-text block, so it is passed through as written.
             */
            'billing_address' => $profile ? $profile->billing_address : null,
            'sector' => $profile ? $profile->sector : null,
            'requires_po' => $profile ? (bool)$profile->requires_po : false,
            'credit_application' => $profile ? (bool)$profile->credit_application : false,
            'trade' => $profile ? $profile->trade : null,
            'expected_spend' => $profile ? $profile->expected_spend : null,
            'website' => $profile ? $profile->website : null,
            'resale_confirmed' => $profile ? (bool)$profile->resale_confirmed : false,
            // Live account standing, labelled as such by the screen.
            'trade_status' => $user ? $user->trade_status : null,
            'account_status' => $user ? $user->status : null,
        ];
    }


    /**
     * The order's audit trail, oldest first.
     *
     * Rows have been written on every admin status change since ORD-03 and
     * nothing ever read them back. An order placed before that, or never
     * touched since, has none — so the first entry is synthesised from the
     * order's own creation, which is a fact the order carries either way.
     */
    private function statusTrail($order, $timeZone = null)
    {
        $format = function ($timestamp) use ($timeZone) {
            if (is_null($timestamp)) {
                return null;
            }

            return Utils::formatDate($timeZone
                ? Utils::convertTimeToUSERzone($timestamp, $timeZone)
                : $timestamp);
        };

        $trail = [[
            'to_status' => (int)Config::get('constants.orderStatus.PENDING'),
            'from_status' => null,
            'by' => $order->user_id ? 'customer' : 'guest',
            'by_name' => null,
            'note' => null,
            'at' => $format($order->created_at),
        ]];

        foreach ($order->status_trail as $row) {
            $trail[] = [
                'to_status' => (int)$row->to_status,
                'from_status' => is_null($row->from_status) ? null : (int)$row->from_status,
                'by' => $row->changed_by_type,
                // Only an admin row carries an admins.id in changed_by.
                'by_name' => $row->byAdmin() && $row->admin ? $row->admin->name : null,
                'note' => $row->note,
                'at' => $format($row->created_at),
            ];
        }

        return $trail;
    }


    public function updatePaymentMethod(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.edit')) {
                return $can;
            }

            $validate = Validation::orderPaymentMethod($request);
            if ($validate) {
                return response()->json($validate);
            }

            $order = Order::find($request->id);

            if (is_null($order)) {
                return response()->json(Validation::nothingFoundLang($lang));
            }

            Order::where('id', $request->id)->update(['order_method' => $request->order_method]);

            return response()->json(new Response($request->token, [
                'order_method' => $request->order_method,
                'id' => $request->id
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function updatePaymentStatus(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.edit')) {
                return $can;
            }

            $validate = Validation::orderPaymentStatus($request);
            if ($validate) {
                return response()->json($validate);
            }

            $order = Order::find($request->id);

            if (is_null($order)) {
                return response()->json(Validation::nothingFoundLang($lang));
            }

            $paid = (bool)$request->payment_done;

            /*
             * Payment stays freely reversible, unlike order status.
             *
             * That is deliberate rather than an oversight: an order marked paid
             * by mistake, a bounced transfer, a chargeback — all of them need
             * the flag moved back, and a one-way switch would leave the only
             * remedy being a database edit. So there is no transition map here.
             *
             * What is refused is a claim that cannot be true. An order that has
             * been cancelled or refunded is settled; asserting a payment
             * against it says the money arrived for goods nobody is sending,
             * and the number it would feed — the paid-revenue reporting — has
             * no way to tell that apart from a real sale.
             */
            /*
             * A bank transfer that arrived after the unpaid-transfer sweep had
             * cancelled the order. The money is real and the customer still
             * wants the goods, so where it is clean to do so the order is put
             * back rather than refunded. When it is not, the admin is told why
             * and the refusal below stands.
             */
            if ($paid && $order->isCancelled()) {
                $refusal = $this->reinstateLateBankTransfer($order);

                if (is_null($refusal)) {
                    $fresh = $order->fresh();

                    try {
                        $this->markOrderPaid($fresh, $lang);
                    } catch (\Throwable $e) {
                        Log::error('Payment received email could not be raised', [
                            'order_id' => $order->id,
                            'message' => $e->getMessage(),
                        ]);
                    }

                    return response()->json(new Response($request->token, [
                        'payment_done' => (int)$fresh->payment_done,
                        'status' => (int)$fresh->status,
                        'reinstated' => true,
                        'paid_at' => $fresh->paid_at ? Utils::formatDate($fresh->paid_at) : null,
                        'id' => $request->id
                    ]));
                }

                if ($refusal !== false) {
                    return response()->json(Validation::error($request->token, $refusal));
                }
            }

            if ($paid && (int)$order->status === (int)Config::get('constants.orderStatus.REFUNDED')) {
                return response()->json(Validation::error($request->token,
                    __('lang.paid_refunded_order', [], $lang)
                ));
            }

            // isCancelled(), not the flag: the flag is only the customer's
            // cancellation, and a staff-cancelled order slipped through.
            if ($paid && $order->isCancelled()) {
                return response()->json(Validation::error($request->token,
                    __('lang.paid_cancelled_order', [], $lang)
                ));
            }

            $wasPaid = (int)$order->payment_done === 1;

            Order::where('id', $request->id)->update(Order::paymentStamp($paid));

            /*
             * An admin asserting that money did or did not arrive is a
             * financial claim, and until now it left no trace beyond the bit it
             * flipped. order_status_histories is about where an order is, not
             * about its money, so this is recorded where the order deletions
             * are — outside the row being changed.
             */
            if ($wasPaid !== $paid) {
                Log::info('Order payment status changed by admin', [
                    'order_id' => $order->id,
                    'order_number' => $order->order,
                    'from' => $wasPaid ? 'paid' : 'unpaid',
                    'to' => $paid ? 'paid' : 'unpaid',
                    'order_method' => $order->order_method,
                    'total_amount' => $order->total_amount,
                    'admin_id' => optional($this->user)->id,
                    'admin_email' => optional($this->user)->email,
                ]);
            }

            $fresh = $order->fresh();

            /*
             * Money an admin has confirmed -- a bank transfer that arrived, cash
             * taken at the counter. The customer used to hear nothing: the
             * "payment received" email existed for exactly this and was only
             * ever sent by the gateways. markOrderPaid() is idempotent per
             * order, so flipping the flag back and forth sends it once.
             */
            if (!$wasPaid && $paid) {
                try {
                    $this->markOrderPaid($fresh, $lang);
                } catch (\Throwable $e) {
                    Log::error('Payment received email could not be raised', [
                        'order_id' => $order->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json(new Response($request->token, [
                // The stored value, not the requested one: they differ whenever
                // the update did anything other than exactly what was asked.
                'payment_done' => (int)$fresh->payment_done,
                'paid_at' => $fresh->paid_at
                    ? Utils::formatDate($request->time_zone
                        ? Utils::convertTimeToUSERzone($fresh->paid_at, $request->time_zone)
                        : $fresh->paid_at)
                    : null,
                'id' => $request->id
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function updateStatus(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.edit')) {
                return $can;
            }

            // QA audit ORD-03: 'status' used to be unvalidated, so any integer
            // was accepted, including values with no meaning.
            $validate = Validation::orderStatusChange($request);
            if ($validate) {
                return response()->json($validate);
            }

            $order = Order::find($request->id);

            if (is_null($order)) {
                return response()->json(Validation::nothingFoundLang($lang));
            }

            $newStatus = (int)$request->status;
            $oldStatus = (int)$order->status;

            if ($newStatus === $oldStatus) {
                return response()->json(new Response($request->token, ['result' => [
                    'status' => $newStatus,
                    'payment_done' => (int)$order->payment_done,
                    'id' => $request->id
                ]]));
            }

            if (!Order::canTransition($oldStatus, $newStatus)) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_status_change', [], $lang)
                ));
            }

            $this->applyStatusChange($order, $newStatus, optional($this->user)->id, 'admin', $request->note, $lang);

            $fresh = $order->fresh();

            return response()->json(new Response($request->token, ['result' =>
                [
                    'status' => $newStatus,
                    // Was hardcoded to PUBLIC whatever actually happened.
                    'payment_done' => (int)$fresh->payment_done,
                    'id' => $request->id
                ]]));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Move an order to a new status: the write, the stock/points/voucher
     * side effects, the audit row and the customer email.
     *
     * Was the body of updateStatus(); lifted out unchanged so that a shipment
     * saved from the order screen (On the way) and a courier's delivered scan
     * from AfterShip (Delivered) move an order exactly as the admin dropdown
     * does. The caller has already checked Order::canTransition().
     *
     * @param  int         $changedBy      admins.id, or null for the system
     * @param  string      $changedByType  'admin' | 'system' | 'aftership'
     */
    public function applyStatusChange(Order $order, $newStatus, $changedBy, $changedByType = 'admin', $note = null, $lang = null)
    {
        $newStatus = (int)$newStatus;
        $oldStatus = (int)$order->status;
        $updatedStatus = ['status' => $newStatus];

        /*
         * Cash is handed over on delivery, so delivering the order is the
         * moment it is paid — and now the moment it records as paid.
         */
        if ((int)Config::get('constants.orderStatus.DELIVERED') === $newStatus &&
            (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY') == (int)$order->order_method) {
            $updatedStatus = array_merge($updatedStatus, Order::paymentStamp(true));
        }

        /*
         * QA audit ORD-01. Cancelling or returning an order used to leave
         * the stock deduction, the loyalty points and the voucher
         * redemption exactly as they were.
         */
        $releasesStock = in_array($newStatus, [
            (int)Config::get('constants.orderStatus.CANCELLED'),
            (int)Config::get('constants.orderStatus.RETURNED'),
            (int)Config::get('constants.orderStatus.REFUNDED'),
        ], true);

        $delivered = (int)Config::get('constants.orderStatus.DELIVERED') === $newStatus;

        DB::transaction(function () use ($order, $updatedStatus, $releasesStock, $delivered, $oldStatus, $newStatus, $changedBy, $changedByType, $note) {

            Order::where('id', $order->id)->update($updatedStatus);

            if ($releasesStock) {
                $order->restoreStockAndRewards();
            }

            // After the status write above, so a cancellation is
            // counted as one rather than as a sale.
            $order->refreshFlashAllowances();

            /*
             * Cherry Points are earned on delivery, not on payment.
             *
             * Inside the transaction, and after the update above, so the
             * points and the status are written together and the fresh read
             * sees the payment_done that cash-on-delivery just set.
             */
            if ($delivered) {
                $this->awardLoyaltyPoints($order->fresh());
            }

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'changed_by' => $changedBy,
                'changed_by_type' => $changedByType,
                'note' => $note
            ]);
        });

        // QA audit EML-02: nothing used to tell the customer.
        $this->notifyStatusChange($order->fresh(), $newStatus, $lang);

        /*
         * A parcel that never left stops being tracked. After everything
         * else and guarded: AfterShip being down must never undo or fail a
         * cancellation that has already been written.
         */
        if ($newStatus === (int)Config::get('constants.orderStatus.CANCELLED')) {
            try {
                ShipmentTracking::onOrderCancelled($order);
            } catch (\Throwable $e) {
                Log::warning('Shipment clean-up after cancellation failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }
    }


    /**
     * Record the courier and tracking number an order went out with, and
     * register them with AfterShip. See ShipmentTracking.
     *
     * Order of events matters: AfterShip is called first and nothing is
     * written unless it accepts, so a refused number leaves the order exactly
     * as it was and the admin sees AfterShip's reason. Then the order moves
     * to On the way (or, already there, a one-off tracking email is raised).
     * The same courier + number again is answered from the saved row: no API
     * call, no email.
     */
    public function saveShipment(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.edit')) {
                return $can;
            }

            $validate = Validation::validationMessage($request, [
                'id' => 'required|integer|exists:orders,id',
                'courier' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
                'tracking_number' => ['required', 'string', 'min:4', 'max:64', 'regex:/^(?=.*[0-9])[A-Za-z0-9]+(?:[ -][A-Za-z0-9]+)*$/'],
                'fields' => ['nullable', 'array'],
                'fields.tracking_account_number' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9]+$/'],
                'fields.tracking_key' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            ], 'form', [
                'courier.regex' => 'Choose a courier from the list.',
                'courier.required' => 'Choose a courier from the list.',
                'tracking_number.regex' => 'Enter the tracking number as printed on the label: letters and digits, at least one digit, single dashes or spaces between groups.',
                'fields.tracking_account_number.regex' => 'The courier account number is letters and digits only.',
                'fields.tracking_key.regex' => 'The tracking key is letters, digits, dots, dashes and underscores.',
            ]);

            if ($validate) {
                return response()->json($validate);
            }

            // Only couriers activated in the AfterShip account can be chosen.
            try {
                $courier = \App\Models\Helper\CourierDirectory::find((string)$request->courier);
            } catch (\App\Services\AfterShipException $e) {
                return response()->json(Validation::fieldError($request->token, 'courier', $e->adminMessage(), $lang));
            }

            if (is_null($courier)) {
                return response()->json(Validation::fieldError($request->token, 'courier',
                    'That courier is not activated in your AfterShip account. Activate it in AfterShip (Settings > Couriers), then refresh the courier list.', $lang));
            }

            $order = Order::find($request->id);

            if (ShipmentTracking::isCollection($order)) {
                return response()->json(Validation::fieldError($request->token, 'tracking_number',
                    'This is a Click & Collect order; there is no parcel to track.', $lang));
            }

            if (!in_array((int)$order->status, ShipmentTracking::shippableStatuses(), true)) {
                return response()->json(Validation::fieldError($request->token, 'tracking_number',
                    'Only a confirmed, picked up or on-the-way order can be given a shipment. Confirm the order first.', $lang));
            }

            // The extra details this courier needs (AfterShip's required_fields).
            $fields = ShipmentTracking::trackingFields($order, $courier, (array)$request->input('fields', []));

            if ($fields['missing']) {
                $field = array_key_first($fields['missing']);
                return response()->json(Validation::fieldError($request->token, 'fields.' . $field,
                    $courier['name'] . ' also needs: ' . implode(', ', $fields['missing']) . '.', $lang));
            }

            // One save per order at a time: a double click must not register twice.
            if (!ShipmentTracking::lockOrder($order->id)) {
                return response()->json(Validation::fieldError($request->token, 'tracking_number',
                    'This shipment is already being saved. Wait a moment, then reload the order.', $lang));
            }

            try {
                try {
                    $saved = ShipmentTracking::save($order, $courier['slug'],
                        (string)$request->tracking_number, optional($this->user)->id, null,
                        $fields['payload'], $courier['name']);

                } catch (\App\Services\AfterShipException $e) {
                    return response()->json(Validation::fieldError($request->token,
                        $e->isInputError() ? 'tracking_number' : 'courier', $e->adminMessage(), $lang));
                }

                /*
                 * Decided by where the order stands now, re-read after the
                 * AfterShip call: a first shipment ships the order; a retry of
                 * a save that stored the row but did not move the order finishes
                 * it; a correction is not announced (the customer's link shows
                 * the new details); an order cancelled meanwhile sends nothing
                 * and its unscanned tracking is removed.
                 */
                $first = !$saved['unchanged'] && !$saved['corrected'];
                $outcome = ShipmentTracking::settle($order->fresh(), $saved['shipment'], $first,
                    optional($this->user)->id, $lang);

                if ($outcome === 'corrected_or_unchanged') {
                    $outcome = $saved['unchanged'] ? 'unchanged' : 'corrected';
                }

                // An adopted tracking may already be out for delivery, at a pickup point or delivered.
                if (!$saved['unchanged'] && $outcome !== 'order_closed') {
                    ShipmentTracking::react($saved['shipment']->fresh());
                }

            } finally {
                ShipmentTracking::unlockOrder($order->id);
            }

            $fresh = $order->fresh();

            if ($outcome === 'order_closed') {
                return response()->json(Validation::fieldError($request->token, 'tracking_number',
                    'The order was closed while the shipment was being saved, so the customer was not told. Reload the order.', $lang));
            }

            return response()->json(new Response($request->token, [
                'shipment' => $saved['shipment']->fresh()->adminView(),
                'status' => (int)$fresh->status,
                'allowed_transitions' => array_values(Order::allowedTransitions()[(int)$fresh->status] ?? []),
                'notified' => $outcome,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Couriers for the shipment form: the ones activated in the AfterShip
     * account (plus AfterShip's test courier outside production). Each says
     * which extra details it needs and which of them the order fills in.
     * ?refresh=1 re-reads the list from AfterShip now.
     */
    public function shipmentCouriers(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.edit')) {
                return $can;
            }

            try {
                $offered = \App\Models\Helper\CourierDirectory::offered((bool)$request->refresh);
            } catch (\App\Services\AfterShipException $e) {
                return response()->json(Validation::error($request->token, $e->adminMessage(), 'form', $lang));
            }

            return response()->json(new Response($request->token, [
                'active' => array_values(array_map(function ($c) {
                    return \App\Models\Helper\CourierDirectory::view($c, true);
                }, $offered)),
                'total' => count($offered),
                'fetched_at' => \App\Models\Helper\CourierDirectory::fetchedAt(),
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Re-read the order's tracking from AfterShip now, for the Refresh
     * button. The webhook and `aftership:sync` do this unasked.
     */
    public function refreshShipment(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.edit')) {
                return $can;
            }

            $shipment = \App\Models\OrderShipment::where('order_id', (int)$id)->first();

            if (is_null($shipment) || !$shipment->aftership_id) {
                return response()->json(Validation::nothingFoundLang($lang, 201));
            }

            try {
                ShipmentTracking::sync($shipment);
            } catch (\App\Services\AfterShipException $e) {
                return response()->json(Validation::error($request->token, $e->adminMessage(), 'form', $lang));
            }

            $order = Order::find((int)$id);

            return response()->json(new Response($request->token, [
                'shipment' => $shipment->fresh()->adminView(),
                'status' => (int)$order->status,
                'allowed_transitions' => array_values(Order::allowedTransitions()[(int)$order->status] ?? []),
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function delete(Request $request, $id)
    {

        try {
            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            /*
             * QA audit ADM-01. This method checked only $this->isVendor — the
             * permission gate every sibling method carries was missing, so any
             * admin role could reach it.
             */
            if ($can = Utils::userCan($this->user, 'order.delete')) {
                return $can;
            }

            $ids = explode(",", $id);

            foreach ($ids as $i) {

                $order = Order::find($i);

                if (is_null($order)) {
                    return response()->json(Validation::nothingFoundLang($lang));
                }

                /*
                 * The order leaves the list; the record stays.
                 *
                 * This used to remove the row and, with it, the line items, the
                 * frozen prices, the voucher and flash-discount rows, the
                 * cancellation and the entire status history. There was no undo
                 * and nothing left afterwards to show the order had existed, so
                 * a mis-click on the wrong row in the list destroyed a financial
                 * record permanently. Every child row is now kept exactly as it
                 * was, and the order is recoverable by clearing deleted_at.
                 *
                 * The stock and the rewards still come back, because a deleted
                 * order is not being fulfilled. The history row written beside
                 * it is what makes that safe to do only once:
                 * restoreStockAndRewards() refuses to run again for an order
                 * that already has a releasing entry, so a delete / restore /
                 * delete cycle cannot credit the same units twice.
                 */
                Log::warning('Order deleted by admin', [
                    'order_id' => $order->id,
                    'order_number' => $order->order,
                    'total_amount' => $order->total_amount,
                    'customer_id' => $order->user_id,
                    'admin_id' => optional($this->user)->id,
                    'admin_email' => optional($this->user)->email,
                ]);

                $adminId = optional($this->user)->id;

                DB::transaction(function () use ($order, $adminId) {

                    $order->restoreStockAndRewards();

                    OrderStatusHistory::create([
                        'order_id' => $order->id,
                        'from_status' => (int)$order->status,
                        'to_status' => (int)Config::get('constants.orderStatus.CANCELLED'),
                        'changed_by' => $adminId,
                        'changed_by_type' => 'admin',
                        'note' => 'Order deleted from the admin order list.',
                    ]);

                    $order->delete();

                    $order->refreshFlashAllowances();
                });
            }


            return response()->json(new Response($request->token, true));


            //return response()->json(Validation::errorTokenLang($request->token, $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function byUser(Request $request)
    {
        try {
            $lang = $request->header('language');
            if ($request->order_id) {
                $query = Order::query();

                if ($lang) {
                    $query = $query->with(['ordered_products' => function ($query) use ($lang) {

                        $query->with(['updated_inventory' => function($query) use ($lang) {
                            $query->with(['inventory_attributes' => function($query) use ($lang) {
                                $query->with(['attribute_value' => function($query) use ($lang) {

                                    $query->leftJoin('attribute_value_langs as avl',
                                        function ($join) use ($lang) {
                                            $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                                            $join->where('avl.lang', $lang);
                                        })
                                        ->select('attribute_values.*',
                                \DB::raw('COALESCE(avl.title, attribute_values.title) as title'));

                                    $query->with(['attribute' => function ($query) use ($lang) {

                                        $query->leftJoin('attribute_langs as al', function ($join) use ($lang) {
                                            $join->on('al.attribute_id', '=', 'attributes.id');
                                            $join->where('al.lang', $lang);
                                        })
                                            ->select('attributes.*', 'al.title');
                                    }]);
                                }]);
                            }]);
                        }]);

                        $query->with(['shipping_place' => function($query){}]);

                        $query->with(['product' => function($query) use ($lang){

                            $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                                $join->on('pl.product_id', '=', 'products.id');
                                $join->where('pl.lang', $lang);
                            })
                                /*
                                 * COALESCE, not a bare pl.title.
                                 *
                                 * The join is a LEFT one, so a product with no
                                 * row for this language selects NULL and
                                 * overrides products.title with nothing - the
                                 * placed-order page listed two products with
                                 * no names at all. Only a translation that
                                 * exists should win over the base title.
                                 */
                                ->select(['products.id',
                                    DB::raw('COALESCE(pl.title, products.title) as title'),
                                    'products.slug', 'products.image', 'products.selling',
                                    'products.offered', 'products.shipping_rule_id', 'products.bundle_deal_id',
                                    DB::raw('COALESCE(pl.unit, products.unit) as unit')]);

                            $query->with(['product_images' => function($query){
                                $query->with(['attributes' => function($query){}]);
                            }]);

                            $query->with(['bundle_deal' => function($query) use ($lang){

                                $query->leftJoin('bundle_deal_langs as bdl', function ($join) use ($lang) {
                                    $join->on('bdl.bundle_deal_id', '=', 'bundle_deals.id');
                                    $join->where('bdl.lang', $lang);
                                })
                                    ->select('bundle_deals.id', 'bundle_deals.buy', 'bundle_deals.free', 'bdl.title');

                            }]);
                        }]);
                    }]);

                    $query = $query->with('address');

                    $query = $query->with('user_info');

                    $query = $query->with('voucher');
                    $query = $query->with('cancellation');


                } else {

                    $query = $query->with('address');

                    $query = $query->with('user_info');

                    $query = $query->with('voucher');
                    $query = $query->with('cancellation');

                    $query = $query->with(['ordered_products' => function ($query){

                        $query->with(['updated_inventory' => function($query){
                            $query->with(['inventory_attributes' => function($query){
                                $query->with(['attribute_value' => function($query){
                                    $query->with(['attribute' => function($query){}]);
                                }]);
                            }]);
                        }]);

                        $query->with(['shipping_place' => function($query){}]);

                        $query->with(['product' => function($query){
                            $query->with(['product_images' => function($query){
                                $query->with(['attributes' => function($query){}]);
                            }]);

                            $query->with(['bundle_deal' => function($query){}]);
                        }]);
                    }]);
                }

                $order = $query->find($request->order_id);

                if (is_null($order)) {
                    return response()->json(Validation::error($request->token,
                        __('lang.no_order', [], $lang)
                    ));
                }



                if ($request->user('user')) {

                    if ((int)$order->user_id !== $request->user('user')->id) {
                        return response()->json(Validation::error($request->token,
                            __('lang.not_order', [], $lang), 'form', null, 403
                        ));
                    }

                } else if ($request->user_token) {

                    if ($order->user_token !== $request->user_token) {
                        return response()->json(Validation::error($request->token,
                            __('lang.not_order', [], $lang), 'form', null, 403
                        ));
                    }

                } else {

                    return response()->json(Validation::errorLang($lang));
                }

                $order['user'] = $order->user_info;
                unset($order->user_info);
                $order['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($order->created_at, $request->time_zone));

                /*
                 * The one summary this order has, built from what the order
                 * recorded and nothing else.
                 *
                 * This block used to spell the whole thing out inline, beside a
                 * second copy from Utils::calcPrice() four lines above it --
                 * and the two disagreed about what "subtotal" meant. calcPrice
                 * called the goods at the charged price the subtotal; this
                 * block called the goods at the list price the subtotal and
                 * printed the difference as a separate discount. The order
                 * detail page reads the first and the order confirmation page
                 * reads the second, so the same order showed a customer two
                 * different Subtotals depending on which screen they opened.
                 *
                 * decorate() also copies each line's stored figures onto the
                 * ordered_products being serialised, so the confirmation screen
                 * prints the per-item price, discount and tax rather than
                 * working any of them out from the live catalogue.
                 */
                $summary = OrderSummary::decorate($order);

                $order['calculations'] = [
                    'subtotal' => number_format($summary['subtotal'], 2, '.', ''),
                    'discount' => number_format($summary['discount'], 2, '.', ''),
                    'bundle_offer' => number_format($summary['bundle_offer'], 2, '.', ''),
                    'flash_discount' => number_format($summary['flash_discount'], 2, '.', ''),
                    // As above: stated, not merely subtracted from the total.
                    'bundle_discount' => number_format($summary['bundle_discount'], 2, '.', ''),
                    'shipping' => number_format($summary['shipping'], 2, '.', ''),
                    'tax' => number_format($summary['tax'], 2, '.', ''),
                    'voucher' => number_format($summary['voucher'], 2, '.', ''),
                    'loyalty_discount' => number_format($summary['loyalty_discount'], 2, '.', ''),
                    'loyalty_label' => $summary['loyalty_label'],
                    /*
                     * The same order as a VAT document states it: the goods net of
                     * VAT, the tax on them, and the two together. Split by
                     * OrderSummary from what the order recorded - never from the
                     * product tax rules as they stand today.
                     */
                    'ex_vat' => number_format($summary['ex_vat'], 2, '.', ''),
                    'vat' => number_format($summary['vat'], 2, '.', ''),
                    'inc_vat' => number_format($summary['inc_vat'], 2, '.', ''),
                    /*
                     * The same goods stated gross, for a summary that leads
                     * with the inc-VAT figure:
                     *   subtotal_inc_vat - discount_inc_vat = inc_vat
                     */
                    'subtotal_inc_vat' => number_format($summary['subtotal_inc_vat'], 2, '.', ''),
                    'discount_inc_vat' => number_format($summary['discount_inc_vat'], 2, '.', ''),
                    // The same goods stated net: subtotal_ex_vat - discount_ex_vat = ex_vat
                    'subtotal_ex_vat' => number_format($summary['subtotal_ex_vat'], 2, '.', ''),
                    'discount_ex_vat' => number_format($summary['discount_ex_vat'], 2, '.', ''),
                    'order_wide_discount' => number_format($summary['order_wide_discount'], 2, '.', ''),
                    // One row per rate actually charged; an invoice has to state them.
                    'vat_breakdown' => $summary['vat_breakdown'],
                    'points_discount' => number_format($summary['points_discount'], 2, '.', ''),
                    'total' => number_format($summary['total'], 2, '.', ''),
                    // split payment: wallet part, and what the order's method took
                    'wallet_paid' => number_format($summary['wallet_paid'], 2, '.', ''),
                    'amount_due' => number_format($summary['amount_due'], 2, '.', ''),
                ];

                // The legacy key, kept for anything still reading it; same
                // numbers, older names.
                $order['calculated'] = Utils::calcPrice($order);

                /*
                 * When an unpaid bank transfer is cancelled, as an ISO instant.
                 * The bank page counted down from `created`, which is a
                 * display string in the viewer's zone and not parseable.
                 */
                $order['transfer_deadline'] = null;

                if ((int)$order->order_method === (int)Config::get('constants.paymentMethod.BANK')
                    && (int)$order->payment_done !== 1) {
                    $order['transfer_deadline'] = optional(
                        CancelUnpaidBankTransfers::deadlineFor($order->getRawOriginal('created_at'))
                    )->toIso8601String();
                }

                /*
                 * The parcel, in full: ownership was checked above (account or
                 * guest token), so this is the same view the signed email link
                 * gets. Not keyed `shipment`, the relation's name.
                 */
                $shipmentRow = \App\Models\OrderShipment::where('order_id', $order->id)->first();
                $order['parcel'] = $shipmentRow ? $shipmentRow->publicView(true) : null;
                $order['parcel_track_url'] = $shipmentRow ? ShipmentTracking::trackUrl($order) : null;

                // Return cases, as the customer may see them (ownership checked above).
                $order['returns'] = \App\Models\Helper\OrderReturns::forCustomer($order, $lang);

                return response()->json(new Response($request->token, $order));

            } else {


                $query = Order::query();
                if ($lang) {
                    $query = $query->with(['ordered_products.product' => function ($query) use ($lang) {

                        $query->leftJoin('product_langs as pl', function ($join) use ($lang) {
                            $join->on('pl.product_id', '=', 'products.id');
                            $join->where('pl.lang', $lang);
                        })
                            ->select('products.id', 'pl.title', 'products.slug', 'products.image', 'products.selling',
                                'products.offered', 'products.shipping_rule_id', 'products.bundle_deal_id', 'pl.unit');


                    }]);
                } else {
                    $query = $query->with('ordered_products.product');
                }

                // Each line's delivery time, so My Orders can show a real estimate
                // instead of "Soon" (the detail branch already loads it).
                $query = $query->with('ordered_products.shipping_place');

                $query = $query->orderBy('created_at', 'DESC');

                if ($request->cancelled) {
                    $query = $query->where('cancelled', $request->cancelled);
                }

                if ($request->paid) {
                    $query = $query->where('payment_done', 1);

                    if ($request->unpaid) {
                        $query = $query->orWhere('payment_done', 0);
                    }
                } else if ($request->unpaid) {
                    $query = $query->where('payment_done', 0);
                }

                if ($request->card_payment) {
                    $query = $query
                        ->where('order_method', Config::get('constants.paymentMethod.RAZORPAY'))
                        ->orWhere('order_method', Config::get('constants.paymentMethod.STRIPE'));

                    if ($request->cash_on_delivery) {
                        $query = $query->orWhere('order_method', Config::get('constants.paymentMethod.CASH_ON_DELIVERY'));
                    }

                } else if ($request->cash_on_delivery) {
                    $query = $query->where('order_method', Config::get('constants.paymentMethod.CASH_ON_DELIVERY'));
                }


                if ($request->user('user')) {

                    $query = $query->where('user_id', $request->user('user')->id);

                } else if ($request->user_token) {

                    $query = $query->where('user_token', $request->user_token);

                } else {

                    return response()->json(Validation::errorLang($lang));
                }


                $data = $query->paginate(Config::get('constants.frontend.PAGINATION'));

                if ($request->time_zone) {
                    foreach ($data as $item) {

                        $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                    }
                } else {
                    foreach ($data as $item) {

                        $item['created'] = Utils::formatDate($item->created_at);
                    }
                }
                return response()->json(new Response($request->token, $data));
            }

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function transaction(Request $request)
    {
        try {

            $lang = $request->header('language');

            $params = json_decode(Utils::jsDecryption($request->data));

            // A malformed payload must not dereference null (QA audit SEC-05).
            if (!is_object($params)) {
                return response()->json(Validation::error($request->token,
                    __('lang.went_wrong', [], $lang)
                ));
            }

            $request->request->add(['user_token' => $params->user_token ?? null]);
            $request->request->add(['id' => $params->id ?? null]);
            $request->request->add(['trans_id' => $params->trans_id ?? null]);


            $validate = Validation::transId($request);
            if ($validate) {
                return response()->json($validate);
            }


            $order = Order::with('voucher')
                ->with('address')
                ->where('id', $request->id)
                ->first();

            if (is_null($order)) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_order', [], $lang)
                ));
            }


            if ($request->user('user')) {
                if ($order->user_id != $request->user('user')->id) {
                    return response()->json(Validation::error($request->token,
                        __('lang.invalid_user', [], $lang)
                    ));
                }

            } else if ($request->user_token) {

                if ($order->user_token != $request->user_token) {
                    return response()->json(Validation::error($request->token,
                        __('lang.invalid_user', [], $lang)
                    ));
                }
            } else {
                return response()->json(Validation::errorLang($lang));
            }

            /*
             * QA audit PAY-05. This wrote whatever trans_id the client sent,
             * over the top of a verified gateway reference. Never once the
             * order is paid — paymentDone() writes the real one after
             * verification.
             */
            if ((int)$order->payment_done === 1) {
                return response()->json(new Response($request->token, true));
            }

            /*
             * A reference the customer types in is a bank transfer's, and only
             * a bank transfer's. It used to be accepted on any unpaid order,
             * card ones included, where it sat in the column a verified
             * gateway reference belongs in.
             */
            if ((int)$order->order_method !== (int)Config::get('constants.paymentMethod.BANK')) {
                return response()->json(Validation::error($request->token,
                    'A transfer reference can only be added to a bank transfer order.'
                ));
            }

            if ($order->isCancelled()) {
                return response()->json(Validation::error($request->token,
                    'This order has been cancelled. Please contact us about your transfer.'
                ));
            }

            /*
             * Correctable until the money is confirmed. The first reference
             * used to be final and every later one was dropped while the
             * customer was told it had saved, so a typo could never be fixed.
             * Safe now that the column can only hold the customer's own
             * reference on an unpaid bank order.
             */
            $result = Order::where('id', $request->id)
                ->where('payment_done', 0)
                ->update(['trans_id' => trim((string)$request->trans_id)]);

            $reference = trim((string)$request->trans_id);
            $previous = $order->trans_id ?: null;

            if ($previous && $previous !== $reference) {
                Log::info('Bank transfer reference corrected by customer', [
                    'order_id' => $order->id,
                    'from' => $previous,
                    'to' => $reference,
                ]);
            }

            // Staff hear about a new or changed reference; a resubmission of
            // the same one is not news.
            if ($previous !== $reference) {
                try {
                    \App\Models\Helper\OrderMailer::staffTransferReference($order->fresh(), $reference, $previous);
                } catch (\Throwable $e) {
                    Log::error('Staff bank transfer notice failed', [
                        'order_id' => $order->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            return response()->json(Validation::error($request->token, $e->getMessage()));
        }
        return response()->json(new Response($request->token, $result));
    }


    /**
     * Confirm a payment, and tell the customer when it did not go through.
     *
     * A wrapper rather than a line added to each of the nine gateway branches.
     * Every branch already reports its refusal the same way -- an error
     * response -- and every branch is somewhere a tenth gateway will one day be
     * added; reading the outcome once, here, is the version a new gateway
     * cannot forget to wire up.
     *
     * The email is claimed once per order, so a customer working through three
     * cards is told once that their payment is not going through, not three
     * times. If a later attempt succeeds they get the receipt as normal -- the
     * earlier message was true when it was sent.
     */
    public function paymentDone(Request $request)
    {
        $response = $this->attemptPayment($request);

        try {
            $body = json_decode($response->getContent(), true);

            if ((int)($body['status'] ?? 0) === 200) {
                return $response;
            }

            $order = Order::find($request->id);

            /*
             * Only for an order that is still unpaid. A refusal on an order
             * that already went through is a duplicate submission or a stale
             * browser tab, and telling that customer their payment failed
             * would be false.
             */
            // Nor on a cancelled one: that refusal is ours, not the card's.
            // Nor for cash or a bank transfer, where no charge was attempted:
            // a refusal there is a rule (collection only, say), not a payment.
            $noCharge = in_array((int)$request->order_method, [
                (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY'),
                (int)Config::get('constants.paymentMethod.BANK'),
            ], true);

            if ($order && !$order->payment_done && !$order->isCancelled() && !$noCharge) {

                OrderEmailDispatcher::dispatch(
                    $order,
                    OrderEmailEvent::PAYMENT_FAILED,
                    $request->header('language')
                );
            }

        } catch (\Throwable $e) {

            // The customer already has their answer; this is only the notice.
            Log::error('Payment failure notification could not be raised', [
                'order_id' => $request->id,
                'message' => $e->getMessage()
            ]);
        }

        return $response;
    }

    /**
     * The gateway verification itself. Was paymentDone(); the name changed so
     * the notification above can wrap it without every caller moving.
     */
    protected function attemptPayment(Request $request)
    {
        try {

            $lang = $request->header('language');

            $params = json_decode(Utils::jsDecryption($request->data));

            // A malformed payload must not dereference null (QA audit SEC-05).
            if (!is_object($params)) {
                return response()->json(Validation::error($request->token,
                    __('lang.went_wrong', [], $lang)
                ));
            }

            $request->request->add(['user_token' => $params->user_token ?? null]);
            $request->request->add(['id' => $params->id ?? null]);
            $request->request->add(['payment_token' => $params->payment_token ?? null]);
            $request->request->add(['order_method' => $params->order_method ?? null]);

            // Razorpay's signature travels alongside the wrapper (QA audit PAY-02).
            $request->request->add([
                'razorpay_payment_id' => $params->razorpay_payment_id ?? $request->razorpay_payment_id,
                'razorpay_signature' => $params->razorpay_signature ?? $request->razorpay_signature,
            ]);

            $validate = Validation::orderStatus($request);
            if ($validate) {
                return response()->json($validate);
            }


            $order = Order::with('voucher')
                ->with('address')
                ->where('id', $request->id)
                ->first();

            if (is_null($order)) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_order', [], $lang)
                ));
            }


            if ($request->user('user')) {

                if ($order->user_id != $request->user('user')->id) {
                    return response()->json(Validation::error($request->token,
                        __('lang.invalid_user', [], $lang)
                    ));
                }

            } else if ($request->user_token) {

                if ($order->user_token != $request->user_token) {
                    return response()->json(Validation::error($request->token,
                        __('lang.invalid_user', [], $lang)
                    ));
                }

            } else {

                return response()->json(Validation::errorLang($lang));
            }


            /*
             * A suspended account may still sign in and read its history, but
             * it may not buy.
             *
             * First, ahead of the gateway and basket checks: those fail for
             * their own reasons, and a suspended customer who is told
             * "something went wrong" will keep trying and then call. Enforced
             * here rather than by hiding the button — the order endpoint is
             * reachable with nothing but a bearer token, so a check in the
             * browser stops nobody.
             */
            $checkingOut = $request->user('user');

            if ($checkingOut && !$checkingOut->canCheckout()) {
                return response()->json(Validation::error($request->token,
                    'This account cannot place orders at the moment. Please contact us.'
                ));
            }

            $payment = Payment::first();

            if ((int)$payment->cash_on_delivery != 1 &&
                ((int)$request->order_method == Config::get('constants.paymentMethod.CASH_ON_DELIVERY'))) {

                return response()->json(Validation::error($request->token,
                    __('lang.accepted_cod', [], $lang)
                ));

            } else if ((int)$payment->paypal != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.PAYPAL')) {

                return response()->json(Validation::error($request->token,
                    __('lang.accepted_paypal', [], $lang)
                ));

            } else if (!$payment->acceptsCardMethod(Config::get('constants.paymentMethod.STRIPE')) &&
                (int)$request->order_method == Config::get('constants.paymentMethod.STRIPE')) {

                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));

            } else if ((int)$payment->razorpay != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.RAZORPAY')) {

                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));

            } else if ((int)$payment->flutterwave != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.FLUTTERWAVE')) {

                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));
            } else if ((int)$payment->bank != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.BANK')
            ) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));
            }

            /*
             * QA audit PAY-05. Without this an order could be "paid" repeatedly:
             * every call re-ran the branch below, and on Stripe that meant a
             * second charge against the same customer.
             */
            if ((int)$order->payment_done === 1) {
                return response()->json(new Response($request->token, true));
            }

            /*
             * No new money for a cancelled order.
             *
             * Refused only where the money has not moved yet: a bank transfer
             * is still to be sent, and the wallet and Stripe branches below
             * take the money themselves. The other gateways arrive here after
             * the card was already charged, and refusing those would leave a
             * customer charged with nothing recorded; they go through and are
             * logged, so the order shows paid-and-cancelled for a refund.
             */
            if ($order->isCancelled()) {

                $movesMoneyHere = in_array((int)$request->order_method, [
                    (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY'),
                    (int)Config::get('constants.paymentMethod.BANK'),
                    (int)Config::get('constants.paymentMethod.WALLET'),
                    (int)Config::get('constants.paymentMethod.STRIPE'),
                ], true);

                if ($movesMoneyHere) {
                    return response()->json(Validation::error($request->token,
                        'This order has been cancelled and can no longer be paid for.'
                    ));
                }

                // Not verified yet; the alert to staff goes out below, once
                // the gateway has confirmed the money actually moved.
                Log::warning('Gateway payment arriving for a cancelled order', [
                    'order_id' => $order->id,
                    'order_method' => (int)$request->order_method,
                ]);
            }

            /*
             * Cash, chosen for an order that already exists. Like a bank
             * transfer there is nothing to confirm here -- the cash changes
             * hands at collection and the move to DELIVERED stamps it paid --
             * and like a bank transfer it used to fall through to the "no
             * gateway branch" refusal, which emailed the customer that their
             * payment had failed while the page said the order was placed.
             *
             * Cash is for Collection Store orders only. The order's own lines
             * record how each is fulfilled, so that is what is checked, not
             * the basket, which has long since been emptied.
             */
            if ((int)$request->order_method === (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY')) {

                $lines = OrderedProduct::where('order_id', $order->id)->pluck('shipping_type');

                $collection = $lines->isNotEmpty() && $lines->every(function ($type) {
                    return (int)$type === (int)Config::get('constants.shippingTypeIn.PICKUP');
                });

                if (!$collection) {
                    return response()->json(Validation::error($request->token,
                        'Cash is only available on Collection Store orders. Please choose another way to pay.'
                    ));
                }

                Order::where('id', $order->id)
                    ->where('payment_done', 0)
                    ->update(['order_method' => $request->order_method]);

                return response()->json(new Response($request->token, [
                    'id' => $order->id,
                    'pay_on_collection' => true,
                ]));
            }

            /*
             * A bank transfer is not a payment that can be confirmed here: the
             * money arrives days later and an admin marks it off, which is
             * what sends the "payment received" email.
             *
             * It still needs its own answer. Without one it fell through to
             * the "no gateway branch" refusal below, and paymentDone() turned
             * that refusal into a "your payment failed" email -- sent to every
             * bank transfer customer seconds after their order confirmation.
             * All this call does is record the choice, for an unpaid order
             * that switches to bank transfer from its order page.
             */
            if ((int)$request->order_method === (int)Config::get('constants.paymentMethod.BANK')) {

                Order::where('id', $order->id)
                    ->where('payment_done', 0)
                    ->update(['order_method' => $request->order_method]);

                return response()->json(new Response($request->token, [
                    'id' => $order->id,
                    'awaiting_transfer' => true,
                ]));
            }

            $paymentDone = false;

            /*
             * The amount every gateway must agree with. Nothing here recomputes
             * a total of its own — the Stripe branch used to, and its formula
             * left out the flash discount and the loyalty points, so the card
             * was charged more than the order recorded (QA audit PAY-04).
             */
            $expectedAmount = WalletSplit::amountDue($order);

            if ($expectedAmount <= 0) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_order', [], $lang)
                ));
            }

            $setting = Setting::select('currency')->first();

            if ((int)$request->order_method == Config::get('constants.paymentMethod.FLUTTERWAVE')) {

                try {

                    $con = \Flutterwave\Helper\Config::setUp(
                        $payment->fw_secret_key,
                        $payment->fw_public_key,
                        $payment->fw_encryption_key,
                        $payment->fw_environment
                    );
                    $transactions = new \Flutterwave\Service\Transactions($con);
                    $response = $transactions->verify($request->payment_token);

                    /*
                     * QA audit PAY-03. The old catch treated any exception whose
                     * message contained "The stream or file" — an unwritable SDK
                     * log — as a successful payment. A verification that cannot
                     * run has not succeeded.
                     */
                    if ($response->status !== "success") {
                        return response()->json(Validation::error($request->token,
                            __('lang.flutterwave_error', [], $lang)
                        ));
                    }

                    $charged = $response->data->amount ?? null;
                    $chargedCurrency = $response->data->currency ?? null;

                    if (!$this->amountMatches($charged, $expectedAmount, $chargedCurrency, $setting->currency)) {
                        Log::warning('Flutterwave amount mismatch', [
                            'order_id' => $order->id,
                            'expected' => $expectedAmount,
                            'charged' => $charged
                        ]);

                        return response()->json(Validation::error($request->token,
                            __('lang.invalid_token', [], $lang)
                        ));
                    }

                    $order->trans_id = $request->payment_token;
                    $paymentDone = true;

                } catch (\Throwable $e) {

                    Log::error('Flutterwave verification failed', [
                        'order_id' => $order->id,
                        'message' => $e->getMessage()
                    ]);

                    return response()->json(Validation::error($request->token,
                        __('lang.flutterwave_error', [], $lang)
                    ));
                }

            } else if ((int)$request->order_method == Config::get('constants.paymentMethod.PAYPAL')) {

                /*
                 * QA audit PAY-01. This branch was a bare "$paymentDone = true"
                 * — no capture, no amount check — so any customer could mark
                 * their own order paid and receive the goods for free.
                 */
                $verified = \App\Models\Helper\GatewayVerifier::verifyPaypal(
                    $payment, $request->payment_token, $expectedAmount, $setting->currency
                );

                if (!$verified['ok']) {
                    Log::warning('PayPal verification failed', [
                        'order_id' => $order->id,
                        'reason' => $verified['reason']
                    ]);

                    return response()->json(Validation::error($request->token,
                        __('lang.accepted_paypal', [], $lang)
                    ));
                }

                $order->trans_id = $verified['reference'];
                $paymentDone = true;

            } else if ((int)$request->order_method == Config::get('constants.paymentMethod.RAZORPAY')) {

                /*
                 * QA audit PAY-02. The only check used to be that the client
                 * echoed back $order->payment_token — the Razorpay *order* id,
                 * which the browser already held before paying. The signature
                 * is what actually proves payment.
                 */
                if ($order->payment_token != $request->payment_token) {

                    return response()->json(Validation::error($request->token,
                        __('lang.invalid_token', [], $lang)
                    ));
                }

                $verified = \App\Models\Helper\GatewayVerifier::verifyRazorpay(
                    $payment,
                    $order->payment_token,
                    $request->razorpay_payment_id,
                    $request->razorpay_signature,
                    $expectedAmount,
                    $setting->currency
                );

                if (!$verified['ok']) {
                    Log::warning('Razorpay verification failed', [
                        'order_id' => $order->id,
                        'reason' => $verified['reason']
                    ]);

                    return response()->json(Validation::error($request->token,
                        __('lang.invalid_token', [], $lang)
                    ));
                }

                $order->trans_id = $verified['reference'];
                $paymentDone = true;

            } else if ((int)$request->order_method == Config::get('constants.paymentMethod.SUMUP')){

                // QA audit PAY-01: was also a bare "$paymentDone = true".
                $verified = \App\Models\Helper\GatewayVerifier::verifySumup(
                    $payment, $request->payment_token, $expectedAmount, $setting->currency
                );

                if (!$verified['ok']) {
                    Log::warning('SumUp verification failed', [
                        'order_id' => $order->id,
                        'checkout_id' => $request->payment_token,
                        'expected_amount' => $expectedAmount,
                        'reason' => $verified['reason']
                    ]);

                    /*
                     * Not 'accepted_gateway'. SumUp *is* an accepted gateway
                     * here - what failed is the verification of this one
                     * payment, and telling the shopper the gateway is refused
                     * sends them (and support) after the wrong fault.
                     */
                    return response()->json(Validation::error($request->token,
                        __('lang.payment_unconfirmed', [], $lang)
                    ));
                }

                $order->trans_id = $verified['reference'];
                $paymentDone = true;

            } else if ((int)$request->order_method == Config::get('constants.paymentMethod.WALLET')) {

                /*
                 * Pay from the Savers Club wallet (top-ups and redeemed gift
                 * cards). The checkout has offered this for a while, but no
                 * branch here handled it, so every wallet payment was refused
                 * as "Gateway is not accepted" and the order stayed unpaid.
                 *
                 * The order and the wallet are locked together and the order is
                 * stamped paid in the same transaction as the debit: a retry or a
                 * double tap cannot charge twice, and a failure cannot take the
                 * money without paying the order.
                 */
                $walletUser = $request->user('user');

                if (!$walletUser) {
                    return response()->json(Validation::error($request->token,
                        'Sign in to pay from your wallet.'
                    ));
                }

                $walletPaid = \Illuminate\Support\Facades\DB::transaction(function () use ($walletUser, $order, $expectedAmount, $request) {

                    $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

                    if ((int)$lockedOrder->payment_done === 1) {
                        return 'already';
                    }

                    $wallet = \App\Models\Wallet::where('user_id', $walletUser->id)->lockForUpdate()->first();

                    if (!$wallet || round((float)$wallet->balance, 2) < $expectedAmount) {
                        return 'short';
                    }

                    $newBalance = round((float)$wallet->balance - $expectedAmount, 2);
                    $wallet->update(['balance' => $newBalance]);

                    $txn = \App\Models\WalletTransaction::create([
                        'user_id'       => $walletUser->id,
                        'type'          => 'debit',
                        'amount'        => $expectedAmount,
                        'balance_after' => $newBalance,
                        'source'        => 'order',
                        'reference_id'  => $order->id,
                        'status'        => 'success',
                    ]);

                    $reference = 'WALLET-' . $txn->id;

                    Order::where('id', $order->id)->update(array_merge(
                        Order::paymentStamp(true),
                        [
                            'order_method' => $request->order_method,
                            'trans_id'     => $reference,
                        ]
                    ));

                    return $reference;
                });

                if ($walletPaid === 'already') {
                    return response()->json(new Response($request->token, true));
                }

                if ($walletPaid === 'short') {
                    return response()->json(Validation::error($request->token,
                        'There is not enough in your wallet for this order. Choose another way to pay.'
                    ));
                }

                $order->trans_id = $walletPaid;
                $paymentDone = true;

            } else if ((int)$request->order_method == Config::get('constants.paymentMethod.STRIPE')) {

                try {
                    \Stripe\Stripe::setApiKey($payment->stripe_secret);

                    $token = (string)$request->payment_token;
                    $amountMinor = Pricing::toMinorUnits($expectedAmount);
                    $currency = strtolower($setting->currency);

                    /*
                     * Server-side confirmation, so SCA / 3-D Secure works (QA
                     * audit PAY-04). Two calls reach here:
                     *
                     *  pm_...  the card, from stripe.createPaymentMethod(). A
                     *          PaymentIntent is created and confirmed. If the
                     *          bank wants a challenge the intent comes back
                     *          requires_action and its client_secret goes to the
                     *          browser, which runs stripe.handleCardAction().
                     *  pi_...  that intent, once the challenge is done. It is
                     *          confirmed again here -- never trusted from the
                     *          browser -- after checking it is this order's and
                     *          for this order's amount.
                     *
                     * The browser used to send a legacy tok_... from
                     * createToken(), which a PaymentIntent does not take as a
                     * payment_method, and no call handled requires_action, so a
                     * card that needs 3-D Secure could never pay.
                     */
                    if (strpos($token, 'pi_') === 0) {

                        $intent = \Stripe\PaymentIntent::retrieve($token);

                        $ownOrder = (string)($intent->metadata['order_id'] ?? '') === (string)$order->id;
                        if (!$ownOrder || !$this->amountMatches(
                                $intent->amount / 100, $amountMinor / 100, $intent->currency, $currency)) {

                            Log::warning('Stripe intent does not match the order', [
                                'order_id' => $order->id,
                                'intent' => $intent->id,
                            ]);

                            return response()->json(Validation::error($request->token,
                                __('lang.accepted_gateway', [], $lang)
                            ));
                        }

                        if ($intent->status === 'requires_confirmation') {
                            $intent = $intent->confirm();
                        }

                    } else if (strpos($token, 'pm_') === 0) {

                        $intent = \Stripe\PaymentIntent::create([
                            'amount' => $amountMinor,
                            'currency' => $currency,
                            'payment_method' => $token,
                            'payment_method_types' => ['card'],
                            'confirmation_method' => 'manual',
                            'confirm' => true,
                            'description' => 'order_id_' . $order->id,
                            'receipt_email' => $order->contactDetails()['email'] ?: null,
                            'metadata' => ['order_id' => $order->id],
                        ], [
                            /*
                             * Replaying the same card on the same order cannot
                             * double-charge. The card is part of the key: with
                             * the order alone, a customer whose first card was
                             * declined could never try a second one, as Stripe
                             * refuses a reused key with different parameters.
                             */
                            'idempotency_key' => 'order-' . $order->id . '-' . $order->order . '-' . $token
                        ]);

                    } else {

                        return response()->json(Validation::error($request->token,
                            __('lang.accepted_gateway', [], $lang)
                        ));
                    }

                    if ($intent->status === 'requires_action') {
                        /*
                         * Not a refusal: a 200, so paymentDone() does not send
                         * the customer a "payment failed" email while they are
                         * still looking at their bank's challenge.
                         */
                        return response()->json(new Response($request->token, [
                            'requires_action' => true,
                            'client_secret' => $intent->client_secret,
                        ]));
                    }

                    if ($intent->status !== 'succeeded') {
                        return response()->json(Validation::error($request->token,
                            __('lang.accepted_gateway', [], $lang)
                        ));
                    }

                    $order->trans_id = $intent->id;
                    $paymentDone = true;

                } catch (\Stripe\Exception\CardException $e) {

                    // A decline: the card's own reason is the useful answer.
                    Log::info('Stripe card declined', [
                        'order_id' => $order->id,
                        'code' => $e->getStripeCode()
                    ]);

                    return response()->json(Validation::error($request->token,
                        $e->getMessage()
                    ));

                } catch (\Throwable $e) {

                    Log::error('Stripe charge failed', [
                        'order_id' => $order->id,
                        'message' => $e->getMessage()
                    ]);

                    return response()->json(Validation::error($request->token,
                        __('lang.accepted_gateway', [], $lang)
                    ));
                }

            } else if ((int)$request->order_method == Config::get('constants.paymentMethod.IYZICO_PAYMENT')) {

                $result["iyzico_payment"] = IyzicoPayment::initIyzico($request, $order->id);

                return response()->json(new Response($request->token, $result));

            }else if ((int)$request->order_method == Config::get('constants.paymentMethod.PAYFAST')) {

                $payFastContact = $order->contactDetails();

                $result["payfast"] = PayFast::getPayFastForm($payment, $order, [
                    'name' => $payFastContact['name'],
                    'email' => $payFastContact['email']
                ], WalletSplit::amountDue($order));

                return response()->json(new Response($request->token, $result));

            }

            if (!$paymentDone) {
                /*
                 * Every branch above logs its own refusal; this one used to
                 * return the same "Gateway is not accepted." with no trace at
                 * all, which is what an unrecognised order_method lands on -
                 * the hardest failure of the lot to diagnose from the outside.
                 */
                Log::warning('Payment confirmation reached no gateway branch', [
                    'order_id' => $order->id,
                    'order_method' => $request->order_method,
                    'order_method_int' => (int)$request->order_method,
                    'has_payment_token' => !empty($request->payment_token)
                ]);

                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));
            }

            $result = Order::where('id', $request->id)->update(array_merge(
                Order::paymentStamp(true),
                [
                    'order_method' => $request->order_method,
                    'trans_id' => $order->trans_id,
                ]
            ));

            $paidOrder = $order->fresh();

            /*
             * The card was charged for an order that is already cancelled --
             * the customer was on the card screen when the unpaid-transfer
             * sweep or an admin cancelled it. The payment is recorded (the
             * money did move) but the customer is not sent "payment
             * received" for an order that is not going anywhere; the shop is
             * told instead, to refund or reinstate and to contact them. It
             * used to be a log line nobody reads.
             */
            if ($paidOrder->isCancelled()) {
                \App\Models\Helper\OrderMailer::alertPaidCancelledOrder($paidOrder);
            } else {
                // Loyalty points and the confirmation email both belong here,
                // once the money is real (QA audit ACC-02, EML-01).
                $this->markOrderPaid($paidOrder, $lang);
            }

        } catch (\Throwable $e) {

            Log::error('paymentDone failed', [
                'order_id' => $request->id,
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return response()->json(Validation::error($request->token,
                __('lang.went_wrong', [], $lang)
            ));
        }
        return response()->json(new Response($request->token, $result));
    }


    /**
     * Put back an order the unpaid-transfer sweep cancelled, now that the
     * transfer has turned up.
     *
     * Only for that case, and only when it is clean:
     *   - bank transfer, unpaid, CANCELLED by the sweep (a 'system' history
     *     row), not by staff and not at the customer's request;
     *   - no Cherry Points and no one-time loyalty discount were spent on it.
     *     The cancellation gave those back and they may have been used again
     *     since; taking them a second time is not something to do silently;
     *   - every line is still in stock.
     *
     * The stock is taken again, the order returns to PENDING stamped paid, and
     * the history records the move and who made it.
     *
     * @return null|false|string null when reinstated; false when this is not
     *                           a sweep-cancelled bank order at all; otherwise
     *                           why it cannot be, for the admin
     */
    protected function reinstateLateBankTransfer(Order $order)
    {
        $cancelled = (int)Config::get('constants.orderStatus.CANCELLED');

        if ((int)$order->order_method !== (int)Config::get('constants.paymentMethod.BANK')
            || (int)$order->payment_done === 1
            || (int)$order->status !== $cancelled
            || (int)$order->cancelled === (int)Config::get('constants.status.PUBLIC')) {
            return false;
        }

        $lastCancel = OrderStatusHistory::where('order_id', $order->id)
            ->where('to_status', $cancelled)
            ->orderByDesc('id')
            ->first();

        if (!$lastCancel || $lastCancel->changed_by_type !== 'system') {
            return false;
        }

        $refundInstead = ' Please refund the transfer and ask the customer to order again.';

        $usedPoints = PointTransaction::where('reference_id', $order->id)
            ->where('source', 'order')
            ->where('type', 'debit')
            ->exists();

        $usedLoyalty = \App\Models\OrderedLoyaltyDiscount::where('order_id', $order->id)
            ->whereNotNull('loyalty_group_user_id')
            ->exists();

        if ($usedPoints || $usedLoyalty) {
            return 'This order was cancelled for non-payment and used a points or loyalty discount, '
                . 'which has since been given back, so it cannot be put back automatically.' . $refundInstead;
        }

        if ((float)$order->wallet_amount > 0) {
            return 'This order was cancelled for non-payment and was part-paid from the wallet, '
                . 'which has since been given back, so it cannot be put back automatically.' . $refundInstead;
        }

        try {
            return DB::transaction(function () use ($order, $cancelled, $refundInstead) {

                $lines = OrderedProduct::where('order_id', $order->id)->get();

                $stock = UpdatedInventory::whereIn('id', $lines->pluck('inventory_id')->filter()->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $wanted = [];
                foreach ($lines as $line) {
                    $wanted[$line->inventory_id] = ($wanted[$line->inventory_id] ?? 0) + (int)$line->quantity;
                }

                foreach ($wanted as $inventoryId => $quantity) {
                    $row = $stock->get($inventoryId);

                    if (!$row || (int)$row->quantity < $quantity) {
                        return 'This order was cancelled for non-payment and an item on it is no longer '
                            . 'in stock, so it cannot be put back.' . $refundInstead;
                    }
                }

                foreach ($wanted as $inventoryId => $quantity) {
                    UpdatedInventory::where('id', $inventoryId)->decrement('quantity', $quantity);
                }

                $pending = (int)Config::get('constants.orderStatus.PENDING');

                Order::where('id', $order->id)->update(array_merge(
                    ['status' => $pending],
                    Order::paymentStamp(true)
                ));

                $order->refreshFlashAllowances();

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'from_status' => $cancelled,
                    'to_status' => $pending,
                    'changed_by' => optional($this->user)->id,
                    'changed_by_type' => 'admin',
                    'note' => 'Reinstated: bank transfer arrived after automatic cancellation',
                ]);

                Log::info('Order reinstated after a late bank transfer', [
                    'order_id' => $order->id,
                    'admin_id' => optional($this->user)->id,
                ]);

                return null;
            });

        } catch (\Throwable $e) {
            Log::error('Late bank transfer reinstatement failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return 'The order could not be put back: ' . $e->getMessage();
        }
    }

    /**
     * Everything that should happen exactly once, when an order is genuinely
     * paid for.
     *
     * QA audit EML-01: the confirmation email used to depend on the browser
     * calling send-order-email afterwards.
     *
     * Cherry Points are deliberately NOT credited here. Paying is not the same
     * as receiving: an order can be paid and then cancelled, returned or never
     * delivered at all, and points handed out at payment have to be clawed back
     * every time that happens. They are credited once, on delivery, by
     * awardLoyaltyPoints().
     */
    public function markOrderPaid($order, $lang = null)
    {
        if (is_null($order)) {
            return;
        }

        /*
         * The receipt first. On the common path -- cash on delivery, bank
         * transfer, a card cleared during checkout -- this is the message the
         * customer gets, and it already tells them the order is paid for.
         */
        $receiptRaised = $this->dispatchOrderEmail($order, $lang);

        /*
         * Money that arrived after the receipt had already gone: a redirect
         * that came back late, a webhook, an admin marking off a transfer.
         * Only then is a separate "payment received" worth sending. Raising it
         * on the common path would put two near-identical emails in the inbox
         * a minute apart, which tells the customer nothing the first did not
         * and is the pattern a filter reads as a misconfigured sender.
         */
        if (!$receiptRaised) {
            OrderEmailDispatcher::dispatch(
                $order, OrderEmailEvent::PAYMENT_RECEIVED, $lang
            );
        }
    }


    /**
     * Credit the customer's Cherry Points for an order that has been delivered.
     *
     * Called from updateStatus() on the move into DELIVERED, which is the only
     * way an order reaches that status, and inside the same transaction as the
     * status write so an order can never be marked delivered without its points
     * or credited without being marked.
     *
     * Safe to call more than once: an order that already has a credit against
     * it is left alone. A later return or refund does not delete that credit,
     * it posts an opposing 'order_reversal' movement (see
     * Order::restoreStockAndRewards), so the customer's history still explains
     * itself and the balance nets to zero.
     */
    public function awardLoyaltyPoints($order)
    {
        if (is_null($order) || !$order->user_id) {
            return;
        }

        try {
            /*
             * A delivered order that was never paid for earns nothing. Cash on
             * delivery is marked paid in the same update that delivers it, so
             * this reads the row after that write, not before.
             */
            if (!(int)$order->payment_done) {

                Log::warning('Delivered order is not marked paid; no points awarded', [
                    'order_id' => $order->id
                ]);

                return;
            }

            $alreadyEarned = PointTransaction::where('reference_id', $order->id)
                ->where('source', 'order')
                ->where('type', 'credit')
                ->exists();

            if ($alreadyEarned) {
                return;
            }

            // Points are earned on what the customer actually paid.
            $earnedPoints = (int)floor(max(0, (float)$order->total_amount));

            if ($earnedPoints > 0) {
                PointTransaction::create([
                    'user_id' => $order->user_id,
                    'type' => 'credit',
                    'points' => $earnedPoints,
                    'value' => $earnedPoints * 0.01,
                    'source' => 'order',
                    'reference_id' => $order->id,
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('Awarding loyalty points failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage()
            ]);
        }
    }


    /**
     * Send the order confirmation without depending on the customer's browser.
     *
     * QA audit EML-01. A closed tab, a dropped connection or a payment redirect
     * that never came back meant no email was ever sent, with no queue, no
     * retry and no record.
     *
     * Called from every branch that creates an order, so the confirmation goes
     * out as soon as the order exists whatever was used to pay for it, and
     * again from markOrderPaid() when a gateway confirms later. Exactly one
     * email results -- the dispatcher claims the event against a unique index,
     * and the second caller loses the claim and returns.
     *
     * Who it reaches is decided from the order itself -- the account holder,
     * the guest_user row matched on user_token, or the contact details
     * captured at checkout -- so a guest is emailed on the same footing as a
     * signed-in customer.
     *
     * The send itself now happens on the queue. This method hands the event
     * over and returns; nothing about the customer's checkout waits on an SMTP
     * handshake any more.
     */
    public function dispatchOrderEmail($order, $lang = null, $timeZone = null)
    {
        if (is_null($order)) {
            return;
        }

        $queued = OrderEmailDispatcher::dispatch(
            $order,
            OrderEmailEvent::ORDER_PLACED,
            $lang,
            ['time_zone' => $timeZone]
        );

        /*
         * orders.confirmation_email_sent_at is kept in step for the admin
         * order screens and the reports that already read it. The column is no
         * longer the lock -- order_email_log is -- but it is still the answer
         * to "was this order's receipt raised", and leaving it null would make
         * every order look unconfirmed.
         */
        if ($queued) {
            Order::where('id', $order->id)
                ->whereNull('confirmation_email_sent_at')
                ->update(['confirmation_email_sent_at' => now()]);
        }

        return $queued;
    }


    /**
     * Tell the customer their order moved.
     *
     * QA audit EML-02. updateStatus() wrote the column and returned; nothing
     * notified anybody, and the delivered notice was a separate button an admin
     * had to remember to press.
     *
     * Every status with an email of its own goes through the one dispatcher, so
     * a rapid sequence of updates produces one email per status rather than one
     * per click -- and an admin who sets the same status twice produces one.
     */
    public function notifyStatusChange($order, $newStatus, $lang = null, array $context = [])
    {
        if (is_null($order)) {
            return;
        }

        $event = OrderEmailEvent::forStatus($newStatus);

        if (is_null($event)) {
            // PENDING is the only status with no email; see OrderEmailEvent.
            return;
        }

        OrderEmailDispatcher::dispatch($order, $event, $lang,
            array_merge(['status' => (int)$newStatus], $context));
    }


    /**
     * Compare a gateway-reported amount and currency with the order.
     */
    private function amountMatches($charged, $expected, $chargedCurrency, $expectedCurrency)
    {
        if (!\App\Models\Helper\GatewayVerifier::amountsMatch($charged, $expected)) {
            return false;
        }

        if (is_null($chargedCurrency) || $chargedCurrency === '') {
            return true;
        }

        return strtoupper((string)$chargedCurrency) === strtoupper((string)$expectedCurrency);
    }





    /**
     * Order placement. Was action() itself; wrapped so a Stripe authorisation
     * made for an order that is then refused - out of stock, a voucher gone,
     * the capture check failing - is released rather than left held on the
     * shopper's card until it expires.
     */
    public function action(Request $request, TwilioService $twilio)
    {
        $response = $this->processOrder($request, $twilio);

        try {
            $stripe = (int)$request->order_method === (int)Config::get('constants.paymentMethod.STRIPE');
            $status = (int)(json_decode($response->getContent(), true)['status'] ?? 0);

            if ($stripe && $status !== 200 && $request->payment_intent && ($payment = Payment::first())) {
                \App\Models\Helper\StripeCheckout::cancel($payment, $request->payment_intent);
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe authorisation release check failed', ['message' => $e->getMessage()]);
        }

        return $response;
    }

    protected function processOrder(Request $request, TwilioService $twilio)
    {
        try {
            $lang = $request->header('language');

            $params = json_decode(Utils::jsDecryption($request->data));

            /*
             * QA audit SEC-05. The wrapper around this payload uses a hardcoded
             * key and static IV shipped in the client bundle, so it is
             * obfuscation, not authentication — every value below is attacker
             * controlled and is treated that way: the order number, the
             * transaction id and every price are derived server-side further
             * down and never read from here.
             *
             * A malformed payload used to reach $params->user_token as a null
             * dereference.
             */
            if (!is_object($params)) {
                return response()->json(Validation::error($request->token,
                    __('lang.went_wrong', [], $lang)
                ));
            }

            $request->request->add(['user_token' => $params->user_token ?? null]);
            $request->request->add(['order_method' => $params->order_method ?? null]);
            $request->request->add(['voucher' => $params->voucher ?? null]);
            $request->request->add(['time_zone' => $params->time_zone ?? null]);
            // The SumUp checkout this order is claimed to have been paid under.
            $request->request->add(['checkout_id' => $params->checkout_id ?? null]);
            // The Stripe authorisation this order is to be paid with.
            $request->request->add(['payment_intent' => $params->payment_intent ?? null]);
            // Whether one is required is decided from the stored profile below,
            // never from this payload; only the text itself comes from here.
            $request->request->add(['purchase_order_number' => $params->purchase_order_number ?? null]);

            /*
             * A suspended account may still sign in and read its history, but
             * it may not buy.
             *
             * First, ahead of the gateway and basket checks: those fail for
             * their own reasons, and a suspended customer who is told
             * "something went wrong" will keep trying and then call. Enforced
             * here rather than by hiding the button — the order endpoint is
             * reachable with nothing but a bearer token, so a check in the
             * browser stops nobody.
             */
            $checkingOut = $request->user('user');

            if ($checkingOut && !$checkingOut->canCheckout()) {
                return response()->json(Validation::error($request->token,
                    'This account cannot place orders at the moment. Please contact us.'
                ));
            }

            $payment = Payment::first();

            if ((int)$payment->cash_on_delivery != 1 &&
                ((int)$request->order_method == Config::get('constants.paymentMethod.CASH_ON_DELIVERY'))) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_cod', [], $lang)
                ));

            } else if ((int)$payment->paypal != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.PAYPAL')
            ) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_paypal', [], $lang)
                ));

            } else if (!$payment->acceptsCardMethod(Config::get('constants.paymentMethod.STRIPE')) &&
                (int)$request->order_method == Config::get('constants.paymentMethod.STRIPE')
            ) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));

            } else if ((int)$request->order_method == Config::get('constants.paymentMethod.STRIPE') &&
                strpos((string)$request->payment_intent, 'pi_') !== 0
            ) {
                // Pay first: a Stripe order is only placed with an authorised card.
                return response()->json(Validation::error($request->token,
                    'The card payment was not completed. Please try again.'
                ));

            }else if (!$payment->acceptsCardMethod(Config::get('constants.paymentMethod.SUMUP')) &&
                (int)$request->order_method == Config::get('constants.paymentMethod.SUMUP')
            ) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));
            } else if ((int)$payment->razorpay != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.RAZORPAY')
            ) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));
            } else if ((int)$payment->iyzico_payment != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.IYZICO_PAYMENT')
            ) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));
            } else if ((int)$payment->bank != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.BANK')
            ) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));

            } else if ((int)$payment->payfast_payment != 1 &&
                (int)$request->order_method == Config::get('constants.paymentMethod.PAYFAST')
            ) {
                return response()->json(Validation::error($request->token,
                    __('lang.accepted_gateway', [], $lang)
                ));
            }


            $validate = Validation::order($request);

            if ($validate) {
                return response()->json($validate);
            }

            $user = $request->user('user');


            /*
             * The purchase order number, for the business accounts that asked
             * to be invoiced against one at signup.
             *
             * Whether it is required is decided from the stored profile, never
             * from the request: a browser saying "this account does not need a
             * PO" is not evidence about an account, and the flag is exactly the
             * kind of thing a customer would switch off to skip a field.
             */
            $purchaseOrderNumber = null;

            if ($user) {

                $profile = $user->businessProfile;

                $needsPo = $profile
                    && $user->account_type !== 'personal'
                    && (bool)$profile->requires_po;

                $purchaseOrderNumber = trim((string)$request->input('purchase_order_number'));

                if ($needsPo && $purchaseOrderNumber === '') {
                    return response()->json(Validation::error($request->token,
                        'Enter the purchase order number for this order.',
                        'purchase_order_number'
                    ));
                }

                if (strlen($purchaseOrderNumber) > 64) {
                    return response()->json(Validation::error($request->token,
                        'That purchase order number is too long.',
                        'purchase_order_number'
                    ));
                }

                $purchaseOrderNumber = $purchaseOrderNumber === '' ? null : $purchaseOrderNumber;
            }

            $cartQuery = Cart::with('product_inner.admin');


            if ($request->user('user')) {

                $cartQuery = $cartQuery->where('user_id', $request->user('user')->id);

            } else if ($request->user_token) {

                $setting = Setting::select('guest_checkout')->first();

                if (!$setting->guest_checkout) {
                    return response()->json(Validation::unauthorized());
                }

                /*
                 * A guest confirms their email on the checkout screen before
                 * they can pay. The receipt, the tracking link and the account
                 * credentials this order creates all go to that address, so an
                 * unconfirmed one must not get as far as an order.
                 */
                $guestCheckingOut = GuestUser::where('user_token', $request->user_token)->first();

                if (!$guestCheckingOut || !$guestCheckingOut->hasVerifiedEmail()) {
                    return response()->json(Validation::error($request->token,
                        'Confirm your email address before placing the order.',
                        'form', $lang
                    ));
                }

                $cartQuery = $cartQuery->where('user_token', $request->user_token);
            } else {

                return response()->json(Validation::errorLang($lang));
            }

            // The Buy Now line if there is one, else the ticked basket lines.
            $existingCart = $cartQuery->with('shipping_place.shipping_rule')
                ->forCheckout()
                ->with('updated_inventory')
                ->get();

            
            /*
             * QA audit INT-01. Every line below used to be wrapped in
             * "if ($cart->shipping_place_id && !is_null($cart->product_inner))"
             * and silently skipped when that was false. When it was false for
             * every row the order was still created, still reported as a
             * success, and still saved with total_amount 0.00 — which is how a
             * third of the orders in this database ended up with no line items.
             *
             * Refuse the checkout instead.
             */
            $unshippable = [];

            foreach ($existingCart as $cart) {
                if (is_null($cart->product_inner)) {
                    $unshippable[$cart->id] = [
                        __('lang.private_product', ['product' => optional($cart->product)->title], $lang)
                    ];
                }
            }

            if (count($unshippable) > 0) {
                return response()->json(Validation::error($request->token, $unshippable, 'product'));
            }

            /*
             * Delivered or collected is one choice for the whole order: it is
             * charged once, so lines that disagree have no single price. The
             * row a line was saved with is not trusted either - which row and
             * what it costs are decided again below from the address, by
             * Helper\Shipping, once the address is known.
             */
            $lineTypes = $existingCart->pluck('shipping_type')->map(function ($type) {
                return (int)$type;
            })->unique();

            if ($existingCart->isNotEmpty() &&
                ($lineTypes->count() !== 1 || !Shipping::isValidType($lineTypes->first()))) {
                return response()->json(Validation::error($request->token,
                    'Choose whether to have your order delivered or to collect it.'
                ));
            }

            $shippingType = $lineTypes->first();

            /*
             * Business and approved-trade accounts buy at the quantity bands in
             * business_product_pricing. The bands were being advertised on the
             * product page and then ignored everywhere that takes money, so a
             * business customer was quoted a wholesale rate and charged retail.
             *
             * Loaded once here and read by both the subtotal loop below and the
             * charging loop further down, so the two cannot disagree.
             */
            $businessTiers = BusinessProductPricing::tierMap(
                $request->user('user'),
                $existingCart->pluck('product_id')
            );

            $totalPriceWithoutShipping = 0;
            foreach ($existingCart as $cart) {
                // One ladder, shared with add-to-cart and the charging loop below.
                $currentPrice = Pricing::unitPrice(
                    $cart->product_inner, $cart->updated_inventory, $cart->quantity,
                    $businessTiers->get($cart->product_inner->id)
                );
                $bundleQtyOffer = Pricing::bundleFreeQuantity($cart->product_inner, $cart->quantity);

                $totalPriceWithoutShipping += $currentPrice * ((int)$cart->quantity - $bundleQtyOffer);
            }

            $totalPriceWithoutShipping = Pricing::round($totalPriceWithoutShipping);

            $offeredVoucher = 0;
            $voucher = null;

            if ($request->voucher) {
                // QA audit CPN-03: the preview endpoint filters on apply_type,
                // so this one must too, or codes rejected on the cart page are
                // accepted at checkout.
                $voucher = Voucher::where('code', $request->voucher)
                    ->where('apply_type', 1)
                    ->where('status', Config::get('constants.status.PUBLIC'))
                    // a redeemed gift voucher is its owner's alone
                    ->usableBy(optional($request->user('user'))->id)
                    ->first();

                if (is_null($voucher)) {
                    return response()->json(Validation::error($request->token,
                        __('lang.invalid_voucher', [], $lang)
                    ));
                }

                if ($voucher->isGiftCard() && $voucher->giftBalance() <= 0) {
                    return response()->json(Validation::error($request->token,
                        'This gift card has been used up.'
                    ));
                }

                /*
                 * QA audit CPN-01. This check used to read
                 * "if ($start >= $now && $now >= $end)", which is satisfiable
                 * only when start >= end — so it never fired and every expired
                 * and not-yet-started voucher was accepted.
                 */
                $start = new Carbon($voucher->start_time);
                $end = new Carbon($voucher->end_time);
                $now = Carbon::now();

                if ($now->lt($start) || $now->gt($end)) {
                    return response()->json(Validation::error($request->token,
                        __('lang.voucher_expired', [], $lang)
                    ));
                }

                if ($totalPriceWithoutShipping < $voucher->min_spend) {
                    $setting = Setting::select('currency', 'currency_icon', 'currency_position')->first();
                    $price = $voucher->min_spend . $setting->currency_icon;

                    if ((int)$setting->currency_position == Config::get('constants.currencyPosition.PRE')) {
                        $price = $setting->currency_icon . $voucher->min_spend;
                    }

                    return response()->json(Validation::error($request->token,
                        __('lang.min_spent', ['amount' => $price], $lang)
                    ));
                }

                /*
                 * QA audit CPN-05. Redemptions used to be counted over every
                 * order carrying the voucher id, including unpaid, abandoned
                 * and cancelled ones — a declined card burned a single-use
                 * code. Only orders that stand actually consume a redemption.
                 */
                $totalOrdered = Voucher::redemptionQuery($voucher->id)->count();

                if ($totalOrdered >= $voucher->usage_limit) {
                    return response()->json(Validation::error($request->token,
                        __('lang.voucher_exceeded', [], $lang)
                    ));
                }


                $OrderedByUserQuery = Voucher::redemptionQuery($voucher->id);

                if ($request->user('user')) {

                    $OrderedByUserQuery = $OrderedByUserQuery->where('user_id', $request->user('user')->id);

                } else if ($request->user_token) {

                    $OrderedByUserQuery = $OrderedByUserQuery->where('user_token', $request->user_token);

                } else {

                    return response()->json(Validation::errorLang($lang));
                }

                $totalOrderedByUser = $OrderedByUserQuery->count();

                if ($totalOrderedByUser >= $voucher->limit_per_customer) {
                    return response()->json(Validation::error($request->token,
                        __('lang.voucher_max', [], $lang)
                    ));
                }

                // QA audit CPN-04: the cap was applied with an (int) cast.
                $offeredVoucher = Pricing::voucherDiscount($voucher, $totalPriceWithoutShipping);
            }

            /*
             * Every line must still be buyable: published, and in stock unless
             * backorder is allowed. The same rule the pre-payment check uses
             * (CheckoutStock); the units themselves are taken further down
             * with a conditional decrement, which is the actual guarantee.
             */
            $cartError = CheckoutStock::refusals(
                $existingCart,
                optional($request->user('user'))->id,
                $request->user_token,
                $lang
            );

            if (count($cartError) > 0) {
                return response()->json(Validation::error($request->token, $cartError, 'product'));
            }

            $setting = Setting::select('currency')->first();

            if (!$voucher) {
                $voucher['id'] = null;
            }


            if (count($existingCart) > 0) {
                $now = Carbon::now();


                $orderArr = [
                    'order_method' => $request->order_method,
                    'voucher_id' => $voucher['id'],
                    'currency' => $setting->currency,
                    'updated_at' => $now,
                    'created_at' => $now,
                ];

                /*
                 * A click-and-collect order is not being delivered anywhere, so
                 * it has no address. The contact details that used to ride along
                 * on the address record are copied onto the order instead, so
                 * confirmation emails and the "ready for pickup" notice still
                 * have somebody to go to.
                 */
                $isCollection = $this->isCollectionOrder($existingCart);

                if ($isCollection) {
                    $contact = $this->collectionContact($request, $user);

                    if (empty($contact['email'])) {
                        return response()->json(Validation::error($request->token,
                            __('lang.select_contact_details', [], $lang)
                        ));
                    }

                    $orderArr['contact_name'] = $contact['name'];
                    $orderArr['contact_email'] = $contact['email'];
                    $orderArr['contact_phone'] = $contact['phone'];
                }

                if ($request->user('user')) {

                    $orderArr['user_id'] = $request->user('user')->id;
                    /*
                     * QA audit PAY-05: the order number and transaction id used
                     * to be taken from the request, letting a caller choose
                     * their own. Both are now server-generated; a real gateway
                     * reference is written by paymentDone() after verification.
                     */
                    $orderArr['order'] = Utils::generateOrderNumber();

                    if (!$isCollection) {
                        if (!$user->default_address) {
                            return response()->json(Validation::error($request->token,
                                __('lang.select_address', [], $lang)
                            ));
                        }

                        $orderArr['user_address_id'] = $user->default_address;
                    }

                } else if ($request->user_token) {

                    // QA audit ORD-02: the created record used to be discarded,
                    // so the next line dereferenced null and 500'd on a guest's
                    // first order.
                    $guestUser = GuestUser::where('user_token', $request->user_token)->first();

                    if (!$guestUser) {
                        $guestUser = GuestUser::create([
                            "user_token" => $request->user_token
                        ]);
                    }


                    if (!$isCollection) {
                        if (!$guestUser->default_address) {
                            $userAddress = UserAddress::where('user_token', $request->user_token)->first();

                            if (is_null($userAddress)) {
                                return response()->json(Validation::error($request->token,
                                    __('lang.select_address', [], $lang)
                                ));
                            }

                            GuestUser::where('user_token', $request->user_token)
                                ->update(['default_address' => $userAddress->id]);

                            $guestUser = $guestUser->fresh();

                        }

                        $orderArr['user_address_id'] = $guestUser->default_address;
                    }

                    $orderArr['user_token'] = $request->user_token;
                    $orderArr['order'] = Utils::generateOrderNumber();
                }


                /*
                 * What delivery costs, decided here and nowhere else: the row
                 * that covers the delivery address (or the collection point),
                 * charged once for the order. A product limited to other
                 * countries stops the order with the same message the shipping
                 * page showed.
                 */
                $deliveryAddress = $isCollection
                    ? null
                    : UserAddress::find($orderArr['user_address_id'] ?? null);

                if (!$isCollection && !$deliveryAddress) {
                    return response()->json(Validation::error($request->token,
                        __('lang.select_address', [], $lang)
                    ));
                }

                $shippingQuote = Shipping::quote(
                    $shippingType,
                    optional($deliveryAddress)->country,
                    optional($deliveryAddress)->state,
                    $isCollection ? $existingCart->pluck('shipping_place_id')->filter()->first() : null,
                    $existingCart->pluck('product_inner'),
                    Shipping::linesWeight($existingCart)
                );

                if (!$shippingQuote['ok']) {
                    $refused = [];

                    foreach ($existingCart as $c) {
                        if (isset($shippingQuote['restricted'][$c->product_inner->id])) {
                            $refused[$c->id] = [$shippingQuote['restricted'][$c->product_inner->id]];
                        }
                    }

                    return response()->json($refused
                        ? Validation::error($request->token, $refused, 'product')
                        : Validation::error($request->token, $shippingQuote['message']));
                }

                $orderShippingPlace = $shippingQuote['place'];
                $orderShippingPrice = $shippingQuote['price'];

                /*
                 * A member's delivery is free (Helper\Membership). Collection
                 * keeps its price. The basket and the shipping quote ask the
                 * same question, so the figure shown is the figure charged.
                 */
                if ((int)$shippingType === Shipping::deliveryType()
                    && Membership::freeShippingFor($request->user('user'), $totalPriceWithoutShipping)) {
                    $orderShippingPrice = 0.0;
                }
                $shippingCharged = false;

                // Decided above from the stored profile, not from the request.
                $orderArr['purchase_order_number'] = $purchaseOrderNumber;

                /*
                 * Who the customer was at this moment.
                 *
                 * The prices on this order were decided by the account's
                 * entitlement (User::hasBusinessPricing), so the receipt has to
                 * be able to say which entitlement that was without asking the
                 * account again — a business customer downgraded, suspended or
                 * renamed next month must still read as the business order they
                 * placed today, at the business price they were charged.
                 */
                if ($user) {
                    $profile = $user->businessProfile;

                    $orderArr['account_type'] = $user->account_type ?: 'personal';
                    $orderArr['company_name'] = $profile->company_name ?? null;
                    $orderArr['vat_number'] = $profile->vat_number ?? null;
                    $orderArr['cro_number'] = $profile->cro_number ?? null;
                }

                $order = Order::create($orderArr);

                if ($voucher && $offeredVoucher > 0) {

                    OrderedVoucher::create([
                        'order_id' => $order->id,
                        'voucher_id' => $voucher->id,
                        'discount' => $offeredVoucher
                    ]);
                }

                $orderedProducts = [];
                $totalPrice = 0;

                $commission = 0;

                /*if ($this->isVendor) {
                    $commission = $this->user->commission;
                }*/

                $totalCartPrice = 0;

                // What each line charges, for the member discount below.
                $memberLines = [];

                foreach ($existingCart as $key => $cart) {
                    if (!is_null($cart->product_inner)) {

                        /*
                         * QA audit PRC-01. This block used to spell the price
                         * ladder out again, testing the flash price as
                         * "!== null" where the subtotal loop above tested it as
                         * "> 0" — the same product priced two ways inside one
                         * request. Both now call the same method.
                         */
                        $currentPrice = Pricing::unitPrice(
                            $cart->product_inner, $cart->updated_inventory, $cart->quantity,
                            $businessTiers->get($cart->product_inner->id)
                        );

                        /*
                         * Kept on the row, in memory only, so the Essentials
                         * Bundle discount below is worked out against what
                         * this order is actually charging rather than against
                         * the price the basket last stored.
                         */
                        $cart->charged_unit_price = $currentPrice;

                        /*
                         * Delivery is charged once per order, on the first
                         * line; the rest carry 0 so the order's own rows still
                         * add up to what was charged (OrderSummary sums them).
                         */
                        $shippingPrice = $shippingCharged ? 0 : $orderShippingPrice;
                        $shippingCharged = true;


                        $bundleQtyOffer = Pricing::bundleFreeQuantity($cart->product_inner, $cart->quantity);

                        // Units the customer actually pays for on this line.
                        $payableQty = max(0, (int)$cart->quantity - (int)$bundleQtyOffer);

                        /*
                         * Tax calculation.
                         *
                         * excludeVAT = 1 means this product's price is quoted
                         * excluding VAT, so tax is added on top; anything else
                         * is treated as VAT-inclusive and nothing is added. That
                         * is the behaviour this store has always had and is left
                         * unchanged deliberately — see TAX-01 in the audit, which
                         * is a business decision, not a code fix.
                         *
                         * QA audit TAX-02: the rate now comes from the joined
                         * tax_rules row instead of a hardcoded 23%.
                         */
                        $taxRule = $cart->product_inner->tax_rules;

                        /*
                         * Read excludeVAT off the relation when it was selected,
                         * and fall back to the product row when it was not.
                         * Without the fallback a missing column reads as null,
                         * which silently means "not taxable" and drops VAT from
                         * the whole order without any error.
                         */
                        $excludeVAT = $cart->product_inner->getAttribute('excludeVAT');

                        if (is_null($excludeVAT)) {
                            $excludeVAT = Product::where('id', $cart->product_inner->id)
                                ->value('excludeVAT');
                        }

                        $productTaxable = (int)$excludeVAT === 1;

                        $taxQtyOffer = Pricing::unitTax($taxRule, $currentPrice, $productTaxable);

                        // QA audit ORD-03: free bundle units were being taxed.
                        $totalTax = Pricing::round($taxQtyOffer * $payableQty);
                        $priceWithoutBundle = Pricing::round($currentPrice * $payableQty);
                        $total = Pricing::round($shippingPrice + $totalTax + $priceWithoutBundle);

                        $totalPrice += $total;

                        OrderedProductsInventory::create([
                            'product_id' => $cart->product_inner->id,
                            'inventory_id' => $cart->inventory_id,
                            'quantity' => $cart->quantity,
                            /*
                             * The struck-through "before" price this line's
                             * discount is measured against, frozen here so no
                             * later change to the catalogue can move it.
                             *
                             * This was products.selling — the product-level
                             * figure — while the basket and the checkout
                             * summary measured the same discount against the
                             * variant's own list price (CartsController::
                             * repriceForCustomer). On a variation product the
                             * two are different numbers, so the order recorded
                             * a Subtotal and a Product Discount the customer
                             * had never been shown: an iPhone variant listed
                             * at 699 and sold to a business account at 674
                             * was confirmed at 6,990 / −250 and then stored as
                             * 8,990 / −2,250, because products.selling is 899.
                             *
                             * Only the two inflated lines disagreed; the total
                             * matched either way, which is why it went
                             * unnoticed.
                             */
                            'actual_price' => Pricing::listPrice(
                                $cart->product_inner, $cart->updated_inventory, $currentPrice
                            ),
                            // QA audit CART-01: was $cart->price, which the
                            // client chose. Now the price actually charged.
                            'price' => $currentPrice,
                            'shipping_price' => $shippingPrice,
                            'product_voucher_discount_applied' => 0,
                            'order_id' => $order->id,
                        ]);

                        // QA audit ORD-03: the flash-discount base is the amount
                        // charged, not the sum of list prices.
                        $totalCartPrice += $priceWithoutBundle;

                        $memberLines[] = [
                            'product' => $cart->product_inner,
                            'inventory' => $cart->updated_inventory,
                            'quantity' => $payableQty,
                            'unit' => $currentPrice,
                        ];

                        $commission = $cart->product_inner->admin->commission;

                        // Inserting ordered product
                        array_push($orderedProducts, [
                            'commission' => $commission,
                            'tax_price' => $taxQtyOffer,
                            /*
                             * How this line was treated for VAT, frozen here.
                             *
                             * tax_price alone cannot say: it is zero both for
                             * a margin-scheme product and for the 111 products
                             * whose prices already contain their VAT, and those
                             * are very different statements. Recording the rate
                             * and the convention means an invoice reprinted next
                             * year states the VAT that was actually charged,
                             * rather than whatever the product's tax rule says
                             * by then.
                             */
                            'tax_rate' => Pricing::vatRate($taxRule),
                            'tax_inclusive' => !$productTaxable,
                            'commission_amount' => ($currentPrice * $cart->quantity * $commission) / 100,
                            'product_id' => $cart->product_inner->id,
                            'inventory_id' => $cart->inventory_id,
                            'quantity' => $cart->quantity,
                            'shipping_place_id' => $orderShippingPlace->id,
                            'shipping_type' => $shippingType,
                            'purchased' => $cart->product_inner->purchased,
                            'bundle_offer' => $bundleQtyOffer,
                            'shipping_price' => $shippingPrice,
                            'selling' => $currentPrice,
                            'withdrawn' => Config::get('constants.withdrawn.NO'),
                            'order_id' => $order->id,
                            'updated_at' => $now,
                            'created_at' => $now
                        ]);


                        /*
                         * The basket already took these units off the stock
                         * when they were added (CartHold), so those are used
                         * first and only what it was not holding - a
                         * reservation that ran out, say - is taken now. The
                         * same units are never taken twice.
                         *
                         * The rest is taken with a conditional decrement. The
                         * check above ran before this order was written, and
                         * another order for the same last unit can get between
                         * the two; "only if enough are
                         * still there" makes the database pick one winner.
                         * The loser's part-built order is rolled back and
                         * they get the same out-of-stock refusal. Backorder
                         * variants (is_active 1) may still go below zero.
                         */
                        $fromHold = CartHold::consume(
                            optional($request->user('user'))->id,
                            $request->user('user') ? null : $request->user_token,
                            $cart->inventory_id,
                            (int)$cart->quantity
                        );

                        if ($fromHold > 0) {
                            $this->stockTaken[$order->id][] = [$cart->inventory_id, $fromHold];
                        }

                        $fresh = (int)$cart->quantity - $fromHold;

                        if ($fresh > 0) {
                            $take = UpdatedInventory::where('id', $cart->inventory_id);

                            if ((int)optional($cart->updated_inventory)->is_active !== 1) {
                                $take->where('quantity', '>=', $fresh);
                            }

                            if (!$take->decrement('quantity', $fresh)) {
                                throw new StockRanOut($cart->id, $cart->product_inner->title);
                            }

                            $this->stockTaken[$order->id][] = [$cart->inventory_id, $fresh];
                        }
                    }
                }

                /*
                 * A flash sale product may be capped at a number of units
                 * at its sale price. Recompute what those sales have sold
                 * now that this order's lines exist -- once for the whole
                 * basket, and only for rows that actually carry a cap.
                 *
                 * Nothing is reserved earlier than this on purpose: an
                 * abandoned basket must not hold an allowance, and the
                 * product reverts to its normal price rather than becoming
                 * unbuyable, so a race here costs a few units of margin
                 * rather than a refused order.
                 */
                FlashSaleAllowance::refresh($existingCart->pluck('product_id'));

                $flashDiscountAmount = 0;

                /*
                 * The store-wide flash discount is offered, not imposed.
                 *
                 * It used to apply to every order the moment a promotion was
                 * live, whatever the basket had shown. The customer now asks
                 * for it on the basket and the answer travels here, so an
                 * order that was quoted without the discount is charged
                 * without it.
                 *
                 * Only the CHOICE comes from the browser. The amount is still
                 * decided here, from the live promotion and the goods actually
                 * being charged, exactly as before -- a client that asks for
                 * the discount cannot say how much it is worth.
                 */
                $wantsFlashDiscount = isset($params->apply_flash_discount)
                    && (int)$params->apply_flash_discount === 1;

                $flash = $wantsFlashDiscount ? FlashDiscount::current() : null;

                if($flash){
                    $flashDiscountAmount = Pricing::flashDiscount($flash, $totalCartPrice);

                    if ($flashDiscountAmount > 0) {

                        OrderedFlashDiscount::create([
                            'order_id' => $order->id,
                            'flash_discount_id' => $flash->id,
                            'discount' => $flashDiscountAmount
                        ]);
                    }
                }


                $totalPrice = Pricing::round($totalPrice);

                /*
                 * The Essentials Bundle discount.
                 *
                 * Re-derived here from `carts.bundle_deal_id` and the prices
                 * this order is charging -- never read from the basket, and
                 * never from anything the browser sent. A bundle only earns it
                 * while the order still contains every one of its products, so
                 * a shopper who removed an item is charged the ordinary prices
                 * for what is left rather than a set price for part of a set.
                 *
                 * Taken off after the voucher and the flash discount, and
                 * before loyalty and points, so the chain below can never take
                 * more than the goods are worth.
                 */
                $bundleBreakdown = BundleDiscount::forRows($existingCart);

                $bundleDiscountAmount = Pricing::round(max(0, min(
                    $bundleBreakdown['total'],
                    max(0, Pricing::round($totalPrice - $offeredVoucher - $flashDiscountAmount))
                )));

                /*
                 * The customer's loyalty group discount.
                 *
                 * This is the whole point of the loyalty group feature and it
                 * was missing: an admin could put a customer in a discount
                 * group and the discount was never applied to anything. The
                 * group is resolved server-side from the customer's live
                 * memberships, not from anything the browser sent, so the
                 * checkout preview cannot decide what is charged.
                 *
                 * It is taken off what is still owed after the voucher and the
                 * flash discount, so the three together can never exceed the
                 * value of the goods.
                 */
                $loyaltyBest = null;
                $loyaltyDiscountAmount = 0;

                if ($request->user('user')) {

                    /*
                     * The loyalty group or the membership discount, whichever
                     * is bigger - never both (Helper\Membership).
                     */
                    $loyaltyBest = Membership::bestOrderDiscount(
                        $request->user('user'),
                        $totalCartPrice,
                        $memberLines
                    );

                    if ($loyaltyBest) {

                        $remainingBeforeLoyalty = max(0, Pricing::round(
                            $totalPrice - $offeredVoucher - $flashDiscountAmount
                            - $bundleDiscountAmount));

                        $loyaltyDiscountAmount = Pricing::round(max(0, min(
                            $loyaltyBest['discount'],
                            $remainingBeforeLoyalty
                        )));

                        // Record what was actually granted, not what was offered.
                        $loyaltyBest['discount'] = $loyaltyDiscountAmount;
                    }
                }

                $pointsDiscount = 0;

                if ($request->user('user')) {
                    $user = $request->user('user');

                    if(isset($params->use_points) && (int)$params->use_points === 1){
                        $availablePoints = PointTransaction::where('user_id', $user->id)
                        ->selectRaw("
                            SUM(
                                CASE
                                    WHEN type='credit' THEN points
                                    ELSE -points
                                END
                            ) as total
                        ")
                        ->value('total') ?? 0;

                        $availableValue = $availablePoints * 0.01;

                        /*
                         * Points can only pay for what is still owed after the
                         * voucher, the flash discount and the loyalty group
                         * discount, otherwise they can exceed the order value
                         * between them.
                         */
                        $remainingAfterDiscounts = max(0,
                            $totalPrice - $offeredVoucher - $flashDiscountAmount
                            - $bundleDiscountAmount - $loyaltyDiscountAmount);

                        $pointsDiscount = Pricing::round(min(
                            (float)($params->points_discount ?? 0),
                            $availableValue,
                            $remainingAfterDiscounts
                        ));

                        $pointsDiscount = max(0, $pointsDiscount);

                        $usedPoints = floor($pointsDiscount / 0.01);

                        if ($usedPoints > 0) {

                            PointTransaction::create([
                                'user_id' => $user->id,
                                'type' => 'debit',
                                'points' => $usedPoints,
                                'value' => $pointsDiscount,
                                'source' => 'order',
                                'reference_id' => $order->id,
                            ]);
                        }
                    }

                    /*
                     * QA audit ACC-02: points used to be *earned* here, before
                     * any gateway had confirmed anything, and were never
                     * reversed when the payment failed. Earning now happens in
                     * markOrderPaid(), once the money is real.
                     */
                }

                /*
                 * QA audit MON-01 / INT-01.
                 *
                 * The payable total is computed once, here, and every branch
                 * below uses this one variable. Each of the nine payment
                 * branches used to write "$totalPrice - $offeredVoucher" of its
                 * own, so the flash discount and the loyalty points were shown
                 * to the customer, recorded against the order, debited from the
                 * points balance — and then never taken off the amount charged.
                 *
                 * An order with no line items is also refused outright rather
                 * than being saved at 0.00.
                 */
                if (count($orderedProducts) === 0) {
                    $order->delete();

                    return response()->json(Validation::error($request->token,
                        __('lang.no_cart', [], $lang)
                    ));
                }

                $payableTotal = Pricing::round(max(0,
                    $totalPrice - $offeredVoucher - $flashDiscountAmount
                    - $bundleDiscountAmount - $loyaltyDiscountAmount
                    - $pointsDiscount));

                /*
                 * Split payment: part from the wallet, the rest by the chosen
                 * method. The part is worked out here from the wallet balance
                 * (never the browser's figure) and debited now, for every
                 * method; rollbackOrder() and restoreStockAndRewards() give it
                 * back if the order dies unpaid. total_amount stays the whole
                 * order - $gatewayAmount is what the method itself charges.
                 */
                $walletPart = 0;

                if ($request->user('user')
                    && isset($params->use_wallet) && (int)$params->use_wallet === 1
                    && (int)$request->order_method !== (int)Config::get('constants.paymentMethod.WALLET')) {

                    $walletPart = WalletSplit::apply($order, $request->user('user')->id, $payableTotal);
                }

                $gatewayAmount = Pricing::round($payableTotal - $walletPart);

                /*
                 * Only now, past the point where the order can still be thrown
                 * away for having no line items, is the loyalty benefit spent.
                 * Recording it earlier would burn a one-time discount on an
                 * order that never existed.
                 */
                if ($loyaltyDiscountAmount > 0) {
                    Membership::redeemOrderDiscount($loyaltyBest, $order->id);
                }

                /*
                 * Record what each bundle actually took off, for the same
                 * reason as the flash and loyalty rows above: OrderSummary
                 * re-derives every total from the order's own rows, so a
                 * discount that reduces the amount charged but is written
                 * nowhere makes the confirmation, the invoice and the admin
                 * order detail all add up to more than was taken.
                 *
                 * Written at the granted amount and in proportion, so the rows
                 * sum to exactly the figure taken off even when the chain
                 * above capped it.
                 */
                if ($bundleDiscountAmount > 0 && $bundleBreakdown['total'] > 0) {

                    $scale = $bundleDiscountAmount / $bundleBreakdown['total'];
                    $written = 0.0;
                    $last = count($bundleBreakdown['bundles']) - 1;

                    foreach ($bundleBreakdown['bundles'] as $index => $granted) {

                        // The last row takes the rounding, so the rows total
                        // the charged figure to the cent.
                        $amount = $index === $last
                            ? Pricing::round($bundleDiscountAmount - $written)
                            : Pricing::round($granted['discount'] * $scale);

                        $written += $amount;

                        OrderedBundleDiscount::create([
                            'order_id' => $order->id,
                            'bundle_deal_id' => $granted['id'],
                            'title' => $granted['title'],
                            'discount' => $amount,
                        ]);
                    }
                }

                $result = OrderedProduct::insert($orderedProducts);

                /*
                 * A SumUp order is only an order if SumUp took the money.
                 *
                 * This used to be settled afterwards, by paymentDone() on a
                 * second call from the browser: the order was created and
                 * returned as a success first, and a refused or unverifiable
                 * payment left it sitting there unpaid - which is every
                 * order_method 9 row in this database with payment_done 0 and
                 * no trans_id. Confirming here, before the basket is emptied
                 * and before anyone is notified, means a failed payment leaves
                 * no order behind at all and the shopper keeps their basket.
                 *
                 * Deliberately placed after the line items are inserted, so
                 * rollbackOrder() can put the stock those lines took back.
                 */
                if ((int)$request->order_method == Config::get('constants.paymentMethod.SUMUP')) {

                    $sumupVerified = \App\Models\Helper\GatewayVerifier::verifySumup(
                        $payment,
                        $request->checkout_id,
                        $gatewayAmount,
                        $setting->currency
                    );

                    if (!$sumupVerified['ok']) {

                        /*
                         * Two different failures. SumUp saying it never took
                         * the money means there is nothing to keep, so the
                         * order goes. SumUp saying it did take money that does
                         * not match this order is the opposite: dropping the
                         * order would destroy the only record of a real
                         * charge, so it stays, unpaid and flagged, for a
                         * person to reconcile.
                         */
                        if (empty($sumupVerified['charged'])) {

                            Log::warning('SumUp payment not completed - order rolled back', [
                                'order_id' => $order->id,
                                'checkout_id' => $request->checkout_id,
                                'expected_amount' => $gatewayAmount,
                                'reason' => $sumupVerified['reason']
                            ]);

                            $this->rollbackOrder($order);

                            return response()->json(Validation::error($request->token,
                                __('lang.payment_unconfirmed', [], $lang)
                            ));
                        }

                        Log::error('SumUp charged an amount this order does not match', [
                            'order_id' => $order->id,
                            'order_number' => $order->order,
                            'checkout_id' => $request->checkout_id,
                            'expected_amount' => $gatewayAmount,
                            'reason' => $sumupVerified['reason']
                        ]);

                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);

                        return response()->json(Validation::error($request->token,
                            __('lang.payment_unconfirmed', [], $lang)
                                . ' (' . $order->order . ')'
                        ));
                    }

                    /*
                     * One checkout, one order. A replayed submit - a double
                     * click, a retried request - would otherwise verify against
                     * the same paid checkout a second time and leave two paid
                     * orders behind for a single charge.
                     */
                    $alreadyUsed = Order::where('trans_id', $sumupVerified['reference'])
                        ->where('id', '!=', $order->id)
                        ->first();

                    if ($alreadyUsed) {

                        Log::warning('SumUp checkout already recorded against an order', [
                            'order_id' => $order->id,
                            'existing_order_id' => $alreadyUsed->id,
                            'checkout_id' => $request->checkout_id
                        ]);

                        $this->rollbackOrder($order);

                        return response()->json(Validation::error($request->token,
                            __('lang.payment_already_used', ['order' => $alreadyUsed->order], $lang)
                        ));
                    }

                    /*
                     * Recorded now rather than left to paymentDone(), which the
                     * browser may never get to call - a closed tab after a
                     * successful charge used to be enough to lose it.
                     */
                    Order::where('id', $order->id)->update(array_merge(
                        Order::paymentStamp(true),
                        [
                            'trans_id' => $sumupVerified['reference'],
                            'total_amount' => $payableTotal,
                        ]
                    ));

                    $this->markOrderPaid($order->fresh(), $lang);
                }

                /*
                 * Stripe: the card was authorised before this order existed
                 * (StripeCheckout). The money is taken here, once the order
                 * and its lines are in place, and only for this order's own
                 * total. Nothing is taken on any failure, so the order is
                 * simply rolled back and the shopper keeps their basket; the
                 * action() wrapper releases the authorisation.
                 */
                if ((int)$request->order_method == Config::get('constants.paymentMethod.STRIPE')) {

                    // One authorisation, one order: a double click or a
                    // retried request must not capture it for a second order.
                    $alreadyUsed = Order::where('trans_id', $request->payment_intent)
                        ->where('id', '!=', $order->id)
                        ->first();

                    if ($alreadyUsed) {

                        Log::warning('Stripe authorisation already recorded against an order', [
                            'order_id' => $order->id,
                            'existing_order_id' => $alreadyUsed->id,
                            'intent' => $request->payment_intent
                        ]);

                        $this->rollbackOrder($order);

                        return response()->json(Validation::error($request->token,
                            __('lang.payment_already_used', ['order' => $alreadyUsed->order], $lang)
                        ));
                    }

                    $stripeCaptured = \App\Models\Helper\StripeCheckout::capture(
                        $payment,
                        $request->payment_intent,
                        $gatewayAmount,
                        $setting->currency
                    );

                    if (!$stripeCaptured['ok']) {

                        Log::warning('Stripe payment not captured - order rolled back', [
                            'order_id' => $order->id,
                            'intent' => $request->payment_intent,
                            'expected_amount' => $gatewayAmount,
                            'reason' => $stripeCaptured['reason']
                        ]);

                        $this->rollbackOrder($order);

                        return response()->json(Validation::error($request->token,
                            __('lang.payment_unconfirmed', [], $lang)
                        ));
                    }

                    Order::where('id', $order->id)->update(array_merge(
                        Order::paymentStamp(true),
                        [
                            'trans_id' => $stripeCaptured['reference'],
                            'total_amount' => $payableTotal,
                        ]
                    ));

                    $this->markOrderPaid($order->fresh(), $lang);
                }

                /*
                 * Procurement: ask the suppliers who cover these categories
                 * to quote. Inside the success guard on purpose -- it used to
                 * run before it, so a failed OrderedProduct::insert() still
                 * sent WhatsApps and wrote notification rows for an order
                 * that had no lines in it.
                 */
                if ($result) {

                    $this->notifySuppliers($order, $orderedProducts, $twilio, $lang);

                    if ($request->user('user')) {

                        $user = $request->user('user');

                        // Exactly the lines this order bought: a Buy Now
                        // order leaves the basket as it was.
                        Cart::where('user_id', $user->id)
                            ->whereIn('id', $existingCart->pluck('id')->all())
                            ->delete();


                        $re['name'] = $user->name;
                        $re['email'] = $user->email;

                    } else if ($request->user_token) {

                          Cart::where('user_token', $request->user_token)
                            ->whereIn('id', $existingCart->pluck('id')->all())
                            ->delete();



                        $guestUser = GuestUser::where('user_token', $request->user_token)->first();

                        $re['name'] = $guestUser->name;
                        $re['email'] = $guestUser->email;

                        /*
                         * The guest proved this address to get here, and the
                         * order gives them something worth coming back to, so
                         * the account is opened for them rather than asked for.
                         * It is deliberately the last thing that happens and it
                         * cannot fail the order: an order that went through is
                         * not undone because a welcome email bounced.
                         */
                        $this->openAccountForGuest($guestUser, $lang);

                    }


                    $re['currency'] = $setting->currency;
                    $re['amount'] = number_format($gatewayAmount, 2, '.', '');
                    $re['wallet_amount'] = number_format($walletPart, 2, '.', '');
                    $re['id'] = $order->id;

                    $re['order'] = $order->order;

                    /*
                     * The confirmation goes out here, once, for every order.
                     *
                     * Each payment branch below used to decide for itself, and
                     * only cash-on-delivery and bank transfer ever did: an
                     * order paid by card was emailed only if the browser came
                     * back from the gateway and called paymentDone(). A closed
                     * tab, a failed redirect or a customer who paid and walked
                     * away meant no confirmation was ever sent, and nothing
                     * recorded that. Sending before the branching means it does
                     * not matter which gateway is used, or whether one is used
                     * at all.
                     *
                     * total_amount is written here for the same reason -- every
                     * branch set it to this same figure, so the email can no
                     * longer race a branch that had not got round to it.
                     *
                     * Guests are covered without anything extra: sendOrderEmail
                     * resolves the recipient from the order, falling back to the
                     * guest_user row and then to the checkout contact details.
                     *
                     * dispatchOrderEmail() is idempotent, so markOrderPaid()
                     * calling it again when a gateway confirms is a no-op.
                     */
                    Order::where('id', $order->id)->update([
                        'total_amount' => $payableTotal,
                    ]);

                    $this->dispatchOrderEmail($order->fresh(), $lang);

                    if ((int)$request->order_method == Config::get('constants.paymentMethod.STRIPE')) {

                        $re['order_method'] = Config::get('constants.paymentMethod.STRIPE');

                        // Saving total amount in order to generate report for admin easily
                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);

                        return response()->json(new Response($request->token, $re));

                    }else if((int)$request->order_method == Config::get('constants.paymentMethod.SUMUP')){

                        $re['order_method'] = Config::get('constants.paymentMethod.SUMUP');
                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);

                        return response()->json(new Response($request->token, $re));

                    }else if ((int)$request->order_method == Config::get('constants.paymentMethod.RAZORPAY')) {

                        try {
                            $api = new Api($payment->razorpay_key, $payment->razorpay_secret);
                            $razorpayOrder = $api->order->create([
                                'receipt' => 'order_id_' . $order->id,
                                'amount' => Pricing::toMinorUnits($gatewayAmount),
                                'currency' => $setting->currency
                            ]);
                            $re['payment_token'] = $razorpayOrder->id;
                            $re['order_method'] = Config::get('constants.paymentMethod.RAZORPAY');

                            // Saving total amount in order to generate report for admin easily
                            Order::where('id', $order->id)->update([
                                'payment_token' => $razorpayOrder->id,
                                'total_amount' => $payableTotal,
                            ]);

                            return response()->json(new Response($request->token, $re));
                        } catch (\Exception $e) {

                            /*
                             * QA audit STK-03. This rollback used to iterate
                             * $existingCart while deleting from ordered_products
                             * by $ops->id — cart ids used as ordered_product
                             * ids, so it deleted unrelated rows belonging to
                             * other orders and left this order's own rows behind.
                             */
                            $this->rollbackOrder($order);

                            return response()->json(Validation::error($request->token, $e->getMessage()));
                        }

                    } else if ((int)$request->order_method == Config::get('constants.paymentMethod.CASH_ON_DELIVERY')) {

                        // Saving total amount in order to generate report for admin easily
                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);

                        // Confirmation already sent above, for every method.
                        return response()->json(new Response($request->token, $order));


                    } else if ((int)$request->order_method == Config::get('constants.paymentMethod.BANK')) {

                        // Saving total amount in order to generate report for admin easily
                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);

                        // Confirmation already sent above, for every method.
                        return response()->json(new Response($request->token, $order));


                    } else if ((int)$request->order_method == Config::get('constants.paymentMethod.PAYPAL')) {

                        // Saving total amount in order to generate report for admin easily
                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);
                        return response()->json(new Response($request->token, $re));

                    } else if ((int)$request->order_method == Config::get('constants.paymentMethod.FLUTTERWAVE')) {

                        // Saving total amount in order to generate report for admin easily
                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);
                        return response()->json(new Response($request->token, $re));


                    } else if ((int)$request->order_method == Config::get('constants.paymentMethod.IYZICO_PAYMENT')) {

                        // Saving total amount in order to generate report for admin easily
                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);

                        $re["iyzico_payment"] = IyzicoPayment::initIyzico($request, $order->id);


                        return response()->json(new Response($request->token, $re));

                    } else if ((int)$request->order_method == Config::get('constants.paymentMethod.PAYFAST')) {

                        // Saving total amount in order to generate report for admin easily
                        Order::where('id', $order->id)->update([
                            'total_amount' => $payableTotal,
                        ]);


                        $re['payfast'] = PayFast::getPayFastForm($payment, $order, $re, $gatewayAmount);

                        return response()->json(new Response($request->token, $re));
                    }
                }

                /*
                 * QA audit INT-01. Falling off the end of the payment chain —
                 * an unhandled order_method, or OrderedProduct::insert()
                 * returning false — used to leave the order row, its line items
                 * and the stock deductions committed while telling the customer
                 * something went wrong. Undo the whole thing instead.
                 */
                $this->rollbackOrder($order);

                return response()->json(Validation::error($request->token,
                    __('lang.went_wrong', [], $lang)
                ));
            }
            return response()->json(Validation::error($request->token,
                __('lang.no_cart', [], $lang)
            ));

        } catch (\Throwable $e) {

            /*
             * \Exception used to be caught here, which misses PHP 8 Errors such
             * as the null dereference in the guest branch (ORD-02) — those
             * escaped as a 500 after the order had already been written.
             */
            if (isset($order) && $order) {
                $this->rollbackOrder($order);
            }

            if ($e instanceof StockRanOut) {
                return response()->json(Validation::error($request->token, [
                    $e->cartId => [__('lang.out_stock_product', ['product' => $e->productTitle], $lang)],
                ], 'product'));
            }

            \Illuminate\Support\Facades\Log::error('Order creation failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json(Validation::error($request->token,
                __('lang.went_wrong', [], $lang)
            ));
        }
    }


    /**
     * Undo a part-built order: restore stock, reverse points, drop child rows.
     *
     * QA audit STK-03 / INT-01. Only the Razorpay branch attempted this, and it
     * deleted the wrong rows; every other failure path left the stock deduction
     * in place permanently.
     */
    private function rollbackOrder($order)
    {
        try {
            DB::transaction(function () use ($order) {

                /*
                 * Give back what this request took. Stock is taken line by
                 * line before ordered_products is written, so a failure in
                 * between (a loyalty, wallet or bundle row, say) found no
                 * lines to restore from and the units were lost. An order
                 * rolled back without that record falls back to its lines.
                 */
                if (isset($this->stockTaken[$order->id])) {
                    foreach ($this->stockTaken[$order->id] as [$inventoryId, $units]) {
                        UpdatedInventory::where('id', $inventoryId)->increment('quantity', $units);
                    }
                } else {
                    $lines = OrderedProduct::where('order_id', $order->id)->get();

                    foreach ($lines as $line) {
                        UpdatedInventory::where('id', $line->inventory_id)
                            ->increment('quantity', $line->quantity);
                    }
                }

                OrderedProduct::where('order_id', $order->id)->delete();
                OrderedProductsInventory::where('order_id', $order->id)->delete();
                OrderedVoucher::where('order_id', $order->id)->delete();
                OrderedFlashDiscount::where('order_id', $order->id)->delete();

                /*
                 * The loyalty discount too. Without this a rolled-back order
                 * left its ordered_loyalty_discount row behind pointing at an
                 * order id that no longer exists, and a one-time benefit
                 * stayed spent on an order that was never placed.
                 */
                Loyalty::release($order->id);

                \App\Models\OrderedLoyaltyDiscount::where('order_id', $order->id)->delete();

                // Give back any points that were spent on this order.
                PointTransaction::where('reference_id', $order->id)
                    ->where('source', 'order')
                    ->delete();

                // And the wallet part of a split payment.
                WalletSplit::release($order);

                /*
                 * Procurement requests, and any price a supplier had
                 * already sent back. supplier_notifications has no
                 * foreign key to orders, so these outlive the order --
                 * and they are exactly what BidController checks a
                 * supplier link against, so leaving them behind keeps
                 * the link to a cancelled order working.
                 */
                SupplierBid::where('order_id', $order->id)->delete();
                SupplierNotification::where('order_id', $order->id)->delete();

                Order::where('id', $order->id)->delete();
            });

            unset($this->stockTaken[$order->id]);

            /*
             * The stock above went back whole, reservation included, and the
             * basket is still there: hold its units again.
             */
            CartHold::reconcile($order->user_id, $order->user_id ? null : $order->user_token);

        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::error('Order rollback failed', [
                'order_id' => $order->id ?? null,
                'message' => $ex->getMessage()
            ]);
        }
    }


    public function payFastNotify(Request $request) {
        $lang = $request->header('language');
        $orderId = $request->m_payment_id;

        $orderDetails = Order::find($orderId);
        $payment = Payment::first();

        $pfParamString = '';
        foreach( $request->all() as $key => $val ) {
            if( $key !== 'signature' ) {
                $pfParamString .= $key .'='. urlencode( $val ) .'&';
            } else {
                break;
            }
        }

        $check1 = PayFast::pfValidSignature($request->all(), $pfParamString, $payment->payfast_passphrase);
        $check2 = PayFast::pfValidIP();
        $cartTotal = $orderDetails->total_amount;
        $check3 = PayFast::pfValidPaymentData($cartTotal, $request->all());
        $check4 = PayFast::pfValidServerConfirmation($pfParamString, $payment->payfast_base_url);


        if(!$check1) {
            return response()->json(Validation::error($request->token,
                __('lang.invalid_sig', [], $lang)
            ))->setStatusCode(496);
        }

        if(!$check2) {
            return response()->json(Validation::error($request->token,
                __('lang.invalid_ip', [], $lang)
            ))->setStatusCode(497);
        }

        if(!$check3) {
            return response()->json(Validation::error($request->token,
                __('lang.invalid_amount', [], $lang)
            ))->setStatusCode(498);
        }

        if(!$check4) {
            return response()->json(Validation::error($request->token,
                __('lang.invalid_validation', [], $lang)
            ))->setStatusCode(499);
        }

        Order::where('id', $orderId)->update([
            'payment_done' => $check1 && $check2 && $check3 && $check4
        ]);

        // A confirmed PayFast payment on an order already cancelled.
        $notified = Order::find($orderId);
        if ($notified && $check1 && $check2 && $check3 && $check4 && $notified->isCancelled()) {
            \App\Models\Helper\OrderMailer::alertPaidCancelledOrder($notified);
        }

        return response()->json(new Response($request->token, true));
    }



    /**
     * The public "send me the order email again" endpoint.
     *
     * Kept, because the storefront still calls it, but it is now a thin wrapper
     * around the same queue everything else uses. The rendering and sending it
     * used to do inline now lives in OrderMailer, so the receipt a customer
     * asks for by hand is byte-for-byte the one the system sends by itself.
     *
     * The ownership check stays here where it belongs -- it is about who is
     * asking, which is a property of the request and not of the email.
     */
    public function sendOrderEmail(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            /*
             * QA audit EML-01. This took any order id and sent mail for it, so
             * ids could be walked to spam the real customers. The caller now has
             * to own the order -- except when the server itself is sending the
             * confirmation after a confirmed payment.
             */
            if (!$request->attributes->get('internal_dispatch')) {

                $owns = Order::where('id', $id);

                if ($request->user('user')) {
                    $owns = $owns->where('user_id', $request->user('user')->id);

                } else if ($request->user_token) {
                    $owns = $owns->where('user_token', $request->user_token);

                } else {
                    return response()->json(Validation::errorLang($lang));
                }

                if (!$owns->exists()) {
                    return response()->json(Validation::error($request->token,
                        __('lang.invalid_order', [], $lang)
                    ));
                }
            }

            $order = Order::find($id);

            if (is_null($order)) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_order', [], $lang)
                ));
            }

            $contact = OrderEmailDispatcher::recipient($order);

            if (!$contact['email']) {
                Log::warning('No recipient for order email', ['order_id' => $id]);

                return response()->json(Validation::error($request->token,
                    __('lang.invalid_order', [], $lang)
                ));
            }

            /*
             * Queued rather than sent here. A customer pressing "resend" gets
             * their answer immediately instead of holding the connection open
             * for an SMTP round trip, and the send inherits the retries.
             *
             * dispatch() returning false means the receipt has already been
             * raised for this order, which from the caller's point of view is a
             * success: the email they asked for is on its way or has arrived.
             */
            OrderEmailDispatcher::dispatch(
                $order,
                OrderEmailEvent::ORDER_PLACED,
                $lang,
                ['time_zone' => $request->time_zone]
            );

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

        return response()->json(new Response($request->token, true));
    }


    /**
     * The admin's "tell them it arrived" button.
     *
     * Retained for the admin order screen, which still has the button, but the
     * delivered email is now raised automatically by the status change as well
     * -- and because both go through the same claim, pressing the button after
     * the status already sent it does not produce a second copy.
     */
    public function sendDeliveredEmail(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            $order = Order::find($id);

            if (is_null($order)) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_order', [], $lang)
                ));
            }

            if ((int)Config::get('constants.orderStatus.DELIVERED') !== (int)$order->status) {
                return response()->json(new Response($request->token, true));
            }

            $contact = OrderEmailDispatcher::recipient($order);

            if (!$contact['email']) {
                return response()->json(Validation::error($request->token,
                    __('lang.no_user', [], $lang)
                ));
            }

            OrderEmailDispatcher::dispatch(
                $order,
                OrderEmailEvent::ORDER_DELIVERED,
                $lang
            );

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token,
                explode('.', $ex->getMessage())[0]
            ));
        }

        return response()->json(new Response($request->token, true));
    }



    public function checkOrder(Request $request)
    {

        try {


            $existing = Licence::where('public_key', $request->public_key)
                ->where('secret_key', $request->secret_key)
                ->where('encrypt_iv', $request->encrypt_iv)
                ->where('encrypt_key', $request->encrypt_key)
                ->first();


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

    public function generatePDF($id)
    {
        if ($this->isVendor) {
            return Utils::isDataOwner(null, null);
        }

        if ($can = Utils::userCan($this->user, 'order.view')) {
            return $can;
        }

        if ($can = Utils::userCan($this->user, 'order.edit')) {
            return $can;
        }

        $key = hex2bin("0123456470abcdef0123456789abcdef");
        $iv = hex2bin("abcdef1876343516abcdef9876543210");

        $encrypted = '3z8tIolfpCM8iqPnvDbv3w==';
        $decrypted = openssl_decrypt($encrypted, 'AES-128-CBC', $key, OPENSSL_ZERO_PADDING, $iv);

        $decrypted = trim($decrypted);

        dd($decrypted);


        $order = MailHelper::order($id);
        $objDemo = MailHelper::emailData('jj');
        $objDemo->logo_base64 = FileHelper::imageToBase64($objDemo->image);

        return view('mail_templates.package_delivered', ['order' => $order, 'setting' => $objDemo]);
        // return view('mail_templates.order_placed', ['order' => $order, 'setting' => $objDemo]);
        return view('mail_templates.order_pdf', ['order' => $order, 'setting' => $objDemo]);

        $pdf = PDF::loadView('mail_templates.order_pdf', ['order' => $order, 'setting' => $objDemo])
            ->setPaper('a4', 'potrait')->setWarnings(false);
        return $pdf->download('disney.pdf');
    }
    /**
     * True when every line being ordered is collected in store.
     *
     * Shipping type is recorded per cart line, so a basket that mixes a posted
     * item with a collected one still needs a delivery address.
     */
    private function isCollectionOrder($existingCart)
    {
        if (!count($existingCart)) {
            return false;
        }

        foreach ($existingCart as $cart) {
            if ((int)$cart->shipping_type !== Config::get('constants.shippingTypeIn.PICKUP')) {
                return false;
            }
        }

        return true;
    }


    /**
     * Who to contact about a collection order.
     *
     * A signed-in shopper's account already holds all three; a guest supplied
     * them at the shipping step, where they were stored against their token
     * because there is no address record to hang them off any more.
     */
    private function collectionContact(Request $request, $user)
    {
        if ($user) {
            return [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ];
        }

        $guestUser = $request->user_token
            ? GuestUser::where('user_token', $request->user_token)->first()
            : null;

        return [
            'name' => optional($guestUser)->name,
            'email' => optional($guestUser)->email,
            'phone' => optional($guestUser)->phone,
        ];
    }


    /**
     * Turn a guest who has just ordered into an account, and email them the
     * password to use it.
     *
     * The address was confirmed on the checkout screen, which is why the
     * account is created verified: making them confirm the same address twice
     * would be asking for something they already gave.
     *
     * Every failure here is swallowed on purpose. This runs after the order
     * has been written and the basket cleared, and none of it is worth losing
     * a paid order over - the worst case is a guest order with no account
     * attached, which is exactly what guest orders were before.
     */
    private function openAccountForGuest($guestUser, $lang = null)
    {
        try {

            if (!$guestUser || !$guestUser->hasVerifiedEmail()) {
                return null;
            }

            $existing = User::where('email', $guestUser->email)->first();

            // Somebody already owns this address; their orders are their own.
            if ($existing && $existing->verified) {
                return null;
            }

            $password = Utils::readablePassword();

            /*
             * A number already on a verified account cannot go on this one -
             * registration forbids it, and this account has to obey the same
             * rule. The account is still worth creating without it.
             */
            $phone = $guestUser->phone;

            if ($phone) {

                $phoneTaken = User::where('phone', $phone)
                    ->where('verified', 1)
                    ->when($existing, function ($query) use ($existing) {
                        $query->where('id', '!=', $existing->id);
                    })
                    ->exists();

                if ($phoneTaken) {
                    $phone = null;
                }
            }

            $payload = [
                'name' => $guestUser->name ?: $guestUser->email,
                'email' => $guestUser->email,
                'phone' => $phone,
                'password' => Hash::make($password),
                'account_type' => 'personal',
                'verified' => true,
                'code' => null,
                'code_expires_at' => null,
                'code_attempts' => 0
            ];

            if ($existing) {
                // An address signed up for but never confirmed - this finishes it.
                $existing->update($payload);
                $user = $existing->fresh();

            } else {
                $user = User::create($payload);
            }

            /*
             * The token stays on the rows alongside the new user_id. Nulling it
             * the way the sign-in merge does would lock this browser out of the
             * order it just placed: it is not signed in yet, and guest access
             * to an order is by token.
             */
            Order::where('user_token', $guestUser->user_token)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);

            UserAddress::where('user_token', $guestUser->user_token)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);

            MailHelper::guestAccountCreated($user, $password, $lang);

            return $user;

        } catch (\Exception $ex) {

            Log::error('Guest account creation failed after order', [
                'guest_user_id' => optional($guestUser)->id,
                'message' => $ex->getMessage()
            ]);

            return null;
        }
    }


    /**
     * Ask suppliers to quote on the procurement lines of an order.
     *
     * A product flagged `procurement` is one we sell and then source. Its
     * categories decide which suppliers hear about it, and each of those gets
     * one WhatsApp carrying a link to the lines that concern them.
     *
     * Was inline in action(), where it ran three queries per ordered line --
     * on the checkout path, for every order, including the great majority
     * with nothing procured in them at all. Now it is three queries for the
     * whole order and it leaves early when there is nothing to do.
     *
     * @param  array $orderedProducts the rows just written for this order
     */
    private function notifySuppliers($order, array $orderedProducts, TwilioService $twilio, $lang = null)
    {
        if (empty($orderedProducts)) {
            return;
        }

        $productIds = array_values(array_unique(array_column($orderedProducts, 'product_id')));

        // One query: which of these are actually sourced rather than stocked.
        $procuredIds = Product::whereIn('id', $productIds)
            ->where('procurement', 1)
            ->pluck('id')
            ->all();

        if (empty($procuredIds)) {
            return;
        }

        $procuredIds = array_flip($procuredIds);

        // One query: category -> the products of this order sitting in it.
        $categoriesByProduct = [];

        foreach (ProductCategory::whereIn('product_id', array_keys($procuredIds))
            ->get(['product_id', 'category_id']) as $row) {

            $categoriesByProduct[$row->product_id][] = $row->category_id;
        }

        $categoryIds = [];

        foreach ($categoriesByProduct as $ids) {
            foreach ($ids as $id) {
                $categoryIds[$id] = $id;
            }
        }

        if (empty($categoryIds)) {
            return;
        }

        // One query: category -> suppliers who cover it.
        $suppliersByCategory = [];

        foreach (SupplierCategory::whereIn('category_id', array_values($categoryIds))
            ->get(['supplier_id', 'category_id']) as $row) {

            $suppliersByCategory[$row->category_id][] = $row->supplier_id;
        }

        if (empty($suppliersByCategory)) {
            return;
        }

        $productMap = [];

        foreach ($orderedProducts as $orderedProduct) {
            $productId = $orderedProduct['product_id'];

            if (!isset($procuredIds[$productId]) || !isset($categoriesByProduct[$productId])) {
                continue;
            }

            /*
             * A product in two categories the same supplier covers must not
             * put the line in their list twice -- the supplier would be shown
             * the same item to price two or three times over.
             */
            $seen = [];

            foreach ($categoriesByProduct[$productId] as $categoryId) {
                foreach ($suppliersByCategory[$categoryId] ?? [] as $supplierId) {

                    if (isset($seen[$supplierId])) {
                        continue;
                    }

                    $seen[$supplierId] = true;

                    $productMap[$supplierId][] = [
                        'product_id'   => $productId,
                        'inventory_id' => $orderedProduct['inventory_id'],
                        'quantity'     => $orderedProduct['quantity'] ?? 0,
                    ];
                }
            }
        }

        if (empty($productMap)) {
            return;
        }

        $suppliers = Supplier::whereIn('id', array_keys($productMap))->get();

        /*
         * Titles for the message. The supplier used to be told only how many
         * items there were -- "Products: 2" -- so nothing in the WhatsApp
         * said what they were being asked to price, or whether it was worth
         * opening at all.
         */
        $titles = Product::whereIn('id', array_keys($procuredIds))
            ->pluck('title', 'id');

        foreach ($suppliers as $supplier) {

            $alreadySentForOrder = SupplierNotification::where([
                'supplier_id' => $supplier->id,
                'order_id'    => $order->id,
            ])->exists();

            if ($alreadySentForOrder) {
                continue;
            }

            $lines = $productMap[$supplier->id];

            $payload = [
                'order_id'    => $order->id,
                'supplier_id' => $supplier->id,
            ];

            $encrypted = Crypt::encryptString(json_encode($payload));

            /*
             * The storefront, not the API. They are the same host on this
             * install, but PROCUREMENT_LINK_BASE exists so a split deploy
             * does not silently mail out links into the API host.
             */
            $linkBase = rtrim(config('services.procurement.link_base') ?: config('app.url'), '/');

            $link = $linkBase . '/supplier/bid/' . urlencode($encrypted);

            $items = [];

            foreach ($lines as $line) {
                $title = $titles[$line['product_id']] ?? 'Item #' . $line['product_id'];

                $items[] = $title . ' x' . max(1, (int)$line['quantity']);
            }

            $message = "📦 New Fulfilment Request!\n\n"
                . "Order ID: {$order->order}\n"
                . 'Items: ' . count($lines) . "\n• " . implode("\n• ", $items) . "\n\n"
                . "Please send us your price here:\n{$link}";

            /*
             * The same facts as the message above, as template placeholders.
             *
             * WhatsApp will not let a business open a conversation with
             * free-form text, so a business-initiated message has to be an
             * approved Content Template with its values supplied separately
             * -- see TWILIO_WHATSAPP_CONTENT_SID. The order here IS the
             * template's {{1}}..{{4}}; changing it changes what suppliers
             * read. Commas rather than bullets because a placeholder cannot
             * contain a newline.
             */
            $variables = [
                $order->order,
                count($lines),
                implode(', ', $items),
                $link,
            ];

            $fullPhone = $supplier->country_code . $supplier->phone;

            /*
             * Reaching the supplier at all is what decides whether a
             * notification row is written. A row is taken as proof they were
             * asked -- the dedupe above skips anyone who has one -- so
             * recording a send that never arrived means they are never asked.
             *
             * Either channel succeeding is enough. Neither succeeding leaves
             * no row, so the next run retries this supplier without holding
             * up the others.
             */
            $channel = strtolower((string)config('services.procurement.channel', 'email'));

            $reached = false;

            if ($channel === 'email' || $channel === 'both') {
                $reached = MailHelper::supplierProcurementRequest(
                    $supplier,
                    $order,
                    $items,
                    $link,
                    BidController::LINK_TTL_DAYS,
                    $lang
                ) || $reached;
            }

            if ($channel === 'whatsapp' || $channel === 'both') {
                try {
                    $twilio->sendWhatsApp($fullPhone, $message, $variables);

                    $reached = true;

                } catch (\Throwable $ex) {
                    /*
                     * Twilio's wording describes the symptom; explain() turns
                     * its numeric code into the thing that has to change.
                     * Carried into the log because nobody reads this failure
                     * until a supplier says they never heard from us.
                     */
                    Log::warning('Supplier WhatsApp notification failed', [
                        'order_id' => $order->id,
                        'supplier_id' => $supplier->id,
                        'reason' => $ex->getMessage(),
                        'fix' => TwilioService::explain($ex->getCode()),
                    ]);
                }
            }

            if (!$reached) {
                continue;
            }

            foreach ($lines as $line) {
                /*
                 * firstOrCreate, not create: supplier_notifications now
                 * carries a unique index over this set of columns, and a
                 * product reachable through two of a supplier's categories
                 * would otherwise try to write the same row twice.
                 */
                SupplierNotification::firstOrCreate([
                    'supplier_id'  => $supplier->id,
                    'product_id'   => $line['product_id'],
                    'inventory_id' => $line['inventory_id'],
                    'order_id'     => $order->id,
                ]);
            }
        }
    }

}
