<?php

namespace Tests\Isolated;

use App\Models\Helper\AfterShipSettings;
use App\Models\Helper\CourierDirectory;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\IsolatedTestCase;

/**
 * Settings > AfterShip (2026-10-21): the API key, webhook secret, delivered
 * scan and check interval are set in the admin instead of .env.
 *
 * A NULL column keeps the .env value; secrets are stored encrypted and never
 * sent back, only their last four characters.
 */
class AfterShipSettingsTest extends IsolatedTestCase
{
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aftership.api_key' => 'env-key-0000',
            'aftership.webhook_secret' => null,
            'aftership.auto_deliver' => true,
            'aftership.sync_minutes' => null,
        ]);
        $this->resetSettings();

        $this->store = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aftership-settings-test-' . getmypid();
        config(['aftership.courier_store' => $this->store]);
        CourierDirectory::flush();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->store . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->store);
        AfterShipSettings::forget();

        parent::tearDown();
    }

    /** Back to "only .env": the captured .env values are the ones set above. */
    private function resetSettings()
    {
        $reflection = new \ReflectionProperty(AfterShipSettings::class, 'envDefaults');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);
        AfterShipSettings::forget();
        AfterShipSettings::apply();
    }

    private function save(array $fields, int $adminId = Fixture::ADMIN_SUPER_ID)
    {
        return $this->asAdmin($adminId)->postJson('/api/admin/setting/aftership', array_merge([
            'auto_deliver' => 1,
        ], $fields));
    }

    public function test_with_nothing_saved_the_env_values_apply()
    {
        $this->assertSame('env-key-0000', config('aftership.api_key'));

        $response = $this->asAdmin()->getJson('/api/admin/setting/aftership');
        $this->assertApiOk($response);
        $this->assertSame('env', $response->json('data.api_key.source'));
        $this->assertSame('0000', $response->json('data.api_key.hint'));
        $this->assertFalse($response->json('data.webhook_secret.set'));
        $this->assertStringEndsWith('/api/v1/aftership/webhook', $response->json('data.webhook_url'));
    }

    public function test_a_saved_key_replaces_the_env_one_and_is_stored_encrypted()
    {
        $response = $this->save(['api_key' => 'asat_live_key_9876', 'webhook_secret' => 'whsec-abcd']);
        $this->assertApiOk($response);

        $this->assertSame('asat_live_key_9876', config('aftership.api_key'));
        $this->assertSame('whsec-abcd', config('aftership.webhook_secret'));

        $row = DB::table('aftership_settings')->first();
        $this->assertStringNotContainsString('asat_live_key_9876', $row->api_key, 'stored encrypted');
        $this->assertStringNotContainsString('whsec-abcd', $row->webhook_secret, 'stored encrypted');

        $body = $response->getContent();
        $this->assertStringNotContainsString('asat_live_key_9876', $body, 'the key is never sent back');
        $this->assertStringNotContainsString('whsec-abcd', $body, 'the secret is never sent back');
        $this->assertSame('admin', $response->json('data.api_key.source'));
        $this->assertSame('9876', $response->json('data.api_key.hint'));
    }

    public function test_the_page_never_returns_a_secret()
    {
        $this->save(['api_key' => 'asat_live_key_9876', 'webhook_secret' => 'whsec-abcd']);

        $body = $this->asAdmin()->getJson('/api/admin/setting/aftership')->getContent();

        $this->assertStringNotContainsString('asat_live_key_9876', $body);
        $this->assertStringNotContainsString('whsec-abcd', $body);
    }

    public function test_a_blank_key_keeps_the_saved_one_and_clear_goes_back_to_env()
    {
        $this->save(['api_key' => 'asat_live_key_9876']);

        $this->assertApiOk($this->save(['api_key' => '', 'sync_minutes' => 120]));
        $this->assertSame('asat_live_key_9876', config('aftership.api_key'), 'blank keeps the saved key');
        $this->assertSame(120, (int)config('aftership.sync_minutes'));

        $response = $this->save(['clear_api_key' => 1]);
        $this->assertApiOk($response);
        $this->assertSame('env-key-0000', config('aftership.api_key'), 'cleared: .env applies again');
        $this->assertSame('env', $response->json('data.api_key.source'));
    }

    public function test_the_delivered_scan_can_be_switched_off()
    {
        $this->assertApiOk($this->save(['auto_deliver' => 0]));

        $this->assertFalse((bool)config('aftership.auto_deliver'));
    }

    public function test_the_saved_values_survive_a_new_request()
    {
        $this->save(['api_key' => 'asat_live_key_9876', 'auto_deliver' => 0]);

        // A fresh process: the config starts from .env again and boot applies the row.
        config(['aftership.api_key' => 'env-key-0000', 'aftership.auto_deliver' => true]);
        $this->resetSettings();

        $this->assertSame('asat_live_key_9876', config('aftership.api_key'));
        $this->assertFalse((bool)config('aftership.auto_deliver'));
    }

    public function test_a_check_interval_outside_the_limits_is_refused()
    {
        $this->assertApiRefused($this->save(['sync_minutes' => 5]), 201);
        $this->assertApiRefused($this->save(['sync_minutes' => 5000]), 201);
        $this->assertSame(0, DB::table('aftership_settings')->count(), 'nothing saved');
    }

    public function test_an_admin_without_settings_permission_cannot_save()
    {
        $response = $this->save(['api_key' => 'asat_live_key_9876'], Fixture::ADMIN_VENDOR_ID);

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertSame('env-key-0000', config('aftership.api_key'));
    }

    public function test_a_new_key_drops_the_old_accounts_courier_list()
    {
        @mkdir($this->store, 0775, true);
        file_put_contents(CourierDirectory::path('active'), json_encode(['fetched_at' => time(), 'couriers' => ['ups' => ['slug' => 'ups']]]));

        $this->save(['api_key' => 'asat_other_account_1111']);

        $this->assertFileDoesNotExist(CourierDirectory::path('active'));
    }

    public function test_test_connection_lists_the_activated_couriers()
    {
        $this->save(['api_key' => 'asat_live_key_9876']);
        $this->fakeHttp(['api.aftership.com/*' => Http::response([
            'meta' => ['code' => 200],
            'data' => ['couriers' => [['slug' => 'an-post', 'name' => 'An Post'], ['slug' => 'dpd-ireland', 'name' => 'DPD Ireland']]],
        ])]);

        $response = $this->asAdmin()->postJson('/api/admin/setting/aftership-test');

        $this->assertApiOk($response);
        $this->assertSame(['an-post', 'dpd-ireland'], array_column($response->json('data.couriers'), 'slug'));
        Http::assertSent(function ($request) {
            return $request->hasHeader('as-api-key', 'asat_live_key_9876');
        });
    }

    public function test_test_connection_reports_a_rejected_key_even_with_an_old_list_stored()
    {
        @mkdir($this->store, 0775, true);
        file_put_contents(CourierDirectory::path('active'), json_encode(['fetched_at' => time() - 99999, 'couriers' => ['ups' => ['slug' => 'ups']]]));
        $this->fakeHttp(['api.aftership.com/*' => Http::response([
            'meta' => ['code' => 401, 'message' => 'Invalid API key.'],
        ], 401)]);

        $response = $this->asAdmin()->postJson('/api/admin/setting/aftership-test');

        $this->assertApiRefused($response, 201, 'rejected the API key');
    }
}
