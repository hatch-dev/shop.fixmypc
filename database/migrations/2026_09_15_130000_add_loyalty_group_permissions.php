<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AddLoyaltyGroupPermissions extends Migration
{
    /**
     * Run the migrations.
     *
     * RolePermissionSeeder returns early once a superadmin role exists, so the
     * loyalty_group group it gained never reaches an install that was seeded
     * before. LoyaltyGroupController gates every action on these names, so
     * without them the whole feature answers "not permitted" on live. Same
     * treatment the clearance group got in 2026_06_22_100100.
     *
     * @return void
     */
    public function up()
    {
        $groupName = 'loyalty_group';
        $permissions = [
            'loyalty_group.view',
            'loyalty_group.create',
            'loyalty_group.edit',
            'loyalty_group.delete',
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
                'loyalty_group.view',
                'loyalty_group.create',
                'loyalty_group.edit',
                'loyalty_group.delete',
            ])->delete();
    }
}
