<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\OrderExchanges;
use App\Models\Helper\OrderReturns;
use App\Models\Helper\PayLinks;
use App\Models\Helper\Response;
use App\Models\Helper\ReturnRefundCalculator;
use App\Models\Helper\ReturnRefused;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Returns in the admin panel (phase 1: staff open the case for the customer).
 *
 * Seeing returns needs order.view; opening, receiving, refunding or rejecting
 * one needs order.edit -- the same gate a cancellation refund has always had.
 * Vendors see neither: a return moves the whole order's money.
 *
 * The moves themselves live in App\Models\Helper\OrderReturns; this only
 * checks access, reads the request and shapes the answer.
 */
class OrderReturnsController extends ControllerHelper
{
    private function gate($permission)
    {
        if ($this->isVendor) {
            return Utils::isDataOwner(null, null);
        }

        return Utils::userCan($this->user, $permission);
    }

    private function refused(Request $request, $message)
    {
        return response()->json(Validation::error($request->token, $message));
    }

    public function all(Request $request)
    {
        try {
            if ($can = $this->gate('order.view')) {
                return $can;
            }

            $query = OrderReturn::query()
                ->join('orders', 'orders.id', '=', 'order_returns.order_id')
                ->leftJoin('users', 'users.id', '=', 'order_returns.user_id')
                ->select('order_returns.*', 'orders.order as order_number', 'orders.contact_name',
                    'orders.contact_email', 'users.name as user_name', 'users.email as user_email')
                ->withCount(['items as units' => function ($q) {
                    $q->select(DB::raw('COALESCE(SUM(quantity), 0)'));
                }])
                ->orderByDesc('order_returns.id');

            if ($request->status && array_key_exists($request->status, OrderReturn::STATUS_LABELS)) {
                $query->where('order_returns.status', $request->status);
            } elseif ($request->status === 'open') {
                $query->whereIn('order_returns.status', [OrderReturn::REQUESTED, OrderReturn::AWAITING, OrderReturn::RECEIVED,
                    OrderReturn::REFUNDING, OrderReturn::AWAITING_PAYMENT]);
            }

            if ($q = trim((string)$request->q)) {
                $query->where(function ($w) use ($q) {
                    $w->where('order_returns.rma_number', 'LIKE', "%{$q}%")
                        ->orWhere('orders.order', 'LIKE', "%{$q}%")
                        ->orWhere('users.name', 'LIKE', "%{$q}%")
                        ->orWhere('users.email', 'LIKE', "%{$q}%")
                        ->orWhere('orders.contact_email', 'LIKE', "%{$q}%");
                });
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $row) {
                $row->status_label = $row->statusLabel();
                $row->reason_label = $row->reasonLabel();
                $row->customer = $row->user_name ?: $row->contact_name;
                $row->customer_email = $row->user_email ?: $row->contact_email;
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return $this->refused($request, $ex->getMessage());
        }
    }

    /** Counts for the tabs on the list. */
    public function stats(Request $request)
    {
        if ($can = $this->gate('order.view')) {
            return $can;
        }

        $counts = OrderReturn::groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');

        return response()->json(new Response($request->token, [
            'requested' => (int)($counts[OrderReturn::REQUESTED] ?? 0),
            'awaiting_payment' => (int)($counts[OrderReturn::AWAITING_PAYMENT] ?? 0),
            'awaiting_return' => (int)($counts[OrderReturn::AWAITING] ?? 0),
            'received' => (int)($counts[OrderReturn::RECEIVED] ?? 0),
            'refunding' => (int)($counts[OrderReturn::REFUNDING] ?? 0),
            'refunded_total' => round((float)OrderReturn::whereIn('status', OrderReturn::MONEY_BACK)->sum('refund_amount'), 2),
        ]));
    }

    public function find(Request $request, $id)
    {
        if ($can = $this->gate('order.view')) {
            return $can;
        }

        $return = OrderReturn::find($id);

        if (!$return) {
            return response()->json(Validation::nothing_found(201, null, 'form', $request->header('language')));
        }

        return response()->json(new Response($request->token, OrderReturns::present($return)));
    }

    /**
     * An order as the return screens need it: can it be returned, each line
     * with what is left to return, and the cases already on it.
     */
    public function forOrder(Request $request, $orderId)
    {
        if ($can = $this->gate('order.view')) {
            return $can;
        }

        $order = Order::with('ordered_products')->find($orderId);

        if (!$order) {
            return response()->json(Validation::nothing_found(201, null, 'form', $request->header('language')));
        }

        $lines = ReturnRefundCalculator::lines($order);
        $details = OrderReturns::itemDetails(array_keys($lines));
        // What each line could be swapped for, priced for this customer.
        $variants = OrderExchanges::variantsFor($order);

        return response()->json(new Response($request->token, [
            'order' => [
                'id' => $order->id,
                'order' => $order->order,
                'status' => (int)$order->status,
                'total_amount' => (float)$order->total_amount,
                'cash_on_delivery' => (int)$order->order_method === (int)Config::get('constants.paymentMethod.CASH_ON_DELIVERY'),
                'contact' => $order->contactDetails(),
            ],
            'eligibility' => OrderReturns::eligibility($order),
            'money_left' => ReturnRefundCalculator::moneyLeft($order),
            'lines' => collect($lines)->map(function ($line) use ($details, $variants, $order) {
                return array_merge($details[$line['ordered_product_id']] ?? [], [
                    'variants' => $variants[$line['ordered_product_id']] ?? [],
                    // what one unit cost them, gross: the base an exchange difference is measured from
                    'unit_price_gross' => OrderExchanges::oldUnitPrice($order->ordered_products->firstWhere('id', $line['ordered_product_id'])),
                    'ordered_product_id' => $line['ordered_product_id'],
                    'quantity' => $line['quantity'],
                    'returned_quantity' => $line['returned_quantity'],
                    'returnable_quantity' => $line['returnable_quantity'],
                ]);
            })->values(),
            'returns' => OrderReturn::where('order_id', $order->id)->with('items')->orderByDesc('id')->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'rma_number' => $r->rma_number,
                        'type' => $r->type,
                        'replacement_order_id' => $r->replacement_order_id,
                        'status' => $r->status,
                        'status_label' => $r->statusLabel(),
                        'reason_label' => $r->reasonLabel(),
                        'units' => (int)$r->items->sum('quantity'),
                        'refund_amount' => $r->refund_amount,
                        'created_at' => (string)$r->created_at,
                    ];
                }),
            'reasons' => OrderReturn::REASONS,
            // For the "email language" picker: nothing records the customer's.
            'languages' => \App\Models\Language::orderBy('id')->get(['code', 'name']),
        ]));
    }

    /** What a refund would be for these items, before the case is opened. */
    public function quote(Request $request)
    {
        if ($can = $this->gate('order.view')) {
            return $can;
        }

        $order = Order::with('ordered_products')->find((int)$request->order_id);

        if (!$order) {
            return response()->json(Validation::nothing_found(201, null, 'form', $request->header('language')));
        }

        return response()->json(new Response($request->token,
            ReturnRefundCalculator::quote($order, $this->quantities($request), (bool)$request->refund_shipping)));
    }

    public function create(Request $request)
    {
        try {
            if ($can = $this->gate('order.edit')) {
                return $can;
            }

            $order = Order::find((int)$request->order_id);

            if (!$order) {
                return response()->json(Validation::nothing_found(201, null, 'form', $request->header('language')));
            }

            $return = OrderReturns::open(
                $order,
                $this->quantities($request),
                (string)$request->reason,
                $this->text($request->customer_note, 2000),
                $this->text($request->staff_note, 2000),
                optional($this->user)->id,
                $request->notify === null ? true : filter_var($request->notify, FILTER_VALIDATE_BOOLEAN),
                $request->email_lang,
                // {ordered_product_id: inventory_id} -- present, the case is an exchange
                $this->exchangeTargets($request)
            );

            return response()->json(new Response($request->token, OrderReturns::present($return)));

        } catch (ReturnRefused $e) {
            return $this->refused($request, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Opening a return failed', ['message' => $e->getMessage()]);
            return $this->refused($request, 'The return could not be opened.');
        }
    }

    /**
     * action = receive | refund | reject | note
     */
    public function action(Request $request, $id)
    {
        try {
            if ($can = $this->gate('order.edit')) {
                return $can;
            }

            $return = OrderReturn::with('items')->find($id);

            if (!$return) {
                return response()->json(Validation::nothing_found(201, null, 'form', $request->header('language')));
            }

            $adminId = optional($this->user)->id;
            $note = $this->text($request->note, 400);

            switch ($request->action) {
                case 'receive':
                    $restock = [];
                    foreach ((array)$request->restock as $itemId => $value) {
                        $restock[(int)$itemId] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }
                    $return = OrderReturns::receive($return, $restock, $note, $adminId);
                    break;

                case 'refund':
                    $amount = $request->amount;
                    if (!is_null($amount) && $amount !== '' && !is_numeric($amount)) {
                        return $this->refused($request, 'The refund amount must be a number.');
                    }
                    $return = OrderReturns::refund(
                        $return,
                        (string)$request->method,
                        ($amount === null || $amount === '') ? null : (float)$amount,
                        filter_var($request->refund_shipping, FILTER_VALIDATE_BOOLEAN),
                        $this->text($request->reference, 255),
                        $note,
                        $adminId
                    );
                    break;

                case 'reject':
                    $return = OrderReturns::reject($return, $note, $adminId,
                        $request->notify === null ? true : filter_var($request->notify, FILTER_VALIDATE_BOOLEAN));
                    break;

                case 'approve':
                    $return = OrderReturns::approve($return, $note, $adminId,
                        $request->notify === null ? true : filter_var($request->notify, FILTER_VALIDATE_BOOLEAN));
                    break;

                case 'send_pay_link':
                    PayLinks::create($return, $adminId);
                    $return = $return->fresh('items');
                    break;

                case 'cancel_pay_link':
                    $return = PayLinks::cancel($return, $adminId, $note);
                    break;

                case 'complete_exchange':
                    $amount = $request->amount;
                    if (!is_null($amount) && $amount !== '' && !is_numeric($amount)) {
                        return $this->refused($request, 'The amount must be a number.');
                    }
                    $return = OrderExchanges::complete(
                        $return,
                        ($amount === null || $amount === '') ? null : (float)$amount,
                        $this->text($request->reference, 255),
                        (string)$request->method,
                        $note,
                        $adminId
                    );
                    break;

                case 'note':
                    $return = OrderReturns::addNote($return, $note, $adminId);
                    break;

                default:
                    return $this->refused($request, 'Unknown action.');
            }

            return response()->json(new Response($request->token, OrderReturns::present($return)));

        } catch (ReturnRefused $e) {
            return $this->refused($request, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Return action failed', ['return_id' => $id, 'message' => $e->getMessage()]);
            return $this->refused($request, 'That could not be done.');
        }
    }

    /**
     * {ordered_product_id: qty} from items[id]=qty, or from "id:qty,id:qty"
     * (the GET preview), integers only.
     */
    private function quantities(Request $request)
    {
        $out = [];
        $items = $request->items;

        if (is_string($items)) {
            $pairs = [];
            foreach (explode(',', $items) as $pair) {
                $parts = explode(':', $pair);
                if (count($parts) === 2) {
                    $pairs[trim($parts[0])] = trim($parts[1]);
                }
            }
            $items = $pairs;
        }

        foreach ((array)$items as $id => $qty) {
            if (is_numeric($id) && is_numeric($qty) && (int)$qty > 0) {
                $out[(int)$id] = (int)$qty;
            }
        }

        return $out;
    }

    /** {ordered_product_id: inventory_id}, integers only. */
    private function exchangeTargets(Request $request)
    {
        $out = [];

        foreach ((array)$request->exchange as $id => $inventoryId) {
            if (is_numeric($id) && is_numeric($inventoryId) && (int)$inventoryId > 0) {
                $out[(int)$id] = (int)$inventoryId;
            }
        }

        return $out;
    }

    private function text($value, $max)
    {
        $value = trim(strip_tags((string)$value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
