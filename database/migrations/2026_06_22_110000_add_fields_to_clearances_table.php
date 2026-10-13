<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFieldsToClearancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the optional deal-content overrides shown on the public hero card:
     * description, compare-at/deal prices, stock counter + claimed %, and the
     * highlight bullet list. All are nullable; when blank the storefront falls
     * back to the linked product's own values.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clearances', function (Blueprint $table) {
            $table->text('description')->nullable()->after('subtitle');
            $table->decimal('actual_price', 10, 2)->nullable()->after('description');
            $table->decimal('deal_price', 10, 2)->nullable()->after('actual_price');
            $table->integer('units_left')->nullable()->after('deal_price');
            $table->integer('claimed_percent')->nullable()->after('units_left');
            $table->text('highlights')->nullable()->after('claimed_percent');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clearances', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'actual_price',
                'deal_price',
                'units_left',
                'claimed_percent',
                'highlights',
            ]);
        });
    }
}
