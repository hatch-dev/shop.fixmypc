<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * RolePermissionSeeder returns early once a superadmin role exists, so a group
 * added to it never reaches an install that was seeded before -- and the live
 * site is one of those. Same treatment clearance (2026_06_22_100100) and
 * loyalty_group (2026_09_15_130000) got.
 */
class AddUpsellPermissions extends Migration
{
    private $permissions = [
        'upsell.view',
        'upsell.create',
        'upsell.edit',
        'upsell.delete',
    ];

    public function up()
    {
        $superAdmin = Role::where(['name' => 'superadmin', 'guard_name' => 'admin'])->first();
        $vendor = Role::where(['name' => 'vendor', 'guard_name' => 'admin'])->first();

        foreach ($this->permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'admin'],
                ['group_name' => 'upsell']
            );

            if ($superAdmin && !$superAdmin->hasPermissionTo($permission)) {
                $superAdmin->givePermissionTo($permission);
            }

            if ($vendor && !$vendor->hasPermissionTo($permission)) {
                $vendor->givePermissionTo($permission);
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
