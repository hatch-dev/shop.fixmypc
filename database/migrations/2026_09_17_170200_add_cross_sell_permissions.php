<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * RolePermissionSeeder returns early once a superadmin role exists, so a group
 * added to it never reaches an install that was seeded before -- and the live
 * site is one of those. Same treatment upsell (2026_09_17_140100), clearance
 * (2026_06_22_100100) and loyalty_group (2026_09_15_130000) got.
 *
 * Four permissions for the PROMOTION, the same four every other promotion
 * here has. "Manage products" and "enable/disable" both change the promotion
 * through the same screen and are covered by `edit`; splitting them would be
 * tick boxes protecting nothing anyone has asked to protect.
 *
 * ASSIGNING a promotion to a product is not here at all, on purpose. It is an
 * edit of the product, made on the product's own screen, and it is gated by
 * `product.edit` -- the permission that already decides who may change what a
 * product page shows. An admin trusted with the product is trusted to say
 * which accessories go under it; one who is not, is not.
 */
class AddCrossSellPermissions extends Migration
{
    private $permissions = [
        'crosssell.view',
        'crosssell.create',
        'crosssell.edit',
        'crosssell.delete',
    ];

    public function up()
    {
        $superAdmin = Role::where(['name' => 'superadmin', 'guard_name' => 'admin'])->first();
        $vendor = Role::where(['name' => 'vendor', 'guard_name' => 'admin'])->first();

        foreach ($this->permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'admin'],
                ['group_name' => 'crosssell']
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
