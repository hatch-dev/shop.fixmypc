<?php

namespace App\Models\Helper;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Paying part of an order from the wallet and the rest by another method.
 *
 * The order keeps its whole value in total_amount; wallet_amount is the part
 * the wallet paid, and the chosen method (card, bank transfer, cash, ...) is
 * charged amountDue() = total_amount - wallet_amount.
 *
 * The wallet part is debited when the order is placed, whatever the method,
 * as a WalletTransaction (source 'order', the same row a full wallet payment
 * writes, so Refunder can find it). It comes back:
 *   - release(): when an order that was never paid is rolled back, cancelled
 *     or removed (rollbackOrder, restoreStockAndRewards on an unpaid order);
 *   - Refunder: when a paid order is refunded - card first, then wallet.
 * Each return is a credit row, and what is still held is always
 * debits - credits for that order, so nothing can be returned twice.
 */
class WalletSplit
{
    /** Sources of credits that give an order's wallet part back. */
    const RETURN_SOURCES = ['refund', 'order_release'];


    /** What the order's own payment method is charged. */
    public static function amountDue($order)
    {
        return Pricing::round(max(0,
            (float)($order->total_amount ?? 0) - (float)($order->wallet_amount ?? 0)));
    }


    /**
     * Debit up to $payable from the user's wallet for this order and record
     * it on the order. Returns the amount taken (0 when there is nothing to
     * take). Never takes the whole order: that is a full wallet payment,
     * order_method WALLET, and has its own path.
     */
    public static function apply($order, $userId, $payable)
    {
        $payable = Pricing::round($payable);

        if (!$userId || $payable <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($order, $userId, $payable) {

            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            $balance = $wallet ? Pricing::round($wallet->balance) : 0;

            // Leave at least one cent for the chosen method to charge.
            $take = Pricing::round(min($balance, $payable - 0.01));

            if ($take <= 0) {
                return 0;
            }

            $newBalance = Pricing::round($balance - $take);
            $wallet->update(['balance' => $newBalance]);

            WalletTransaction::create([
                'user_id'       => $userId,
                'type'          => 'debit',
                'amount'        => $take,
                'balance_after' => $newBalance,
                'source'        => 'order',
                'reference_id'  => $order->id,
                'status'        => 'success',
            ]);

            Order::where('id', $order->id)->update(['wallet_amount' => $take]);
            $order->wallet_amount = $take;

            return $take;
        });
    }


    /** The wallet money this order still holds: debits less anything returned. */
    public static function held($order)
    {
        if (!$order || !$order->user_id) {
            return 0;
        }

        $debited = (float)WalletTransaction::where('user_id', $order->user_id)
            ->where('reference_id', $order->id)
            ->where('source', 'order')->where('type', 'debit')
            ->sum('amount');

        $returned = (float)WalletTransaction::where('user_id', $order->user_id)
            ->where('reference_id', $order->id)
            ->whereIn('source', self::RETURN_SOURCES)->where('type', 'credit')
            ->sum('amount');

        return Pricing::round(max(0, $debited - $returned));
    }


    /**
     * Give back what an unpaid split order still holds. Only for a split
     * order (wallet_amount > 0) that was never paid: a paid order's money
     * moves through Refunder, and a full wallet payment is always paid.
     */
    public static function release($order)
    {
        if (!$order || (float)($order->wallet_amount ?? 0) <= 0 || (int)$order->payment_done === 1) {
            return 0;
        }

        return self::credit($order, self::held($order), 'order_release');
    }


    /** Put up to $amount of this order's wallet part back in the wallet. */
    public static function credit($order, $amount, $source)
    {
        return DB::transaction(function () use ($order, $amount, $source) {

            $wallet = Wallet::where('user_id', $order->user_id)->lockForUpdate()->first();

            // Re-read under the lock, so two callers cannot both return it.
            $amount = Pricing::round(min((float)$amount, self::held($order)));

            if (!$wallet || $amount <= 0) {
                return 0;
            }

            $newBalance = Pricing::round((float)$wallet->balance + $amount);
            $wallet->update(['balance' => $newBalance]);

            WalletTransaction::create([
                'user_id'       => $order->user_id,
                'type'          => 'credit',
                'amount'        => $amount,
                'balance_after' => $newBalance,
                'source'        => $source,
                'reference_id'  => $order->id,
                'status'        => 'success',
            ]);

            return $amount;
        });
    }
}
