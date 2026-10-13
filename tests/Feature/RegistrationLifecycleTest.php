<?php

namespace Tests\Feature;

use App\Models\Helper\TotpHelper;
use App\Models\User;
use App\Models\UserBusinessProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Registration -> verification -> security -> profile, end to end.
 *
 * The point of each test is that a value typed into the registration form
 * survives every layer beneath it and comes back out of the profile API,
 * and that the account can be managed afterwards without going back through
 * registration. "The UI has a checkbox for it" is not evidence of any of
 * that, so nothing here looks at the UI.
 */
class RegistrationLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private function uniqueEmail()
    {
        return 'reg.' . uniqid() . '@example.test';
    }

    private function uniquePhone()
    {
        return '08' . random_int(10000000, 99999999);
    }

    /**
     * Register through the real endpoint and return the (still unverified)
     * row, exactly as the wizard's details step leaves it.
     */
    private function register($overrides = [])
    {
        $payload = array_merge([
            'name' => 'Test Person',
            'email' => $this->uniqueEmail(),
            'phone' => $this->uniquePhone(),
            'password' => 'Abcdefghij1',
            'account_type' => 'personal',
            'terms' => 1,
            'pref_email_offers' => 1,
            'pref_sms_offers' => 0,
            'pref_recommendations' => 1,
        ], $overrides);

        $response = $this->postJson('/api/v1/user/signup', $payload);

        $response->assertStatus(200);
        $this->assertSame(200, $response->json('status'), json_encode($response->json()));

        return User::where('email', $payload['email'])->first();
    }

    /** Sign in as this user for the account-area endpoints. */
    private function actAs(User $user)
    {
        Passport::actingAs($user->fresh(), ['user'], 'user');
    }

    /* ══════════ A · personal registration ══════════ */

    public function test_personal_registration_saves_every_field_it_collects()
    {
        $email = $this->uniqueEmail();
        $phone = $this->uniquePhone();

        $user = $this->register([
            'name' => 'Dervla Personal',
            'email' => $email,
            'phone' => $phone,
            'pref_email_offers' => 1,
            'pref_sms_offers' => 1,
            'pref_recommendations' => 0,
        ]);

        $this->assertNotNull($user, 'signup created no row');
        $this->assertSame('Dervla Personal', $user->name);
        $this->assertSame($phone, $user->phone);
        $this->assertSame('personal', $user->account_type);

        // Preferences are three separate facts, not one flag.
        $this->assertTrue((bool)$user->pref_email_offers);
        $this->assertTrue((bool)$user->pref_sms_offers);
        $this->assertFalse((bool)$user->pref_recommendations);

        // Not usable until the emailed code is entered.
        $this->assertFalse((bool)$user->verified);

        // A personal account has no company details to check, which is a
        // different state from "not checked yet".
        $this->assertSame(User::VERIFICATION_NOT_REQUIRED, $user->verification_status);
        $this->assertNull($user->trade_status);

        // The password is hashed, and is not the plain text.
        $this->assertNotSame('Abcdefghij1', $user->password);
        $this->assertTrue(password_verify('Abcdefghij1', $user->password));

        // No company row for a personal account.
        $this->assertNull(UserBusinessProfile::where('user_id', $user->id)->first());
    }

    public function test_registration_rejects_a_weak_password_and_a_duplicate_email()
    {
        $existing = $this->register();
        $existing->update(['verified' => true]);

        $weak = $this->postJson('/api/v1/user/signup', [
            'name' => 'Weak',
            'email' => $this->uniqueEmail(),
            'phone' => $this->uniquePhone(),
            'password' => 'short',
            'account_type' => 'personal',
            'terms' => 1,
        ]);

        $this->assertSame(201, $weak->json('status'), 'a weak password was accepted');

        $duplicate = $this->postJson('/api/v1/user/signup', [
            'name' => 'Duplicate',
            'email' => $existing->email,
            'phone' => $this->uniquePhone(),
            'password' => 'Abcdefghij1',
            'account_type' => 'personal',
            'terms' => 1,
        ]);

        $this->assertNotSame(200, $duplicate->json('status'),
            'a second account was opened on a verified address');
    }

    /* ══════════ B · trade registration ══════════ */

    public function test_trade_registration_saves_the_application_and_opens_it_pending()
    {
        $user = $this->register([
            'name' => 'Trade Person',
            'account_type' => 'trade',
            'company_name' => 'Fix It Ltd',
            'vat_number' => 'IE1234567AB',
            'cro_number' => '654321',
            'billing_address' => '1 Trade Row, Dublin',
            'trade' => 'Computer / laptop repair shop',
            'expected_spend' => '€1,000 - €5,000',
            'website' => 'fixit.ie',
            'resale_confirmed' => 1,
            'pref_trade_list' => 1,
        ]);

        $this->assertSame('trade', $user->account_type);

        // Pending, not approved. Trade pricing is a staff decision.
        $this->assertSame(User::TRADE_PENDING, $user->trade_status);
        $this->assertFalse($user->hasTradePricing());
        $this->assertFalse($user->hasBusinessPricing());

        // ...but the account can still buy, at retail.
        $this->assertTrue($user->canCheckout());

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();

        $this->assertNotNull($profile, 'no business profile was written');
        $this->assertSame('Fix It Ltd', $profile->company_name);
        $this->assertSame('IE1234567AB', $profile->vat_number);
        $this->assertSame('654321', $profile->cro_number);
        $this->assertSame('1 Trade Row, Dublin', $profile->billing_address);
        $this->assertSame('Computer / laptop repair shop', $profile->trade);
        $this->assertSame('€1,000 - €5,000', $profile->expected_spend);
        $this->assertTrue((bool)$profile->resale_confirmed);
        $this->assertTrue((bool)$user->pref_trade_list);
    }

    public function test_business_registration_saves_po_and_credit_choices()
    {
        $user = $this->register([
            'account_type' => 'business',
            'company_name' => 'Office Co',
            'sector' => 'Professional services / office',
            'billing_address' => '2 Office Park, Cork',
            'requires_po' => 1,
            'credit_application' => 1,
        ]);

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();

        $this->assertNotNull($profile);
        $this->assertSame('Professional services / office', $profile->sector);

        // The two that the frontend previously showed and nothing consumed.
        $this->assertTrue((bool)$profile->requires_po);
        $this->assertTrue((bool)$profile->credit_application);

        // A business account is entitled to band pricing from day one.
        $this->assertTrue($user->hasBusinessPricing());
    }

    /* ══════════ F · "Skip for now" ══════════ */

    public function test_skipping_two_factor_leaves_a_working_account_with_2fa_off()
    {
        $user = $this->register();

        // "Skip for now" posts nothing at all — the account is already made.
        // So the state to assert is simply what registration left behind.
        $user->update(['verified' => true]);
        $user = $user->fresh();

        $this->assertFalse($user->twoFactorEnabled(), 'skipping marked the account as 2FA-enabled');
        $this->assertNull($user->two_factor_method);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_secret);

        // The account is usable: signing in returns a session, not a challenge.
        $login = $this->postJson('/api/v1/user/signin', [
            'email' => $user->email,
            'password' => 'Abcdefghij1',
        ]);

        $this->assertSame(200, $login->json('status'), json_encode($login->json()));
        $this->assertNull($login->json('data.two_factor_required'),
            'a skipped account was challenged for a second factor it never set up');
        $this->assertNotNull($login->json('data.access_token') ?: $login->json('data.token'),
            'no session was issued to an account with 2FA skipped');

        // And the profile API says plainly that 2FA is not configured.
        $this->actAs($user);

        $status = $this->getJson('/api/v1/user/two-factor/status');

        $this->assertSame(200, $status->json('status'));
        $this->assertFalse($status->json('data.enabled'));
        $this->assertNull($status->json('data.method'));
    }

    public function test_two_factor_can_be_turned_on_later_from_the_account()
    {
        $user = $this->register();
        $user->update(['verified' => true]);
        $this->actAs($user);

        // 1 · ask for a secret
        $setup = $this->postJson('/api/v1/user/two-factor/setup');

        $this->assertSame(200, $setup->json('status'), json_encode($setup->json()));

        $secret = $setup->json('data.secret');

        $this->assertNotEmpty($secret);
        $this->assertStringContainsString('otpauth://', $setup->json('data.uri'));

        // Nothing is on until a code proves the secret works.
        $this->assertFalse($user->fresh()->twoFactorEnabled());

        // 2 · a wrong code is refused and changes nothing
        $wrong = $this->postJson('/api/v1/user/two-factor/confirm', ['code' => '000000']);

        $this->assertSame(201, $wrong->json('status'), 'a wrong code was accepted');
        $this->assertFalse($user->fresh()->twoFactorEnabled());

        // 3 · the right code switches it on
        $confirm = $this->postJson('/api/v1/user/two-factor/confirm', [
            'code' => TotpHelper::codeAt($secret),
        ]);

        $this->assertSame(200, $confirm->json('status'), json_encode($confirm->json()));
        $this->assertSame('app', $confirm->json('data.method'));

        $codes = $confirm->json('data.recovery_codes');

        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes, 'no recovery codes were issued');

        $user = $user->fresh();

        $this->assertTrue($user->twoFactorEnabled());
        $this->assertSame('app', $user->two_factor_method);

        // The secret is stored encrypted, not in the clear.
        $this->assertNotSame($secret, $user->two_factor_secret);
        $this->assertSame($secret, Crypt::decryptString($user->two_factor_secret));

        // 4 · signing in now demands the second factor
        $login = $this->postJson('/api/v1/user/signin', [
            'email' => $user->email,
            'password' => 'Abcdefghij1',
        ]);

        $this->assertTrue((bool)$login->json('data.two_factor_required'),
            'sign-in did not ask for the second factor after it was enabled');
        $this->assertNull($login->json('data.access_token'),
            'a session was issued before the second factor was proved');

        $challenge = $login->json('data.challenge');

        $this->assertNotEmpty($challenge);

        // A wrong code does not get in.
        $bad = $this->postJson('/api/v1/user/two-factor/challenge', [
            'challenge' => $challenge,
            'code' => '000000',
        ]);

        $this->assertNotSame(200, $bad->json('status'), 'a wrong second factor was accepted');

        // The right one does.
        $good = $this->postJson('/api/v1/user/two-factor/challenge', [
            'challenge' => $challenge,
            'code' => TotpHelper::codeAt(Crypt::decryptString($user->two_factor_secret)),
        ]);

        $this->assertSame(200, $good->json('status'), json_encode($good->json()));
    }

    public function test_a_recovery_code_signs_in_once_and_is_then_spent()
    {
        $user = $this->register();
        $user->update(['verified' => true]);
        $this->actAs($user);

        $secret = $this->postJson('/api/v1/user/two-factor/setup')->json('data.secret');

        $codes = $this->postJson('/api/v1/user/two-factor/confirm', [
            'code' => TotpHelper::codeAt($secret),
        ])->json('data.recovery_codes');

        $user = $user->fresh();
        $recovery = $codes[0];

        $this->assertTrue(\App\Http\Controllers\TwoFactorController::passesChallenge($user, $recovery));

        // Single use: the same code must not work twice.
        $this->assertFalse(
            \App\Http\Controllers\TwoFactorController::passesChallenge($user->fresh(), $recovery),
            'a recovery code was accepted a second time'
        );
    }

    public function test_disabling_two_factor_requires_the_password()
    {
        $user = $this->register();
        $user->update(['verified' => true]);
        $this->actAs($user);

        $secret = $this->postJson('/api/v1/user/two-factor/setup')->json('data.secret');

        $this->postJson('/api/v1/user/two-factor/confirm', [
            'code' => TotpHelper::codeAt($secret),
        ]);

        $this->assertTrue($user->fresh()->twoFactorEnabled());

        // Wrong password: still on.
        $refused = $this->postJson('/api/v1/user/two-factor/disable', ['password' => 'nope']);

        $this->assertSame(201, $refused->json('status'));
        $this->assertTrue($user->fresh()->twoFactorEnabled(),
            '2FA was turned off without the account password');

        // Right password: off, and nothing left behind.
        $ok = $this->postJson('/api/v1/user/two-factor/disable', ['password' => 'Abcdefghij1']);

        $this->assertSame(200, $ok->json('status'), json_encode($ok->json()));

        $user = $user->fresh();

        $this->assertFalse($user->twoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_method);
    }

    /* ══════════ E · code hygiene ══════════ */

    /**
     * Codes are not interchangeable between flows.
     *
     * The SMS second factor was written with no purpose and read back with
     * no filter, so the newest usable code on the address — including one
     * emailed by the passwordless sign-in, which never touches the phone —
     * was accepted as the texted second factor.
     */
    public function test_an_emailed_sign_in_code_cannot_stand_in_for_the_texted_second_factor()
    {
        $user = $this->register();
        $user->update(['verified' => true]);

        $plain = '424242';

        // A perfectly valid code, issued by a different flow.
        \App\Models\LoginCode::create([
            'email' => $user->email,
            'purpose' => \App\Models\LoginCode::PURPOSE_LOGIN,
            'code' => \Illuminate\Support\Facades\Hash::make($plain),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $accepted = app(\App\Http\Controllers\TwoFactorController::class)
            ->consumeSmsCode($user->email, $plain);

        $this->assertFalse($accepted,
            'a code issued for passwordless sign-in was accepted as the SMS second factor');

        // A guest-checkout code is no better.
        \App\Models\LoginCode::create([
            'email' => $user->email,
            'purpose' => \App\Models\LoginCode::PURPOSE_GUEST_CHECKOUT,
            'code' => \Illuminate\Support\Facades\Hash::make($plain),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertFalse(
            app(\App\Http\Controllers\TwoFactorController::class)->consumeSmsCode($user->email, $plain),
            'a guest-checkout code was accepted as the SMS second factor'
        );

        // The right purpose is accepted, and only once.
        \App\Models\LoginCode::create([
            'email' => $user->email,
            'purpose' => \App\Models\LoginCode::PURPOSE_TWO_FACTOR,
            'code' => \Illuminate\Support\Facades\Hash::make($plain),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertTrue(
            app(\App\Http\Controllers\TwoFactorController::class)->consumeSmsCode($user->email, $plain)
        );

        $this->assertFalse(
            app(\App\Http\Controllers\TwoFactorController::class)->consumeSmsCode($user->email, $plain),
            'a texted code was accepted twice'
        );
    }

    public function test_a_new_mobile_number_is_no_longer_a_verified_one()
    {
        $user = $this->register();

        $user->update([
            'verified' => true,
            'phone_verified_at' => now(),
            'two_factor_method' => 'sms',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->assertTrue($user->fresh()->phoneVerified());
        $this->assertTrue($user->fresh()->twoFactorEnabled());

        $this->actAs($user);

        $this->postJson('/api/v1/user/update-profile', [
            'name' => $user->name,
            'phone' => $this->uniquePhone(),
        ])->assertStatus(200);

        $user = $user->fresh();

        $this->assertFalse($user->phoneVerified(),
            'a number that was never proved was carried over as verified');

        // SMS 2FA cannot keep pointing at a number nobody has proved.
        $this->assertFalse($user->twoFactorEnabled(),
            'SMS two-factor survived a move to an unproved number');
    }

    public function test_an_authenticator_app_survives_a_phone_number_change()
    {
        $user = $this->register();
        $user->update(['verified' => true]);
        $this->actAs($user);

        $secret = $this->postJson('/api/v1/user/two-factor/setup')->json('data.secret');

        $this->postJson('/api/v1/user/two-factor/confirm', [
            'code' => TotpHelper::codeAt($secret),
        ])->assertStatus(200);

        $this->assertTrue($user->fresh()->twoFactorEnabled());

        $this->postJson('/api/v1/user/update-profile', [
            'name' => $user->name,
            'phone' => $this->uniquePhone(),
        ])->assertStatus(200);

        // The app has nothing to do with the number, so it stays on.
        $this->assertTrue($user->fresh()->twoFactorEnabled(),
            'changing a phone number switched off an authenticator app');
        $this->assertSame('app', $user->fresh()->two_factor_method);
    }

    public function test_the_phone_verification_code_is_its_own_purpose()
    {
        $user = $this->register();
        $user->update(['verified' => true]);
        $this->actAs($user);

        $this->assertFalse($user->fresh()->phoneVerified());

        $plain = '313131';

        \App\Models\LoginCode::create([
            'email' => $user->email,
            'purpose' => \App\Models\LoginCode::PURPOSE_PHONE_VERIFY,
            'code' => \Illuminate\Support\Facades\Hash::make($plain),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        // A phone-verification code must not double as a second factor.
        $this->assertFalse(
            app(\App\Http\Controllers\TwoFactorController::class)->consumeSmsCode($user->email, $plain),
            'a phone-verification code was accepted as the second factor'
        );

        // A wrong code proves nothing.
        $this->postJson('/api/v1/user/phone/verify', ['code' => '000000']);
        $this->assertFalse($user->fresh()->phoneVerified());

        // The right one does.
        $this->postJson('/api/v1/user/phone/verify', ['code' => $plain])->assertStatus(200);

        $this->assertTrue($user->fresh()->phoneVerified(),
            'a correct code did not mark the number verified');
    }

    /* ══════════ G · profile editing ══════════ */

    public function test_registration_values_come_back_out_of_the_profile_api()
    {
        $user = $this->register([
            'account_type' => 'business',
            'company_name' => 'Readback Ltd',
            'sector' => 'School or college',
            'billing_address' => '3 Readback Way',
            'requires_po' => 1,
        ]);

        $user->update(['verified' => true]);
        $this->actAs($user);

        $profile = $this->getJson('/api/v1/user/profile');

        $this->assertSame(200, $profile->json('status'));

        // Account standing is decided server-side and handed over whole.
        $this->assertSame('business', $profile->json('data.account.account_type'));
        $this->assertTrue($profile->json('data.account.has_business_pricing'));

        // ...and the company details come back with it.
        $this->assertSame('Readback Ltd', $profile->json('data.business_profile.company_name'));
        $this->assertSame('School or college', $profile->json('data.business_profile.sector'));
        $this->assertTrue((bool)$profile->json('data.business_profile.requires_po'));

        // Secrets never appear in a profile response.
        $this->assertArrayNotHasKey('password', (array)$profile->json('data'));
        $this->assertArrayNotHasKey('two_factor_secret', (array)$profile->json('data'));
        $this->assertArrayNotHasKey('two_factor_recovery_codes', (array)$profile->json('data'));
    }

    public function test_preferences_are_editable_and_persist()
    {
        $user = $this->register([
            'pref_email_offers' => 1,
            'pref_sms_offers' => 1,
            'pref_recommendations' => 1,
        ]);

        $user->update(['verified' => true]);
        $this->actAs($user);

        $response = $this->postJson('/api/v1/user/update-profile', [
            'name' => $user->name,
            'pref_email_offers' => 0,
            'pref_sms_offers' => 1,
            'pref_recommendations' => 0,
        ]);

        $this->assertSame(200, $response->json('status'), json_encode($response->json()));

        $user = $user->fresh();

        $this->assertFalse((bool)$user->pref_email_offers, 'turning a preference off did not persist');
        $this->assertTrue((bool)$user->pref_sms_offers);
        $this->assertFalse((bool)$user->pref_recommendations);

        // And the read path agrees with what was written.
        $this->assertFalse((bool)$this->getJson('/api/v1/user/profile')->json('data.pref_email_offers'));
    }

    /**
     * The failure mode a partial form invites: reading absent checkboxes as
     * "off" and wiping settings the form never showed.
     */
    public function test_saving_the_name_alone_does_not_wipe_preferences_or_company_details()
    {
        $user = $this->register([
            'account_type' => 'business',
            'company_name' => 'Untouched Ltd',
            'sector' => 'Hospitality or retail',
            'billing_address' => '4 Untouched Street',
            'requires_po' => 1,
            'pref_email_offers' => 1,
            'pref_recommendations' => 1,
        ]);

        $user->update(['verified' => true]);
        $this->actAs($user);

        // Exactly what the name-and-phone panel posts.
        $this->postJson('/api/v1/user/update-profile', [
            'name' => 'Renamed Person',
            'phone' => $user->phone,
        ])->assertStatus(200);

        $user = $user->fresh();

        $this->assertSame('Renamed Person', $user->name);

        $this->assertTrue((bool)$user->pref_email_offers, 'a name change switched marketing off');
        $this->assertTrue((bool)$user->pref_recommendations);

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();

        $this->assertSame('Untouched Ltd', $profile->company_name, 'a name change blanked the company');
        $this->assertSame('Hospitality or retail', $profile->sector);
        $this->assertTrue((bool)$profile->requires_po);
    }

    public function test_business_details_are_editable_from_the_account()
    {
        $user = $this->register([
            'account_type' => 'business',
            'company_name' => 'Before Ltd',
            'sector' => 'Charity or community group',
            'billing_address' => 'Old Address',
        ]);

        $user->update(['verified' => true]);
        $this->actAs($user);

        $response = $this->postJson('/api/v1/user/update-profile', [
            'name' => $user->name,
            'company_name' => 'After Ltd',
            'billing_address' => 'New Address',
            'sector' => 'Medical or dental practice',
            'vat_number' => 'IE9876543Z',
            'requires_po' => 1,
        ]);

        $this->assertSame(200, $response->json('status'), json_encode($response->json()));

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();

        $this->assertSame('After Ltd', $profile->company_name);
        $this->assertSame('New Address', $profile->billing_address);
        $this->assertSame('Medical or dental practice', $profile->sector);
        $this->assertSame('IE9876543Z', $profile->vat_number);
        $this->assertTrue((bool)$profile->requires_po);

        // Changing who the company is sends it back for checking.
        $this->assertSame(User::VERIFICATION_PENDING, $user->fresh()->verification_status);
    }

    /**
     * The takeover the profile form would otherwise allow: a number that
     * cannot be registered with can't be edited in either.
     */
    public function test_another_companys_vat_number_cannot_be_edited_in()
    {
        $incumbent = $this->register([
            'account_type' => 'business',
            'company_name' => 'Incumbent Ltd',
            'sector' => 'Other',
            'billing_address' => '9 Incumbent Way',
            'vat_number' => 'IE5556667X',
        ]);

        $incumbent->update(['verified' => true]);

        $challenger = $this->register([
            'account_type' => 'business',
            'company_name' => 'Challenger Ltd',
            'sector' => 'Other',
            'billing_address' => '10 Challenge Road',
        ]);

        $challenger->update(['verified' => true]);
        $this->actAs($challenger);

        $response = $this->postJson('/api/v1/user/update-profile', [
            'name' => $challenger->name,
            'vat_number' => 'IE5556667X',
        ]);

        $this->assertSame(201, $response->json('status'),
            "one account edited in another company's VAT number");

        $this->assertNotSame('IE5556667X',
            UserBusinessProfile::where('user_id', $challenger->id)->first()->vat_number);

        // ...and re-saving your own number is not reported as a clash.
        $this->actAs($incumbent);

        $this->postJson('/api/v1/user/update-profile', [
            'name' => $incumbent->name,
            'vat_number' => 'IE5556667X',
        ])->assertStatus(200);

        $this->assertSame(200,
            $this->postJson('/api/v1/user/update-profile', [
                'name' => $incumbent->name,
                'vat_number' => 'IE5556667X',
            ])->json('status'),
            'an account was blocked from re-saving its own VAT number');
    }

    public function test_free_text_is_refused_where_a_vat_number_belongs()
    {
        $user = $this->register([
            'account_type' => 'business',
            'company_name' => 'Shape Ltd',
            'sector' => 'Other',
            'billing_address' => '5 Shape Road',
        ]);

        $user->update(['verified' => true]);
        $this->actAs($user);

        $response = $this->postJson('/api/v1/user/update-profile', [
            'name' => $user->name,
            'vat_number' => 'will send later',
        ]);

        $this->assertSame(201, $response->json('status'), 'free text was stored as a VAT number');
    }

    /* ══════════ D · the PO requirement reaches checkout ══════════ */

    /**
     * The wrapper the storefront puts around an order payload. Its key ships
     * in the client bundle, so this is obfuscation rather than authentication;
     * mirrored here so the test goes in through the real entry point.
     *
     * Zero padding, space-filled to the block size, because that is what
     * Utils::jsDecryption expects coming back — PKCS7 padding leaves bytes
     * that trim() misses, json_decode returns null, and the controller
     * answers "Something went wrong", which looks just like a refusal and
     * would let this test pass without reaching the check it exists for.
     */
    private function orderPayload(array $params)
    {
        $json = json_encode($params);
        $padded = $json . str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt(
            $padded,
            'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'),
            OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210')
        );
    }

    /**
     * Always re-establishes the Passport context first: a plain actingAs()
     * leaves the token without the 'user' scope, and every later call in the
     * test is then refused by scope:user for a reason that has nothing to do
     * with what is being tested.
     */
    private function placeOrder(User $user, array $payloadExtra = [])
    {
        $this->actAs($user);

        /*
         * The PO number goes inside the encrypted payload, not alongside it:
         * the controller copies it out of the decrypted params over anything
         * of the same name posted as a plain field, so a top-level one is
         * read as absent.
         */
        return $this->postJson('/api/v1/order/action', [
            'data' => $this->orderPayload(array_merge([
                'user_token' => null,
                'order_method' => 2, // cash on delivery: the one gateway enabled here
                'voucher' => '',
                'time_zone' => 'Europe/Dublin',
            ], $payloadExtra)),
            'order_method' => 2,
        ]);
    }

    /**
     * The preference is only worth saving if something downstream reads it.
     * Toggled through the profile endpoint a customer actually uses, then
     * checked at the order endpoint.
     */
    public function test_the_po_preference_set_from_the_profile_gates_checkout()
    {
        $user = $this->register([
            'account_type' => 'business',
            'company_name' => 'PO Ltd',
            'sector' => 'Other',
            'billing_address' => '8 PO Place',
            'requires_po' => 0,
        ]);

        $user->update(['verified' => true]);
        $this->actAs($user);

        // Off: the order is refused for other reasons (no basket), but never
        // for a missing PO number.
        $before = $this->placeOrder($user);

        $this->assertStringNotContainsString('purchase order',
            strtolower((string)$before->json('message')),
            'a PO number was demanded from an account that had not asked for one');

        // Turn it on from Account -> Business Details.
        $this->actAs($user);

        $this->postJson('/api/v1/user/update-profile', [
            'name' => $user->name,
            'requires_po' => 1,
        ])->assertStatus(200);

        $this->assertTrue(
            (bool)UserBusinessProfile::where('user_id', $user->id)->first()->requires_po
        );

        // On: checkout now insists on one.
        $this->actAs($user);

        $after = $this->placeOrder($user);

        $this->assertSame(201, $after->json('status'));
        $this->assertStringContainsString('purchase order number',
            strtolower((string)$after->json('message')),
            'the saved PO preference was ignored at checkout');

        // ...and supplying one gets past that check.
        $supplied = $this->placeOrder($user, ['purchase_order_number' => 'PO-12345']);

        $this->assertStringNotContainsString('purchase order number',
            strtolower((string)$supplied->json('message')),
            'a supplied PO number was not accepted');
    }

    /* ══════════ H · protected fields ══════════ */

    public function test_a_customer_cannot_promote_their_own_account()
    {
        $user = $this->register([
            'account_type' => 'trade',
            'company_name' => 'Climber Ltd',
            'vat_number' => 'IE1112223C',
            'billing_address' => '6 Climb Lane',
            'trade' => 'IT reseller or MSP',
            'expected_spend' => '€500 - €1,000',
            'resale_confirmed' => 1,
        ]);

        $user->update(['verified' => true]);
        $this->actAs($user);

        // Every protected field, in one request, all at once.
        $this->postJson('/api/v1/user/update-profile', [
            'name' => $user->name,
            'account_type' => 'business',
            'trade_status' => User::TRADE_APPROVED,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_VERIFIED,
            'verified' => 1,
            'resale_confirmed' => 1,
        ])->assertStatus(200);

        $user = $user->fresh();

        $this->assertSame('trade', $user->account_type, 'a customer changed their own account type');
        $this->assertSame(User::TRADE_PENDING, $user->trade_status,
            'a customer approved their own trade account');
        $this->assertNotSame(User::VERIFICATION_VERIFIED, $user->verification_status,
            'a customer verified their own company details');

        // The decisive check: still no trade pricing.
        $this->assertFalse($user->hasTradePricing());
        $this->assertFalse($user->hasBusinessPricing());
    }

    public function test_a_customer_cannot_grant_themselves_a_credit_account()
    {
        $user = $this->register([
            'account_type' => 'business',
            'company_name' => 'Credit Ltd',
            'sector' => 'Other',
            'billing_address' => '7 Credit Court',
            'credit_application' => 0,
        ]);

        $user->update(['verified' => true]);
        $this->actAs($user);

        // Applying is allowed...
        $this->postJson('/api/v1/user/update-profile', [
            'name' => $user->name,
            'credit_application' => 1,
        ])->assertStatus(200);

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();

        $this->assertTrue((bool)$profile->credit_application, 'the credit application was not recorded');

        /*
         * ...but applying is all it is. There is no column a customer can
         * write that means "approved" — approval is recorded by staff, and
         * the application flag alone grants nothing.
         */
        $this->assertFalse($user->fresh()->hasTradePricing());
    }

    public function test_one_account_cannot_edit_another_account(  )
    {
        $mine = $this->register();
        $mine->update(['verified' => true]);

        $theirs = $this->register();
        $theirs->update(['verified' => true, 'name' => 'Their Name']);

        $this->actAs($mine);

        // There is no id in the payload the endpoint will honour: it edits
        // whoever the token belongs to, and nothing else.
        $this->postJson('/api/v1/user/update-profile', [
            'id' => $theirs->id,
            'user_id' => $theirs->id,
            'name' => 'Hijacked',
        ])->assertStatus(200);

        $this->assertSame('Their Name', $theirs->fresh()->name,
            'one account edited another account');
        $this->assertSame('Hijacked', $mine->fresh()->name);
    }

    /* ══════════ I · existing accounts ══════════ */

    public function test_an_account_predating_these_columns_still_works()
    {
        $user = $this->register();

        /*
         * A row that predates these columns took the defaults the ALTER
         * applied — the three account columns are NOT NULL, so this, and not
         * a row full of nulls, is what an existing customer actually looks
         * like. No trade application and no second factor, because neither
         * existed when they signed up.
         */
        User::where('id', $user->id)->update([
            'verified' => true,
            'account_type' => 'personal',
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_NOT_REQUIRED,
            'trade_status' => null,
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $user = $user->fresh();

        $this->assertTrue($user->isActive());
        $this->assertTrue($user->canCheckout());
        $this->assertFalse($user->twoFactorEnabled());

        $summary = $user->accountSummary();

        $this->assertSame('personal', $summary['account_type']);
        $this->assertSame(User::STATUS_ACTIVE, $summary['status']);
        $this->assertSame(User::VERIFICATION_NOT_REQUIRED, $summary['verification_status']);
        $this->assertFalse($summary['has_business_pricing']);

        /*
         * isActive() also tolerates an unset status in memory, which is what
         * protects a row read before the column had a value. Asserted on the
         * model rather than through the database, which will not store one.
         */
        $blank = new User(['status' => null]);
        $this->assertTrue($blank->isActive(), 'a blank status locked an account out');

        $blank->status = '';
        $this->assertTrue($blank->isActive(), 'an empty status locked an account out');

        // And they can still sign in.
        $login = $this->postJson('/api/v1/user/signin', [
            'email' => $user->email,
            'password' => 'Abcdefghij1',
        ]);

        $this->assertSame(200, $login->json('status'), json_encode($login->json()));
    }
}
