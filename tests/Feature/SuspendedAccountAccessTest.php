<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Helper\AccountStatus;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\TestCase;

/**
 * What a suspended account can and cannot do.
 *
 * Suspension used to be a pricing and checkout rule only: the customer could
 * still sign in, and an open session carried on working until its token expired
 * — twelve months here. Now it revokes every token the account holds and every
 * way into a session refuses it.
 *
 * The distinction these tests hold down is between the two ladders. Account
 * status suspended means no access at all. Trade status suspended means trade
 * pricing is withdrawn and nothing else — that customer still signs in and
 * still buys at retail.
 */
class SuspendedAccountAccessTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Abcdefghij1';

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Suspension Fixture',
            'email' => 'susp.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt(self::PASSWORD),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_NOT_REQUIRED,
        ], $attributes))->fresh();
    }

    private function signIn($user)
    {
        return $this->postJson('/api/v1/user/signin', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);
    }

    /** Sign in for real and hand back the bearer token that came out. */
    private function tokenFor($user)
    {
        $response = $this->signIn($user);

        $response->assertStatus(200);

        $token = $response->json('data.token');

        $this->assertNotEmpty($token, 'Sign-in did not return a token.');

        return $token;
    }

    /*
     * Guards are resolved once per application instance, not once per request,
     * so a user resolved by an earlier call in the same test is still sitting
     * on the guard for the next one. Forgetting them is what makes each call
     * behave like the separate HTTP request it is meant to represent.
     */
    private function asToken($token)
    {
        /*
         * flushHeaders() as well as forgetGuards(): withHeaders() accumulates
         * onto the test case, so without it a later "guest" call still carries
         * the bearer from an earlier one and reads as refused for the wrong
         * reason.
         */
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    /** A call carrying no credentials at all. */
    private function asGuest()
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /**
     * A customer request that was turned away, however it was turned away.
     *
     * Two shapes count, and both are correct:
     *
     *  - Passport rejects a revoked token before any of this code runs, which
     *    the app renders as HTTP 201 carrying `status: 204, "Unauthenticated."`
     *  - A token that survived revocation reaches EnsureAccountIsActive, which
     *    answers HTTP 401 with the customer-facing suspension sentence.
     *
     * Asserting on the HTTP code alone would pass against a wide-open route,
     * because the refusal for the first shape is HTTP 201.
     */
    private function assertRefused($response, $context = '')
    {
        $http = $response->getStatusCode();
        $bodyStatus = $response->json('status');

        $refused = $http === 401
            || $bodyStatus === 204
            || $bodyStatus === 401;

        $this->assertTrue($refused,
            trim("{$context} Expected a refusal, got HTTP {$http} with body status "
                . var_export($bodyStatus, true) . '.'));

        // Whatever the shape, no account data comes back with it.
        $this->assertNull($response->json('data.email'), "{$context} No account detail may be returned.");
    }

    private function suspend($user, $note = 'Payment dispute under review')
    {
        $error = AccountStatus::applyAccount($user, User::STATUS_SUSPENDED, $note);

        $this->assertNull($error, 'Suspension was refused: ' . $error);

        return $user->fresh();
    }

    private function liveTokenCount($user)
    {
        return Token::where('user_id', $user->id)->where('revoked', false)->count();
    }

    // ---- sign-in is refused --------------------------------------------------

    public function test_an_active_account_can_sign_in()
    {
        $user = $this->customer();

        $response = $this->signIn($user);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_a_suspended_account_cannot_sign_in()
    {
        $user = $this->customer();

        $this->tokenFor($user);

        $this->suspend($user);

        $response = $this->signIn($user);

        $this->assertSame(201, $response->json('status'), 'Sign-in should be refused.');
        $this->assertSame(AccountStatus::SUSPENDED_MESSAGE, $response->json('message'));
        // Nothing was issued.
        $this->assertNull($response->json('data.token'));
    }

    public function test_the_refusal_says_nothing_about_why_the_account_was_suspended()
    {
        $user = $this->customer();

        $this->suspend($user, 'INTERNAL: chargeback ring, see ticket 4471');

        $body = $this->signIn($user)->getContent();

        $this->assertStringNotContainsString('chargeback', $body);
        $this->assertStringNotContainsString('4471', $body);
        $this->assertStringNotContainsString('INTERNAL', $body);
    }

    public function test_a_rejected_account_cannot_sign_in()
    {
        $user = $this->customer(['status' => User::STATUS_PENDING]);

        AccountStatus::applyAccount($user, User::STATUS_REJECTED);

        $response = $this->signIn($user->fresh());

        $this->assertSame(201, $response->json('status'));
        $this->assertNull($response->json('data.token'));
    }

    /**
     * A wrong password on a suspended account must answer like a wrong password
     * on any other, or the response tells an attacker which addresses exist and
     * what state they are in.
     */
    public function test_a_wrong_password_on_a_suspended_account_does_not_reveal_the_suspension()
    {
        $user = $this->customer();

        $this->suspend($user);

        $response = $this->postJson('/api/v1/user/signin', [
            'email' => $user->email,
            'password' => 'NotThePassword9',
        ]);

        $this->assertNotSame(AccountStatus::SUSPENDED_MESSAGE, $response->json('message'));
    }

    // ---- existing sessions die ------------------------------------------------

    /*
     * /user/profile sits on the optional-auth group: it answers guests too,
     * with is_logged_in false. So the thing to assert there is that the
     * suspended customer is no longer recognised, not that the call 401s.
     * The genuinely protected routes are checked below.
     */
    public function test_suspension_revokes_the_token_the_customer_is_holding()
    {
        $user = $this->customer();

        $token = $this->tokenFor($user);

        // It works while the account is active.
        $this->asToken($token)->getJson('/api/v1/user/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.is_logged_in', true);

        $this->suspend($user);

        $response = $this->asToken($token)->getJson('/api/v1/user/profile');

        $this->assertNotTrue($response->json('data.is_logged_in'),
            'A revoked token must not still identify the customer.');
        $this->assertNull($response->json('data.email'),
            'No account detail may come back for a revoked token.');
    }

    public function test_a_revoked_token_cannot_reach_a_protected_route()
    {
        $user = $this->customer();

        $token = $this->tokenFor($user);

        $this->asToken($token)->getJson('/api/v1/user/two-factor/status')->assertStatus(200);

        $this->suspend($user);

        $this->assertRefused($this->asToken($token)->getJson("/api/v1/user/two-factor/status"),
            "A revoked token must not reach a protected route.");
    }

    public function test_suspension_revokes_every_session_not_just_the_current_one()
    {
        $user = $this->customer();

        // Three sign-ins: three devices.
        $chrome = $this->tokenFor($user);
        $firefox = $this->tokenFor($user);
        $mobile = $this->tokenFor($user);

        $this->assertSame(3, $this->liveTokenCount($user));

        $this->suspend($user);

        $this->assertSame(0, $this->liveTokenCount($user),
            'Every token must be revoked, not only the one in use.');

        foreach ([$chrome, $firefox, $mobile] as $token) {
            $this->assertRefused($this->asToken($token)->getJson("/api/v1/user/two-factor/status"),
                "Every device must lose access, not only the one in use.");
        }
    }

    public function test_suspension_marks_the_device_sessions_inactive()
    {
        $user = $this->customer();

        $this->tokenFor($user);
        $this->tokenFor($user);

        $this->assertSame(2, UserSession::where('user_id', $user->id)->where('is_active', true)->count());

        $this->suspend($user);

        $this->assertSame(0, UserSession::where('user_id', $user->id)->where('is_active', true)->count());
    }

    public function test_suspension_clears_the_remember_me_token()
    {
        $user = $this->customer();

        $user->forceFill(['remember_token' => 1])->save();

        $this->suspend($user);

        $this->assertSame(0, (int)$user->fresh()->remember_token,
            'A remember-me cookie must not sign a suspended account back in.');
    }

    // ---- protected endpoints refuse -------------------------------------------

    /**
     * The middleware is the second line: a token that somehow escaped
     * revocation must still not work. Acting as the user directly is how a
     * surviving token behaves.
     */
    public function test_a_surviving_token_is_still_refused_by_the_middleware()
    {
        $user = $this->customer();

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        Passport::actingAs($user->fresh(), ['user'], 'user');

        $response = $this->getJson('/api/v1/user/two-factor/status');

        $response->assertStatus(401);
        $this->assertSame(AccountStatus::SUSPENDED_MESSAGE, $response->json('message'));
    }

    public function test_a_suspended_account_is_refused_on_every_protected_customer_route()
    {
        $user = $this->customer();

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        foreach ([
            ['get', '/api/v1/user/two-factor/status'],
            ['get', '/api/v1/user/loyalty-discount'],
            ['get', '/api/v1/user/recently-viewed'],
            ['get', '/api/v1/user/compare-list/all'],
            ['post', '/api/v1/user/update-profile'],
            ['post', '/api/v1/user/update-user-password'],
        ] as [$method, $route]) {

            // A fresh identity per call: the middleware revokes on the way past.
            $this->app['auth']->forgetGuards();
            Passport::actingAs($user->fresh(), ['user'], 'user');

            $response = $this->{$method . 'Json'}($route);

            $this->assertSame(401, $response->getStatusCode(),
                "{$route} must refuse a suspended account.");
            $this->assertSame(AccountStatus::SUSPENDED_MESSAGE, $response->json('message'),
                "{$route} must give the customer-facing reason.");
        }
    }

    /**
     * Requirement in its own right: the reason a reviewer wrote down is a staff
     * remark and must not travel out with the refusal.
     */
    public function test_the_middleware_refusal_carries_no_internal_note()
    {
        $user = $this->customer();

        $this->suspend($user, 'INTERNAL: chargeback ring, ticket 4471');

        $this->app['auth']->forgetGuards();
        Passport::actingAs($user->fresh(), ['user'], 'user');

        $body = $this->getJson('/api/v1/user/two-factor/status')->getContent();

        $this->assertStringNotContainsString('chargeback', $body);
        $this->assertStringNotContainsString('4471', $body);
    }

    public function test_reaching_a_protected_route_revokes_what_is_left()
    {
        $user = $this->customer();

        $token = $this->tokenFor($user);

        // Suspend the column only, leaving the token alive, as though
        // revocation had not run.
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertSame(1, $this->liveTokenCount($user));

        $this->asToken($token)->getJson('/api/v1/user/two-factor/status');

        $this->assertSame(0, $this->liveTokenCount($user),
            'The middleware should revoke a token it finds on a blocked account.');
    }

    /**
     * The same clean-up on the public group: a surviving token seen there is
     * revoked too, so browsing does not leave a working key in play.
     */
    public function test_the_public_group_also_revokes_a_surviving_token()
    {
        $user = $this->customer();

        $token = $this->tokenFor($user);

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $response = $this->asToken($token)->getJson('/api/v1/user/profile');

        $response->assertStatus(401);
        $this->assertSame(AccountStatus::SUSPENDED_MESSAGE, $response->json('message'));
        $this->assertTrue($response->json('session_ended'));
        $this->assertSame(0, $this->liveTokenCount($user));
    }

    /**
     * The symptom that started this: the page kept its signed-in shell over a
     * dead session because nothing it called ever said so. Most refusals here
     * answer HTTP 201 and put the outcome in the body, which the browser's
     * HTTP client reads as success — so the answer has to be a real 401.
     */
    public function test_a_dead_session_is_reported_as_401_on_the_public_routes()
    {
        $user = $this->customer();

        $token = $this->tokenFor($user);

        $this->suspend($user);

        foreach ([
            '/api/v1/user/profile',
            '/api/v1/sessions',
            '/api/v1/cart/by-user',
            '/api/v1/user/address/all',
        ] as $route) {

            $response = $this->asToken($token)->getJson($route);

            $this->assertSame(401, $response->getStatusCode(),
                "{$route} must answer 401 so the browser can act on it.");
            $this->assertTrue($response->json('session_ended'),
                "{$route} must carry the session_ended flag.");
            $this->assertNotEmpty($response->json('message'),
                "{$route} must say something the customer can read.");
        }
    }

    // ---- and nobody else is disturbed --------------------------------------------

    /**
     * A bearer we cannot identify is not evidence of anything.
     *
     * The first version of the "your session ended" answer fired whenever a
     * bearer failed to resolve, which meant a stale "Bearer null" or "Bearer
     * undefined" — routine from a client that has lost its session — signed
     * the visitor out mid-browse. Only a token we issued, and have since
     * revoked or let expire, counts.
     */
    public function test_an_unrecognisable_bearer_does_not_sign_anybody_out()
    {
        foreach ([
            'Bearer null',
            'Bearer undefined',
            'Bearer abc123',
            'Bearer ',
            'null',
            'Bearer not.a.jwt',
            'Bearer aaa.bbb.ccc',
        ] as $header) {

            $this->flushHeaders();
            $this->app['auth']->forgetGuards();

            $response = $this->withHeaders(['Authorization' => $header])
                ->getJson('/api/v1/products?limit=1');

            $this->assertNotSame(401, $response->getStatusCode(),
                "'{$header}' is not a token of ours and must not end anyone's session.");
        }
    }

    /**
     * Passport strips the Authorization header off the request when it refuses
     * a token, so the bearer has to be read before the guard runs. Reading it
     * afterwards found nothing and the suspended customer was quietly treated
     * as a guest instead of being told.
     */
    public function test_a_revoked_token_is_recognised_after_the_guard_has_rejected_it()
    {
        $user = $this->customer();

        $token = $this->tokenFor($user);

        $this->suspend($user);

        $response = $this->asToken($token)->getJson('/api/v1/user/profile');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertTrue($response->json('session_ended'));
    }

    public function test_a_guest_carrying_no_token_is_unaffected()
    {
        // A guest sends a guest token, never a bearer, so the new refusal
        // must not touch them.
        $response = $this->asGuest()->getJson('/api/v1/user/profile');

        $this->assertSame(200, $response->getStatusCode(),
            'Guest browsing must not be refused.');
        $this->assertNotTrue($response->json('data.is_logged_in'));
    }

    public function test_an_active_customer_is_unaffected_by_the_new_refusal()
    {
        $user = $this->customer();

        $token = $this->tokenFor($user);

        $response = $this->asToken($token)->getJson('/api/v1/user/profile');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_logged_in'));
        $this->assertSame($user->email, $response->json('data.email'));
    }

    public function test_a_guest_can_still_browse_after_a_suspension_elsewhere()
    {
        $user = $this->customer();

        $this->tokenFor($user);
        $this->suspend($user);

        $this->assertSame(200,
            $this->asGuest()->getJson('/api/v1/user/profile')->getStatusCode());
    }

    public function test_a_suspended_account_cannot_place_an_order()
    {
        $user = $this->customer();

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertFalse($user->fresh()->canCheckout());
        $this->assertFalse($user->fresh()->hasBusinessPricing());
    }

    // ---- reactivation ----------------------------------------------------------

    public function test_reactivation_lets_the_customer_sign_in_again()
    {
        $user = $this->customer();

        $this->suspend($user);
        $this->signIn($user->fresh())->assertJsonPath('status', 201);

        AccountStatus::applyAccount($user->fresh(), User::STATUS_ACTIVE);

        $response = $this->signIn($user->fresh());

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_reactivation_does_not_bring_the_old_tokens_back()
    {
        $user = $this->customer();

        $old = $this->tokenFor($user);

        $this->suspend($user);

        AccountStatus::applyAccount($user->fresh(), User::STATUS_ACTIVE);

        // The account works again...
        $this->signIn($user->fresh())->assertStatus(200);

        // ...but the key from before the suspension does not.
        $this->assertRefused($this->asToken($old)->getJson("/api/v1/user/two-factor/status"),
            "A token revoked by suspension must stay revoked after reactivation.");

        $this->assertSame(1, $this->liveTokenCount($user),
            'Only the token from the fresh sign-in should be live.');
    }

    // ---- account suspension is not trade suspension -----------------------------

    public function test_a_suspended_trade_status_does_not_stop_the_customer_signing_in()
    {
        $user = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
            'verification_status' => User::VERIFICATION_PENDING,
        ]);

        AccountStatus::applyTrade($user, User::TRADE_APPROVED);
        AccountStatus::applyTrade($user->fresh(), User::TRADE_SUSPENDED);

        $user = $user->fresh();

        $this->assertSame(User::TRADE_SUSPENDED, $user->trade_status);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);

        // Signing in still works: the account is active, only the pricing went.
        $this->signIn($user)->assertStatus(200);
        $this->assertFalse($user->hasBusinessPricing());
        $this->assertTrue($user->canCheckout());
    }

    public function test_withdrawing_trade_pricing_does_not_revoke_any_session()
    {
        $user = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
            'verification_status' => User::VERIFICATION_PENDING,
        ]);

        $token = $this->tokenFor($user);

        AccountStatus::applyTrade($user->fresh(), User::TRADE_APPROVED);
        AccountStatus::applyTrade($user->fresh(), User::TRADE_SUSPENDED);

        $this->assertSame(1, $this->liveTokenCount($user));
        $this->asToken($token)->getJson('/api/v1/user/two-factor/status')->assertStatus(200);
    }

    // ---- other customers are untouched -------------------------------------------

    public function test_suspending_one_customer_does_not_sign_out_another()
    {
        $alice = $this->customer();
        $bob = $this->customer();

        $aliceToken = $this->tokenFor($alice);
        $bobToken = $this->tokenFor($bob);

        $this->suspend($alice);

        $this->assertSame(0, $this->liveTokenCount($alice));
        $this->assertSame(1, $this->liveTokenCount($bob),
            "Suspending Alice must not touch Bob's session.");

        $this->asToken($bobToken)->getJson('/api/v1/user/profile')->assertStatus(200);
        $this->signIn($bob->fresh())->assertStatus(200);
    }

    public function test_an_active_business_account_is_unaffected_by_another_suspension()
    {
        $suspended = $this->customer();
        $business = $this->customer([
            'account_type' => 'business',
            'verification_status' => User::VERIFICATION_PENDING,
        ]);

        $this->suspend($suspended);

        $this->signIn($business->fresh())->assertStatus(200);
        $this->assertTrue($business->fresh()->hasBusinessPricing());
    }

    // ---- admin route drives the same thing ----------------------------------------

    public function test_the_admin_suspend_action_revokes_the_customers_sessions()
    {
        $customer = $this->customer();

        $this->tokenFor($customer);
        $this->tokenFor($customer);

        $this->assertSame(2, $this->liveTokenCount($customer));

        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        $this->app['auth']->forgetGuards();
        Passport::actingAs($admin, ['admin'], 'admin');

        $this->postJson("/api/admin/user/status/{$customer->id}", [
            'status' => User::STATUS_SUSPENDED,
            'status_note' => 'Suspended from the admin screen',
        ])->assertStatus(200);

        $this->assertSame(User::STATUS_SUSPENDED, $customer->fresh()->status);
        $this->assertSame(0, $this->liveTokenCount($customer),
            'Suspending from the admin screen must end the customer sessions too.');

        $this->app['auth']->forgetGuards();

        $this->signIn($customer->fresh())->assertJsonPath('status', 201);
    }

    public function test_the_headline_no_longer_promises_a_suspended_customer_can_sign_in()
    {
        $user = $this->customer();

        $this->suspend($user);

        $headline = $user->fresh()->accountHeadline();

        $this->assertStringNotContainsString('still sign in', $headline);
        $this->assertStringContainsString('cannot be used to sign in', $headline);
        // And still no internal note.
        $this->assertStringNotContainsString('Payment dispute', $headline);
    }
}
