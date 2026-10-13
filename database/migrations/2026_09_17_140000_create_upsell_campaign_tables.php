<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upsell campaigns: many source products, many upgrade options, one set of
 * rules.
 *
 * An upsell offers a BETTER ALTERNATIVE to what the customer has -- 128GB to
 * 256GB. Taking one replaces the line rather than adding to it, which is the
 * whole distinction from cross-sell and the reason this is a separate module
 * rather than a `type` column on a shared table: the two answer different
 * questions and will diverge.
 *
 * The campaign shape matters. "These three iPhones can each be upgraded to
 * these two models" is one row here and six in a pair table -- six rows that
 * drift apart the moment one of them is edited.
 *
 * Nothing about MONEY is stored. No price, no discount, no VAT. The catalogue,
 * the business bands, the flash sales and the clearances remain the single
 * source of truth for what anything costs; a figure banked here would go stale
 * the moment the catalogue moved, which is the defect `upsell_products.price`
 * had.
 */
class CreateUpsellCampaignTables extends Migration
{
    public function up()
    {
        Schema::create('upsell_campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();

            /*
             * 1 active / 2 inactive, matching constants.status.PUBLIC and
             * .PRIVATE. Every other admin list in this project reads status
             * that way, so the shared list page, the status chip and the
             * ordering all work without a translation layer.
             */
            $table->unsignedTinyInteger('status')->default(2);

            /*
             * Who the offer is for. Matched against users.account_type; a guest
             * matches 'all' and nothing else.
             */
            $table->enum('customer_type', ['all', 'personal', 'business', 'trade'])
                ->default('all');

            $table->decimal('minimum_cart_value', 10, 2)->nullable();

            $table->boolean('show_on_product_page')->default(true);
            $table->boolean('show_on_cart_page')->default(true);
            $table->boolean('show_on_checkout_page')->default(false);

            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();

            /*
             * Which campaign wins when two of them name the same source
             * product. Lower number first, the same convention as the option
             * priority below, so an admin only learns the rule once.
             */
            $table->unsignedInteger('priority')->default(10);

            // The admin who created it. Nullable because an admin may be
            // deleted long after the campaigns they set up.
            $table->unsignedInteger('created_by')->nullable();

            $table->timestamps();

            // "Live campaigns, best first" is the only read the storefront
            // will make, so it gets the index rather than one column each.
            $table->index(['status', 'priority'], 'upsell_campaigns_live');
            $table->index(['start_date', 'end_date'], 'upsell_campaigns_window');
        });

        /*
         * The products a campaign applies to: what the customer is looking at
         * or already has.
         *
         * inventory_id is how this fits the catalogue's existing variant model
         * instead of inventing a second one. Null means the whole product --
         * every variant of it. Set means one specific variant, which is what
         * makes "iPhone 15 Pro 128GB -> 256GB" expressible when both are
         * variants of a single product rather than two products.
         */
        Schema::create('upsell_sources', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('upsell_campaign_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('inventory_id')->nullable();

            $table->timestamps();

            /*
             * Not a UNIQUE index. MySQL treats every NULL as distinct, so a
             * unique key over a nullable column would let "the whole product"
             * be added twice and silently stop protecting anything. The
             * controller rejects duplicates, where it can also say which
             * product it is refusing and why.
             */
            $table->index(
                ['upsell_campaign_id', 'product_id'],
                'upsell_sources_campaign'
            );

            // The storefront read: "which campaigns cover this product".
            $table->index('product_id', 'upsell_sources_product');

            $table->foreign('upsell_campaign_id', 'fk_upsell_sources_campaign')
                ->references('id')->on('upsell_campaigns')
                ->onDelete('cascade');

            /*
             * A deleted product takes its rules with it. A row pointing at a
             * product that no longer exists can only be filtered out at read
             * time, so it is dead weight.
             */
            $table->foreign('product_id', 'fk_upsell_sources_product')
                ->references('id')->on('products')
                ->onDelete('cascade');
        });

        /* What the customer is offered instead. Same variant rules as above. */
        Schema::create('upsell_options', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('upsell_campaign_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('inventory_id')->nullable();

            // Lower shows first. Not unique: ties fall back to id, so the
            // order stays deterministic either way.
            $table->unsignedInteger('priority')->default(1);

            $table->timestamps();

            $table->index(
                ['upsell_campaign_id', 'priority'],
                'upsell_options_order'
            );

            $table->index('product_id', 'upsell_options_product');

            $table->foreign('upsell_campaign_id', 'fk_upsell_options_campaign')
                ->references('id')->on('upsell_campaigns')
                ->onDelete('cascade');

            $table->foreign('product_id', 'fk_upsell_options_product')
                ->references('id')->on('products')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('upsell_options');
        Schema::dropIfExists('upsell_sources');
        Schema::dropIfExists('upsell_campaigns');
    }
}
