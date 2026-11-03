<?php

namespace App\Models\Helper;

use App\Models\GiftVoucherCode;
use App\Models\GiftVoucherOrder;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Settling a gift card bought by card (SumUp).
 *
 * Codes used to be issued only when the customer's browser came back from
 * SumUp and called /purchase-gift-voucher/confirm. Paying and then closing
 * the tab left the purchase pending forever with nothing issued. Settling is
 * now one routine reached three ways - the browser's confirm, SumUp's own
 * notification (return_url), and a scheduled sweep of pending purchases - so
 * whichever arrives first issues the codes and the others find them issued.
 *
 * Whatever reaches it, SumUp is asked directly what happened to the checkout
 * (GatewayVerifier::verifySumup): a notification is a reason to look, never
 * evidence of payment. Codes are issued once, under a lock on the purchase.
 */
class GiftCardPurchases
{
    const PENDING = 'pending';
    const SUCCESS = 'success';
    const FAILED = 'failed';
    const CANCELLED = 'cancelled';
    const REFUNDED = 'refunded';
    const PARTLY_REFUNDED = 'partially_refunded';

    /** A card checkout nobody has paid in this long is given up on. */
    const ABANDON_AFTER_HOURS = 24;

    /**
     * Find out from SumUp where this purchase stands and act on it.
     *
     * Returns ['state' => paid|pending|failed|cancelled|unconfirmed, 'codes' => newly issued codes].
     * Codes are only ever returned to the call that issued them, so exactly
     * one caller emails them.
     */
    public static function settle(GiftVoucherOrder $order)
    {
        if ($order->status === self::SUCCESS || in_array($order->status, [self::REFUNDED, self::PARTLY_REFUNDED], true)) {
            return ['state' => 'paid', 'codes' => []];
        }
        if (in_array($order->status, [self::FAILED, self::CANCELLED], true)) {
            return ['state' => $order->status, 'codes' => []];
        }
        if ($order->payment_method !== 'sumup' || empty($order->reference)) {
            return ['state' => 'pending', 'codes' => []];
        }

        $verified = GatewayVerifier::verifySumup(Payment::first(), $order->reference, (float)$order->total, 'EUR');
        $isGift = strpos((string)($verified['checkout']['description'] ?? ''), 'Gift Voucher Purchase #') === 0;

        if ($verified['ok'] && $isGift) {
            $codes = DB::transaction(function () use ($order) {
                $locked = GiftVoucherOrder::where('id', $order->id)->lockForUpdate()->first();
                if ($locked->status !== self::PENDING) {
                    return [];
                }
                $locked->update(['status' => self::SUCCESS]);
                return GiftVoucherCode::issueFor($locked);
            });
            return ['state' => 'paid', 'codes' => $codes];
        }

        if ($verified['ok'] || !empty($verified['charged'])) {
            /*
             * SumUp took money, but not for this purchase as recorded (another
             * amount, or not a gift card checkout). Nothing is issued and the
             * purchase stays pending for a person to look at; it is not marked
             * failed, because money was taken.
             */
            Log::error('Gift card checkout charged but does not match the purchase', [
                'gift_order_id' => $order->id, 'reason' => $verified['reason'] ?? 'not a gift card checkout',
            ]);
            return ['state' => 'unconfirmed', 'codes' => []];
        }

        $status = self::checkoutStatus($verified['reason'] ?? '');
        if ($status === 'FAILED') {
            self::close($order, self::FAILED);
            return ['state' => self::FAILED, 'codes' => []];
        }
        if ($status === 'EXPIRED' || ($status !== 'PENDING' && $order->created_at
                && $order->created_at->lt(now()->subHours(self::ABANDON_AFTER_HOURS)))) {
            self::close($order, self::CANCELLED);
            return ['state' => self::CANCELLED, 'codes' => []];
        }
        return ['state' => 'pending', 'codes' => []];
    }

    /** Settle, then email whatever this call issued. */
    public static function settleAndDeliver(GiftVoucherOrder $order, $lang = null)
    {
        $result = self::settle($order);
        $result['sent'] = true;
        if (!empty($result['codes'])) {
            $result['sent'] = MailHelper::giftCardDelivery($order->fresh(), $result['codes'], $lang);
        }
        return $result;
    }

    /** "sumup checkout status FAILED after 1 lookup(s)" -> FAILED */
    private static function checkoutStatus($reason)
    {
        return preg_match('/checkout status ([A-Z_]+)/', (string)$reason, $m) ? $m[1] : null;
    }

    /** Pending -> failed/cancelled, only if nothing settled it meanwhile. */
    private static function close(GiftVoucherOrder $order, $status)
    {
        GiftVoucherOrder::where('id', $order->id)->where('status', self::PENDING)->update(['status' => $status]);
        Log::info('Gift card purchase closed unpaid', ['gift_order_id' => $order->id, 'status' => $status]);
    }

    /**
     * SumUp refunds a transaction, not a checkout, and a gift card purchase
     * only records its checkout. The checkout lists its transactions; the
     * successful one is what was charged.
     */
    public static function sumupTransactionId($checkoutId)
    {
        $payment = Payment::first();
        if (!$payment || empty($payment->sumup_api_key) || empty($checkoutId)) {
            return null;
        }
        $response = Http::timeout(GatewayVerifier::TIMEOUT_SECONDS)
            ->withToken($payment->sumup_api_key)
            ->get('https://api.sumup.com/v0.1/checkouts/' . urlencode($checkoutId));
        if (!$response->successful()) {
            return null;
        }
        foreach ((array)($response->json('transactions') ?? []) as $txn) {
            if (($txn['status'] ?? null) === 'SUCCESSFUL' && !empty($txn['id'])) {
                return $txn['id'];
            }
        }
        return null;
    }
}
