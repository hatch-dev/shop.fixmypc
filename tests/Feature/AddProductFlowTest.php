<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TaxRules;
use App\Models\UpdatedInventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Adding a product through the admin panel, end to end.
 *
 * Each case here is something the flow got wrong rather than a feature being
 * re-confirmed, so the names read as the symptom the admin saw.
 */
class AddProductFlowTest extends TestCase
{
    use DatabaseTransactions;

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

    /** The payload the admin form posts, with only the fields under test varied. */
    private function payload(array $over = [])
    {
        $rule = TaxRules::first();

        return array_merge([
            'title' => 'QA Flow ' . uniqid(),
            'short_heading' => 'Short heading',
            'slug' => 'qa-flow-' . uniqid(),
            'unit' => 'pcs',
            'meta_title' => 'Meta title',
            'meta_description' => 'Meta description',
            'meta_keywords' => 'one,two',
            'description' => 'Description',
            'overview' => 'Overview',
            'selling' => 100,
            'purchased' => 50,
            'offered' => '',
            'excludeVAT' => 0,
            'status' => 2,
            'tax_rule_id' => $rule ? $rule->id : 1,
            'shipping_rule_id' => 1,
        ], $over);
    }

    private function save(array $payload, $id = null)
    {
        $url = '/api/admin/product/action' . ($id ? '/' . $id : '');

        return json_decode($this->postJson($url, $payload)->getContent(), true)['data'] ?? [];
    }

    private function saveInventory($productId, array $rows)
    {
        $response = $this->postJson(
            '/api/admin/updated-inventory/action/' . $productId,
            ['inventories' => $rows]
        );

        return json_decode($response->getContent(), true)['data'] ?? [];
    }

    /**
     * vatInclusiveOfferedPrice is null for a product with no offer, and the
     * column was NOT NULL under STRICT_TRANS_TABLES. Every product that was
     * not on offer failed to save, with "Something went wrong" as the reason.
     */
    public function test_a_product_with_no_offer_price_can_be_created()
    {
        $this->admin();

        $data = $this->save($this->payload());

        $this->assertArrayNotHasKey('form', $data, 'save was refused');
        $this->assertNotEmpty($data['id'] ?? null);
        $this->assertNull(Product::find($data['id'])->vatInclusiveOfferedPrice);
    }

    /** The offer path kept working, and still carries VAT. */
    public function test_a_product_with_an_offer_price_still_records_its_vat_inclusive_offer()
    {
        $this->admin();

        $data = $this->save($this->payload(['offered' => 80]));

        $this->assertNotEmpty($data['id'] ?? null);
        $this->assertGreaterThan(0, (float)Product::find($data['id'])->vatInclusiveOfferedPrice);
    }

    /**
     * Choosing the featured image before the first save used to try to create
     * the product from the upload form's three fields, all of them empty, and
     * fail on a NOT NULL column.
     */
    public function test_featured_image_can_be_chosen_before_the_product_is_saved()
    {
        $this->admin();

        $before = Product::count();

        $response = $this->post('/api/admin/product/upload', [
            'id' => '',
            'tax_rule_id' => '',
            'shipping_rule_id' => '',
            'photo' => UploadedFile::fake()->image('featured.jpg', 600, 600),
        ], ['Accept' => 'application/json']);

        $data = json_decode($response->getContent(), true)['data'] ?? [];

        $this->assertArrayNotHasKey('form', $data, 'upload was refused');
        $this->assertNotEmpty($data['image'] ?? null);

        // and no half-built product was left behind
        $this->assertSame($before, Product::count());

        // the filename the form now holds survives the save
        $saved = $this->save($this->payload(['image' => $data['image']]));
        $this->assertSame($data['image'], $saved['image'] ?? null);

        @unlink(base_path('uploads/' . $data['image']));
    }

    /** With no image chosen, a new product still falls back to the placeholder. */
    public function test_a_product_saved_without_an_image_gets_the_placeholder()
    {
        $this->admin();

        $data = $this->save($this->payload());

        $this->assertSame(
            config('constants.media.DEFAULT_IMAGE'),
            $data['image'] ?? null
        );
    }

    /**
     * The edit path handed every scalar in the request to a query-builder
     * update. CheckForAllScopes merges a fresh `token` into the request when
     * the admin's access token is close to expiring, and the update then died
     * on "Unknown column 'token'", losing the edit.
     */
    public function test_an_edit_survives_a_request_carrying_fields_that_are_not_columns()
    {
        $this->admin();

        $created = $this->save($this->payload());
        $this->assertNotEmpty($created['id'] ?? null);

        $edited = $this->save($this->payload([
            'title' => 'Renamed by QA',
            'slug' => $created['slug'],
            'token' => 'a-token-that-is-not-a-column',
            'some_other_stray_field' => 'x',
        ]), $created['id']);

        $this->assertArrayNotHasKey('form', $edited, 'edit was refused');
        $this->assertSame('Renamed by QA', Product::find($created['id'])->title);
    }

    /** The same update is no longer a way to write columns the server owns. */
    public function test_an_edit_cannot_set_the_rating_review_count_or_owner()
    {
        $admin = $this->admin();

        $created = $this->save($this->payload());
        $this->assertNotEmpty($created['id'] ?? null);

        $this->save($this->payload([
            'slug' => $created['slug'],
            'rating' => 5,
            'review_count' => 4321,
            'admin_id' => 999999,
        ]), $created['id']);

        $product = Product::find($created['id']);

        $this->assertSame(0, (int)$product->rating);
        $this->assertSame(0, (int)$product->review_count);
        $this->assertSame((int)$admin->id, (int)$product->admin_id);
    }

    /**
     * Categories are deleted before they are reinserted. Without a
     * transaction, a failure between the two published a product belonging to
     * no category at all.
     */
    public function test_a_failed_save_leaves_the_previous_categories_in_place()
    {
        $this->admin();

        $created = $this->save($this->payload([
            'product_categories' => [Category::value('id')],
        ]));
        $this->assertNotEmpty($created['id'] ?? null);

        $before = ProductCategory::where('product_id', $created['id'])->count();
        $this->assertGreaterThan(0, $before);

        // A title past the column width fails the row write, after the
        // categories have already been deleted and reinserted.
        $refused = $this->save($this->payload([
            'slug' => $created['slug'],
            'title' => str_repeat('x', 300),
            'product_categories' => [],
        ]), $created['id']);

        $this->assertArrayHasKey('form', $refused, 'expected the save to fail');
        $this->assertSame(
            $before,
            ProductCategory::where('product_id', $created['id'])->count(),
            'categories were lost by a save that failed'
        );
    }

    /** Negative stock and negative prices are refused rather than stored. */
    public function test_inventory_refuses_negative_quantity_and_price()
    {
        $this->admin();

        $created = $this->save($this->payload());
        $this->assertNotEmpty($created['id'] ?? null);

        $body = $this->saveInventory($created['id'], [[
            'id' => '', 'sku' => 'QA-NEG-1', 'price' => -50, 'quantity' => -99, 'is_active' => 0,
        ]]);

        $this->assertArrayHasKey('inventory', $body, 'negative stock was accepted');
        $this->assertSame(0, UpdatedInventory::where('product_id', $created['id'])->count());
    }

    /** A stock row with no SKU is refused, matching what the form demands. */
    public function test_inventory_refuses_a_row_with_no_sku()
    {
        $this->admin();

        $created = $this->save($this->payload());

        $body = $this->saveInventory($created['id'], [[
            'id' => '', 'sku' => '', 'price' => 10, 'quantity' => 5, 'is_active' => 0,
        ]]);

        $this->assertArrayHasKey('inventory', $body);
    }

    /** Two rows in one submission cannot share a SKU. */
    public function test_inventory_refuses_a_duplicate_sku_within_one_product()
    {
        $this->admin();

        $created = $this->save($this->payload());

        $body = $this->saveInventory($created['id'], [
            ['id' => '', 'sku' => 'QA-DUP', 'price' => 10, 'quantity' => 5, 'is_active' => 0],
            ['id' => '', 'sku' => 'QA-DUP', 'price' => 12, 'quantity' => 3, 'is_active' => 0],
        ]);

        $this->assertArrayHasKey('inventory', $body);
    }

    /** A well-formed stock row still saves. */
    public function test_valid_inventory_saves()
    {
        $this->admin();

        $created = $this->save($this->payload());

        $this->saveInventory($created['id'], [[
            'id' => '', 'sku' => 'QA-OK-' . uniqid(), 'price' => 99.5, 'quantity' => 7, 'is_active' => 0,
        ]]);

        $row = UpdatedInventory::where('product_id', $created['id'])->first();

        $this->assertNotNull($row);
        $this->assertSame(7, (int)$row->quantity);
        $this->assertSame('99.50', $row->price);
    }

    /**
     * The cross-sell picker on a product being created has no product id to
     * scope the option list with. It used to refuse to render at all, so a new
     * product could not be put into a cross-sell without saving it, navigating
     * back in and finding the tab again.
     */
    public function test_cross_sell_options_list_without_a_product_id()
    {
        $this->admin();

        if (!\App\Models\CrossSell::exists()) {
            $this->markTestSkipped('No cross-sell promotions to list.');
        }

        $body = json_decode(
            $this->getJson('/api/admin/cross-sell/options')->getContent(),
            true
        )['data'] ?? [];

        $this->assertArrayHasKey('data', $body, 'options were refused without a product id');
        $this->assertNotEmpty($body['data']);

        // Nothing can already be assigned to, or offer, a product that has no id.
        foreach ($body['data'] as $option) {
            $this->assertFalse($option['already_assigned']);
            $this->assertFalse($option['offers_this_product']);
        }
    }

    /** The picker's deferred ticks are written once the product exists. */
    public function test_cross_sells_can_be_assigned_to_a_freshly_created_product()
    {
        $this->admin();

        $crossSell = \App\Models\CrossSell::first();

        if (!$crossSell) {
            $this->markTestSkipped('No cross-sell promotion to assign.');
        }

        $created = $this->save($this->payload());
        $this->assertNotEmpty($created['id'] ?? null);

        $response = $this->postJson(
            '/api/admin/cross-sell/assign/' . $created['id'],
            ['cross_sell_id' => $crossSell->id]
        );

        $body = json_decode($response->getContent(), true)['data'] ?? [];

        $this->assertArrayNotHasKey('form', $body, 'assignment was refused');
        $this->assertTrue(
            \App\Models\ProductCrossSell::where('product_id', $created['id'])
                ->where('cross_sell_id', $crossSell->id)
                ->exists(),
            'the assignment was not written'
        );
    }
}
