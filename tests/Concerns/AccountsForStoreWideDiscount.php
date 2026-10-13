<?php

namespace Tests\Concerns;

use App\Models\FlashDiscount;
use App\Models\Helper\Pricing;
use Illuminate\Support\Facades\DB;

/**
 * Order-total tests, in the presence of a store-wide flash discount.
 *
 * A test that rebuilds "what the customer should have been charged" from the
 * order's own lines -- goods, their VAT and carriage -- is measuring the right
 * thing, but it is not measuring all of it. A flash discount is not a line.
 *
 * It is also not automatic. The shopper asks for it on the basket and the
 * answer travels with the order as `apply_flash_discount`; an order that does
 * not ask is charged without it. So there are two ways for one of these sums
 * to be wrong, and both are worth catching: a discount missing from an order
 * that asked, and a discount applied to one that did not.
 *
 * That is not hypothetical. Extending one seeded row's end_time on 2026-09-18
 * broke seven tests across three classes with no code change involved.
 */
trait AccountsForStoreWideDiscount
{
    /**
     * What the live promotion takes off this many euros of goods.
     *
     * The base is the goods actually charged, ex VAT and ex carriage, because
     * that is what OrdersController hands Pricing::flashDiscount.
     *
     * $optedIn says whether the order asked for the discount. It defaults to
     * false, which is what an order that sends no `apply_flash_discount` gets
     * -- so a caller that has not thought about it is held to "no discount",
     * and an order that helps itself to one still fails.
     */
    protected function storeWideDiscountOn($goods, $optedIn = false)
    {
        if (!$optedIn) {
            return 0.0;
        }

        return Pricing::flashDiscount(FlashDiscount::current(), $goods);
    }

    /**
     * The same figure, read back from what the order stored.
     *
     * Checked against the rule as well as taken on trust: an order that
     * recorded a discount nobody asked for, or that dropped one that was,
     * is a defect, and this is the one place positioned to notice.
     */
    protected function storeWideDiscountTaken($orderId, $goods, $optedIn = false)
    {
        $recorded = (float)DB::table('ordered_flash_discount')
            ->where('order_id', $orderId)
            ->sum('discount');

        $this->assertEqualsWithDelta(
            $this->storeWideDiscountOn($goods, $optedIn), $recorded, 0.02,
            $optedIn
                ? 'the order did not receive the store-wide discount it asked for'
                : 'the order was given a store-wide discount it never asked for'
        );

        return $recorded;
    }
}
