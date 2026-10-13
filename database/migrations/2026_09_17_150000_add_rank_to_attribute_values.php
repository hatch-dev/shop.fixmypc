<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of two attribute values is the BETTER one.
 *
 * The upgrade engine has to answer "is 256GB above 128GB?", and nothing in the
 * catalogue says so today: attribute_values carries a title and a colour and
 * nothing else, and the id order is insertion order, which is not the same
 * thing and drifts the moment a value is added late.
 *
 * Deliberately on attribute_values rather than on the upgrade configuration.
 * 128GB being below 256GB is a fact about the values themselves, true for
 * every product that uses them; per-product ranking would be the same fact
 * copied once per product, to be re-entered and eventually disagreed with.
 *
 * Nullable on purpose. Most values never need one: the engine parses a rank
 * out of the title ("128GB", "1TB", "2 Years") and falls back to the cheapest
 * variant carrying the value, so the common cases work with no admin input at
 * all. A rank is what an admin sets when those cannot work -- "Excellent" over
 * "Very Good", or "Pro" over "Standard".
 */
class AddRankToAttributeValues extends Migration
{
    public function up()
    {
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->unsignedInteger('rank')->nullable()->after('attribute_id');

            // The engine reads "every value of this attribute, best last".
            $table->index(['attribute_id', 'rank'], 'attribute_values_rank');
        });
    }

    public function down()
    {
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropIndex('attribute_values_rank');
            $table->dropColumn('rank');
        });
    }
}
