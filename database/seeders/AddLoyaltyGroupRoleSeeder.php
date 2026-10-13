<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The loyalty group menu entry ships with gate: 'loyalty_group', and the
 * sidebar only renders an entry when a matching `<gate>.view` or
 * `<gate>.create` permission exists. No such permission was ever created, so
 * the menu was invisible to every role including superadmin, and the
 * controller had no permission to check either -- any authenticated admin
 * could read and edit every group regardless of their role.
 *
 * Loyalty groups are store-wide customer segments, so they follow the same
 * rule as `voucher` and `user`: superadmin only, not vendors.
 */
class AddLoyaltyGroupRoleSeeder extends Seeder
{
    public function run()
    {
        if (!Role::where('name', 'superadmin')->first()) {
            return;
        }

        $roleSuperAdmin = Role::findByName('superadmin', 'admin');

        $groupName = 'loyalty_group';

        $names = [
            'loyalty_group.view',
            'loyalty_group.create',
            'loyalty_group.edit',
            'loyalty_group.delete'
        ];

        foreach ($names as $name) {

            $permission = Permission::where('group_name', $groupName)
                ->where('guard_name', 'admin')
                ->where('name', $name)
                ->first();

            if (!$permission) {
                $permission = Permission::create([
                    'group_name' => $groupName,
                    'guard_name' => 'admin',
                    'name' => $name
                ]);
            }

            $roleSuperAdmin->givePermissionTo($permission);
            $permission->assignRole($roleSuperAdmin);
        }
    }
}
