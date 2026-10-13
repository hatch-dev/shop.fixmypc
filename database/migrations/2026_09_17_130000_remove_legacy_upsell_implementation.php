<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clears the ground for the Upsell rebuild.
 *
 * Two half-features and one abandoned generalisation were all trying to be the
 * upsell at once:
 *
 *  - `updated_upsells` + its two child tables and `products.updated_upsell_id`:
 *    the original upgrade-options feature. One product in the whole catalogue
 *    still pointed at it.
 *  - `recommendation_campaigns` / `_sources` / `_targets` and
 *    `carts.recommendation_campaign_id`: a combined upsell + cross-sell
 *    campaign layer. Superseded by the dedicated `upsell_*` tables.
 *
 * What is deliberately LEFT ALONE is the `upsells` / `upsell_products` /
 * `upsell_categories` / `upsell_subcategories` chain and `products.upsell_id`.
 * The name is a historical accident: the storefront reads that group as the
 * CROSS-SELL source -- the product page's "goes well with" block and the
 * basket's add-these-too prompt -- and 112 products are configured against it.
 * Offering an ADDITIONAL product is cross-sell, not upsell, so it retires with
 * cross-sell rather than here.
 *
 * down() puts the structures back so the migration is reversible. It does not
 * put the rows back; anything configured against them is re-created as an
 * upsell campaign.
 */
class RemoveLegacyUpsellImplementation extends Migration
{
    public function up()
    {
        /*
         * The basket reference first: the column carries a foreign key into
         * recommendation_campaigns, so the table cannot be dropped while it
         * stands.
         */
        if (Schema::hasColumn('carts', 'recommendation_campaign_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropForeign('fk_carts_rec_campaign');
                $table->dropColumn('recommendation_campaign_id');
            });
        }

        // Children before parents, so the foreign keys go quietly.
        Schema::dropIfExists('recommendation_targets');
        Schema::dropIfExists('recommendation_sources');
        Schema::dropIfExists('recommendation_campaigns');

        Schema::dropIfExists('updated_upsell_product_service_items');
        Schema::dropIfExists('updated_upsells_product_service');
        Schema::dropIfExists('updated_upsells');

        if (Schema::hasColumn('products', 'updated_upsell_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('updated_upsell_id');
            });
        }

        /*
         * `upsell` is a stray empty table from an earlier import -- no model,
         * no controller and no rows have ever referenced it. Dropped here
         * because leaving it is exactly the kind of thing that gets mistaken
         * for the real one later.
         */
        if (Schema::hasTable('upsell') && \DB::table('upsell')->count() === 0) {
            Schema::dropIfExists('upsell');
        }
    }

    public function down()
    {
        if (!Schema::hasColumn('products', 'updated_upsell_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('updated_upsell_id')->nullable();
            });
        }

        Schema::create('updated_upsells', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('updated_upsells_product_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('updated_upsells_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->string('image')->nullable();
            $table->text('description')->nullable();
            $table->decimal('service_price', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('updated_upsell_product_service_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('updated_upsell_product_service_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('inventory_id')->nullable();
            $table->timestamps();
        });

        /*
         * The campaign layer is not recreated. It was never released, nothing
         * outside it ever read its tables, and a reversal that resurrects a
         * superseded schema is a trap rather than a safety net.
         */
    }
}
