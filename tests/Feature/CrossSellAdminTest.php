<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CrossSell;
use App\Models\CrossSellProduct;
use App\Models\Product;
use App\Models\ProductCrossSell;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Promotions -> Cross-sell, and the assignment section on a product, end to
 * end through the real admin routes.
 *
 * The cases here are the ones a promotion module gets wrong: a promotion
 * switched on with nothing to show, the same product listed twice, an edit
 * that keeps the old products against the new name, a promotion assigned to
 * a product it offers, the same promotion assigned twice, and an assignment
 * endpoint reachable by somebody who may not edit the product.
 *
 * As in UpsellCampaignAdminTest, every negative case reads the BODY. This
 * codebase answers a refusal with status 201 inside an HTTP 200, so asserting
 * on the HTTP code alone passes for every refusal there is.
 */
class CrossSellAdminTest extends TestCase
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

    /**
     * Published products no promotion offers and none is assigned to.
     *
     * Six promotions came across from the legacy groups, so a test that
     * grabbed the first published product would be asserting against whatever
     * those already say about it.
     */
    private function products($count = 4)
    {
        $products = Product::where('status', 1)
            ->whereDoesntHave('crossSellAssignments')
            ->whereNotIn('id', CrossSellProduct::pluck('product_id'))
            ->limit($count)
            ->get();

        if ($products->count() < $count) {
            $this->markTestSkipped('Not enough unconfigured published products.');
        }

        return $products;
    }

    private function payload(array $productIds, $overrides = [])
    {
        return array_merge([
            'name' => 'QA Laptop Accessories ' . uniqid(),
            'description' => 'Accessories commonly purchased with laptops.',
            'status' => CrossSell::STATUS_ACTIVE,
            'max_products' => 4,
            'products' => $productIds,
        ], $overrides);
    }

    /** The body's own status, which is where a refusal actually lives. */
    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }

    private function create(array $productIds, $overrides = [])
    {
        $response = $this->postJson('/api/admin/cross-sell/action', $this->payload($productIds, $overrides));

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        return CrossSell::find($response->json('data.id'));
    }

    private function memberIds(CrossSell $crossSell)
    {
        return CrossSellProduct::where('cross_sell_id', $crossSell->id)
            ->ordered()
            ->pluck('product_id')
            ->map('intval')
            ->all();
    }

    private function assign($productId, $crossSellId)
    {
        return $this->postJson("/api/admin/cross-sell/assign/{$productId}", ['cross_sell_id' => $crossSellId]);
    }


    /* ---- permissions ------------------------------------------------- */

    /**
     * Unauthenticated, spelled the way this application spells it: HTTP 201
     * with status 204 in the body, not 401. Both halves are checked -- the
     * refusal code AND the absence of any data.
     */
    public function test_the_list_is_closed_to_anyone_without_an_admin_session()
    {
        $this->asGuest();

        $response = $this->getJson('/api/admin/cross-sell/all');

        $this->assertSame(204, (int)$response->json('status'));
        $this->assertNull($response->json('data'));
    }

    public function test_creating_is_closed_and_inert_without_an_admin_session()
    {
        $this->asGuest();

        $products = $this->products(1);

        $response = $this->postJson('/api/admin/cross-sell/action', $this->payload([$products[0]->id]));

        $this->assertSame(204, (int)$response->json('status'));
        $this->assertSame(0, CrossSell::where('name', 'LIKE', 'QA Laptop Accessories %')->count());
    }

    public function test_assigning_is_closed_without_an_admin_session()
    {
        $this->admin();

        $products = $this->products(2);
        $crossSell = $this->create([$products[1]->id]);

        $this->asGuest();

        $response = $this->assign($products[0]->id, $crossSell->id);

        $this->assertSame(204, (int)$response->json('status'));
        $this->assertSame(0, ProductCrossSell::where('product_id', $products[0]->id)->count());
    }


    /* ---- promotions -------------------------------------------------- */

    public function test_an_admin_can_create_a_promotion_with_products_in_order()
    {
        $this->admin();

        $products = $this->products(3);

        $crossSell = $this->create([$products[2]->id, $products[0]->id, $products[1]->id]);

        $this->assertSame(
            [(int)$products[2]->id, (int)$products[0]->id, (int)$products[1]->id],
            $this->memberIds($crossSell),
            'the products were not stored in the order they were picked'
        );

        $this->assertSame(CrossSell::STATUS_ACTIVE, (int)$crossSell->status);
        $this->assertSame(4, (int)$crossSell->max_products);
    }

    /** An empty promotion can be SAVED -- as a draft -- but not switched on. */
    public function test_an_empty_promotion_can_be_saved_as_a_draft()
    {
        $this->admin();

        $crossSell = $this->create([], ['status' => CrossSell::STATUS_DRAFT]);

        $this->assertSame(CrossSell::STATUS_DRAFT, (int)$crossSell->status);
        $this->assertSame([], $this->memberIds($crossSell));
    }

    public function test_an_empty_promotion_cannot_be_saved_as_active()
    {
        $this->admin();

        $response = $this->postJson('/api/admin/cross-sell/action', $this->payload([], ['status' => CrossSell::STATUS_ACTIVE]));

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertSame(0, CrossSell::where('name', 'LIKE', 'QA Laptop Accessories %')->count());
    }

    /** Rule 12: the same product twice inside one promotion. */
    public function test_a_product_listed_twice_is_stored_once()
    {
        $this->admin();

        $products = $this->products(2);

        $crossSell = $this->create([$products[0]->id, $products[1]->id, $products[0]->id]);

        $this->assertSame([(int)$products[0]->id, (int)$products[1]->id], $this->memberIds($crossSell));
    }

    /** Rule 12 at the level that actually holds against two admins saving at once. */
    public function test_the_database_refuses_a_duplicate_member()
    {
        $this->admin();

        $products = $this->products(1);
        $crossSell = $this->create([$products[0]->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('cross_sell_products')->insert([
            'cross_sell_id' => $crossSell->id, 'product_id' => $products[0]->id, 'sort_order' => 9,
        ]);
    }

    public function test_an_unpublished_product_cannot_be_offered()
    {
        $this->admin();

        $products = $this->products(1);
        $unpublished = Product::where('status', '<>', 1)->first();

        if (!$unpublished) {
            $this->markTestSkipped('No unpublished product to refuse.');
        }

        $response = $this->postJson('/api/admin/cross-sell/action', $this->payload([$products[0]->id, $unpublished->id]));

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertStringContainsString($unpublished->title, (string)$response->json('message'));
    }

    /** The edit replaces the products wholesale, never merges. */
    public function test_editing_replaces_the_product_list()
    {
        $this->admin();

        $products = $this->products(3);
        $crossSell = $this->create([$products[0]->id, $products[1]->id]);

        $response = $this->postJson(
            "/api/admin/cross-sell/action/{$crossSell->id}",
            $this->payload([$products[2]->id], ['name' => 'QA Renamed ' . uniqid()])
        );

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame([(int)$products[2]->id], $this->memberIds($crossSell));
        $this->assertStringStartsWith('QA Renamed', $crossSell->fresh()->name);
    }

    /**
     * Out of range is REFUSED on the way in rather than quietly clamped: an
     * admin who typed 99 should be told, not find 12. The clamp lives on the
     * read side, for a value that reached the database some other way.
     */
    public function test_the_display_maximum_is_bounded()
    {
        $this->admin();

        $products = $this->products(1);

        foreach ([99, 0] as $bad) {
            $response = $this->postJson('/api/admin/cross-sell/action', $this->payload([$products[0]->id], ['max_products' => $bad]));

            $this->assertSame(201, $this->bodyStatus($response), "max_products of {$bad} was accepted");
        }

        $this->assertSame(12, (int)$this->create([$products[0]->id], ['max_products' => 12])->max_products);

        $forced = $this->create([$products[0]->id]);
        DB::table('cross_sells')->where('id', $forced->id)->update(['max_products' => 99]);

        $this->assertSame(12, $forced->fresh()->displayLimit(), 'a hand-edited value rendered more than the UI can carry');
    }

    public function test_switching_on_an_empty_promotion_is_refused_by_name()
    {
        $this->admin();

        $crossSell = $this->create([], ['status' => CrossSell::STATUS_DRAFT]);

        $response = $this->postJson('/api/admin/cross-sell/toggle', ['ids' => [$crossSell->id], 'status' => 1]);

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame([], $response->json('data.changed'));
        $this->assertSame($crossSell->id, (int)$response->json('data.blocked.0.id'));
        $this->assertSame(CrossSell::STATUS_DRAFT, (int)$crossSell->fresh()->status);
    }

    public function test_switching_off_is_never_refused_and_the_promotion_survives()
    {
        $this->admin();

        $products = $this->products(1);
        $crossSell = $this->create([$products[0]->id]);

        $this->postJson('/api/admin/cross-sell/toggle', ['ids' => [$crossSell->id], 'status' => 2]);

        $this->assertSame(CrossSell::STATUS_INACTIVE, (int)$crossSell->fresh()->status);
        $this->assertSame(1, CrossSellProduct::where('cross_sell_id', $crossSell->id)->count());
    }

    /** Deleting the promotion takes its rows with it and leaves the products. */
    public function test_deleting_a_promotion_leaves_its_products_in_the_catalogue()
    {
        $this->admin();

        $products = $this->products(2);
        $crossSell = $this->create([$products[1]->id]);
        $this->assign($products[0]->id, $crossSell->id);

        $response = $this->deleteJson("/api/admin/cross-sell/delete/{$crossSell->id}");

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertNull(CrossSell::find($crossSell->id));
        $this->assertSame(0, CrossSellProduct::where('cross_sell_id', $crossSell->id)->count());
        $this->assertSame(0, ProductCrossSell::where('cross_sell_id', $crossSell->id)->count());
        $this->assertNotNull(Product::find($products[1]->id));
    }

    public function test_the_list_finds_a_promotion_by_a_product_it_offers()
    {
        $this->admin();

        $products = $this->products(1);
        $crossSell = $this->create([$products[0]->id]);

        $ids = collect($this->getJson('/api/admin/cross-sell/all?q=' . urlencode($products[0]->title))->json('data.data'))
            ->pluck('id')->map('intval')->all();

        $this->assertContains($crossSell->id, $ids);
    }

    public function test_the_list_filters_by_status()
    {
        $this->admin();

        $draft = $this->create([], ['status' => CrossSell::STATUS_DRAFT]);

        $ids = collect($this->getJson('/api/admin/cross-sell/all?filter_status=1')->json('data.data'))
            ->pluck('id')->map('intval')->all();

        $this->assertNotContains($draft->id, $ids);
    }


    /* ---- assignments ------------------------------------------------- */

    public function test_an_admin_can_assign_an_existing_promotion_to_a_product()
    {
        $this->admin();

        $products = $this->products(2);
        $crossSell = $this->create([$products[1]->id]);

        $response = $this->assign($products[0]->id, $crossSell->id);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertSame($crossSell->id, (int)$response->json('data.rows.0.cross_sell_id'));
        $this->assertTrue($response->json('data.rows.0.live'), 'a live assignment of a live promotion reads as not showing');
    }

    /** Rule 12: the same promotion twice under one product, refused by name. */
    public function test_the_same_promotion_cannot_be_assigned_twice()
    {
        $this->admin();

        $products = $this->products(2);
        $crossSell = $this->create([$products[1]->id]);

        $this->assign($products[0]->id, $crossSell->id);
        $response = $this->assign($products[0]->id, $crossSell->id);

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertStringContainsString($crossSell->name, (string)$response->json('message'));
        $this->assertSame(1, ProductCrossSell::where('product_id', $products[0]->id)->count());
    }

    /** Rule 12: a promotion that offers the product itself cannot sit under it. */
    public function test_a_promotion_offering_the_product_cannot_be_assigned_to_it()
    {
        $this->admin();

        $products = $this->products(2);
        $crossSell = $this->create([$products[0]->id, $products[1]->id]);

        $response = $this->assign($products[0]->id, $crossSell->id);

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertSame(0, ProductCrossSell::where('product_id', $products[0]->id)->count());
    }

    public function test_a_product_can_carry_several_promotions_in_order()
    {
        $this->admin();

        $products = $this->products(3);
        $first = $this->create([$products[1]->id]);
        $second = $this->create([$products[2]->id]);

        $this->assign($products[0]->id, $first->id);
        $this->assign($products[0]->id, $second->id);

        $rows = ProductCrossSell::where('product_id', $products[0]->id)->ordered()->get();

        $this->assertSame([$first->id, $second->id], $rows->pluck('cross_sell_id')->map('intval')->all());
        $this->assertSame([1, 2], $rows->pluck('sort_order')->map('intval')->all());

        // Reversed, the way a drag would send it.
        $response = $this->postJson("/api/admin/cross-sell/assignments/{$products[0]->id}", [
            'order' => $rows->pluck('id')->reverse()->values()->all(),
        ]);

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame(
            [$second->id, $first->id],
            ProductCrossSell::where('product_id', $products[0]->id)->ordered()->pluck('cross_sell_id')->map('intval')->all()
        );
    }

    public function test_an_assignment_can_be_switched_off_without_touching_the_promotion()
    {
        $this->admin();

        $products = $this->products(2);
        $crossSell = $this->create([$products[1]->id]);
        $this->assign($products[0]->id, $crossSell->id);

        $row = ProductCrossSell::where('product_id', $products[0]->id)->first();

        $response = $this->postJson("/api/admin/cross-sell/assignments/{$products[0]->id}", ['id' => $row->id, 'status' => 2]);

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame(2, (int)$row->fresh()->status);
        $this->assertFalse($response->json('data.rows.0.live'));
        $this->assertSame(CrossSell::STATUS_ACTIVE, (int)$crossSell->fresh()->status, 'switching an assignment off switched the promotion off');
    }

    public function test_an_assignment_cannot_be_reached_through_another_product()
    {
        $this->admin();

        $products = $this->products(3);
        $crossSell = $this->create([$products[2]->id]);
        $this->assign($products[1]->id, $crossSell->id);

        $foreign = ProductCrossSell::where('product_id', $products[1]->id)->first();

        $this->postJson("/api/admin/cross-sell/assignments/{$products[0]->id}", ['id' => $foreign->id, 'status' => 2]);
        $this->deleteJson("/api/admin/cross-sell/unassign/{$products[0]->id}/{$foreign->id}");

        $this->assertSame(1, (int)$foreign->fresh()->status);
    }

    public function test_unassigning_removes_only_the_assignment()
    {
        $this->admin();

        $products = $this->products(2);
        $crossSell = $this->create([$products[1]->id]);
        $this->assign($products[0]->id, $crossSell->id);

        $row = ProductCrossSell::where('product_id', $products[0]->id)->first();

        $response = $this->deleteJson("/api/admin/cross-sell/unassign/{$products[0]->id}/{$row->id}");

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertNull(ProductCrossSell::find($row->id));
        $this->assertNotNull(CrossSell::find($crossSell->id), 'unassigning deleted the promotion');
        $this->assertSame(1, CrossSellProduct::where('cross_sell_id', $crossSell->id)->count());
    }

    /** The picker says which promotions cannot be picked, and why. */
    public function test_the_assign_options_mark_what_cannot_be_picked()
    {
        $this->admin();

        $products = $this->products(3);
        $assigned = $this->create([$products[1]->id]);
        $offering = $this->create([$products[0]->id]);
        $this->assign($products[0]->id, $assigned->id);

        $rows = collect($this->getJson("/api/admin/cross-sell/options?product_id={$products[0]->id}&q=QA")->json('data.data'))->keyBy('id');

        $this->assertTrue($rows[$assigned->id]['already_assigned']);
        $this->assertTrue($rows[$offering->id]['offers_this_product']);
        $this->assertFalse($rows[$offering->id]['already_assigned']);
    }

    /**
     * The backfill never produces a live promotion with nothing to show.
     *
     * Two legacy groups pointed only at products that have since been
     * deleted. Carried over as Active they would be live promotions that can
     * never show anything -- the exact state toggle() refuses to let an admin
     * reach by hand, so the migration must not create it either.
     */
    public function test_no_migrated_promotion_is_active_without_products()
    {
        $this->admin();

        $offenders = CrossSell::whereNotNull('legacy_upsell_id')
            ->where('status', CrossSell::STATUS_ACTIVE)
            ->whereDoesntHave('products')
            ->pluck('name')
            ->all();

        $this->assertSame([], $offenders, 'a migrated promotion is switched on with nothing to offer');
    }

    /** The migration's own bookkeeping stays out of what an admin reads. */
    public function test_no_migrated_promotion_leaks_the_legacy_marker()
    {
        $this->admin();

        $leaking = CrossSell::where('description', 'LIKE', '%legacy-upsell-group%')->count();

        $this->assertSame(0, $leaking, 'the migration marker is showing in the description column');
    }

    /** Rule 11: a deleted product leaves the promotion, and the promotion stands. */
    public function test_deleting_a_product_removes_it_from_every_promotion()
    {
        $this->admin();

        $products = $this->products(1);

        $source = Product::where('status', 1)->first();
        $row = (array)DB::table('products')->where('id', $source->id)->first();
        unset($row['id']);
        $row['title'] = 'QA Disposable ' . uniqid();
        $row['slug'] = 'qa-disposable-' . uniqid();
        $victimId = DB::table('products')->insertGetId($row);

        $crossSell = $this->create([$products[0]->id, $victimId]);

        DB::table('products')->where('id', $victimId)->delete();

        $this->assertSame([(int)$products[0]->id], $this->memberIds($crossSell));
        $this->assertNotNull(CrossSell::find($crossSell->id));
    }
}
