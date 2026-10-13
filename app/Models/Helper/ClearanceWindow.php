<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\Config;

/**
 * When a clearance deal is running, and how a pricing record carries it.
 *
 * A clearance used to be applied on the way OUT of FrontendController: the
 * response payload was rewritten and nothing else in the system knew. Pricing
 * -- the one place prices are decided, and the only thing the basket, the
 * order, the vouchers, the bundle and every recommendation block ask -- had no
 * clearance step at all, so the store advertised one figure and charged
 * another. A product on a 20% deal showed EUR 8.00 on its page and came back
 * from the basket at EUR 10.00.
 *
 * It is now carried the same way a flash sale is: joined onto the pricing
 * record as two columns, and read off that record by Pricing. Adding the join
 * to a query is the whole of what a caller has to do.
 *
 * @see FlashSaleWindow the same shape, for the promotion this one sits under
 */
class ClearanceWindow
{
    /** Now, in the format the clearances timestamps are compared against. */
    public static function now()
    {
        return date('Y-m-d H:i:s');
    }


    /**
     * Narrow $query to the clearance deals that are live right now.
     *
     * A deal is live when it is published and either has no timer at all or
     * has not reached it yet -- the same two tests the storefront page makes.
     *
     * The timer is expressed as one raw fragment rather than a nested closure
     * because a JoinClause cannot build a nested where: it hands its
     * connection where a Builder is expected and fatals. An unnested
     * whereNull/orWhere pair would OR itself against the whole join condition
     * instead of against its own sibling, which silently widens the join to
     * every clearance ever made.
     *
     * $table is an internal table name, never request input, and is checked
     * against the identifier pattern before it reaches the fragment.
     */
    public static function live($query, $table = 'clearances')
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Unexpected table name: ' . $table);
        }

        return $query
            ->where($table . '.status', Config::get('constants.status.PUBLIC'))
            ->whereRaw(
                "(`{$table}`.`end_time` IS NULL OR `{$table}`.`end_time` >= ?)",
                [self::now()]
            );
    }


    /**
     * Attach the live clearance, if any, to a product query.
     *
     * A left join, so a product with no deal still comes back -- and the two
     * columns are simply null, which is what Pricing reads as "no clearance".
     */
    public static function join($query, $table = 'clearances')
    {
        return $query->leftJoin($table, function ($join) use ($table) {
            $join->on('products.id', '=', $table . '.product_id');
            self::live($join, $table);
        });
    }


    /**
     * The columns a pricing record carries the deal in.
     *
     * Aliased rather than selected raw: `discount_type` and `discount_value`
     * are also flash-sale column names, and a record that carried both under
     * one name would price a flash sale as a clearance.
     */
    public static function columns($table = 'clearances')
    {
        return [
            $table . '.discount_type as clearance_discount_type',
            $table . '.discount_value as clearance_discount_value',
        ];
    }


    /**
     * Put the same two columns on records that were not fetched through the
     * join -- a listing page, whose query is built elsewhere and selects its
     * own column list.
     *
     * One query for the whole page rather than one per tile. Records that
     * already carry a clearance are left alone, so this is safe to call twice.
     */
    public static function attach($items)
    {
        if (is_null($items)) {
            return;
        }

        $ids = collect($items)->pluck('id')->filter()->unique()->values()->all();

        if (empty($ids)) {
            return;
        }

        $deals = self::live(
            \App\Models\Clearance::whereIn('product_id', $ids)
        )
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('product_id');

        if ($deals->isEmpty()) {
            return;
        }

        foreach ($items as $item) {
            $group = $deals->get($item->id ?? null);

            if (!$group) {
                continue;
            }

            $deal = $group->first();

            $item->clearance_discount_type = $deal->discount_type;
            $item->clearance_discount_value = $deal->discount_value;
        }
    }
}
