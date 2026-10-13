<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product upgrade configuration.
 *
 * An upgrade offers a BETTER VARIANT OF THE SAME PRODUCT -- 128GB to 256GB --
 * and taking one replaces the customer's choice rather than adding a second
 * product. That is what makes it a different thing from the upsell campaigns
 * in `upsell_campaigns`, which point at other products entirely.
 *
 * The important design decision here is what is NOT stored.
 *
 * No pairings. "128GB -> 256GB" and "128GB -> 512GB" are not rows; they are
 * derived from the product's own variants at read time. An admin who adds a
 * 1TB variant gets the 1TB upgrade with no further configuration, and one who
 * deletes a variant cannot leave a rule pointing at nothing.
 *
 * No prices. The upgrade cost is the target variant's price minus the current
 * one's, both put through the ordinary pricing engine for whoever is asking --
 * so a business band, a flash sale, a clearance and a quantity break all apply
 * to an upgrade exactly as they apply to a purchase. A stored difference would
 * be wrong the first time either price moved.
 *
 * A row here is therefore configuration -- which attribute upgrades, for whom,
 * shown how -- and never the upgrades themselves.
 */
class CreateProductUpgradeTables extends Migration
{
    public function up()
    {
        Schema::create('product_upgrade_groups', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_id');

            // What the customer sees above the options: "Storage Upgrade".
            $table->string('name');

            /*
             * The attribute that upgrades. Everything else about the current
             * variant is held fixed, which is what keeps a storage upgrade
             * from quietly changing the colour.
             */
            $table->unsignedInteger('attribute_id');

            /*
             * automatic - every higher-ranked sibling qualifies.
             * manual    - only the values in product_upgrade_group_options do.
             *
             * Manual wins where it is configured, which is the whole point of
             * having it: an admin who wants to offer 512GB and not 256GB says
             * so, and the engine stops guessing.
             */
            $table->enum('mode', ['automatic', 'manual'])->default('automatic');

            // 1 public / 2 private, matching constants.status.
            $table->unsignedTinyInteger('status')->default(1);

            $table->unsignedInteger('display_order')->default(1);

            /*
             * What to do with an upgrade nobody can buy.
             *
             * hide    - leave it out entirely.
             * disable - show it greyed, so the customer can see the better
             *           variant exists and is simply gone for now.
             */
            $table->enum('out_of_stock', ['hide', 'disable'])->default('hide');

            // Matched against users.account_type; a guest matches 'all' only.
            $table->enum('customer_type', ['all', 'personal', 'business', 'trade'])
                ->default('all');

            /*
             * A cap on how many options are offered, best-ranked first. A
             * product with eight storage tiers should not put eight radio
             * buttons under the buy box.
             */
            $table->unsignedInteger('max_options')->nullable();

            $table->unsignedInteger('admin_id')->nullable();

            $table->timestamps();

            /*
             * One group per attribute per product. Two groups upgrading the
             * same attribute would offer the same variant twice, under two
             * headings, with two different sets of rules.
             */
            $table->unique(['product_id', 'attribute_id'], 'product_upgrade_groups_unique');

            // The storefront read: "this product's live groups, in order".
            $table->index(['product_id', 'status', 'display_order'], 'product_upgrade_groups_lookup');

            $table->foreign('product_id', 'fk_upgrade_groups_product')
                ->references('id')->on('products')
                ->onDelete('cascade');

            $table->foreign('attribute_id', 'fk_upgrade_groups_attribute')
                ->references('id')->on('attributes')
                ->onDelete('cascade');
        });

        /*
         * The manual override: exactly which values of the attribute to offer.
         *
         * Values rather than variants, because a value is what survives the
         * compatibility filter. "Offer 512GB" holds for the black one and the
         * blue one; "offer variant 162" would be the grey 512GB and nothing
         * else, and would break the moment a variant was rebuilt.
         */
        Schema::create('product_upgrade_group_options', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_upgrade_group_id');
            $table->unsignedInteger('attribute_value_id');

            $table->unsignedInteger('display_order')->default(1);

            $table->timestamps();

            $table->unique(
                ['product_upgrade_group_id', 'attribute_value_id'],
                'product_upgrade_options_unique'
            );

            $table->foreign('product_upgrade_group_id', 'fk_upgrade_options_group')
                ->references('id')->on('product_upgrade_groups')
                ->onDelete('cascade');

            $table->foreign('attribute_value_id', 'fk_upgrade_options_value')
                ->references('id')->on('attribute_values')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_upgrade_group_options');
        Schema::dropIfExists('product_upgrade_groups');
    }
}
