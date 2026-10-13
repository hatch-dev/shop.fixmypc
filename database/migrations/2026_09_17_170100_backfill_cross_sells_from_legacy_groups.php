<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the live cross-sell configuration onto the promotion tables.
 *
 * The storefront's "goes well with" block has always been driven by
 * `upsells` / `upsell_products` / `products.upsell_id`: a GROUP with a name
 * and a status, a list of products, and a pointer from each primary product.
 * That is exactly a cross-sell promotion, so this is a translation:
 *
 *   upsells            -> cross_sells          (name, status carried over)
 *   upsell_products    -> cross_sell_products  (order as listed)
 *   products.upsell_id -> product_cross_sells  (one assignment per pointer)
 *
 * 6 groups, 10 products between them, 112 pointers on this install.
 *
 * Three things deliberately do NOT come across:
 *
 *  - `upsell_products.price`. A price banked against a recommendation goes
 *    stale the moment the catalogue, a band or a flash sale moves. Pricing
 *    decides it now, per customer, at read time.
 *  - a product whose row has since been deleted. The foreign key would refuse
 *    it, and a recommendation for a product that does not exist was never
 *    displayable anyway.
 *  - the group tables themselves. Nothing reads them after this. They are left
 *    standing rather than dropped so a rollback has something to go back to;
 *    retiring them is a separate, deliberate step.
 *
 * Three products point at a group that also lists them. Those assignments ARE
 * carried over: the storefront excludes the primary product from its own
 * promotion at read time (rule 4), so they still get the group's other
 * products, which is what they get today. New assignments of that shape are
 * refused by the controller.
 *
 * Idempotent: keyed on the legacy id, so running it twice adds nothing.
 */
class BackfillCrossSellsFromLegacyGroups extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('upsells') || !Schema::hasTable('upsell_products')) {
            return;
        }

        if (!Schema::hasColumn('products', 'upsell_id')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Only groups something actually points at, or that hold something.
        // An empty, unassigned group is a promotion nobody would recognise.
        $groups = DB::table('upsells')->orderBy('id')->get();

        foreach ($groups as $group) {
            $products = DB::table('upsell_products')
                ->join('products', 'products.id', '=', 'upsell_products.product_id')
                ->where('upsell_products.upsell_id', $group->id)
                ->orderBy('upsell_products.id')
                ->pluck('upsell_products.product_id')
                ->unique()
                ->values();

            $assigned = DB::table('products')
                ->where('upsell_id', $group->id)
                ->orderBy('id')
                ->pluck('id');

            if ($products->isEmpty() && $assigned->isEmpty()) {
                continue;
            }

            // Idempotence, off its own column rather than a marker in the
            // description -- admins read the description.
            if (DB::table('cross_sells')->where('legacy_upsell_id', $group->id)->exists()) {
                continue;
            }

            /*
             * The group's own switch carries over -- a group nobody turned on
             * does not quietly go live because the default changed -- EXCEPT
             * where nothing survived to offer.
             *
             * Two of these groups point at products that have since been
             * deleted. Carried over as Active they would be live promotions
             * that can never show anything, which is the exact state the
             * admin screen refuses to let anyone switch on by hand. They come
             * across as drafts instead: named, kept, and waiting for somebody
             * to put products in them.
             */
            $status = $products->isEmpty()
                ? 3
                : ((int)$group->status === 1 ? 1 : 2);

            $crossSellId = DB::table('cross_sells')->insertGetId([
                'name' => trim((string)$group->title) !== '' ? $group->title : ('Cross-sell #' . $group->id),
                'description' => 'Migrated from the previous cross-sell group.',
                'status' => $status,
                'max_products' => 4,
                'created_by' => $group->admin_id ?: null,
                'legacy_upsell_id' => $group->id,
                'created_at' => $group->created_at ?: $now,
                'updated_at' => $now,
            ]);

            $position = 0;
            $rows = [];

            foreach ($products as $productId) {
                $rows[] = [
                    'cross_sell_id' => $crossSellId,
                    'product_id' => (int)$productId,
                    'sort_order' => ++$position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows) {
                DB::table('cross_sell_products')->insertOrIgnore($rows);
            }

            $assignments = [];

            foreach ($assigned as $productId) {
                $assignments[] = [
                    'product_id' => (int)$productId,
                    'cross_sell_id' => $crossSellId,
                    'sort_order' => 1,
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($assignments, 500) as $chunk) {
                DB::table('product_cross_sells')->insertOrIgnore($chunk);
            }
        }
    }

    /** Removes only what this migration created, leaving admin-made ones. */
    public function down()
    {
        if (Schema::hasTable('cross_sells')) {
            DB::table('cross_sells')->whereNotNull('legacy_upsell_id')->delete();
        }
    }
}
