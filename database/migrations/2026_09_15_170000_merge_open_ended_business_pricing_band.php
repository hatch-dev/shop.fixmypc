<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fold the bolted-on top band back into the tier it was propping up.
 *
 * AddOpenEndedBusinessPricingBand closed the cliff above 30 by adding a fourth
 * band, 31-and-over, priced a little under the 21-30 tier. It stopped the
 * 31st unit costing more than the 30th, which was the urgent part, but it is
 * not the ladder the business runs: they price in three tiers and the top one
 * is meant to be "21 and over", one rate for every order of 21 or more.
 *
 * Four bands say something different to a customer reading the rate card -
 * that there is a further discount at 31 - and they are a row the admin has to
 * keep in step by hand every time the 21-30 rate moves. This migration makes
 * the ladder the business asked for:
 *
 *   before   5-10   11-20   21-30   31+
 *   after    5-10   11-20   21+
 *
 * The surviving band keeps the 21-30 tier's own figures, because that is the
 * rate the business quoted for "21 and over". The consequence is worth being
 * plain about: an order of 31 or more moves from the 31+ band's rate up to the
 * 21-30 rate. Nothing gets dearer than it was before the cliff was closed, and
 * nothing above 20 is charged retail - but 31-and-over is no longer the
 * cheapest row on the ladder, because the business does not have one.
 *
 * Carried as a migration for the same reason the first one was: dev, staging
 * and live should end up with the same ladder, and the figures are reviewable
 * next to the schema rather than living only in an admin's browser history.
 */
class MergeOpenEndedBusinessPricingBand extends Migration
{
    private const PRODUCT_ID = 99240288;

    /** The tier that becomes open-ended. */
    private const KEEP_MIN_QTY = 21;
    private const KEEP_MAX_QTY = 30;

    /** The band folded into it. */
    private const DROP_MIN_QTY = 31;

    public function up()
    {
        $keep = $this->band(self::KEEP_MIN_QTY);
        $drop = $this->band(self::DROP_MIN_QTY);

        // Nothing to do on an install that never had the bolted-on band, or
        // where an admin has already made this edit on the screen.
        if (!$keep || (int)$keep->max_qty !== self::KEEP_MAX_QTY) {
            return;
        }

        DB::transaction(function () use ($keep, $drop) {
            /*
             * Open the tier first. If the delete ran first and the update then
             * failed, the ladder would be left stopping at 30 again - which is
             * the exact defect this whole change exists to prevent, and the
             * one state worse than either the before or the after.
             */
            DB::table('business_product_pricing')
                ->where('id', $keep->id)
                // 0 is "no upper limit", not "a maximum of nothing"; see
                // BusinessProductPricing::coversQuantity.
                ->update(['max_qty' => 0, 'updated_at' => now()]);

            if ($drop && (int)$drop->max_qty === 0) {
                DB::table('business_product_pricing')->where('id', $drop->id)->delete();
            }
        });
    }

    /**
     * Put the fourth band back and bound the tier below it again.
     *
     * The rolled-back state is the one AddOpenEndedBusinessPricingBand left,
     * not the original cliff: rolling this back must not re-create a ladder
     * that charges more for buying more.
     */
    public function down()
    {
        $keep = $this->band(self::KEEP_MIN_QTY);

        if (!$keep || (int)$keep->max_qty !== 0) {
            return;
        }

        DB::transaction(function () use ($keep) {
            if (!$this->band(self::DROP_MIN_QTY)) {
                DB::table('business_product_pricing')->insert([
                    'product_id' => self::PRODUCT_ID,
                    'min_qty' => self::DROP_MIN_QTY,
                    'max_qty' => 0,
                    'wholesale_price' => 500.00,
                    'discount_type' => 'fixed',
                    'discount_value' => 90.00,
                    'final_price' => 410.00,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('business_product_pricing')
                ->where('id', $keep->id)
                ->update(['max_qty' => self::KEEP_MAX_QTY, 'updated_at' => now()]);
        });
    }

    private function band($minQty)
    {
        return DB::table('business_product_pricing')
            ->where('product_id', self::PRODUCT_ID)
            ->where('min_qty', $minQty)
            ->first();
    }
}
