<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\UpsellCampaign;
use App\Models\UpsellOption;
use App\Models\UpsellSource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Promotions -> Product Recommendations -> Upsell, end to end through the real
 * admin routes.
 *
 * The cases here are the ones an upsell module gets wrong: a campaign saved
 * with its new sources against its old options, a product offered as an
 * upgrade to itself, a campaign switched on that can never fire, a variant
 * borrowed from a different product, and a refusal that arrives as HTTP 200
 * and is read as success.
 *
 * That last one is worth stating plainly, because it is the trap this codebase
 * sets: Validation::error returns status 201 IN THE BODY with an HTTP 200, so
 * asserting on the HTTP code alone passes for every refusal. Every negative
 * case below reads the body.
 */
class UpsellCampaignAdminTest extends TestCase
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

    /** Published products to build campaigns out of. */
    private function products($count = 3)
    {
        $products = Product::where('status', 1)->limit($count)->get();

        if ($products->count() < $count) {
            $this->markTestSkipped('Not enough published products to build a campaign.');
        }

        return $products;
    }

    /** A product that genuinely has more than one variant. */
    private function variantProduct()
    {
        $product = Product::where('status', 1)
            ->whereHas('product_inventories', null, '>=', 2)
            ->first();

        if (!$product) {
            $this->markTestSkipped('No multi-variant product to upgrade between.');
        }

        return $product;
    }

    private function payload($overrides = [])
    {
        $products = $this->products(3);

        return array_merge([
            'name' => 'QA Storage Upgrade ' . uniqid(),
            'description' => 'Offer customers the higher-storage option.',
            'status' => 2,
            'customer_type' => 'all',
            'source_products' => [
                ['product_id' => $products[0]->id],
            ],
            'upgrade_options' => [
                ['product_id' => $products[1]->id, 'priority' => 1],
                ['product_id' => $products[2]->id, 'priority' => 2],
            ],
            'show_on_product_page' => true,
            'show_on_cart_page' => true,
            'show_on_checkout_page' => false,
            'priority' => 10,
        ], $overrides);
    }

    /** The body's own status, which is where a refusal actually lives. */
    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }

    private function create($overrides = [])
    {
        $response = $this->postJson('/api/admin/upsell/action', $this->payload($overrides));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        return $response->json('data');
    }


    /* ---- permissions ------------------------------------------------- */

    /**
     * Unauthenticated, spelled the way this application spells it.
     *
     * Handler::unauthenticated answers HTTP 201 with status 204 in the body --
     * not 401. Asserting 401 here would fail against a perfectly closed
     * endpoint, and asserting 200 would pass against a wide open one, so both
     * halves are checked: the refusal code AND the absence of any data.
     */
    public function test_the_list_is_closed_to_anyone_without_an_admin_session()
    {
        $this->asGuest();

        $response = $this->getJson('/api/admin/upsell/all');

        $this->assertSame(204, (int)$response->json('status'));
        $this->assertNull($response->json('data'));
    }

    public function test_saving_is_closed_to_anyone_without_an_admin_session()
    {
        $this->asGuest();

        $response = $this->postJson('/api/admin/upsell/action', $this->payload());

        $this->assertSame(204, (int)$response->json('status'));
        $this->assertSame(0, UpsellCampaign::where('name', 'LIKE', 'QA Storage Upgrade %')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 5))
            ->count());
    }


    /* ---- create ------------------------------------------------------ */

    public function test_a_campaign_is_created_with_its_sources_and_options()
    {
        $this->admin();

        $data = $this->create();

        $this->assertSame(1, $data['sources_count']);
        $this->assertSame(2, $data['options_count']);
        $this->assertSame(2, (int)$data['status']);

        // The rows are really there, not just echoed back.
        $this->assertSame(1, UpsellSource::where('upsell_campaign_id', $data['id'])->count());
        $this->assertSame(2, UpsellOption::where('upsell_campaign_id', $data['id'])->count());
    }

    /**
     * The brief is explicit: a new campaign is inactive unless the admin
     * chose otherwise. A module that quietly publishes what it creates is one
     * an admin cannot draft in.
     */
    public function test_a_campaign_is_not_activated_unless_the_admin_asks()
    {
        $this->admin();

        $payload = $this->payload();
        unset($payload['status']);
        $payload['status'] = 2;

        $data = $this->create(['status' => 2]);

        $this->assertSame(2, (int)$data['status']);
        $this->assertFalse($data['is_live']);
    }

    public function test_the_order_options_were_asked_for_is_preserved()
    {
        $this->admin();

        $products = $this->products(3);

        $data = $this->create([
            'upgrade_options' => [
                ['product_id' => $products[2]->id, 'priority' => 1],
                ['product_id' => $products[1]->id, 'priority' => 2],
            ],
        ]);

        $this->assertSame(
            [(int)$products[2]->id, (int)$products[1]->id],
            array_column($data['options'], 'product_id')
        );
    }

    /**
     * No price, no discount, no VAT anywhere on the campaign.
     *
     * The pricing ladder stays the single source of truth for what an upgrade
     * costs. A figure banked here would go stale the moment the catalogue
     * moved, which is exactly the defect upsell_products.price had.
     */
    public function test_a_campaign_stores_no_money_of_its_own()
    {
        $this->admin();

        $data = $this->create();

        $columns = array_keys(
            \DB::getSchemaBuilder()->getColumnListing('upsell_campaigns')
                ? array_flip(\DB::getSchemaBuilder()->getColumnListing('upsell_campaigns'))
                : []
        );

        foreach (['price', 'discount_type', 'discount_value', 'vat', 'selling'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        foreach (['upsell_sources', 'upsell_options'] as $table) {
            $child = \DB::getSchemaBuilder()->getColumnListing($table);

            foreach (['price', 'discount_value', 'vat'] as $forbidden) {
                $this->assertNotContains($forbidden, $child, "$table carries $forbidden");
            }
        }

        $this->assertIsArray($data['options']);
    }


    /* ---- refusals ---------------------------------------------------- */

    public function test_a_product_cannot_be_offered_as_an_upgrade_to_itself()
    {
        $this->admin();

        $products = $this->products(2);

        $response = $this->postJson('/api/admin/upsell/action', $this->payload([
            'source_products' => [['product_id' => $products[0]->id]],
            'upgrade_options' => [['product_id' => $products[0]->id]],
        ]));

        // HTTP 200 with a refusal in the body - the trap this suite exists for.
        $response->assertStatus(200);
        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertArrayHasKey('upgrade_options', $response->json('data'));
    }

    /**
     * The same product on both sides IS allowed when the variants differ:
     * 128GB upgrading to 256GB is the canonical upsell and both are variants
     * of one catalogue product.
     */
    public function test_one_variant_may_be_upgraded_to_another_of_the_same_product()
    {
        $this->admin();

        $product = $this->variantProduct();

        $variants = UpdatedInventory::where('product_id', $product->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        $response = $this->postJson('/api/admin/upsell/action', $this->payload([
            'source_products' => [
                ['product_id' => $product->id, 'inventory_id' => $variants[0]->id],
            ],
            'upgrade_options' => [
                ['product_id' => $product->id, 'inventory_id' => $variants[1]->id],
            ],
        ]));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $data = $response->json('data');

        $this->assertSame((int)$variants[0]->id, $data['sources'][0]['inventory_id']);
        $this->assertSame((int)$variants[1]->id, $data['options'][0]['inventory_id']);
    }

    public function test_a_variant_from_a_different_product_is_refused()
    {
        $this->admin();

        $products = $this->products(2);

        $foreign = UpdatedInventory::where('product_id', '!=', $products[0]->id)->first();

        if (!$foreign) {
            $this->markTestSkipped('No inventory row belonging to another product.');
        }

        $response = $this->postJson('/api/admin/upsell/action', $this->payload([
            'source_products' => [
                ['product_id' => $products[0]->id, 'inventory_id' => $foreign->id],
            ],
        ]));

        $this->assertSame(201, $this->bodyStatus($response));
    }

    public function test_a_campaign_showing_nowhere_is_refused()
    {
        $this->admin();

        $response = $this->postJson('/api/admin/upsell/action', $this->payload([
            'show_on_product_page' => false,
            'show_on_cart_page' => false,
            'show_on_checkout_page' => false,
        ]));

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertArrayHasKey('display', $response->json('data'));
    }

    public function test_an_end_date_before_the_start_date_is_refused()
    {
        $this->admin();

        $response = $this->postJson('/api/admin/upsell/action', $this->payload([
            'start_date' => '2026-10-01 00:00:00',
            'end_date' => '2026-09-01 00:00:00',
        ]));

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertArrayHasKey('end_date', $response->json('data'));
    }

    public function test_a_campaign_with_no_upgrade_options_is_refused()
    {
        $this->admin();

        $response = $this->postJson('/api/admin/upsell/action', $this->payload([
            'upgrade_options' => [],
        ]));

        $this->assertSame(201, $this->bodyStatus($response));
    }

    public function test_an_unpublished_product_is_refused_and_named()
    {
        $this->admin();

        $private = Product::where('status', '!=', 1)->first();

        if (!$private) {
            $this->markTestSkipped('No unpublished product to refuse.');
        }

        $response = $this->postJson('/api/admin/upsell/action', $this->payload([
            'upgrade_options' => [['product_id' => $private->id]],
        ]));

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertStringContainsString($private->title, (string)$response->json('message'));
    }


    /* ---- update ------------------------------------------------------ */

    /**
     * A save replaces BOTH selections together.
     *
     * The failure this rules out is a campaign left holding new sources
     * against the previous options - a rule nobody configured, and one that
     * looks correct on the list page because both counts are plausible.
     */
    public function test_an_edit_replaces_both_selections_together()
    {
        $this->admin();

        $created = $this->create();
        $products = $this->products(3);

        $response = $this->postJson('/api/admin/upsell/action/' . $created['id'], $this->payload([
            'name' => $created['name'],
            'source_products' => [
                ['product_id' => $products[1]->id],
                ['product_id' => $products[2]->id],
            ],
            'upgrade_options' => [
                ['product_id' => $products[0]->id, 'priority' => 1],
            ],
        ]));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $data = $response->json('data');

        $this->assertSame((int)$created['id'], (int)$data['id']);
        $this->assertSame(2, $data['sources_count']);
        $this->assertSame(1, $data['options_count']);

        $this->assertSame(
            [(int)$products[1]->id, (int)$products[2]->id],
            array_column($data['sources'], 'product_id')
        );

        $this->assertSame(
            [(int)$products[0]->id],
            array_column($data['options'], 'product_id')
        );
    }

    /** The same product twice in one step collapses to one row. */
    public function test_a_product_picked_twice_is_stored_once()
    {
        $this->admin();

        $products = $this->products(2);

        $data = $this->create([
            'source_products' => [
                ['product_id' => $products[0]->id],
                ['product_id' => $products[0]->id],
            ],
            'upgrade_options' => [['product_id' => $products[1]->id]],
        ]);

        $this->assertSame(1, $data['sources_count']);
    }


    /* ---- activation -------------------------------------------------- */

    public function test_a_complete_campaign_activates()
    {
        $this->admin();

        $created = $this->create();

        $response = $this->postJson('/api/admin/upsell/toggle', [
            'ids' => [$created['id']],
            'status' => 1,
        ]);

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame([(int)$created['id']], $response->json('data.changed'));
        $this->assertSame([], $response->json('data.blocked'));

        $this->assertTrue(UpsellCampaign::find($created['id'])->isLive());
    }

    /**
     * An expired campaign cannot be switched on, and the refusal says why.
     *
     * Silently flipping the switch back is the behaviour that sends an admin
     * to support: the campaign reads "Active" and shows nothing.
     */
    public function test_an_expired_campaign_refuses_to_activate_and_says_why()
    {
        $this->admin();

        $created = $this->create();

        UpsellCampaign::where('id', $created['id'])->update([
            'start_date' => '2020-01-01 00:00:00',
            'end_date' => '2020-02-01 00:00:00',
        ]);

        $response = $this->postJson('/api/admin/upsell/toggle', [
            'ids' => [$created['id']],
            'status' => 1,
        ]);

        $this->assertSame([], $response->json('data.changed'));

        $blocked = $response->json('data.blocked');

        $this->assertCount(1, $blocked);
        $this->assertSame((int)$created['id'], (int)$blocked[0]['id']);
        $this->assertNotEmpty($blocked[0]['reasons']);

        // Still off.
        $this->assertSame(2, (int)UpsellCampaign::find($created['id'])->status);
    }

    /** Switching OFF is never refused, whatever state the campaign is in. */
    public function test_an_expired_campaign_can_always_be_switched_off()
    {
        $this->admin();

        $created = $this->create();

        UpsellCampaign::where('id', $created['id'])->update([
            'status' => 1,
            'end_date' => '2020-02-01 00:00:00',
        ]);

        $response = $this->postJson('/api/admin/upsell/toggle', [
            'ids' => [$created['id']],
            'status' => 2,
        ]);

        $this->assertSame([(int)$created['id']], $response->json('data.changed'));
        $this->assertSame(2, (int)UpsellCampaign::find($created['id'])->status);
    }

    /** A bulk activation reports what went through AND what did not. */
    public function test_a_bulk_activation_is_partial_rather_than_all_or_nothing()
    {
        $this->admin();

        $good = $this->create();
        $bad = $this->create();

        UpsellCampaign::where('id', $bad['id'])->update(['end_date' => '2020-02-01 00:00:00']);

        $response = $this->postJson('/api/admin/upsell/toggle', [
            'ids' => [$good['id'], $bad['id']],
            'status' => 1,
        ]);

        $this->assertSame([(int)$good['id']], $response->json('data.changed'));
        $this->assertCount(1, $response->json('data.blocked'));
    }


    /* ---- duplicate / delete ------------------------------------------ */

    public function test_a_duplicate_copies_both_selections_and_starts_switched_off()
    {
        $this->admin();

        $original = $this->create();

        UpsellCampaign::where('id', $original['id'])->update(['status' => 1]);

        $response = $this->postJson('/api/admin/upsell/duplicate/' . $original['id'], [
            'name' => 'QA Copy ' . uniqid(),
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $copy = $response->json('data');

        $this->assertNotSame((int)$original['id'], (int)$copy['id']);
        $this->assertSame($original['sources_count'], $copy['sources_count']);
        $this->assertSame($original['options_count'], $copy['options_count']);

        // Off, so it never competes with its original before anyone edits it.
        $this->assertSame(2, (int)$copy['status']);
    }

    public function test_deleting_a_campaign_takes_its_sources_and_options_with_it()
    {
        $this->admin();

        $created = $this->create();

        $this->deleteJson('/api/admin/upsell/delete/' . $created['id'])
            ->assertStatus(200);

        $this->assertNull(UpsellCampaign::find($created['id']));
        $this->assertSame(0, UpsellSource::where('upsell_campaign_id', $created['id'])->count());
        $this->assertSame(0, UpsellOption::where('upsell_campaign_id', $created['id'])->count());
    }

    public function test_several_campaigns_delete_in_one_request()
    {
        $this->admin();

        $a = $this->create();
        $b = $this->create();

        $this->deleteJson('/api/admin/upsell/delete/' . $a['id'] . ',' . $b['id'])
            ->assertStatus(200);

        $this->assertNull(UpsellCampaign::find($a['id']));
        $this->assertNull(UpsellCampaign::find($b['id']));
    }


    /* ---- list, search and filters ------------------------------------ */

    public function test_the_list_reports_counts_rather_than_whole_product_lists()
    {
        $this->admin();

        $created = $this->create();

        $row = collect($this->getJson('/api/admin/upsell/all?q=' . urlencode($created['name']))
            ->json('data.data'))
            ->firstWhere('id', $created['id']);

        $this->assertNotNull($row, 'the campaign is missing from its own search');
        $this->assertSame(1, (int)$row['sources_count']);
        $this->assertSame(2, (int)$row['options_count']);
        $this->assertArrayHasKey('schedule_state', $row);
        $this->assertArrayHasKey('is_live', $row);
    }

    /** Search reaches the products, not only the campaign name. */
    public function test_search_finds_a_campaign_by_its_product_name()
    {
        $this->admin();

        $products = $this->products(2);

        $created = $this->create([
            'source_products' => [['product_id' => $products[0]->id]],
            'upgrade_options' => [['product_id' => $products[1]->id]],
        ]);

        $ids = collect($this->getJson('/api/admin/upsell/all?q=' . urlencode($products[0]->title))
            ->json('data.data'))
            ->pluck('id')
            ->map('intval')
            ->all();

        $this->assertContains((int)$created['id'], $ids);
    }

    public function test_the_status_filter_narrows_the_list()
    {
        $this->admin();

        $created = $this->create();

        $ids = collect($this->getJson('/api/admin/upsell/all?filter_status=1')->json('data.data'))
            ->pluck('id')
            ->map('intval')
            ->all();

        $this->assertNotContains((int)$created['id'], $ids);
    }


    /**
     * The date filter asks about the DATES ALONE.
     *
     * A switched-on campaign that ended last week has to answer "Expired"
     * here, because that is the question an admin is asking when a live-looking
     * campaign is showing nothing.
     */
    public function test_the_date_filter_ignores_the_on_off_switch()
    {
        $this->admin();

        $created = $this->create();

        UpsellCampaign::where('id', $created['id'])->update([
            'status' => 1,
            'end_date' => '2020-02-01 00:00:00',
        ]);

        $ids = collect($this->getJson('/api/admin/upsell/all?filter_schedule=expired')->json('data.data'))
            ->pluck('id')
            ->map('intval')
            ->all();

        $this->assertContains((int)$created['id'], $ids);
    }

    public function test_the_display_location_filter_narrows_the_list()
    {
        $this->admin();

        $created = $this->create([
            'show_on_product_page' => true,
            'show_on_cart_page' => false,
            'show_on_checkout_page' => false,
        ]);

        $cartIds = collect($this->getJson('/api/admin/upsell/all?filter_location=cart')->json('data.data'))
            ->pluck('id')->map('intval')->all();

        $productIds = collect($this->getJson('/api/admin/upsell/all?filter_location=product')->json('data.data'))
            ->pluck('id')->map('intval')->all();

        $this->assertNotContains((int)$created['id'], $cartIds);
        $this->assertContains((int)$created['id'], $productIds);
    }

    /** orderby goes straight into an ORDER BY, so anything unknown falls back. */
    public function test_an_unknown_sort_column_does_not_reach_the_database()
    {
        $this->admin();

        $this->getJson('/api/admin/upsell/all?orderby=' . urlencode('name; DROP TABLE upsell_campaigns'))
            ->assertStatus(200);

        $this->assertTrue(\Schema::hasTable('upsell_campaigns'));
    }


    /* ---- product picker ---------------------------------------------- */

    public function test_the_picker_returns_the_columns_the_step_renders()
    {
        $this->admin();

        $row = $this->getJson('/api/admin/upsell/products')->json('data.data.0');

        $this->assertNotNull($row, 'the picker returned nothing to pick');

        foreach (['id', 'title', 'image', 'sku', 'category', 'selling', 'status', 'in_stock', 'variants'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    /** The picker offers only products that could actually be shown. */
    public function test_the_picker_only_offers_published_products()
    {
        $this->admin();

        $statuses = collect($this->getJson('/api/admin/upsell/products')->json('data.data'))
            ->pluck('status')
            ->map('intval')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([1], $statuses);
    }

    public function test_the_picker_searches_by_sku()
    {
        $this->admin();

        /*
         * A DISTINCTIVE SKU. Much of this catalogue was imported with the SKU
         * "1", and searching for that matches thousands of rows -- the product
         * under test then sits on page nine of the picker and the assertion
         * fails against a search that is working perfectly well.
         *
         * UpdatedInventory has no product() relation, so the join to the
         * published products is written out.
         */
        $inventory = UpdatedInventory::whereNotNull('sku')
            ->whereRaw('CHAR_LENGTH(sku) >= 5')
            ->whereIn('product_id', Product::where('status', 1)->pluck('id'))
            ->first();

        if (!$inventory) {
            $this->markTestSkipped('No distinctive SKU on a published product to search for.');
        }

        $ids = collect($this->getJson('/api/admin/upsell/products?q=' . urlencode($inventory->sku))
            ->json('data.data'))
            ->pluck('id')
            ->map('intval')
            ->all();

        $this->assertContains((int)$inventory->product_id, $ids);
    }

    /**
     * Variants ride along with each row.
     *
     * Without them the picker cannot express "iPhone 15 Pro 128GB" at all, and
     * the module would need a second variant system of its own.
     */
    public function test_the_picker_carries_each_products_variants()
    {
        $this->admin();

        $product = $this->variantProduct();

        $row = collect($this->getJson('/api/admin/upsell/products?q=' . urlencode($product->title))
            ->json('data.data'))
            ->firstWhere('id', (int)$product->id);

        $this->assertNotNull($row, 'the multi-variant product is not in its own search');
        $this->assertGreaterThanOrEqual(2, count($row['variants']));
        $this->assertArrayHasKey('label', $row['variants'][0]);
        $this->assertArrayHasKey('in_stock', $row['variants'][0]);
    }


    /* ---- read one ---------------------------------------------------- */

    public function test_the_detail_screen_gets_products_not_ids()
    {
        $this->admin();

        $created = $this->create();

        $data = $this->getJson('/api/admin/upsell/find/' . $created['id'])->json('data');

        $this->assertArrayHasKey('title', $data['sources'][0]);
        $this->assertArrayHasKey('in_stock', $data['sources'][0]);
        $this->assertArrayHasKey('blockers', $data);
    }

    public function test_a_missing_campaign_is_a_refusal_rather_than_an_exception()
    {
        $this->admin();

        $response = $this->getJson('/api/admin/upsell/find/99999999');

        $response->assertStatus(200);
        $this->assertNotSame(200, $this->bodyStatus($response));
    }
}
