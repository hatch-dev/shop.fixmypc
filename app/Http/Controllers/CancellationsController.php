<?php

namespace App\Http\Controllers;

use App\Models\Cancellation;
use App\Models\Helper\OrderEmailDispatcher;
use App\Models\Helper\OrderEmailEvent;
use App\Models\Helper\Pricing;
use App\Models\Helper\Refunder;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\OrderStatusHistory;
use App\Models\RatingReview;
use App\Models\ReviewImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancellationsController extends \App\Models\Helper\ControllerHelper
{

    /**
     * Refund an order through the gateway that took the money.
     *
     * QA audit RFN-01. This method used to be, in its entirety,
     * "Cancellation::where('id',$id)->update(['refunded' => true])" — the admin
     * saw "Refunded", the customer saw nothing, and no money moved. It also
     * carried no permission check, was a GET, and could be replayed.
     */
    public function refund(Request $request, $id)
    {

        try {

            $lang = $request->header('language');

            if ($this->isVendor) {
                return Utils::isDataOwner(null, null);
            }

            if ($can = Utils::userCan($this->user, 'order.edit')) {
                return $can;
            }

            $cancellation = Cancellation::find($id);

            if (is_null($cancellation)) {
                return response()->json(Validation::nothing_found(201, null, 'form', $lang));
            }

            // Replaying the call must not refund twice.
            if ((int)$cancellation->refunded === 1) {
                return Validation::success(
                    $request,
                    __('lang.refunded', [], $lang),
                    $cancellation
                );
            }

            $order = Order::find($cancellation->order_id);

            if (is_null($order)) {
                return response()->json(Validation::nothing_found(201, null, 'form', $lang));
            }

            if ($order->payment_done != Config::get('constants.status.PUBLIC')) {
                return response()->json(Validation::error($request->token,
                    __('lang.unpaid_order', [], $lang)));
            }

            if ($order->order_method == Config::get('constants.paymentMethod.CASH_ON_DELIVERY')) {
                return response()->json(Validation::error($request->token,
                    __('lang.unable_refund', [], $lang)));
            }

            $hasNotRefundableProduct = OrderedProduct::join('products as p', function ($join) use ($cancellation) {
                $join->on('p.id', '=', 'ordered_products.product_id');
                $join->where('ordered_products.order_id', $cancellation->order_id);
                $join->where('p.refundable', '!=', Config::get('constants.status.PUBLIC'));
            })
                ->first();

            if (!is_null($hasNotRefundableProduct)) {
                return response()->json(Validation::error($request->token,
                    __('lang.not_refund', [], $lang)));
            }

            $amount = Pricing::round($order->total_amount);

            if ($amount <= 0) {
                return response()->json(Validation::error($request->token,
                    __('lang.invalid_amount', [], $lang)));
            }

            // Actually move the money.
            $result = Refunder::refund($order, $amount);

            if (!$result['ok']) {

                Cancellation::where('id', $id)->update([
                    'refund_error' => $result['reason']
                ]);

                Log::error('Refund failed', [
                    'order_id' => $order->id,
                    'reason' => $result['reason']
                ]);

                return response()->json(Validation::error($request->token,
                    __('lang.refund_failed', [], $lang)));
            }

            DB::transaction(function () use ($id, $order, $amount, $result) {

                Cancellation::where('id', $id)->update([
                    'refunded' => 1,
                    'refunded_amount' => $amount,
                    'refunded_at' => now(),
                    'refund_reference' => $result['reference'],
                    'refund_error' => null
                ]);

                /*
                 * A refund is a status change like any other: it releases the
                 * stock, reverses the loyalty points and frees the voucher
                 * redemption, none of which used to happen (QA audit ORD-01).
                 */
                /*
                 * The order stays CANCELLED (2026-10-21). This used to move it
                 * to REFUNDED, which made a refund look like an order status:
                 * the customer's Cancelled tab listed it as "Refunded" and the
                 * admin's Cancelled chip lost it. The refund is the
                 * cancellation row's own fact (refunded, refunded_amount,
                 * refund_reference); the history gets a note.
                 *
                 * Stock, points and vouchers were released when it was
                 * cancelled; restoreStockAndRewards() is a no-op then, and
                 * still releases a legacy order that only carries the flag.
                 */
                $order->restoreStockAndRewards();

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'from_status' => (int)$order->status,
                    'to_status' => (int)$order->status,
                    'changed_by' => optional($this->user)->id,
                    'changed_by_type' => 'admin',
                    'note' => 'Refunded ' . number_format($amount, 2) . ' (' . $result['reference'] . ')'
                ]);
            });

            /*
             * Tell the customer their money is on its way back.
             *
             * A refund was the one order event that produced no message of any
             * kind: the status moved to Refunded inside the transaction above
             * rather than through updateStatus(), so it never passed the status
             * notification, and nobody noticed because the admin screen showed
             * the refund as done. The customer's first news of it was their
             * bank statement.
             *
             * Raised after the transaction commits, not inside it. An email
             * queued inside a transaction that then rolls back is an email
             * about a refund that did not happen.
             */
            OrderEmailDispatcher::dispatch(
                $order,
                OrderEmailEvent::ORDER_REFUNDED,
                $lang,
                [
                    'status' => (int)Config::get('constants.orderStatus.REFUNDED'),
                    'refund_amount' => $amount,
                    'refund_reference' => $result['reference'],
                ]
            );

            $cancellation = Cancellation::find($id);

            return Validation::success(
                $request,
                __('lang.refunded', [], $lang),
                $cancellation
            );


        } catch (\Throwable $ex) {

            Log::error('Refund threw', ['message' => $ex->getMessage()]);

            return response()->json(Validation::error($request->token,
                __('lang.refund_failed', [], $lang)));
        }
    }

    public function find(Request $request, $orderId)
    {
        try {

            $lang = $request->header('language');
            $cancellation = Cancellation::where('order_id', $orderId)->get()->first();

            if (is_null($cancellation)) {
                return response()->json(Validation::nothing_found(201, null, 'form', $lang));
            }

            return response()->json(new Response($request->token, $cancellation));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function findCancellation(Request $request, $orderId)
    {
        try {

            $lang = $request->header('language');


            $query = Cancellation::where('order_id', $orderId);

            if ($request->user('user')) {

                $query = $query->where('user_id', $request->user('user')->id);

            } else if ($request->user_token) {

                $query = $query->where('user_token', $request->user_token);

            } else {

                return response()->json(Validation::errorLang($lang));
            }

            $cancellation = $query->first();

            if (is_null($cancellation)) {
                return response()->json(Validation::nothing_found(201, null, 'form', $lang));
            }

            return response()->json(new Response($request->token, $cancellation));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function cancelOrder(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::cancelled($request);
            if ($validate) {
                return response()->json($validate);
            }


            $query = Order::where('id', $request->order_id);

            if ($request->user('user')) {

                $query = $query->where('user_id', $request->user('user')->id);

            } else if ($request->user_token) {

                $query = $query->where('user_token', $request->user_token);

            } else {

                return response()->json(Validation::errorLang($lang));
            }

            $order = $query->first();

            if (is_null($order)) {
                return response()->json(Validation::nothingFoundLang($lang));
            }

            if ($order->status != Config::get('constants.orderStatus.PENDING')) {
                return response()->json(Validation::error($request->token,
                    __('lang.cancel_order', [], $lang)));
            }

            /*
             * QA audit ORD-01. This set 'cancelled' and stopped: the status
             * column stayed PENDING so the order kept showing as live in every
             * admin list, and the stock, points and voucher redemption were
             * never released.
             */
            $cancelledStatus = Config::get('constants.orderStatus.CANCELLED');

            $updated = DB::transaction(function () use ($request, $order, $cancelledStatus) {

                $order->restoreStockAndRewards();

                $result = Order::where('id', $request->order_id)->update([
                    'cancelled' => true,
                    'status' => $cancelledStatus
                ]);

                // After the update, not before: the tally reads the
                // orders table and would otherwise still see a sale.
                $order->refreshFlashAllowances();

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'from_status' => (int)$order->status,
                    'to_status' => $cancelledStatus,
                    'changed_by' => $order->user_id,
                    'changed_by_type' => 'customer',
                    'note' => $request->title
                ]);

                return $result;
            });

            if ($updated) {


                $existingQuery = Cancellation::where('order_id', $request->order_id);

                if ($request->user('user')) {

                    $existingQuery = $existingQuery->where('user_id', $request->user('user')->id);

                } else if ($request->user_token) {

                    $existingQuery = $existingQuery->where('user_token', $request->user_token);

                }

                $existing = $existingQuery->first();

                if (is_null($existing)) {

                    /*
                     * QA audit RFN-02. This was
                     * Cancellation::create($request->all()) with 'refunded' in
                     * the model's $fillable, so a customer could post
                     * "refunded": 1 and mark their own cancellation settled.
                     * An explicit whitelist instead.
                     */
                    $attributes = [
                        'order_id' => $request->order_id,
                        'title' => $request->title,
                        'message' => $request->message,
                    ];

                    if ($request->user('user')) {
                        $attributes['user_id'] = $request->user('user')->id;

                    } else if ($request->user_token) {
                        $attributes['user_token'] = $request->user_token;
                    }

                    Cancellation::create($attributes);
                } else {
                    Cancellation::where('id', $existing->id)->update([
                        'title' => $request->title,
                        'message' => $request->message,
                    ]);
                }

                $data = ReviewImage::leftJoin('rating_reviews', 'review_images.rating_review_id', '=', 'rating_reviews.id')
                    ->where('rating_reviews.order_id', $request->order_id);

                $data->delete();

                RatingReview::where('order_id', $request->order_id)->delete();

            }

            return Validation::success(
                $request,
                __('lang.cancelled', [], $lang),
                ['result' => ['cancelled' => true, 'order_id' => $request->order_id]]
            );

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
