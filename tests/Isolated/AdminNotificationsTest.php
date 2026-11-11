<?php

namespace Tests\Isolated;

use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * The admin header's notification bell (AdminNotifications, brought over
 * from the live server, 26 Oct 2026): GET api/admin/notifications and
 * POST api/admin/notifications/read.
 */
class AdminNotificationsTest extends IsolatedTestCase
{
    private function order(\DateTimeInterface $createdAt): int
    {
        $id = DB::table('orders')->insertGetId([
            'order' => 'ORD' . random_int(1000000, 9999999), 'user_id' => Fixture::USER_PERSONAL_ID,
            'status' => (int)config('constants.orderStatus.PENDING'), 'order_method' => (int)config('constants.paymentMethod.SUMUP'),
            'payment_done' => 1, 'cancelled' => 2, 'total_amount' => 500, 'wallet_amount' => 0, 'currency' => 'EUR',
            'contact_name' => 'Bell Tester', 'contact_email' => 'bell@fixture.test',
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        DB::table('ordered_products')->insert(['order_id' => $id, 'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID, 'quantity' => 1, 'selling' => 500, 'tax_price' => 0, 'tax_rate' => 23,
            'tax_inclusive' => 1, 'bundle_offer' => 0, 'shipping_type' => 1,
            'shipping_place_id' => DB::table('shipping_places')->value('id'), 'created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $id;
    }

    private function feed(int $adminId = Fixture::ADMIN_SUPER_ID)
    {
        $response = $this->asAdmin($adminId)->getJson('/api/admin/notifications?limit=50');
        $this->assertApiOk($response);

        return $response->json('data');
    }

    private function item(array $feed, string $id)
    {
        foreach ($feed['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    public function test_a_new_order_shows_as_unread_with_its_details()
    {
        DB::table('admin_notification_states')->where('admin_id', Fixture::ADMIN_SUPER_ID)->delete();
        $id = $this->order(now()->subMinutes(5));

        $feed = $this->feed();
        $item = $this->item($feed, 'order:' . $id);

        $this->assertNotNull($item, 'the order is in the bell');
        $this->assertSame('order_placed', $item['type']);
        $this->assertFalse($item['read']);
        $this->assertSame($id, (int)$item['order_id']);
        $this->assertTrue($item['paid']);
        $this->assertFalse($item['cancelled']);
        $this->assertGreaterThanOrEqual(1, $feed['unread']);
        $this->assertArrayHasKey('pending', $feed);
        $this->assertArrayHasKey('server_time', $feed);
    }

    public function test_marking_one_notification_read_leaves_the_others_unread()
    {
        DB::table('admin_notification_states')->where('admin_id', Fixture::ADMIN_SUPER_ID)->delete();
        $first = $this->order(now()->subMinutes(10));
        $second = $this->order(now()->subMinutes(5));
        $before = $this->feed()['unread'];

        $response = $this->asAdmin()->postJson('/api/admin/notifications/read', ['ids' => ['order:' . $first]]);
        $this->assertApiOk($response);
        $feed = $response->json('data');

        $this->assertTrue($this->item($feed, 'order:' . $first)['read']);
        $this->assertFalse($this->item($feed, 'order:' . $second)['read']);
        $this->assertSame($before - 1, $feed['unread']);
    }

    public function test_mark_all_read_clears_the_count_and_the_per_item_rows()
    {
        $id = $this->order(now()->subMinutes(5));
        $this->asAdmin()->postJson('/api/admin/notifications/read', ['ids' => ['order:' . $id]]);

        $response = $this->asAdmin()->postJson('/api/admin/notifications/read', ['all' => true]);
        $this->assertApiOk($response);

        $this->assertSame(0, $response->json('data.unread'));
        $this->assertSame(0, DB::table('admin_notification_reads')->where('admin_id', Fixture::ADMIN_SUPER_ID)->count(),
            'the per-id rows behind the cut-off are removed');

        // Something new after "mark all read" is unread again.
        $newer = $this->order(now()->addSecond());
        $this->assertFalse($this->item($this->feed(), 'order:' . $newer)['read']);
    }

    public function test_older_orders_are_listed_as_read_and_a_month_old_one_is_not_listed()
    {
        DB::table('admin_notification_states')->where('admin_id', Fixture::ADMIN_SUPER_ID)->delete();
        $tenDays = $this->order(now()->subDays(10));
        $fortyDays = $this->order(now()->subDays(40));

        $feed = $this->feed();

        $this->assertTrue($this->item($feed, 'order:' . $tenDays)['read'], 'older than 7 days never counts as unread');
        $this->assertNull($this->item($feed, 'order:' . $fortyDays), 'older than 30 days is not listed');
    }

    /**
     * Vendor first: within one test the app reuses the controller instance,
     * and ControllerHelper only ever sets isSuperAdmin to true, so a vendor
     * request after a superadmin one would inherit it. Each real request is
     * its own PHP process; the superadmin side is covered by the tests above.
     */
    public function test_a_vendor_does_not_see_orders_for_other_sellers_products()
    {
        $id = $this->order(now()->subMinutes(5));

        $this->assertNull($this->item($this->feed(Fixture::ADMIN_VENDOR_ID), 'order:' . $id),
            'the fixture phone belongs to the superadmin, not the vendor');
    }

    public function test_only_well_formed_ids_are_stored_when_marking_read()
    {
        $id = $this->order(now()->subMinutes(5));

        $this->asAdmin()->postJson('/api/admin/notifications/read', [
            'ids' => ['order:' . $id, 'order:' . $id, "x'; DROP TABLE orders; --", 'order:abc', ''],
        ]);

        $stored = DB::table('admin_notification_reads')->where('admin_id', Fixture::ADMIN_SUPER_ID)
            ->pluck('notification_id')->all();
        $this->assertSame(['order:' . $id], array_values(array_unique($stored)));
        $this->assertSame(1, count($stored), 'a repeated id is stored once');
    }

    public function test_signed_out_and_customer_requests_are_refused()
    {
        $this->asGuest();
        $guest = $this->getJson('/api/admin/notifications');
        $this->assertNotSame(200, (int)$guest->json('status'));
        $this->assertNull($guest->json('data.items'));

        $customer = $this->asCustomer()->getJson('/api/admin/notifications');
        $this->assertNotSame(200, (int)$customer->json('status'));
        $this->assertNull($customer->json('data.items'));
    }
}
