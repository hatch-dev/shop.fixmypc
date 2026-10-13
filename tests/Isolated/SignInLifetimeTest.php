<?php

namespace Tests\Isolated;

use App\Models\LoginCode;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\IsolatedTestCase;

/**
 * How long a customer stays signed in (2026-10-13).
 *
 * "Keep me signed in" ticked: 30 days. Unticked: a day. Both used to be
 * twelve months, and the flag was read under a name the login page never
 * sent, so a browser signed in weeks earlier went on showing that account's
 * basket to whoever used it next.
 */
class SignInLifetimeTest extends IsolatedTestCase
{
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::findOrFail(Fixture::USER_PERSONAL_ID);
        DB::table('oauth_access_tokens')->where('user_id', $this->user->id)->delete();
    }

    /** Hours until the newest customer token expires. */
    private function hoursLeft()
    {
        $token = DB::table('oauth_access_tokens')
            ->where('user_id', $this->user->id)
            ->where('scopes', 'like', '%"user"%')
            ->orderByDesc('created_at')->orderByDesc('expires_at')
            ->first();

        $this->assertNotNull($token, 'a token was issued');

        return Carbon::parse(date('Y-m-d H:i:s'))->diffInMinutes(Carbon::parse($token->expires_at), false) / 60;
    }

    /**
     * As a new HTTP request would start: no signed-in guard, and no token
     * server left over from an earlier step of the test. Passport fixes a
     * token's lifetime when it builds that server, so a test that signs in
     * twice would otherwise be measuring the first sign-in's lifetime twice.
     */
    private function freshRequest()
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance(\League\OAuth2\Server\AuthorizationServer::class);
    }

    private function signIn(array $extra = [])
    {
        $this->freshRequest();

        $response = $this->postJson('/api/v1/user/signin', [
            'email' => Fixture::email(Fixture::USER_PERSONAL_ID),
            'password' => Fixture::PASSWORD,
        ] + $extra);

        $this->assertApiOk($response);

        return $response;
    }

    public function test_without_keep_me_signed_in_the_sign_in_lasts_a_day()
    {
        $response = $this->signIn();

        $this->assertEqualsWithDelta(24, $this->hoursLeft(), 0.1);
        $this->assertFalse($response->json('data.remembered'));
    }

    public function test_keep_me_signed_in_lasts_thirty_days()
    {
        $response = $this->signIn(['remember_me' => true]);

        $this->assertEqualsWithDelta(30 * 24, $this->hoursLeft(), 0.1);
        $this->assertTrue($response->json('data.remembered'));
    }

    public function test_the_old_flag_name_is_still_honoured()
    {
        $this->signIn(['remember_token' => 1]);

        $this->assertEqualsWithDelta(30 * 24, $this->hoursLeft(), 0.1);
    }

    public function test_no_sign_in_lasts_a_year_any_more()
    {
        $this->signIn(['remember_me' => true]);

        $this->assertLessThanOrEqual(30 * 24 + 0.1, $this->hoursLeft());
    }

    public function test_a_code_sign_in_honours_the_choice_too()
    {
        foreach ([[false, 24], [true, 30 * 24]] as [$remember, $hours]) {
            LoginCode::create([
                'email' => $this->user->email,
                'purpose' => LoginCode::PURPOSE_LOGIN,
                'code' => Hash::make('424242'),
                'expires_at' => now()->addMinutes(10),
                'ip_address' => '127.0.0.1',
            ]);

            $this->freshRequest();
            $this->assertApiOk($this->postJson('/api/v1/user/login-code/verify', [
                'email' => $this->user->email,
                'code' => '424242',
                'remember_me' => $remember,
            ]));

            $this->assertEqualsWithDelta($hours, $this->hoursLeft(), 0.1, ($remember ? 'ticked' : 'unticked') . ' ' . json_encode(DB::table('oauth_access_tokens')->where('user_id', $this->user->id)->get(['id', 'created_at', 'expires_at'])));
        }
    }

    public function test_a_one_day_sign_in_renewed_in_its_last_minute_stays_one_day()
    {
        // A one-day token with seconds to go.
        Passport::personalAccessTokensExpireIn(Carbon::now()->addDay());
        $result = $this->user->createToken('user', ['user']);

        DB::table('oauth_access_tokens')->where('id', $result->token->id)->update([
            'created_at' => Carbon::now()->subDay()->addSeconds(30)->format('Y-m-d H:i:s'),
            'expires_at' => Carbon::now()->addSeconds(30)->format('Y-m-d H:i:s'),
        ]);

        $this->freshRequest();
        $this->withHeaders(['Authorization' => 'Bearer ' . $result->accessToken])
            ->getJson('/api/v1/user/points');

        $this->assertNull(DB::table('oauth_access_tokens')->where('id', $result->token->id)->first(), 'it was replaced');
        $this->assertEqualsWithDelta(24, $this->hoursLeft(), 0.1, 'not 30 days');
    }
}
