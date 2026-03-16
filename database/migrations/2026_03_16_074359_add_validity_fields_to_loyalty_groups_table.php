<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddValidityFieldsToLoyaltyGroupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('loyalty_groups', function (Blueprint $table) {
            $table->enum('validity', ['one_time', 'period', 'date_range'])
                  ->default('one_time')
                  ->after('discount_value');

            $table->integer('period_days')->nullable()->after('validity');
            $table->date('start_date')->nullable()->after('period_days');
            $table->date('end_date')->nullable()->after('start_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('loyalty_groups', function (Blueprint $table) {
            $table->dropColumn([
                'validity',
                'period_days',
                'start_date',
                'end_date'
            ]);
        });
    }
}
