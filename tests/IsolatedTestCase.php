<?php

namespace Tests;

use App\Models\Admin;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Testing\Fixture;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/**
 * Base class for tests that run against the isolated test database.
 *
 * The legacy suites in tests/Feature run against the dev database and pick
 * their fixtures out of whatever it holds, so an admin editing a product moves
 * them. Tests extending this class instead run against `mrmobile_test`, built
 * by `php artisan test:db` from committed files, and name their data from
 * Database\Seeders\Testing\Fixture. Every test runs inside a transaction that
 * is rolled back, so the database is the same at the start of every test.
 *
 * Three things are enforced rather than trusted:
 *
 *  - The database name must end in _test, and must have been seeded by the
 *    current Fixture::VERSION. Otherwise the run stops and says what to do.
 *  - Mail must use the array transport. The dev .env sends through Brevo, so
 *    a misconfigured run would email real people.
 *  - Outbound HTTP through Laravel's client is blocked. A call nothing faked
 *    gets a 599 answer and fails the test, naming the URL. Fake what a test
 *    needs with fakeHttp(), not Http::fake(): Laravel 8 uses the FIRST stub
 *    that matches, so a stub added after the block would never be reached.
 *    (curl_init() calls - PayFast, image import from URL, licence activation -
 *    are not covered; the seeded gateway keys are dummies, so they fail there.)
 */
abstract class IsolatedTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var bool Checked once per PHPUnit process, not once per test. */
    private static $databaseVerified = false;

    /** @var string[] URLs a test tried to reach without faking them. */
    private $blockedRequests = [];

    public function createApplication()
    {
        $app = parent::createApplication();

        $database = env('DB_TEST_DATABASE', 'mrmobile_test');
        if (!preg_match('/_test$/', $database)) {
            throw new \RuntimeException("DB_TEST_DATABASE=$database: the isolated suite only runs against a database whose name ends in _test.");
        }

        $app['config']->set('database.connections.mysql.database', $database);
        $app['db']->purge('mysql');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (config('mail.default') !== 'array') {
            $this->fail('MAIL_MAILER is "' . config('mail.default') . '", not "array": this run could send real email. Check phpunit.xml.');
        }

        $this->verifyDatabaseOnce();
        $this->fakeHttp();

        // Tokens minted by asCustomer()/asAdmin() must outlive the test.
        Passport::personalAccessTokensExpireIn(Carbon::now()->addDays(30));
    }

    protected function tearDown(): void
    {
        $blocked = $this->blockedRequests;
        $this->blockedRequests = [];

        parent::tearDown();

        if ($blocked) {
            $this->fail("Outbound HTTP was blocked; fake it with \$this->fakeHttp([...]):\n  " . implode("\n  ", array_unique($blocked)));
        }
    }

    /**
     * Fake outbound HTTP, e.g. fakeHttp(['api.sumup.com/*' => Http::response([...])]).
     * Replaces any stubs set earlier in the test; anything not matched is blocked.
     */
    protected function fakeHttp(array $stubs = [])
    {
        Http::swap(new Factory($this->app['events']));

        // One stub, not the test's stubs followed by a block: Laravel 8 invokes
        // every stub on every request before picking the first answer, so a
        // separate block would record calls the test had in fact faked.
        Http::fake(function ($request, $options) use ($stubs) {
            foreach ($stubs as $pattern => $answer) {
                if (Str::is(Str::start($pattern, '*'), $request->url())) {
                    return is_callable($answer) ? $answer($request, $options) : $answer;
                }
            }

            $this->blockedRequests[] = $request->method() . ' ' . $request->url();

            return Http::response(['message' => 'Outbound HTTP is blocked in tests'], 599);
        });
    }

    /**
     * The blocked calls so far, cleared: for a test that expects a call to be
     * blocked, so tearDown does not fail it.
     */
    protected function takeBlockedRequests(): array
    {
        $blocked = $this->blockedRequests;
        $this->blockedRequests = [];

        return $blocked;
    }

    /**
     * Send the following requests as a customer, with a real long-lived bearer.
     *
     * Passport::actingAs() is avoided on purpose: it injects a `token` field
     * into $request->all(), which breaks controllers that save $request->all().
     * The guards are forgotten first because Laravel keeps whoever the previous
     * request in the same test resolved, and would otherwise answer as them.
     */
    protected function asCustomer(int $userId = Fixture::USER_PERSONAL_ID)
    {
        return $this->withBearer(User::findOrFail($userId)->createToken('test', ['user'])->accessToken);
    }

    /** Send the following requests as an admin (guard admin, scope admin). */
    protected function asAdmin(int $adminId = Fixture::ADMIN_SUPER_ID)
    {
        return $this->withBearer(Admin::findOrFail($adminId)->createToken('test', ['admin'])->accessToken);
    }

    /** Send the following requests signed out, as the fixture guest basket. */
    protected function asGuest()
    {
        $this->app['auth']->forgetGuards();
        unset($this->defaultHeaders['Authorization']);

        return $this;
    }

    private function withBearer(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * Assert the API refused a request. Refusals are carried in the JSON body
     * (HTTP 201 with {"status": 403, ...}), so assertStatus() cannot see them.
     */
    protected function assertApiRefused($response, int $status, string $messageContains = null)
    {
        $this->assertSame($status, (int)$response->json('status'), 'API status. Body: ' . $response->getContent());
        if ($messageContains !== null) {
            $this->assertStringContainsString($messageContains, (string)$response->json('message'));
        }
    }

    /** Assert the API accepted a request: {"status": 200, ...} in the body. */
    protected function assertApiOk($response)
    {
        $this->assertSame(200, (int)$response->json('status'), 'API status. Body: ' . substr($response->getContent(), 0, 1000));
    }

    private function verifyDatabaseOnce()
    {
        if (self::$databaseVerified) {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        $hint = 'Run `php artisan test:db` to build it.';

        try {
            $version = DB::table('_fixture_version')->value('version');
        } catch (\Throwable $e) {
            $this->fail("`$database` is not a seeded test database. $hint\n" . $e->getMessage());
        }

        if ((int)$version !== Fixture::VERSION) {
            $this->fail("`$database` was seeded by fixture version $version; the code expects " . Fixture::VERSION . ". $hint");
        }

        self::$databaseVerified = true;
    }
}
