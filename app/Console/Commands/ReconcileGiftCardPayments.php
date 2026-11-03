<?php

namespace App\Console\Commands;

use App\Models\GiftVoucherOrder;
use App\Models\Helper\GiftCardPurchases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Settle gift card purchases paid by card that nobody settled.
 *
 * The net under SumUp's notification (WalletController::sumupGiftCardNotify)
 * and the customer's browser: a purchase still pending after a couple of
 * minutes is checked with SumUp directly (GiftCardPurchases::settle). Paid ->
 * codes issued and emailed, once; failed or expired at SumUp -> closed;
 * abandoned for a day -> cancelled. Nothing is ever issued without SumUp
 * saying it was paid for the purchase's total.
 */
class ReconcileGiftCardPayments extends Command
{
    protected $signature = 'gift-cards:reconcile-payments';

    protected $description = 'Check pending gift card card payments with SumUp and settle them';

    /** Enough for any realistic backlog without one slow run. */
    const BATCH = 50;

    public function handle()
    {
        $pending = GiftVoucherOrder::where('status', GiftCardPurchases::PENDING)
            ->where('payment_method', 'sumup')
            ->whereNotNull('reference')
            // the browser and the notification get the first two minutes
            ->where('created_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $counts = [];
        foreach ($pending as $order) {
            try {
                $state = GiftCardPurchases::settleAndDeliver($order)['state'];
            } catch (\Throwable $e) {
                Log::error('Gift card payment reconcile failed', ['gift_order_id' => $order->id, 'message' => $e->getMessage()]);
                $state = 'error';
            }
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        $this->info($pending->count() . ' pending purchase(s) checked' . ($counts ? ': ' . json_encode($counts) : '.'));
        return 0;
    }
}
