<?php

namespace App\Models\Helper;

use App\Models\FlashSaleProduct;
use Illuminate\Support\Facades\DB;

/**
 * How much of a flash sale product's allowance has been taken.
 *
 * flash_sale_products.quantity_limit caps how many units may be sold at the
 * sale price. Null means no cap, which is every row that predates the column.
 *
 * When the allowance runs out the product does NOT stop selling -- it goes
 * back to its normal price. That is why the exhausted test lives in the
 * queries (FlashSaleWindow::liveProduct) rather than in a stock check: the
 * flash row simply stops joining, and Pricing's existing ladder takes over.
 * A customer is never blocked, they just do not get the sale price.
 *
 * quantity_sold is recomputed from the orders rather than incremented. An
 * increment has to be undone on every path that cancels, refunds or deletes
 * an order, and the first one anybody forgets leaves the tally permanently
 * wrong -- with no symptom until an allowance ends early.
 */
class FlashSaleAllowance
{
    /**
     * Units of this product sold inside this sale's window.
     *
     * Cancelled is the flag OR status 6, the same reading the order lists and
     * the dashboard use.
     */
    public static function soldFor($flashSaleProduct)
    {
        if (is_null($flashSaleProduct)) {
            return 0;
        }

        $sale = $flashSaleProduct->flash_sale;

        if (is_null($sale)) {
            return 0;
        }

        return (int)DB::table('ordered_products_inventories as opi')
            ->join('orders as o', 'o.id', '=', 'opi.order_id')
            ->where('opi.product_id', $flashSaleProduct->product_id)
            ->where('o.cancelled', '!=', 1)
            ->where('o.status', '!=', 6)
            ->where('o.created_at', '>=', $sale->start_time)
            ->where('o.created_at', '<=', $sale->end_time)
            ->sum('opi.quantity');
    }


    /**
     * Bring the tally up to date for every capped sale row holding one of
     * these products.
     *
     * Called after an order is placed and after one is cancelled or has its
     * status changed. Uncapped rows are skipped: nothing reads their tally,
     * and recomputing them would put a query on the checkout path for every
     * sale in the catalogue.
     */
    public static function refresh($productIds)
    {
        $ids = collect($productIds)->filter()->map(function ($id) { return (int)$id; })->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $rows = FlashSaleProduct::with('flash_sale')
            ->whereIn('product_id', $ids)
            ->whereNotNull('quantity_limit')
            ->get();

        foreach ($rows as $row) {
            FlashSaleProduct::where('id', $row->id)
                ->update(['quantity_sold' => self::soldFor($row)]);
        }
    }


    /** Units still available at the sale price; null when uncapped. */
    public static function remaining($flashSaleProduct)
    {
        if (is_null($flashSaleProduct) || is_null($flashSaleProduct->quantity_limit)) {
            return null;
        }

        return max(0, (int)$flashSaleProduct->quantity_limit - (int)$flashSaleProduct->quantity_sold);
    }


    /** Has this row used its allowance up? Uncapped rows never have. */
    public static function isExhausted($flashSaleProduct)
    {
        $remaining = self::remaining($flashSaleProduct);

        return !is_null($remaining) && $remaining < 1;
    }
}
