<?php

namespace Tests\Isolated;

use App\Models\Helper\TotpHelper;
use App\Models\PasswordResetToken;
use App\Models\User;
use Database\Seeders\Testing\Fixture;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Token;
use Tests\IsolatedTestCase;

/**
 * Sign-in, registration and password reset (2026-11-04).
 *
 * The gaps this covers, as found in the code:
 *  - signing up again over an unconfirmed sign-up replaced its password and
 *    phone, and the code then emailed to the address confirmed them, so the
 *    owner of the address could be handed an account somebody else controls;
 *  - a person who signed up but never confirmed was told "wrong password",
 *    sent no reset link and told their address did not exist, with no way on;
 *  - user/update-password set a password from the signup code with a loose
 *    comparison, no expiry and no attempt limit;
 *  - the social callback signed in without the account-status check or the
 *    second factor and put the access token in the redirect URL;
 *  - six-character passwords were still accepted by the legacy signup path
 *    and the change-password form.
 *
 * Throttling is switched off here: every throttled route shares one counter
 * per IP, and these tests make more requests than a person would. It is not
 * what is under test.
 */
class AuthenticationSecurityTest extends IsolatedTestCase
{
    private const NEW_PASSWORD = 'Brand-New-Pass-42';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mails()->flush();
    }

    private function mails()
    {
        return Mail::getSwiftMailer()->getTransport();
    }

    /** Mails sent to this address since the test started. */
    private function mailsTo(string $email)
    {
        return collect($this->mails()->messages())
            ->filter(function ($message) use ($email) {
                return array_key_exists($email, (array)$message->getTo());
            })
            ->values();
    }

    private function signIn(string $email, string $password, array $extra = [])
    {
        $this->asGuest();

        return $this->postJson('/api/v1/user/signin', ['email' => $email, 'password' => $password] + $extra);
    }

    private function register(string $email, string $password, array $overrides = [])
    {
        $this->asGuest();

        return $this->postJson('/api/v1/user/signup', array_merge([
            'name' => 'Pat Pending',
            'email' => $email,
            'phone' => '+3538' . random_int(10000000, 99999999),
            'password' => $password,
            'account_type' => 'personal',
            'terms' => 1,
        ], $overrides));
    }

    private function verify(string $email, $code, $password)
    {
        $this->asGuest();

        return $this->postJson('/api/v1/user/verify', array_filter([
            'email' => $email, 'code' => $code, 'password' => $password,
        ], function ($value) { return $value !== null; }));
    }

    /**
     * A refusal whose text is on the form: validation failures carry it in
     * data.form (what the storefront shows) and leave `message` empty.
     */
    private function assertFormRefused($response, string $contains)
    {
        $this->assertSame(201, (int)$response->json('status'), 'API status. Body: ' . $response->getContent());
        $this->assertStringContainsString($contains, implode(' ', (array)$response->json('data.form')));
    }

    private function liveTokens(int $userId)
    {
        return Token::where('user_id', $userId)->where('revoked', false)->count();
    }

    // ── 1-3: who can sign in ─────────────────────────────────────────────

    public function test_a_verified_customer_signs_in()
    {
        $response = $this->signIn(Fixture::email(Fixture::USER_PERSONAL_ID), Fixture::PASSWORD);

        $this->assertApiOk($response);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_a_wrong_password_an_unknown_address_and_an_unconfirmed_sign_up_all_read_the_same()
    {
        $answers = [
            'wrong password' => $this->signIn(Fixture::email(Fixture::USER_PERSONAL_ID), 'Not-The-Password-1'),
            'unknown address' => $this->signIn('nobody-' . uniqid() . '@fixture.test', Fixture::PASSWORD),
            'unconfirmed, wrong password' => $this->signIn(Fixture::email(Fixture::USER_UNVERIFIED_ID), 'Not-The-Password-1'),
        ];

        foreach ($answers as $case => $response) {
            $this->assertApiRefused($response, 201, 'Wrong email/password');
            $this->assertNull($response->json('data.token'), $case);
            $this->assertNull($response->json('data.verification_pending'), "$case: says nothing about a pending sign-up");
        }
    }

    // ── 4: unconfirmed sign-ups ──────────────────────────────────────────

    public function test_the_person_who_signed_up_is_told_the_email_is_not_confirmed_yet()
    {
        $response = $this->signIn(Fixture::email(Fixture::USER_UNVERIFIED_ID), Fixture::PASSWORD);

        $this->assertApiRefused($response, 201, 'not confirmed your email address');
        $this->assertTrue($response->json('data.verification_pending'));
        $this->assertNull($response->json('data.token'), 'still not signed in');
    }

    public function test_a_new_code_is_sent_to_an_unconfirmed_sign_up_with_its_password()
    {
        $email = Fixture::email(Fixture::USER_UNVERIFIED_ID);
        $before = User::where('email', $email)->first();

        $this->assertApiOk($this->postJson('/api/v1/user/verify/resend', ['email' => $email, 'password' => Fixture::PASSWORD]));

        $after = $before->fresh();
        $this->assertNotEmpty($after->code);
        $this->assertTrue($after->code_expires_at->isFuture());
        $this->assertSame(0, (int)$after->code_attempts);
        $this->assertSame($before->password, $after->password, 'nothing but the code changes');
        $this->assertSame(1, User::where('email', $email)->count(), 'no second account');
        $this->assertCount(1, $this->mailsTo($email));

        // asking again straight away is refused
        $this->assertApiRefused($this->postJson('/api/v1/user/verify/resend', ['email' => $email, 'password' => Fixture::PASSWORD]), 201, 'less than a minute');
    }

    public function test_a_new_code_is_refused_alike_for_a_wrong_password_a_confirmed_account_or_an_unknown_address()
    {
        $unverified = User::findOrFail(Fixture::USER_UNVERIFIED_ID);
        $codeBefore = $unverified->code;

        $cases = [
            [Fixture::email(Fixture::USER_UNVERIFIED_ID), 'Not-The-Password-1'],
            [Fixture::email(Fixture::USER_PERSONAL_ID), Fixture::PASSWORD],
            ['nobody-' . uniqid() . '@fixture.test', Fixture::PASSWORD],
        ];

        foreach ($cases as [$email, $password]) {
            $this->assertApiRefused(
                $this->postJson('/api/v1/user/verify/resend', ['email' => $email, 'password' => $password]),
                201, 'We could not send a code for those details'
            );
        }

        $this->assertSame($codeBefore, $unverified->fresh()->code);
        $this->assertCount(0, $this->mails()->messages(), 'nothing was sent');
    }

    // ── 5: codes ─────────────────────────────────────────────────────────

    public function test_a_code_with_the_signup_password_confirms_the_account_and_signs_in()
    {
        $email = 'new-' . uniqid() . '@fixture.test';
        $this->assertApiOk($this->register($email, 'Signup-Pass-123'));

        $user = User::where('email', $email)->first();
        $this->assertSame(0, (int)$user->verified);

        $response = $this->verify($email, $user->code, 'Signup-Pass-123');

        $this->assertApiOk($response);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(1, (int)$user->fresh()->verified);
        $this->assertNull($user->fresh()->code, 'the code is spent');

        // and the account now signs in normally
        $this->assertApiOk($this->signIn($email, 'Signup-Pass-123'));
    }

    public function test_a_code_without_the_password_is_refused()
    {
        $email = 'new-' . uniqid() . '@fixture.test';
        $this->assertApiOk($this->register($email, 'Signup-Pass-123'));
        $user = User::where('email', $email)->first();

        $this->assertFormRefused($this->verify($email, $user->code, null), 'Enter the password you chose');
        $this->assertSame(0, (int)$user->fresh()->verified);
    }

    public function test_an_expired_code_is_refused_and_says_so()
    {
        $email = 'new-' . uniqid() . '@fixture.test';
        $this->assertApiOk($this->register($email, 'Signup-Pass-123'));
        $user = User::where('email', $email)->first();
        User::where('id', $user->id)->update(['code_expires_at' => now()->subMinute()]);

        $this->assertApiRefused($this->verify($email, $user->code, 'Signup-Pass-123'), 201, 'That code has expired');
        $this->assertSame(0, (int)$user->fresh()->verified);

        // a new code then works
        $this->assertApiOk($this->postJson('/api/v1/user/verify/resend', ['email' => $email, 'password' => 'Signup-Pass-123']));
        $this->assertApiOk($this->verify($email, $user->fresh()->code, 'Signup-Pass-123'));
    }

    public function test_five_wrong_codes_burn_the_code()
    {
        $email = 'new-' . uniqid() . '@fixture.test';
        $this->assertApiOk($this->register($email, 'Signup-Pass-123'));
        $user = User::where('email', $email)->first();
        $wrong = $user->code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < User::MAX_CODE_ATTEMPTS; $i++) {
            $this->assertApiRefused($this->verify($email, $wrong, 'Signup-Pass-123'), 201);
        }

        $this->assertApiRefused($this->verify($email, $user->code, 'Signup-Pass-123'), 201, 'Too many incorrect codes');
        $this->assertNull($user->fresh()->code);
        $this->assertSame(0, (int)$user->fresh()->verified);
    }

    // ── 6: nobody overwrites somebody else's pending sign-up ─────────────

    public function test_a_live_pending_sign_up_cannot_be_replaced_with_a_different_password()
    {
        $email = 'victim-' . uniqid() . '@fixture.test';
        $this->assertApiOk($this->register($email, 'Victim-Pass-123', ['name' => 'Vic Victim']));
        $victim = User::where('email', $email)->first();

        $this->assertApiRefused(
            $this->register($email, 'Attacker-Pass-123', ['name' => 'Ann Attacker']),
            201, 'waiting to be confirmed'
        );

        $row = $victim->fresh();
        $this->assertTrue(Hash::check('Victim-Pass-123', $row->password), 'password untouched');
        $this->assertSame('Vic Victim', $row->name);
        $this->assertSame($victim->code, $row->code, 'code untouched');
        $this->assertSame(1, User::where('email', $email)->count());
    }

    public function test_the_owner_can_restart_their_own_sign_up_with_the_same_password()
    {
        $email = 'owner-' . uniqid() . '@fixture.test';
        $this->assertApiOk($this->register($email, 'Owner-Pass-123', ['name' => 'First Try']));

        $this->assertApiOk($this->register($email, 'Owner-Pass-123', ['name' => 'Second Try']));

        $row = User::where('email', $email)->first();
        $this->assertSame('Second Try', $row->name);
        $this->assertSame(1, User::where('email', $email)->count());
        $this->assertApiOk($this->verify($email, $row->code, 'Owner-Pass-123'));
    }

    public function test_details_swapped_in_after_expiry_cannot_be_confirmed_by_the_owner_of_the_address()
    {
        $email = 'victim-' . uniqid() . '@fixture.test';
        $this->assertApiOk($this->register($email, 'Victim-Pass-123'));
        $victim = User::where('email', $email)->first();

        // the victim's code lapses; somebody else then signs up with the address
        User::where('id', $victim->id)->update(['code_expires_at' => now()->subMinute()]);
        $this->assertApiOk($this->register($email, 'Attacker-Pass-123', ['name' => 'Ann Attacker']));
        $attackerCode = $victim->fresh()->code;

        // the victim types in the code that arrived, with their own password
        $this->assertApiRefused($this->verify($email, $attackerCode, 'Victim-Pass-123'), 201, 'changed after your code was sent');

        $row = $victim->fresh();
        $this->assertSame(0, (int)$row->verified, 'the swapped details were not confirmed');
        $this->assertNull($row->code, 'and that code can no longer confirm them');
        $this->assertApiRefused($this->verify($email, $attackerCode, 'Attacker-Pass-123'), 201);

        // the victim simply starts again
        $this->assertApiOk($this->register($email, 'Victim-Pass-123', ['name' => 'Vic Victim']));
        $this->assertApiOk($this->verify($email, $victim->fresh()->code, 'Victim-Pass-123'));
        $this->assertTrue(Hash::check('Victim-Pass-123', $victim->fresh()->password));
    }

    // ── 6 (rules): one password rule everywhere ──────────────────────────

    public function test_the_legacy_signup_path_and_the_registration_form_both_need_a_strong_password()
    {
        foreach (['short1A', 'nocapitals123', 'NoNumbersHere'] as $weak) {
            $this->asGuest();
            $legacy = $this->postJson('/api/v1/user/signup', ['name' => 'Leo Legacy', 'email' => 'legacy-' . uniqid() . '@fixture.test', 'password' => $weak]);
            $this->assertFormRefused($legacy, 'Use at least 10 characters');

            $this->assertFormRefused($this->register('form-' . uniqid() . '@fixture.test', $weak), 'Use at least 10 characters');
        }
    }

    public function test_the_change_password_form_needs_a_strong_password()
    {
        $response = $this->asCustomer(Fixture::USER_PERSONAL_ID)->postJson('/api/v1/user/update-user-password', [
            'current_password' => Fixture::PASSWORD,
            'new_password' => 'weakpass',
        ]);

        $this->assertFormRefused($response, 'Use at least 10 characters');
        $this->assertTrue(Hash::check(Fixture::PASSWORD, User::find(Fixture::USER_PERSONAL_ID)->password));
    }

    // ── 7-9: forgot / reset ──────────────────────────────────────────────

    public function test_a_registered_customer_resets_their_password_from_the_emailed_link()
    {
        $email = Fixture::email(Fixture::USER_PERSONAL_ID);

        $this->asGuest();
        $this->assertApiOk($this->postJson('/api/v1/user/forgot-password', ['email' => $email]));

        $mails = $this->mailsTo($email);
        $this->assertCount(1, $mails);
        $this->assertSame(1, preg_match('/token=([A-Za-z0-9]{64})/', $mails[0]->getBody(), $m), 'the mail carries the link');
        $token = $m[1];

        $this->assertNotSame($token, PasswordResetToken::where('email', $email)->latest('id')->value('token'), 'stored hashed');

        $this->assertApiOk($this->postJson('/api/v1/user/reset-password/check', ['token' => $token, 'email' => $email]));
        $this->assertApiOk($this->postJson('/api/v1/user/reset-password', ['token' => $token, 'email' => $email, 'password' => self::NEW_PASSWORD]));

        $this->assertApiOk($this->signIn($email, self::NEW_PASSWORD));
        $this->assertApiRefused($this->signIn($email, Fixture::PASSWORD), 201, 'Wrong email/password');
    }

    public function test_a_reset_needs_a_strong_password()
    {
        $email = Fixture::email(Fixture::USER_PERSONAL_ID);
        $token = PasswordResetToken::issueFor($email);

        $this->asGuest();
        $this->assertFormRefused($this->postJson('/api/v1/user/reset-password', ['token' => $token, 'email' => $email, 'password' => 'weakpass1']), 'Use at least 10 characters');
        $this->assertTrue(Hash::check(Fixture::PASSWORD, User::find(Fixture::USER_PERSONAL_ID)->password));
    }

    public function test_an_unregistered_or_unconfirmed_address_is_told_to_register_and_gets_no_link()
    {
        $this->asGuest();

        foreach (['nobody-' . uniqid() . '@fixture.test', Fixture::email(Fixture::USER_UNVERIFIED_ID)] as $email) {
            $usersBefore = User::count();

            $response = $this->postJson('/api/v1/user/forgot-password', ['email' => $email]);

            $this->assertFormRefused($response, 'No account found with this email address. Please register first and then log in.');
            $this->assertCount(0, $this->mailsTo($email), "no mail to $email");
            $this->assertSame(0, PasswordResetToken::where('email', $email)->count(), "no link issued for $email");
            $this->assertSame($usersBefore, User::count(), 'no account created');
        }

        // and a made-up token changes nothing
        $this->assertApiRefused($this->postJson('/api/v1/user/reset-password', [
            'token' => str_repeat('a', 64), 'email' => 'nobody@fixture.test', 'password' => self::NEW_PASSWORD,
        ]), 201, 'expired or has already been used');
    }

    public function test_a_used_or_expired_link_cannot_change_the_password()
    {
        $email = Fixture::email(Fixture::USER_PERSONAL_ID);
        $this->asGuest();

        $used = PasswordResetToken::issueFor($email);
        $this->assertApiOk($this->postJson('/api/v1/user/reset-password', ['token' => $used, 'email' => $email, 'password' => self::NEW_PASSWORD]));
        $this->assertApiRefused($this->postJson('/api/v1/user/reset-password', ['token' => $used, 'email' => $email, 'password' => 'Another-Pass-99']), 201, 'expired or has already been used');
        $this->assertApiRefused($this->postJson('/api/v1/user/reset-password/check', ['token' => $used, 'email' => $email]), 201);

        $expired = PasswordResetToken::issueFor($email);
        PasswordResetToken::where('email', $email)->whereNull('used_at')->update(['expires_at' => now()->subMinute()]);
        $this->assertApiRefused($this->postJson('/api/v1/user/reset-password', ['token' => $expired, 'email' => $email, 'password' => 'Another-Pass-99']), 201, 'expired or has already been used');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, User::find(Fixture::USER_PERSONAL_ID)->password), 'only the first, live link took effect');
    }

    // ── 10: password changes end other sessions ──────────────────────────

    public function test_a_reset_signs_out_every_session()
    {
        $user = User::findOrFail(Fixture::USER_PERSONAL_ID);
        $phone = $user->createToken('user', ['user'])->accessToken;
        $user->createToken('test', ['user']);
        $this->assertGreaterThan(0, $this->liveTokens($user->id));

        $token = PasswordResetToken::issueFor($user->email);
        $this->asGuest();
        $this->assertApiOk($this->postJson('/api/v1/user/reset-password', ['token' => $token, 'email' => $user->email, 'password' => self::NEW_PASSWORD]));

        $this->assertSame(0, $this->liveTokens($user->id), 'every token revoked, whatever its name');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $phone])->getJson('/api/v1/user/profile')->assertStatus(401);
    }

    public function test_changing_the_password_signs_out_the_other_devices()
    {
        $user = User::findOrFail(Fixture::USER_PERSONAL_ID);
        $laptop = $user->createToken('user', ['user']);
        $phone = $user->createToken('user', ['user']);

        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $laptop->accessToken])
            ->postJson('/api/v1/user/update-user-password', [
                'current_password' => Fixture::PASSWORD,
                'new_password' => self::NEW_PASSWORD,
            ]);

        $this->assertApiOk($response);
        $this->assertTrue((bool)Token::find($phone->token->id)->revoked, 'the other device is signed out');
        $this->assertFalse((bool)Token::find($laptop->token->id)->revoked, 'the browser that changed it signs itself out');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $phone->accessToken])->getJson('/api/v1/user/profile')->assertStatus(401);
    }

    public function test_the_old_update_password_endpoint_is_gone()
    {
        $user = User::findOrFail(Fixture::USER_UNVERIFIED_ID);

        $this->asGuest();
        $response = $this->postJson('/api/v1/user/update-password', [
            'email' => $user->email, 'code' => (string)$user->code, 'password' => 'Hijack-Pass-123',
        ]);

        // No POST route: 405, because another user/... route answers that path for GET.
        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertFalse(Hash::check('Hijack-Pass-123', $user->fresh()->password));
    }

    // ── 11: social sign-in is switched off ───────────────────────────────

    public function test_social_sign_in_signs_nobody_in_and_puts_nothing_in_the_url()
    {
        $suspended = User::findOrFail(Fixture::USER_SUSPENDED_ID);
        $tokensBefore = Token::count();
        $usersBefore = User::count();

        $this->asGuest();
        foreach (['/api/v1/user/social-login/redirect/google', '/api/v1/user/social-login/callback/google?code=x&state=y'] as $url) {
            $response = $this->get($url);

            $response->assertRedirect();
            $location = $response->headers->get('Location');
            $this->assertStringEndsWith('/login', $location, $url);
            $this->assertStringNotContainsString('token', $location);
            $this->assertStringNotContainsString($suspended->email, $location);
        }

        $this->assertSame($tokensBefore, Token::count(), 'no session was issued');
        $this->assertSame($usersBefore, User::count(), 'no account was created');
    }

    // ── 12: two-factor still applies ─────────────────────────────────────

    public function test_two_factor_still_stands_between_the_password_and_a_session()
    {
        $user = User::findOrFail(Fixture::USER_PERSONAL_ID);
        $secret = TotpHelper::generateSecret();
        User::where('id', $user->id)->update([
            'two_factor_method' => 'app',
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $first = $this->signIn($user->email, Fixture::PASSWORD);
        $this->assertApiOk($first);
        $this->assertTrue($first->json('data.two_factor_required'));
        $this->assertNull($first->json('data.token'), 'the password alone is not a session');

        $this->asGuest();
        $wrong = TotpHelper::codeAt($secret) === '000000' ? '111111' : '000000';
        $this->assertApiRefused($this->postJson('/api/v1/user/two-factor/challenge', ['challenge' => $first->json('data.challenge'), 'code' => $wrong]), 201);

        $this->asGuest();
        $second = $this->postJson('/api/v1/user/two-factor/challenge', ['challenge' => $first->json('data.challenge'), 'code' => TotpHelper::codeAt($secret)]);
        $this->assertApiOk($second);
        $this->assertNotEmpty($second->json('data.token'));
    }

    // ── sign-in code: no account discovery ───────────────────────────────

    public function test_asking_for_a_sign_in_code_reads_the_same_with_or_without_an_account()
    {
        $this->asGuest();

        $known = $this->postJson('/api/v1/user/login-code', ['email' => Fixture::email(Fixture::USER_PERSONAL_ID)]);
        $unknown = $this->postJson('/api/v1/user/login-code', ['email' => 'nobody-' . uniqid() . '@fixture.test']);
        $unconfirmed = $this->postJson('/api/v1/user/login-code', ['email' => Fixture::email(Fixture::USER_UNVERIFIED_ID)]);

        foreach ([$known, $unknown, $unconfirmed] as $response) {
            $this->assertApiOk($response);
            $this->assertSame(['email', 'expires_in_minutes'], array_keys($response->json('data')));
        }

        $this->assertCount(1, $this->mails()->messages(), 'a code went to the account only');
        $this->assertCount(1, $this->mailsTo(Fixture::email(Fixture::USER_PERSONAL_ID)));
    }
}
