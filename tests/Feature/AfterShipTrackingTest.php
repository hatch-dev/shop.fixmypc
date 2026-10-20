<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Helper\ShipmentTracking;
use App\Models\Order;
use App\Models\OrderEmailLog;
use App\Models\OrderShipment;
use App\Models\OrderStatusHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AfterShip shipment tracking, end to end (2026-10-16).
 *
 * Admin saves courier + number -> AfterShip create -> order On the way ->
 * one customer email carrying the tracking link -> AfterShip webhook / sync
 * -> Delivered. AfterShip is faked by ONE closure that answers every request
 * (Laravel 8 runs every stub and sends anything unanswered for real), and
 * records what was asked, so "no API call" is asserted, not assumed.
 *
 * Mail is the array transport and the queue is sync (phpunit.xml), so "sent"
 * means the job ran and the mailer accepted the message.
 */
class AfterShipTrackingTest extends TestCase
{
    use DatabaseTransactions;

    /** Requests the fake received: [method, path, body]. */
    private $calls = [];

    /** Queue of responses: callable(method, path, body) => [status, json, headers]. */
    private $answer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aftership.api_key' => 'asat_test_key_never_real',
            'aftership.webhook_secret' => 'whsec-test',
            'aftership.allow_test_courier' => true,
            'aftership.auto_deliver' => true,
        ]);

        // Couriers come from AfterShip; each test gets its own stored copy so
        // nothing here touches storage/app/aftership or the network.
        $this->courierStore = storage_path('framework/testing/aftership-' . uniqid());
        config(['aftership.courier_store' => $this->courierStore]);
        \App\Models\Helper\CourierDirectory::flush();
        $this->seedCouriers();

        $this->calls = [];
        $this->answer = function ($method, $path, $body) {
            if ($method === 'GET' && $path === 'couriers') {
                $list = !empty($body['_active'])
                    ? array_intersect_key($this->courierFixture, array_flip(['an-post', 'dpd-ireland']))
                    : $this->courierFixture;
                return [200, ['meta' => ['code' => 200], 'data' => ['total' => count($list), 'couriers' => array_values($list)]]];
            }

            if ($method === 'DELETE') {
                return [200, ['meta' => ['code' => 200], 'data' => ['tracking' => ['id' => basename($path)]]]];
            }
            return [201, ['meta' => ['code' => 201], 'data' => $this->remote($body['tracking_number'] ?? 'X', $body['slug'] ?? 'an-post')]];
        };

        Http::fake(function ($request) {
            $url = $request->url();

            if (strpos($url, 'https://api.aftership.com/') !== 0) {
                // Never let a test reach the network.
                return Http::response(['blocked' => $url], 599);
            }

            if (!empty($this->throwConnection)) {
                throw new ConnectionException('Connection refused');
            }

            $path = preg_replace('#^https://api\.aftership\.com/tracking/[0-9-]+/#', '', strtok($url, '?'));
            $body = json_decode($request->body(), true) ?: [];
            // GET /couriers?active=true answers with the account's activated couriers only.
            if (strpos($url, 'active=true') !== false) {
                $body['_active'] = true;
            }
            $this->calls[] = [$request->method(), $path, $body, $request->header('as-api-key')[0] ?? null];

            list($status, $json, $headers) = array_pad(call_user_func($this->answer, $request->method(), $path, $body), 3, []);

            return Http::response($json, $status, $headers);
        });

        $this->mails()->flush();
    }

    private $throwConnection = false;

    private $courierStore;

    /** A slice of AfterShip's GET /couriers answer, in its own shape. */
    private $courierFixture = [
        'an-post' => ['slug' => 'an-post', 'name' => 'An Post', 'other_name' => 'Ireland Post', 'required_fields' => [], 'optional_fields' => [], 'service_from_country_regions' => ['IRL'], 'web_url' => 'https://www.anpost.com', 'phone' => '1', 'credentials' => null],
        'dpd-ireland' => ['slug' => 'dpd-ireland', 'name' => 'DPD Ireland', 'other_name' => '', 'required_fields' => [], 'optional_fields' => [], 'service_from_country_regions' => ['IRL']],
        'dpd' => ['slug' => 'dpd', 'name' => 'DPD', 'other_name' => '', 'required_fields' => [], 'service_from_country_regions' => ['DEU']],
        'gls-netherlands' => ['slug' => 'gls-netherlands', 'name' => 'GLS Netherlands', 'other_name' => '', 'required_fields' => ['destination_postal_code'], 'service_from_country_regions' => ['NLD']],
        'nacex-spain-reference' => ['slug' => 'nacex-spain-reference', 'name' => 'NACEX Spain', 'other_name' => '', 'required_fields' => ['tracking_account_number', 'tracking_key'], 'service_from_country_regions' => ['ESP']],
        'testing-courier' => ['slug' => 'testing-courier', 'name' => 'Testing Courier', 'other_name' => '', 'required_fields' => [], 'service_from_country_regions' => []],
    ];

    /** Write the stored copies CourierDirectory reads, fresh, as if fetched just now. */
    private function seedCouriers(array $activeSlugs = ['an-post', 'dpd-ireland'])
    {
        @mkdir($this->courierStore, 0775, true);
        $norm = function ($list) {
            $out = [];
            foreach ($list as $c) { $n = \App\Models\Helper\CourierDirectory::normalise($c); $out[$n['slug']] = $n; }
            return $out;
        };
        file_put_contents($this->courierStore . '/couriers-all.json', json_encode(['fetched_at' => time(), 'couriers' => $norm($this->courierFixture)]));
        file_put_contents($this->courierStore . '/couriers-active.json', json_encode(['fetched_at' => time(),
            'couriers' => $norm(array_intersect_key($this->courierFixture, array_flip($activeSlugs)))]));
        \App\Models\Helper\CourierDirectory::flush();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->courierStore . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($this->courierStore);
        \App\Models\Helper\CourierDirectory::flush();
        parent::tearDown();
    }

    private function mails()
    {
        return app('mailer')->getSwiftMailer()->getTransport();
    }

    private function messagesTo($email)
    {
        return collect($this->mails()->messages())
            ->filter(function ($m) use ($email) { return array_key_exists($email, $m->getTo() ?: []); })
            ->values();
    }

    /** An AfterShip tracking object as the 2026-07 API returns it. */
    private function remote($number, $slug = 'an-post', array $over = [])
    {
        return array_merge([
            'id' => 'as' . substr(md5($slug . $number), 0, 30),
            'tracking_number' => $number,
            'slug' => $slug,
            'tag' => 'Pending',
            'subtag' => 'Pending_001',
            'subtag_message' => 'Pending',
            'updated_at' => '2026-10-16T10:00:00+00:00',
            'courier_tracking_link' => 'https://www.anpost.com/Post-Parcels/Track/History?item=' . $number,
            'checkpoints' => [],
        ], $over);
    }

    private function order($status, array $over = [])
    {
        return Order::create(array_merge([
            'order' => 'ASTEST' . random_int(100000, 999999),
            'status' => $status,
            'order_method' => config('constants.paymentMethod.BANK'),
            'total_amount' => 49.99,
            'currency' => 'EUR',
            'payment_done' => 1,
            'user_token' => 'aftership-test-' . uniqid(),
            'contact_name' => 'Tracking Fixture',
            'contact_email' => 'aftership.' . uniqid() . '@example.test',
            'contact_phone' => '+353871234567',
        ], $over));
    }

    private function asAdmin()
    {
        $this->app['auth']->forgetGuards();
        Passport::actingAs(Admin::orderBy('id')->first(), ['admin'], 'admin');
    }

    private function ship(Order $order, $courier = 'an-post', $number = 'CE123456789IE')
    {
        $this->asAdmin();

        return $this->postJson('/api/admin/order/shipment', [
            'id' => $order->id, 'courier' => $courier, 'tracking_number' => $number,
        ]);
    }

    private function s($name)
    {
        return (int)config('constants.orderStatus.' . $name);
    }

    private function webhook(array $msg, $secret = 'whsec-test', $event = 'tracking_update')
    {
        $raw = json_encode(['event' => $event, 'event_id' => uniqid(), 'ts' => time(), 'msg' => $msg]);
        $sig = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return $this->call('POST', '/api/v1/aftership/webhook', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AFTERSHIP_HMAC_SHA256' => $sig], $raw);
    }

    /** The whole happy path from the admin's Save to the customer's inbox. */
    public function test_saving_a_shipment_registers_it_ships_the_order_and_emails_the_link_once()
    {
        $order = $this->order($this->s('CONFIRMED'));

        $res = $this->ship($order, 'an-post', 'ce 123 456 789 ie');

        $res->assertJsonPath('status', 200);
        $this->assertSame('shipped', $res->json('data.notified'));
        $this->assertSame($this->s('ON_THE_WAY'), $res->json('data.status'));

        // AfterShip got the parcel, the order number, and nothing personal.
        $this->assertCount(1, $this->calls);
        list($method, $path, $body, $key) = $this->calls[0];
        $this->assertSame(['POST', 'trackings'], [$method, $path]);
        $this->assertSame('asat_test_key_never_real', $key);
        $this->assertSame('CE123456789IE', $body['tracking_number']);
        $this->assertSame('an-post', $body['slug']);
        $this->assertSame($order->order, $body['order_number']);
        $this->assertArrayNotHasKey('customers', $body);
        $this->assertStringNotContainsString($order->contact_email, json_encode($body));

        // Stored against this order.
        $row = OrderShipment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('CE123456789IE', $row->tracking_number);
        $this->assertSame('An Post', $row->courier_name);
        $this->assertSame($this->remote('CE123456789IE')['id'], $row->aftership_id);

        // Moved through the normal transition, with its audit row.
        $this->assertSame($this->s('ON_THE_WAY'), (int)$order->fresh()->status);
        $this->assertTrue(OrderStatusHistory::where('order_id', $order->id)
            ->where('to_status', $this->s('ON_THE_WAY'))->where('changed_by_type', 'admin')->exists());

        // One email, carrying courier, number and the signed tracking link.
        $mails = $this->messagesTo($order->contact_email);
        $this->assertCount(1, $mails);
        $html = $mails[0]->getBody();
        $this->assertStringContainsString('An Post', $html);
        $this->assertStringContainsString('CE123456789IE', $html);
        $this->assertStringContainsString(htmlspecialchars(ShipmentTracking::trackUrl($order->fresh()), ENT_QUOTES), $html);
        $this->assertSame('sent', OrderEmailLog::where('order_id', $order->id)
            ->where('event', 'order_shipped')->value('status'));

        // The key never travels back to the browser.
        $this->assertStringNotContainsString('asat_test_key_never_real', $res->getContent());
    }

    public function test_resubmitting_the_same_shipment_makes_no_api_call_and_sends_nothing()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->ship($order)->assertJsonPath('status', 200);
        $this->calls = [];
        $this->mails()->flush();

        $res = $this->ship($order, 'an-post', 'CE123456789IE');

        $res->assertJsonPath('status', 200);
        $this->assertSame('unchanged', $res->json('data.notified'));
        $this->assertCount(0, $this->calls, 'a double click must not reach AfterShip');
        $this->assertCount(0, $this->messagesTo($order->contact_email));
        $this->assertSame(1, OrderShipment::where('order_id', $order->id)->count());
    }

    public function test_an_order_already_on_its_way_gets_one_tracking_email_and_corrections_do_not_resend()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->asAdmin();
        $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => $this->s('ON_THE_WAY')])
            ->assertJsonPath('status', 200);
        $this->assertCount(1, $this->messagesTo($order->contact_email), 'the plain on-the-way email');
        $this->mails()->flush();

        $res = $this->ship($order);
        $this->assertSame('tracking_email', $res->json('data.notified'));

        $mails = $this->messagesTo($order->contact_email);
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Tracking details for order', $mails[0]->getSubject());
        $this->assertStringContainsString('CE123456789IE', $mails[0]->getBody());

        // A corrected number goes to AfterShip, but the customer is not emailed twice:
        // the link they already have shows the corrected details.
        $this->mails()->flush();
        $this->ship($order, 'dpd-ireland', '15501234567890')->assertJsonPath('status', 200);
        $this->assertSame('15501234567890', OrderShipment::where('order_id', $order->id)->value('tracking_number'));
        $this->assertCount(0, $this->messagesTo($order->contact_email));
        $this->assertSame(1, OrderEmailLog::where('order_id', $order->id)->where('event', 'tracking_added')->count());
    }

    /**
     * Found by the live end-to-end run: a shipment saved at Confirmed (its
     * "on the way" email already carried the tracking), then corrected, used
     * to send a second "tracking details" email.
     */
    public function test_correcting_a_shipment_never_emails_the_customer_again()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->ship($order)->assertJsonPath('status', 200);
        $this->assertCount(1, $this->messagesTo($order->contact_email));
        $this->mails()->flush();

        $res = $this->ship($order, 'dpd-ireland', '15501234567890');

        $res->assertJsonPath('status', 200);
        $this->assertSame('corrected', $res->json('data.notified'));
        $this->assertSame('15501234567890', OrderShipment::where('order_id', $order->id)->value('tracking_number'));
        $this->assertCount(0, $this->messagesTo($order->contact_email));
        $this->assertSame(0, OrderEmailLog::where('order_id', $order->id)->where('event', 'tracking_added')->count());
    }

    public function test_a_tracking_aftership_already_holds_is_adopted_not_duplicated()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $existing = $this->remote('CE123456789IE', 'an-post', ['id' => 'existing0000000000000000000000ab', 'tag' => 'InTransit']);

        $this->answer = function ($method, $path) use ($existing) {
            if ($method === 'POST') {
                return [400, ['meta' => ['code' => 4003, 'message' => 'Tracking already exists.', 'type' => 'BadRequest'],
                    'data' => ['id' => $existing['id'], 'slug' => 'an-post', 'tracking_number' => 'CE123456789IE']]];
            }
            return [200, ['meta' => ['code' => 200], 'data' => $existing]];
        };

        $this->ship($order)->assertJsonPath('status', 200);

        $this->assertSame(['POST', 'GET'], array_column($this->calls, 0));
        $this->assertSame('trackings/' . $existing['id'], $this->calls[1][1]);
        $row = OrderShipment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($existing['id'], $row->aftership_id);
        $this->assertSame('InTransit', $row->tag);
    }

    /** Every refusal leaves the order as it was: no row, no status change, no email. */
    private function assertNothingHappened(Order $order, $status)
    {
        $this->assertSame(0, OrderShipment::where('order_id', $order->id)->count());
        $this->assertSame($status, (int)$order->fresh()->status);
        $this->assertCount(0, $this->messagesTo($order->contact_email));
    }

    public function test_a_courier_outside_the_list_is_refused_before_aftership_is_called()
    {
        $order = $this->order($this->s('CONFIRMED'));

        $res = $this->ship($order, 'made-up-courier', 'CE123456789IE');

        $this->assertSame(201, $res->json('status'));
        $this->assertStringContainsString('not activated in your AfterShip account', $res->json('data.fields.courier.0'));
        $this->assertCount(0, $this->calls);
        $this->assertNothingHappened($order, $this->s('CONFIRMED'));
    }

    public function test_malformed_tracking_numbers_are_refused_before_aftership_is_called()
    {
        $order = $this->order($this->s('CONFIRMED'));

        // The last two were ACCEPTED by AfterShip for An Post in the live run.
        foreach (['', 'ab', '<script>x</script>', str_repeat('9', 65), "CE1'; DROP", 'ZZ-NOT-A-PARCEL', 'A--------B'] as $bad) {
            $res = $this->ship($order, 'an-post', $bad);
            $this->assertSame(201, $res->json('status'), "accepted: $bad");
            $this->assertNotEmpty($res->json('data.fields.tracking_number'), "no field error for: $bad");
        }

        $this->assertCount(0, $this->calls);
        $this->assertNothingHappened($order, $this->s('CONFIRMED'));
    }

    public function test_a_number_aftership_rejects_shows_its_reason_and_saves_nothing()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->answer = function () {
            return [400, ['meta' => ['code' => 4005, 'message' => 'The value of `tracking_number` is invalid.', 'type' => 'BadRequest'], 'data' => []]];
        };

        $res = $this->ship($order, 'an-post', 'NOTREAL1');

        $this->assertSame(201, $res->json('status'));
        $this->assertStringContainsString('not valid for the selected courier', $res->json('data.fields.tracking_number.0'));
        $this->assertNothingHappened($order, $this->s('CONFIRMED'));
    }

    public function test_unsupported_courier_bad_key_outage_and_unreachable_are_reported_plainly()
    {
        $cases = [
            'unsupported courier' => [[400, ['meta' => ['code' => 4010, 'message' => 'The value of `slug` is invalid.']]], 'does not recognise that courier'],
            'invalid key' => [[401, ['meta' => ['code' => 401, 'message' => 'The API key is invalid.', 'type' => 'Unauthorized'], 'data' => []]], 'rejected the API key'],
            'aftership 500' => [[500, ['meta' => ['code' => 500, 'message' => 'Something went wrong']]], 'problem on its side'],
            'not json' => [[502, ['html' => 'Bad gateway']], 'problem on its side'],
        ];

        foreach ($cases as $label => list($response, $expect)) {
            $order = $this->order($this->s('CONFIRMED'));
            $this->answer = function () use ($response) { return $response; };

            $res = $this->ship($order);

            $this->assertSame(201, $res->json('status'), $label);
            $this->assertStringContainsString($expect, $res->json('data.form.0'), $label);
            $this->assertNothingHappened($order, $this->s('CONFIRMED'));
        }

        $order = $this->order($this->s('CONFIRMED'));
        $this->throwConnection = true;
        $res = $this->ship($order);
        $this->assertStringContainsString('could not be reached', $res->json('data.form.0'));
        $this->assertNothingHappened($order, $this->s('CONFIRMED'));
    }

    public function test_a_rate_limited_create_is_retried_once_then_succeeds()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $n = 0;
        $this->answer = function ($method, $path, $body) use (&$n) {
            if (++$n === 1) {
                return [429, ['meta' => ['code' => 429, 'message' => 'Too many requests', 'type' => 'TooManyRequests']],
                    ['X-RateLimit-Reset' => (string)time()]];
            }
            return [201, ['meta' => ['code' => 201], 'data' => $this->remote($body['tracking_number'])]];
        };

        $this->ship($order)->assertJsonPath('status', 200);

        $this->assertCount(2, $this->calls);
        $this->assertSame(1, OrderShipment::where('order_id', $order->id)->count());
    }

    public function test_a_rate_limit_that_persists_is_reported_and_saves_nothing()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->answer = function () {
            return [429, ['meta' => ['code' => 429, 'message' => 'Too many requests']], ['X-RateLimit-Reset' => (string)time()]];
        };

        $res = $this->ship($order);

        $this->assertCount(2, $this->calls, 'one retry, not a loop');
        $this->assertStringContainsString('rate limit', $res->json('data.form.0'));
        $this->assertNothingHappened($order, $this->s('CONFIRMED'));
    }

    public function test_pending_or_finished_orders_cannot_be_given_a_shipment()
    {
        foreach (['PENDING', 'DELIVERED', 'CANCELLED'] as $status) {
            $order = $this->order($this->s($status));

            $res = $this->ship($order);

            $this->assertSame(201, $res->json('status'), $status);
            $this->assertStringContainsString('Confirm the order first', $res->json('data.form.0'), $status);
            $this->assertCount(0, $this->calls, $status);
            $this->assertNothingHappened($order, $this->s($status));
        }
    }

    public function test_the_shipment_endpoints_need_an_admin()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->app['auth']->forgetGuards();

        $res = $this->postJson('/api/admin/order/shipment', ['id' => $order->id, 'courier' => 'an-post', 'tracking_number' => 'CE123456789IE']);

        $this->assertNotSame(200, $res->json('status'));
        $this->assertCount(0, $this->calls);
        $this->assertNothingHappened($order, $this->s('CONFIRMED'));
    }

    private function shipped()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->ship($order)->assertJsonPath('status', 200);
        $this->mails()->flush();
        $this->calls = [];

        return [$order->fresh(), OrderShipment::where('order_id', $order->id)->firstOrFail()];
    }

    public function test_webhook_refuses_unsigned_badly_signed_and_unconfigured_calls()
    {
        list($order, $row) = $this->shipped();
        $msg = $this->remote($row->tracking_number, 'an-post', ['tag' => 'Delivered', 'updated_at' => '2026-10-17T10:00:00+00:00']);

        $this->webhook($msg, 'wrong-secret')->assertStatus(401);

        $raw = json_encode(['msg' => $msg]);
        $this->call('POST', '/api/v1/aftership/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
            ->assertStatus(401);

        config(['aftership.webhook_secret' => '']);
        $this->webhook($msg, '')->assertStatus(503);

        $this->assertSame('Pending', $row->fresh()->tag);
        $this->assertSame($this->s('ON_THE_WAY'), (int)$order->fresh()->status);
    }

    public function test_webhook_updates_status_keeps_only_town_level_places_and_delivers_the_order_once()
    {
        list($order, $row) = $this->shipped();

        $this->webhook($this->remote($row->tracking_number, 'an-post', [
            'tag' => 'InTransit', 'subtag_message' => 'In Transit', 'updated_at' => '2026-10-16T12:00:00+00:00',
            'checkpoints' => [[
                'checkpoint_time' => '2026-10-16T11:00:00+01:00', 'tag' => 'InTransit', 'message' => 'Item accepted',
                'city' => 'Dublin', 'state' => 'Leinster', 'country_region' => 'IRL', 'country_region_name' => 'Ireland',
                'location' => '12 Private Street, Dublin 4', 'coordinate' => ['latitude' => 53.3, 'longitude' => -6.2],
            ]],
        ]))->assertStatus(200)->assertJsonPath('updated', 1);

        $row->refresh();
        $this->assertSame('InTransit', $row->tag);
        $this->assertSame('Dublin, Leinster, Ireland', $row->checkpoints[0]['place']);
        $this->assertStringNotContainsString('Private Street', json_encode($row->checkpoints));
        $this->assertStringNotContainsString('53.3', json_encode($row->checkpoints));
        $this->assertSame($this->s('ON_THE_WAY'), (int)$order->fresh()->status);

        $delivered = $this->remote($row->tracking_number, 'an-post', ['tag' => 'Delivered', 'updated_at' => '2026-10-17T09:00:00+00:00']);
        $this->webhook($delivered)->assertStatus(200);
        $this->webhook($delivered)->assertStatus(200); // AfterShip retries; must be harmless

        $this->assertSame($this->s('DELIVERED'), (int)$order->fresh()->status);
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)
            ->where('to_status', $this->s('DELIVERED'))->where('changed_by_type', 'aftership')->count());
        $this->assertCount(1, $this->messagesTo($order->contact_email), 'one delivered email');
    }

    public function test_an_older_webhook_never_overwrites_a_newer_status()
    {
        list($order, $row) = $this->shipped();
        config(['aftership.auto_deliver' => false]);

        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'OutForDelivery', 'updated_at' => '2026-10-17T08:00:00+00:00']));
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'InTransit', 'updated_at' => '2026-10-16T08:00:00+00:00']))
            ->assertJsonPath('updated', 0);

        $this->assertSame('OutForDelivery', $row->fresh()->tag);
    }

    public function test_delivered_scan_never_revives_a_cancelled_order()
    {
        list($order, $row) = $this->shipped();
        $this->asAdmin();
        $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => $this->s('CANCELLED')]);
        $this->mails()->flush();

        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'Delivered', 'updated_at' => '2026-10-18T09:00:00+00:00']))
            ->assertStatus(200);

        $this->assertSame($this->s('CANCELLED'), (int)$order->fresh()->status);
        $this->assertCount(0, $this->messagesTo($order->contact_email));
    }

    public function test_webhook_for_a_tracking_we_do_not_hold_is_acknowledged_and_ignored()
    {
        $this->webhook($this->remote('UNKNOWN123', 'ups', ['id' => 'nobody00000000000000000000000000']))
            ->assertStatus(200)->assertJsonPath('updated', 0);
    }

    public function test_public_tracking_shows_courier_and_status_but_the_number_and_trail_need_the_signed_link()
    {
        list($order, $row) = $this->shipped();
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'InTransit', 'updated_at' => '2026-10-16T12:00:00+00:00',
            'checkpoints' => [['checkpoint_time' => '2026-10-16T11:00:00+01:00', 'tag' => 'InTransit', 'message' => 'Item accepted', 'city' => 'Dublin']]]));
        $this->app['auth']->forgetGuards();

        $anon = $this->getJson('/api/v1/track-order?tracking_id=' . $order->order)->json('data.shipment');
        $this->assertSame('An Post', $anon['courier']);
        $this->assertSame('In transit', $anon['status']);
        $this->assertFalse($anon['full']);
        $this->assertStringEndsWith('89IE', $anon['tracking_number']);
        $this->assertStringNotContainsString('CE1234567', $anon['tracking_number']);
        $this->assertArrayNotHasKey('checkpoints', $anon);
        $this->assertArrayNotHasKey('url', $anon);

        $forged = $this->getJson('/api/v1/track-order?tracking_id=' . $order->order . '&t=' . str_repeat('a', 32))->json('data.shipment');
        $this->assertFalse($forged['full']);

        parse_str(parse_url(ShipmentTracking::trackUrl($order), PHP_URL_QUERY), $q);
        $this->assertSame($order->order, $q['order_id']);
        $signed = $this->getJson('/api/v1/track-order?tracking_id=' . $order->order . '&t=' . $q['t'])->json('data.shipment');
        $this->assertTrue($signed['full']);
        $this->assertSame('CE123456789IE', $signed['tracking_number']);
        $this->assertSame('Dublin', $signed['checkpoints'][0]['place']);
        $this->assertStringStartsWith('https://www.anpost.com/', $signed['url']);

        // A signature is for one order only.
        $other = $this->order($this->s('CONFIRMED'));
        $this->assertFalse(ShipmentTracking::verifySignature($other, $q['t']));
    }

    public function test_sync_command_refreshes_stale_in_flight_shipments_from_aftership()
    {
        list($order, $row) = $this->shipped();
        // A webhook is configured in setUp, so the safety-net window is 12 hours.
        OrderShipment::where('id', $row->id)->update(['last_synced_at' => now()->subHours(13)]);
        config(['aftership.auto_deliver' => false]);

        $this->answer = function ($method, $path) use ($row) {
            return [200, ['meta' => ['code' => 200], 'data' => $this->remote($row->tracking_number, 'an-post',
                ['id' => $row->aftership_id, 'tag' => 'OutForDelivery', 'updated_at' => '2026-10-17T07:00:00+00:00'])]];
        };

        $this->artisan('aftership:sync')->assertExitCode(0);

        $this->assertContains(['GET', 'trackings/' . $row->aftership_id], array_map(function ($c) {
            return [$c[0], $c[1]];
        }, $this->calls));
        $this->assertSame('OutForDelivery', $row->fresh()->tag);
    }

    public function test_admin_refresh_reads_aftership_and_records_a_failure_on_the_row()
    {
        list($order, $row) = $this->shipped();

        $this->answer = function () use ($row) {
            return [200, ['meta' => ['code' => 200], 'data' => $this->remote($row->tracking_number, 'an-post',
                ['id' => $row->aftership_id, 'tag' => 'AttemptFail', 'subtag_message' => 'Addressee not available', 'updated_at' => '2026-10-17T07:00:00+00:00'])]];
        };

        $this->asAdmin();
        $res = $this->postJson('/api/admin/order/shipment-refresh/' . $order->id);
        $res->assertJsonPath('status', 200);
        $this->assertSame('Delivery attempt failed', $res->json('data.shipment.tag_label'));

        $this->answer = function () { return [500, ['meta' => ['code' => 500, 'message' => 'down']]]; };
        $res = $this->postJson('/api/admin/order/shipment-refresh/' . $order->id);
        $this->assertSame(201, $res->json('status'));
        $this->assertStringContainsString('problem on its side', $row->fresh()->last_error);
    }

    public function test_admin_order_screen_gets_the_shipment_and_courier_list_but_never_the_key()
    {
        list($order, $row) = $this->shipped();
        $this->asAdmin();

        $res = $this->getJson('/api/admin/order/find/' . $order->id);

        $res->assertJsonPath('status', 200);
        $this->assertSame('CE123456789IE', $res->json('data.shipment_tracking.tracking_number'));
        $couriers = $this->getJson('/api/admin/order/shipment-couriers');
        $this->assertContains('an-post', array_column($couriers->json('data.active'), 'slug'));
        $this->assertStringNotContainsString('asat_test_key_never_real', $couriers->getContent());
        $this->assertTrue($res->json('data.shipment_allowed'));
        $this->assertStringNotContainsString('asat_test_key_never_real', $res->getContent());
        $this->assertStringNotContainsString('whsec-test', $res->getContent());
    }

    // ---- Follow-up work (2026-10-19): staff alerts, delivery emails, clean-up, My order ----

    private function supportEmail()
    {
        $to = \App\Models\Helper\MailBrand::make()->support_email;
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->markTestSkipped('No shop support address configured to alert.');
        }
        return $to;
    }

    private function deletes()
    {
        return array_values(array_filter($this->calls, function ($c) { return $c[0] === 'DELETE'; }));
    }

    public function test_a_parcel_with_no_courier_scan_after_48_hours_alerts_staff_once()
    {
        $support = $this->supportEmail();
        list($order, $row) = $this->shipped();

        // 47 hours: not yet.
        OrderShipment::where('id', $row->id)->update(['tracking_set_at' => now()->subHours(47)]);
        $this->assertSame(0, ShipmentTracking::checkUnscanned());
        $this->assertNull($row->fresh()->attention());

        OrderShipment::where('id', $row->id)->update(['tracking_set_at' => now()->subHours(49), 'last_synced_at' => now()->subHours(2)]);
        $this->answer = function () use ($row) {
            return [200, ['meta' => ['code' => 200], 'data' => $this->remote($row->tracking_number, 'an-post', ['id' => $row->aftership_id])]];
        };
        $this->artisan('aftership:sync')->assertExitCode(0);

        $alerts = $this->messagesTo($support)->filter(function ($m) use ($order) {
            return strpos($m->getSubject(), 'No courier scan yet: order ' . $order->order) === 0;
        });
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString($row->tracking_number, $alerts->first()->getBody());
        $this->assertSame(['no_scan'], $row->fresh()->staff_alerts);

        // The admin screen shows it too.
        $this->asAdmin();
        $this->assertSame('no_scan', $this->getJson('/api/admin/order/find/' . $order->id)->json('data.shipment_tracking.attention.reason'));

        // The next hourly run does not repeat it, and the customer heard nothing.
        $this->mails()->flush();
        $this->artisan('aftership:sync')->assertExitCode(0);
        $this->assertCount(0, $this->messagesTo($support));
        $this->assertCount(0, $this->messagesTo($order->contact_email));
    }

    public function test_out_for_delivery_and_a_failed_attempt_each_email_the_customer_once()
    {
        list($order, $row) = $this->shipped();
        config(['aftership.auto_deliver' => true]);

        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'OutForDelivery', 'updated_at' => '2026-10-17T07:00:00+00:00']));
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'OutForDelivery', 'updated_at' => '2026-10-17T07:30:00+00:00']));

        $subjects = $this->messagesTo($order->contact_email)->map(function ($m) { return $m->getSubject(); })->all();
        $this->assertCount(1, $subjects);
        $this->assertStringContainsString('Out for delivery today', $subjects[0]);
        $body = $this->messagesTo($order->contact_email)->first()->getBody();
        $this->assertStringContainsString($row->tracking_number, $body);
        $this->assertStringContainsString('Track your parcel', $body);

        $this->mails()->flush();
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'AttemptFail', 'subtag_message' => 'Addressee not available', 'updated_at' => '2026-10-17T13:00:00+00:00']));
        // Out again next day, then a second failure: neither emails a second time.
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'OutForDelivery', 'updated_at' => '2026-10-18T07:00:00+00:00']));
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'AttemptFail', 'updated_at' => '2026-10-18T13:00:00+00:00']));

        $subjects = $this->messagesTo($order->contact_email)->map(function ($m) { return $m->getSubject(); })->all();
        $this->assertCount(1, $subjects);
        $this->assertStringContainsString('We missed you', $subjects[0]);
        $this->assertSame($this->s('ON_THE_WAY'), (int)$order->fresh()->status);
    }

    public function test_a_failed_attempt_and_an_exception_alert_staff_once_each()
    {
        $support = $this->supportEmail();
        list($order, $row) = $this->shipped();

        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'AttemptFail', 'updated_at' => '2026-10-17T13:00:00+00:00']));
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'OutForDelivery', 'updated_at' => '2026-10-18T07:00:00+00:00']));
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'AttemptFail', 'updated_at' => '2026-10-18T13:00:00+00:00']));
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'Exception', 'subtag_message' => 'Returning to sender', 'updated_at' => '2026-10-19T09:00:00+00:00']));

        $staff = $this->messagesTo($support)->map(function ($m) { return $m->getSubject(); })->values()->all();
        $this->assertSame([
            'Delivery attempt failed: order ' . $order->order,
            'Delivery problem: order ' . $order->order,
        ], $staff);
        $this->assertSame(['AttemptFail', 'Exception'], $row->fresh()->staff_alerts);

        // The exception itself sends the customer nothing (staff decide what to tell them).
        $customer = $this->messagesTo($order->contact_email)->map(function ($m) { return $m->getSubject(); })->all();
        $this->assertCount(2, $customer, 'one out-for-delivery and one missed-you email only');
    }

    public function test_no_alerts_or_customer_emails_for_a_cancelled_order()
    {
        $support = $this->supportEmail();
        list($order, $row) = $this->shipped();
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'InTransit', 'updated_at' => '2026-10-17T07:00:00+00:00']));
        $this->asAdmin();
        $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => $this->s('CANCELLED')]);
        $this->mails()->flush();

        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'OutForDelivery', 'updated_at' => '2026-10-18T07:00:00+00:00']));
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'Exception', 'updated_at' => '2026-10-18T09:00:00+00:00']));

        $this->assertCount(0, $this->messagesTo($order->contact_email));
        $this->assertCount(0, $this->messagesTo($support));
    }

    public function test_cancelling_before_the_courier_scan_removes_the_tracking_from_aftership()
    {
        list($order, $row) = $this->shipped();
        $id = $row->aftership_id;

        $this->asAdmin();
        $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => $this->s('CANCELLED')])
            ->assertJsonPath('status', 200);

        $this->assertSame([['DELETE', 'trackings/' . $id]], array_map(function ($c) { return [$c[0], $c[1]]; }, $this->deletes()));
        $this->assertNull($row->fresh()->aftership_id);
        $this->assertStringContainsString('Order cancelled before the courier scanned', $row->fresh()->last_error);
        $this->assertSame($this->s('CANCELLED'), (int)$order->fresh()->status);
    }

    public function test_cancelling_after_a_scan_keeps_tracking_so_the_return_can_be_followed()
    {
        list($order, $row) = $this->shipped();
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'InTransit', 'updated_at' => '2026-10-17T07:00:00+00:00']));
        $this->calls = [];

        $this->asAdmin();
        $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => $this->s('CANCELLED')]);

        $this->assertCount(0, $this->deletes());
        $this->assertSame($row->aftership_id, $row->fresh()->aftership_id);
    }

    public function test_aftership_being_down_never_blocks_a_cancellation()
    {
        list($order, $row) = $this->shipped();
        $this->answer = function () { return [500, ['meta' => ['code' => 500, 'message' => 'down']]]; };

        $this->asAdmin();
        $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => $this->s('CANCELLED')])
            ->assertJsonPath('status', 200);

        $this->assertSame($this->s('CANCELLED'), (int)$order->fresh()->status);
    }

    public function test_a_corrected_number_removes_the_old_tracking_unless_another_order_shares_it()
    {
        list($order, $row) = $this->shipped();
        $old = $row->aftership_id;

        $this->ship($order, 'dpd-ireland', '15501234567890')->assertJsonPath('status', 200);
        $this->assertSame([['DELETE', 'trackings/' . $old]], array_map(function ($c) { return [$c[0], $c[1]]; }, $this->deletes()));

        // Two orders in one parcel: correcting one must not delete the other's tracking.
        $a = $this->order($this->s('CONFIRMED'));
        $b = $this->order($this->s('CONFIRMED'));
        $this->ship($a, 'an-post', 'CE111111111IE');
        $this->ship($b, 'an-post', 'CE111111111IE');
        $shared = OrderShipment::where('order_id', $a->id)->value('aftership_id');
        $this->assertSame($shared, OrderShipment::where('order_id', $b->id)->value('aftership_id'));
        $this->calls = [];

        $this->ship($a, 'an-post', 'CE222222222IE')->assertJsonPath('status', 200);
        $this->assertCount(0, $this->deletes(), 'order B still uses the old tracking');
    }

    public function test_a_failed_clean_up_does_not_fail_the_correction()
    {
        list($order, $row) = $this->shipped();
        $this->answer = function ($method, $path, $body) {
            if ($method === 'DELETE') {
                return [500, ['meta' => ['code' => 500, 'message' => 'down']]];
            }
            return [201, ['meta' => ['code' => 201], 'data' => $this->remote($body['tracking_number'] ?? 'X', $body['slug'] ?? 'an-post')]];
        };

        $this->ship($order, 'dpd-ireland', '15501234567890')->assertJsonPath('status', 200);
        $this->assertSame('15501234567890', $row->fresh()->tracking_number);
    }

    public function test_my_order_page_gets_the_full_parcel_for_its_owner_only()
    {
        $token = 'aftership-guest-' . uniqid();
        \App\Models\GuestUser::create(['user_token' => $token]);
        $order = $this->order($this->s('CONFIRMED'), ['user_token' => $token, 'time_zone' => 'Europe/Dublin']);
        $this->ship($order)->assertJsonPath('status', 200);
        $this->app['auth']->forgetGuards();

        $mine = $this->postJson('/api/v1/order/by-user', ['order_id' => $order->id, 'user_token' => $token, 'time_zone' => 'Europe/Dublin']);
        $mine->assertJsonPath('status', 200);
        $this->assertSame('CE123456789IE', $mine->json('data.parcel.tracking_number'));
        $this->assertTrue($mine->json('data.parcel.full'));
        $this->assertSame(ShipmentTracking::trackUrl($order->fresh()), $mine->json('data.parcel_track_url'));

        // Someone else's guest token gets nothing.
        $other = 'aftership-guest-' . uniqid();
        \App\Models\GuestUser::create(['user_token' => $other]);
        $theirs = $this->postJson('/api/v1/order/by-user', ['order_id' => $order->id, 'user_token' => $other, 'time_zone' => 'Europe/Dublin']);
        $this->assertNotSame(200, $theirs->json('status'));
        $this->assertStringNotContainsString('CE123456789IE', $theirs->getContent());
    }

    // ---- Couriers from AfterShip's API, not a typed-in list (2026-10-19) ----

    private function couriersApi(array $params = [])
    {
        $this->asAdmin();
        return $this->getJson('/api/admin/order/shipment-couriers' . ($params ? '?' . http_build_query($params) : ''));
    }

    private function getCalls($path)
    {
        return array_values(array_filter($this->calls, function ($c) use ($path) { return $c[0] === 'GET' && $c[1] === $path; }));
    }

    public function test_the_courier_list_is_fetched_from_aftership_then_served_from_the_stored_copy()
    {
        foreach (glob($this->courierStore . '/*') as $file) { unlink($file); }
        \App\Models\Helper\CourierDirectory::flush();

        $res = $this->couriersApi();
        $res->assertJsonPath('status', 200);
        $this->assertCount(2, $this->getCalls('couriers'), 'activated list + full list, from AfterShip');
        $this->assertSame(3, $res->json('data.total'), 'activated couriers + the test courier');
        $this->assertFileExists($this->courierStore . '/couriers-all.json');

        // A fresh request (new process) reads the stored copy: no API call.
        \App\Models\Helper\CourierDirectory::flush();
        $this->calls = [];
        $this->couriersApi()->assertJsonPath('status', 200);
        $this->assertCount(0, $this->getCalls('couriers'));

        // The refresh link re-reads AfterShip on demand.
        \App\Models\Helper\CourierDirectory::flush();
        $this->couriersApi(['refresh' => 1])->assertJsonPath('status', 200);
        $this->assertCount(2, $this->getCalls('couriers'));
    }

    public function test_only_couriers_activated_in_aftership_are_offered()
    {
        $res = $this->couriersApi();

        $this->assertSame(['an-post', 'dpd-ireland', 'testing-courier'], array_column($res->json('data.active'), 'slug'),
            'the account\'s activated couriers, plus the test courier outside production');
        $this->assertSame(3, $res->json('data.total'));
        $this->assertNull($res->json('data.results'), 'no search of AfterShip\'s full list any more');
        $this->assertNotContains('dpd', array_column($res->json('data.active'), 'slug'), 'listed by AfterShip but not activated');
    }

    public function test_a_courier_aftership_lists_but_the_account_has_not_activated_is_refused()
    {
        $order = $this->order($this->s('CONFIRMED'));

        $res = $this->ship($order, 'dpd', 'DPD12345678');

        $this->assertStringContainsString('Activate it in AfterShip', $res->json('data.form.0'));
        $this->assertCount(0, array_filter($this->calls, function ($c) { return $c[0] === 'POST'; }));
        $this->assertSame(0, OrderShipment::where('order_id', $order->id)->count());
    }

    public function test_an_activated_courier_carries_its_extra_fields()
    {
        $this->seedCouriers(['an-post', 'gls-netherlands', 'nacex-spain-reference']);
        $active = collect($this->couriersApi()->json('data.active'))->keyBy('slug');

        $this->assertSame(['destination_postal_code' => 'Delivery postcode'], $active['gls-netherlands']['auto_fields']);
        $this->assertSame(['tracking_account_number' => 'Courier account number', 'tracking_key' => 'Courier tracking key'],
            $active['nacex-spain-reference']['manual_fields']);
        $this->assertSame([], $active['an-post']['auto_fields']);
    }
    public function test_a_stale_copy_is_used_when_aftership_is_down_and_a_clear_error_when_there_is_none()
    {
        $this->answer = function () { return [500, ['meta' => ['code' => 500, 'message' => 'down']]]; };

        // Week-old copy: still usable, AfterShip was asked.
        foreach (['all', 'active'] as $kind) {
            $p = $this->courierStore . "/couriers-$kind.json";
            $d = json_decode(file_get_contents($p), true); $d['fetched_at'] = time() - 7 * 86400; file_put_contents($p, json_encode($d));
        }
        \App\Models\Helper\CourierDirectory::flush();
        $res = $this->couriersApi();
        $res->assertJsonPath('status', 200);
        $this->assertContains('an-post', array_column($res->json('data.active'), 'slug'));
        $this->assertNotEmpty($this->getCalls('couriers'));

        // No copy at all: the admin is told why.
        foreach (glob($this->courierStore . '/*') as $file) { unlink($file); }
        \App\Models\Helper\CourierDirectory::flush();
        $res = $this->couriersApi();
        $this->assertSame(201, $res->json('status'));
        $this->assertStringContainsString('problem on its side', $res->json('data.form.0'));
    }

    public function test_production_fetches_only_the_activated_list()
    {
        config(['aftership.allow_test_courier' => false]);
        foreach (glob($this->courierStore . '/*') as $file) { unlink($file); }
        \App\Models\Helper\CourierDirectory::flush();

        $this->couriersApi()->assertJsonPath('status', 200);

        $this->assertCount(1, $this->getCalls('couriers'), 'one call: the activated list; not the 2,000+ full list');
        $this->assertFileDoesNotExist($this->courierStore . '/couriers-all.json');
    }

    public function test_the_test_courier_is_hidden_and_refused_where_not_allowed()
    {
        config(['aftership.allow_test_courier' => false]);

        $this->assertNotContains('testing-courier', array_column($this->couriersApi()->json('data.active'), 'slug'));


        $order = $this->order($this->s('CONFIRMED'));
        $res = $this->ship($order, 'testing-courier', 'ITD-1-1234567890');
        $this->assertStringContainsString('not activated in your AfterShip account', $res->json('data.form.0'));
        $this->assertCount(0, array_filter($this->calls, function ($c) { return $c[0] === 'POST'; }));
    }

    public function test_a_courier_needing_a_postcode_gets_it_from_the_order()
    {
        $this->seedCouriers(['an-post', 'gls-netherlands']);
        $address = \App\Models\UserAddress::create(['name' => 'Fixture', 'phone' => '0871234567', 'country' => 'IE', 'state' => 'D',
            'city' => 'Dublin', 'zip' => 'D02 XY45', 'address_1' => '1 Test Street', 'type' => 1, 'default' => 2]);
        $order = $this->order($this->s('CONFIRMED'), ['user_address_id' => $address->id]);

        $this->ship($order, 'gls-netherlands', 'GLS12345678')->assertJsonPath('status', 200);

        $create = array_values(array_filter($this->calls, function ($c) { return $c[0] === 'POST' && $c[1] === 'trackings'; }))[0][2];
        $this->assertSame('D02 XY45', $create['destination_postal_code']);
        $this->assertSame('IRL', $create['destination_country_region']);
        $this->assertSame('GLS Netherlands', OrderShipment::where('order_id', $order->id)->value('courier_name'));
    }

    public function test_a_courier_needing_a_postcode_is_refused_when_the_order_has_none()
    {
        $this->seedCouriers(['an-post', 'gls-netherlands']);
        $order = $this->order($this->s('CONFIRMED'));

        $res = $this->ship($order, 'gls-netherlands', 'GLS12345678');

        $this->assertSame(201, $res->json('status'));
        $this->assertStringContainsString('GLS Netherlands also needs: Delivery postcode', $res->json('data.form.0'));
        $this->assertCount(0, array_filter($this->calls, function ($c) { return $c[0] === 'POST'; }));
    }

    public function test_account_number_and_key_are_asked_of_the_admin_when_the_courier_needs_them()
    {
        $this->seedCouriers(['an-post', 'nacex-spain-reference']);
        $order = $this->order($this->s('CONFIRMED'));
        $this->asAdmin();

        $res = $this->postJson('/api/admin/order/shipment', ['id' => $order->id, 'courier' => 'nacex-spain-reference', 'tracking_number' => 'NX123456']);
        $this->assertStringContainsString('also needs: Courier account number, Courier tracking key', $res->json('data.form.0'));
        $this->assertNotEmpty($res->json('data.fields')['fields.tracking_account_number'] ?? null);

        $res = $this->postJson('/api/admin/order/shipment', ['id' => $order->id, 'courier' => 'nacex-spain-reference', 'tracking_number' => 'NX123456',
            'fields' => ['tracking_account_number' => 'ACC123', 'tracking_key' => 'key-9']]);
        $res->assertJsonPath('status', 200);
        $create = array_values(array_filter($this->calls, function ($c) { return $c[0] === 'POST' && $c[1] === 'trackings'; }))[0][2];
        $this->assertSame('ACC123', $create['tracking_account_number']);
        $this->assertSame('key-9', $create['tracking_key']);

        // Junk in those fields never reaches AfterShip.
        $res = $this->postJson('/api/admin/order/shipment', ['id' => $order->id, 'courier' => 'nacex-spain-reference', 'tracking_number' => 'NX999999',
            'fields' => ['tracking_account_number' => '<script>', 'tracking_key' => 'k']]);
        $this->assertSame(201, $res->json('status'));
    }

    public function test_the_courier_endpoints_need_an_admin()
    {
        $this->app['auth']->forgetGuards();

        $this->assertNotSame(200, $this->getJson('/api/admin/order/shipment-couriers')->json('status'));

        $this->assertCount(0, $this->calls);
    }

    public function test_a_status_message_that_repeats_the_status_is_not_shown_twice()
    {
        list($order, $row) = $this->shipped();
        $this->assertNull($row->fresh()->adminView()['subtag_message'], '"Awaiting courier scan · Pending" said it twice');

        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'AttemptFail', 'subtag_message' => 'Addressee not available', 'updated_at' => '2026-10-17T13:00:00+00:00']));
        $this->assertSame('Addressee not available', $row->fresh()->adminView()['subtag_message']);
    }

    /** Found on 2026-10-19: the free plan's 100 calls/day ran out and the screen said "try again in a few seconds". */
    public function test_a_spent_daily_allowance_is_reported_as_such_and_not_retried()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->answer = function () {
            return [429, ['meta' => ['code' => 429, 'type' => 'TooManyRequests',
                'message' => 'You have exceeded the API call rate limit. Default limit is 100 request per day for free user. The limit will be reset at 2026-10-20T04:37:00Z.']],
                ['X-RateLimit-Reset' => (string)(time() + 6 * 3600)]];
        };

        $res = $this->ship($order);

        $this->assertCount(1, $this->calls, 'no pointless retry against a daily limit');
        $msg = $res->json('data.form.0');
        $this->assertStringContainsString('daily API limit reached', $msg);
        $this->assertStringContainsString('Irish time', $msg);
        $this->assertStringNotContainsString('few seconds', $msg);
        $this->assertSame(0, OrderShipment::where('order_id', $order->id)->count());
    }

    // ---- Review fixes (2026-10-19) ----

    private function syncedIds()
    {
        return ShipmentTracking::dueForSync()->pluck('order_id')->all();
    }

    private function shipmentRow(Order $order, array $over = [])
    {
        return OrderShipment::create(array_merge([
            'order_id' => $order->id, 'courier_slug' => 'an-post', 'courier_name' => 'An Post',
            'tracking_number' => 'CE' . random_int(100000000, 999999999) . 'IE', 'aftership_id' => 'row' . uniqid(),
            'tag' => 'InTransit', 'tracking_set_at' => now()->subHours(5), 'last_synced_at' => now()->subHours(13),
        ], $over));
    }

    public function test_the_sync_checks_every_12h_with_a_webhook_and_hourly_without()
    {
        $row = $this->shipmentRow($this->order($this->s('ON_THE_WAY')), ['last_synced_at' => now()->subHours(3)]);

        $this->assertNotContains($row->order_id, $this->syncedIds(), 'webhook set: 3h is not stale');
        OrderShipment::where('id', $row->id)->update(['last_synced_at' => now()->subHours(13)]);
        $this->assertContains($row->order_id, $this->syncedIds());

        config(['aftership.webhook_secret' => '']);
        OrderShipment::where('id', $row->id)->update(['last_synced_at' => now()->subHours(2)]);
        $this->assertContains($row->order_id, $this->syncedIds(), 'no webhook: hourly');

        config(['aftership.sync_minutes' => 300]);
        $this->assertNotContains($row->order_id, $this->syncedIds(), 'AFTERSHIP_SYNC_MINUTES overrides');
    }

    public function test_the_sync_skips_finished_orders_and_slows_down_for_long_silent_parcels()
    {
        $delivered = $this->shipmentRow($this->order($this->s('DELIVERED')), ['tag' => 'Pending']);
        $refunded = $this->shipmentRow($this->order($this->s('REFUNDED')));
        $returning = $this->shipmentRow($this->order($this->s('CANCELLED')), ['tag' => 'InTransit']);
        $silent = $this->shipmentRow($this->order($this->s('ON_THE_WAY')), ['tag' => 'Pending', 'tracking_set_at' => now()->subDays(4)]);

        $due = $this->syncedIds();
        $this->assertNotContains($delivered->order_id, $due, 'delivered by hand');
        $this->assertNotContains($refunded->order_id, $due);
        $this->assertContains($returning->order_id, $due, 'cancelled while moving: the return is followed');
        $this->assertNotContains($silent->order_id, $due, 'four days without a scan: once a day, not every 12h');

        OrderShipment::where('id', $silent->id)->update(['last_synced_at' => now()->subHours(25)]);
        $this->assertContains($silent->order_id, $this->syncedIds());
    }

    public function test_a_failed_check_backs_off_and_a_tracking_aftership_lost_is_let_go()
    {
        $row = $this->shipmentRow($this->order($this->s('ON_THE_WAY')));

        $this->answer = function () { return [500, ['meta' => ['code' => 500, 'message' => 'down']]]; };
        $this->artisan('aftership:sync')->assertExitCode(0);
        $this->assertNotContains($row->order_id, $this->syncedIds(), 'a failure still counts as a check');
        $this->assertStringContainsString('problem on its side', $row->fresh()->last_error);

        OrderShipment::where('id', $row->id)->update(['last_synced_at' => now()->subHours(13)]);
        $this->answer = function () { return [404, ['meta' => ['code' => 4004, 'message' => 'Tracking does not exist.', 'type' => 'NotFound']]]; };
        $this->artisan('aftership:sync')->assertExitCode(0);

        $this->assertNull($row->fresh()->aftership_id);
        $this->assertStringContainsString('Save the shipment again', $row->fresh()->last_error);
        $this->assertNotContains($row->order_id, $this->syncedIds());
    }

    public function test_no_scan_is_neither_alerted_nor_shown_for_an_order_delivered_by_hand()
    {
        $support = $this->supportEmail();
        $order = $this->order($this->s('DELIVERED'));
        $row = $this->shipmentRow($order, ['tag' => 'Pending', 'tracking_set_at' => now()->subHours(60)]);

        $this->assertSame(0, ShipmentTracking::checkUnscanned());
        $this->assertNull($row->fresh()->attention());
        $this->assertCount(0, $this->messagesTo($support));
    }

    public function test_a_save_that_stored_the_row_but_did_not_ship_the_order_is_finished_by_the_retry()
    {
        $order = $this->order($this->s('CONFIRMED'));
        // As left by a save whose status change failed after the row was written.
        $this->shipmentRow($order, ['courier_slug' => 'an-post', 'tracking_number' => 'CE123456789IE', 'tag' => 'Pending']);

        $res = $this->ship($order, 'an-post', 'CE123456789IE');

        $res->assertJsonPath('status', 200);
        $this->assertSame('shipped', $res->json('data.notified'));
        $this->assertSame($this->s('ON_THE_WAY'), (int)$order->fresh()->status);
        $this->assertCount(0, array_filter($this->calls, function ($c) { return $c[0] === 'POST'; }), 'no second AfterShip create');
        $this->assertCount(1, $this->messagesTo($order->contact_email));
    }

    public function test_an_order_cancelled_while_aftership_is_called_is_not_told_and_its_tracking_is_removed()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $this->answer = function ($method, $path, $body) use ($order) {
            if ($method === 'POST' && $path === 'trackings') {
                // Another admin cancels during the AfterShip call.
                Order::where('id', $order->id)->update(['status' => $this->s('CANCELLED')]);
                return [201, ['meta' => ['code' => 201], 'data' => $this->remote($body['tracking_number'])]];
            }
            return [200, ['meta' => ['code' => 200], 'data' => []]];
        };

        $res = $this->ship($order);

        $this->assertSame(201, $res->json('status'));
        $this->assertStringContainsString('closed while the shipment was being saved', $res->json('data.form.0'));
        $this->assertCount(0, $this->messagesTo($order->contact_email));
        $this->assertCount(1, $this->deletes(), 'the never-scanned tracking is removed');
        $this->assertNull(OrderShipment::where('order_id', $order->id)->value('aftership_id'));
    }

    public function test_a_second_save_while_the_first_is_running_is_refused()
    {
        $order = $this->order($this->s('CONFIRMED'));
        config(['database.connections.lockprobe' => config('database.connections.' . config('database.default'))]);
        $other = \Illuminate\Support\Facades\DB::connection('lockprobe');
        $other->selectOne('SELECT GET_LOCK(?, 0) AS got', ['aftership-order-' . $order->id]);

        try {
            $res = $this->ship($order);
            $this->assertStringContainsString('already being saved', $res->json('data.form.0'));
            $this->assertCount(0, $this->calls);
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS r', ['aftership-order-' . $order->id]);
            \Illuminate\Support\Facades\DB::purge('lockprobe');
        }

        $this->ship($order)->assertJsonPath('status', 200);
    }

    public function test_adopting_a_tracking_aftership_already_has_as_delivered_delivers_the_order()
    {
        $order = $this->order($this->s('CONFIRMED'));
        $existing = $this->remote('CE123456789IE', 'an-post', ['id' => 'already0000000000000000000000del', 'tag' => 'Delivered']);
        $this->answer = function ($method) use ($existing) {
            if ($method === 'POST') {
                return [400, ['meta' => ['code' => 4003, 'message' => 'Tracking already exists.'], 'data' => ['id' => $existing['id']]]];
            }
            return [200, ['meta' => ['code' => 200], 'data' => $existing]];
        };

        $this->ship($order)->assertJsonPath('status', 200);

        $this->assertSame($this->s('DELIVERED'), (int)$order->fresh()->status);
        $this->assertSame(1, OrderEmailLog::where('order_id', $order->id)->where('event', 'order_delivered')->count());
    }

    public function test_a_staff_alert_whose_email_failed_is_retried_and_two_runs_send_it_once()
    {
        $support = $this->supportEmail();
        $order = $this->order($this->s('ON_THE_WAY'));
        $row = $this->shipmentRow($order, ['tag' => 'Exception']);

        \Illuminate\Support\Facades\Mail::partialMock()->shouldReceive('raw')->once()->andThrow(new \RuntimeException('smtp down'));
        $this->assertFalse(ShipmentTracking::alertStaff($row->fresh(), 'Exception'));
        $this->assertNull($row->fresh()->staff_alerts, 'claim released after the failure');

        \Illuminate\Support\Facades\Mail::clearResolvedInstances();
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
        $this->mails()->flush();

        $stale = $row->fresh();   // a second run that read the row before the first claimed it
        $this->assertTrue(ShipmentTracking::alertStaff($row->fresh(), 'Exception'));
        $this->assertFalse(ShipmentTracking::alertStaff($stale, 'Exception'));
        $this->assertCount(1, $this->messagesTo($support));
    }

    public function test_cancelling_with_only_a_label_created_removes_the_tracking_too()
    {
        list($order, $row) = $this->shipped();
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'InfoReceived', 'updated_at' => '2026-10-17T07:00:00+00:00']));
        $this->calls = [];

        $this->asAdmin();
        $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => $this->s('CANCELLED')]);

        $this->assertCount(1, $this->deletes());
        $this->assertNull($row->fresh()->aftership_id);
    }

    public function test_ready_for_pickup_emails_the_customer_once()
    {
        list($order, $row) = $this->shipped();

        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'AvailableForPickup', 'updated_at' => '2026-10-17T07:00:00+00:00']));
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'AvailableForPickup', 'updated_at' => '2026-10-17T09:00:00+00:00']));

        $subjects = $this->messagesTo($order->contact_email)->map(function ($m) { return $m->getSubject(); })->all();
        $this->assertCount(1, $subjects);
        $this->assertStringContainsString('Ready to collect', $subjects[0]);
    }

    public function test_odd_aftership_shapes_are_survived()
    {
        list($order, $row) = $this->shipped();

        $this->webhook(['id' => $row->aftership_id, 'tag' => null, 'checkpoints' => 'not-a-list', 'subtag_message' => ['x']])->assertStatus(200);
        $this->webhook(['id' => $row->aftership_id, 'tag' => 'InTransit', 'checkpoints' => [['checkpoint_time' => '2026-10-17T08:00:00+00:00', 'city' => 'Dublin'], 'junk', null]])
            ->assertStatus(200)->assertJsonPath('updated', 1);

        $row->refresh();
        $this->assertSame('InTransit', $row->tag);
        $this->assertCount(1, $row->checkpoints);
        $this->assertNotNull($row->remote_updated_at, 'the newest checkpoint stands in for a missing updated_at');

        // An older replay without updated_at is now recognised as older.
        $this->webhook(['id' => $row->aftership_id, 'tag' => 'Pending', 'checkpoints' => [['checkpoint_time' => '2026-10-16T08:00:00+00:00']]])
            ->assertJsonPath('updated', 0);
    }

    public function test_a_new_status_without_its_own_detail_drops_the_old_detail()
    {
        list($order, $row) = $this->shipped();
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'OutForDelivery', 'subtag_message' => 'On the van', 'updated_at' => '2026-10-17T07:00:00+00:00']));
        $this->assertSame('On the van', $row->fresh()->subtag_message);

        $msg = $this->remote($row->tracking_number, 'an-post', ['tag' => 'Delivered', 'updated_at' => '2026-10-17T12:00:00+00:00']);
        unset($msg['subtag'], $msg['subtag_message']);
        $this->webhook($msg);

        $this->assertNull($row->fresh()->subtag_message);
    }

    public function test_scans_are_ordered_by_instant_not_by_text()
    {
        $scans = OrderShipment::publicCheckpoints([
            ['checkpoint_time' => '2026-10-17T09:00:00+01:00', 'message' => 'earlier (08:00 UTC)'],
            ['checkpoint_time' => '2026-10-17T08:30:00+00:00', 'message' => 'later (08:30 UTC)'],
        ]);

        $this->assertSame('later (08:30 UTC)', $scans[0]['message']);
    }

    public function test_the_tracking_link_can_have_its_own_secret()
    {
        $order = $this->order($this->s('ON_THE_WAY'));
        $old = ShipmentTracking::signature($order);

        config(['aftership.link_secret' => 'separate-link-secret']);
        $new = ShipmentTracking::signature($order);

        $this->assertNotSame($old, $new);
        $this->assertTrue(ShipmentTracking::verifySignature($order, $new));
        $this->assertFalse(ShipmentTracking::verifySignature($order, $old));
        $this->assertFalse(ShipmentTracking::verifySignature($order, ['array']));
    }

    public function test_a_failed_courier_list_refresh_backs_off_instead_of_calling_aftership_on_every_request()
    {
        foreach (['all', 'active'] as $kind) {
            $p = $this->courierStore . "/couriers-$kind.json";
            $d = json_decode(file_get_contents($p), true); $d['fetched_at'] = time() - 7 * 86400; file_put_contents($p, json_encode($d));
        }
        $this->answer = function () { return [500, ['meta' => ['code' => 500, 'message' => 'down']]]; };

        \App\Models\Helper\CourierDirectory::flush();
        $this->couriersApi()->assertJsonPath('status', 200);
        $this->assertCount(2, $this->getCalls('couriers'));

        // The next request (a new process) does not ask again for 10 minutes.
        \App\Models\Helper\CourierDirectory::flush();
        $this->calls = [];
        $this->couriersApi()->assertJsonPath('status', 200);
        $this->assertCount(0, $this->getCalls('couriers'));

        // The refresh link still forces it.
        \App\Models\Helper\CourierDirectory::flush();
        $this->couriersApi(['refresh' => 1]);
        $this->assertCount(2, $this->getCalls('couriers'));
    }

    public function test_announce_itself_tells_no_closed_order_anything()
    {
        foreach (['CANCELLED', 'DELIVERED', 'REFUNDED'] as $status) {
            $order = $this->order($this->s($status));
            $this->shipmentRow($order);

            $this->assertSame('order_closed', ShipmentTracking::announce($order), $status);
            $this->assertCount(0, $this->messagesTo($order->contact_email), $status);
            $this->assertSame(0, OrderEmailLog::where('order_id', $order->id)->where('event', 'tracking_added')->count(), $status);
        }
    }

    /** Found on 2026-10-19: FED-7-8464612 sat at Pending / "Wrong carrier" with nothing flagged. */
    public function test_a_wrong_or_unrecognised_courier_is_flagged_and_alerted_at_once()
    {
        $support = $this->supportEmail();
        list($order, $row) = $this->shipped();

        $this->webhook($this->remote($row->tracking_number, 'an-post', [
            'tag' => 'Pending', 'subtag' => 'Pending_004', 'subtag_message' => 'Wrong carrier', 'updated_at' => '2026-10-17T07:00:00+00:00',
        ]))->assertStatus(200);

        $this->assertSame('wrong_carrier', $row->fresh()->attention(), 'no 48-hour wait');
        $alerts = $this->messagesTo($support)->filter(function ($m) { return strpos($m->getSubject(), 'Wrong courier for tracking number') === 0; });
        $this->assertCount(1, $alerts);

        // Repeated updates do not repeat the alert; the admin screen carries the warning.
        $this->webhook($this->remote($row->tracking_number, 'an-post', ['tag' => 'Pending', 'subtag' => 'Pending_004', 'updated_at' => '2026-10-17T09:00:00+00:00']));
        $this->assertCount(1, $this->messagesTo($support)->filter(function ($m) { return strpos($m->getSubject(), 'Wrong courier') === 0; }));
        $this->asAdmin();
        $this->assertSame('wrong_carrier', $this->getJson('/api/admin/order/find/' . $order->id)->json('data.shipment_tracking.attention.reason'));

        // Correcting the shipment clears it.
        $this->ship($order, 'dpd-ireland', '15501234567890')->assertJsonPath('status', 200);
        $this->assertNull($row->fresh()->attention());

        // A plain "no information yet" Pending is not a problem.
        $this->assertNull($this->shipmentRow($this->order($this->s('ON_THE_WAY')), ['tag' => 'Pending', 'subtag' => 'Pending_001'])->fresh()->attention());
    }

    /** 2026-10-19: AfterShip's destination column showed only the flag; town level is now sent. */
    public function test_town_level_destination_and_origin_are_sent_but_never_the_eircode_or_personal_details()
    {
        $address = \App\Models\UserAddress::create(['name' => 'Daniel Murphy', 'phone' => '0871234567', 'country' => 'IE', 'state' => 'D',
            'city' => 'Donabate', 'zip' => 'A65 F4E2', 'address_1' => '1 Private Lane', 'email' => 'private@example.test', 'type' => 1, 'default' => 2]);
        $order = $this->order($this->s('CONFIRMED'), ['user_address_id' => $address->id]);

        $this->ship($order, 'an-post', 'CE123456789IE')->assertJsonPath('status', 200);

        $create = array_values(array_filter($this->calls, function ($c) { return $c[0] === 'POST' && $c[1] === 'trackings'; }))[0][2];
        $setting = \App\Models\Setting::first();

        $this->assertSame('Donabate', $create['destination_city']);
        $this->assertSame('Dublin', $create['destination_state'], 'county name, not the stored code');
        $this->assertSame('IRL', $create['destination_country_region']);
        if ($setting && $setting->city) {
            $this->assertSame(trim($setting->city), $create['origin_city']);
            $this->assertSame('IRL', $create['origin_country_region']);
        }

        $sent = json_encode($create);
        foreach (['A65 F4E2', 'Private Lane', 'Daniel Murphy', '0871234567', 'private@example.test', $order->contact_email] as $private) {
            $this->assertStringNotContainsString($private, $sent, "must not reach AfterShip: $private");
        }
        $this->assertArrayNotHasKey('destination_postal_code', $create, 'An Post does not require it');
    }
}
