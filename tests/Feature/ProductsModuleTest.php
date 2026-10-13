<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CollectionWithProduct;
use App\Models\InventoryAttribute;
use App\Models\OrderedProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFeatureImage;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\ProductSubcategory;
use App\Models\TaxRules;
use App\Models\UpdatedInventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The admin Products section, end to end.
 *
 * Covers the list, the editor, media, stock, the bulk editor and delete --
 * every endpoint under /api/admin/product plus the two that sit outside it
 * (duplicate, all-simple) and the inventory save the product form posts.
 *
 * Tests named test_defect_* record behaviour that is wrong today. They assert
 * the wrong behaviour on purpose so the suite stays green until each is fixed;
 * the docblock says what the correct behaviour would be.
 */
class ProductsModuleTest extends TestCase
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

    /** Run $callback with $permission taken away from the superadmin role. */
    private function without($permission, callable $callback)
    {
        $role = Role::where('name', 'superadmin')->where('guard_name', 'admin')->first();
        $perm = Permission::where('name', $permission)->where('guard_name', 'admin')->first();

        if (!$role || !$perm) {
            $this->markTestSkipped($permission . ' is not a registered permission.');
        }

        $role->revokePermissionTo($perm);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        try {
            $this->admin();
            return $callback();
        } finally {
            $role->givePermissionTo($perm);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        }
    }

    private function payload(array $over = [])
    {
        $rule = TaxRules::first();

        return array_merge([
            'title'            => 'QA Products ' . uniqid(),
            'short_heading'    => 'Short heading',
            'slug'             => 'qa-products-' . uniqid(),
            'unit'             => 'pcs',
            'meta_title'       => 'Meta title',
            'meta_description' => 'Meta description',
            'meta_keywords'    => 'one,two',
            'description'      => 'Description',
            'overview'         => 'Overview',
            'selling'          => 100,
            'purchased'        => 50,
            'offered'          => '',
            'excludeVAT'       => 0,
            'status'           => 2,
            'tax_rule_id'      => $rule ? $rule->id : 1,
            'shipping_rule_id' => 1,
        ], $over);
    }

    private function save(array $payload, $id = null)
    {
        $url = '/api/admin/product/action' . ($id ? '/' . $id : '');

        return json_decode($this->postJson($url, $payload)->getContent(), true)['data'] ?? [];
    }

    /** A saved product to work on, returned as a fresh model. */
    private function makeProduct(array $over = [])
    {
        $data = $this->save($this->payload($over));

        $this->assertNotEmpty($data['id'] ?? null, 'fixture product was refused: ' . json_encode($data));

        return Product::find($data['id']);
    }

    private function listProducts(array $query = [])
    {
        $response = $this->getJson('/api/admin/product/all?' . http_build_query($query));

        return json_decode($response->getContent(), true)['data'] ?? [];
    }

    private function shippingPlaceId()
    {
        return \App\Models\ShippingPlace::value('id');
    }

    private function ids($page)
    {
        return array_map('strval', collect($page['data'] ?? [])->pluck('id')->all());
    }

    /* ================================================================
     *  THE LIST  -  GET /api/admin/product/all
     * ================================================================ */

    public function test_the_list_is_paginated_and_carries_the_columns_the_grid_draws()
    {
        $this->admin();

        $page = $this->listProducts();

        $this->assertArrayHasKey('data', $page, 'the list was refused');
        $this->assertArrayHasKey('total', $page);
        $this->assertNotEmpty($page['data']);

        $row = $page['data'][0];

        foreach (['id', 'title', 'slug', 'status', 'selling', 'purchased', 'offered',
                  'views', 'created', 'brand', 'tax_rules', 'product_categories'] as $key) {
            $this->assertArrayHasKey($key, $row, $key . ' is missing from a product row');
        }
    }

    public function test_the_list_needs_product_view()
    {
        $body = $this->without('product.view', function () {
            return $this->getJson('/api/admin/product/all')->json();
        });

        $this->assertNotSame(200, (int)($body['status'] ?? 200),
            'the product list was served without product.view');
    }

    public function test_the_status_filter_returns_only_that_status()
    {
        $this->admin();

        $page = $this->listProducts(['status' => 1]);

        $this->assertNotEmpty($page['data']);

        foreach ($page['data'] as $row) {
            $this->assertSame(1, (int)$row['status']);
        }
    }

    public function test_the_search_matches_a_title_word_by_word()
    {
        $this->admin();

        $product = $this->makeProduct(['title' => 'Zephyr QA Widget ' . uniqid(), 'status' => 1]);

        $page = $this->listProducts(['q' => 'zephyr qa widget']);

        $this->assertContains((string)$product->id, $this->ids($page));
    }

    public function test_the_brand_filter_returns_only_that_brand()
    {
        $this->admin();

        $brand = Brand::first();

        if (!$brand) {
            $this->markTestSkipped('No brand to filter on.');
        }

        $page = $this->listProducts(['brand' => $brand->id]);

        foreach ($page['data'] as $row) {
            $this->assertSame((int)$brand->id, (int)$row['brand_id']);
        }
    }

    public function test_the_category_filter_returns_only_products_in_that_category()
    {
        $this->admin();

        $categoryId = ProductCategory::value('category_id');

        if (!$categoryId) {
            $this->markTestSkipped('No product is in a category.');
        }

        $page = $this->listProducts(['category_id' => $categoryId]);

        $this->assertNotEmpty($page['data']);

        foreach ($page['data'] as $row) {
            $this->assertTrue(
                ProductCategory::where('product_id', $row['id'])
                    ->where('category_id', $categoryId)->exists(),
                'product ' . $row['id'] . ' is not in category ' . $categoryId
            );
        }
    }

    public function test_the_in_stock_and_out_of_stock_filters_disagree_about_the_same_product()
    {
        $this->admin();

        $product = $this->makeProduct(['status' => 1]);

        UpdatedInventory::create([
            'product_id' => $product->id, 'sku' => 'QA-STK-' . uniqid(),
            'price' => 10, 'quantity' => 5, 'is_active' => 1,
        ]);

        $this->assertContains((string)$product->id, $this->ids($this->listProducts(['stock' => 'in'])));
        $this->assertNotContains((string)$product->id, $this->ids($this->listProducts(['stock' => 'out'])));

        UpdatedInventory::where('product_id', $product->id)->update(['quantity' => 0]);

        $this->assertContains((string)$product->id, $this->ids($this->listProducts(['stock' => 'out'])));
        $this->assertNotContains((string)$product->id, $this->ids($this->listProducts(['stock' => 'in'])));
    }

    /**
     * The Subcategory dropdown on the product list filters the list.
     *
     * The regression this pins: pages/products/index.vue rendered the select,
     * put subcategory_id in the query string and refetched -- and all() never
     * read the parameter, so the grid came back unchanged while looking
     * filtered.
     */
    public function test_the_subcategory_filter_returns_only_products_in_that_subcategory()
    {
        $this->admin();

        $child = Category::whereNotNull('parent')->first();

        if (!$child) {
            $this->markTestSkipped('No subcategory in the catalogue.');
        }

        $inIt = $this->makeProduct([
            'status' => 1,
            'product_categories' => [$child->parent],
            'subcategories' => [['id' => $child->id, 'category_id' => $child->parent]],
        ]);

        $notInIt = $this->makeProduct([
            'status' => 1,
            'product_categories' => [$child->parent],
        ]);

        $ids = $this->ids($this->listProducts(['subcategory_id' => $child->id]));

        $this->assertContains((string)$inIt->id, $ids);
        $this->assertNotContains((string)$notInIt->id, $ids,
            'the subcategory filter returned a product that is not in it');

        // An unknown subcategory matches nothing, rather than everything.
        $this->assertSame([], $this->ids($this->listProducts(['subcategory_id' => 999999999])));
    }




    /**
     * A slug is required, and a product without one is still listed.
     *
     * The regression this pins, from both ends. all() opened with
     * whereNotNull('slug') while productMain() never required one, so a
     * product created through any path that omitted it was live, orderable and
     * completely absent from the only screen that could have fixed it -- nine
     * rows in this database got in that way, seven of them published.
     *
     * The save now demands a slug, and the list no longer hides a row for
     * lacking one: something wrong with a product is a reason to show it.
     */
    public function test_a_save_without_a_slug_is_refused()
    {
        $this->admin();

        $before = Product::count();

        $body = $this->save($this->payload(['slug' => null]));

        $this->assertArrayHasKey('form', $body, 'a product was created with no slug');
        $this->assertSame($before, Product::count());
    }

    /**
     * And the refusal names the field, rather than claiming the slug is taken.
     *
     * where('slug', null) becomes `slug IS NULL` in Laravel, so with slugless
     * rows in the table every save that omitted the slug was answered with
     * "Slug already exists." -- a wrong diagnosis of a field the admin had not
     * filled in and the server had never said was required.
     */
    public function test_a_missing_slug_is_not_reported_as_a_duplicate()
    {
        $this->admin();

        $body = $this->save($this->payload(['slug' => null]));

        $this->assertNotContains('Slug already exists.', $body['form'] ?? [],
            'a missing slug is still reported as a duplicate');
    }

    /** A row that already has no slug is visible in the list, not hidden. */
    public function test_a_product_with_no_slug_is_still_listed()
    {
        $this->admin();

        $product = $this->makeProduct(['status' => 1]);

        Product::where('id', $product->id)->update(['slug' => null]);

        $this->assertContains((string)$product->id, $this->ids($this->listProducts()),
            'a slugless product is hidden from the admin list again');
    }


    /* ================================================================
     *  THE EDITOR  -  GET find, POST action
     * ================================================================ */

    public function test_find_returns_everything_the_editor_binds_to()
    {
        $this->admin();

        $category = Category::whereNull('parent')->first();

        $product = $this->makeProduct([
            'product_categories'   => $category ? [$category->id] : [],
            'primary_category_id'  => $category ? $category->id : null,
            'specifications'       => [
                ['group' => 'QA Group', 'items' => [['value' => 'QA Value']]],
            ],
        ]);

        $body = $this->getJson('/api/admin/product/find/' . $product->id)->json('data');

        $this->assertSame((string)$product->id, (string)$body['id']);

        foreach (['title', 'slug', 'selling', 'purchased', 'status', 'tax_rule_id',
                  'shipping_rule_id', 'product_categories', 'product_images',
                  'specifications', 'feature_images', 'product_collections',
                  'primary_category_id'] as $key) {
            $this->assertArrayHasKey($key, $body, $key . ' is missing from find');
        }

        if ($category) {
            $this->assertSame((int)$category->id, (int)$body['primary_category_id']);
        }

        $this->assertSame('QA Group', $body['specifications'][0]['group'] ?? null);
    }

    public function test_find_needs_product_view()
    {
        $this->admin();
        $product = $this->makeProduct();

        $body = $this->without('product.view', function () use ($product) {
            return $this->getJson('/api/admin/product/find/' . $product->id)->json();
        });

        $this->assertNotSame(200, (int)($body['status'] ?? 200));
    }

    public function test_dropdown_data_carries_every_list_the_editor_needs()
    {
        $this->admin();

        $body = $this->getJson('/api/admin/product/dropdown-data')->json('data');

        foreach (['brands', 'categories', 'tax_rules', 'shipping_rules',
                  'product_collections', 'bundle_deals', 'attributes'] as $key) {
            $this->assertArrayHasKey($key, $body, $key . ' is missing from dropdown-data');
        }
    }

    public function test_creating_needs_product_create_and_editing_needs_product_edit()
    {
        $this->admin();
        $product = $this->makeProduct();

        $created = $this->without('product.create', function () {
            return $this->save($this->payload());
        });
        $this->assertArrayHasKey('form', $created, 'a product was created without product.create');

        $edited = $this->without('product.edit', function () use ($product) {
            return $this->save($this->payload(['slug' => $product->slug, 'title' => 'Nope']), $product->id);
        });
        $this->assertArrayHasKey('form', $edited, 'a product was edited without product.edit');
        $this->assertNotSame('Nope', $product->fresh()->title);
    }

    public function test_the_required_fields_are_refused_when_missing()
    {
        $this->admin();

        // Not shipping_rule_id: products are no longer assigned a shipping
        // rule (2026-09-28, ShippingByAddressTest), so it is not required.
        foreach (['title', 'unit', 'meta_title', 'meta_description', 'description',
                  'overview', 'selling', 'purchased', 'tax_rule_id'] as $field) {

            $body = $this->save($this->payload([$field => null]));

            $this->assertArrayHasKey('form', $body, $field . ' was accepted as empty');
        }
    }

    public function test_an_offer_above_the_selling_price_is_refused()
    {
        $this->admin();

        $body = $this->save($this->payload(['selling' => 100, 'offered' => 150]));

        $this->assertArrayHasKey('form', $body, 'an offer above the selling price was accepted');
    }

    public function test_a_duplicate_slug_is_refused_on_create_and_on_edit()
    {
        $this->admin();

        $first  = $this->makeProduct();
        $second = $this->makeProduct();

        $created = $this->save($this->payload(['slug' => $first->slug]));
        $this->assertArrayHasKey('form', $created, 'a second product took an existing slug');

        $edited = $this->save($this->payload(['slug' => $first->slug]), $second->id);
        $this->assertArrayHasKey('form', $edited, 'an edit took another product\'s slug');
        $this->assertNotSame($first->slug, $second->fresh()->slug);
    }

    public function test_the_shipping_dimensions_and_purchase_note_are_saved()
    {
        $this->admin();

        $product = $this->makeProduct([
            'length' => '10.50', 'width' => '20.25', 'height' => '5.00',
            'weight' => '1.250', 'purchase_note' => 'Handle with care',
        ]);

        $this->assertSame('10.50', (string)$product->length);
        $this->assertSame('20.25', (string)$product->width);
        $this->assertSame('5.00', (string)$product->height);
        $this->assertSame('1.250', (string)$product->weight);
        $this->assertSame('Handle with care', $product->purchase_note);
    }

    public function test_collections_and_specifications_are_rewritten_on_every_save()
    {
        $this->admin();

        $collectionIds = \App\Models\ProductCollection::limit(2)->pluck('id')->all();

        if (count($collectionIds) < 2) {
            $this->markTestSkipped('Fewer than two product collections exist.');
        }

        $product = $this->makeProduct([
            'product_collections' => $collectionIds,
            'specifications' => [
                ['group' => 'Display', 'items' => [['value' => '13 inch'], ['value' => 'Retina']]],
            ],
        ]);

        $this->assertSame(2, CollectionWithProduct::where('product_id', $product->id)->count());
        $this->assertSame(2, ProductSpecification::where('product_id', $product->id)->count());

        $this->save($this->payload([
            'slug' => $product->slug,
            'product_collections' => [$collectionIds[0]],
            'specifications' => [
                ['group' => 'Display', 'items' => [['value' => '15 inch']]],
            ],
        ]), $product->id);

        $this->assertSame(1, CollectionWithProduct::where('product_id', $product->id)->count());
        $this->assertSame(1, ProductSpecification::where('product_id', $product->id)->count());
        $this->assertSame('15 inch', ProductSpecification::where('product_id', $product->id)->value('value'));
    }

    /**
     * The picker's options carry each root's subcategories.
     *
     * `product/dropdown-data` has always eager-loaded `child` on every root,
     * and the admin store dropped it while flattening the response to
     * {id: {title}} - so the product form could only ever offer top-level
     * categories and there was no way to put a product in a subcategory from
     * that screen, though the save and find() below both handle them. This
     * pins the half of that contract the server owns.
     */
    public function test_the_category_dropdown_offers_subcategories()
    {
        $this->admin();

        $child = Category::whereNotNull('parent')->first();

        if (!$child) {
            $this->markTestSkipped('No subcategory in the catalogue.');
        }

        $categories = $this->getJson('/api/admin/product/dropdown-data')->json('data.categories');

        $this->assertNotEmpty($categories);

        $parent = collect($categories)->firstWhere('id', $child->parent);

        $this->assertNotNull($parent, 'the subcategory\'s parent is not in the options');
        $this->assertArrayHasKey('child', $parent, 'roots arrive without their children');

        $this->assertContains((int)$child->id, collect($parent['child'])->pluck('id')
            ->map(fn($id) => (int)$id)->all(), 'the subcategory is missing from its parent');

        // And the roots themselves are roots: a subcategory must not also be
        // offered as a top-level option, or it could be picked twice.
        $this->assertNull(collect($categories)->firstWhere('id', $child->id),
            'a subcategory was also listed as a root category');
    }

    /**
     * The product save writes subcategories, and rewrites them on the next save.
     *
     * The regression this pins: find() read product_subcategories and hung them
     * under each category as `child`, and bulkUpdate() wrote them -- but
     * action(), the only save the product form uses, ignored the key entirely,
     * so subcategories chosen on the product page were silently dropped.
     */
    public function test_the_product_save_writes_subcategories()
    {
        $this->admin();

        $children = Category::whereNotNull('parent')->limit(2)->get();

        if ($children->isEmpty()) {
            $this->markTestSkipped('No subcategory in the catalogue.');
        }

        $first = $children[0];

        $product = $this->makeProduct([
            'product_categories' => [$first->parent],
            'subcategories' => [['id' => $first->id, 'category_id' => $first->parent]],
        ]);

        $rows = ProductSubcategory::where('product_id', $product->id)->get();

        $this->assertCount(1, $rows, 'the subcategory was dropped by the save');
        $this->assertSame((int)$first->id, (int)$rows[0]->subcategory_id);
        $this->assertSame((int)$first->parent, (int)$rows[0]->category_id);

        // find() hands it back under its parent category, which is what the
        // editor binds the subcategory picker to.
        $body = $this->getJson('/api/admin/product/find/' . $product->id)->json('data');

        $category = collect($body['product_categories'])
            ->firstWhere('category_id', $first->parent);

        $this->assertNotNull($category);
        $this->assertSame((int)$first->id, (int)$category['category']['child'][0]['id']);

        // A later save replaces them rather than adding to them.
        $this->save($this->payload([
            'slug' => $product->slug,
            'product_categories' => [$first->parent],
            'subcategories' => [],
        ]), $product->id);

        $this->assertSame(0, ProductSubcategory::where('product_id', $product->id)->count(),
            'removing every subcategory left the old rows behind');
    }


    /**
     * The VAT-inclusive columns follow the rate on the selected tax rule, not
     * a hardcoded 23% on rule 1, and a product with no offer stores null
     * rather than 0.00.
     */
    public function test_the_vat_inclusive_columns_follow_the_selected_tax_rule()
    {
        $this->admin();

        $rule = TaxRules::where('type', '!=', 1)->first() ?: TaxRules::first();

        if (!$rule) {
            $this->markTestSkipped('No tax rule to price against.');
        }

        $product = $this->makeProduct(['selling' => 200, 'offered' => 150, 'tax_rule_id' => $rule->id]);

        $expectedSelling = \App\Models\Helper\Pricing::round(
            200 + \App\Models\Helper\Pricing::unitTax($rule, 200, true)
        );
        $expectedOffered = \App\Models\Helper\Pricing::round(
            150 + \App\Models\Helper\Pricing::unitTax($rule, 150, true)
        );

        $this->assertEqualsWithDelta($expectedSelling, (float)$product->vatInclusivePrice, 0.02);
        $this->assertEqualsWithDelta($expectedOffered, (float)$product->vatInclusiveOfferedPrice, 0.02);

        $noOffer = $this->makeProduct(['selling' => 200, 'offered' => '', 'tax_rule_id' => $rule->id]);
        $this->assertNull($noOffer->vatInclusiveOfferedPrice);
    }

    /**
     * An edit that does not carry excludeVAT leaves it alone.
     *
     * The regression this pins: action() did
     * `$request['excludeVAT'] = $request->excludeVAT;` unconditionally, so a
     * client that left the field out wrote null into a NOT NULL column. Under
     * STRICT_TRANS_TABLES the whole save was lost behind a generic "Something
     * went wrong"; without it the flag silently reset to 0 and an ex-VAT
     * product started being sold inc-VAT.
     */
    public function test_an_edit_that_omits_exclude_vat_preserves_it()
    {
        $this->admin();

        $product = $this->makeProduct(['excludeVAT' => 1]);
        $this->assertSame(1, (int)$product->excludeVAT);

        $payload = $this->payload(['slug' => $product->slug, 'title' => 'Kept ex-VAT']);
        unset($payload['excludeVAT']);

        $body = $this->save($payload, $product->id);

        $this->assertArrayNotHasKey('form', $body, 'the save was refused: ' . json_encode($body));
        $this->assertSame('Kept ex-VAT', $product->fresh()->title);
        $this->assertSame(1, (int)$product->fresh()->excludeVAT,
            'excludeVAT was wiped by a save that did not mention it');
    }

    /** Sending it still sets it, in both directions. */
    public function test_exclude_vat_can_still_be_turned_on_and_off()
    {
        $this->admin();

        $product = $this->makeProduct(['excludeVAT' => 0]);

        $this->save($this->payload(['slug' => $product->slug, 'excludeVAT' => 1]), $product->id);
        $this->assertSame(1, (int)$product->fresh()->excludeVAT);

        $this->save($this->payload(['slug' => $product->slug, 'excludeVAT' => 0]), $product->id);
        $this->assertSame(0, (int)$product->fresh()->excludeVAT);
    }

    /* ================================================================
     *  MEDIA
     * ================================================================ */

    public function test_gallery_images_upload_and_delete()
    {
        $this->admin();
        $product = $this->makeProduct();

        $body = $this->post('/api/admin/product/upload-images/' . $product->id, [
            'images' => [
                UploadedFile::fake()->image('one.jpg', 400, 400),
                UploadedFile::fake()->image('two.jpg', 400, 400),
            ],
        ], ['Accept' => 'application/json'])->json('data');

        $this->assertIsArray($body);
        $this->assertCount(2, ProductImage::where('product_id', $product->id)->get());

        $images = ProductImage::where('product_id', $product->id)->get();

        $this->deleteJson('/api/admin/product/delete-image/' . $images[0]->id)->assertOk();
        $this->assertSame(1, ProductImage::where('product_id', $product->id)->count());

        foreach ($images as $img) {
            @unlink(base_path('uploads/' . $img->image));
        }
    }

    /**
     * Gallery images are timestamped and come back in a stable order.
     *
     * The regression this pins: multipleImageUpload() used ProductImage::insert(),
     * which bypasses Eloquent's timestamps, so created_at was NULL on all 231
     * rows in this database -- and allImages() reads them back with
     * orderBy('created_at','ASC'). The order was whatever MySQL felt like,
     * which is why dragging images into an order never stuck.
     */
    public function test_uploaded_gallery_images_are_timestamped_and_ordered()
    {
        $this->admin();
        $product = $this->makeProduct();

        $this->post('/api/admin/product/upload-images/' . $product->id, [
            'images' => [
                UploadedFile::fake()->image('one.jpg', 300, 300),
                UploadedFile::fake()->image('two.jpg', 300, 300),
                UploadedFile::fake()->image('three.jpg', 300, 300),
            ],
        ], ['Accept' => 'application/json']);

        $images = ProductImage::where('product_id', $product->id)->orderBy('id')->get();

        $this->assertCount(3, $images);

        foreach ($images as $image) {
            $this->assertNotNull($image->created_at, 'a gallery image has no created_at');
        }

        // Read back through the endpoint the editor uses: same order, every time.
        $listed = collect($this->getJson('/api/admin/product/all-images/' . $product->id)->json('data'))
            ->pluck('id')->map(function ($id) { return (int)$id; })->all();

        $this->assertSame(
            $images->pluck('id')->map(function ($id) { return (int)$id; })->all(),
            $listed,
            'the gallery came back in a different order than it was uploaded'
        );

        foreach ($images as $image) {
            @unlink(base_path('uploads/' . $image->image));
        }
    }

    /**
     * set-main-image needs product.edit, and the image has to be the
     * product's own.
     *
     * The regression this pins: setMainImage() ran no Utils::userCan() check
     * and never verified the image belonged to the product, so any signed-in
     * admin -- including a role with no product permission at all -- could
     * point any product's featured image at any other product's photo.
     */
    public function test_set_main_image_needs_permission_and_the_products_own_image()
    {
        $this->admin();

        $owner  = $this->makeProduct();
        $victim = $this->makeProduct();

        $this->post('/api/admin/product/upload-images/' . $owner->id, [
            'images' => [UploadedFile::fake()->image('owned.jpg', 300, 300)],
        ], ['Accept' => 'application/json']);

        $image = ProductImage::where('product_id', $owner->id)->first();
        $this->assertNotNull($image);

        $wasVictimImage = $victim->image;

        // No permission: refused.
        $refused = $this->without('product.edit', function () use ($owner, $image) {
            return $this->postJson('/api/admin/product/set-main-image', [
                'image_id'   => $image->id,
                'product_id' => $owner->id,
            ])->json();
        });

        $this->assertNotSame(200, (int)($refused['status'] ?? 200),
            'set-main-image went through without product.edit');
        $this->assertSame($owner->image, $owner->fresh()->image);

        // With permission, but somebody else's image: refused.
        $this->admin();

        $crossed = $this->postJson('/api/admin/product/set-main-image', [
            'image_id'   => $image->id,
            'product_id' => $victim->id,
        ])->json();

        $this->assertNotSame(200, (int)($crossed['status'] ?? 200),
            'another product\'s image was accepted');
        $this->assertSame($wasVictimImage, $victim->fresh()->image);

        // Its own image still works.
        $this->postJson('/api/admin/product/set-main-image', [
            'image_id'   => $image->id,
            'product_id' => $owner->id,
        ])->assertOk();

        $this->assertSame($image->image, $owner->fresh()->image);

        @unlink(base_path('uploads/' . $image->image));
    }

    /**
     * Several feature images uploaded together each keep their own file.
     *
     * The regression this pins: setProductFeatureImages() named every file
     * time().'.'.extension inside one loop, so a batch uploaded within the
     * same second all resolved to a single name. On Linux each move overwrote
     * the last and the product ended up with N rows pointing at one file; on
     * Windows the second rename was refused outright and the request 500d
     * halfway through, leaving the rows written so far behind.
     */
    public function test_feature_images_uploaded_together_each_keep_their_own_file()
    {
        $this->admin();
        $product = $this->makeProduct();

        $this->post('/api/admin/product/feature-images/' . $product->id, [
            'images' => [
                UploadedFile::fake()->image('f1.jpg', 300, 300),
                UploadedFile::fake()->image('f2.jpg', 300, 300),
                UploadedFile::fake()->image('f3.jpg', 300, 300),
            ],
        ], ['Accept' => 'application/json'])->assertOk();

        $names = ProductFeatureImage::where('product_id', $product->id)
            ->orderBy('sort_order')->pluck('image');

        $this->assertCount(3, $names, 'not every feature image was recorded');
        $this->assertSame(3, $names->unique()->count(),
            'the feature images share a filename');

        foreach ($names as $name) {
            $this->assertFileExists(base_path('uploads/' . $name),
                'a feature image row points at a file that is not there');
        }

        foreach ($names as $name) {
            @unlink(base_path('uploads/' . $name));
        }
    }


    /**
     * Reading a product's gallery and mapping images to a variant both need a
     * permission, and the mapping only accepts the product's own images.
     *
     * The regression this pins: upload-images and delete-image both called
     * Utils::userCan('product.edit'); all-images and set-variant-images called
     * nothing, so the gallery of any product could be read and its
     * variant-image mapping rewritten -- with images from anywhere in the
     * catalogue -- by an admin whose role granted nothing on products.
     */
    public function test_gallery_read_and_variant_image_mapping_need_permission()
    {
        $this->admin();
        $product = $this->makeProduct();

        $this->post('/api/admin/product/upload-images/' . $product->id, [
            'images' => [UploadedFile::fake()->image('v.jpg', 300, 300)],
        ], ['Accept' => 'application/json']);

        $image = ProductImage::where('product_id', $product->id)->first();

        $stock = UpdatedInventory::create([
            'product_id' => $product->id, 'sku' => 'QA-VI-' . uniqid(),
            'price' => 10, 'quantity' => 1, 'is_active' => 1,
        ]);

        $listed = $this->without('product.view', function () use ($product) {
            return $this->getJson('/api/admin/product/all-images/' . $product->id)->json('status');
        });

        $this->assertNotSame(200, (int)$listed,
            'the gallery was served without product.view');

        $mapped = $this->without('product.edit', function () use ($image, $stock) {
            return $this->postJson('/api/admin/product/set-variant-images', [
                'inventory_id' => $stock->id,
                'image_ids' => [$image->id],
            ])->json('status');
        });

        $this->assertNotSame(200, (int)$mapped,
            'a variant-image mapping was written without product.edit');
        $this->assertSame(0, \App\Models\InventoryImage::where('inventory_id', $stock->id)->count());

        // With the permission it works, and only for this product's images.
        $this->admin();

        $other = $this->makeProduct();
        $this->post('/api/admin/product/upload-images/' . $other->id, [
            'images' => [UploadedFile::fake()->image('other.jpg', 300, 300)],
        ], ['Accept' => 'application/json']);

        $foreign = ProductImage::where('product_id', $other->id)->first();

        $this->postJson('/api/admin/product/set-variant-images', [
            'inventory_id' => $stock->id,
            'image_ids' => [$image->id, $foreign->id],
        ])->assertOk();

        $this->assertSame(
            [(int)$image->id],
            \App\Models\InventoryImage::where('inventory_id', $stock->id)
                ->pluck('product_image_id')
                ->map(function ($id) { return (int)$id; })->all(),
            'an image belonging to another product was mapped to this variant'
        );

        @unlink(base_path('uploads/' . $image->image));
        @unlink(base_path('uploads/' . $foreign->image));
    }

    public function test_the_featured_image_upload_replaces_the_old_file_on_an_existing_product()
    {
        $this->admin();
        $product = $this->makeProduct();

        $body = $this->post('/api/admin/product/upload/' . $product->id, [
            'id' => $product->id,
            'tax_rule_id' => $product->tax_rule_id,
            'shipping_rule_id' => $product->shipping_rule_id,
            'photo' => UploadedFile::fake()->image('hero.jpg', 500, 500),
        ], ['Accept' => 'application/json'])->json('data');

        $this->assertNotEmpty($body['image'] ?? null);
        $this->assertSame($body['image'], $product->fresh()->image);

        @unlink(base_path('uploads/' . $body['image']));
    }

    /* ================================================================
     *  STOCK  -  the inventory save the product form posts
     * ================================================================ */

    /**
     * Removing an attribute from a variant removes that variant's row and
     * nothing else.
     *
     * The regression this pins: action() used to collect
     * `$j->attribute_value['id']` -- ATTRIBUTE VALUE ids -- and then remove the
     * ones no longer submitted with
     * `InventoryAttribute::whereIn('id', $existingAttributes[...])->delete()`,
     * treating those value ids as inventory_attributes PRIMARY KEYS.
     *
     * attribute_values run 1..64 in this database and inventory_attributes
     * 55..273, so every removal silently destroyed an unrelated row in that
     * overlapping range -- another product's variant quietly lost its colour or
     * capacity, breaking its variant picker and its upgrade ladder -- while the
     * row the admin actually unticked stayed put.
     *
     * The fixture below is built on a real id collision rather than a
     * synthetic one, so it keeps testing the thing that went wrong.
     */
    public function test_removing_a_variant_attribute_touches_only_that_variant()
    {
        $this->admin();

        // An attribute value whose id ALSO exists as an inventory_attributes
        // primary key on some other product's variant. This is the row the old
        // code deleted by mistake.
        $collateral = null;
        $removedValue = null;

        foreach (AttributeValue::orderBy('id')->get() as $value) {
            $row = InventoryAttribute::find($value->id);

            if ($row) {
                $collateral   = $row;
                $removedValue = $value;
                break;
            }
        }

        if (!$collateral) {
            $this->markTestSkipped('No attribute value id overlaps an inventory_attributes id.');
        }

        $keptValue = AttributeValue::where('id', '!=', $removedValue->id)->first();
        $this->assertNotNull($keptValue);

        $product = $this->makeProduct();

        // Two attribute values on one variant, so one can be taken away later.
        $saved = $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [
            'inventories' => [[
                'id' => '', 'sku' => 'QA-IA-' . uniqid(), 'price' => 10,
                'quantity' => 3, 'is_active' => 1,
                'values' => [['id' => $keptValue->id], ['id' => $removedValue->id]],
            ]],
        ])->json('data');

        $inventoryId = $saved[0]['id'] ?? null;
        $this->assertNotNull($inventoryId, 'the stock row was refused');

        $this->assertNotSame((int)$inventoryId, (int)$collateral->inventory_id,
            'the collateral belongs to the product under test - pick another fixture');

        $attributesOf = function ($inventoryId) {
            return InventoryAttribute::where('inventory_id', $inventoryId)
                ->pluck('attribute_value_id')
                ->map(function ($id) { return (int)$id; })
                ->sort()->values()->all();
        };

        $this->assertSame(
            collect([(int)$keptValue->id, (int)$removedValue->id])->sort()->values()->all(),
            $attributesOf($inventoryId)
        );

        // Re-save with only the kept value: the other one is removed.
        $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [
            'inventories' => [[
                'id' => $inventoryId, 'sku' => 'QA-IA-' . uniqid(), 'price' => 10,
                'quantity' => 3, 'is_active' => 1,
                'values' => [['id' => $keptValue->id]],
            ]],
        ])->assertOk();

        // The row the admin unticked is the row that went.
        $this->assertSame([(int)$keptValue->id], $attributesOf($inventoryId));

        // The bystander with the colliding id is untouched.
        $this->assertNotNull(InventoryAttribute::find($collateral->id),
            'a row on another product was deleted by this save');
        $this->assertSame(
            (int)$collateral->inventory_id,
            (int)InventoryAttribute::find($collateral->id)->inventory_id
        );
    }

    /**
     * The stock save needs product.edit, and refuses a product that is not
     * there.
     *
     * The regression this pins: UpdatedInventoriesController extended
     * Controller rather than ControllerHelper, so it had no $this->user and
     * never called Utils::userCan() at all -- any signed-in admin could
     * rewrite any product's stock, prices, SKUs and barcodes.
     */
    public function test_the_stock_save_needs_product_edit()
    {
        $this->admin();
        $product = $this->makeProduct();

        $body = $this->without('product.edit', function () use ($product) {
            return $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [
                'inventories' => [[
                    'id' => '', 'sku' => 'QA-NOPERM-' . uniqid(), 'price' => 10,
                    'quantity' => 99, 'is_active' => 1, 'values' => [],
                ]],
            ])->json();
        });

        $this->assertNotSame(200, (int)($body['status'] ?? 200),
            'stock was written without product.edit');
        $this->assertSame(0, UpdatedInventory::where('product_id', $product->id)->count());

        // With the permission it still saves.
        $this->admin();

        $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [
            'inventories' => [[
                'id' => '', 'sku' => 'QA-PERM-' . uniqid(), 'price' => 10,
                'quantity' => 99, 'is_active' => 1, 'values' => [],
            ]],
        ])->assertOk();

        $this->assertSame(1, UpdatedInventory::where('product_id', $product->id)->count());
    }

    /** Stock cannot be written against a product that does not exist. */
    public function test_the_stock_save_refuses_an_unknown_product()
    {
        $this->admin();

        $body = $this->postJson('/api/admin/updated-inventory/action/999999999', [
            'inventories' => [[
                'id' => '', 'sku' => 'QA-GHOST-' . uniqid(), 'price' => 10,
                'quantity' => 1, 'is_active' => 1, 'values' => [],
            ]],
        ])->json();

        $this->assertNotSame(200, (int)($body['status'] ?? 200));
        $this->assertSame(0, UpdatedInventory::where('product_id', 999999999)->count());
    }

    public function test_a_variant_that_has_been_ordered_is_retired_rather_than_deleted()
    {
        $this->admin();
        $product = $this->makeProduct();

        $saved = $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [
            'inventories' => [[
                'id' => '', 'sku' => 'QA-RET-' . uniqid(), 'price' => 10,
                'quantity' => 4, 'is_active' => 1, 'values' => [],
            ]],
        ])->json('data');

        $inventoryId = $saved[0]['id'] ?? null;
        $this->assertNotNull($inventoryId);

        $order = \App\Models\Order::first();

        if (!$order) {
            $this->markTestSkipped('No order to hang an ordered product off.');
        }

        OrderedProduct::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'inventory_id' => $inventoryId, 'quantity' => 1,
            'shipping_place_id' => $this->shippingPlaceId(), 'shipping_type' => 0,
            'purchased' => 5, 'selling' => 10,
        ]);

        // Replace it with a different variant: the ordered one is dropped from
        // the submitted list, which is how the form says "delete this row".
        $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [
            'inventories' => [[
                'id' => '', 'sku' => 'QA-RET2-' . uniqid(), 'price' => 12,
                'quantity' => 6, 'is_active' => 1, 'values' => [],
            ]],
        ])->assertOk();

        $retired = UpdatedInventory::find($inventoryId);

        $this->assertNotNull($retired, 'a variant with order history was deleted');
        $this->assertSame(0, (int)$retired->quantity);
        $this->assertSame(0, (int)$retired->is_active);
    }

    /**
     * The last variant of a product can be removed.
     *
     * The regression this pins: Validation::updatedInventory() demanded
     * `inventories` be an array with at least one row, and the form posts the
     * whole list on every save -- so deleting the only remaining variant
     * submitted an empty array and was refused, inside the body with HTTP 200,
     * leaving a generic stock error on screen and the row where it was.
     */
    public function test_the_last_variant_can_be_deleted()
    {
        $this->admin();
        $product = $this->makeProduct();

        $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [
            'inventories' => [[
                'id' => '', 'sku' => 'QA-LAST-' . uniqid(), 'price' => 10,
                'quantity' => 4, 'is_active' => 1, 'values' => [],
            ]],
        ])->assertOk();

        $this->assertSame(1, UpdatedInventory::where('product_id', $product->id)->count());

        $body = $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [
            'inventories' => [],
        ])->json();

        $this->assertSame(200, (int)($body['status'] ?? 0),
            'an empty stock list was refused: ' . json_encode($body['data'] ?? []));
        $this->assertSame(0, UpdatedInventory::where('product_id', $product->id)->count(),
            'the last variant survived');
    }

    /** The list still has to be present -- a save that forgets it is a bug. */
    public function test_a_stock_save_with_no_inventories_key_is_refused()
    {
        $this->admin();
        $product = $this->makeProduct();

        $body = $this->postJson('/api/admin/updated-inventory/action/' . $product->id, [])->json();

        $this->assertNotSame(200, (int)($body['status'] ?? 200));
    }


    /* ================================================================
     *  THE BULK EDITOR  -  POST /api/admin/product/bulk-update
     * ================================================================ */

    public function test_the_bulk_editor_updates_prices_status_and_categories()
    {
        $this->admin();

        $product  = $this->makeProduct(['selling' => 100, 'status' => 2]);
        $category = Category::whereNull('parent')->first();

        $this->postJson('/api/admin/product/bulk-update', [
            'products' => json_encode([[
                'id' => $product->id,
                'title' => 'Bulk renamed',
                'slug' => $product->slug,
                'selling' => 250,
                'discount_type' => 'fixed',
                'discount_value' => 0,
                'offered' => 200,
                'status' => 1,
                'upsell_id' => null,
                'primary_category_id' => $category ? $category->id : null,
                'categories' => $category ? [$category->id] : [],
            ]]),
        ])->assertOk();

        $fresh = $product->fresh();

        $this->assertSame('Bulk renamed', $fresh->title);
        $this->assertSame(250.0, (float)$fresh->selling);
        $this->assertSame(200.0, (float)$fresh->offered);
        $this->assertSame(1, (int)$fresh->status);

        if ($category) {
            $this->assertTrue(ProductCategory::where('product_id', $product->id)
                ->where('category_id', $category->id)->exists());
        }
    }

    public function test_the_bulk_editor_records_a_before_and_after_log()
    {
        $this->admin();

        $product = $this->makeProduct(['selling' => 100]);
        $before  = \App\Models\ProductLog::count();

        $this->postJson('/api/admin/product/bulk-update', [
            'products' => json_encode([[
                'id' => $product->id, 'title' => $product->title, 'slug' => $product->slug,
                'selling' => 111, 'discount_type' => 'fixed', 'discount_value' => 0,
                'offered' => 0, 'status' => 1, 'upsell_id' => null,
            ]]),
        ])->assertOk();

        $this->assertSame($before + 1, \App\Models\ProductLog::count());
    }

    /**
     * The bulk editor needs product.edit.
     *
     * The regression this pins: bulkUpdate() called Utils::userCan() nowhere,
     * so any signed-in admin could rewrite titles, slugs, prices, status and
     * categories across the whole catalogue whatever their role granted.
     */
    public function test_the_bulk_editor_needs_product_edit()
    {
        $this->admin();
        $product = $this->makeProduct(['selling' => 100]);

        $body = $this->without('product.edit', function () use ($product) {
            return $this->postJson('/api/admin/product/bulk-update', [
                'products' => json_encode([[
                    'id' => $product->id, 'title' => 'No permission needed',
                    'slug' => $product->slug, 'selling' => 777,
                    'discount_type' => 'fixed', 'discount_value' => 0,
                    'offered' => 0, 'status' => 1, 'upsell_id' => null,
                ]]),
            ])->json();
        });

        $this->assertNotSame(200, (int)($body['status'] ?? 200),
            'the bulk editor ran without product.edit');
        $this->assertSame(100.0, (float)$product->fresh()->selling,
            'the bulk editor wrote without product.edit');
        $this->assertSame($product->title, $product->fresh()->title);
    }

    /**
     * The bulk editor answers to the same rules as the product form.
     *
     * The regression this pins: it called no Validation:: anything, so an
     * offer above the selling price -- refused on the product page since QA
     * audit PRC-02 -- went straight into the column, and so did a negative
     * price, a meaningless status and a duplicate slug.
     *
     * @dataProvider badBulkRows
     */
    public function test_the_bulk_editor_refuses_a_row_that_breaks_a_rule($override, $because)
    {
        $this->admin();

        $product = $this->makeProduct(['selling' => 100, 'offered' => 80, 'status' => 1]);
        $before  = $product->fresh()->toArray();

        $row = array_merge([
            'id' => $product->id, 'title' => $product->title, 'slug' => $product->slug,
            'selling' => 100, 'discount_type' => 'fixed', 'discount_value' => 0,
            'offered' => 80, 'status' => 1, 'upsell_id' => null,
        ], $override);

        if (($override['slug'] ?? null) === '__taken__') {
            $row['slug'] = $this->makeProduct()->slug;
        }

        $body = $this->postJson('/api/admin/product/bulk-update', [
            'products' => json_encode([$row]),
        ])->json();

        $this->assertSame(201, (int)($body['status'] ?? 0), $because . ' was accepted');
        $this->assertNotEmpty($body['data']['form'] ?? [], 'no reason was given');
        $this->assertArrayHasKey((string)$product->id, $body['data']['rows'] ?? [],
            'the refusal did not say which row was at fault');

        $after = $product->fresh()->toArray();

        foreach (['title', 'slug', 'selling', 'offered', 'status'] as $column) {
            $this->assertSame($before[$column], $after[$column],
                $column . ' was written by a refused bulk update');
        }
    }

    public function badBulkRows()
    {
        return [
            'offer above selling'  => [['offered' => 5000], 'an offer above the selling price'],
            'negative offer'       => [['offered' => -10], 'a negative offer'],
            'zero selling price'   => [['selling' => 0], 'a selling price of zero'],
            'negative selling'     => [['selling' => -5], 'a negative selling price'],
            'unknown status'       => [['status' => 7], 'a status of 7'],
            'percentage over 100'  => [['discount_type' => 'percentage', 'discount_value' => 150], 'a 150 percent discount'],
            'negative discount'    => [['discount_value' => -1], 'a negative discount'],
            'empty title'          => [['title' => '   '], 'an empty title'],
            'empty slug'           => [['slug' => ''], 'an empty slug'],
            'duplicate slug'       => [['slug' => '__taken__'], 'another product slug'],
        ];
    }

    /** One bad row stops the whole batch; the good rows are not half-applied. */
    public function test_a_bulk_update_with_one_bad_row_writes_nothing()
    {
        $this->admin();

        $good = $this->makeProduct(['selling' => 100, 'status' => 1]);
        $bad  = $this->makeProduct(['selling' => 100, 'status' => 1]);

        $rowFor = function ($product, $over = []) {
            return array_merge([
                'id' => $product->id, 'title' => $product->title, 'slug' => $product->slug,
                'selling' => 100, 'discount_type' => 'fixed', 'discount_value' => 0,
                'offered' => 0, 'status' => 1, 'upsell_id' => null,
            ], $over);
        };

        $body = $this->postJson('/api/admin/product/bulk-update', [
            'products' => json_encode([
                $rowFor($good, ['selling' => 250]),
                $rowFor($bad, ['selling' => 250, 'offered' => 9999]),
            ]),
        ])->json();

        $this->assertSame(201, (int)($body['status'] ?? 0));
        $this->assertSame(100.0, (float)$good->fresh()->selling,
            'a good row was written even though the batch was refused');
        $this->assertSame(100.0, (float)$bad->fresh()->selling);
    }

    /**
     * A bulk price change carries the VAT-inclusive columns with it.
     *
     * The regression this pins: action() recomputed vatInclusivePrice and
     * vatInclusiveOfferedPrice from the tax rule on every save; bulkUpdate()
     * wrote selling and offered and touched neither, so after a bulk
     * repricing both columns still described the old price -- the drift QA
     * audit TAX-02 was raised to clear, reopened by a different door. Both
     * paths now derive them from one place.
     */
    public function test_a_bulk_price_change_recomputes_the_vat_inclusive_columns()
    {
        $this->admin();

        $product = $this->makeProduct(['selling' => 100, 'offered' => '']);
        $rule    = TaxRules::find($product->tax_rule_id);

        $this->postJson('/api/admin/product/bulk-update', [
            'products' => json_encode([[
                'id' => $product->id, 'title' => $product->title, 'slug' => $product->slug,
                'selling' => 900, 'discount_type' => 'fixed', 'discount_value' => 0,
                'offered' => 450, 'status' => 1, 'upsell_id' => null,
            ]]),
        ])->assertOk();

        $fresh = $product->fresh();

        $this->assertSame(900.0, (float)$fresh->selling);

        $this->assertEqualsWithDelta(
            \App\Models\Helper\Pricing::round(900 + \App\Models\Helper\Pricing::unitTax($rule, 900, true)),
            (float)$fresh->vatInclusivePrice, 0.02,
            'the VAT-inclusive price still describes the old selling price'
        );

        $this->assertEqualsWithDelta(
            \App\Models\Helper\Pricing::round(450 + \App\Models\Helper\Pricing::unitTax($rule, 450, true)),
            (float)$fresh->vatInclusiveOfferedPrice, 0.02,
            'the VAT-inclusive offer still describes the old offer'
        );
    }

    /** Clearing the offer in bulk clears its VAT-inclusive column too. */
    public function test_clearing_an_offer_in_bulk_nulls_the_vat_inclusive_offer()
    {
        $this->admin();

        $product = $this->makeProduct(['selling' => 100, 'offered' => 80]);
        $this->assertGreaterThan(0, (float)$product->vatInclusiveOfferedPrice);

        $this->postJson('/api/admin/product/bulk-update', [
            'products' => json_encode([[
                'id' => $product->id, 'title' => $product->title, 'slug' => $product->slug,
                'selling' => 100, 'discount_type' => 'fixed', 'discount_value' => 0,
                'offered' => 0, 'status' => 1, 'upsell_id' => null,
            ]]),
        ])->assertOk();

        $this->assertNull($product->fresh()->vatInclusiveOfferedPrice);
    }


    /* ================================================================
     *  DUPLICATE
     * ================================================================ */

    /**
     * Duplicate needs product.create, and produces a usable copy.
     *
     * The regression this pins: duplicate() ran no Utils::userCan() check, so
     * the $can('product','create') gate on the Products grid was decoration;
     * and it copied only the row and its categories, leaving stock, gallery
     * images, specifications, collections and feature images behind.
     */
    public function test_duplicate_needs_product_create()
    {
        $this->admin();

        $source = $this->makeProduct();
        $before = Product::count();

        $body = $this->without('product.create', function () use ($source) {
            return $this->postJson('/api/admin/products/' . $source->id . '/duplicate')->json();
        });

        $this->assertNull($body['id'] ?? null, 'a copy was made without product.create');
        $this->assertSame($before, Product::count());
    }

    public function test_duplicate_copies_what_describes_the_product()
    {
        $this->admin();

        $collectionIds = \App\Models\ProductCollection::limit(1)->pluck('id')->all();
        $category = Category::whereNull('parent')->first();
        $values = AttributeValue::limit(1)->get();

        $source = $this->makeProduct([
            'status' => 1,
            'product_collections' => $collectionIds,
            'product_categories' => $category ? [$category->id] : [],
            'primary_category_id' => $category ? $category->id : null,
            'specifications' => [['group' => 'Display', 'items' => [['value' => '13 inch']]]],
        ]);

        $this->post('/api/admin/product/upload-images/' . $source->id, [
            'images' => [UploadedFile::fake()->image('gallery.jpg', 300, 300)],
        ], ['Accept' => 'application/json']);

        $gallery = ProductImage::where('product_id', $source->id)->first();

        ProductFeatureImage::create([
            'product_id' => $source->id, 'image' => 'qa-feature.jpg', 'sort_order' => 0,
        ]);

        $saved = $this->postJson('/api/admin/updated-inventory/action/' . $source->id, [
            'inventories' => [[
                'id' => '', 'sku' => 'QA-SRC-' . uniqid(), 'price' => 10,
                'quantity' => 7, 'is_active' => 1,
                'values' => $values->map(function ($v) { return ['id' => $v->id]; })->all(),
                'image_ids' => [$gallery->id],
            ]],
        ])->json('data');

        $sourceInventoryId = $saved[0]['id'];

        $copyId = $this->postJson('/api/admin/products/' . $source->id . '/duplicate')
            ->assertOk()->json('id');

        $this->assertNotNull($copyId);

        $copy = Product::find($copyId);
        $this->assertSame($source->title . ' (Copy)', $copy->title);

        // A copy never goes live on the storefront by itself.
        $this->assertSame(2, (int)$copy->status, 'the copy was published');
        $this->assertNotSame($source->slug, $copy->slug);

        $this->assertSame(1, ProductCategory::where('product_id', $copyId)->count());
        $this->assertSame(1, CollectionWithProduct::where('product_id', $copyId)->count());
        $this->assertSame(1, ProductSpecification::where('product_id', $copyId)->count());
        $this->assertSame(1, ProductFeatureImage::where('product_id', $copyId)->count());
        $this->assertSame(1, ProductImage::where('product_id', $copyId)->count());

        $stock = UpdatedInventory::where('product_id', $copyId)->get();
        $this->assertCount(1, $stock);
        $this->assertSame(7, (int)$stock[0]->quantity);
        $this->assertNotSame(
            UpdatedInventory::find($sourceInventoryId)->sku,
            $stock[0]->sku,
            'the copy took the original SKU'
        );

        if ($values->count()) {
            $this->assertSame(1, InventoryAttribute::where('inventory_id', $stock[0]->id)->count());
        }

        // The variant points at the COPY's image row, not the original's.
        $mapped = \App\Models\InventoryImage::where('inventory_id', $stock[0]->id)
            ->pluck('product_image_id')->all();

        $this->assertCount(1, $mapped);
        $this->assertNotSame((int)$gallery->id, (int)$mapped[0],
            'the copy shares the original product image row');
        $this->assertSame(
            (int)ProductImage::where('product_id', $copyId)->value('id'),
            (int)$mapped[0]
        );

        @unlink(base_path('uploads/' . $gallery->image));
    }

    /** Reviews, ratings and view counts stay with the original. */
    public function test_duplicate_does_not_carry_over_reviews_or_ratings()
    {
        $this->admin();

        $source = $this->makeProduct();
        Product::where('id', $source->id)->update(['rating' => 5, 'review_count' => 12]);

        $copyId = $this->postJson('/api/admin/products/' . $source->id . '/duplicate')
            ->assertOk()->json('id');

        $copy = Product::find($copyId);

        $this->assertSame(0, (int)$copy->getRawOriginal('rating'));
        $this->assertSame(0, (int)$copy->getRawOriginal('review_count'));
    }


    /* ================================================================
     *  DELETE
     * ================================================================ */

    public function test_delete_removes_the_product_its_stock_and_its_images()
    {
        $this->admin();
        $product = $this->makeProduct();

        UpdatedInventory::create([
            'product_id' => $product->id, 'sku' => 'QA-DEL-' . uniqid(),
            'price' => 10, 'quantity' => 2, 'is_active' => 1,
        ]);

        $this->post('/api/admin/product/upload-images/' . $product->id, [
            'images' => [UploadedFile::fake()->image('gone.jpg', 300, 300)],
        ], ['Accept' => 'application/json']);

        $this->deleteJson('/api/admin/product/delete/' . $product->id)->assertOk();

        $this->assertNull(Product::find($product->id));
        $this->assertSame(0, UpdatedInventory::where('product_id', $product->id)->count());
        $this->assertSame(0, ProductImage::where('product_id', $product->id)->count());
    }

    public function test_delete_needs_product_delete()
    {
        $this->admin();
        $product = $this->makeProduct();

        $body = $this->without('product.delete', function () use ($product) {
            return $this->deleteJson('/api/admin/product/delete/' . $product->id)->json();
        });

        $this->assertNotSame(200, (int)($body['status'] ?? 200));
        $this->assertNotNull(Product::find($product->id));
    }

    /**
     * A product that appears on a past order is retired, not destroyed.
     *
     * The regression this pins: delete() used to open with
     * OrderedProduct::where('product_id', $id)->delete(), because the FK on
     * ordered_products would otherwise refuse to let the product row go. Those
     * rows ARE the order -- its lines, quantities and the price charged -- so a
     * discontinued product took part of the order history with it. Totals
     * stopped reconciling with their lines and a reprinted invoice was missing
     * items.
     *
     * It now follows the rule the stock save settled in QA audit STK-04: off
     * sale and out of every storefront surface, but the row stays so the
     * orders that point at it still resolve.
     */
    public function test_deleting_a_product_that_has_been_ordered_retires_it_instead()
    {
        $this->admin();

        $order = \App\Models\Order::first();

        if (!$order) {
            $this->markTestSkipped('No order to hang an ordered product off.');
        }

        $collectionIds = \App\Models\ProductCollection::limit(1)->pluck('id')->all();

        $product = $this->makeProduct([
            'status' => 1,
            'product_collections' => $collectionIds,
        ]);

        $stock = UpdatedInventory::create([
            'product_id' => $product->id, 'sku' => 'QA-HIST-' . uniqid(),
            'price' => 10, 'quantity' => 2, 'is_active' => 1,
        ]);

        $line = OrderedProduct::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'inventory_id' => $stock->id, 'quantity' => 2,
            'shipping_place_id' => $this->shippingPlaceId(), 'shipping_type' => 0,
            'purchased' => 50, 'selling' => 100,
        ]);

        \App\Models\UserWishlist::create([
            'product_id' => $product->id,
            'user_id' => \App\Models\User::value('id'),
        ]);

        $body = $this->deleteJson('/api/admin/product/delete/' . $product->id)
            ->assertOk()->json();

        // The order still has its line, at the price it was charged.
        $kept = OrderedProduct::find($line->id);
        $this->assertNotNull($kept, 'the order line was destroyed by the delete');
        $this->assertSame(2, (int)$kept->quantity);
        $this->assertSame('100.00', (string)$kept->selling);

        // The product and the variant the line points at are both still there.
        $retired = Product::find($product->id);
        $this->assertNotNull($retired, 'the product row was destroyed');
        $this->assertNotNull(UpdatedInventory::find($stock->id),
            'the variant the order line points at was destroyed');

        // But it is off sale and out of every storefront surface.
        $this->assertSame(2, (int)$retired->status, 'the product is still published');
        $this->assertSame(0, CollectionWithProduct::where('product_id', $product->id)->count());
        $this->assertSame(0, \App\Models\UserWishlist::where('product_id', $product->id)->count());
        $this->assertSame(0, \App\Models\Cart::where('product_id', $product->id)->count());

        // And the admin is told, rather than left to notice.
        $this->assertSame([$product->title], $body['data']['retired'] ?? null);
        $this->assertStringContainsString('past order', (string)($body['message'] ?? ''));
    }

    /** A product nobody has ordered is still deleted outright. */
    public function test_deleting_a_product_that_has_never_been_ordered_removes_it()
    {
        $this->admin();

        $product = $this->makeProduct(['status' => 1]);

        $body = $this->deleteJson('/api/admin/product/delete/' . $product->id)
            ->assertOk()->json();

        $this->assertNull(Product::find($product->id));
        $this->assertSame([], $body['data']['retired'] ?? null);
        $this->assertSame('', (string)($body['message'] ?? ''));
    }


    /**
     * Delete never touches specifications or feature images itself; the
     * database does. product_specifications carries ON DELETE CASCADE, so
     * those go. This records which of the product's own tables the controller
     * is relying on the schema for, and asserts feature images are cleaned up
     * too - if that constraint is ever dropped, this is what notices.
     */
    public function test_delete_clears_specifications_and_feature_images()
    {
        $this->admin();

        $product = $this->makeProduct([
            'specifications' => [['group' => 'Display', 'items' => [['value' => '13 inch']]]],
        ]);

        ProductFeatureImage::create([
            'product_id' => $product->id, 'image' => 'qa-orphan.jpg', 'sort_order' => 0,
        ]);

        $this->assertSame(1, ProductSpecification::where('product_id', $product->id)->count());
        $this->assertSame(1, ProductFeatureImage::where('product_id', $product->id)->count());

        $this->deleteJson('/api/admin/product/delete/' . $product->id)->assertOk();

        $this->assertSame(0, ProductSpecification::where('product_id', $product->id)->count(),
            'specifications outlived the product');
        $this->assertSame(0, ProductFeatureImage::where('product_id', $product->id)->count(),
            'feature images outlived the product');
    }

    /**
     * A bulk delete with one bad id destroys nothing.
     *
     * The regression this pins: delete() exploded the id list and returned
     * "nothing found" the moment one id did not resolve -- with no transaction
     * open and everything before it in the list already gone. The admin saw an
     * error and reasonably assumed nothing had happened.
     */
    public function test_a_bulk_delete_with_one_bad_id_deletes_nothing()
    {
        $this->admin();

        $first  = $this->makeProduct();
        $second = $this->makeProduct();

        $body = $this->deleteJson('/api/admin/product/delete/'
            . $first->id . ',999999999,' . $second->id)->json();

        $this->assertNotSame(200, (int)($body['status'] ?? 200),
            'a selection containing an unknown id reported success');

        $this->assertNotNull(Product::find($first->id),
            'the first product was destroyed by a delete that then failed');
        $this->assertNotNull(Product::find($second->id));
    }

    /** A clean selection is still deleted in full. */
    public function test_a_bulk_delete_removes_every_product_in_the_selection()
    {
        $this->admin();

        $first  = $this->makeProduct();
        $second = $this->makeProduct();

        $this->deleteJson('/api/admin/product/delete/' . $first->id . ',' . $second->id)
            ->assertOk();

        $this->assertNull(Product::find($first->id));
        $this->assertNull(Product::find($second->id));
    }


    /* ================================================================
     *  THE OTHER TWO PRODUCT ENDPOINTS
     * ================================================================ */

    /**
     * The with-categories search finds products by title and by SKU.
     *
     * The regression this pins: allProductsWithCategory() filtered on
     * `pl.title` but never joined product_langs, so MySQL rejected the whole
     * statement with "Unknown column 'pl.title'" and the endpoint answered
     * "Something went wrong" every time anything was typed into the box.
     */
    public function test_the_with_categories_search_matches_a_title_and_a_sku()
    {
        $this->admin();

        $marker  = 'Zarquon' . substr(uniqid(), -6);
        $product = $this->makeProduct(['title' => $marker . ' Widget']);

        $sku = 'QA-WCS-' . strtoupper(substr(uniqid(), -6));

        UpdatedInventory::create([
            'product_id' => $product->id, 'sku' => $sku,
            'price' => 10, 'quantity' => 1, 'is_active' => 1,
        ]);

        $byTitle = $this->getJson('/api/admin/product/with-categories?q=' . $marker)->json();

        $this->assertArrayNotHasKey('form', $byTitle['data'] ?? [],
            'the search was refused: ' . json_encode($byTitle['data'] ?? []));

        $this->assertContains((string)$product->id,
            array_map('strval', collect($byTitle['data']['data'] ?? [])->pluck('id')->all()),
            'a product could not be found by its title');

        $bySku = $this->getJson('/api/admin/product/with-categories?q=' . $sku)->json();

        $this->assertContains((string)$product->id,
            array_map('strval', collect($bySku['data']['data'] ?? [])->pluck('id')->all()),
            'a product could not be found by its SKU');
    }


    /**
     * all-simple is admin-only, and costs one query.
     *
     * The regression this pins: the route sat above the auth:admin group in
     * routes/api.php, so the whole catalogue -- unpublished products and their
     * titles included -- was readable without a token. And it read through
     * Eloquent, so Product's three $appends fired per row: 372 queries for 122
     * products, on an endpoint anyone could call.
     */
    public function test_all_simple_is_admin_only()
    {
        $this->admin();
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/products/all-simple');

        $this->assertNotSame(200, $response->getStatusCode(),
            'the whole catalogue is still readable without a token');
    }

    public function test_all_simple_needs_product_view()
    {
        $body = $this->without('product.view', function () {
            return $this->getJson('/api/products/all-simple')->json();
        });

        $this->assertNotSame(200, (int)($body['status'] ?? 200));
    }

    public function test_all_simple_costs_one_query()
    {
        $this->admin();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $body = $this->getJson('/api/products/all-simple')->json('data');

        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $rows = count($body ?? []);

        $this->assertGreaterThan(10, $rows, 'not enough products to measure');

        /*
         * A ceiling rather than an exact figure: most of what is left is the
         * admin guard resolving the token, the role and the permissions, and
         * that is the same whether the catalogue holds 12 products or 1,200.
         * What matters is that the count no longer tracks $rows -- it was 372
         * for these 122 products.
         */
        $this->assertLessThan(25, $queries,
            'the list costs a query per product again. rows=' . $rows . ' queries=' . $queries);

        // Still the whole catalogue, drafts included - it is a picker for
        // admins, and a review can hang off an unpublished product.
        $draft = Product::where('status', 2)->value('id');

        if ($draft) {
            $this->assertContains((string)$draft,
                array_map('strval', collect($body)->pluck('id')->all()));
        }
    }


    /**
     * Every product-page view is counted, cached or not.
     *
     * The regression this pins: the increment sat inside
     * Utils::cacheRemember()'s closure, so it only ran when the day-long
     * cached payload was rebuilt. Every visitor served from cache was
     * invisible, and the Views column on the Products grid -- along with its
     * "high return" filter, views > 100 -- read an arbitrary fraction of the
     * real traffic.
     */
    public function test_every_product_page_view_is_counted()
    {
        $this->admin();

        $product = Product::whereNotNull('slug')->where('status', 1)->first();

        if (!$product) {
            $this->markTestSkipped('No published product to view.');
        }

        cache()->forget('detail.' . $product->id);

        $before = (int)\App\Models\ProductView::where('product_id', $product->id)->value('views');

        // The first is a cache miss, the next two are served from cache.
        $this->getJson('/api/v1/product/' . $product->id);
        $this->getJson('/api/v1/product/' . $product->id);
        $this->getJson('/api/v1/product/' . $product->id);

        $after = (int)\App\Models\ProductView::where('product_id', $product->id)->value('views');

        $this->assertSame($before + 3, $after,
            'cached views are still not counted');
    }

    /* ================================================================
     *  PUBLISH / UNPUBLISH FROM THE LIST
     * ================================================================ */

    /**
     * The Draft action on the Products grid changes the product's status.
     *
     * The regression this pins: the menu item pushed the router at the
     * product's edit page -- the same thing Edit and View already did -- so an
     * action called Draft changed nothing at all.
     */
    public function test_a_product_can_be_published_and_unpublished_from_the_list()
    {
        $this->admin();

        $product = $this->makeProduct(['status' => 1]);

        $this->postJson('/api/admin/product/update-status', [
            'id' => $product->id, 'status' => 2,
        ])->assertOk();

        $this->assertSame(2, (int)$product->fresh()->status);

        $this->postJson('/api/admin/product/update-status', [
            'id' => $product->id, 'status' => 1,
        ])->assertOk();

        $this->assertSame(1, (int)$product->fresh()->status);
    }

    public function test_a_status_change_takes_a_selection()
    {
        $this->admin();

        $first  = $this->makeProduct(['status' => 1]);
        $second = $this->makeProduct(['status' => 1]);

        $this->postJson('/api/admin/product/update-status', [
            'id' => $first->id . ',' . $second->id, 'status' => 2,
        ])->assertOk();

        $this->assertSame(2, (int)$first->fresh()->status);
        $this->assertSame(2, (int)$second->fresh()->status);
    }

    public function test_a_status_change_needs_product_edit()
    {
        $this->admin();
        $product = $this->makeProduct(['status' => 1]);

        $body = $this->without('product.edit', function () use ($product) {
            return $this->postJson('/api/admin/product/update-status', [
                'id' => $product->id, 'status' => 2,
            ])->json();
        });

        $this->assertNotSame(200, (int)($body['status'] ?? 200));
        $this->assertSame(1, (int)$product->fresh()->status);
    }

    /** A status with no meaning, or an id that is not there, changes nothing. */
    public function test_a_status_change_refuses_nonsense()
    {
        $this->admin();
        $product = $this->makeProduct(['status' => 1]);

        $bad = $this->postJson('/api/admin/product/update-status', [
            'id' => $product->id, 'status' => 7,
        ])->json();

        $this->assertNotSame(200, (int)($bad['status'] ?? 200));
        $this->assertSame(1, (int)$product->fresh()->status);

        $missing = $this->postJson('/api/admin/product/update-status', [
            'id' => $product->id . ',999999999', 'status' => 2,
        ])->json();

        $this->assertNotSame(200, (int)($missing['status'] ?? 200));
        $this->assertSame(1, (int)$product->fresh()->status,
            'a selection containing an unknown id still changed the others');
    }

    /* ================================================================
     *  WHAT MAY BE WRITTEN INTO THE UPLOADS DIRECTORY
     * ================================================================ */

    /**
     * The bulk editor will not write an executable file into the web root.
     *
     * The regression this pins: it built the filename from
     * $file->getClientOriginalName() -- entirely caller-controlled -- and moved
     * the upload straight into base_path('uploads'), which is served directly
     * and carries no .htaccess. Nothing checked what the file was, so a single
     * POST could drop a .php file into the web root and then request it.
     */
    public function test_the_bulk_editor_refuses_a_file_that_is_not_an_image()
    {
        $this->admin();

        $product = $this->makeProduct(['selling' => 100, 'status' => 1]);
        $wasImage = $product->image;

        $payload = UploadedFile::fake()->createWithContent(
            'shell.php', '<?php echo "pwned"; ?>'
        );

        $this->post('/api/admin/product/bulk-update', [
            'products' => json_encode([[
                'id' => $product->id, 'title' => $product->title, 'slug' => $product->slug,
                'selling' => 100, 'discount_type' => 'fixed', 'discount_value' => 0,
                'offered' => 0, 'status' => 1, 'upsell_id' => null,
            ]]),
            'images' => [$product->id => $payload],
        ], ['Accept' => 'application/json']);

        $this->assertFileDoesNotExist(base_path('uploads/shell.php'));

        foreach (glob(base_path('uploads/*shell*')) as $written) {
            @unlink($written);
            $this->fail('a file named after the upload was written: ' . $written);
        }

        $this->assertSame($wasImage, $product->fresh()->image,
            'the product took the rejected file as its image');
    }

    /** A real image still goes through, under a name the server chose. */
    public function test_the_bulk_editor_accepts_an_image_and_renames_it()
    {
        $this->admin();

        $product = $this->makeProduct(['selling' => 100, 'status' => 1]);

        $this->post('/api/admin/product/bulk-update', [
            'products' => json_encode([[
                'id' => $product->id, 'title' => $product->title, 'slug' => $product->slug,
                'selling' => 100, 'discount_type' => 'fixed', 'discount_value' => 0,
                'offered' => 0, 'status' => 1, 'upsell_id' => null,
            ]]),
            'images' => [$product->id => UploadedFile::fake()->image('hero.jpg', 400, 400)],
        ], ['Accept' => 'application/json'])->assertOk();

        $image = $product->fresh()->image;

        $this->assertNotSame('hero.jpg', $image, 'the client-supplied name was used');
        $this->assertMatchesRegularExpression('/^product-[0-9a-f-]{36}\.(jpg|jpeg|png|gif|webp)$/', $image);

        @unlink(base_path('uploads/' . $image));
        @unlink(base_path('uploads/' . env('THUMB_PREFIX') . $image));
    }

    /** The same rule on the feature-image endpoint. */
    public function test_feature_images_refuse_a_file_that_is_not_an_image()
    {
        $this->admin();
        $product = $this->makeProduct();

        $this->post('/api/admin/product/feature-images/' . $product->id, [
            'images' => [
                UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1; ?>'),
            ],
        ], ['Accept' => 'application/json']);

        $this->assertSame(0, ProductFeatureImage::where('product_id', $product->id)->count(),
            'a non-image was recorded as a feature image');

        $this->assertFileDoesNotExist(base_path('uploads/evil.php'));
    }

    /**
     * The admin listings do not cost a query per product.
     *
     * The regression this pins: Product's three $appends run once per
     * serialised row -- collection_background_image always queries, is_new
     * queries whenever created_at was not selected -- and no admin screen
     * reads any of the three. product/all cost 55 queries for 14 rows, and
     * with-categories cost 302 because it also hands back every product in the
     * catalogue for its picker. Five admin pages call with-categories on mount.
     *
     * Ceilings rather than exact figures: the eager loads and the admin guard
     * cost a fixed number either way. What matters is that neither tracks the
     * number of products.
     *
     * @dataProvider adminListEndpoints
     */
    public function test_an_admin_listing_does_not_cost_a_query_per_product($url, $ceiling)
    {
        $this->admin();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $response = $this->getJson($url);

        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertNotEmpty($response->json('data.data'), 'the list came back empty');

        $this->assertLessThan($ceiling, $queries,
            $url . ' now costs ' . $queries . ' queries');
    }

    public function adminListEndpoints()
    {
        return [
            'product list'    => ['/api/admin/product/all', 40],
            'with categories' => ['/api/admin/product/with-categories', 40],
        ];
    }

    /** with-categories still hands back the whole catalogue for its picker. */
    public function test_with_categories_still_returns_every_product_for_the_picker()
    {
        $this->admin();

        $body = $this->getJson('/api/admin/product/with-categories')->json();

        $this->assertSame(
            Product::count(),
            count($body['products'] ?? []),
            'the picker list lost products when it stopped using models'
        );

        $first = $body['products'][0] ?? [];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('title', $first);
    }
}
