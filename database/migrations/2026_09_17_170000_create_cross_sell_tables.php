<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-sell as a PROMOTION: a named, reusable set of complementary products
 * that is then assigned to the products it goes with.
 *
 *   cross_sells            "Laptop Accessories"          (the promotion)
 *   cross_sell_products    bag, mouse, cable, stand      (what it offers)
 *   product_cross_sells    laptop -> Laptop Accessories  (where it shows)
 *
 * Three tables rather than one pair table, because the same four accessories
 * belong under thirty laptops. As pairs that is 120 rows that drift apart the
 * moment one of them is edited; as a promotion it is one row an admin can
 * name, switch off, and assign in one click. It is also the shape the legacy
 * `upsells` group already had, which is why the backfill that follows is a
 * translation rather than a reconstruction.
 *
 * Deliberately NOT a `type` on `upsell_campaigns`. An upsell REPLACES a line
 * and carries a schedule, an audience and a cart minimum; a cross-sell ADDS a
 * line and carries none of those. Forcing both through one table would give
 * every read a filter it does not care about, and that is exactly what
 * `recommendation_campaigns` tried before it was dropped.
 *
 * Nothing about MONEY is stored anywhere here. The catalogue, the bands, the
 * flash sales and the clearances remain the single source of truth for what
 * anything costs; `upsell_products.price` -- the legacy table this replaces --
 * is the one column NOT carried across, because a figure banked against a
 * recommendation goes stale the moment the catalogue moves.
 */
class CreateCrossSellTables extends Migration
{
    public function up()
    {
        Schema::create('cross_sells', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();

            /*
             * 1 active / 2 inactive, matching constants.status.PUBLIC and
             * .PRIVATE as every other promotion here does -- plus 3 draft,
             * which is what a promotion is until somebody says it is ready.
             * Only 1 ever reaches a shopper.
             *
             * 3 rather than 0 on purpose: the shared admin Dropdown never
             * emits for an option keyed '0'.
             */
            $table->unsignedTinyInteger('status')->default(3);

            /*
             * How many of its products a shopper is shown at once. Per
             * promotion, not per store: "Laptop Accessories" has four
             * headline items and "Cable Bundle" has two, and one number for
             * the whole shop serves neither. It caps DISPLAY, never
             * configuration -- the products past it are what covers a
             * sold-out one.
             */
            $table->unsignedTinyInteger('max_products')->default(4);

            // The admin who created it. Nullable because an admin may be
            // deleted long after the promotions they set up.
            $table->unsignedInteger('created_by')->nullable();

            /*
             * Which legacy `upsells` group this promotion was migrated from,
             * or null for one an admin made.
             *
             * It exists so the backfill can recognise its own work and stay
             * idempotent. It is a column rather than a marker buried in the
             * description because the description is shown to admins, and
             * "(legacy-upsell-group:12)" is bookkeeping they did not ask to
             * read.
             */
            $table->unsignedBigInteger('legacy_upsell_id')->nullable()->unique('cross_sells_legacy');

            $table->timestamps();

            // The storefront's only read on this table: "is it live".
            $table->index('status', 'cross_sells_status');
        });

        /* What a promotion offers. */
        Schema::create('cross_sell_products', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cross_sell_id');
            $table->unsignedBigInteger('product_id');

            // Lower shows first. Ties fall back to id, so a reorder never has
            // to renumber rows it did not move and the order stays stable.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            /*
             * Rule 12: the same product twice in one promotion. Refused by the
             * controller too, where it can say which product -- but two
             * admins saving at once only meet each other here.
             */
            $table->unique(
                ['cross_sell_id', 'product_id'],
                'cross_sell_products_pair'
            );

            $table->index(
                ['cross_sell_id', 'sort_order'],
                'cross_sell_products_order'
            );

            // "Which promotions offer this product" -- the admin's question
            // before removing an accessory from the catalogue.
            $table->index('product_id', 'cross_sell_products_product');

            $table->foreign('cross_sell_id', 'fk_cross_sell_products_cross_sell')
                ->references('id')->on('cross_sells')
                ->onDelete('cascade');

            /*
             * Rule 11: a deleted product leaves every promotion it was in,
             * and the promotion itself stands. Cascade rather than restrict,
             * because deleting a mouse must never be blocked by the
             * promotions that happen to offer it.
             */
            $table->foreign('product_id', 'fk_cross_sell_products_product')
                ->references('id')->on('products')
                ->onDelete('cascade');
        });

        /* Where a promotion shows: the PRIMARY products it is assigned to. */
        Schema::create('product_cross_sells', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('cross_sell_id');

            // Which promotion's products come first when a product has more
            // than one. Lower first, same rule as everywhere else.
            $table->unsignedInteger('sort_order')->default(0);

            /*
             * This ASSIGNMENT's own switch, which is not the promotion's. An
             * admin can stop "Laptop Accessories" showing under one laptop
             * without stopping it under the other twenty-nine.
             */
            $table->unsignedTinyInteger('status')->default(1);

            $table->timestamps();

            // Rule 12: the same promotion assigned to the same product twice.
            $table->unique(
                ['product_id', 'cross_sell_id'],
                'product_cross_sells_pair'
            );

            /*
             * The storefront's read: "the live assignments for this product,
             * in order". One composite index answers it outright.
             */
            $table->index(
                ['product_id', 'status', 'sort_order'],
                'product_cross_sells_live'
            );

            // "Where is this promotion assigned" -- the list page's count.
            $table->index('cross_sell_id', 'product_cross_sells_cross_sell');

            $table->foreign('product_id', 'fk_product_cross_sells_product')
                ->references('id')->on('products')
                ->onDelete('cascade');

            $table->foreign('cross_sell_id', 'fk_product_cross_sells_cross_sell')
                ->references('id')->on('cross_sells')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_cross_sells');
        Schema::dropIfExists('cross_sell_products');
        Schema::dropIfExists('cross_sells');
    }
}
