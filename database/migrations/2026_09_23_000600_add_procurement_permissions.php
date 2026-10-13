<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Awarding a line to a supplier, and signing off that they have been paid.
 *
 * Separate from supplier.* because they are different rights on different
 * things: supplier.edit changes a contact record, while these two commit and
 * settle money. Separate from each other for the same reason -- whoever
 * chooses the supplier is not automatically whoever confirms the bank
 * transfer went out.
 *
 * Reading quotes stays on supplier.view: it is the same information the
 * Requests screen already shows, arranged differently.
 */
class AddProcurementPermissions extends Migration
{
    private $permissions = [
        'procurement.decide',
        'procurement.pay',
    ];

    public function up()
    {
        $superAdmin = Role::where(['name' => 'superadmin', 'guard_name' => 'admin'])->first();

        foreach ($this->permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'admin'],
                ['group_name' => 'procurement']
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
