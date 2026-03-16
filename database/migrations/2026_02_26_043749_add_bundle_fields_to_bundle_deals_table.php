<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBundleFieldsToBundleDealsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('bundle_deals', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->enum('discount_type', ['fixed', 'percentage'])
                ->default('fixed')
                ->after('description');
            $table->decimal('discount_value', 10, 2)
                ->default(0)
                ->after('discount_type');
            });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('bundle_deals', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'discount_type',
                'discount_value'
            ]);
        });
    }
}
