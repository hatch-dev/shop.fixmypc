<?php

namespace Tests\Isolated;

use App\Models\Admin;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\Hash;
use Tests\IsolatedTestCase;

/**
 * tests/FINDINGS.md F-01 (2026-11-18 audit).
 *
 * POST api/admin/signup took the whole request, so anyone could create an
 * active, verified staff account. That account had no role, but several
 * admin endpoints checked no permission and handed it every payment gateway
 * secret, the SumUp key (and let it rewrite that key in .env), customers'
 * wallet balances and the newsletter list. Seller signup had the same
 * verified/active hole.
 */
class AdminAccessSecurityTest extends IsolatedTestCase
{
    /** A signed-in staff account with no role at all. */
    private function noRoleAdmin()
    {
        return Admin::create([
            'name' => 'No Role', 'username' => 'norole' . uniqid(), 'email' => 'norole-' . uniqid() . '@example.invalid',
            'password' => Hash::make(Fixture::PASSWORD), 'verified' => 1, 'active' => 1,
        ]);
    }

    public function test_there_is_no_public_admin_signup()
    {
        $email = 'intruder-' . uniqid() . '@example.invalid';

        $this->asGuest()->postJson('/api/admin/signup', [
            'username' => 'intruder' . uniqid(), 'name' => 'Intruder', 'email' => $email,
            'password' => 'Intruder123', 'verified' => 1, 'active' => 1,
        ])->assertStatus(405);

        $this->assertNull(Admin::where('email', $email)->first());
    }

    public function test_seller_signup_cannot_mark_itself_verified_or_active()
    {
        $email = 'seller-' . uniqid() . '@example.invalid';

        $this->asGuest()->postJson('/api/v1/seller/signup', [
            'name' => 'Audit Seller', 'email' => $email, 'store_name' => 'Audit Store',
            'password' => 'Seller12345', 'verified' => 1, 'active' => 1,
        ]);

        $admin = Admin::where('email', $email)->first();
        $this->assertNotNull($admin, 'the seller account is still created');
        $this->assertSame(0, (int)$admin->verified);
        $this->assertSame(0, (int)$admin->active);
    }

    public function test_settings_secrets_and_customer_lists_need_a_permission()
    {
        $admin = $this->noRoleAdmin();

        foreach (['/api/admin/payment/find', '/api/admin/setting/sumup',
                     '/api/admin/wallet-overview/all', '/api/admin/subscriber/all-subscribers'] as $url) {
            $this->assertApiRefused($this->asAdmin($admin->id)->getJson($url), 403);
        }
    }

    public function test_the_sumup_key_cannot_be_rewritten_without_settings_permission()
    {
        $admin = $this->noRoleAdmin();
        $env = file_get_contents(base_path('.env'));

        $this->assertApiRefused($this->asAdmin($admin->id)->postJson('/api/admin/setting/sumup', [
            'sumup_api_key' => 'attacker', 'sumup_merchant_email' => 'a@example.invalid', 'sumup_merchant_code' => 'X',
        ]), 403);

        $this->assertSame($env, file_get_contents(base_path('.env')));
    }

    public function test_a_line_break_cannot_add_settings_to_env()
    {
        $env = file_get_contents(base_path('.env'));

        $reply = $this->asAdmin()->postJson('/api/admin/setting/sumup', [
            'sumup_api_key' => "key\nAPP_DEBUG=true", 'sumup_merchant_email' => 'a@example.invalid', 'sumup_merchant_code' => 'X',
        ]);

        $this->assertNotSame(200, (int)$reply->json('status'));
        $this->assertSame($env, file_get_contents(base_path('.env')));
    }

    /**
     * 2026-11-19: the admin guard resolved a customer's token to the admin
     * with the same id, so storefront routes in admin-aware controllers
     * treated that customer as staff.
     */
    public function test_a_customer_token_is_never_taken_for_the_admin_with_the_same_id()
    {
        Admin::forceCreate([
            'id' => Fixture::USER_PERSONAL_ID, 'name' => 'Id Clash', 'username' => 'idclash' . uniqid(),
            'email' => 'idclash-' . uniqid() . '@example.invalid', 'password' => Hash::make(Fixture::PASSWORD),
            'verified' => 1, 'active' => 1,
        ]);
        \DB::table('user_addresses')->insert([
            'user_id' => Fixture::USER_BUSINESS_ID, 'name' => 'Other Customer', 'email' => 'other@example.invalid',
            'phone' => '0870000001', 'address_1' => '1 Private Street', 'city' => 'Dublin', 'country' => 'IE', 'zip' => 'D01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $reply = $this->asCustomer(Fixture::USER_PERSONAL_ID)
            ->getJson('/api/v1/user/address/all?user_id=' . Fixture::USER_BUSINESS_ID);

        $this->assertStringNotContainsString('1 Private Street', $reply->getContent());
    }

    public function test_reviews_cannot_be_published_or_rewritten_anonymously()
    {
        $review = \App\Models\RatingReview::create([
            'product_id' => Fixture::PRODUCT_PHONE_ID, 'order_id' => 0, 'rating' => 5,
            'review' => 'A real customer review', 'source' => 'shop', 'name' => 'Real', 'approved' => 1,
        ]);
        $count = \App\Models\RatingReview::count();

        $fake = ['rating' => 5, 'source' => 'shop', 'name' => 'Anon', 'product_id' => $review->product_id,
            'review' => 'fake', 'is_verified' => 1];
        $tamper = ['review_id' => $review->id, 'rating' => 1, 'source' => 'shop', 'name' => 'Tampered',
            'product_id' => $review->product_id, 'review' => 'tampered'];

        // The old public v1 store/update routes no longer exist (moved to the admin group).
        $this->asGuest()->postJson('/api/v1/rating-review/store', $fake)->assertStatus(405);
        $this->asGuest()->postJson('/api/v1/rating-review/update', $tamper)->assertStatus(405);

        // The admin routes refuse a guest and a customer token alike.
        foreach (['store' => $fake, 'update' => $tamper] as $action => $body) {
            foreach (['guest' => $this->asGuest(), 'customer' => $this->asCustomer()] as $who => $client) {
                $reply = $client->postJson('/api/admin/rating-review/' . $action, $body);
                // The API answers a refusal in its body: 204 "Unauthenticated." or 403.
                $this->assertTrue(in_array($reply->status(), [401, 403], true)
                    || in_array((int)$reply->json('status'), [204, 401, 403], true),
                    "$who on admin/rating-review/$action was not refused: " . substr($reply->getContent(), 0, 200));
            }
        }

        $this->assertSame($count, \App\Models\RatingReview::count());
        $this->assertSame('A real customer review', $review->fresh()->review);
    }

    /** Staff sign-in had only the general 300/minute limit. */
    public function test_repeated_wrong_staff_passwords_are_throttled()
    {
        $codes = [];
        for ($i = 0; $i < 11; $i++) {
            $codes[] = $this->asGuest()->postJson('/api/admin/login', [
                'email' => Fixture::ADMIN_SUPER_EMAIL, 'password' => 'wrong-' . $i,
            ])->status();
        }

        $this->assertNotContains(429, array_slice($codes, 0, 10), 'the first ten tries are answered');
        $this->assertSame(429, end($codes), 'the eleventh in a minute is refused');

        // Counted apart from the customer sign-in limit on the same IP.
        $this->assertNotSame(429, $this->asGuest()->postJson('/api/v1/user/signin', [
            'email' => 'nobody@example.invalid', 'password' => 'wrong-password-1',
        ])->status());
    }

    public function test_the_super_admin_still_reads_them()
    {
        $this->assertApiOk($this->asAdmin()->getJson('/api/admin/payment/find'));
        $this->assertApiOk($this->asAdmin()->getJson('/api/admin/setting/sumup'));
        $this->assertApiOk($this->asAdmin()->getJson('/api/admin/wallet-overview/all'));
        $this->assertApiOk($this->asAdmin()->getJson('/api/admin/subscriber/all-subscribers'));
    }
}
