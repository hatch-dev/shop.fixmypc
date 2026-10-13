<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AddClearancePermissions extends Migration
{
    /**
     * Run the migrations.
     *
     * Registers the "clearance" permission group so that existing installs
     * (where RolePermissionSeeder only ran once) get the new gate. Permissions
     * are granted to the superadmin role and to vendors.
     *
     * @return void
     */
    public function up()
    {
        $groupName = 'clearance';
        $permissions = [
            'clearance.view',
            'clearance.create',
            'clearance.edit',
            'clearance.delete',
        ];

        $superAdmin = Role::where(['name' => 'superadmin', 'guard_name' => 'admin'])->first();
        $vendor = Role::where(['name' => 'vendor', 'guard_name' => 'admin'])->first();

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'admin'],
                ['group_name' => $groupName]
            );

            if ($superAdmin && !$superAdmin->hasPermissionTo($permission)) {
                $superAdmin->givePermissionTo($permission);
            }

            if ($vendor && !$vendor->hasPermissionTo($permission)) {
                $vendor->givePermissionTo($permission);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Permission::where('guard_name', 'admin')
            ->whereIn('name', [
                'clearance.view',
                'clearance.create',
                'clearance.edit',
                'clearance.delete',
            ])->delete();
    }
}
