<?php

namespace App\Http\Controllers;

use App\Models\WalletTransaction;
use App\Models\PointTransaction;
use Illuminate\Http\Request;
use App\Models\Helper\Response;
use App\Models\Helper\Validation;

class WalletOverviewController extends Controller
{
    public function all(Request $request)
    {
        try {

            $userId = $request->user_id;

            $walletQuery = WalletTransaction::with('user')
                ->select('id','user_id','amount','type','source','created_at','balance_after');

            $pointQuery = PointTransaction::with('user')
                ->select('id','user_id','points','type','source','created_at');

            if ($userId) {
                $walletQuery->where('user_id', $userId);
                $pointQuery->where('user_id', $userId);
            }

            $wallet = $walletQuery->get()->map(function ($item) {
                return [
                    'id' => 'w_' . $item->id,
                    'user_id' => $item->user_id,
                    'user' => optional($item->user)->name ?? '-',
                    'email' => optional($item->user)->email ?? '-',
                    'amount' => $item->amount,
                    'points' => 0,
                    'type' => $item->type,
                    'source' => $item->source,
                    'created' => $item->created_at->format('d M Y'),
                ];
            });

            $points = $pointQuery->get()->map(function ($item) {
                return [
                    'id' => 'p_' . $item->id,
                    'user_id' => $item->user_id,
                    'user' => optional($item->user)->name ?? '-',
                    'email' => optional($item->user)->email ?? '-',
                    'amount' => 0,
                    'points' => $item->points,
                    'type' => $item->type,
                    'source' => $item->source,
                    'created' => $item->created_at->format('d M Y'),
                ];
            });

            $data = $wallet->merge($points)
                ->sortByDesc('created')
                ->values();

            $grouped = $data->groupBy('user_id')->map(function ($items) {
                $first = $items->first();

                $walletBalance = WalletTransaction::where('user_id', $first['user_id'])
                    ->orderBy('created_at', 'desc')
                    ->value('balance_after') ?? 0;

                $credit = PointTransaction::where('user_id', $first['user_id'])
                    ->where('type', 'credit')
                    ->sum('points');

                $debit = PointTransaction::where('user_id', $first['user_id'])
                    ->where('type', 'debit')
                    ->sum('points');

                return [
                    'user_id' => $first['user_id'],
                    'user' => $first['user'],
                    'email' => $first['email'],
                    'wallet_balance' => $walletBalance,
                    'cherry_points' => $credit - $debit,
                    'transactions' => $items->values()
                ];
            })->values();

            return response()->json(
                new Response($request->token, [
                    'data' => $grouped
                ])
            );

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
