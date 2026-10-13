<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddTradeToUsersAccountType extends Migration
{
    /**
     * account_type is an ENUM('personal','business'); the multi-step signup
     * adds a third option. Raw SQL because doctrine/dbal does not handle
     * enum changes.
     *
     * @return void
     */
    public function up()
    {
        DB::statement(
            "ALTER TABLE `users` MODIFY `account_type` "
            . "ENUM('personal','business','trade') NOT NULL DEFAULT 'personal'"
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Anything already on trade falls back to business, so the column
        // can narrow again without losing rows.
        DB::statement("UPDATE `users` SET `account_type` = 'business' WHERE `account_type` = 'trade'");

        DB::statement(
            "ALTER TABLE `users` MODIFY `account_type` "
            . "ENUM('personal','business') NOT NULL DEFAULT 'personal'"
        );
    }
}
