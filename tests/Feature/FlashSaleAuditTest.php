<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\FlashSale;
use App\Models\FlashSaleLang;
use App\Models\FlashSaleProduct;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Promotions -> Flash Sale, end to end through the real routes.
 *
 * A flash sale in this codebase is a dated window (flash_sales) holding a
 * list of absolute per-product prices (flash_sale_products.price). There is
 * no discount type, no percentage, and no quantity cap anywhere in the
 * schema, so every "discount" here is derived: selling - price.
 *
 * As in CrossSellAdminTest, every negative case reads the BODY. This codebase
 * answers a refusal with status 201 inside an HTTP 200, so asserting on the
 * HTTP code alone passes for every refusal there is.
 */
class FlashSaleAuditTest extends TestCase
{
    use DatabaseTransactions;

    /* ---- fixtures ---------------------------------------------------- */

    private $adminUser;
    private $adminHeaders = [];

    /**
     * A REAL, long-lived Passport token rather than Passport::actingAs.
     *
     * actingAs hands the request a TransientToken with no expires_at, which
     * CheckForAllScopes reads as "expires in under a minute" and answers by
     * rotating the token into $request['token']. Every admin write in this
     * module then carries a stray scalar field the controller copies into its
     * UPDATE, so actingAs tests a request shape production almost never
     * sends. The near-expiry case is covered on its own in FS-E4.
     */
    private function admin()
    {
        if (!$this->adminUser) {
            $this->adminUser = Admin::whereHas('roles', function ($query) {
                $query->where('name', 'superadmin');
            })->first();

            if (!$this->adminUser) {
                $this->markTestSkipped('No superadmin to drive the admin API with.');
            }

            Passport::personalAccessTokensExpireIn(Carbon::now()->addDays(30));

            $this->adminHeaders = [
                'Authorization' => 'Bearer ' .
                    $this->adminUser->createToken('admin', ['admin'])->accessToken,
            ];
        }

        $this->app['auth']->forgetGuards();

        return $this->adminUser;
    }

    private function asGuest()
    {
        $this->app['auth']->forgetGuards();
        $this->adminHeaders = [];
    }

    /** Published products no flash sale already owns. */
    private function products($count = 3)
    {
        $products = Product::where('status', 1)
            ->where('selling', '>', 0)
            ->whereNotIn('id', FlashSaleProduct::pluck('product_id'))
            ->orderBy('id')
            ->limit($count)
            ->get();

        if ($products->count() < $count) {
            $this->markTestSkipped('Not enough unconfigured published products.');
        }

        return $products;
    }

    private function adminGet($url)
    {
        return $this->getJson($url, $this->adminHeaders);
    }
    /** The body's own status, which is where a refusal actually lives. */
    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }

    private function fmt($carbon)
    {
        return $carbon->format('d-m-Y H:i:s');
    }

    /**
     * The payload the admin screen posts: DD-MM-YYYY times, a UTC time_zone so
     * the server's conversion is the identity, and products as the form
     * shapes them.
     */
    private function payload(array $rows, array $overrides = [])
    {
        $products = [];

        foreach ($rows as $row) {
            $products[] = [
                'price' => $row['price'],
                'product' => ['id' => $row['product']->id],
            ];
        }

        return array_merge([
            'title' => 'QA Flash ' . uniqid(),
            'status' => 1,
            'start_time' => $this->fmt(Carbon::now()->subHour()),
            'end_time' => $this->fmt(Carbon::now()->addDays(2)),
            'time_zone' => 'UTC',
            'products' => $products,
        ], $overrides);
    }

    private function save(array $payload, $id = null)
    {
        return $this->postJson('/api/admin/flash-sale/action' . ($id ? "/{$id}" : ''),
            $payload, $this->adminHeaders);
    }

    /** Create through the API and hand back the row. */
    private function createSale(array $rows, array $overrides = [])
    {
        $response = $this->save($this->payload($rows, $overrides));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        return FlashSale::find($response->json('data.id'));
    }

    /* ---- permissions (Step 2.21, Step 11.10-11) ---------------------- */

    /** FS-P1 */
    public function test_listing_refuses_an_unauthenticated_caller()
    {
        $this->asGuest();

        $response = $this->adminGet('/api/admin/flash-sale/all?orderby=created_at&type=desc');

        // App-wide convention: an unauthenticated JSON call answers HTTP 201
        // with body status 204. What matters is that no data came back.
        $this->assertSame(204, $this->bodyStatus($response));
        $this->assertNull($response->json('data'));
    }

    /** FS-P2 */
    public function test_create_refuses_an_unauthenticated_caller()
    {
        $products = $this->products(1);
        $this->asGuest();

        $before = FlashSale::count();

        $response = $this->save($this->payload([['product' => $products[0], 'price' => 1]]));

        $this->assertSame(204, $this->bodyStatus($response));
        $this->assertSame($before, FlashSale::count(), 'A guest created a flash sale.');
    }

    /** FS-P3 */
    public function test_delete_refuses_an_unauthenticated_caller()
    {
        $this->asGuest();

        $this->assertSame(204,
            $this->bodyStatus($this->deleteJson('/api/admin/flash-sale/delete/1', [], $this->adminHeaders)));
    }

    /** FS-P4 - a customer token must not reach the admin module. */
    public function test_a_customer_token_cannot_reach_the_admin_module()
    {
        $customer = $this->customer();
        $this->app['auth']->forgetGuards();
        Passport::actingAs($customer, ['user'], 'user');

        $response = $this->adminGet('/api/admin/flash-sale/all?orderby=created_at&type=desc');

        $this->assertSame(204, $this->bodyStatus($response));
        $this->assertNull($response->json('data'));
    }

    /* ---- listing (Step 2.1, 2.12-2.15) ------------------------------- */

    /** FS-L1 */
    public function test_listing_returns_a_paginated_page()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 1]]);

        $response = $this->adminGet('/api/admin/flash-sale/all?orderby=created_at&type=desc');

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame(14, $response->json('data.per_page'));
        $this->assertContains(
            $sale->id,
            collect($response->json('data.data'))->pluck('id')->map('intval')->all()
        );
    }

    /** FS-L2 - search by title. */
    public function test_listing_search_matches_on_title()
    {
        $this->admin();
        $products = $this->products(1);
        $title = 'QA Searchable ' . uniqid();
        $sale = $this->createSale([['product' => $products[0], 'price' => 1]], ['title' => $title]);

        $response = $this->adminGet('/api/admin/flash-sale/all?orderby=created_at&type=desc&q=' . urlencode($title));

        $ids = collect($response->json('data.data'))->pluck('id')->map('intval')->all();

        $this->assertSame([$sale->id], $ids);
    }

    /** FS-L3 - sorting honours the requested column. */
    public function test_listing_sorts_by_the_requested_column()
    {
        $this->admin();
        $products = $this->products(2);
        $a = $this->createSale([['product' => $products[0], 'price' => 1]], ['title' => 'AAA ' . uniqid()]);
        $b = $this->createSale([['product' => $products[1], 'price' => 1]], ['title' => 'ZZZ ' . uniqid()]);

        $asc = collect($this->adminGet('/api/admin/flash-sale/all?orderby=title&type=asc')->json('data.data'))
            ->pluck('id')->map('intval');

        $this->assertTrue(
            $asc->search($a->id) < $asc->search($b->id),
            'Ascending title order did not put AAA before ZZZ.'
        );
    }

    /** FS-L4 - the sort column reaches the query unfiltered. */
    public function test_listing_accepts_an_arbitrary_sort_column()
    {
        $this->admin();

        $response = $this->adminGet('/api/admin/flash-sale/all?orderby=admin_id&type=desc');

        $this->assertSame(200, $this->bodyStatus($response),
            'admin_id is not an offered sort option but was accepted.');
    }

    /** FS-L5 - a missing sort column. */
    public function test_listing_without_a_sort_column()
    {
        $this->admin();

        $response = $this->adminGet('/api/admin/flash-sale/all');

        $this->assertSame(200, $this->bodyStatus($response),
            'Listing without orderby answered: ' . (string)$response->json('message'));
    }

    /* ---- customers --------------------------------------------------- */

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Flash Fixture',
            'email' => 'flash.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    private function actingAsCustomer($attributes = [])
    {
        $customer = $this->customer($attributes);

        $this->app['auth']->forgetGuards();
        Passport::actingAs($customer, ['user'], 'user');

        return $customer;
    }

    /* ---- create / validation (Step 2.2, 2.16, Step 5.1-2) ------------ */

    /** FS-C1 - a valid flash sale. */
    public function test_creates_a_valid_flash_sale()
    {
        $this->admin();
        $products = $this->products(1);

        $start = Carbon::now()->subHour();
        $end = Carbon::now()->addDay();

        $response = $this->save($this->payload(
            [['product' => $products[0], 'price' => 5]],
            ['start_time' => $this->fmt($start), 'end_time' => $this->fmt($end)]
        ));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $sale = FlashSale::find($response->json('data.id'));

        $this->assertNotNull($sale);
        $this->assertSame(1, (int)$sale->status);
        $this->assertSame($start->format('Y-m-d H:i:s'), Carbon::parse($sale->start_time)->format('Y-m-d H:i:s'));
        $this->assertSame($end->format('Y-m-d H:i:s'), Carbon::parse($sale->end_time)->format('Y-m-d H:i:s'));

        $rows = FlashSaleProduct::where('flash_sale_id', $sale->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($products[0]->id, (int)$rows[0]->product_id);
        $this->assertSame('5.00', (string)$rows[0]->price);
    }

    /** FS-C2 - required fields. */
    public function test_refuses_a_flash_sale_with_no_required_fields()
    {
        $this->admin();

        $response = $this->save([]);

        $this->assertSame(201, $this->bodyStatus($response),
            'An empty payload was accepted.');
    }

    /** FS-C3 - each required field on its own. */
    public function test_each_required_field_is_enforced()
    {
        $this->admin();
        $products = $this->products(1);

        foreach (['title', 'start_time', 'end_time', 'status'] as $field) {
            $payload = $this->payload([['product' => $products[0], 'price' => 5]]);
            unset($payload[$field]);

            $this->assertSame(201, $this->bodyStatus($this->save($payload)),
                "A flash sale saved with no {$field}.");
        }
    }

    /** FS-C4 - end before start. */
    public function test_refuses_an_end_time_before_the_start_time()
    {
        $this->admin();
        $products = $this->products(1);

        $response = $this->save($this->payload(
            [['product' => $products[0], 'price' => 5]],
            [
                'start_time' => $this->fmt(Carbon::now()->addDay()),
                'end_time' => $this->fmt(Carbon::now()->addHour()),
            ]
        ));

        $this->assertSame(201, $this->bodyStatus($response), 'end < start was accepted.');
    }

    /** FS-C5 - the same instant for both ends. */
    public function test_refuses_an_identical_start_and_end_time()
    {
        $this->admin();
        $products = $this->products(1);
        $when = $this->fmt(Carbon::now()->addDay());

        $response = $this->save($this->payload(
            [['product' => $products[0], 'price' => 5]],
            ['start_time' => $when, 'end_time' => $when]
        ));

        $this->assertSame(201, $this->bodyStatus($response), 'A zero-length sale was accepted.');
    }

    /** FS-C6 - a start time in the past is allowed on purpose (back-dating). */
    public function test_allows_a_start_time_in_the_past()
    {
        $this->admin();
        $products = $this->products(1);

        $response = $this->save($this->payload(
            [['product' => $products[0], 'price' => 5]],
            [
                'start_time' => $this->fmt(Carbon::now()->subDays(3)),
                'end_time' => $this->fmt(Carbon::now()->addDay()),
            ]
        ));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
    }

    /* ---- price configuration (Step 5.13-18, Step 11.7-8) ------------- */

    /** FS-V1 - a negative sale price. */
    public function test_refuses_a_negative_sale_price()
    {
        $this->admin();
        $products = $this->products(1);

        $response = $this->save($this->payload([['product' => $products[0], 'price' => -50]]));

        $sale = FlashSale::find($response->json('data.id'));
        $stored = $sale ? FlashSaleProduct::where('flash_sale_id', $sale->id)->value('price') : null;

        $this->assertNotEquals('-50.00', (string)$stored,
            'A negative flash price was stored.');
    }

    /** FS-V2 - a zero sale price (a free product). */
    public function test_a_zero_sale_price()
    {
        $this->admin();
        $products = $this->products(1);

        $response = $this->save($this->payload([['product' => $products[0], 'price' => 0]]));

        $sale = FlashSale::find($response->json('data.id'));
        $stored = $sale ? FlashSaleProduct::where('flash_sale_id', $sale->id)->value('price') : null;

        $this->assertNotSame('0.00', (string)$stored,
            'A flash price of zero was stored — the product is now free.');
    }

    /** FS-V3 - a sale price above the product's own selling price. */
    public function test_refuses_a_sale_price_above_the_selling_price()
    {
        $this->admin();
        $products = $this->products(1);
        $above = (float)$products[0]->selling + 100;

        $response = $this->save($this->payload([['product' => $products[0], 'price' => $above]]));

        $sale = FlashSale::find($response->json('data.id'));
        $stored = $sale ? FlashSaleProduct::where('flash_sale_id', $sale->id)->value('price') : null;

        $this->assertNull($stored,
            'A "sale" price higher than the shelf price was stored.');
    }

    /** FS-V4 - a non-numeric price. */
    public function test_refuses_a_non_numeric_price()
    {
        $this->admin();
        $products = $this->products(1);

        $response = $this->save($this->payload([['product' => $products[0], 'price' => 'free']]));

        $this->assertSame(201, $this->bodyStatus($response),
            'A non-numeric price was accepted.');
    }

    /** FS-V5 - a product id that does not exist. */
    public function test_refuses_an_unknown_product_id()
    {
        $this->admin();

        $response = $this->save($this->payload([], ['products' => [
            ['price' => 5, 'product' => ['id' => 999999999]],
        ]]));

        $this->assertSame(201, $this->bodyStatus($response),
            'A flash sale was saved against a product that does not exist.');
    }

    /** FS-V6 - a malformed products entry (no product key). */
    public function test_refuses_a_malformed_products_entry()
    {
        $this->admin();

        $response = $this->save($this->payload([], ['products' => [['price' => 5]]]));

        $this->assertSame(201, $this->bodyStatus($response),
            'A products row with no product was accepted.');
    }

    /** FS-V7 - an unpublished product. */
    public function test_refuses_an_unpublished_product()
    {
        $this->admin();

        $private = Product::where('status', '!=', 1)
            ->whereNotIn('id', FlashSaleProduct::pluck('product_id'))
            ->first();

        if (!$private) {
            $this->markTestSkipped('No unpublished product to try.');
        }

        $response = $this->save($this->payload([['product' => $private, 'price' => 1]]));

        $this->assertSame(201, $this->bodyStatus($response),
            'An unpublished product was added to a flash sale.');
    }

    /* ---- duplicates (Step 5.11-12) ----------------------------------- */

    /** FS-D1 - the same product listed twice in one payload. */
    public function test_refuses_the_same_product_twice_in_one_sale()
    {
        $this->admin();
        $products = $this->products(1);

        $response = $this->save($this->payload([
            ['product' => $products[0], 'price' => 5],
            ['product' => $products[0], 'price' => 3],
        ]));

        $sale = FlashSale::find($response->json('data.id'));
        $rows = $sale ? FlashSaleProduct::where('flash_sale_id', $sale->id)->count() : 0;

        $this->assertLessThan(2, $rows,
            'The same product was stored twice in one flash sale.');
    }

    /** FS-D2 - a product already in another flash sale. */
    public function test_refuses_a_product_already_in_another_flash_sale()
    {
        $this->admin();
        $products = $this->products(1);

        $this->createSale([['product' => $products[0], 'price' => 5]]);

        $response = $this->save($this->payload([['product' => $products[0], 'price' => 4]]));

        $this->assertSame(201, $this->bodyStatus($response),
            'A product was added to two flash sales at once.');
    }

    /** FS-D3 - the same product kept on an EDIT of its own sale. */
    public function test_editing_a_sale_keeps_its_own_products()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        $row = FlashSaleProduct::where('flash_sale_id', $sale->id)->first();

        $response = $this->save([
            'title' => 'Edited ' . uniqid(),
            'status' => 1,
            'start_time' => $this->fmt(Carbon::now()->subHour()),
            'end_time' => $this->fmt(Carbon::now()->addDays(3)),
            'time_zone' => 'UTC',
            'products' => [[
                'id' => (string)$row->id,
                'price' => 4,
                'updated' => true,
                'product' => ['id' => $products[0]->id],
            ]],
        ], $sale->id);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertSame('4.00', (string)FlashSaleProduct::find($row->id)->price);
        $this->assertSame(1, FlashSaleProduct::where('flash_sale_id', $sale->id)->count());
    }

    /* ---- edit / delete / status (Step 2.3-2.5, 2.11) ----------------- */

    /** FS-E1 - find returns the sale with its products. */
    public function test_find_returns_the_sale_with_its_products()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 6]]);

        $response = $this->adminGet('/api/admin/flash-sale/find/' . $sale->id);

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame($sale->id, (int)$response->json('data.id'));
        $this->assertSame($products[0]->id, (int)$response->json('data.products.0.product_id'));
    }

    /** FS-E2 - find on an id that is not there. */
    public function test_find_on_an_unknown_id()
    {
        $this->admin();

        $this->assertSame(201, $this->bodyStatus($this->adminGet('/api/admin/flash-sale/find/999999999')));
    }

    /** FS-E3 - an edit changes the window and the price. */
    public function test_edit_updates_the_window_and_the_price()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);
        $row = FlashSaleProduct::where('flash_sale_id', $sale->id)->first();

        $newEnd = Carbon::now()->addDays(9);

        $response = $this->save([
            'title' => 'Renamed',
            'status' => 1,
            'start_time' => $this->fmt(Carbon::now()->subHour()),
            'end_time' => $this->fmt($newEnd),
            'time_zone' => 'UTC',
            'products' => [[
                'id' => (string)$row->id, 'price' => 3.5, 'updated' => true,
                'product' => ['id' => $products[0]->id],
            ]],
        ], $sale->id);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $sale = $sale->fresh();

        $this->assertSame('Renamed', $sale->title);
        $this->assertSame($newEnd->format('Y-m-d H:i:s'), Carbon::parse($sale->end_time)->format('Y-m-d H:i:s'));
        $this->assertSame('3.50', (string)FlashSaleProduct::find($row->id)->price);
    }

    /** FS-E4 - an edit made in the last minute of a token's life. */
    public function test_edit_survives_a_token_rotation()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        // CheckForAllScopes rotates a near-expiry token into $request['token'].
        // Passport::actingAs reproduces that request shape exactly.
        $this->app['auth']->forgetGuards();
        Passport::actingAs($this->adminUser, ['admin'], 'admin');

        $response = $this->postJson('/api/admin/flash-sale/action/' . $sale->id, [
            'title' => 'Rotated',
            'status' => 1,
            'start_time' => $this->fmt(Carbon::now()->subHour()),
            'end_time' => $this->fmt(Carbon::now()->addDays(4)),
            'time_zone' => 'UTC',
            'products' => [],
        ]);

        $this->assertSame(200, $this->bodyStatus($response),
            'An edit failed when the admin token was rotated mid-request: '
            . (string)$response->json('message'));
    }

    /** FS-E5 - admin_id must not be settable from the payload. */
    public function test_edit_cannot_reassign_the_owning_admin()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        $other = Admin::where('id', '!=', $this->adminUser->id)->first();

        if (!$other) {
            $this->markTestSkipped('Only one admin on this install.');
        }

        $this->save([
            'title' => 'Hijack',
            'status' => 1,
            'start_time' => $this->fmt(Carbon::now()->subHour()),
            'end_time' => $this->fmt(Carbon::now()->addDay()),
            'time_zone' => 'UTC',
            'admin_id' => $other->id,
            'products' => [],
        ], $sale->id);

        $this->assertSame((int)$this->adminUser->id, (int)$sale->fresh()->admin_id,
            'The payload reassigned the flash sale to another admin.');
    }

    /** FS-E6 - disable a running sale. */
    public function test_a_sale_can_be_disabled()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        $response = $this->save([
            'title' => $sale->title,
            'status' => 2,
            'start_time' => $this->fmt(Carbon::now()->subHour()),
            'end_time' => $this->fmt(Carbon::now()->addDay()),
            'time_zone' => 'UTC',
            'products' => [],
        ], $sale->id);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertSame(2, (int)$sale->fresh()->status);
    }

    /** FS-E7 - status is not constrained to the two statuses that exist. */
    public function test_status_is_constrained_to_public_or_private()
    {
        $this->admin();
        $products = $this->products(1);

        $response = $this->save($this->payload(
            [['product' => $products[0], 'price' => 5]], ['status' => 7]
        ));

        $this->assertSame(201, $this->bodyStatus($response),
            'status 7 was accepted; only 1 (public) and 2 (private) exist.');
    }

    /** FS-E8 - delete removes the sale, its products and its translations. */
    public function test_delete_removes_the_sale_and_its_rows()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        FlashSaleLang::create(['flash_sale_id' => $sale->id, 'title' => 'x', 'lang' => 'fr']);

        $response = $this->deleteJson('/api/admin/flash-sale/delete/' . $sale->id, [], $this->adminHeaders);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertNull(FlashSale::find($sale->id));
        $this->assertSame(0, FlashSaleProduct::where('flash_sale_id', $sale->id)->count());
        $this->assertSame(0, FlashSaleLang::where('flash_sale_id', $sale->id)->count());
    }

    /** FS-E9 - delete on an id that is not there. */
    public function test_delete_on_an_unknown_id()
    {
        $this->admin();

        $this->assertSame(201, $this->bodyStatus(
            $this->deleteJson('/api/admin/flash-sale/delete/999999999', [], $this->adminHeaders)));
    }

    /** FS-E10 - a bulk delete is all-or-nothing. */
    public function test_a_bulk_delete_that_hits_a_bad_id_leaves_nothing_half_done()
    {
        $this->admin();
        $products = $this->products(2);
        $a = $this->createSale([['product' => $products[0], 'price' => 5]]);
        $b = $this->createSale([['product' => $products[1], 'price' => 5]]);

        $this->deleteJson("/api/admin/flash-sale/delete/{$a->id},999999999,{$b->id}", [], $this->adminHeaders);

        $survivors = FlashSale::whereIn('id', [$a->id, $b->id])->count();

        $this->assertContains($survivors, [0, 2],
            'A bulk delete stopped halfway: one sale was deleted and the other kept.');
    }

    /* ---- the sale window (Step 3.4-3.7, Step 5.7-9) ------------------ */

    /** A sale written straight to the tables, so the window is exact. */
    private function seedSale($product, $price, $start, $end, $status = 1)
    {
        $sale = FlashSale::create([
            'title' => 'Seeded ' . uniqid(),
            'status' => $status,
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time' => $end->format('Y-m-d H:i:s'),
            'admin_id' => $this->admin()->id,
        ]);

        FlashSaleProduct::create([
            'product_id' => $product->id,
            'flash_sale_id' => $sale->id,
            'price' => $price,
            'admin_id' => $this->adminUser->id,
        ]);

        return $sale;
    }

    /** What the storefront product endpoint quotes for one product. */
    private function storefrontPrice($product)
    {
        $this->asGuest();

        return (float)$this->getJson('/api/v1/product/' . $product->id)
            ->json('data.display_price');
    }

    /** FS-W1 - a running sale is what the storefront quotes. */
    public function test_a_running_sale_is_quoted_on_the_product_page()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 1.23, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->assertSame(1.23, $this->storefrontPrice($product));
    }

    /** FS-W2 - an expired sale is not. */
    public function test_an_expired_sale_is_not_quoted()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 1.23, Carbon::now()->subDays(5), Carbon::now()->subDays(2));

        $this->assertNotSame(1.23, $this->storefrontPrice($product),
            'An expired flash price was still being quoted.');
    }

    /** FS-W3 - a disabled sale is not. */
    public function test_a_disabled_sale_is_not_quoted()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 1.23, Carbon::now()->subHour(), Carbon::now()->addDay(), 2);

        $this->assertNotSame(1.23, $this->storefrontPrice($product),
            'A disabled flash price was still being quoted.');
    }

    /** FS-W4 - a sale that has not started yet must not be. */
    public function test_a_sale_that_has_not_started_is_not_quoted()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 1.23, Carbon::now()->addDays(2), Carbon::now()->addDays(5));

        $this->assertNotSame(1.23, $this->storefrontPrice($product),
            'A flash sale that starts in two days is already being charged today.');
    }

    /** FS-W5 - the flash sale page itself must not list an unstarted sale. */
    public function test_the_flash_sale_endpoint_hides_an_unstarted_sale()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 1.23, Carbon::now()->addDays(2), Carbon::now()->addDays(5));

        $this->asGuest();

        $ids = collect($this->getJson('/api/v1/flash-sale')->json('data'))
            ->pluck('id')->map('intval')->all();

        $this->assertNotContains($sale->id, $ids,
            'An unstarted flash sale is already on the storefront flash sale list.');
    }

    /** FS-W6 - and the detail page for it must not open. */
    public function test_the_flash_sale_detail_hides_an_unstarted_sale()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 1.23, Carbon::now()->addDays(2), Carbon::now()->addDays(5));

        $this->asGuest();

        $this->assertSame(201,
            $this->bodyStatus($this->getJson('/api/v1/flash-sale/' . $sale->id)),
            'An unstarted flash sale page is already open to shoppers.');
    }

    /** FS-W7 - an expired sale's page is closed. */
    public function test_the_flash_sale_detail_hides_an_expired_sale()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 1.23, Carbon::now()->subDays(5), Carbon::now()->subDay());

        $this->asGuest();

        $this->assertSame(201,
            $this->bodyStatus($this->getJson('/api/v1/flash-sale/' . $sale->id)));
    }

    /** FS-W8 - a running sale's page lists it at the flash price. */
    public function test_the_flash_sale_detail_lists_products_at_the_flash_price()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 1.23, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->asGuest();

        $response = $this->getJson('/api/v1/flash-sale/' . $sale->id);
        $row = collect($response->json('data.data'))->firstWhere('product_id', $product->id);

        $this->assertNotNull($row, 'The product is missing from its own flash sale page.');
        $this->assertSame(1.23, (float)$row['display_price']);
    }

    /* ---- cart & checkout (Steps 6, 9, 10) ---------------------------- */

    /** A published product with something in stock to actually buy. */
    private function sellableProduct()
    {
        $product = Product::where('products.status', 1)
            ->where('products.selling', '>', 0)
            ->whereNotIn('products.id', FlashSaleProduct::pluck('product_id'))
            ->whereHas('product_inventories', function ($q) {
                $q->where('quantity', '>', 5);
            })
            ->orderBy('products.id')
            ->first();

        if (!$product) {
            $this->markTestSkipped('No published, in-stock product to buy.');
        }

        return $product;
    }

    private function inventoryFor($product)
    {
        return UpdatedInventory::where('product_id', $product->id)
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->first();
    }

    private function addToCart($product, $inventory, $quantity = 1, $extra = [])
    {
        return $this->postJson('/api/v1/cart/action', array_merge([
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => $quantity,
        ], $extra));
    }

    private function cartLine($product)
    {
        return collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);
    }

    /** FS-B1 - a running sale is the price the basket holds. */
    public function test_the_basket_holds_the_flash_price()
    {
        $this->admin();
        $product = $this->sellableProduct();
        $inventory = $this->inventoryFor($product);

        $this->seedSale($product, 2.50, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 2);

        $line = $this->cartLine($product);

        $this->assertNotNull($line, 'The line never reached the basket.');
        $this->assertSame(2.5, (float)$line['unit_price']);
        $this->assertSame(5.0, (float)$line['line_total']);
    }

    /** FS-B2 - a price sent by the client is ignored. */
    public function test_the_basket_ignores_a_price_sent_by_the_client()
    {
        $this->admin();
        $product = $this->sellableProduct();
        $inventory = $this->inventoryFor($product);

        $this->seedSale($product, 2.50, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1, [
            'price' => 0.01, 'original_price' => 0.01,
        ]);

        $this->assertSame(2.5, (float)$this->cartLine($product)['unit_price'],
            'A price sent from the browser reached the basket.');
    }

    /** FS-B3 - the sale ending while the line sits there reprices it. */
    public function test_a_sale_expiring_while_in_the_basket_reprices_the_line()
    {
        $this->admin();
        $product = $this->sellableProduct();
        $inventory = $this->inventoryFor($product);

        $sale = $this->seedSale($product, 2.50, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);

        $this->assertSame(2.5, (float)$this->cartLine($product)['unit_price']);

        FlashSale::where('id', $sale->id)
            ->update(['end_time' => Carbon::now()->subMinute()->format('Y-m-d H:i:s')]);

        $this->assertNotSame(2.5, (float)$this->cartLine($product)['unit_price'],
            'An expired flash price survived in the basket.');
    }

    /** FS-B4 - an unstarted sale must not price the basket. */
    public function test_an_unstarted_sale_does_not_price_the_basket()
    {
        $this->admin();
        $product = $this->sellableProduct();
        $inventory = $this->inventoryFor($product);

        $this->seedSale($product, 2.50, Carbon::now()->addDays(2), Carbon::now()->addDays(5));

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);

        $this->assertNotSame(2.5, (float)$this->cartLine($product)['unit_price'],
            'A flash sale that has not started is already discounting the basket.');
    }

    /* ---- the money the order takes ----------------------------------- */

    private $place;

    /** A product that can actually reach checkout: priced, stocked, shippable. */
    private function buyableProduct()
    {
        $rows = Product::whereHas('product_inventories')
            ->where('selling', '>', 0)
            ->where('status', config('constants.status.PUBLIC'))
            ->whereNotNull('shipping_rule_id')
            ->whereNotIn('id', FlashSaleProduct::pluck('product_id'))
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($rows as $product) {
            $inventory = UpdatedInventory::where('product_id', $product->id)->orderBy('id')->first();
            $place = optional($product->shipping_rule)->shipping_places()->first();

            if (!$inventory || !$place) {
                continue;
            }

            UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 5000]);

            $this->place = $place;

            return [$product, $inventory->fresh()];
        }

        $this->markTestSkipped('No product that can reach checkout.');
    }

    private function addressedCustomer($attributes = [])
    {
        $customer = $this->customer($attributes);

        $address = \App\Models\UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Flash Checkout',
            'phone' => $customer->phone,
            'country' => 'IE',
            'state' => 'Dublin',
            'city' => 'Dublin',
            'zip' => 'D02X286',
            'address_1' => '26 Main Street',
            'type' => 1,
            'default' => 1,
        ]);

        $customer->default_address = $address->id;
        $customer->save();

        $this->app['auth']->forgetGuards();
        Passport::actingAs($customer->fresh(), ['user'], 'user');

        return $customer->fresh();
    }

    private function shipAll($customer)
    {
        Cart::where('user_id', $customer->id)->update([
            'shipping_place_id' => $this->place->id,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
            'selected' => (int)config('constants.status.PUBLIC'),
        ]);
    }

    private function checkout()
    {
        $json = json_encode([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ]);

        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return $this->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC',
                hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
                hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);
    }

    private function orderedLine($orderId, $productId)
    {
        return DB::table('ordered_products_inventories')
            ->where('order_id', $orderId)
            ->where('product_id', $productId)
            ->first();
    }

    /** FS-O1 - the order charges the flash price. */
    public function test_the_order_charges_the_flash_price()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 2);
        $this->shipAll($customer);

        $response = $this->checkout();

        $this->assertSame(200, $this->bodyStatus($response),
            'Checkout refused: ' . json_encode($response->json('message')));

        $orderId = $response->json('data.id') ?? $response->json('data.order.id');
        $line = $this->orderedLine($orderId, $product->id);

        $this->assertNotNull($line, 'The order has no line for the product.');
        $this->assertSame(3.0, (float)$line->price);
    }

    /** FS-O2 - a sale that ended before checkout cannot be bought at its price. */
    public function test_an_expired_sale_cannot_be_bought_at_the_old_price()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $sale = $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 1);

        FlashSale::where('id', $sale->id)
            ->update(['end_time' => Carbon::now()->subMinute()->format('Y-m-d H:i:s')]);

        $this->shipAll($customer);

        $response = $this->checkout();

        $this->assertSame(200, $this->bodyStatus($response),
            'Checkout refused: ' . json_encode($response->json('message')));

        $orderId = $response->json('data.id') ?? $response->json('data.order.id');
        $line = $this->orderedLine($orderId, $product->id);

        $this->assertNotSame(3.0, (float)$line->price,
            'An expired flash price was charged.');
    }

    /** FS-O3 - an unstarted sale must not be buyable at its price. */
    public function test_an_unstarted_sale_cannot_be_bought_at_its_price()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->addDays(2), Carbon::now()->addDays(5));

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 1);
        $this->shipAll($customer);

        $response = $this->checkout();

        $this->assertSame(200, $this->bodyStatus($response),
            'Checkout refused: ' . json_encode($response->json('message')));

        $orderId = $response->json('data.id') ?? $response->json('data.order.id');
        $line = $this->orderedLine($orderId, $product->id);

        $this->assertNotSame(3.0, (float)$line->price,
            'A flash sale that has not started was charged at its sale price.');
    }

    /* ---- account types (Step 7) -------------------------------------- */

    /** The flash price this account is quoted on the product page. */
    private function quotedFor($product, $customer = null)
    {
        $this->app['auth']->forgetGuards();

        if ($customer) {
            Passport::actingAs($customer, ['user'], 'user');
        }

        return (float)$this->getJson('/api/v1/product/' . $product->id)
            ->json('data.display_price');
    }

    /** FS-A1 - a guest gets the flash price. */
    public function test_a_guest_is_quoted_the_flash_price()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->assertSame(4.0, $this->quotedFor($product));
    }

    /** FS-A2 - a personal account gets the flash price. */
    public function test_a_personal_account_is_quoted_the_flash_price()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->assertSame(4.0, $this->quotedFor($product, $this->customer()));
    }

    /** FS-A3 - a business account with no band gets the flash price. */
    public function test_a_business_account_with_no_band_is_quoted_the_flash_price()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $this->assertSame(4.0,
            $this->quotedFor($product, $this->customer(['account_type' => 'business'])));
    }

    /**
     * FS-A4 - a band never raises a flash price.
     *
     * The documented rule in Pricing::businessPrice: wholesale rates are
     * agreed long before a sale is scheduled, so a trade account must not be
     * charged more than a walk-up shopper during a promotion.
     */
    public function test_a_band_above_the_flash_price_does_not_raise_it()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $tier = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => 9.00,
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]);
        $tier->final_price = $tier->computedFinalPrice();
        $tier->save();

        $this->assertSame(4.0,
            $this->quotedFor($product, $this->customer(['account_type' => 'business'])));
    }

    /** FS-A5 - a band below the flash price still wins. */
    public function test_a_band_below_the_flash_price_still_wins()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $tier = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => 2.00,
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]);
        $tier->final_price = $tier->computedFinalPrice();
        $tier->save();

        $this->assertSame(2.0,
            $this->quotedFor($product, $this->customer(['account_type' => 'business'])));
    }

    /** FS-A6 - an approved trade account is quoted the cheaper of the two. */
    public function test_an_approved_trade_account_is_quoted_the_cheaper_figure()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $tier = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => 3.00,
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]);
        $tier->final_price = $tier->computedFinalPrice();
        $tier->save();

        $trade = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
        ]);

        $this->assertSame(3.0, $this->quotedFor($product, $trade));
    }

    /** FS-A7 - a trade account still awaiting approval buys at the flash price. */
    public function test_a_pending_trade_account_gets_the_flash_price_only()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $tier = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => 3.00,
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]);
        $tier->final_price = $tier->computedFinalPrice();
        $tier->save();

        $pending = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]);

        $this->assertSame(4.0, $this->quotedFor($product, $pending));
    }

    /* ---- stock (Step 3.8, Step 5.23-27) ------------------------------ */

    /*
     * A flash sale changes the price, never the stock rule. is_active on an
     * inventory row means backorder is allowed for that variant, so a zero
     * quantity on an active row is purchasable on purpose; the control case
     * behaves identically with no flash sale in the picture. These assert the
     * rule as written, so a flash sale that starts bending it is caught.
     */

    /** FS-S1 - a backorderable variant stays purchasable during a sale. */
    public function test_a_backorderable_flash_product_stays_purchasable()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        UpdatedInventory::where('product_id', $product->id)
            ->update(['quantity' => 0, 'is_active' => 1]);

        $this->asGuest();

        $this->assertTrue((bool)$this->getJson('/api/v1/product/' . $product->id)
            ->json('data.in_stock'));
    }

    /** FS-S2 - a variant that is out of stock and not backorderable is refused. */
    public function test_an_unbackorderable_out_of_stock_flash_product_is_refused()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        UpdatedInventory::where('product_id', $product->id)
            ->update(['quantity' => 0, 'is_active' => 0]);

        $this->actingAsCustomer();

        $response = $this->addToCart($product, $inventory->fresh(), 1);

        $this->assertSame(201, $this->bodyStatus($response),
            'An out-of-stock, non-backorderable flash product went into the basket.');
    }

    /** FS-S3 - a quantity beyond stock on a non-backorderable variant. */
    public function test_a_quantity_beyond_stock_is_refused_when_backorder_is_off()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        UpdatedInventory::where('id', $inventory->id)
            ->update(['quantity' => 3, 'is_active' => 0]);

        $this->actingAsCustomer();

        $response = $this->addToCart($product, $inventory->fresh(), 10);

        $this->assertSame(201, $this->bodyStatus($response),
            'Ten units went into the basket with three in stock and backorder off.');
    }

    /** FS-S4 - the sale price still applies to a backordered unit. */
    public function test_the_flash_price_applies_to_a_backordered_unit()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        UpdatedInventory::where('product_id', $product->id)
            ->update(['quantity' => 0, 'is_active' => 1]);

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory->fresh(), 1);

        $this->assertSame(3.0, (float)$this->cartLine($product)['unit_price']);
    }

    /* ---- integrity & lifecycle (Step 5.28-30, Step 12) --------------- */

    /** FS-I1 - deleting a product that a flash sale holds. */
    public function test_deleting_a_product_a_flash_sale_holds()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $sale = $this->seedSale($product, 2.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $failed = false;

        try {
            DB::table('products')->where('id', $product->id)->delete();
        } catch (\Exception $e) {
            $failed = true;
        }

        $this->assertTrue($failed,
            'A product was deleted out from under a live flash sale.');
        $this->assertSame(1, FlashSaleProduct::where('flash_sale_id', $sale->id)->count());
    }

    /** FS-I2 - deleting the sale while a line sits in a basket. */
    public function test_deleting_a_sale_reprices_a_line_already_in_a_basket()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();
        $sale = $this->seedSale($product, 2.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);
        $this->assertSame(2.0, (float)$this->cartLine($product)['unit_price']);

        $this->admin();
        $this->deleteJson('/api/admin/flash-sale/delete/' . $sale->id, [], $this->adminHeaders);

        $this->app['auth']->forgetGuards();
        Passport::actingAs(User::find(Cart::where('product_id', $product->id)
            ->latest('id')->value('user_id')), ['user'], 'user');

        $this->assertNotSame(2.0, (float)$this->cartLine($product)['unit_price'],
            'A deleted flash sale is still pricing a basket.');
    }

    /** FS-I3 - disabling the sale reprices a line already in a basket. */
    public function test_disabling_a_sale_reprices_a_line_already_in_a_basket()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();
        $sale = $this->seedSale($product, 2.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $customer = $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);
        $this->assertSame(2.0, (float)$this->cartLine($product)['unit_price']);

        FlashSale::where('id', $sale->id)->update(['status' => 2]);

        $this->app['auth']->forgetGuards();
        Passport::actingAs($customer, ['user'], 'user');

        $this->assertNotSame(2.0, (float)$this->cartLine($product)['unit_price'],
            'A disabled flash sale is still pricing a basket.');
    }

    /** FS-I4 - a refused save must not leave a half-built sale behind. */
    public function test_a_refused_save_leaves_no_half_built_sale()
    {
        $this->admin();

        $before = FlashSale::count();

        $this->save($this->payload([], ['products' => [
            ['price' => 5, 'product' => ['id' => 999999999]],
        ]]));

        $this->assertSame($before, FlashSale::count(),
            'A flash sale row survived a save that failed on its products.');
    }

    /* ---- the money, in full (Step 6) --------------------------------- */

    /** FS-M1 - the order records the flash price and the price it replaced. */
    public function test_the_order_records_both_the_flash_price_and_the_list_price()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $listPrice = Pricing::retailUnitPrice(Product::pricingRecord($product->id), $inventory);

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 2);
        $this->shipAll($customer);

        $response = $this->checkout();
        $this->assertSame(200, $this->bodyStatus($response),
            'Checkout refused: ' . json_encode($response->json('message')));

        $orderId = $response->json('data.id') ?? $response->json('data.order.id');
        $line = $this->orderedLine($orderId, $product->id);

        $this->assertSame(3.0, (float)$line->price, 'The charged price is not the flash price.');
        $this->assertSame(2, (int)$line->quantity);
        $this->assertSame((float)$listPrice, (float)$line->actual_price,
            'actual_price should be the price the flash sale replaced.');
    }

    /** FS-M2 - the order total is built from the flash price, not the shelf price. */
    public function test_the_order_total_is_built_from_the_flash_price()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 2);
        $this->shipAll($customer);

        $response = $this->checkout();
        $orderId = $response->json('data.id') ?? $response->json('data.order.id');

        $order = DB::table('orders')->where('id', $orderId)->first();
        $line = $this->orderedLine($orderId, $product->id);

        $goods = 3.00 * 2;
        $expected = $goods + (float)$line->shipping_price;

        $this->assertEqualsWithDelta($expected, (float)$order->total_amount, 0.01,
            'The order total does not add up from the flash price.');
    }

    /** FS-M3 - VAT is taken on the flash price, not the shelf price. */
    public function test_vat_is_taken_on_the_flash_price()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);

        $line = $this->cartLine($product);

        $record = Product::pricingRecord($product->id);
        $expected = Pricing::vatBreakdown(
            3.00, Pricing::vatRate($record->tax_rules ?? null), Pricing::isVatInclusive($record)
        );

        $this->assertSame((float)$expected['ex'], (float)$line['unit_ex_vat']);
        $this->assertSame((float)$expected['vat'], (float)$line['unit_vat']);
        $this->assertSame((float)$expected['inc'], (float)$line['unit_inc_vat']);
    }

    /** FS-M4 - a quantity of zero or less is refused. */
    public function test_a_zero_or_negative_quantity_is_refused()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 3.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();

        $this->addToCart($product, $inventory, -5);

        $line = $this->cartLine($product);

        $this->assertTrue(is_null($line) || (int)$line['quantity'] > 0,
            'A negative quantity reached the basket.');
    }

    /* ---- what a bad price actually costs (severity evidence) --------- */

    /** FS-X1 - a negative flash price is charged as a negative line. */
    public function test_a_negative_flash_price_reaches_the_basket()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, -50.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);

        $this->assertGreaterThanOrEqual(0, (float)$this->cartLine($product)['unit_price'],
            'A negative flash price became a negative basket line.');
    }

    /** FS-X2 - a zero flash price gives the product away. */
    public function test_a_zero_flash_price_gives_the_product_away()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $this->seedSale($product, 0.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);

        $this->assertGreaterThan(0, (float)$this->cartLine($product)['unit_price'],
            'A flash price of 0.00 is being charged as free.');
    }

    /** FS-X3 - a flash price above the shelf price raises what is charged. */
    public function test_a_flash_price_above_the_shelf_price_raises_the_charge()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        $shelf = Pricing::retailUnitPrice(Product::pricingRecord($product->id), $inventory);

        $this->seedSale($product, $shelf + 100, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);

        $this->assertLessThanOrEqual((float)$shelf, (float)$this->cartLine($product)['unit_price'],
            'A flash "sale" is charging more than the shelf price.');
    }

    /* ---- what the storefront payloads carry (Step 8) ----------------- */

    /** FS-F1 - the home rail payload carries the flash price. */
    public function test_the_home_rail_payload_carries_the_flash_price()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $sale = $this->seedSale($product, 1.11, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->asGuest();

        $row = collect($this->getJson('/api/v1/flash-sale')->json('data'))
            ->firstWhere('id', $sale->id);

        $this->assertNotNull($row, 'The sale is missing from the rail payload.');

        $entry = collect($row['public_products'])->firstWhere('product_id', $product->id);

        $this->assertNotNull($entry, 'The product is missing from the rail payload.');
        $this->assertSame(1.11, (float)$entry['price']);
    }

    /**
     * FS-F2 - the rail rows are not priced for whoever is looking.
     *
     * Every other product surface runs its rows through ListingPrices, which
     * is the one place the customer's entitlement and the VAT split are
     * applied. FlashSale::public_products does not, so the home rail quotes
     * the flash price to a business account whose band is lower, with no
     * vat_rate for the Inc-/Ex-VAT label the rest of the shop carries.
     */
    public function test_the_home_rail_rows_are_priced_for_the_account()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $sale = $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $tier = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 1, 'max_qty' => 0,
            'wholesale_price' => 2.00,
            'discount_type' => 'fixed', 'discount_value' => 0,
        ]);
        $tier->final_price = $tier->computedFinalPrice();
        $tier->save();

        $this->app['auth']->forgetGuards();
        Passport::actingAs($this->customer(['account_type' => 'business']), ['user'], 'user');

        $row = collect($this->getJson('/api/v1/flash-sale')->json('data'))
            ->firstWhere('id', $sale->id);
        $entry = collect($row['public_products'])->firstWhere('product_id', $product->id);

        $this->assertArrayHasKey('display_price', $entry,
            'The rail rows never reach ListingPrices, so they carry no account price and no VAT split.');
        $this->assertSame(2.0, (float)$entry['display_price']);
    }

    /** FS-F3 - the sale page carries the account's own price. */
    public function test_the_flash_sale_page_carries_the_account_price()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $sale = $this->seedSale($product, 4.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $tier = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 1, 'max_qty' => 0,
            'wholesale_price' => 2.00,
            'discount_type' => 'fixed', 'discount_value' => 0,
        ]);
        $tier->final_price = $tier->computedFinalPrice();
        $tier->save();

        $this->app['auth']->forgetGuards();
        Passport::actingAs($this->customer(['account_type' => 'business']), ['user'], 'user');

        $row = collect($this->getJson('/api/v1/flash-sale/' . $sale->id)->json('data.data'))
            ->firstWhere('product_id', $product->id);

        $this->assertSame(2.0, (float)$row['display_price'],
            'The sale page does not price against the account.');

        /*
         * price stays the raw flash figure -- that is what the row IS. What
         * matters is that the tile has the account's price to render and the
         * retail figure to strike through, which is the contract
         * FlashProductTile now reads (it used to render price).
         */
        $this->assertSame(4.0, (float)$row['price']);
        $this->assertSame(10.0, (float)$row['display_compare_price']);
        $this->assertArrayHasKey('vat_rate', $row);
    }

    /* ---- permission granularity (Step 2.21) -------------------------- */

    /** An admin holding exactly the permissions named, and its bearer token. */
    private function adminWith(array $permissions)
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'qa_flash_' . uniqid(), 'guard_name' => 'admin']
        );
        $role->syncPermissions($permissions);

        $admin = Admin::create([
            'name' => 'QA Flash Admin',
            'email' => 'qa.flash.' . uniqid() . '@example.test',
            'password' => bcrypt('Abcdefghij1'),
            'status' => 1,
        ]);
        $admin->assignRole($role);

        Passport::personalAccessTokensExpireIn(Carbon::now()->addDays(30));

        /*
         * The auth guards keep whoever they resolved on the previous request
         * inside one test, and ControllerHelper reads the admin off the guard
         * rather than off the token. Without this the restricted call is
         * authorised as the superadmin that set the fixture up.
         */
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' .
            $admin->fresh()->createToken('admin', ['admin'])->accessToken];
    }

    /** FS-P5 - view alone cannot create. */
    public function test_view_permission_alone_cannot_create()
    {
        $this->admin();
        $products = $this->products(1);
        $payload = $this->payload([['product' => $products[0], 'price' => 5]]);

        $headers = $this->adminWith(['flash_sale.view']);
        $before = FlashSale::count();

        $response = $this->postJson('/api/admin/flash-sale/action', $payload, $headers);

        $this->assertSame(403, $this->bodyStatus($response));
        $this->assertSame($before, FlashSale::count());
    }

    /** FS-P6 - create alone cannot edit. */
    public function test_create_permission_alone_cannot_edit()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        $headers = $this->adminWith(['flash_sale.create']);

        $response = $this->postJson('/api/admin/flash-sale/action/' . $sale->id, [
            'title' => 'Nope', 'status' => 1,
            'start_time' => $this->fmt(Carbon::now()->subHour()),
            'end_time' => $this->fmt(Carbon::now()->addDay()),
            'time_zone' => 'UTC', 'products' => [],
        ], $headers);

        $this->assertSame(403, $this->bodyStatus($response));
        $this->assertNotSame('Nope', $sale->fresh()->title);
    }

    /** FS-P7 - edit alone cannot delete. */
    public function test_edit_permission_alone_cannot_delete()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        $headers = $this->adminWith(['flash_sale.edit']);

        $response = $this->deleteJson('/api/admin/flash-sale/delete/' . $sale->id, [], $headers);

        $this->assertSame(403, $this->bodyStatus($response));
        $this->assertNotNull(FlashSale::find($sale->id));
    }

    /** FS-P8 - no flash_sale permission at all cannot read the list. */
    public function test_an_admin_without_the_permission_cannot_read_the_list()
    {
        $headers = $this->adminWith([]);

        $response = $this->getJson('/api/admin/flash-sale/all?orderby=created_at&type=desc', $headers);

        $this->assertSame(403, $this->bodyStatus($response));
    }

    /* ---- translations (flash_sale_langs) ----------------------------- */

    /** FS-T1 - an untranslated sale still has a title in another language. */
    public function test_an_untranslated_sale_keeps_its_title_in_the_listing()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        $row = collect($this->getJson('/api/admin/flash-sale/all?orderby=created_at&type=desc',
            $this->adminHeaders + ['language' => 'fr'])->json('data.data'))
            ->firstWhere('id', $sale->id);

        $this->assertNotNull($row, 'The sale vanished from the French listing.');
        $this->assertNotEmpty($row['title'],
            'A sale with no French translation lists with a blank title.');
    }

    /** FS-T2 - and opens in the editor with a title. */
    public function test_an_untranslated_sale_opens_with_a_title()
    {
        $this->admin();
        $products = $this->products(1);
        $sale = $this->createSale([['product' => $products[0], 'price' => 5]]);

        $response = $this->getJson('/api/admin/flash-sale/find/' . $sale->id,
            $this->adminHeaders + ['language' => 'fr']);

        $this->assertNotEmpty($response->json('data.title'),
            'Opening an untranslated sale in another language shows an empty title field.');
    }

    /** FS-T3 - and the storefront rail names it too. */
    public function test_the_storefront_rail_names_an_untranslated_sale()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $sale = $this->seedSale($product, 1.11, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->asGuest();

        $row = collect($this->getJson('/api/v1/flash-sale', ['language' => 'fr'])->json('data'))
            ->firstWhere('id', $sale->id);

        $this->assertNotNull($row);
        $this->assertNotEmpty($row['title'],
            'A shopper browsing in French sees an unnamed flash sale banner.');
    }

    /* ---- admin list filters (Step 2.13) ------------------------------ */

    private function listedIds($query)
    {
        return collect($this->adminGet('/api/admin/flash-sale/all?' . $query)->json('data.data'))
            ->pluck('id')->map('intval')->all();
    }

    /** FS-N1 - filter by where the sale sits against the clock. */
    public function test_the_list_can_be_filtered_by_window()
    {
        $this->admin();
        $products = $this->products(3);

        $upcoming = $this->seedSale($products[0], 1, Carbon::now()->addDay(), Carbon::now()->addDays(3));
        $running = $this->seedSale($products[1], 1, Carbon::now()->subHour(), Carbon::now()->addDay());
        $expired = $this->seedSale($products[2], 1, Carbon::now()->subDays(3), Carbon::now()->subDay());

        $up = $this->listedIds('filter_window=upcoming');
        $this->assertContains($upcoming->id, $up);
        $this->assertNotContains($running->id, $up);
        $this->assertNotContains($expired->id, $up);

        $live = $this->listedIds('filter_window=running');
        $this->assertContains($running->id, $live);
        $this->assertNotContains($upcoming->id, $live);

        $gone = $this->listedIds('filter_window=expired');
        $this->assertContains($expired->id, $gone);
        $this->assertNotContains($running->id, $gone);
    }

    /** FS-N2 - filter by status. */
    public function test_the_list_can_be_filtered_by_status()
    {
        $this->admin();
        $products = $this->products(2);

        $on = $this->seedSale($products[0], 1, Carbon::now()->subHour(), Carbon::now()->addDay(), 1);
        $off = $this->seedSale($products[1], 1, Carbon::now()->subHour(), Carbon::now()->addDay(), 2);

        $active = $this->listedIds('filter_status=1');
        $this->assertContains($on->id, $active);
        $this->assertNotContains($off->id, $active);

        $inactive = $this->listedIds('filter_status=2');
        $this->assertContains($off->id, $inactive);
        $this->assertNotContains($on->id, $inactive);
    }

    /** FS-N3 - an unknown filter value is ignored rather than fatal. */
    public function test_an_unknown_filter_value_is_ignored()
    {
        $this->admin();

        $this->assertSame(200, $this->bodyStatus(
            $this->adminGet('/api/admin/flash-sale/all?filter_status=abc&filter_window=nonsense')));
    }

    /* ---- the quantity allowance (Step 3.9, Step 5.26-27) ------------- */

    /** A seeded sale whose product carries a cap. */
    private function seedCappedSale($product, $price, $limit, $sold = 0)
    {
        $sale = $this->seedSale($product, $price, Carbon::now()->subHour(), Carbon::now()->addDay());

        FlashSaleProduct::where('flash_sale_id', $sale->id)
            ->update(['quantity_limit' => $limit, 'quantity_sold' => $sold]);

        return $sale;
    }

    /** FS-Q1 - a cap with room left still gives the sale price. */
    public function test_an_allowance_with_room_left_still_gives_the_sale_price()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedCappedSale($product, 2.00, 50, 10);

        $this->assertSame(2.0, $this->storefrontPrice($product));
    }

    /** FS-Q2 - an exhausted allowance reverts to the normal price. */
    public function test_an_exhausted_allowance_reverts_to_the_normal_price()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $this->seedCappedSale($product, 2.00, 50, 50);

        $expected = (float)Pricing::shelfPrice($product);

        $this->assertSame($expected, $this->storefrontPrice($product),
            'An exhausted allowance is still being sold at the sale price.');
    }

    /** FS-Q3 - and the product is still buyable, at that normal price. */
    public function test_an_exhausted_allowance_does_not_block_the_purchase()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();
        $this->seedCappedSale($product, 2.00, 5, 5);

        $this->actingAsCustomer();

        $response = $this->addToCart($product, $inventory, 1);

        $this->assertSame(200, $this->bodyStatus($response),
            'An exhausted allowance blocked the purchase instead of repricing it.');
        $this->assertNotSame(2.0, (float)$this->cartLine($product)['unit_price']);
    }

    /** FS-Q4 - no cap means no cap, however much has been sold. */
    public function test_an_uncapped_row_is_never_exhausted()
    {
        $this->admin();
        $product = $this->products(1)[0];
        $sale = $this->seedSale($product, 2.00, Carbon::now()->subHour(), Carbon::now()->addDay());

        FlashSaleProduct::where('flash_sale_id', $sale->id)
            ->update(['quantity_limit' => null, 'quantity_sold' => 9999]);

        $this->assertSame(2.0, $this->storefrontPrice($product));
    }

    /** FS-Q5 - placing an order counts against the allowance. */
    public function test_placing_an_order_counts_against_the_allowance()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();
        $sale = $this->seedCappedSale($product, 3.00, 10, 0);

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 4);
        $this->shipAll($customer);

        $response = $this->checkout();
        $this->assertSame(200, $this->bodyStatus($response),
            'Checkout refused: ' . json_encode($response->json('message')));

        $row = FlashSaleProduct::where('flash_sale_id', $sale->id)->first();

        $this->assertSame(4, (int)$row->quantity_sold,
            'The order did not count against the allowance.');
    }

    /** FS-Q6 - and the sale price is what it was charged at. */
    public function test_the_units_inside_the_allowance_are_charged_at_the_sale_price()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();
        $this->seedCappedSale($product, 3.00, 10, 0);

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 2);
        $this->shipAll($customer);

        $response = $this->checkout();
        $orderId = $response->json('data.id') ?? $response->json('data.order.id');

        $this->assertSame(3.0, (float)$this->orderedLine($orderId, $product->id)->price);
    }

    /** FS-Q7 - an order that uses the allowance up reverts the price. */
    public function test_an_order_that_uses_the_allowance_up_reverts_the_price()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();
        $this->seedCappedSale($product, 3.00, 2, 0);

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 2);
        $this->shipAll($customer);

        $this->assertSame(200, $this->bodyStatus($this->checkout()));

        // The next shopper along.
        $this->assertNotSame(3.0, $this->storefrontPrice($product),
            'The allowance was used up and the sale price is still being quoted.');
    }

    /** FS-Q8 - cancelling the order gives the allowance back. */
    public function test_cancelling_an_order_gives_the_allowance_back()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();
        $sale = $this->seedCappedSale($product, 3.00, 10, 0);

        $customer = $this->addressedCustomer();
        $this->addToCart($product, $inventory, 3);
        $this->shipAll($customer);

        $response = $this->checkout();
        $orderId = $response->json('data.id') ?? $response->json('data.order.id');

        $this->assertSame(3, (int)FlashSaleProduct::where('flash_sale_id', $sale->id)->value('quantity_sold'));

        DB::table('orders')->where('id', $orderId)
            ->update(['cancelled' => 1, 'status' => 6]);

        \App\Models\Order::find($orderId)->refreshFlashAllowances();

        $this->assertSame(0, (int)FlashSaleProduct::where('flash_sale_id', $sale->id)->value('quantity_sold'),
            'A cancelled order is still holding the allowance.');
    }

    /** FS-Q9 - the admin API stores a limit, and refuses a nonsense one. */
    public function test_the_admin_api_stores_a_quantity_limit()
    {
        $this->admin();
        $products = $this->products(1);

        $payload = $this->payload([['product' => $products[0], 'price' => 5]]);
        $payload['products'][0]['quantity_limit'] = 25;

        $response = $this->save($payload);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $sale = FlashSale::find($response->json('data.id'));

        $this->assertSame(25, (int)FlashSaleProduct::where('flash_sale_id', $sale->id)->value('quantity_limit'));
    }

    /** FS-Q10 - blank means no limit. */
    public function test_a_blank_quantity_limit_means_no_limit()
    {
        $this->admin();
        $products = $this->products(1);

        $payload = $this->payload([['product' => $products[0], 'price' => 5]]);
        $payload['products'][0]['quantity_limit'] = '';

        $response = $this->save($payload);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $sale = FlashSale::find($response->json('data.id'));

        $this->assertNull(FlashSaleProduct::where('flash_sale_id', $sale->id)->value('quantity_limit'));
    }

    /** FS-Q11 - a limit of zero or less is refused. */
    public function test_a_quantity_limit_below_one_is_refused()
    {
        $this->admin();
        $products = $this->products(1);

        foreach ([0, -5, 'lots'] as $bad) {
            $payload = $this->payload([['product' => $products[0], 'price' => 5]]);
            $payload['products'][0]['quantity_limit'] = $bad;

            $this->assertSame(201, $this->bodyStatus($this->save($payload)),
                "A quantity limit of '{$bad}' was accepted.");
        }
    }

    /** FS-Q12 - quantity_sold cannot be set from the payload. */
    public function test_the_tally_cannot_be_set_from_the_payload()
    {
        $this->admin();
        $products = $this->products(1);

        $payload = $this->payload([['product' => $products[0], 'price' => 5]]);
        $payload['products'][0]['quantity_limit'] = 10;
        $payload['products'][0]['quantity_sold'] = 9;

        $response = $this->save($payload);
        $sale = FlashSale::find($response->json('data.id'));

        $this->assertSame(0, (int)FlashSaleProduct::where('flash_sale_id', $sale->id)->value('quantity_sold'),
            'The client set the allowance tally.');
    }

    /* ---- the ceiling the admin is held to is the one that is charged --- */

    /**
     * FS-C7 - a product already on offer is discounted from the OFFER.
     *
     * Pricing caps a flash price at shelfPrice() -- the offer price when
     * there is one -- so a sale priced between the offer and the full price
     * is not a reduction at all: the customer was already paying less. The
     * admin screen measured against the higher of the two, so it accepted a
     * figure the till would never charge, and the staff member had no way to
     * tell. Hiding the Offered column makes that gap invisible, so the two
     * have to agree.
     */
    public function test_a_sale_price_between_the_offer_and_the_full_price_is_refused()
    {
        $this->admin();
        $product = $this->products(1)[0];

        Product::where('id', $product->id)->update(['selling' => 500, 'offered' => 450]);
        $product = $product->fresh();

        $response = $this->save($this->payload([['product' => $product, 'price' => 480]]));

        $this->assertSame(201, $this->bodyStatus($response),
            'A sale price of 480 was accepted against an offer price of 450 — '
            . 'the customer would still be charged 450.');
    }

    /** FS-C8 - and the same product still takes a genuine reduction. */
    public function test_a_sale_price_below_the_offer_is_accepted()
    {
        $this->admin();
        $product = $this->products(1)[0];

        Product::where('id', $product->id)->update(['selling' => 500, 'offered' => 450]);
        $product = $product->fresh();

        $response = $this->save($this->payload([['product' => $product, 'price' => 400]]));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
    }

    /** FS-C9 - what the admin is held to is what the basket charges. */
    public function test_the_admin_ceiling_matches_what_the_basket_charges()
    {
        $this->admin();
        [$product, $inventory] = $this->buyableProduct();

        Product::where('id', $product->id)->update(['selling' => 500, 'offered' => 450]);

        $this->seedSale($product->fresh(), 480, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->actingAsCustomer();
        $this->addToCart($product, $inventory, 1);

        $this->assertSame(450.0, (float)$this->cartLine($product)['unit_price'],
            'The basket does not charge the shelf price the admin ceiling is derived from.');
    }

    /* ---- variation products (Workflow A) ----------------------------- */

    /**
     * A product with variants at three different prices, which is the case
     * a single absolute flash price gets wrong: 648 across the iPhone 15
     * Pro's tiers is 7% off the cheapest and 35% off the dearest.
     */
    private function tieredProduct()
    {
        $product = Product::where('status', 1)
            ->whereNotIn('id', FlashSaleProduct::pluck('product_id'))
            ->whereHas('product_inventories', function ($q) {
                $q->whereHas('inventory_attributes');
            })
            ->get()
            ->first(function ($candidate) {
                return UpdatedInventory::where('product_id', $candidate->id)
                    ->whereHas('inventory_attributes')
                    ->where('price', '>', 0)
                    ->distinct()->pluck('price')->count() > 1;
            });

        if (!$product) {
            $this->markTestSkipped('No variation product with more than one price tier.');
        }

        return $product;
    }

    private function tierInventories($product)
    {
        return UpdatedInventory::where('product_id', $product->id)
            ->whereHas('inventory_attributes')
            ->where('price', '>', 0)
            ->get()
            ->unique('price')
            ->sortBy('price')
            ->values();
    }

    /** FS-VR1 - a percentage reduces each variant from its own price. */
    public function test_a_percentage_scales_across_the_variants()
    {
        $this->admin();
        $product = $this->tieredProduct();
        $tiers = $this->tierInventories($product);

        $sale = $this->seedSale($product, 0, Carbon::now()->subHour(), Carbon::now()->addDay());

        FlashSaleProduct::where('flash_sale_id', $sale->id)->update([
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'price' => Pricing::round(Pricing::shelfPrice($product) * 0.8),
        ]);

        $record = Product::pricingRecord($product->id);

        foreach ($tiers as $inventory) {
            $this->assertSame(
                Pricing::round((float)$inventory->price * 0.8),
                Pricing::retailUnitPrice($record, $inventory),
                "the {$inventory->price} tier was not reduced by 20% of its own price"
            );
        }
    }

    /** FS-VR2 - and the listing keeps its range instead of collapsing. */
    public function test_a_percentage_keeps_the_listing_range()
    {
        $this->admin();
        $product = $this->tieredProduct();
        $inventories = UpdatedInventory::where('product_id', $product->id)->get();

        $before = Pricing::displayRange(Product::pricingRecord($product->id), $inventories, null);
        $this->assertTrue($before['variable'], 'the fixture is not a variable-priced product');

        $sale = $this->seedSale($product, 0, Carbon::now()->subHour(), Carbon::now()->addDay());

        FlashSaleProduct::where('flash_sale_id', $sale->id)->update([
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'price' => Pricing::round(Pricing::shelfPrice($product) * 0.8),
        ]);

        $after = Pricing::displayRange(Product::pricingRecord($product->id), $inventories, null);

        $this->assertTrue($after['variable'],
            'a flash sale flattened a variable-priced product to one price');
        $this->assertSame(Pricing::round($before['min'] * 0.8), $after['min']);
        $this->assertSame(Pricing::round($before['max'] * 0.8), $after['max']);
    }

    /** FS-VR3 - the API stores a percentage sale. */
    public function test_the_api_stores_a_percentage_sale()
    {
        $this->admin();
        $product = $this->tieredProduct();

        $payload = $this->payload([['product' => $product, 'price' => 0]]);
        $payload['products'][0]['discount_type'] = 'percentage';
        $payload['products'][0]['discount_value'] = 20;

        $response = $this->save($payload);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $row = FlashSaleProduct::where('flash_sale_id', $response->json('data.id'))->first();

        $this->assertSame('percentage', $row->discount_type);
        $this->assertSame('20.00', (string)$row->discount_value);
        $this->assertSame(
            Pricing::round(Pricing::shelfPrice($product) * 0.8),
            (float)$row->price,
            'the headline price is not the product-level figure'
        );
    }

    /** FS-VR4 - an absolute price is refused on a multi-tier product. */
    public function test_a_fixed_price_is_refused_on_a_variation_product()
    {
        $this->admin();
        $product = $this->tieredProduct();

        $payload = $this->payload([['product' => $product, 'price' => 1]]);
        $payload['products'][0]['discount_type'] = 'fixed_price';

        $response = $this->save($payload);

        $this->assertSame(201, $this->bodyStatus($response),
            'one flat price was accepted across variants priced differently');
        $this->assertStringContainsString('variants', (string)$response->json('message'));
    }

    /** FS-VR5 - and still accepted on a product with one price. */
    public function test_a_fixed_price_is_still_accepted_on_a_simple_product()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $payload = $this->payload([['product' => $product, 'price' => 1]]);
        $payload['products'][0]['discount_type'] = 'fixed_price';

        $this->assertSame(200, $this->bodyStatus($this->save($payload)));
    }

    /** FS-VR6 - a percentage of 100 or more is refused. */
    public function test_a_percentage_of_a_hundred_is_refused()
    {
        $this->admin();
        $product = $this->tieredProduct();

        foreach ([100, 150, 0, -5] as $bad) {
            $payload = $this->payload([['product' => $product, 'price' => 0]]);
            $payload['products'][0]['discount_type'] = 'percentage';
            $payload['products'][0]['discount_value'] = $bad;

            $this->assertSame(201, $this->bodyStatus($this->save($payload)),
                "a percentage of {$bad} was accepted");
        }
    }

    /** FS-VR7 - an amount that would wipe out the cheapest tier is refused. */
    public function test_an_amount_bigger_than_the_cheapest_tier_is_refused()
    {
        $this->admin();
        $product = $this->tieredProduct();
        $cheapest = (float)$this->tierInventories($product)->first()->price;

        $payload = $this->payload([['product' => $product, 'price' => 0]]);
        $payload['products'][0]['discount_type'] = 'amount';
        $payload['products'][0]['discount_value'] = $cheapest;

        $this->assertSame(201, $this->bodyStatus($this->save($payload)),
            'an amount equal to the cheapest variant was accepted');
    }

    /** FS-VR8 - the basket charges the variant's own reduced price. */
    public function test_the_basket_charges_the_variant_reduced_price()
    {
        $this->admin();
        $product = $this->tieredProduct();
        $dearest = $this->tierInventories($product)->last();

        UpdatedInventory::where('id', $dearest->id)->update(['quantity' => 50, 'is_active' => 1]);

        $sale = $this->seedSale($product, 0, Carbon::now()->subHour(), Carbon::now()->addDay());
        FlashSaleProduct::where('flash_sale_id', $sale->id)->update([
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'price' => Pricing::round(Pricing::shelfPrice($product) * 0.8),
        ]);

        $this->actingAsCustomer();
        $this->addToCart($product, $dearest->fresh(), 1);

        $this->assertSame(
            Pricing::round((float)$dearest->price * 0.8),
            (float)$this->cartLine($product)['unit_price'],
            'the basket charged the product-level figure rather than the variant’s'
        );
    }

    /** FS-VR9 - the form can read a product's price tiers. */
    public function test_the_tiers_endpoint_reports_every_price_tier()
    {
        $this->admin();
        $product = $this->tieredProduct();
        $expected = $this->tierInventories($product)
            ->pluck('price')->map(function ($p) { return round((float)$p, 2); })->values()->all();

        $response = $this->adminGet('/api/admin/flash-sale/product-tiers/' . $product->id);

        $this->assertSame(200, $this->bodyStatus($response));
        // JSON turns a whole float into an int, so compare as floats.
        $this->assertSame($expected,
            array_map('floatval', $response->json('data.tiers')));
    }

    /** FS-VR10 - a simple product reports its one tier. */
    public function test_the_tiers_endpoint_reports_one_tier_for_a_simple_product()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $tiers = $this->adminGet('/api/admin/flash-sale/product-tiers/' . $product->id)->json('data.tiers');

        $this->assertCount(1, $tiers);
        $this->assertSame(round(Pricing::shelfPrice($product), 2), (float)$tiers[0]);
    }

    /** FS-VR11 - and it is gated like the rest of the module. */
    public function test_the_tiers_endpoint_needs_the_permission()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $headers = $this->adminWith([]);

        $this->assertSame(403, $this->bodyStatus(
            $this->getJson('/api/admin/flash-sale/product-tiers/' . $product->id, $headers)));
    }

    /** FS-VR12 - opening a sale carries the tiers with it. */
    public function test_opening_a_sale_carries_the_tiers()
    {
        $this->admin();
        $product = $this->tieredProduct();

        $payload = $this->payload([['product' => $product, 'price' => 0]]);
        $payload['products'][0]['discount_type'] = 'percentage';
        $payload['products'][0]['discount_value'] = 10;

        $sale = FlashSale::find($this->save($payload)->json('data.id'));

        $row = $this->adminGet('/api/admin/flash-sale/find/' . $sale->id)->json('data.products.0');

        $this->assertNotNull($row['tiers'] ?? null, 'the row carries no tiers');
        $this->assertGreaterThan(1, count($row['tiers']['tiers']));
    }

    /**
     * FS-VR13 - a sale reopens as the shape it was saved as.
     *
     * The admin form used to force every row to 'percentage' on load and
     * derive a percentage back out of the stored price, so an "amount off"
     * sale reopened as a percentage and saving again stored it as one.
     * The server is what has to carry the shape across that round trip.
     */
    public function test_a_sale_reopens_as_the_shape_it_was_saved_as()
    {
        $this->admin();
        $product = $this->tieredProduct();

        foreach ([['percentage', 20], ['amount', 100]] as [$type, $value]) {
            $payload = $this->payload([['product' => $product, 'price' => 0]]);
            $payload['products'][0]['discount_type'] = $type;
            $payload['products'][0]['discount_value'] = $value;

            $saleId = $this->save($payload)->json('data.id');

            $this->assertNotNull($saleId, "the {$type} sale was refused");

            $row = $this->adminGet('/api/admin/flash-sale/find/' . $saleId)->json('data.products.0');

            $this->assertSame($type, $row['discount_type'],
                "a {$type} sale reopened as {$row['discount_type']}");
            $this->assertSame((float)$value, (float)$row['discount_value']);

            FlashSaleProduct::where('flash_sale_id', $saleId)->delete();
            FlashSale::where('id', $saleId)->delete();
        }
    }

    /** FS-VR14 - and an absolute price stays absolute. */
    public function test_an_absolute_price_reopens_as_an_absolute_price()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $payload = $this->payload([['product' => $product, 'price' => 1.50]]);
        $payload['products'][0]['discount_type'] = 'fixed_price';

        $saleId = $this->save($payload)->json('data.id');
        $row = $this->adminGet('/api/admin/flash-sale/find/' . $saleId)->json('data.products.0');

        $this->assertSame('fixed_price', $row['discount_type']);
        $this->assertSame(1.5, (float)$row['price']);
    }

    /**
     * FS-VR15 - re-saving an untouched sale does not change what it charges.
     *
     * The round trip is where the shape got lost, so the test that matters
     * is that a sale survives being opened and saved again.
     */
    public function test_reopening_and_saving_a_sale_leaves_the_prices_alone()
    {
        $this->admin();
        $product = $this->tieredProduct();
        $dearest = $this->tierInventories($product)->last();

        $payload = $this->payload([['product' => $product, 'price' => 0]]);
        $payload['products'][0]['discount_type'] = 'amount';
        $payload['products'][0]['discount_value'] = 100;

        $saleId = $this->save($payload)->json('data.id');

        $record = Product::pricingRecord($product->id);
        $before = Pricing::retailUnitPrice($record, $dearest);

        // Open it, and send back exactly what came out.
        $row = $this->adminGet('/api/admin/flash-sale/find/' . $saleId)->json('data.products.0');

        $again = $this->payload([], ['products' => [[
            'id' => (string)$row['id'],
            'updated' => true,
            'product' => ['id' => $product->id],
            'price' => $row['price'],
            'discount_type' => $row['discount_type'],
            'discount_value' => $row['discount_value'],
        ]]]);

        $this->assertSame(200, $this->bodyStatus($this->save($again, $saleId)));

        $this->assertSame($before,
            Pricing::retailUnitPrice(Product::pricingRecord($product->id), $dearest),
            'reopening and saving the sale changed what a variant is charged');
    }

    /* ---- what the product picker is told (Step 2.6) ------------------ */

    private function metaFor($ids, $exclude = null)
    {
        $url = '/api/admin/flash-sale/product-meta?ids=' . implode(',', (array)$ids)
            . ($exclude ? '&exclude=' . $exclude : '');

        return $this->adminGet($url)->json('data');
    }

    /** FS-PM1 - it says whether a product has variants. */
    public function test_the_picker_is_told_whether_a_product_has_variants()
    {
        $this->admin();
        $tiered = $this->tieredProduct();
        $simple = $this->products(1)[0];

        $meta = $this->metaFor([$tiered->id, $simple->id]);

        $this->assertGreaterThan(1, $meta[(string)$tiered->id]['variants']);
        $this->assertGreaterThan(1, count($meta[(string)$tiered->id]['tiers']));

        $this->assertSame(0, $meta[(string)$simple->id]['variants']);
        $this->assertCount(1, $meta[(string)$simple->id]['tiers']);
    }

    /** FS-PM2 - and which sale already holds it. */
    public function test_the_picker_is_told_a_product_is_already_in_a_live_sale()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subHour(), Carbon::now()->addDay());

        $held = $this->metaFor([$product->id])[(string)$product->id]['held_by'];

        $this->assertNotNull($held, 'a product in a running sale was offered as free');
        $this->assertSame($sale->id, $held['id']);
        $this->assertTrue($held['live']);
        $this->assertSame($sale->title, $held['title']);
    }

    /**
     * FS-PM3 - a finished sale does not hold the product any more.
     *
     * This asserted the opposite until the clash rule was narrowed: a sale
     * that had ended weeks ago still blocked its products for ever.
     */
    public function test_a_finished_sale_no_longer_holds_its_products()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 2, Carbon::now()->subDays(5), Carbon::now()->subDay());

        $this->assertNull(
            $this->metaFor([$product->id])[(string)$product->id]['held_by'],
            'a sale that has already ended is still blocking its products'
        );
    }

    /** FS-PM3b - a sale that has not started yet does hold them, though. */
    public function test_an_upcoming_sale_still_holds_its_products()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->addDay(), Carbon::now()->addDays(5));

        $held = $this->metaFor([$product->id])[(string)$product->id]['held_by'];

        $this->assertNotNull($held, 'a scheduled sale was not reported as holding its product');
        $this->assertSame($sale->id, $held['id']);
        $this->assertFalse($held['live'], 'a sale that has not started was reported as running');
    }

    /** FS-PM3c - and the picker answers for the window the form is on. */
    public function test_the_picker_answers_for_the_window_being_edited()
    {
        $this->admin();
        $product = $this->products(1)[0];

        // Runs next month; the sale being written is this week.
        $this->seedSale($product, 2, Carbon::now()->addDays(60), Carbon::now()->addDays(65));

        $url = '/api/admin/flash-sale/product-meta?ids=' . $product->id
            . '&start=' . urlencode(Carbon::now()->format('Y-m-d H:i:s'))
            . '&end=' . urlencode(Carbon::now()->addDays(5)->format('Y-m-d H:i:s'));

        $this->assertNull(
            $this->adminGet($url)->json('data.' . $product->id . '.held_by'),
            'a sale two months away blocked a product for this week'
        );
    }

    /** FS-PM4 - the sale being edited does not block its own products. */
    public function test_the_sale_being_edited_does_not_hold_its_own_products()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subHour(), Carbon::now()->addDay());

        $this->assertNull(
            $this->metaFor([$product->id], $sale->id)[(string)$product->id]['held_by'],
            'editing a sale reported its own products as taken'
        );
    }

    /** FS-PM5 - a free product reports nothing holding it. */
    public function test_a_free_product_is_reported_as_free()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->assertNull($this->metaFor([$product->id])[(string)$product->id]['held_by']);
    }

    /** FS-PM6 - and it is gated like the rest of the module. */
    public function test_the_meta_endpoint_needs_the_permission()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->assertSame(403, $this->bodyStatus($this->getJson(
            '/api/admin/flash-sale/product-meta?ids=' . $product->id, $this->adminWith([]))));
    }

    /* ---- a lapsed sale switches itself off --------------------------- */

    /** FS-EX1 - the sweep switches off a sale whose window has closed. */
    public function test_the_sweep_switches_off_a_lapsed_sale()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subDays(2), Carbon::now()->subHour());

        $this->assertSame(1, (int)$sale->status, 'the fixture did not start out live');

        \App\Console\Commands\ExpireFlashSales::sweep();

        $this->assertSame(2, (int)$sale->fresh()->status,
            'a sale whose end time has passed is still switched on');
    }

    /** FS-EX2 - a running sale is left alone. */
    public function test_the_sweep_leaves_a_running_sale_alone()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subHour(), Carbon::now()->addDay());

        \App\Console\Commands\ExpireFlashSales::sweep();

        $this->assertSame(1, (int)$sale->fresh()->status, 'a running sale was switched off');
    }

    /** FS-EX3 - so is one that has not started yet. */
    public function test_the_sweep_leaves_an_upcoming_sale_alone()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->addDay(), Carbon::now()->addDays(3));

        \App\Console\Commands\ExpireFlashSales::sweep();

        $this->assertSame(1, (int)$sale->fresh()->status,
            'a sale that has not started yet was switched off');
    }

    /** FS-EX4 - a sale the admin switched off stays off, and is not touched. */
    public function test_the_sweep_does_not_disturb_a_sale_already_switched_off()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subDays(2), Carbon::now()->subHour(), 2);

        \App\Console\Commands\ExpireFlashSales::sweep();

        $this->assertSame(2, (int)$sale->fresh()->status);
    }

    /** FS-EX5 - opening the admin list performs the sweep. */
    public function test_opening_the_list_switches_off_a_lapsed_sale()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subDays(2), Carbon::now()->subHour());

        $this->adminGet('/api/admin/flash-sale/all?orderby=created_at&type=desc');

        $this->assertSame(2, (int)$sale->fresh()->status,
            'the list showed a lapsed sale without switching it off');
    }

    /** FS-EX6 - and the artisan command reports what it did. */
    public function test_the_command_reports_what_it_switched_off()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subDays(2), Carbon::now()->subHour());

        $this->artisan('flash-sale:expire')
            ->expectsOutput('Switched off 1 lapsed flash sale(s).')
            ->assertExitCode(0);

        $this->assertSame(2, (int)$sale->fresh()->status);
    }

    /* ---- the window goes out as an instant, not a wall clock --------- */

    /**
     * FS-TZ1 - the storefront countdown needs a zone on the timestamp.
     *
     * Uncast, these went out as "2026-09-18 13:07:00" with no zone, which
     * new Date() reads as LOCAL time. A shopper on +05:30 therefore read a
     * 13:07 UTC end as 13:07 their time -- five and a half hours early --
     * and the countdown clamped to 00:00:00:00 on a sale still running.
     */
    public function test_the_storefront_window_carries_a_timezone()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subHour(), Carbon::now()->addHours(3));

        $this->asGuest();

        $row = collect($this->getJson('/api/v1/flash-sale')->json('data'))
            ->firstWhere('id', $sale->id);

        $this->assertNotNull($row);

        foreach (['start_time', 'end_time'] as $field) {
            $this->assertMatchesRegularExpression(
                '/(Z|[+\-]\d{2}:?\d{2})$/',
                (string)$row[$field],
                "{$field} went out with no timezone, so the browser reads it as local time"
            );
        }
    }

    /** FS-TZ2 - and it still means the instant that was stored. */
    public function test_the_storefront_window_is_the_instant_that_was_stored()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $end = Carbon::now()->addHours(3)->startOfSecond();
        $sale = $this->seedSale($product, 2, Carbon::now()->subHour(), $end);

        $this->asGuest();

        $row = collect($this->getJson('/api/v1/flash-sale')->json('data'))
            ->firstWhere('id', $sale->id);

        $this->assertSame(
            $end->utc()->timestamp,
            Carbon::parse($row['end_time'])->utc()->timestamp,
            'the end time no longer refers to the instant that was saved'
        );
    }

    /** FS-TZ3 - the sale page carries the same instant. */
    public function test_the_sale_page_window_carries_a_timezone()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subHour(), Carbon::now()->addHours(3));

        $this->asGuest();

        $row = collect($this->getJson('/api/v1/flash-sale/' . $sale->id)->json('data.data'))
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression(
            '/(Z|[+\-]\d{2}:?\d{2})$/', (string)$row['end_time']);
    }

    /** FS-TZ4 - and the admin editor still opens on the right local time. */
    public function test_the_admin_editor_still_reads_the_window()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $end = Carbon::now()->addDay()->startOfSecond();
        $sale = $this->seedSale($product, 2, Carbon::now()->subHour(), $end);

        $row = $this->adminGet('/api/admin/flash-sale/find/' . $sale->id)->json('data');

        $this->assertSame(
            $end->utc()->timestamp,
            Carbon::parse($row['end_time'])->utc()->timestamp
        );
    }

    /* ---- one sale at a time, not one sale ever ----------------------- */

    /** Try to put $product in a new sale spanning $start..$end. */
    private function saveWindow($product, Carbon $start, Carbon $end)
    {
        return $this->save($this->payload(
            [['product' => $product, 'price' => 1]],
            ['start_time' => $this->fmt($start), 'end_time' => $this->fmt($end)]
        ));
    }

    /** FS-CL1 - a product whose previous sale has ENDED can be used again. */
    public function test_a_product_from_a_finished_sale_can_be_used_again()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 2, Carbon::now()->subDays(3), Carbon::now()->subHour());

        $response = $this->saveWindow($product, Carbon::now()->subHour(), Carbon::now()->addDays(30));

        $this->assertSame(200, $this->bodyStatus($response),
            'a product was still blocked by a sale that had already ended: '
            . (string)$response->json('message'));
    }

    /** FS-CL2 - even when the old window overlaps, as long as it is over. */
    public function test_a_finished_sale_does_not_block_a_backdated_window()
    {
        $this->admin();
        $product = $this->products(1)[0];

        // Ended an hour ago; the new sale is backdated to before that.
        $this->seedSale($product, 2, Carbon::now()->subDays(2), Carbon::now()->subHour());

        $response = $this->saveWindow($product, Carbon::now()->subDays(2), Carbon::now()->addDays(30));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
    }

    /** FS-CL3 - two sales a month apart are both fine. */
    public function test_two_sales_in_different_months_can_share_a_product()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 2, Carbon::now()->addDays(60), Carbon::now()->addDays(65));

        $response = $this->saveWindow($product, Carbon::now()->subHour(), Carbon::now()->addDays(5));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
    }

    /** FS-CL4 - but overlapping windows still clash. */
    public function test_overlapping_windows_still_clash()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 2, Carbon::now()->addDays(5), Carbon::now()->addDays(15));

        $response = $this->saveWindow($product, Carbon::now()->addDays(10), Carbon::now()->addDays(20));

        $this->assertSame(201, $this->bodyStatus($response),
            'two sales overlapping on the same product were allowed');
        $this->assertStringContainsString('same dates', (string)$response->json('message'));
    }

    /** FS-CL5 - and the refusal names the sale in the way. */
    public function test_the_clash_names_the_sale_in_the_way()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $sale = $this->seedSale($product, 2, Carbon::now()->subHour(), Carbon::now()->addDays(10));

        $message = (string)$this->saveWindow(
            $product, Carbon::now(), Carbon::now()->addDays(2)
        )->json('message');

        $this->assertStringContainsString($sale->title, $message);
        $this->assertStringContainsString($product->title, $message);
    }

    /** FS-CL6 - a switched-off sale whose window overlaps still clashes. */
    public function test_a_switched_off_sale_with_an_overlapping_window_clashes()
    {
        $this->admin();
        $product = $this->products(1)[0];

        $this->seedSale($product, 2, Carbon::now()->addDay(), Carbon::now()->addDays(10), 2);

        $this->assertSame(201, $this->bodyStatus(
            $this->saveWindow($product, Carbon::now()->addDays(2), Carbon::now()->addDays(5))),
            'a product was double-booked into an overlapping sale that was merely switched off');
    }
}
