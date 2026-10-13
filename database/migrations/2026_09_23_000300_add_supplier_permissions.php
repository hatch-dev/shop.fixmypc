<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Procurement had only supplier.view and supplier.create, added by hand
 * rather than by any migration or seeder. The list page has always rendered
 * Edit and Delete buttons, and SupplierController has always honoured them,
 * so two of the four rights the screen offers existed nowhere and could not
 * be granted or withheld.
 *
 * Deliberately NOT given to vendor, unlike cross-sell. A supplier is a
 * shop-wide commercial relationship, not a per-vendor one: procurement
 * matches a supplier to an order line by category, across every vendor's
 * products, so one vendor editing the list changes who gets asked about
 * another vendor's orders.
 */
class AddSupplierPermissions extends Migration
{
    private $permissions = [
        'supplier.view',
        'supplier.create',
        'supplier.edit',
        'supplier.delete',
    ];

    public function up()
    {
        $superAdmin = Role::where(['name' => 'superadmin', 'guard_name' => 'admin'])->first();

        foreach ($this->permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'admin'],
                ['group_name' => 'supplier']
            );

            if ($superAdmin && !$superAdmin->hasPermissionTo($permission)) {
                $superAdmin->givePermissionTo($permission);
            }
        }
    }

    public function down()
    {
        Permission::where('guard_name', 'admin')
            ->whereIn('name', $this->permissions)
            ->delete();
    }
}
