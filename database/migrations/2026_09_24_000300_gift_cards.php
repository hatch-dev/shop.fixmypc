<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Gift cards, end to end.
 *
 *  - gift_voucher (the designs an admin manages) gains is_active, so a design
 *    can be withdrawn without deleting it and the purchases that point at it.
 *  - gift_voucher_orders stored amount and total as INT, so a €12.50 card was
 *    recorded as €12. Both become decimals, and each purchase can carry a
 *    recipient (name, email, message) to deliver the codes to.
 *  - gift_voucher_codes gains a status (active / redeemed / void) and a record
 *    of where the code was emailed.
 *  - wallet_transactions.source gains 'gift_card': redeeming a code now
 *    credits its value to the redeemer's wallet, which keeps any balance for
 *    later orders instead of losing it on a small basket.
 *  - Permissions for both admin screens. RolePermissionSeeder is skipped on an
 *    install that already has a superadmin, so they are created here.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('gift_voucher', 'is_active')) {
            Schema::table('gift_voucher', function (Blueprint $table) {
                $table->boolean('is_active')->default(true);
            });
        }

        DB::statement('ALTER TABLE gift_voucher_orders MODIFY amount DECIMAL(10,2) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE gift_voucher_orders MODIFY total DECIMAL(10,2) NOT NULL DEFAULT 0');

        Schema::table('gift_voucher_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('gift_voucher_orders', 'recipient_name')) {
                $table->string('recipient_name', 120)->nullable();
                $table->string('recipient_email', 190)->nullable();
                $table->string('sender_name', 120)->nullable();
                $table->text('message')->nullable();
            }
        });

        Schema::table('gift_voucher_codes', function (Blueprint $table) {
            if (!Schema::hasColumn('gift_voucher_codes', 'status')) {
                $table->string('status', 16)->default('active')->index();
                $table->string('sent_to', 190)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('void_reason', 255)->nullable();
            }
        });

        // Codes redeemed under the earlier single-use design are redeemed.
        DB::table('gift_voucher_codes')->whereNotNull('redeemed_by')->update(['status' => 'redeemed']);

        DB::statement("ALTER TABLE wallet_transactions MODIFY source
            ENUM('topup','order','refund','admin','gift_voucher','gift_card') NOT NULL");

        $groups = [
            'gift_voucher' => ['gift_voucher.view', 'gift_voucher.create', 'gift_voucher.edit', 'gift_voucher.delete'],
            'gift_card'    => ['gift_card.view', 'gift_card.edit'],
        ];

        $superAdmin = Role::where(['name' => 'superadmin', 'guard_name' => 'admin'])->first();

        foreach ($groups as $group => $names) {
            foreach ($names as $name) {
                $permission = Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'admin'],
                    ['group_name' => $group]
                );

                if ($superAdmin && !$superAdmin->hasPermissionTo($permission)) {
                    $superAdmin->givePermissionTo($permission);
                }
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down()
    {
        Permission::where('guard_name', 'admin')
            ->whereIn('group_name', ['gift_voucher', 'gift_card'])
            ->delete();

        DB::statement("ALTER TABLE wallet_transactions MODIFY source
            ENUM('topup','order','refund','admin','gift_voucher') NOT NULL");

        Schema::table('gift_voucher_codes', function (Blueprint $table) {
            $table->dropColumn(['status', 'sent_to', 'sent_at', 'void_reason']);
        });

        Schema::table('gift_voucher_orders', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'recipient_email', 'sender_name', 'message']);
        });

        Schema::table('gift_voucher', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
