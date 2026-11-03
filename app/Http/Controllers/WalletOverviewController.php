<?php

namespace App\Http\Controllers;

use App\Models\WalletTransaction;
use App\Models\PointTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use App\Models\Helper\Response;
use App\Models\Helper\Validation;

class WalletOverviewController extends Controller
{
    /**
     * Wallet and Cherry Points activity, one entry per customer, a page at a
     * time.
     *
     * Each entry is the customer, their wallet balance, their Cherry Points
     * balance, and every wallet and points transaction of theirs, newest
     * first. The balances are worked out exactly as they always were: the
     * wallet is the balance_after of the customer's latest wallet
     * transaction, the points are credits less debits.
     *
     * This used to answer every customer at once with no page information,
     * so the list could not paginate and had no total to show; it sorted the
     * transactions by their formatted date string ("21 Feb 2026" before
     * "03 Mar 2026"), and it ran three queries per customer. The search box
     * (q) and a single customer (user_id) narrow the list.
     */
    public function all(Request $request)
    {
        try {

            $activity = DB::query()
                ->fromSub(
                    DB::table('wallet_transactions')->select('user_id', 'created_at')
                        ->unionAll(DB::table('point_transactions')->select('user_id', 'created_at')),
                    'activity'
                )
                // a left join: activity from an account since deleted still shows
                ->leftJoin('users', 'users.id', '=', 'activity.user_id')
                ->select('activity.user_id', DB::raw('MAX(activity.created_at) as last_activity'))
                ->groupBy('activity.user_id');

            if ($request->user_id) {
                $activity->where('activity.user_id', $request->user_id);
            }

            $search = trim((string)$request->q);

            if ($search !== '') {
                $activity->where(function ($query) use ($search) {
                    $query->where('users.name', 'like', '%' . $search . '%')
                        ->orWhere('users.email', 'like', '%' . $search . '%');
                });
            }

            $page = $activity
                ->orderByDesc('last_activity')
                ->orderByDesc('activity.user_id')
                ->paginate(Config::get('constants.api.PAGINATION'));

            $userIds = collect($page->items())->pluck('user_id')->all();

            $wallet = WalletTransaction::with('user:id,name,email')
                ->whereIn('user_id', $userIds)
                ->select('id', 'user_id', 'amount', 'type', 'source', 'created_at', 'balance_after')
                ->get()
                ->groupBy('user_id');

            $points = PointTransaction::with('user:id,name,email')
                ->whereIn('user_id', $userIds)
                ->select('id', 'user_id', 'points', 'type', 'source', 'created_at')
                ->get()
                ->groupBy('user_id');

            $entries = collect($page->items())->map(function ($row) use ($wallet, $points) {

                $walletRows = $wallet->get($row->user_id, collect());
                $pointRows = $points->get($row->user_id, collect());

                $user = optional($walletRows->first())->user ?? optional($pointRows->first())->user;

                $transactions = $walletRows->map(function ($item) {
                    return $this->transaction($item, 'w_', (float)$item->amount, 0);
                })->merge($pointRows->map(function ($item) {
                    return $this->transaction($item, 'p_', 0, (int)$item->points);
                }))->sortByDesc(function ($item) {
                    return $item['created_at'] . '|' . str_pad($item['seq'], 12, '0', STR_PAD_LEFT);
                })->values();

                // The latest wallet transaction's balance, as before.
                $latestWallet = $walletRows->sortByDesc(function ($item) {
                    return $item->created_at->format('Y-m-d H:i:s') . '|' . str_pad($item->id, 12, '0', STR_PAD_LEFT);
                })->first();

                $credit = $pointRows->where('type', 'credit')->sum('points');
                $debit = $pointRows->where('type', 'debit')->sum('points');

                return [
                    'user_id' => $row->user_id,
                    'user' => optional($user)->name ?? '-',
                    'email' => optional($user)->email ?? '-',
                    'wallet_balance' => $latestWallet ? (float)$latestWallet->balance_after : 0,
                    'cherry_points' => $credit - $debit,
                    'last_activity' => $transactions->isNotEmpty() ? $transactions->first()['created'] : null,
                    'transactions' => $transactions->map(function ($item) {
                        unset($item['seq']);
                        return $item;
                    })->values(),
                ];
            });

            $page->setCollection($entries);

            return response()->json(new Response($request->token, $page));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /** One wallet or points transaction, as a row of the list. */
    private function transaction($item, $prefix, $amount, $points)
    {
        return [
            'id' => $prefix . $item->id,
            'seq' => $item->id,
            'user_id' => $item->user_id,
            'amount' => $amount,
            'points' => $points,
            'type' => $item->type,
            'source' => $item->source,
            'created_at' => $item->created_at->format('Y-m-d H:i:s'),
            'created' => $item->created_at->format('d M Y'),
            'time' => $item->created_at->format('H:i'),
        ];
    }
}
