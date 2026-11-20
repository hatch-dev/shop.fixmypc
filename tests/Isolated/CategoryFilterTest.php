<?php

namespace Tests\Isolated;

use App\Models\Helper\CategoryProducts;
use App\Models\Product;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Storefront category listing: parent categories and subcategories
 * (GET api/v1/all?category=<slug>, and the products endpoint's filter).
 *
 * The product form files a product's categories in product_categories and its
 * subcategories in product_subcategories. The listing and its counts read
 * product_categories for the one category only, so a subcategory such as
 * "Laptops > MacBook" listed nothing and counted 0, and a parent did not list
 * what was filed under its subcategories.
 */
class CategoryFilterTest extends IsolatedTestCase
{
    private $parent;
    private $mac;
    private $gaming;
    private $other;

    protected function setUp(): void
    {
        parent::setUp();
        CategoryProducts::flush();
        config(['constants.listing.PAGINATION' => 5]);

        $this->parent = $this->category('Test Laptops', 'test-laptops', null);
        $this->mac = $this->category('Test MacBook', 'test-macbook', $this->parent);
        $this->gaming = $this->category('Test Gaming', 'test-gaming', $this->parent);
        $this->other = $this->category('Test Audio', 'test-audio', null);
    }

    private function category($title, $slug, $parent)
    {
        return DB::table('categories')->insertGetId([
            'title' => $title, 'slug' => $slug, 'parent' => $parent, 'status' => 1, 'in_frontend' => 1,
            'featured' => 2, 'in_footer' => 2, 'admin_id' => Fixture::ADMIN_SUPER_ID,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product($n)
    {
        $copy = Product::withoutGlobalScopes()->findOrFail(Fixture::PRODUCT_LAPTOP_ID)->replicate();
        $copy->title = 'Category test product ' . $n;
        $copy->slug = 'category-test-product-' . $n . '-' . uniqid();
        $copy->status = 1;
        $copy->save();

        return $copy->id;
    }

    /** As the product form saves it: the parent ticked, the subcategory under it. */
    private function fileUnderSubcategory($productId, $sub)
    {
        DB::table('product_categories')->insert(['product_id' => $productId, 'category_id' => $this->parent, 'primary' => 0]);
        DB::table('product_subcategories')->insert(['product_id' => $productId, 'category_id' => $this->parent, 'subcategory_id' => $sub]);
    }

    private function fileUnder($productId, $categoryId)
    {
        DB::table('product_categories')->insert(['product_id' => $productId, 'category_id' => $categoryId, 'primary' => 0]);
    }

    /** Every product id the listing returns across all its pages, plus what page 1 said. */
    private function listing($slug)
    {
        $ids = [];
        $page = 1;
        do {
            $reply = $this->asGuest()->getJson('/api/v1/all?category=' . $slug . '&page=' . $page);
            $this->assertApiOk($reply);
            $result = $reply->json('data.result');
            $first = $first ?? ['total' => $result['total'], 'per_page' => $result['per_page'], 'last_page' => $result['last_page'],
                'count' => $reply->json('data.category.products_count'),
                'children' => collect($reply->json('data.category.child'))->pluck('products_count', 'id')->all()];
            foreach ($result['data'] as $row) {
                $ids[] = $row['id'];
            }
            $page++;
        } while ($page <= $result['last_page']);

        return [$ids, $first];
    }

    public function test_parent_subcategories_counts_and_pagination()
    {
        $mac = [];
        for ($i = 0; $i < 7; $i++) {
            $mac[] = $id = $this->product('mac' . $i);
            $this->fileUnderSubcategory($id, $this->mac);
        }
        $gaming = [];
        for ($i = 0; $i < 2; $i++) {
            $gaming[] = $id = $this->product('gaming' . $i);
            $this->fileUnder($id, $this->gaming);          // filed under the child directly
        }
        $parentOnly = [];
        for ($i = 0; $i < 3; $i++) {
            $parentOnly[] = $id = $this->product('laptop' . $i);
            $this->fileUnder($id, $this->parent);
        }
        $audio = [];
        for ($i = 0; $i < 2; $i++) {
            $audio[] = $id = $this->product('audio' . $i);
            $this->fileUnder($id, $this->other);
        }
        $both = $this->product('mac-and-audio');               // in two categories
        $this->fileUnderSubcategory($both, $this->mac);
        $this->fileUnder($both, $this->other);

        // MacBook: only MacBook products, across two pages of 5, counted the same
        [$ids, $meta] = $this->listing('test-macbook');
        $this->assertEqualsCanonicalizing(array_merge($mac, [$both]), $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'no product repeated across pages');
        $this->assertSame(8, $meta['total']);
        $this->assertSame(2, $meta['last_page']);
        $this->assertSame(8, $meta['count']);

        // Gaming (filed under the child in product_categories): only its products
        [$ids, $meta] = $this->listing('test-gaming');
        $this->assertEqualsCanonicalizing($gaming, $ids);
        $this->assertSame(2, $meta['count']);

        // Laptops: itself and both subcategories, each product once
        [$ids, $meta] = $this->listing('test-laptops');
        $this->assertEqualsCanonicalizing(array_merge($mac, [$both], $gaming, $parentOnly), $ids);
        $this->assertSame(13, $meta['total']);
        $this->assertSame(13, $meta['count']);
        $this->assertSame(8, $meta['children'][$this->mac]);
        $this->assertSame(2, $meta['children'][$this->gaming]);

        // another parent: none of the laptops, but the product filed in both
        [$ids, $meta] = $this->listing('test-audio');
        $this->assertEqualsCanonicalizing(array_merge($audio, [$both]), $ids);
        $this->assertSame(3, $meta['count']);

        // the sidebar/menu list carries the same counts
        $nav = collect($this->asGuest()->getJson('/api/v1/common')->json('data.nav_categories'))->keyBy('id');
        if ($nav->has($this->parent)) {
            $this->assertSame(13, $nav[$this->parent]['products_count']);
            $this->assertSame(8, collect($nav[$this->parent]['child'])->keyBy('id')[$this->mac]['products_count']);
        }
    }

    public function test_the_products_endpoint_filters_the_same_way()
    {
        $inMac = $this->product('p-mac');
        $this->fileUnderSubcategory($inMac, $this->mac);
        $inParent = $this->product('p-laptop');
        $this->fileUnder($inParent, $this->parent);

        $ids = collect($this->asGuest()->getJson('/api/v1/products?category=test-macbook&page=1')->json('data.result.data'))->pluck('id')->all();
        $this->assertSame([$inMac], $ids);

        $ids = collect($this->asGuest()->getJson('/api/v1/products?category=test-laptops&page=1')->json('data.result.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$inMac, $inParent], $ids);
    }

    public function test_an_unknown_category_lists_nothing_instead_of_failing()
    {
        $reply = $this->asGuest()->getJson('/api/v1/all?category=no-such-category&page=1');

        $this->assertApiOk($reply);
        $this->assertSame(0, $reply->json('data.result.total'));
    }
}
