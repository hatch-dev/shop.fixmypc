<?php

namespace Tests\Isolated;

use App\Models\User;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatedTestCase;

/**
 * Proves the isolated test foundation itself: the right database, the seeded
 * fixture, the three kinds of caller, and the two safety nets (HTTP and mail).
 * If this class fails, fix the foundation before believing any other result.
 */
class FoundationSmokeTest extends IsolatedTestCase
{
    public function test_it_runs_against_the_isolated_database()
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->assertSame(Fixture::VERSION, (int)DB::table('_fixture_version')->value('version'));
    }

    public function test_writes_are_rolled_back_between_tests_part_one()
    {
        DB::table('products')->where('id', Fixture::PRODUCT_PHONE_ID)->update(['title' => 'Changed by a test']);

        $this->assertSame('Changed by a test', DB::table('products')->where('id', Fixture::PRODUCT_PHONE_ID)->value('title'));
    }

    /** @depends test_writes_are_rolled_back_between_tests_part_one */
    public function test_writes_are_rolled_back_between_tests_part_two()
    {
        $this->assertSame('Fixturo Phone X', DB::table('products')->where('id', Fixture::PRODUCT_PHONE_ID)->value('title'));
    }

    public function test_the_seeded_gateway_keys_are_dummies()
    {
        $payments = (array)DB::table('payments')->first();

        foreach (['stripe_secret', 'sumup_api_key', 'paypal_secret', 'razorpay_secret', 'fw_secret_key'] as $column) {
            $this->assertMatchesRegularExpression('/test|fixture|sandbox/i', (string)$payments[$column], $column);
        }
    }

    public function test_a_guest_can_open_a_published_product()
    {
        $response = $this->asGuest()->getJson('/api/v1/product/' . Fixture::PRODUCT_PHONE_ID);

        $this->assertApiOk($response);
    }

    public function test_search_finds_published_fixtures_but_not_the_private_one()
    {
        $response = $this->asGuest()->getJson('/api/v1/search?q=Fixturo');
        $this->assertApiOk($response);

        $ids = collect($response->json('data.product'))->pluck('id')->map(function ($id) { return (int)$id; });

        $this->assertContains(Fixture::PRODUCT_PHONE_ID, $ids, 'Body: ' . substr($response->getContent(), 0, 800));
        $this->assertNotContains(Fixture::PRODUCT_UNPUBLISHED_ID, $ids);
    }

    public function test_a_customer_signs_in_with_the_fixture_password()
    {
        $response = $this->postJson('/api/v1/user/signin', [
            'email' => Fixture::email(Fixture::USER_PERSONAL_ID),
            'password' => Fixture::PASSWORD,
        ]);

        $this->assertApiOk($response);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_a_suspended_customer_cannot_sign_in()
    {
        $response = $this->postJson('/api/v1/user/signin', [
            'email' => Fixture::email(Fixture::USER_SUSPENDED_ID),
            'password' => Fixture::PASSWORD,
        ]);

        $this->assertNotSame(200, (int)$response->json('status'), 'Body: ' . $response->getContent());
        $this->assertEmpty($response->json('data.token'));
    }

    public function test_an_admin_signs_in_and_reaches_an_admin_route()
    {
        $login = $this->postJson('/api/admin/login', [
            'email' => Fixture::ADMIN_SUPER_EMAIL,
            'password' => Fixture::PASSWORD,
        ]);
        $this->assertApiOk($login);

        $response = $this->asAdmin()->getJson('/api/admin/product/find/' . Fixture::PRODUCT_PHONE_ID);
        $this->assertApiOk($response);
    }

    public function test_an_admin_route_refuses_a_customer_token()
    {
        $response = $this->asCustomer()->getJson('/api/admin/product/find/' . Fixture::PRODUCT_PHONE_ID);

        $this->assertNotSame(200, (int)$response->json('status'), 'Body: ' . $response->getContent());
    }

    public function test_the_account_fixtures_carry_the_entitlements_they_claim()
    {
        $this->assertFalse(User::find(Fixture::USER_PERSONAL_ID)->hasBusinessPricing());
        $this->assertTrue(User::find(Fixture::USER_BUSINESS_ID)->hasBusinessPricing());
        $this->assertTrue(User::find(Fixture::USER_TRADE_APPROVED_ID)->hasBusinessPricing());
        $this->assertFalse(User::find(Fixture::USER_TRADE_PENDING_ID)->hasBusinessPricing());
        $this->assertFalse(User::find(Fixture::USER_SUSPENDED_ID)->hasBusinessPricing());
    }

    public function test_unfaked_outbound_http_is_blocked()
    {
        $response = Http::get('https://api.sumup.com/v0.1/checkouts');

        $this->assertSame(599, $response->status());
        $this->assertSame(['GET https://api.sumup.com/v0.1/checkouts'], $this->takeBlockedRequests());
    }

    public function test_faked_outbound_http_is_answered_by_the_fake()
    {
        $this->fakeHttp(['api.sumup.com/*' => Http::response(['status' => 'PAID'], 200)]);

        $this->assertSame('PAID', Http::get('https://api.sumup.com/v0.1/checkouts/1')->json('status'));
    }

    public function test_mail_is_captured_not_sent()
    {
        Mail::raw('Fixture mail', function ($message) {
            $message->to(Fixture::email(Fixture::USER_PERSONAL_ID))->subject('Smoke');
        });

        $messages = app('mail.manager')->mailer()->getSwiftMailer()->getTransport()->messages();

        $this->assertCount(1, $messages);
        $this->assertSame('Smoke', $messages->first()->getSubject());
    }
}
