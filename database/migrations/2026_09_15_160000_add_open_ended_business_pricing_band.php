<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close the pricing cliff above the top quantity band.
 *
 * The ladder on product 99240288 stopped at 21-30. A band that stops does not
 * mean "no further discount" — Pricing::businessPrice falls back to the retail
 * ladder the moment no band covers the quantity, so 31 units were charged
 * 505.00 each against 420.00 at 30. Buying one more unit cost the customer
 * 2,635.00 more, which is the one thing a trade account will always notice.
 *
 * The fix is a final band with no upper bound. max_qty 0 is how both the admin
 * screen and BusinessProductPricing::coversQuantity express "and over", so 31,
 * 100 and 1000 all price the same and no future quantity can fall off the end.
 *
 * Carried as a migration rather than typed into the admin screen so dev,
 * staging and live end up with the same ladder, and so the figures are
 * reviewable next to the rest of the schema.
 */
class AddOpenEndedBusinessPricingBand extends Migration
{
    /**
     * Product the ladder belongs to, and the band being added.
     *
     * wholesale_price matches the rows already on the ladder rather than the
     * product's current offered price: the discount hangs off the band's own
     * base, so taking a different one here would quietly reprice the tier.
     */
    private const PRODUCT_ID = 99240288;
    private const MIN_QTY = 31;
    private const WHOLESALE_PRICE = 500.00;
    private const DISCOUNT_VALUE = 90.00;
    private const FINAL_PRICE = 410.00;

    public function up()
    {
        if (!$this->ladderExists()) {
            return;
        }

        // Idempotent: re-running must not leave two bands covering 31, which
        // is the overlap the admin screen rejects.
        $existing = DB::table('business_product_pricing')
            ->where('product_id', self::PRODUCT_ID)
            ->where('min_qty', self::MIN_QTY)
            ->exists();

        if ($existing) {
            return;
        }

        DB::table('business_product_pricing')->insert([
            'product_id' => self::PRODUCT_ID,
            'min_qty' => self::MIN_QTY,
            // 0 is "no upper limit", not "a maximum of nothing".
            'max_qty' => 0,
            'wholesale_price' => self::WHOLESALE_PRICE,
            'discount_type' => 'fixed',
            'discount_value' => self::DISCOUNT_VALUE,
            'final_price' => self::FINAL_PRICE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        DB::table('business_product_pricing')
            ->where('product_id', self::PRODUCT_ID)
            ->where('min_qty', self::MIN_QTY)
            ->where('max_qty', 0)
            ->delete();
    }

    /**
     * Only extend a ladder that is actually there.
     *
     * An install without these bands has deliberately not configured business
     * pricing for the product; adding a lone 31-and-over band would give it a
     * ladder it never asked for.
     */
    private function ladderExists()
    {
        return DB::table('business_product_pricing')
            ->where('product_id', self::PRODUCT_ID)
            ->exists();
    }
}
