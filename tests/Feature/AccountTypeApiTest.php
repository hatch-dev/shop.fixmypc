<?php

namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Product;
use App\Models\User;
use App\Models\UserBusinessProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Business and trade accounts across the API surface: registration, the review
 * queue, the pricing they are entitled to, and what suspension takes away.
 *
 * Every test runs inside a transaction that is rolled back, so the development
 * database is left exactly as it was found.
 */
class AccountTypeApiTest extends TestCase
{
    use DatabaseTransactions;

    private function signupPayload($overrides = [])
    {
        $unique = uniqid();

        return array_merge([
            'name' => 'Test Person',
            'email' => "acct.{$unique}@example.test",
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => 'Abcdefghij1',
            'terms' => 1,
            'account_type' => 'personal',
        ], $overrides);
    }

    private function businessPayload($overrides = [])
    {
        return $this->signupPayload(array_merge([
            'account_type' => 'business',
            'company_name' => 'Acme Supplies Ltd',
            'sector' => 'IT and electronics',
            'billing_address' => '1 Main Street, Kilcock',
        ], $overrides));
    }

    private function tradePayload($overrides = [])
    {
        return $this->signupPayload(array_merge([
            'account_type' => 'trade',
            'company_name' => 'Sparks Electrical',
            'trade' => 'Electrician',
            'vat_number' => 'IE' . random_int(1000000, 9999999) . 'X',
            'billing_address' => '2 High Street, Maynooth',
            'expected_spend' => '1000-5000',
            'resale_confirmed' => 1,
        ], $overrides));
    }

    private function signup($payload)
    {
        return $this->postJson('/api/v1/user/signup', $payload);
    }

    private function userFor($email)
    {
        return User::where('email', $email)->first();
    }

    /** A customer who has confirmed their email and can actually shop. */
    private function activeUser($attributes = [])
    {
        $user = User::create(array_merge([
            'name' => 'Fixture',
            'email' => 'fixture.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_NOT_REQUIRED,
        ], $attributes));

        return $user->fresh();
    }

    // ---- registration ------------------------------------------------------

    public function test_a_personal_registration_still_works_unchanged()
    {
        $payload = $this->signupPayload();

        $this->signup($payload)->assertStatus(200);

        $user = $this->userFor($payload['email']);

        $this->assertNotNull($user);
        $this->assertSame('personal', $user->account_type);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        // Nothing to verify, and no company record left behind.
        $this->assertSame(User::VERIFICATION_NOT_REQUIRED, $user->verification_status);
        $this->assertNull($user->trade_status);
        $this->assertNull($user->businessProfile);
        $this->assertSame(0, (int)$user->verified);
    }

    public function test_a_business_registration_stores_the_company_and_opens_active()
    {
        $payload = $this->businessPayload(['vat_number' => 'IE7654321B', 'cro_number' => '556677']);

        $this->signup($payload)->assertStatus(200);

        $user = $this->userFor($payload['email']);

        $this->assertSame('business', $user->account_type);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        // Company details exist but nobody has checked them yet.
        $this->assertSame(User::VERIFICATION_PENDING, $user->verification_status);

        $profile = $user->businessProfile;

        $this->assertSame('Acme Supplies Ltd', $profile->company_name);
        $this->assertSame('IE7654321B', $profile->vat_number);
        $this->assertSame('556677', $profile->cro_number);
        $this->assertSame('IT and electronics', $profile->sector);
    }

    public function test_a_trade_registration_opens_pending_and_is_not_granted_pricing()
    {
        $payload = $this->tradePayload();

        $this->signup($payload)->assertStatus(200);

        $user = $this->userFor($payload['email']);

        $this->assertSame('trade', $user->account_type);
        $this->assertSame(User::TRADE_PENDING, $user->trade_status);
        // Usable account, unapproved application: retail prices until reviewed.
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertFalse($user->hasBusinessPricing());
        $this->assertTrue($user->canCheckout());
    }

    /**
     * trade_status and status are both fillable, so a signup that passed the
     * raw request through would let an applicant approve itself.
     */
    public function test_a_registration_cannot_approve_or_verify_itself()
    {
        $payload = $this->tradePayload([
            'trade_status' => User::TRADE_APPROVED,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_VERIFIED,
            'verified' => 1,
        ]);

        $this->signup($payload)->assertStatus(200);

        $user = $this->userFor($payload['email']);

        $this->assertSame(User::TRADE_PENDING, $user->trade_status);
        $this->assertSame(User::VERIFICATION_PENDING, $user->verification_status);
        $this->assertSame(0, (int)$user->verified);
        $this->assertFalse($user->hasBusinessPricing());
    }

    public function test_an_unknown_account_type_is_refused()
    {
        $response = $this->signup($this->signupPayload(['account_type' => 'wholesale']));

        $this->assertSame(201, $response->json('status'));
        $this->assertArrayHasKey('account_type', $response->json('data.fields'));
    }

    public function test_business_registration_requires_the_business_details()
    {
        $payload = $this->businessPayload();
        unset($payload['company_name'], $payload['sector'], $payload['billing_address']);

        $response = $this->signup($payload);

        $fields = $response->json('data.fields');

        $this->assertSame(201, $response->json('status'));
        $this->assertArrayHasKey('company_name', $fields);
        $this->assertArrayHasKey('sector', $fields);
        $this->assertArrayHasKey('billing_address', $fields);
    }

    public function test_trade_registration_requires_the_trade_details()
    {
        $payload = $this->tradePayload();
        unset($payload['trade'], $payload['vat_number'], $payload['resale_confirmed']);

        $response = $this->signup($payload);

        $fields = $response->json('data.fields');

        $this->assertArrayHasKey('trade', $fields);
        $this->assertArrayHasKey('vat_number', $fields);
        $this->assertArrayHasKey('resale_confirmed', $fields);
    }

    public function test_an_unusable_vat_number_is_refused()
    {
        $response = $this->signup($this->tradePayload(['vat_number' => 'will send later']));

        $this->assertArrayHasKey('vat_number', $response->json('data.fields'));
    }

    public function test_a_duplicate_email_is_refused()
    {
        $existing = $this->activeUser();

        $response = $this->signup($this->businessPayload(['email' => $existing->email]));

        $this->assertSame(201, $response->json('status'));
        $this->assertArrayHasKey('email', $response->json('data.fields'));
    }

    public function test_a_vat_number_already_on_a_verified_account_is_refused()
    {
        $existing = $this->activeUser(['account_type' => 'business']);

        UserBusinessProfile::create([
            'user_id' => $existing->id,
            'company_name' => 'Already Registered Ltd',
            'vat_number' => 'IE5551234Q',
        ]);

        $response = $this->signup($this->tradePayload(['vat_number' => 'IE5551234Q']));

        $this->assertSame(201, $response->json('status'));
        $this->assertArrayHasKey('vat_number', $response->json('data.fields'));
    }

    /**
     * Re-registering an unverified address is how somebody who lost their code
     * starts again, and it overwrites the row. A suspended account must not be
     * launderable that way.
     */
    public function test_a_suspended_account_cannot_be_re_registered()
    {
        $suspended = $this->activeUser([
            'account_type' => 'business',
            'verified' => false,
            'status' => User::STATUS_SUSPENDED,
        ]);

        $response = $this->signup($this->businessPayload(['email' => $suspended->email]));

        $this->assertSame(201, $response->json('status'));
        $this->assertSame(User::STATUS_SUSPENDED, $suspended->fresh()->status);
    }

    // ---- pricing entitlement, enforced at the API ---------------------------

    /**
     * The product page asks this endpoint what quantity bands to advertise.
     * It must answer nothing at all for anyone not entitled to them, or the
     * store advertises a rate the till will not honour.
     */
    private function bandsFor($user, $productId)
    {
        $response = $this->actingAs($user, 'user')
            ->getJson("/api/v1/business-product/{$productId}");

        $response->assertStatus(200);

        return $response->json('data.pricing');
    }

    private function pricedProduct()
    {
        $product = Product::where('status', 1)->whereNotNull('selling')
            ->where('selling', '>', 0)->first();

        if (!$product) {
            $this->markTestSkipped('No published product to price against.');
        }

        BusinessProductPricing::create([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => round($product->selling * 0.5, 2),
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'final_price' => round($product->selling * 0.5, 2),
        ]);

        return $product;
    }

    public function test_a_guest_is_offered_no_business_bands()
    {
        $product = $this->pricedProduct();

        $this->getJson("/api/v1/business-product/{$product->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.pricing', []);
    }

    public function test_a_personal_account_is_offered_no_business_bands()
    {
        $product = $this->pricedProduct();

        $this->assertSame([], $this->bandsFor($this->activeUser(), $product->id));
    }

    public function test_a_pending_trade_account_is_offered_no_bands()
    {
        $product = $this->pricedProduct();

        $user = $this->activeUser([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]);

        $this->assertSame([], $this->bandsFor($user, $product->id));
    }

    public function test_a_rejected_trade_account_is_offered_no_bands()
    {
        $product = $this->pricedProduct();

        $user = $this->activeUser([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_REJECTED,
        ]);

        $this->assertSame([], $this->bandsFor($user, $product->id));
    }

    public function test_an_approved_trade_account_is_offered_the_bands()
    {
        $product = $this->pricedProduct();

        $user = $this->activeUser([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
        ]);

        $this->assertNotEmpty($this->bandsFor($user, $product->id));
    }

    public function test_a_business_account_is_offered_the_bands()
    {
        $product = $this->pricedProduct();

        $user = $this->activeUser(['account_type' => 'business']);

        $this->assertNotEmpty($this->bandsFor($user, $product->id));
    }

    /** Suspension takes the pricing away, not just the ability to check out. */
    public function test_a_suspended_trade_account_loses_its_bands()
    {
        $product = $this->pricedProduct();

        $user = $this->activeUser([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->assertSame([], $this->bandsFor($user, $product->id));
    }

    public function test_withdrawing_trade_status_loses_the_bands()
    {
        $product = $this->pricedProduct();

        $user = $this->activeUser([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_SUSPENDED,
        ]);

        $this->assertSame([], $this->bandsFor($user, $product->id));
    }

    /**
     * The endpoint quotes the price the band will actually charge, derived
     * server-side. The storefront was recomputing it from products.offered,
     * so whenever the two differed the page advertised a figure nothing would
     * honour.
     */
    public function test_the_quoted_band_price_is_the_one_the_backend_derives()
    {
        $product = $this->pricedProduct();

        $user = $this->activeUser(['account_type' => 'business']);

        $bands = $this->bandsFor($user, $product->id);

        $this->assertSame(
            round($product->selling * 0.5, 2),
            (float)$bands[0]['final_price']
        );
    }

    // ---- what the storefront is told about the account ----------------------

    public function test_the_profile_reports_the_account_state()
    {
        $user = $this->activeUser([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
            'verification_status' => User::VERIFICATION_PENDING,
        ]);

        $response = $this->actingAs($user, 'user')->getJson('/api/v1/user/profile');

        $response->assertStatus(200);

        $account = $response->json('data.account');

        $this->assertSame('trade', $account['account_type']);
        $this->assertSame('pending', $account['trade_status']);
        $this->assertFalse($account['has_business_pricing']);
        $this->assertTrue($account['can_checkout']);
    }

    public function test_the_profile_never_leaks_pricing_entitlement_to_a_suspended_account()
    {
        $user = $this->activeUser([
            'account_type' => 'business',
            'status' => User::STATUS_SUSPENDED,
        ]);

        $account = $this->actingAs($user, 'user')
            ->getJson('/api/v1/user/profile')
            ->json('data.account');

        $this->assertFalse($account['has_business_pricing']);
        $this->assertFalse($account['can_checkout']);
    }

    // ---- admin authorization ------------------------------------------------

    /**
     * The review queue and the suspend button are behind admin permissions.
     * A customer holding nothing but their own bearer token must not reach
     * either, whatever the storefront does or does not render.
     */
    public function test_a_customer_cannot_approve_their_own_trade_application()
    {
        $user = $this->activeUser([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]);

        $this->actingAs($user, 'user')
            ->postJson("/api/admin/trade-application/action/{$user->id}", [
                'trade_status' => User::TRADE_APPROVED,
            ]);

        $this->assertSame(User::TRADE_PENDING, $user->fresh()->trade_status);
        $this->assertFalse($user->fresh()->hasBusinessPricing());
    }

    public function test_a_customer_cannot_reactivate_their_own_suspended_account()
    {
        $user = $this->activeUser([
            'account_type' => 'business',
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->actingAs($user, 'user')
            ->postJson("/api/admin/user/status/{$user->id}", [
                'status' => User::STATUS_ACTIVE,
            ]);

        $this->assertSame(User::STATUS_SUSPENDED, $user->fresh()->status);
    }

    public function test_a_customer_cannot_mark_their_own_details_verified()
    {
        $user = $this->activeUser([
            'account_type' => 'business',
            'verification_status' => User::VERIFICATION_PENDING,
        ]);

        $this->actingAs($user, 'user')
            ->postJson("/api/admin/user/verification/{$user->id}", [
                'verification_status' => User::VERIFICATION_VERIFIED,
            ]);

        $this->assertSame(User::VERIFICATION_PENDING, $user->fresh()->verification_status);
    }

    public function test_an_anonymous_caller_cannot_reach_the_review_queue()
    {
        $response = $this->getJson('/api/admin/trade-application/all');

        $this->assertNotSame(200, $response->json('status'));
    }

    /**
     * The wrapper the storefront puts around an order payload.
     *
     * Its key and IV ship in the client bundle, so this is obfuscation rather
     * than authentication — which is exactly why the controller derives every
     * price server-side and reads nothing but identifiers out of it. Mirrored
     * here so the test exercises the real entry point.
     */
    private function orderPayload(array $params)
    {
        $json = json_encode($params);

        /*
         * Zero padding, space-filled to the block size, because that is what
         * Utils::jsDecryption expects on the way back in. Encrypting with the
         * default PKCS7 padding instead leaves pad bytes on the decoded string
         * that trim() does not remove, json_decode returns null, and the
         * controller answers "Something went wrong" — which looks exactly like
         * a refused order and would have let this test pass without ever
         * reaching the check it exists to cover.
         */
        $padded = $json . str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt(
            $padded,
            'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'),
            OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210')
        );
    }

    // ---- checkout access ----------------------------------------------------

    /**
     * Suspension has to bite where it costs something. The order endpoint is
     * reachable with nothing but a bearer token, so a hidden button stops
     * nobody.
     */
    public function test_a_suspended_account_cannot_place_an_order()
    {
        $user = $this->activeUser([
            'account_type' => 'business',
            'status' => User::STATUS_SUSPENDED,
        ]);

        $response = $this->actingAs($user, 'user')->postJson('/api/v1/order/action', [
            'data' => $this->orderPayload([
                'user_token' => null,
                'order_method' => 1,
                'voucher' => '',
                'time_zone' => 'Europe/Dublin',
            ]),
            'order_method' => 1,
        ]);

        /*
         * Asserted on the message, not merely on a non-200. Everything about
         * this request is incomplete — there is no basket and no configured
         * gateway — so it would be refused either way, and a looser assertion
         * would keep passing if the suspension check were removed entirely.
         */
        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString(
            'cannot place orders',
            (string)$response->json('message')
        );
        $this->assertSame(0, \App\Models\Order::where('user_id', $user->id)->count());
    }

    /**
     * The mirror of the test above: an active account gets past the suspension
     * check and is stopped by the next thing instead. Without this pair, the
     * check could be deleted and the suite would stay green.
     */
    public function test_an_active_account_is_not_stopped_by_the_suspension_check()
    {
        $user = $this->activeUser(['account_type' => 'business']);

        $response = $this->actingAs($user, 'user')->postJson('/api/v1/order/action', [
            'data' => $this->orderPayload([
                'user_token' => null,
                'order_method' => 1,
                'voucher' => '',
                'time_zone' => 'Europe/Dublin',
            ]),
            'order_method' => 1,
        ]);

        $this->assertStringNotContainsString(
            'cannot place orders',
            (string)$response->json('message')
        );
    }
}
