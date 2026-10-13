<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * What the server does when a registration arrives carrying fields belonging
 * to an account type other than the one it claims.
 *
 * The wizard keeps one form object so that going back to the type step and
 * forward again does not lose what was typed. Until now it also submitted that
 * whole object, so choosing business, filling it in and then switching to trade
 * sent `sector`, `requires_po` and `credit_application` along with the trade
 * application. The browser now builds the body from a field map, but the
 * browser is not the control — these tests fix the server's behaviour so that
 * a hand-made request cannot do what the form no longer does.
 */
class RegistrationTypeSwitchTest extends TestCase
{
    use DatabaseTransactions;

    private function signupPayload($overrides = [])
    {
        return array_merge([
            'name' => 'Switch Fixture',
            'email' => 'switch.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => 'Abcdefghij1',
            'terms' => 1,
        ], $overrides);
    }

    /** Everything a business step collects. */
    private function businessFields($overrides = [])
    {
        return array_merge([
            'company_name' => 'Murphy IT Solutions Ltd',
            'sector' => 'Professional services / office',
            'billing_address' => '25 Main Street, Dublin, Ireland',
            'vat_number' => 'IE1234567A',
            'cro_number' => '123456',
            'requires_po' => 1,
            'credit_application' => 1,
        ], $overrides);
    }

    /** Everything a trade step collects. */
    private function tradeFields($overrides = [])
    {
        return array_merge([
            'company_name' => "O'Brien Electrical Services Ltd",
            'trade' => 'Field engineer / sole trader',
            'vat_number' => 'IE' . random_int(1000000, 9999999) . 'B',
            'cro_number' => '654321',
            'billing_address' => '42 Industrial Estate, Cork, Ireland',
            'expected_spend' => '€2,000 – €5,000',
            'website' => 'https://example.com',
            'resale_confirmed' => 1,
        ], $overrides);
    }

    private function signup($payload)
    {
        return $this->postJson('/api/v1/user/signup', $payload);
    }

    private function userFor($email)
    {
        return User::where('email', $email)->first();
    }

    // ---- Test Case 6: business filled in, submitted as trade ----------------

    public function test_business_answers_do_not_reach_a_trade_registration()
    {
        // Exactly what the old wizard sent after business -> trade.
        $payload = $this->signupPayload(array_merge(
            $this->businessFields(),
            $this->tradeFields(),
            ['account_type' => 'trade']
        ));

        $this->signup($payload)->assertStatus(200);

        $user = $this->userFor($payload['email']);

        $this->assertSame('trade', $user->account_type);
        $this->assertSame(User::TRADE_PENDING, $user->trade_status);

        $profile = $user->businessProfile;

        // The trade answers are stored.
        $this->assertSame('Field engineer / sole trader', $profile->trade);
        $this->assertSame('€2,000 – €5,000', $profile->expected_spend);
        $this->assertTrue((bool)$profile->resale_confirmed);

        // The business-only answers are not, however loudly the request asked.
        $this->assertNull($profile->sector,
            'Sector is a business question and must not be stored against a trade account.');
        $this->assertFalse((bool)$profile->requires_po);
        $this->assertFalse((bool)$profile->credit_application);
    }

    public function test_a_trade_registration_keeps_the_company_details_both_types_share()
    {
        $payload = $this->signupPayload(array_merge(
            $this->tradeFields(['company_name' => 'Shared Co Ltd']),
            ['account_type' => 'trade']
        ));

        $this->signup($payload)->assertStatus(200);

        $profile = $this->userFor($payload['email'])->businessProfile;

        // Company name, VAT, CRO and billing address belong to both types, so
        // switching between them must not throw them away.
        $this->assertSame('Shared Co Ltd', $profile->company_name);
        $this->assertSame('654321', $profile->cro_number);
        $this->assertNotEmpty($profile->vat_number);
        $this->assertNotEmpty($profile->billing_address);
    }

    // ---- Test Case 7: trade filled in, submitted as business ----------------

    public function test_trade_answers_do_not_reach_a_business_registration()
    {
        $payload = $this->signupPayload(array_merge(
            $this->tradeFields(),
            $this->businessFields(['vat_number' => 'IE9998887C']),
            ['account_type' => 'business']
        ));

        $this->signup($payload)->assertStatus(200);

        $user = $this->userFor($payload['email']);

        $this->assertSame('business', $user->account_type);

        $profile = $user->businessProfile;

        $this->assertSame('Professional services / office', $profile->sector);
        $this->assertTrue((bool)$profile->requires_po);

        // None of the trade application survives the switch.
        $this->assertNull($profile->trade);
        $this->assertNull($profile->expected_spend);
        $this->assertNull($profile->website);
        $this->assertFalse((bool)$profile->resale_confirmed);
    }

    /**
     * The one that matters most: a request carrying a whole trade application
     * but claiming to be a business account must not end up in the review
     * queue, and must not be handed trade pricing.
     */
    public function test_a_business_registration_creates_no_trade_application()
    {
        $payload = $this->signupPayload(array_merge(
            $this->tradeFields(),
            $this->businessFields(['vat_number' => 'IE7776665D']),
            ['account_type' => 'business', 'trade_status' => 'approved']
        ));

        $this->signup($payload)->assertStatus(200);

        $user = $this->userFor($payload['email']);

        $this->assertSame('business', $user->account_type);
        // trade_status is derived from the account type, never read from the
        // request - so the injected "approved" is ignored.
        $this->assertNull($user->trade_status);
        $this->assertFalse($user->hasTradePricing());
    }

    public function test_a_business_registration_does_not_join_the_trade_mailing_list()
    {
        $payload = $this->signupPayload(array_merge(
            $this->businessFields(['vat_number' => 'IE6665554E']),
            ['account_type' => 'business', 'pref_trade_list' => 1]
        ));

        $this->signup($payload)->assertStatus(200);

        // A trade-only preference on a business account is not a preference.
        $this->assertFalse((bool)$this->userFor($payload['email'])->pref_trade_list);
    }

    // ---- Test Case 8: business filled in, submitted as personal -------------

    public function test_a_personal_registration_stores_no_company_record()
    {
        $payload = $this->signupPayload(array_merge(
            $this->businessFields(),
            $this->tradeFields(),
            ['account_type' => 'personal']
        ));

        $this->signup($payload)->assertStatus(200);

        $user = $this->userFor($payload['email']);

        $this->assertSame('personal', $user->account_type);
        $this->assertNull($user->trade_status);
        // No business profile row at all - not an empty one.
        $this->assertNull($user->businessProfile);
        // "Not required" is a different state from "not checked yet".
        $this->assertSame(User::VERIFICATION_NOT_REQUIRED, $user->verification_status);
        $this->assertFalse($user->hasBusinessPricing());
    }

    /**
     * A personal registration is not held to the business rules, so the
     * company fields riding along must not be able to refuse it either.
     */
    public function test_a_personal_registration_is_not_refused_by_business_rules()
    {
        $payload = $this->signupPayload([
            'account_type' => 'personal',
            // Would fail the business/trade format checks if they applied.
            'vat_number' => 'not a vat number',
            'cro_number' => 'IE12345',
        ]);

        $this->signup($payload)->assertStatus(200);

        $this->assertSame('personal', $this->userFor($payload['email'])->account_type);
    }

    // ---- validation follows the claimed type --------------------------------

    public function test_trade_rules_apply_the_moment_the_type_says_trade()
    {
        // Business is happy without a VAT number; trade is not.
        $payload = $this->signupPayload(array_merge(
            $this->businessFields(['vat_number' => null]),
            ['account_type' => 'trade']
        ));

        $response = $this->signup($payload);

        $this->assertSame(201, $response->json('status'));
        $this->assertNull($this->userFor($payload['email']),
            'A refused registration must not create an account.');
    }

    public function test_business_rules_do_not_demand_the_trade_answers()
    {
        $payload = $this->signupPayload(array_merge(
            $this->businessFields(['vat_number' => 'IE5554443F']),
            ['account_type' => 'business']
        ));

        // No trade, no expected_spend, no resale_confirmed anywhere.
        $this->signup($payload)->assertStatus(200);

        $this->assertSame('business', $this->userFor($payload['email'])->account_type);
    }

    public function test_business_rules_still_demand_the_business_answers()
    {
        $payload = $this->signupPayload(array_merge(
            $this->tradeFields(),
            ['account_type' => 'business']
        ));

        // The trade fields are all present, but sector is not - and business
        // needs it. Carrying a full trade application is no substitute.
        $response = $this->signup($payload);

        $this->assertSame(201, $response->json('status'));
        $this->assertNull($this->userFor($payload['email']));
    }

    public function test_an_unknown_account_type_falls_back_to_personal()
    {
        $payload = $this->signupPayload(array_merge(
            $this->tradeFields(),
            ['account_type' => 'wholesale']
        ));

        $response = $this->signup($payload);

        // Refused by the rules, which only accept the three known types.
        $this->assertSame(201, $response->json('status'));
        $this->assertNull($this->userFor($payload['email']));
    }

    // ---- re-registering over an unverified row ------------------------------

    /**
     * Re-registering an address that never confirmed its email overwrites the
     * pending row wholesale. Switching type on that second attempt has to take
     * the first attempt's account type and profile with it.
     */
    public function test_switching_type_on_a_second_attempt_leaves_nothing_behind()
    {
        $first = $this->signupPayload(array_merge(
            $this->tradeFields(),
            ['account_type' => 'trade']
        ));

        $this->signup($first)->assertStatus(200);

        $user = $this->userFor($first['email']);
        $this->assertSame('trade', $user->account_type);
        $this->assertSame(User::TRADE_PENDING, $user->trade_status);
        $this->assertNotNull($user->businessProfile);

        // Same address and phone, now registering as personal.
        $second = array_merge($first, $this->signupPayload([
            'email' => $first['email'],
            'phone' => $first['phone'],
            'account_type' => 'personal',
        ]));

        $this->signup($second)->assertStatus(200);

        $user = $this->userFor($first['email'])->fresh();

        $this->assertSame('personal', $user->account_type);
        $this->assertNull($user->trade_status);
        $this->assertSame(User::VERIFICATION_NOT_REQUIRED, $user->verification_status);
        // The trade application from the first attempt is gone.
        $this->assertNull($user->fresh()->businessProfile);
        $this->assertSame(1, User::where('email', $first['email'])->count());
    }

    public function test_switching_from_personal_to_business_on_a_second_attempt()
    {
        $first = $this->signupPayload(['account_type' => 'personal']);

        $this->signup($first)->assertStatus(200);
        $this->assertNull($this->userFor($first['email'])->businessProfile);

        $second = array_merge($first, $this->businessFields(['vat_number' => 'IE4443332G']), [
            'account_type' => 'business',
        ]);

        $this->signup($second)->assertStatus(200);

        $user = $this->userFor($first['email'])->fresh();

        $this->assertSame('business', $user->account_type);
        $this->assertSame(User::VERIFICATION_PENDING, $user->verification_status);
        $this->assertSame('Murphy IT Solutions Ltd', $user->businessProfile->company_name);
        $this->assertSame(1, User::where('email', $first['email'])->count());
    }

    // ---- nothing is created before the final submission ---------------------

    public function test_no_account_exists_until_the_details_step_is_submitted()
    {
        $payload = $this->signupPayload(array_merge(
            $this->businessFields(['vat_number' => 'IE3332221H']),
            ['account_type' => 'business']
        ));

        // Moving around the wizard makes no request at all, so the only way to
        // assert it is that nothing exists for this address beforehand.
        $this->assertNull($this->userFor($payload['email']));

        $this->signup($payload)->assertStatus(200);

        $this->assertNotNull($this->userFor($payload['email']));
    }

    public function test_a_refused_submission_creates_neither_account_nor_profile()
    {
        $payload = $this->signupPayload(array_merge(
            $this->businessFields(['company_name' => '']),
            ['account_type' => 'business']
        ));

        $response = $this->signup($payload);

        $this->assertSame(201, $response->json('status'));
        $this->assertNull($this->userFor($payload['email']));
    }
}
