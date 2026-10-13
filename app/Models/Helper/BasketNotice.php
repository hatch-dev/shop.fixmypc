<?php

namespace App\Models\Helper;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tell the shopper when their basket changed without them (2026-10-13).
 *
 * Three things did that silently, which is how a shopper could open their
 * basket - or reach the payment page - and find it different with no idea
 * why:
 *
 *  - expired: CartHold emptied it after 20 idle minutes and released the
 *    units (the lines are kept here so they can be added back);
 *  - trimmed: CartHold could not hold every unit of a line and cut it back,
 *    or removed it;
 *  - merged:  signing in added the guest basket to the account.
 *
 * A notice waits, per basket (owner key u:<id> or g:<token>), until the
 * shopper dismisses it. The basket read (cart/by-user) carries the pending
 * ones, so whichever page loads the basket next can show them. Recording a
 * notice never gets in the way of the change it describes: every write here
 * swallows its own failure.
 */
class BasketNotice
{
    const EXPIRED = 'expired';
    const TRIMMED = 'trimmed';
    const MERGED = 'merged';

    /** Older notices than this are of no use to anyone and are not shown. */
    const KEEP_DAYS = 14;

    public static function add($userId, $userToken, $kind, array $payload)
    {
        try {
            $key = CartHold::ownerKey($userId, $userToken);

            if (!$key) {
                return;
            }

            DB::table('basket_notices')->insert([
                'owner_key' => $key,
                'kind' => $kind,
                'payload' => json_encode($payload),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Basket notice not recorded', ['kind' => $kind, 'message' => $e->getMessage()]);
        }
    }

    /** The pending notices for a basket, oldest first, as the storefront reads them. */
    public static function pending($userId, $userToken)
    {
        try {
            $key = CartHold::ownerKey($userId, $userToken);

            if (!$key) {
                return [];
            }

            $since = date('Y-m-d H:i:s', strtotime('-' . self::KEEP_DAYS . ' days'));

            return DB::table('basket_notices')
                ->where('owner_key', $key)
                ->where('created_at', '>=', $since)
                ->orderBy('id')
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => (int)$row->id,
                        'kind' => $row->kind,
                        'at' => $row->created_at,
                    ] + (json_decode($row->payload, true) ?: []);
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function find($userId, $userToken, $id)
    {
        $key = CartHold::ownerKey($userId, $userToken);

        $row = $key ? DB::table('basket_notices')->where('owner_key', $key)->where('id', (int)$id)->first() : null;

        return $row ? ['id' => (int)$row->id, 'kind' => $row->kind] + (json_decode($row->payload, true) ?: []) : null;
    }

    /** Dismiss some (or all) of a basket's notices. Only its own. */
    public static function dismiss($userId, $userToken, array $ids = null)
    {
        $key = CartHold::ownerKey($userId, $userToken);

        if (!$key) {
            return 0;
        }

        $query = DB::table('basket_notices')->where('owner_key', $key);

        if ($ids !== null) {
            $query->whereIn('id', array_map('intval', $ids));
        }

        return $query->delete();
    }

    /** A guest's notices follow them into their account at sign-in. */
    public static function moveToUser($userToken, $userId)
    {
        try {
            DB::table('basket_notices')
                ->where('owner_key', CartHold::ownerKey(null, $userToken))
                ->update(['owner_key' => CartHold::ownerKey($userId, null)]);
        } catch (\Throwable $e) {
            // The notice is lost, not the sign-in.
        }
    }

    /**
     * Lines as a notice keeps them: enough to name them, and to add them back.
     *
     * @param  iterable  $lines  carts rows (product_id, inventory_id, quantity)
     */
    public static function describe($lines)
    {
        $lines = collect($lines);
        $titles = Product::whereIn('id', $lines->pluck('product_id')->unique()->all())->pluck('title', 'id');

        return $lines->map(function ($line) use ($titles) {
            return [
                'product_id' => (int)$line->product_id,
                'inventory_id' => (int)$line->inventory_id,
                'quantity' => (int)$line->quantity,
                'title' => $titles[$line->product_id] ?? 'Item',
            ];
        })->values()->all();
    }
}
