<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\Config;

/**
 * When a flash sale is running.
 *
 * flash_sales carries start_time, end_time and status. Twenty query sites
 * across the controllers and the models tested only two of those three: a
 * sale counted as live from the moment it was SAVED until its end_time, and
 * its start_time was never read by anything. A promotion scheduled for next
 * Friday discounted the product page, the listings, the basket and the order
 * the same afternoon it was entered, which is the one mistake a dated
 * promotion must not make.
 *
 * The window is decided here, once, so a twenty-first caller cannot get it
 * wrong the same way. It takes whatever is being built -- a JoinClause or a
 * query builder -- because the same three columns qualify a sale in both.
 */
class FlashSaleWindow
{
    /** Now, in the format the flash_sales timestamps are compared against. */
    public static function now()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * The same window, plus the product's own allowance.
     *
     * For the joins that bring flash_sale_products in -- which is every
     * site that prices a product. A row that has sold its allowance
     * simply stops joining, so Pricing falls through to the normal
     * ladder and the product goes back to its usual price instead of
     * becoming unbuyable.
     */
    public static function liveProduct($query, $table = 'flash_sales', $products = 'flash_sale_products')
    {
        /*
         * Expressed as one raw fragment rather than a nested closure:
         * a JoinClause cannot build a nested where (it hands its
         * connection where a Builder is expected and fatals), and an
         * unnested whereNull/orWhereColumn pair would OR itself against
         * the whole join condition instead of against each other.
         *
         * $products is an internal table name, never request input, and
         * is checked against the identifier pattern before it is used.
         */
        if (!preg_match('/^[A-Za-z0-9_]+$/', $products)) {
            throw new \InvalidArgumentException('Unexpected table name: ' . $products);
        }

        return self::live($query, $table)->whereRaw(
            "(`{$products}`.`quantity_limit` IS NULL"
            . " OR `{$products}`.`quantity_sold` < `{$products}`.`quantity_limit`)"
        );
    }


    /**
     * Narrow $query to the flash sales that are live right now.
     *
     * Both bounds are inclusive: a sale is live from its start_time and stays
     * live through its end_time, matching how the admin screen reads the two
     * fields it collects.
     */
    public static function live($query, $table = 'flash_sales')
    {
        $now = self::now();

        return $query
            ->where($table . '.start_time', '<=', $now)
            ->where($table . '.end_time', '>=', $now)
            ->where($table . '.status', Config::get('constants.status.PUBLIC'));
    }
}
