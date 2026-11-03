<?php

namespace App\Models\Helper;

use App\Models\GiftCardEvent;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherCode;
use App\Models\GiftVoucherOrder;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What staff can do to gift cards beyond resend and void: issue one, change a
 * card's balance or expiry, and refund a purchase. Every change is recorded
 * in gift_card_events with the amount, the reason, the admin and the time.
 *
 * Each returns [ok, message, data]. Messages are for staff (English, like the
 * rest of the gift card admin).
 *
 * A card's value is the code's amount until it is redeemed, then its
 * voucher's gift_value; its balance is that value less what standing orders
 * took (Voucher::giftBalance). Neither may go below zero, and no change may
 * leave a card worth less than has already been spent from it.
 */
class GiftCardAdmin
{
    const MAX_VALUE = 5000;
    const MAX_ISSUE_QUANTITY = 20;

    private static function needsLog()
    {
        return GiftCardEvent::available()
            ? null
            : [false, 'This needs the gift card database update (migration 2026_11_03_000100_gift_card_events). Ask your developer to run it.', null];
    }

    /** [value, spent, balance] of a card right now. */
    public static function figures(GiftVoucherCode $code, Voucher $voucher = null)
    {
        $voucher = $voucher ?: ($code->voucher_id ? Voucher::find($code->voucher_id) : null);
        if ($code->status === GiftVoucherCode::REDEEMED && $voucher) {
            $value = round((float)$voucher->gift_value, 2);
            $balance = (float)$voucher->giftBalance();
            return [$value, round($value - $balance, 2), $balance];
        }
        $value = round((float)$code->amount, 2);
        return [$value, 0.0, $code->status === GiftVoucherCode::ACTIVE ? $value : 0.0];
    }

    // ------------------------------------------------------------------ issue

    /**
     * Staff issue cards without a purchase (goodwill, replacement, in-store).
     * Recorded as a gift_voucher_orders row paid by 'admin' with nothing
     * charged, so every card still belongs to a purchase and a design.
     */
    public static function issue(array $in, $adminId)
    {
        if ($refusal = self::needsLog()) {
            return $refusal;
        }
        $design = GiftVoucher::find($in['design_id'] ?? null);
        if (!$design) {
            return [false, 'Choose a gift card design.', null];
        }
        $amount = round((float)($in['amount'] ?? 0), 2);
        if ($amount < 1 || $amount > self::MAX_VALUE) {
            return [false, 'The value must be between 1 and ' . self::MAX_VALUE . '.', null];
        }
        $quantity = (int)($in['quantity'] ?? 1);
        if ($quantity < 1 || $quantity > self::MAX_ISSUE_QUANTITY) {
            return [false, 'Issue between 1 and ' . self::MAX_ISSUE_QUANTITY . ' cards at a time.', null];
        }
        $reason = trim((string)($in['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            return [false, 'Say why the card is being issued (255 characters at most).', null];
        }
        $expires = null;
        if (!empty($in['expires_at'])) {
            try {
                $expires = Carbon::parse($in['expires_at'])->endOfDay();
            } catch (\Throwable $e) {
                return [false, 'That expiry date is not a date.', null];
            }
            if ($expires->isPast()) {
                return [false, 'The expiry date must be in the future.', null];
            }
        }
        $customer = null;
        if (!empty($in['customer_email'])) {
            $customer = User::where('email', trim($in['customer_email']))->first();
            if (!$customer) {
                return [false, 'No customer account uses that email address.', null];
            }
        }
        $recipientEmail = trim((string)($in['recipient_email'] ?? '')) ?: null;
        if ($recipientEmail && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [false, 'The recipient email address is not valid.', null];
        }

        [$order, $codes] = DB::transaction(function () use ($design, $amount, $quantity, $customer, $recipientEmail, $in, $adminId, $reason, $expires) {
            $order = GiftVoucherOrder::create([
                'user_id'         => optional($customer)->id,
                'voucher_id'      => $design->id,
                'amount'          => $amount,
                'quantity'        => $quantity,
                // nothing was paid for a card staff issue
                'total'           => 0,
                'payment_method'  => 'admin',
                'reference'       => 'admin:' . (int)$adminId,
                'status'          => GiftCardPurchases::SUCCESS,
                'recipient_email' => $recipientEmail,
                'recipient_name'  => $recipientEmail ? (trim((string)($in['recipient_name'] ?? '')) ?: null) : null,
                'sender_name'     => $recipientEmail ? (trim((string)($in['sender_name'] ?? '')) ?: null) : null,
                'message'         => $recipientEmail ? (trim((string)($in['message'] ?? '')) ?: null) : null,
            ]);
            $codes = GiftVoucherCode::issueFor($order);
            foreach ($codes as $code) {
                if ($expires) {
                    $code->update(['expires_at' => $expires]);
                }
                GiftCardEvent::record([
                    'gift_voucher_code_id'  => $code->id,
                    'gift_voucher_order_id' => $order->id,
                    'type'                  => GiftCardEvent::ISSUED,
                    'amount'                => $amount,
                    'new_value'             => number_format($amount, 2, '.', ''),
                    'reason'                => $reason,
                    'admin_id'              => $adminId,
                ]);
            }
            return [$order, $codes];
        });

        $sent = null;
        if (!empty($in['send_email']) && ($recipientEmail || $customer)) {
            $sent = MailHelper::giftCardDelivery($order->fresh(), array_map(function ($c) { return $c->fresh(); }, $codes));
        }

        $message = count($codes) === 1 ? 'Gift card ' . $codes[0]->code . ' issued.' : count($codes) . ' gift cards issued.';
        if ($sent === false) {
            $message .= ' The email could not be sent; the codes are listed below.';
        }
        return [true, $message, ['order_id' => $order->id, 'codes' => collect($codes)->pluck('code')->all()]];
    }

    // ---------------------------------------------------------------- balance

    /**
     * Add to or take from a card's value. A card not yet redeemed changes its
     * code amount; a redeemed one its voucher's gift_value (and the voucher's
     * face price, which is what it shows). Never below what is already spent.
     */
    public static function adjustBalance($codeId, $delta, $reason, $adminId)
    {
        if ($refusal = self::needsLog()) {
            return $refusal;
        }
        $delta = round((float)$delta, 2);
        $reason = trim((string)$reason);
        if ($delta == 0.0 || abs($delta) > self::MAX_VALUE) {
            return [false, 'Enter an amount to add (e.g. 10) or take off (e.g. -10), up to ' . self::MAX_VALUE . '.', null];
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            return [false, 'Say why the balance is being changed (255 characters at most).', null];
        }
        $code = GiftVoucherCode::find($codeId);
        if (!$code) {
            return [false, 'That gift card was not found.', null];
        }
        if ($code->status === GiftVoucherCode::VOID) {
            return [false, 'A voided card cannot be changed.', null];
        }
        if ($code->status === GiftVoucherCode::REDEEMED && !$code->voucher_id) {
            return [false, 'This card was moved to a wallet under the old rule and has no balance of its own.', null];
        }

        // A redeemed card can be mid-checkout: wait for it, as a checkout would.
        $work = function () use ($code, $delta, $reason, $adminId) {
            return DB::transaction(function () use ($code, $delta, $reason, $adminId) {
                $code = GiftVoucherCode::where('id', $code->id)->lockForUpdate()->first();
                $voucher = $code->voucher_id ? Voucher::where('id', $code->voucher_id)->lockForUpdate()->first() : null;
                [$value, $spent, $balance] = self::figures($code, $voucher);
                $newValue = round($value + $delta, 2);

                if ($newValue - $spent < 0) {
                    return [false, sprintf('That would leave the card below zero: %.2f has already been spent and %.2f is left.', $spent, $balance), null];
                }
                if ($code->status === GiftVoucherCode::ACTIVE && $newValue < 1) {
                    return [false, 'A card that has not been added to vouchers must stay worth at least 1. Void it instead.', null];
                }
                if ($newValue > self::MAX_VALUE) {
                    return [false, 'A card can be worth at most ' . self::MAX_VALUE . '.', null];
                }

                if ($code->status === GiftVoucherCode::REDEEMED) {
                    $voucher->update(['gift_value' => $newValue, 'price' => $newValue]);
                } else {
                    $code->update(['amount' => $newValue]);
                }
                GiftCardEvent::record([
                    'gift_voucher_code_id'  => $code->id,
                    'gift_voucher_order_id' => $code->gift_voucher_order_id,
                    'voucher_id'            => $code->voucher_id,
                    'type'                  => GiftCardEvent::BALANCE,
                    'amount'                => $delta,
                    'old_value'             => number_format($value, 2, '.', ''),
                    'new_value'             => number_format($newValue, 2, '.', ''),
                    'reason'                => $reason,
                    'admin_id'              => $adminId,
                ]);
                return [true, sprintf('Balance changed: the card is now worth %.2f, with %.2f left.', $newValue, $newValue - $spent), null];
            });
        };

        if ($code->voucher_id) {
            $result = GiftCardLock::with($code->voucher_id, $work);
            return $result ?: [false, 'The card is being used at checkout right now. Try again in a moment.', null];
        }
        return $work();
    }

    // ----------------------------------------------------------------- expiry

    public static function changeExpiry($codeId, $date, $reason, $adminId)
    {
        if ($refusal = self::needsLog()) {
            return $refusal;
        }
        $reason = trim((string)$reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            return [false, 'Say why the expiry is being changed (255 characters at most).', null];
        }
        try {
            $expires = Carbon::parse((string)$date)->endOfDay();
        } catch (\Throwable $e) {
            return [false, 'That is not a date.', null];
        }
        if ($expires->isPast()) {
            return [false, 'The new expiry date must be in the future.', null];
        }
        if ($expires->gt(now()->addYears(10))) {
            return [false, 'The expiry can be at most ten years away.', null];
        }

        return DB::transaction(function () use ($codeId, $expires, $reason, $adminId) {
            $code = GiftVoucherCode::where('id', $codeId)->lockForUpdate()->first();
            if (!$code) {
                return [false, 'That gift card was not found.', null];
            }
            if ($code->status === GiftVoucherCode::VOID) {
                return [false, 'A voided card cannot be changed.', null];
            }
            if ($code->status === GiftVoucherCode::REDEEMED && !$code->voucher_id) {
                return [false, 'This card was moved to a wallet under the old rule and has no expiry of its own.', null];
            }
            $old = $code->expires_at ? $code->expires_at->format('Y-m-d') : null;
            $code->update(['expires_at' => $expires]);
            if ($code->voucher_id) {
                Voucher::where('id', $code->voucher_id)->update(['end_time' => $expires]);
            }
            GiftCardEvent::record([
                'gift_voucher_code_id'  => $code->id,
                'gift_voucher_order_id' => $code->gift_voucher_order_id,
                'voucher_id'            => $code->voucher_id,
                'type'                  => GiftCardEvent::EXPIRY,
                'old_value'             => $old,
                'new_value'             => $expires->format('Y-m-d'),
                'reason'                => $reason,
                'admin_id'              => $adminId,
            ]);
            return [true, 'Expiry changed to ' . $expires->format('j M Y') . '.', null];
        });
    }

    // ----------------------------------------------------------------- refund

    /**
     * Refund a purchase: the cards in it that nobody has added to vouchers
     * yet, at the price paid for each, back the way they were paid - to the
     * wallet, or to the card through SumUp. Those cards are voided first, so
     * none can be redeemed while the money is on its way; if the payment
     * provider refuses, they are put back.
     *
     * A card already in someone's vouchers is theirs to spend and is not
     * refunded here: void or adjust it first if that is what is wanted.
     */
    public static function refundPurchase($orderId, $reason, $adminId)
    {
        if ($refusal = self::needsLog()) {
            return $refusal;
        }
        $reason = trim((string)$reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            return [false, 'Say why the purchase is being refunded (255 characters at most).', null];
        }

        // 1. Hold the cards: void every refundable one, under the purchase's lock.
        $held = DB::transaction(function () use ($orderId) {
            $order = GiftVoucherOrder::where('id', $orderId)->lockForUpdate()->first();
            if (!$order) {
                return [false, 'That purchase was not found.'];
            }
            if (!in_array($order->status, [GiftCardPurchases::SUCCESS, GiftCardPurchases::PARTLY_REFUNDED], true)) {
                return [false, 'Only a paid purchase can be refunded.'];
            }
            if (!in_array($order->payment_method, ['wallet', 'sumup'], true) || (float)$order->total <= 0) {
                return [false, 'Nothing was paid for this purchase, so there is nothing to refund. Void the card instead.'];
            }
            $codes = GiftVoucherCode::where('gift_voucher_order_id', $order->id)
                ->where('status', GiftVoucherCode::ACTIVE)->lockForUpdate()->get();
            if ($codes->isEmpty()) {
                return [false, 'Every card in this purchase has already been added to vouchers, voided or refunded.'];
            }
            $refunded = (float)GiftCardEvent::where('gift_voucher_order_id', $order->id)
                ->where('type', GiftCardEvent::REFUND)->sum('amount');
            $amount = round(min($codes->count() * (float)$order->amount, (float)$order->total - $refunded), 2);
            if ($amount <= 0) {
                return [false, 'This purchase has already been refunded in full.'];
            }
            GiftVoucherCode::whereIn('id', $codes->pluck('id'))
                ->update(['status' => GiftVoucherCode::VOID, 'void_reason' => 'Refund in progress']);
            return [true, $order, $codes, $amount];
        });
        if (!$held[0]) {
            return [false, $held[1], null];
        }
        [, $order, $codes, $amount] = $held;

        // 2. Send the money back.
        $paid = $order->payment_method === 'wallet'
            ? self::refundToWallet($order, $amount)
            : self::refundToCard($order, $amount);

        if (!$paid['ok']) {
            GiftVoucherCode::whereIn('id', $codes->pluck('id'))
                ->where('void_reason', 'Refund in progress')
                ->update(['status' => GiftVoucherCode::ACTIVE, 'void_reason' => null]);
            Log::warning('Gift card refund refused', ['gift_order_id' => $order->id, 'reason' => $paid['reason']]);
            return [false, 'The refund did not go through, so nothing was changed: ' . $paid['reason'], null];
        }

        // 3. Record it.
        DB::transaction(function () use ($order, $codes, $amount, $reason, $adminId, $paid) {
            $each = round($amount / max(1, $codes->count()), 2);
            $written = 0.0;
            foreach ($codes->values() as $i => $code) {
                $share = $i === $codes->count() - 1 ? round($amount - $written, 2) : $each;
                $written += $share;
                GiftVoucherCode::where('id', $code->id)->update(['void_reason' => 'Refunded: ' . mb_substr($reason, 0, 230)]);
                GiftCardEvent::record([
                    'gift_voucher_code_id'  => $code->id,
                    'gift_voucher_order_id' => $order->id,
                    'type'                  => GiftCardEvent::REFUND,
                    'amount'                => $share,
                    'new_value'             => mb_substr((string)$paid['reference'], 0, 64),
                    'reason'                => $reason,
                    'admin_id'              => $adminId,
                ]);
            }
            $left = GiftVoucherCode::where('gift_voucher_order_id', $order->id)
                ->whereIn('status', [GiftVoucherCode::ACTIVE, GiftVoucherCode::REDEEMED])->exists();
            GiftVoucherOrder::where('id', $order->id)->update([
                'status' => $left ? GiftCardPurchases::PARTLY_REFUNDED : GiftCardPurchases::REFUNDED,
            ]);
        });

        return [true, sprintf('Refunded %.2f %s.', $amount,
            $order->payment_method === 'wallet' ? 'to the customer\'s wallet' : 'to the card through SumUp'), ['amount' => $amount]];
    }

    private static function refundToWallet(GiftVoucherOrder $order, $amount)
    {
        if (!$order->user_id) {
            return ['ok' => false, 'reason' => 'the purchase has no customer account to refund to', 'reference' => null];
        }
        return DB::transaction(function () use ($order, $amount) {
            $wallet = Wallet::where('user_id', $order->user_id)->lockForUpdate()->first();
            if (!$wallet) {
                return ['ok' => false, 'reason' => 'the customer has no wallet', 'reference' => null];
            }
            $balance = round((float)$wallet->balance + $amount, 2);
            $wallet->update(['balance' => $balance]);
            $txn = WalletTransaction::create([
                'user_id'       => $order->user_id,
                'type'          => 'credit',
                'amount'        => $amount,
                'balance_after' => $balance,
                'source'        => 'gift_voucher_refund',
                'reference_id'  => $order->id,
                'status'        => 'success',
            ]);
            return ['ok' => true, 'reason' => null, 'reference' => 'wallet:' . $txn->id];
        });
    }

    private static function refundToCard(GiftVoucherOrder $order, $amount)
    {
        $transactionId = GiftCardPurchases::sumupTransactionId($order->reference);
        if (!$transactionId) {
            return ['ok' => false, 'reason' => 'SumUp did not report a successful transaction for this purchase', 'reference' => null];
        }
        return Refunder::sumupTransaction($transactionId, $amount);
    }
}
