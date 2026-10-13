<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products are no longer assigned a shipping rule (see Helper\Shipping).
 *
 * The column stays, with its values, as a record of what each product used to
 * carry - but nothing reads it, so it must not be required either: the
 * foreign key refused every new product saved without one (0 is no rule),
 * and it would stop a rule being deleted while old products still named it.
 * Dropped where it exists (the live database was SQL-imported, so its
 * constraints are not guaranteed to match the migrations), and the column
 * becomes nullable.
 */
class MakeProductsShippingRuleOptional extends Migration
{
    private function foreignKeys()
    {
        return collect(DB::select(
            "SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
               AND COLUMN_NAME = 'shipping_rule_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
        ))->pluck('name');
    }

    public function up()
    {
        foreach ($this->foreignKeys() as $name) {
            DB::statement("ALTER TABLE `products` DROP FOREIGN KEY `{$name}`");
        }

        if (Schema::hasColumn('products', 'shipping_rule_id')) {
            DB::statement('ALTER TABLE `products` MODIFY `shipping_rule_id` INT UNSIGNED NULL DEFAULT NULL');
        }
    }

    public function down()
    {
        // Not restored: products saved since have no rule to point at.
    }
}
