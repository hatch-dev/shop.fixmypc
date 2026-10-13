<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Cart tracing (App\Models\Helper\CartTrace, 2026-10-09).
 *
 * Added after "a newly registered account sometimes opens with products in
 * its cart". The lines are the browser's own guest basket, merged at the
 * account's first sign-in -- these tests pin that the log records enough to
 * prove it for any one account: which action made each line, how old it was,
 * and whether the guest basket had a reservation that would have emptied it.
 */
class CartTraceTest extends IsolatedTestCase
{
    private $log;

    protected function setUp(): void
    {
        parent::setUp();

        // A file of its own, not storage/logs: the suite must not write there.
        $this->log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cart-trace-test-' . uniqid() . '.log';

        // phpunit.xml switches tracing off for every other test.
        config(['logging.channels.cart_trace' => [
            'enabled' => true,
            'driver' => 'single',
            'path' => $this->log,
            'level' => 'info',
        ]]);

        Cart::whereNull('admin_id')->delete();
        DB::table('cart_reservations')->delete();
        DB::table('cart_holds')->delete();
        DB::table('updated_inventories')->whereIn('id', [Fixture::PHONE_128_BLACK_ID, Fixture::LAPTOP_INVENTORY_ID])
            ->update(['quantity' => 10, 'is_active' => 0]);
    }

    protected function tearDown(): void
    {
        foreach (glob(substr($this->log, 0, -4) . '*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @return array<int, array{event:string, context:array}> */
    private function entries()
    {
        if (!is_file($this->log)) {
            return [];
        }

        $entries = [];

        foreach (file($this->log, FILE_IGNORE_NEW_LINES) as $raw) {
            if (preg_match('/^\[[^\]]+\]\s+\S+:\s+(\w+)\s+(\{.*\})\s*(?:\[\])?\s*$/', $raw, $m)) {
                $entries[] = ['event' => $m[1], 'context' => json_decode($m[2], true)];
            }
        }

        return $entries;
    }

    private function event($name)
    {
        return array_values(array_filter($this->entries(), function ($e) use ($name) {
            return $e['event'] === $name;
        }));
    }

    private function guestAdds($productId, $inventoryId, $path = '/api/v1/cart/action')
    {
        return $this->asGuest()->postJson($path, [
            'product_id' => $productId,
            'inventory_id' => $inventoryId,
            'quantity' => 1,
            'user_token' => Fixture::GUEST_TOKEN,
        ]);
    }

    /** The account was created a moment ago: the sign-in that follows registration. */
    private function freshAccount()
    {
        DB::table('users')->where('id', Fixture::USER_PERSONAL_ID)
            ->update(['created_at' => Carbon::now()->format('Y-m-d H:i:s')]);

        return User::findOrFail(Fixture::USER_PERSONAL_ID);
    }

    private function signIn()
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/user/signin', [
            'email' => Fixture::email(Fixture::USER_PERSONAL_ID),
            'password' => Fixture::PASSWORD,
            'user_token' => Fixture::GUEST_TOKEN,
        ]);
    }

    public function test_every_new_line_is_logged_with_the_action_that_made_it()
    {
        $this->assertApiOk($this->guestAdds(Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID));
        $this->assertApiOk($this->guestAdds(Fixture::PRODUCT_LAPTOP_ID, Fixture::LAPTOP_INVENTORY_ID, '/api/v1/cart/buy-now'));

        $created = $this->event('line_created');
        $this->assertCount(2, $created, 'Log: ' . @file_get_contents($this->log));

        $this->assertSame('add_to_cart', $created[0]['context']['origin']);
        $this->assertSame('buy_now', $created[1]['context']['origin']);
        $this->assertSame('guest', $created[0]['context']['owner']['type']);

        // A guest token is a credential: the log holds a prefix, never the token.
        $this->assertStringNotContainsString(Fixture::GUEST_TOKEN, file_get_contents($this->log));
    }

    public function test_registering_records_which_guest_lines_the_new_account_took_over()
    {
        $this->guestAdds(Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID);
        $lineId = (int)Cart::where('user_token', Fixture::GUEST_TOKEN)->value('id');

        $this->freshAccount();
        $this->assertApiOk($this->signIn());

        $merges = $this->event('guest_basket_merged');
        $this->assertCount(1, $merges);

        $merge = $merges[0]['context'];
        $this->assertTrue($merge['new_account']);
        $this->assertSame(Fixture::USER_PERSONAL_ID, $merge['user_id']);
        $this->assertSame(1, $merge['lines_moved']);
        $this->assertSame($lineId, $merge['lines'][0]['cart_id'], 'joins to its line_created entry');
        $this->assertNotEmpty($merge['guest_basket']['last_activity_at'], 'how old the guest basket was');
        $this->assertNotEmpty($merge['guest_basket']['expires_at']);
        $this->assertStringEndsWith('user/signin', $merge['endpoint']);

        // The line really did move -- tracing changed nothing.
        $this->assertSame(Fixture::USER_PERSONAL_ID, (int)Cart::find($lineId)->user_id);
    }

    public function test_an_old_guest_basket_shows_its_age_and_when_it_would_have_expired()
    {
        $this->guestAdds(Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID);

        // The guest added it three days ago and has not been back since. A
        // basket that old can only be one that never had a reservation (one
        // with a reservation empties at 20 minutes): a line from before
        // reservations existed.
        DB::table('cart_holds')->delete();
        DB::table('cart_reservations')->delete();
        $threeDays = Carbon::now()->subDays(3)->format('Y-m-d H:i:s');
        Cart::where('user_token', Fixture::GUEST_TOKEN)
            ->update(['created_at' => $threeDays, 'updated_at' => $threeDays]);
        DB::table('guest_users')->where('user_token', Fixture::GUEST_TOKEN)->update(['updated_at' => $threeDays]);

        $this->freshAccount();
        $this->assertApiOk($this->signIn());

        $merge = $this->event('guest_basket_merged')[0]['context'];
        $this->assertSame('3 days', $merge['guest_basket']['idle']);
        $this->assertFalse($merge['guest_basket']['reserved']);
        $this->assertSame('3 days', $merge['lines'][0]['age']);

        Artisan::call('cart:trace', ['who' => Fixture::email(Fixture::USER_PERSONAL_ID)]);
        $out = Artisan::output();

        $this->assertStringContainsString('[at registration]', $out);
        $this->assertStringContainsString('guest basket: NOT reserved, last active', $out);
        $this->assertStringContainsString('would have expired', $out);
        $this->assertStringContainsString('YES - came from a guest basket', $out);
        $this->assertStringContainsString('add_to_cart', $out);
    }

    public function test_a_new_account_with_an_empty_guest_basket_is_logged_as_empty()
    {
        $this->freshAccount();
        $this->assertApiOk($this->signIn());

        $merge = $this->event('guest_basket_merged')[0]['context'];
        $this->assertTrue($merge['new_account']);
        $this->assertSame(0, $merge['lines_moved']);
    }

    public function test_an_established_account_signing_in_with_nothing_to_move_is_not_logged()
    {
        DB::table('users')->where('id', Fixture::USER_PERSONAL_ID)
            ->update(['created_at' => Carbon::now()->subYear()->format('Y-m-d H:i:s')]);

        $this->assertApiOk($this->signIn());

        $this->assertCount(0, $this->event('guest_basket_merged'));
    }

    public function test_a_broken_log_never_costs_the_shopper_their_basket()
    {
        // A directory where the file should be: every write fails.
        config(['logging.channels.cart_trace.path' => sys_get_temp_dir()]);

        $this->assertApiOk($this->guestAdds(Fixture::PRODUCT_PHONE_ID, Fixture::PHONE_128_BLACK_ID));

        $this->freshAccount();
        $this->assertApiOk($this->signIn());

        $this->assertSame(1, Cart::where('user_id', Fixture::USER_PERSONAL_ID)->count());
    }
}
