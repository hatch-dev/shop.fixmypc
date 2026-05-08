<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\PointTransaction;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherOrder;
use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    public function getWallet(Request $request)
    {
        $user = $request->user();

        $wallet = Wallet::where('user_id', $user->id)->first();

        $pending = WalletTransaction::where('user_id', $user->id)
        ->where('status', 'pending')
        ->sum('amount');

        return response()->json([
            'balance' => $wallet?->balance ?? 0.00,
            'pending' => $pending ?? 0.00,
            'currency' => $wallet?->currency ?? 'EUR'
        ]);
    }

    public function confirmTopup(Request $request){
        try{
            $user = $request->user();
            $checkout_reference = $request->checkout_reference;
            $transaction_id = $request->transaction_id;
            $amount = $request->amount;

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0, 'currency' => 'EUR']
            );

            $newBalance = $wallet->balance + $amount;

            WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source' => 'topup',
                'reference_id' => $checkout_reference,
                'transaction_id' => $transaction_id,
                'status' => 'success',
            ]);

            $wallet->update([
                'balance' => $newBalance
            ]);

            return response()->json([
                'message' => 'Wallet credited successfully',
                'balance' => $wallet->balance
            ]);
        }catch(\Exception $e){
            return response()->json([
                'message' => 'Something went wrong while confirming the topup',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function transactions(Request $request)
    {
        $user = $request->user();

        $transactions = WalletTransaction::where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->paginate(10);

        return response()->json($transactions);
    }

    public function getPoints(Request $request)
    {
        $user = $request->user();

        $points = PointTransaction::where('user_id', $user->id)
            ->selectRaw("
                SUM(CASE WHEN type='credit' THEN points ELSE -points END) as total
            ")
            ->value('total') ?? 0;

        $value = $points * 0.01;

        return response()->json([
            'points' => (int)$points,
            'value' => number_format($value, 2, '.', '')
        ]);
    }

    public function purchaseGiftVoucher(Request $request)
    {
        $request->validate([
            'voucher_id' => 'required|exists:gift_voucher,id',
            'amount' => 'required|numeric|min:1',
            'quantity' => 'required|integer|min:1',
            'payment_method' => 'required|in:wallet,sumup',
        ]);

        $voucher = GiftVoucher::findOrFail($request->voucher_id);

        $total = $request->amount * $request->quantity;


        $user = Auth::user();

        $order = GiftVoucherOrder::create([
            'user_id' => $user->id,
            'voucher_id' => $voucher->id,
            'amount' => $request->amount / $request->quantity,
            'quantity' => $request->quantity,
            'total' => $total,
            'payment_method' => $request->payment_method,
            'status' => 'success'
        ]);

        Wallet::where('user_id', $user->id)
            ->decrement('balance', $total);

        WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'debit',
            'amount' => $total,
            'balance_after' => Wallet::where('user_id', $user->id)->value('balance'),
            'source' => 'gift_voucher',
            'reference_id' => $order->id,
            'status' => 'success',
        ]);


        return response()->json([
            'message' => 'Voucher purchased successfully'
        ]);
    }
}
