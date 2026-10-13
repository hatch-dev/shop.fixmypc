<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for the Flash Discount screen.
 *
 * The menu entry ships with gate: 'flash_discount' and the sidebar only
 * renders an entry when a matching permission exists, but nothing in the
 * repository ever created one. The two rows that exist on this installation
 * -- flash_discount.view and flash_discount.create -- were inserted by hand,
 * so a fresh deploy had no Flash Discount menu at all, and neither .edit nor
 * .delete has ever existed anywhere, which is why a role could not be granted
 * one without the other.
 *
 * A store-wide discount belongs to the store, so it follows `voucher` and
 * `loyalty_group`: superadmin only, never vendors. FlashDiscountController
 * turns vendors away for the same reason.
 */
class AddFlashDiscountRoleSeeder extends Seeder
{
    public function run()
    {
        if (!Role::where('name', 'superadmin')->first()) {
            return;
        }

        $roleSuperAdmin = Role::findByName('superadmin', 'admin');

        $groupName = 'flash_discount';

        $names = [
            'flash_discount.view',
            'flash_discount.create',
            'flash_discount.edit',
            'flash_discount.delete'
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
