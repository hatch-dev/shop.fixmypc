<?php

namespace App\Http\Controllers;

use App\Models\Helper\OrderExchanges;
use App\Models\Helper\OrderReturns;
use App\Models\Helper\PayLinks;
use App\Models\Helper\Response;
use App\Models\Helper\ReturnRefundCalculator;
use App\Models\Helper\ReturnRefused;
use App\Models\Helper\Validation;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The customer's side of returns (phase 2): what they may send back, asking
 * for it, withdrawing the ask, and paying an exchange difference by link.
 *
 * Ownership is the order page's own rule (OrdersController::byUser): the
 * signed-in customer the order belongs to, or a guest holding its
 * user_token. The pay link needs neither -- its token is the credential.
 */
class CustomerReturnsController extends Controller
{
    /** The order, if the caller owns it; null otherwise. */
    private function ownedOrder(Request $request, $orderId)
    {
        $order = Order::with('ordered_products')->find((int)$orderId);

        if (!$order) {
            return null;
        }

        if ($user = $request->user('user')) {
            return (int)$order->user_id === (int)$user->id ? $order : null;
        }

        if ($request->user_token && $order->user_token && hash_equals((string)$order->user_token, (string)$request->user_token)) {
            return $order;
        }

        return null;
    }

    private function refused(Request $request, $message)
    {
        return response()->json(Validation::error($request->token, $message));
    }

    /**
     * What this order can send back: each line with how many units are left,
     * whether it can be refunded, and the variants it can be swapped for.
     */
    public function options(Request $request)
    {
        $lang = $request->header('language');
        $order = $this->ownedOrder($request, $request->order_id);

        if (!$order) {
            return $this->refused($request, __('lang.not_order', [], $lang));
        }

        $eligible = OrderReturns::eligibility($order, $lang);
        $lines = ReturnRefundCalculator::lines($order);
        $details = OrderReturns::itemDetails(array_keys($lines));
        $variants = OrderExchanges::variantsFor($order);
        $titles = collect(OrderReturns::forCustomerTitles($order, $lang));

        $reasons = [];
        foreach (array_keys(OrderReturn::REASONS) as $key) {
            $reasons[$key] = __('lang.return_reason_' . $key, [], $lang);
        }

        return response()->json(new Response($request->token, [
            // A customer is held to the window; staff are not.
            'can_request' => $eligible['ok'] && $eligible['within_window'] !== false,
            'reason' => !$eligible['ok'] ? $eligible['reason']
                : ($eligible['within_window'] === false ? __('lang.return_window_passed', ['days' => $eligible['window_days']], $lang) : null),
            'window_days' => $eligible['window_days'],
            'days_left' => is_null($eligible['days_since_delivery']) ? null
                : max(0, $eligible['window_days'] - $eligible['days_since_delivery']),
            'reasons' => $reasons,
            'lines' => collect($lines)->map(function ($line) use ($details, $variants, $order, $titles) {
                $id = $line['ordered_product_id'];

                return [
                    'ordered_product_id' => $id,
                    'title' => $titles[$id] ?? ($details[$id]['title'] ?? ''),
                    'variant' => $details[$id]['variant'] ?? '',
                    'image' => $details[$id]['image'] ?? null,
                    'refundable' => $details[$id]['refundable'] ?? true,
                    'quantity' => $line['quantity'],
                    'returnable_quantity' => $line['returnable_quantity'],
                    'unit_price' => OrderExchanges::oldUnitPrice($order->ordered_products->firstWhere('id', $id)),
                    // Only what can be had: an exchange to a sold-out variant would be refused later.
                    'variants' => collect($variants[$id] ?? [])->where('available', true)->values(),
                ];
            })->values(),
        ]));
    }

    public function request(Request $request)
    {
        $lang = $request->header('language');

        try {
            $order = $this->ownedOrder($request, $request->order_id);

            if (!$order) {
                return $this->refused($request, __('lang.not_order', [], $lang));
            }

            $quantities = [];
            foreach ((array)$request->items as $id => $qty) {
                if (is_numeric($id) && is_numeric($qty) && (int)$qty > 0) {
                    $quantities[(int)$id] = (int)$qty;
                }
            }

            $exchange = [];
            if ($request->type === OrderReturn::TYPE_EXCHANGE) {
                foreach ((array)$request->exchange as $id => $inventoryId) {
                    if (is_numeric($id) && is_numeric($inventoryId) && (int)$inventoryId > 0) {
                        $exchange[(int)$id] = (int)$inventoryId;
                    }
                }

                if (!$exchange) {
                    return $this->refused($request, __('lang.return_choose_variant', [], $lang));
                }
            }

            $note = trim(strip_tags((string)$request->note));

            $return = OrderReturns::request($order, $quantities, $exchange, (string)$request->reason,
                $note === '' ? null : mb_substr($note, 0, 2000), $lang);

            return response()->json(new Response($request->token, [
                'rma_number' => $return->rma_number,
                'returns' => OrderReturns::forCustomer($order->fresh(), $lang),
            ], 200, __('lang.return_requested_ok', [], $lang)));

        } catch (ReturnRefused $e) {
            return $this->refused($request, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Customer return request failed', ['order_id' => $request->order_id, 'message' => $e->getMessage()]);
            return $this->refused($request, __('lang.went_wrong', [], $lang));
        }
    }

    public function cancel(Request $request)
    {
        $lang = $request->header('language');

        try {
            $return = OrderReturn::find((int)$request->return_id);
            $order = $return ? $this->ownedOrder($request, $return->order_id) : null;

            if (!$order) {
                return $this->refused($request, __('lang.not_order', [], $lang));
            }

            OrderReturns::cancelByCustomer($return);

            return response()->json(new Response($request->token, [
                'returns' => OrderReturns::forCustomer($order->fresh(), $lang),
            ]));

        } catch (ReturnRefused $e) {
            return $this->refused($request, $e->getMessage());
        }
    }

    // ------------------------------------------------------------- pay link

    public function payLink(Request $request)
    {
        $token = (string)$request->input('link');
        $lang = $request->header('language');

        try {
            return response()->json(new Response($request->token, PayLinks::view(PayLinks::find($token, $lang), $lang)));
        } catch (ReturnRefused $e) {
            return $this->refused($request, $e->getMessage());
        }
    }

    public function payLinkStart(Request $request)
    {
        $token = (string)$request->input('link');
        $lang = $request->header('language');

        try {
            return response()->json(new Response($request->token, PayLinks::start(PayLinks::find($token, $lang), $lang)));
        } catch (ReturnRefused $e) {
            return $this->refused($request, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Pay link start failed', ['message' => $e->getMessage()]);
            return $this->refused($request, __('lang.pay_link_start_failed', [], $lang));
        }
    }

    public function payLinkConfirm(Request $request)
    {
        $token = (string)$request->input('link');
        $lang = $request->header('language');

        try {
            $link = PayLinks::confirm(PayLinks::find($token, $lang), $lang);

            return response()->json(new Response($request->token, PayLinks::view($link, $lang)));
        } catch (ReturnRefused $e) {
            return $this->refused($request, $e->getMessage());
        }
    }
}
