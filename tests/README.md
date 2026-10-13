# Tests

Two kinds of test live here, and they use different databases.

| Folder | Database | Status |
|---|---|---|
| `tests/Isolated/` | `mrmobile_test`, rebuilt from committed files | New tests go here. Runs in CI. |
| `tests/Feature/`, `tests/Unit/` | the dev database in `.env` | Legacy. Moving to Isolated module by module. |

Also here:

- [COVERAGE.md](COVERAGE.md): every API route and page by module, and which tests reach it.
  Generated: run `php artisan test:inventory` after adding tests or routes (CI fails if you forget).
- [FINDINGS.md](FINDINGS.md): defects found while testing, confirmed by running the code.

## Running the isolated suite

```bash
composer test:isolated        # rebuild the test database, then run the suite
```

Or the two steps separately:

```bash
php artisan test:db                              # ~4s: drop, recreate, load, seed
vendor/bin/phpunit --testsuite Isolated
vendor/bin/phpunit --filter FoundationSmokeTest  # one class
```

Every test runs inside a transaction that is rolled back, so you only need
`test:db` again when the fixture changes (the suite tells you) or after a
schema change.

## How the test database is built

`php artisan test:db` builds `mrmobile_test` (or `DB_TEST_DATABASE`) from:

1. `database/testing/schema.sql`: every table, no rows.
2. `database/testing/reference.sql`: configuration the app needs (languages,
   permissions, tax rules, shipping, site settings, pages, migrations).
3. `Database\Seeders\Testing\TestFixtureSeeder`: known admins, customers and
   products at fixed ids, and payment settings with dummy keys.

It refuses any database whose name does not end in `_test`.

**After a migration changes the schema**, refresh the dumps from your dev
database and rebuild:

```bash
bash database/testing/export.sh && php artisan test:db
```

Never add a table holding credentials, customers or orders to
`export.sh`: `reference.sql` is committed.

## Writing a test

Extend `Tests\IsolatedTestCase` and take data from `Database\Seeders\Testing\Fixture`:

```php
class CartTest extends IsolatedTestCase
{
    public function test_a_customer_adds_a_variant_to_the_cart()
    {
        $response = $this->asCustomer(Fixture::USER_PERSONAL_ID)
            ->postJson('/api/v1/cart/action', [/* ... */ 'inventory_id' => Fixture::PHONE_256_BLACK_ID]);

        $this->assertApiOk($response);
    }
}
```

What the fixture holds (see `Fixture.php` for every id):

- **Admins**: superadmin, vendor. **Customers**: personal, business, trade
  approved, trade pending, suspended, unverified. All use `Fixture::PASSWORD`.
- **Products**: an Inc-VAT phone with 4 variants, an Ex-VAT laptop with
  trade bands (10+ at €900, 50+ at €850), a margin-scheme refurb, a
  back-order tablet, sold-out earbuds, and an unpublished product.

Helpers on `IsolatedTestCase`:

- `asCustomer($id)`, `asAdmin($id)`, `asGuest()`: switch who is calling.
  They use real bearer tokens and reset the auth guards, so switching caller
  inside one test works.
- `assertApiOk($response)`, `assertApiRefused($response, 403, 'message')`:
  this API returns refusals as HTTP 201 with `{"status": 403}` in the body, so
  `assertStatus()` cannot see them.
- `fakeHttp(['api.sumup.com/*' => Http::response([...])])`: fake an outside
  service. Do not call `Http::fake()` directly.

## Safety nets

A run stops, rather than going ahead unsafely, when:

- the database name does not end in `_test`, or was seeded by an older fixture;
- `MAIL_MAILER` is not `array` (the dev `.env` sends real email through Brevo);
- a test makes an HTTP call it did not fake. The call gets a 599 answer and
  the test fails, naming the URL.

Calls made with `curl_init()` (PayFast, image import from a URL, licence
activation) are not intercepted. The seeded gateway keys are dummies, so
they fail at the provider.
