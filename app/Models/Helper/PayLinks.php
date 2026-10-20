<?php

namespace App\Models\Helper;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnHistory;
use App\Models\PayLink;
use App\Models\Payment;
use App\Models\UpdatedInventory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pay-by-link for a dearer exchange (built 2026-10-19 at the user's request).
 *
 *   create   staff send it: the amount is fixed, the new units are held
 *            (taken from stock now, so the customer never pays for one that
 *            sold out), and the customer is emailed the link
 *   start    the customer opens it: a checkout is made at the shop's card
 *            gateway (SumUp or Stripe) for the link's amount -- never one the
 *            browser names
 *   confirm  the gateway is asked whether it was paid; if so the exchange
 *            completes by itself (OrderExchanges::complete)
 *   cancel   staff withdraw it: the held units go back on the shelf
 *
 * The token in the URL is the only credential, as with the track-order link:
 * 48 random characters, unique, and useless once paid or cancelled.
 */
class PayLinks
{
    const VALID_DAYS = 7;

    /** Stripe will not take a card payment under 0.50. */
    const MIN_AMOUNT = 0.50;

    public static function url(PayLink $link)
    {
        $base = rtrim((string)(config('env.url.CLIENT_BASE_URL') ?: config('app.url')), '/');

        return $base . '/pay/' . $link->token;
    }

    public static function urlForReturn(OrderReturn $return)
    {
        $link = PayLink::where('order_return_id', $return->id)->where('status', PayLink::PENDING)->latest('id')->first();

        return $link ? self::url($link) : null;
    }

    public static function openFor(OrderReturn $return)
    {
        return PayLink::where('order_return_id', $return->id)->where('status', PayLink::PENDING)->latest('id')->first();
    }

    /**
     * Send the difference on a received, dearer exchange as a pay link.
     */
    public static function create(OrderReturn $return, $adminId)
    {
        if (!$return->isExchange()) {
            throw new ReturnRefused('A pay link is for the difference on an exchange.');
        }

        $payment = Payment::first();
        $gateway = $payment ? $payment->cardGateway() : null;

        if (!$gateway) {
            throw new ReturnRefused('No card gateway is switched on in Payment Setting, so the customer cannot pay by card.');
        }

        $quote = OrderExchanges::quote($return);

        if ($quote['difference'] < self::MIN_AMOUNT) {
            throw new ReturnRefused('There is nothing (or too little) to pay by card. Complete the exchange instead.');
        }

        if (!$quote['in_stock']) {
            throw new ReturnRefused('A variant to swap to is out of stock. Restock it, or refund instead.');
        }

        $order = Order::find($return->order_id);

        $link = DB::transaction(function () use ($return, $quote, $order, $gateway, $adminId) {

            $claimed = OrderReturn::where('id', $return->id)->where('status', OrderReturn::RECEIVED)
                ->update(['status' => OrderReturn::AWAITING_PAYMENT, 'refund_error' => null]);

            if (!$claimed) {
                throw new ReturnRefused('The old item must be received first, and only one pay link can be open.');
            }

            // Hold the new units, so the customer never pays for one that sold out.
            foreach ($quote['items'] as $line) {
                $taken = UpdatedInventory::where('id', $line['exchange_inventory_id'])
                    ->where(function ($q) use ($line) {
                        $q->where('quantity', '>=', $line['quantity'])->orWhere('is_active', 1);
                    })
                    ->decrement('quantity', $line['quantity']);

                if (!$taken) {
                    throw new ReturnRefused('A variant to swap to sold out a moment ago.');
                }
            }

            $link = PayLink::create([
                'token' => Str::random(48),
                'order_return_id' => $return->id,
                'order_id' => $order->id,
                'amount' => $quote['difference'],
                'status' => PayLink::PENDING,
                'currency' => strtoupper($order->currency ?: 'EUR'),
                'gateway' => $gateway,
                'stock_reserved' => true,
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'created_by' => $adminId,
            ]);

            OrderReturnHistory::create([
                'order_return_id' => $return->id,
                'from_status' => OrderReturn::RECEIVED,
                'to_status' => OrderReturn::AWAITING_PAYMENT,
                'admin_id' => $adminId,
                'note' => 'Pay link sent for ' . number_format($link->amount, 2) . '; new units held until it is paid or cancelled',
            ]);

            return $link;
        });

        OrderEmailDispatcher::dispatch($return->order_id,
            OrderEmailEvent::forReturn(OrderEmailEvent::RETURN_PAYMENT, $return->id), $return->lang);

        return $link;
    }

    /** Withdraw an unpaid link: the held units go back, the case is received again. */
    public static function cancel(OrderReturn $return, $adminId, $note = null)
    {
        return DB::transaction(function () use ($return, $adminId, $note) {
            $link = PayLink::where('order_return_id', $return->id)->where('status', PayLink::PENDING)->lockForUpdate()->first();

            if (!$link) {
                throw new ReturnRefused('There is no open pay link on this exchange.');
            }

            // A payment the customer has already started may have gone through.
            if ($link->gateway_session && self::verify($link)['ok']) {
                throw new ReturnRefused('The customer has already paid this link. Refresh the page.');
            }

            $link->status = PayLink::CANCELLED;
            $link->cancelled_at = now();
            $link->save();

            if ($link->stock_reserved) {
                foreach ($return->items as $item) {
                    if ($item->exchange_inventory_id) {
                        UpdatedInventory::where('id', $item->exchange_inventory_id)->increment('quantity', $item->quantity);
                    }
                }
            }

            OrderReturn::where('id', $return->id)->where('status', OrderReturn::AWAITING_PAYMENT)
                ->update(['status' => OrderReturn::RECEIVED]);

            OrderReturnHistory::create([
                'order_return_id' => $return->id,
                'from_status' => OrderReturn::AWAITING_PAYMENT,
                'to_status' => OrderReturn::RECEIVED,
                'admin_id' => $adminId,
                'note' => trim('Pay link cancelled; held units back in stock. ' . $note),
            ]);

            return $return->fresh('items');
        });
    }

    /** A pending, unexpired link by its token, or a refusal the customer can read. */
    public static function find($token, $lang = null)
    {
        $link = is_string($token) && strlen($token) === 48 ? PayLink::where('token', $token)->first() : null;

        if (!$link) {
            throw new ReturnRefused(__('lang.pay_link_not_found', [], $lang));
        }

        return $link;
    }

    /** What the pay page shows. Nothing about the customer beyond the order number. */
    public static function view(PayLink $link, $lang = null)
    {
        $return = OrderReturn::with('items')->find($link->order_return_id);
        $order = Order::find($link->order_id);
        $payment = Payment::first();

        $items = [];
        if ($return) {
            foreach (OrderReturns::forCustomer($order, $lang) as $row) {
                if ($row['id'] === $return->id) {
                    $items = $row['items'];
                }
            }
        }

        return [
            'status' => $link->status === PayLink::PENDING && $link->isExpired() ? 'expired' : $link->status,
            'amount' => $link->amount,
            'currency' => $link->currency,
            'gateway' => $link->gateway === (int)Config::get('constants.paymentMethod.STRIPE') ? 'stripe' : 'sumup',
            'stripe_key' => $link->gateway === (int)Config::get('constants.paymentMethod.STRIPE') ? optional($payment)->stripe_key : null,
            'order_number' => optional($order)->order,
            'rma_number' => optional($return)->rma_number,
            'items' => $items,
            'expires_at' => $link->expires_at->toIso8601String(),
            'paid_at' => optional($link->paid_at)->toIso8601String(),
            'replacement_order' => $return && $return->replacement_order_id
                ? optional(Order::find($return->replacement_order_id))->order : null,
        ];
    }

    /**
     * Make (or reuse) the checkout at the gateway for exactly this link.
     *
     * @return array ['checkout_id' => SumUp id] or ['client_secret' => Stripe secret]
     */
    public static function start(PayLink $link, $lang = null)
    {
        self::assertPayable($link, $lang);
        $payment = Payment::first();
        $rma = optional(OrderReturn::find($link->order_return_id))->rma_number;

        if ($link->gateway === (int)Config::get('constants.paymentMethod.STRIPE')) {
            \Stripe\Stripe::setApiKey($payment->stripe_secret);

            if ($link->gateway_session) {
                $intent = \Stripe\PaymentIntent::retrieve($link->gateway_session);

                if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                    return ['client_secret' => $intent->client_secret];
                }
            }

            $intent = \Stripe\PaymentIntent::create([
                'amount' => Pricing::toMinorUnits($link->amount),
                'currency' => strtolower($link->currency),
                'payment_method_types' => ['card'],
                'description' => 'Exchange ' . $rma,
                'metadata' => ['purpose' => 'pay_link', 'pay_link_id' => $link->id, 'rma' => $rma],
            ], ['idempotency_key' => 'pay-link-' . $link->id . '-' . substr(sha1((string)$link->gateway_session), 0, 8)]);

            $link->gateway_session = $intent->id;
            $link->save();

            return ['client_secret' => $intent->client_secret];
        }

        // SumUp: a checkout can be paid once, so an unpaid one is reused.
        if ($link->gateway_session) {
            $existing = Http::timeout(30)->withToken($payment->sumup_api_key)
                ->get('https://api.sumup.com/v0.1/checkouts/' . urlencode($link->gateway_session));

            if ($existing->successful() && ($existing->json('status') === 'PENDING')) {
                return ['checkout_id' => $link->gateway_session];
            }
        }

        $response = Http::timeout(30)->withToken($payment->sumup_api_key)->post('https://api.sumup.com/v0.1/checkouts', [
            'checkout_reference' => 'PAYLINK-' . $link->id . '-' . Str::random(6),
            'amount' => (float)$link->amount,
            'currency' => $link->currency,
            'pay_to_email' => $payment->sumup_merchant_email,
            'merchant_code' => $payment->sumup_merchant_code,
            'description' => 'Exchange Payment #' . $rma,
        ]);

        if (!$response->successful() || !$response->json('id')) {
            Log::error('Pay link: SumUp checkout not created', ['link' => $link->id, 'body' => substr($response->body(), 0, 300)]);
            throw new ReturnRefused(__('lang.pay_link_start_failed', [], $lang));
        }

        $link->gateway_session = $response->json('id');
        $link->save();

        return ['checkout_id' => $link->gateway_session];
    }

    /**
     * Ask the gateway, and if it was paid, complete the exchange. Safe to call
     * again: a paid link answers paid.
     */
    public static function confirm(PayLink $link, $lang = null)
    {
        $link = DB::transaction(function () use ($link, $lang) {
            $link = PayLink::where('id', $link->id)->lockForUpdate()->first();

            if ($link->status === PayLink::PAID) {
                return $link;
            }

            if ($link->status !== PayLink::PENDING || !$link->gateway_session) {
                throw new ReturnRefused(__('lang.pay_link_not_paid', [], $lang));
            }

            // Not expiry: money already taken counts, however late it was confirmed.
            $result = self::verify($link);

            if (!$result['ok']) {
                Log::info('Pay link not paid yet', ['link' => $link->id, 'reason' => $result['reason']]);
                throw new ReturnRefused(__('lang.pay_link_not_paid', [], $lang));
            }

            $link->status = PayLink::PAID;
            $link->paid_at = now();
            $link->gateway_reference = $result['reference'];
            $link->save();

            return $link;
        });

        $return = OrderReturn::with('items')->find($link->order_return_id);

        if ($return && $return->status === OrderReturn::AWAITING_PAYMENT) {
            try {
                OrderExchanges::complete($return, $link->amount, $link->gateway_reference, null,
                    'Paid by link', null, ['pay_link' => $link]);
            } catch (\Throwable $e) {
                // Paid, so the customer is told so; staff finish it from the case.
                Log::critical('Pay link paid but the exchange did not complete', ['link' => $link->id, 'message' => $e->getMessage()]);
                OrderReturn::where('id', $return->id)->update([
                    'refund_error' => 'Paid by link (' . $link->gateway_reference . ') but the exchange did not complete: '
                        . substr($e->getMessage(), 0, 400),
                ]);
            }
        }

        return $link->fresh();
    }

    /** Was this link's checkout paid, for its exact amount and currency. */
    public static function verify(PayLink $link)
    {
        $payment = Payment::first();

        if ($link->gateway === (int)Config::get('constants.paymentMethod.STRIPE')) {
            try {
                \Stripe\Stripe::setApiKey($payment->stripe_secret);
                $intent = \Stripe\PaymentIntent::retrieve($link->gateway_session);

                if (($intent->metadata['pay_link_id'] ?? null) != $link->id) {
                    return ['ok' => false, 'reason' => 'intent belongs to something else', 'reference' => null];
                }
                if ($intent->status !== 'succeeded') {
                    return ['ok' => false, 'reason' => 'stripe status ' . $intent->status, 'reference' => null];
                }
                if ((int)$intent->amount_received !== Pricing::toMinorUnits($link->amount)
                    || strtolower($intent->currency) !== strtolower($link->currency)) {
                    return ['ok' => false, 'reason' => 'stripe amount or currency mismatch', 'reference' => null];
                }

                return ['ok' => true, 'reason' => null, 'reference' => $intent->id];

            } catch (\Throwable $e) {
                return ['ok' => false, 'reason' => 'stripe: ' . $e->getMessage(), 'reference' => null];
            }
        }

        $result = GatewayVerifier::verifySumup($payment, $link->gateway_session, $link->amount, $link->currency);

        // The checkout must be one this link made, not any paid checkout for the same amount.
        if ($result['ok'] && strpos((string)($result['checkout']['checkout_reference'] ?? ''), 'PAYLINK-' . $link->id . '-') !== 0) {
            return ['ok' => false, 'reason' => 'sumup checkout is not this link\'s', 'reference' => null];
        }

        return $result;
    }

    private static function assertPayable(PayLink $link, $lang)
    {
        if ($link->status === PayLink::PAID) {
            throw new ReturnRefused(__('lang.pay_link_paid', [], $lang));
        }
        if ($link->status !== PayLink::PENDING) {
            throw new ReturnRefused(__('lang.pay_link_cancelled', [], $lang));
        }
        if ($link->isExpired()) {
            throw new ReturnRefused(__('lang.pay_link_expired', [], $lang));
        }
    }
}
