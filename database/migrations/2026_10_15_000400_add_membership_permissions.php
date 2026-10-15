<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AddMembershipPermissions extends Migration
{
    /**
     * Run the migrations.
     *
     * The membership plans and members pages (MembershipPlanController,
     * MembershipMemberController) gate on these. Added by migration because
     * RolePermissionSeeder returns early once a superadmin role exists.
     *
     * @return void
     */
    public function up()
    {
        $groupName = 'membership';
        $permissions = [
            'membership.view',
            'membership.create',
            'membership.edit',
            'membership.delete',
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
                'membership.view',
                'membership.create',
                'membership.edit',
                'membership.delete',
            ])->delete();
    }
}
