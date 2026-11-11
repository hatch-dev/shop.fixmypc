<?php

namespace Tests\Isolated;

use App\Models\Admin;
use App\Models\Helper\AccountErasure;
use App\Models\Helper\AccountStatus;
use App\Models\User;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;
use Tests\IsolatedTestCase;

/**
 * Deleting a customer from the admin list (UsersController::delete ->
 * AccountErasure), brought over from the live server (29 Oct 2026).
 *
 * A customer with no records the shop must keep is deleted outright; one
 * with orders (or wallet, vouchers, memberships) is erased: the row stays so
 * the orders stay whole, the person is scrubbed out and sign-in is closed.
 * Revoking their sessions must not touch an admin who shares the numeric id.
 */
class AccountErasureTest extends IsolatedTestCase
{
    private function newCustomer(string $email): User
    {
        $user = User::findOrFail(Fixture::USER_PERSONAL_ID)->replicate();
        $user->forceFill(['email' => $email, 'name' => 'Erasure Tester', 'phone' => '0000000000'])->save();

        return $user->fresh();
    }

    private function address(User $user): int
    {
        return DB::table('user_addresses')->insertGetId([
            'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => '0000000000',
            'address_1' => '1 Main Street', 'city' => 'Kilcock', 'zip' => 'W23 0000', 'country' => 'IE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(User $user, ?int $addressId = null): int
    {
        return DB::table('orders')->insertGetId([
            'order' => 'ORD' . random_int(1000000, 9999999), 'user_id' => $user->id, 'user_address_id' => $addressId,
            'status' => (int)config('constants.orderStatus.DELIVERED'), 'order_method' => (int)config('constants.paymentMethod.SUMUP'),
            'payment_done' => 1, 'cancelled' => 2, 'total_amount' => 500, 'wallet_amount' => 0, 'currency' => 'EUR',
            'trans_id' => 'TX-' . random_int(1000, 9999), 'contact_name' => $user->name, 'contact_email' => $user->email,
            'created_at' => now()->subDays(3), 'updated_at' => now(),
        ]);
    }

    private function customerToken(User $user): string
    {
        return $user->createToken('user', ['user'])->token->id;
    }

    public function test_a_customer_with_no_records_is_deleted_outright()
    {
        $user = $this->newCustomer('erase-none-' . uniqid() . '@fixture.test');
        $this->address($user);
        DB::table('user_wishlists')->insert(['user_id' => $user->id, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'created_at' => now(), 'updated_at' => now()]);
        $tokenId = $this->customerToken($user);

        $this->assertApiOk($this->asAdmin()->deleteJson('/api/admin/user/delete/' . $user->id));

        $this->assertNull(User::find($user->id), 'the row is gone');
        $this->assertSame(0, DB::table('user_addresses')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('user_wishlists')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('id', $tokenId)->count(), 'its sign-in tokens are gone');
    }

    public function test_a_customer_with_orders_is_erased_and_the_orders_stay_whole()
    {
        $email = 'erase-orders-' . uniqid() . '@fixture.test';
        $user = $this->newCustomer($email);
        $usedAddress = $this->address($user);
        $spareAddress = $this->address($user);
        $orderId = $this->order($user, $usedAddress);
        DB::table('ordered_products')->insert(['order_id' => $orderId, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID, 'quantity' => 1, 'selling' => 500, 'tax_price' => 0,
            'tax_rate' => 23, 'tax_inclusive' => 1, 'bundle_offer' => 0, 'shipping_type' => 1,
            'shipping_place_id' => DB::table('shipping_places')->value('id'),
            'created_at' => now(), 'updated_at' => now()]);
        $tokenId = $this->customerToken($user);

        $this->assertApiOk($this->asAdmin()->deleteJson('/api/admin/user/delete/' . $user->id));

        $row = User::find($user->id);
        $this->assertNotNull($row, 'the row stays for the order');
        $this->assertSame(AccountErasure::ERASED_NAME, $row->name);
        $this->assertSame('deleted-' . $user->id . '@deleted.invalid', $row->email);
        $this->assertNull($row->phone);
        $this->assertNotNull($row->erased_at);
        $this->assertSame(User::STATUS_SUSPENDED, $row->status);

        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->whereNull('deleted_at')->count(), 'the order is untouched');
        $this->assertSame(1, DB::table('ordered_products')->where('order_id', $orderId)->count(), 'its lines are untouched');
        $this->assertSame(1, DB::table('user_addresses')->where('id', $usedAddress)->count(), 'the address the order went to stays');
        $this->assertSame(0, DB::table('user_addresses')->where('id', $spareAddress)->count(), 'an unused address goes');
        $this->assertTrue((bool)DB::table('oauth_access_tokens')->where('id', $tokenId)->value('revoked'), 'signed out');

        // The old email is free again and cannot sign in to the scrubbed row.
        $this->asGuest();
        $this->assertApiRefused($this->postJson('/api/v1/user/signin', ['email' => $email, 'password' => Fixture::PASSWORD]), 201);
    }

    public function test_an_erased_customer_is_left_out_of_the_admin_list_and_cannot_be_opened()
    {
        $user = $this->newCustomer('erase-hidden-' . uniqid() . '@fixture.test');
        $this->order($user);
        $this->assertApiOk($this->asAdmin()->deleteJson('/api/admin/user/delete/' . $user->id));

        $list = $this->asAdmin()->getJson('/api/admin/user/all?limit=200&q=' . urlencode(AccountErasure::ERASED_NAME));
        $this->assertApiOk($list);
        $this->assertStringNotContainsString('deleted-' . $user->id . '@deleted.invalid', $list->getContent());

        $this->assertNotSame(200, (int)$this->asAdmin()->getJson('/api/admin/user/find/' . $user->id)->json('status'));
        $this->assertNotSame(200, (int)$this->asAdmin()->deleteJson('/api/admin/user/delete/' . $user->id)->json('status'),
            'deleting it twice is refused');
    }

    public function test_a_live_paid_membership_blocks_deletion_and_nothing_is_touched()
    {
        $user = $this->newCustomer('erase-member-' . uniqid() . '@fixture.test');
        $plan = DB::table('membership_plans')->value('id') ?: DB::table('membership_plans')->insertGetId([
            'name' => 'Test plan', 'slug' => 'test-plan-' . uniqid(), 'price' => 9.99, 'currency' => 'EUR',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_memberships')->insert([
            'user_id' => $user->id, 'membership_plan_id' => $plan, 'source' => 'stripe', 'status' => 'active',
            'stripe_subscription_id' => 'sub_test_' . uniqid(), 'price' => 9.99, 'currency' => 'EUR',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $other = $this->newCustomer('erase-member-other-' . uniqid() . '@fixture.test');

        $response = $this->asAdmin()->deleteJson('/api/admin/user/delete/' . $other->id . ',' . $user->id);

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertStringContainsString('live paid membership', $response->getContent());
        $this->assertSame($user->email, User::find($user->id)->email, 'the member is untouched');
        $this->assertNotNull(User::find($other->id), 'one blocked id in a multi-delete removes nobody');
    }

    public function test_an_admin_without_the_delete_permission_is_refused()
    {
        $user = $this->newCustomer('erase-vendor-' . uniqid() . '@fixture.test');

        $response = $this->asAdmin(Fixture::ADMIN_VENDOR_ID)->deleteJson('/api/admin/user/delete/' . $user->id);

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertNotNull(User::find($user->id));
    }

    /**
     * Customer and admin tokens share oauth_access_tokens and a numeric
     * user_id. Revoking a customer's sessions (erasure, password reset,
     * suspension) must leave an admin with the same id signed in - the
     * fix kept from live when merging AccountStatus::revokeAllSessions.
     */
    public function test_revoking_a_customers_sessions_leaves_an_admin_with_the_same_id_signed_in()
    {
        $user = User::findOrFail(Fixture::USER_PERSONAL_ID);
        if (!Admin::find($user->id)) {
            $admin = Admin::findOrFail(Fixture::ADMIN_SUPER_ID)->replicate();
            $admin->forceFill(['email' => 'same-id-' . uniqid() . '@fixture.test', 'username' => 'same' . uniqid()]);
            $admin->id = $user->id;
            $admin->save();
        }
        $adminToken = Admin::findOrFail($user->id)->createToken('test', ['admin'])->token->id;
        $kept = $this->customerToken($user);
        $other = $this->customerToken($user);

        AccountStatus::revokeAllSessions($user, $kept);

        $this->assertFalse((bool)Token::find($adminToken)->revoked, 'the admin with the same id is still signed in');
        $this->assertFalse((bool)Token::find($kept)->revoked, 'the kept customer token stays');
        $this->assertTrue((bool)Token::find($other)->revoked, 'the other customer token is revoked');
    }
}
