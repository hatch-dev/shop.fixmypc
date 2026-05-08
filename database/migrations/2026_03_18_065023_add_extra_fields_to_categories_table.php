<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExtraFieldsToCategoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('categories', function (Blueprint $table) {
            // TEXT FIELDS
            $table->text('short_description')->nullable()->after('slug');
            $table->text('description')->nullable()->after('short_description');

            // MEDIA
            $table->string('banner')->nullable()->after('image');
            $table->string('gallery')->nullable()->after('banner');

            // SEO
            $table->string('canonical_url')->nullable()->after('meta_keywords');

            // VISIBILITY
            $table->boolean('searchable')->default(true)->after('canonical_url');

            // SCHEDULING
            $table->timestamp('publish_at')->nullable()->after('searchable');

            // DISPLAY SETTINGS
            $table->integer('display_order')->default(1)->after('publish_at');
            $table->integer('products_per_page')->default(12)->after('display_order');

            // FEATURE FLAGS
            $table->boolean('show_in_nav')->default(false)->after('products_per_page');
            $table->boolean('enable_filters')->default(false)->after('show_in_nav');
            $table->boolean('show_homepage')->default(false)->after('enable_filters');
            $table->boolean('allow_promotions')->default(false)->after('show_homepage');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn([
                'short_description',
                'description',
                'banner',
                'gallery',
                'canonical_url',
                'searchable',
                'publish_at',
                'display_order',
                'products_per_page',
                'show_in_nav',
                'enable_filters',
                'show_homepage',
                'allow_promotions'
            ]);
        });
    }
}
