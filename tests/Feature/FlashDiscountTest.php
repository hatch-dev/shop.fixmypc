<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FlashDiscount;
use App\Models\Helper\Pricing;
use App\Models\OrderedFlashDiscount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The store-wide Flash Discount, end to end.
 *
 * Not to be confused with Flash Sales (flash_sales / flash_sale_products),
 * which discount individual products. This is the single banner discount
 * taken off the whole basket: admin CRUD at /api/admin/flash-discount, the
 * storefront read at /api/v1/flash-discount, Pricing::flashDiscount, and the
 * ordered_flash_discount row written at checkout.
 *
 * As everywhere else in this codebase a refusal is HTTP 200 with a status
 * inside the body, so every negative case reads the BODY.
 */
class FlashDiscountTest extends TestCase
{
    use DatabaseTransactions;

    /* ---- fixtures ---------------------------------------------------- */

    private function admin()
    {
        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        $this->app['auth']->forgetGuards();
        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    private function asGuest()
    {
        $this->app['auth']->forgetGuards();
    }

    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }

    private function clearExisting()
    {
        DB::table('ordered_flash_discount')->delete();
        DB::table('flash_discounts')->delete();
    }

    private function payload($overrides = [])
    {
        return array_merge([
            'type' => 'percentage',
            'value' => 10,
            'min_cart_value' => 100,
            'max_discount' => 50,
            'start_time' => Carbon::now()->subHour()->format('Y-m-d H:i:s'),
            'end_time' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
            'is_active' => 1,
        ], $overrides);
    }

    private function make($overrides = [])
    {
        return FlashDiscount::create($this->payload($overrides));
    }

    /* ---- access control ---------------------------------------------- */

    public function test_the_list_is_closed_without_an_admin_session()
    {
        $this->asGuest();

        $response = $this->getJson('/api/admin/flash-discount/all?orderby=id&type=desc');

        $this->assertSame(204, $this->bodyStatus($response));
        $this->assertNull($response->json('data'));
    }

    public function test_creating_is_closed_and_inert_without_an_admin_session()
    {
        $this->asGuest();

        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload());

        $this->assertSame(204, $this->bodyStatus($response));
        $this->assertSame($before, FlashDiscount::count(), 'A guest created a store-wide discount.');
    }

    public function test_deleting_is_closed_and_inert_without_an_admin_session()
    {
        $this->admin();
        $flash = $this->make();

        $this->asGuest();

        $response = $this->deleteJson("/api/admin/flash-discount/delete/{$flash->id}");

        $this->assertSame(204, $this->bodyStatus($response));
        $this->assertNotNull(FlashDiscount::find($flash->id), 'A guest deleted a store-wide discount.');
    }

    /* ---- admin CRUD --------------------------------------------------- */

    public function test_a_discount_is_created_with_every_field_it_was_given()
    {
        $this->admin();

        // Only one promotion can be live at a time now, so a test that
        // creates one through the API has to own the window.
        $this->clearExisting();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload());

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $flash = FlashDiscount::find($response->json('data.id'));

        $this->assertNotNull($flash);
        $this->assertSame('percentage', $flash->type);
        $this->assertEquals(10, (float)$flash->value);
        $this->assertEquals(100, (float)$flash->min_cart_value);
        $this->assertEquals(50, (float)$flash->max_discount);
        $this->assertEquals(1, (int)$flash->is_active);
    }

    public function test_find_returns_the_discount_it_was_asked_for()
    {
        $this->admin();
        $flash = $this->make(['value' => 12]);

        $response = $this->getJson("/api/admin/flash-discount/find/{$flash->id}");

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertEquals($flash->id, $response->json('data.id'));
        $this->assertEquals(12, (float)$response->json('data.value'));
    }

    public function test_find_refuses_an_id_that_does_not_exist()
    {
        $this->admin();

        $response = $this->getJson('/api/admin/flash-discount/find/99999999');

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertNull($response->json('data.id'));
    }

    public function test_the_list_returns_rows_and_is_orderable()
    {
        $this->admin();
        $this->clearExisting();

        $a = $this->make(['value' => 5]);
        $b = $this->make(['value' => 6]);

        $response = $this->getJson('/api/admin/flash-discount/all?orderby=id&type=desc');

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $ids = array_column($response->json('data.data'), 'id');

        $this->assertSame([$b->id, $a->id], array_slice($ids, 0, 2));
    }

    public function test_the_list_survives_being_called_with_no_ordering_parameters()
    {
        $this->admin();
        $this->make();

        $response = $this->getJson('/api/admin/flash-discount/all');

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
    }

    public function test_an_edit_updates_the_row_it_names_and_no_other()
    {
        $this->admin();
        $this->clearExisting();

        $target = $this->make(['value' => 10]);

        // Switched off, so it does not trip the overlap rule the way a second
        // live promotion in the same window now would.
        $other = $this->make(['value' => 20, 'is_active' => 0]);

        $response = $this->postJson(
            "/api/admin/flash-discount/action/{$target->id}",
            $this->payload(['value' => 30])
        );

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertEquals(30, (float)FlashDiscount::find($target->id)->value);
        $this->assertEquals(20, (float)FlashDiscount::find($other->id)->value);
    }

    /**
     * Editing a row must not be refused for overlapping itself.
     */
    public function test_a_live_discount_can_be_edited_in_place()
    {
        $this->admin();
        $this->clearExisting();

        $flash = $this->make(['value' => 10]);

        $response = $this->postJson(
            "/api/admin/flash-discount/action/{$flash->id}",
            $this->payload(['value' => 12])
        );

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertEquals(12, (float)FlashDiscount::find($flash->id)->value);
    }

    public function test_editing_a_discount_that_is_gone_is_refused()
    {
        $this->admin();

        $response = $this->postJson('/api/admin/flash-discount/action/99999999', $this->payload());

        $this->assertNotSame(200, $this->bodyStatus($response));
    }

    public function test_saving_a_live_discount_with_the_form_default_leaves_it_live()
    {
        $this->admin();

        // Only one promotion can be live at a time now, so a test that
        // creates one through the API has to own the window.
        $this->clearExisting();

        $flash = $this->make(['is_active' => 1]);

        $response = $this->postJson(
            "/api/admin/flash-discount/action/{$flash->id}",
            $this->payload(['is_active' => null])
        );

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertEquals(1, (int)FlashDiscount::find($flash->id)->is_active);
    }

    public function test_a_discount_can_be_switched_off()
    {
        $this->admin();

        $flash = $this->make(['is_active' => 1]);

        $this->postJson(
            "/api/admin/flash-discount/action/{$flash->id}",
            $this->payload(['is_active' => 0])
        );

        $this->assertEquals(0, (int)FlashDiscount::find($flash->id)->is_active);
    }

    public function test_a_discount_is_deleted()
    {
        $this->admin();
        $flash = $this->make();

        $response = $this->deleteJson("/api/admin/flash-discount/delete/{$flash->id}");

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertNull(FlashDiscount::find($flash->id));
    }

    /* ---- validation ---------------------------------------------------- */

    public function test_a_discount_with_no_type_is_refused()
    {
        $this->admin();
        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload(['type' => null]));

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertSame($before, FlashDiscount::count());
    }

    public function test_a_discount_with_no_value_is_refused()
    {
        $this->admin();
        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload(['value' => null]));

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertSame($before, FlashDiscount::count());
    }

    public function test_a_discount_with_no_dates_is_refused()
    {
        $this->admin();
        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload([
            'start_time' => null,
            'end_time' => null,
        ]));

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertSame($before, FlashDiscount::count());
    }

    public function test_a_discount_with_a_type_outside_the_enum_is_refused_cleanly()
    {
        $this->admin();
        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload(['type' => 'free']));

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertSame($before, FlashDiscount::count());
        $this->assertStringNotContainsStringIgnoringCase(
            'SQLSTATE',
            (string)$response->json('message'),
            'A refused type leaked the SQL error to the admin.'
        );
    }

    public function test_a_percentage_over_one_hundred_is_refused()
    {
        $this->admin();
        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload([
            'type' => 'percentage',
            'value' => 150,
        ]));

        $this->assertNotSame(200, $this->bodyStatus($response), 'A 150% store-wide discount was accepted.');
        $this->assertSame($before, FlashDiscount::count());
    }

    public function test_a_negative_value_is_refused()
    {
        $this->admin();
        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload(['value' => -20]));

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertSame($before, FlashDiscount::count());
    }

    public function test_a_window_that_ends_before_it_starts_is_refused()
    {
        $this->admin();
        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload([
            'start_time' => Carbon::now()->addDay()->format('Y-m-d H:i:s'),
            'end_time' => Carbon::now()->format('Y-m-d H:i:s'),
        ]));

        $this->assertNotSame(200, $this->bodyStatus($response), 'A window ending before it starts was accepted.');
        $this->assertSame($before, FlashDiscount::count());
    }

    public function test_the_datetime_local_format_the_admin_form_posts_is_stored_correctly()
    {
        $this->admin();

        // Only one promotion can be live at a time now, so a test that
        // creates one through the API has to own the window.
        $this->clearExisting();

        $start = Carbon::now()->subHour();
        $end = Carbon::now()->addHour();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload([
            'start_time' => $start->format('Y-m-d\TH:i'),
            'end_time' => $end->format('Y-m-d\TH:i'),
        ]));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $flash = FlashDiscount::find($response->json('data.id'));

        $this->assertSame($start->format('Y-m-d H:i'), $flash->start_time->format('Y-m-d H:i'));
        $this->assertSame($end->format('Y-m-d H:i'), $flash->end_time->format('Y-m-d H:i'));
    }

    public function test_find_returns_dates_the_edit_form_can_display()
    {
        $this->admin();
        $flash = $this->make();

        $response = $this->getJson("/api/admin/flash-discount/find/{$flash->id}");

        $start = (string)$response->json('data.start_time');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/',
            $start,
            'datetime-local cannot render "' . $start . '", so the edit form opens with its dates blank.'
        );
    }

    /* ---- Pricing::flashDiscount ---------------------------------------- */

    public function test_a_percentage_discount_is_taken_off_the_subtotal()
    {
        $flash = $this->make(['type' => 'percentage', 'value' => 10, 'min_cart_value' => 0, 'max_discount' => null]);

        $this->assertEquals(20.00, Pricing::flashDiscount($flash, 200));
    }

    public function test_a_fixed_discount_is_the_value_itself()
    {
        $flash = $this->make(['type' => 'fixed', 'value' => 25, 'min_cart_value' => 0, 'max_discount' => null]);

        $this->assertEquals(25.00, Pricing::flashDiscount($flash, 200));
    }

    public function test_nothing_is_taken_off_a_basket_under_the_minimum()
    {
        $flash = $this->make(['type' => 'percentage', 'value' => 10, 'min_cart_value' => 100]);

        $this->assertEquals(0.0, Pricing::flashDiscount($flash, 99.99));
    }

    public function test_a_basket_exactly_on_the_minimum_qualifies()
    {
        $flash = $this->make(['type' => 'percentage', 'value' => 10, 'min_cart_value' => 100, 'max_discount' => null]);

        $this->assertEquals(10.00, Pricing::flashDiscount($flash, 100));
    }

    public function test_the_cap_is_honoured()
    {
        $flash = $this->make(['type' => 'percentage', 'value' => 50, 'min_cart_value' => 0, 'max_discount' => 30]);

        $this->assertEquals(30.00, Pricing::flashDiscount($flash, 200));
    }

    public function test_a_cap_of_zero_means_no_cap_not_no_discount()
    {
        $flash = $this->make(['type' => 'percentage', 'value' => 10, 'min_cart_value' => 0, 'max_discount' => 0]);

        $this->assertEquals(
            10.00,
            Pricing::flashDiscount($flash, 100),
            'Max Discount left at the admin form default of 0 cancelled the whole discount.'
        );
    }

    public function test_a_fixed_discount_never_exceeds_the_basket()
    {
        $flash = $this->make(['type' => 'fixed', 'value' => 500, 'min_cart_value' => 0, 'max_discount' => null]);

        $this->assertEquals(50.00, Pricing::flashDiscount($flash, 50));
    }

    public function test_an_empty_basket_earns_nothing()
    {
        $flash = $this->make(['type' => 'fixed', 'value' => 25, 'min_cart_value' => 0, 'max_discount' => null]);

        $this->assertEquals(0.0, Pricing::flashDiscount($flash, 0));
    }

    public function test_no_discount_at_all_earns_nothing()
    {
        $this->assertEquals(0.0, Pricing::flashDiscount(null, 200));
    }

    /* ---- the active scope ---------------------------------------------- */

    public function test_an_inactive_discount_is_not_active()
    {
        $this->clearExisting();
        $this->make(['is_active' => 0]);

        $this->assertNull(FlashDiscount::active()->first());
    }

    public function test_an_expired_discount_is_not_active()
    {
        $this->clearExisting();
        $this->make([
            'start_time' => Carbon::now()->subDays(2)->format('Y-m-d H:i:s'),
            'end_time' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $this->assertNull(FlashDiscount::active()->first());
    }

    public function test_a_future_discount_is_not_active()
    {
        $this->clearExisting();
        $this->make([
            'start_time' => Carbon::now()->addDay()->format('Y-m-d H:i:s'),
            'end_time' => Carbon::now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        $this->assertNull(FlashDiscount::active()->first());
    }

    /**
     * Only one store-wide discount runs at a time, and the rule is enforced
     * where an admin can be told why.
     */
    public function test_a_window_overlapping_a_live_discount_is_refused()
    {
        $this->admin();
        $this->clearExisting();

        $this->make(['value' => 5]);

        $before = FlashDiscount::count();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload(['value' => 25]));

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertSame($before, FlashDiscount::count());
        $this->assertStringContainsStringIgnoringCase('overlap', (string)$response->json('message'));
    }

    public function test_a_window_that_only_touches_another_is_allowed()
    {
        $this->admin();
        $this->clearExisting();

        $handover = Carbon::now()->addHour();

        $this->make(['end_time' => $handover->format('Y-m-d H:i:s')]);

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload([
            'start_time' => $handover->format('Y-m-d H:i:s'),
            'end_time' => $handover->copy()->addDay()->format('Y-m-d H:i:s'),
        ]));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
    }

    public function test_a_switched_off_discount_does_not_block_the_window()
    {
        $this->admin();
        $this->clearExisting();

        $this->make(['is_active' => 0]);

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload());

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
    }

    /**
     * Rows that predate the overlap rule can still sit on top of each other,
     * so the one that wins has to be decided rather than left to row order --
     * and decided the same way for the banner and for the checkout.
     */
    public function test_the_live_discount_is_chosen_deterministically()
    {
        $this->clearExisting();

        $this->make(['type' => 'percentage', 'value' => 5, 'min_cart_value' => 0, 'max_discount' => null]);
        $newest = $this->make(['type' => 'percentage', 'value' => 25, 'min_cart_value' => 0, 'max_discount' => null]);

        $this->assertEquals($newest->id, FlashDiscount::current()->id);
        $this->assertEquals($newest->id, FlashDiscount::active()->first()->id);
    }

    /* ---- the storefront read -------------------------------------------- */

    public function test_the_storefront_sees_a_live_discount()
    {
        $this->asGuest();
        $this->clearExisting();

        $this->make(['type' => 'percentage', 'value' => 15, 'min_cart_value' => 100, 'max_discount' => 200]);

        $response = $this->getJson('/api/v1/flash-discount');

        $response->assertOk();
        $this->assertTrue($response->json('active'));
        $this->assertSame('percentage', $response->json('type'));
        $this->assertEquals(15, $response->json('value'));
        $this->assertEquals(100, $response->json('min_cart_value'));
        $this->assertEquals(200, $response->json('max_discount'));
    }

    public function test_the_storefront_sees_nothing_when_no_discount_is_live()
    {
        $this->asGuest();
        $this->clearExisting();
        $this->make(['is_active' => 0]);

        $response = $this->getJson('/api/v1/flash-discount');

        $response->assertOk();
        $this->assertFalse($response->json('active'));
    }

    public function test_the_storefront_window_is_unambiguous_about_its_timezone()
    {
        $this->asGuest();
        $this->clearExisting();

        $end = Carbon::now()->addHours(3);
        $this->make(['end_time' => $end->format('Y-m-d H:i:s')]);

        $response = $this->getJson('/api/v1/flash-discount');

        $this->assertSame(
            $end->copy()->utc()->format('Y-m-d\TH:i'),
            Carbon::parse($response->json('end_time'))->utc()->format('Y-m-d\TH:i')
        );
    }

    public function test_the_storefront_never_advertises_a_discount_the_checkout_will_not_give()
    {
        $this->asGuest();
        $this->clearExisting();

        $flash = $this->make([
            'type' => 'percentage', 'value' => 10, 'min_cart_value' => 0, 'max_discount' => 0,
        ]);

        $response = $this->getJson('/api/v1/flash-discount');

        $advertised = ($response->json('value') / 100) * 100;
        $charged = Pricing::flashDiscount($flash, 100);

        $this->assertEquals(
            $advertised,
            $charged,
            'On a EUR 100 basket the banner offers ' . $advertised . ' and the checkout gives ' . $charged . '.'
        );
    }

    /* ---- what an order keeps -------------------------------------------- */

    public function test_deleting_a_discount_does_not_erase_what_past_orders_were_charged()
    {
        $this->admin();

        $orderId = DB::table('orders')->value('id');

        if (!$orderId) {
            $this->markTestSkipped('No order to attach a historic discount to.');
        }

        $flash = $this->make();

        OrderedFlashDiscount::create([
            'order_id' => $orderId,
            'flash_discount_id' => $flash->id,
            'discount' => 17.50,
        ]);

        $this->deleteJson("/api/admin/flash-discount/delete/{$flash->id}");

        $this->assertEquals(
            17.50,
            (float)DB::table('ordered_flash_discount')->where('order_id', $orderId)->value('discount'),
            'Deleting the promotion deleted the order line that recorded the charge.'
        );
    }

    public function test_an_order_cannot_accumulate_two_flash_discount_rows()
    {
        $this->admin();

        $orderId = DB::table('orders')->value('id');

        if (!$orderId) {
            $this->markTestSkipped('No order to attach a historic discount to.');
        }

        $flash = $this->make();

        OrderedFlashDiscount::create([
            'order_id' => $orderId, 'flash_discount_id' => $flash->id, 'discount' => 10,
        ]);

        $duplicated = true;

        try {
            OrderedFlashDiscount::create([
                'order_id' => $orderId, 'flash_discount_id' => $flash->id, 'discount' => 10,
            ]);
        } catch (\Exception $e) {
            $duplicated = false;
        }

        $this->assertFalse($duplicated, 'An order took a second flash discount row, doubling the discount.');
    }

    /* ---- the permission the screen is gated on --------------------------- */

    public function test_the_flash_discount_permission_group_is_complete()
    {
        $names = DB::table('permissions')
            ->where('group_name', 'flash_discount')
            ->pluck('name')
            ->all();

        foreach (['view', 'create', 'edit', 'delete'] as $ability) {
            $this->assertContains('flash_discount.' . $ability, $names);
        }
    }

    /* ---- what the admin form actually posts ------------------------------ */

    /**
     * Max Discount is optional. An untouched field must mean "no ceiling",
     * which for a nullable money column is null -- not 0, which the pricing
     * rule reads as a ceiling of zero.
     */
    public function test_an_empty_cap_is_stored_as_no_cap()
    {
        $this->admin();
        $this->clearExisting();

        $response = $this->postJson('/api/admin/flash-discount/action', $this->payload([
            'max_discount' => '',
            'min_cart_value' => '',
        ]));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $flash = FlashDiscount::find($response->json('data.id'));

        $this->assertNull($flash->max_discount);
        $this->assertNull($flash->min_cart_value);
        $this->assertEquals(10.00, Pricing::flashDiscount($flash, 100));
    }

    /** The checkbox posts a real boolean, and it has to survive the trip. */
    public function test_the_active_checkbox_round_trips()
    {
        $this->admin();
        $this->clearExisting();

        $created = $this->postJson('/api/admin/flash-discount/action', $this->payload(['is_active' => true]));

        $this->assertTrue($created->json('data.is_active'));

        $id = $created->json('data.id');

        $this->postJson("/api/admin/flash-discount/action/{$id}", $this->payload(['is_active' => false]));

        $this->assertFalse($this->getJson("/api/admin/flash-discount/find/{$id}")->json('data.is_active'));
    }

    /**
     * A bulk delete is all or nothing. The old loop deleted as it went and
     * bailed on the first id it could not find.
     */
    public function test_a_bulk_delete_naming_a_stale_id_deletes_nothing()
    {
        $this->admin();
        $this->clearExisting();

        $a = $this->make(['is_active' => 0]);
        $b = $this->make(['is_active' => 0]);

        $response = $this->deleteJson("/api/admin/flash-discount/delete/{$a->id},99999999,{$b->id}");

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertNotNull(FlashDiscount::find($a->id), 'The first id was deleted before the stale one was noticed.');
        $this->assertNotNull(FlashDiscount::find($b->id));
    }

    public function test_a_bulk_delete_of_real_ids_removes_them_all()
    {
        $this->admin();
        $this->clearExisting();

        $a = $this->make(['is_active' => 0]);
        $b = $this->make(['is_active' => 0]);

        $response = $this->deleteJson("/api/admin/flash-discount/delete/{$a->id},{$b->id}");

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame(0, FlashDiscount::whereIn('id', [$a->id, $b->id])->count());
    }

    /**
     * The storefront says null for "no ceiling" so the banner cannot read a
     * cap of 0 as a real limit and print "upto 0".
     */
    public function test_the_storefront_reports_no_cap_as_null()
    {
        $this->asGuest();
        $this->clearExisting();

        $this->make(['max_discount' => 0]);

        $this->assertNull($this->getJson('/api/v1/flash-discount')->json('max_discount'));
    }

    /**
     * The list is sorted by a column this controller chose, not by whatever
     * arrives in the query string.
     */
    public function test_the_list_ignores_a_sort_column_that_is_not_a_column()
    {
        $this->admin();
        $this->make();

        $response = $this->getJson('/api/admin/flash-discount/all?orderby=(select+1)&type=sideways');

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
    }
}
