<?php
namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Helper\AccountStatus;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The same product priced through every surface a customer sees it on, for
 * each account type. The point is not that any one number is right but that
 * they all agree: the tile, the product page, search, the basket and the
 * figure checkout would charge.
 *
 * They did not. Listings, search and the product page all showed retail to
 * everyone, and only the basket knew about quantity bands.
 */
class PricingConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    private $product;

    private function banded()
    {
        if ($this->product) {
            return $this->product;
        }

        /*
         * Taken from the shop listing itself rather than straight out of the
         * products table, so it is certainly a product that appears on the
         * surfaces being compared. Picking one by query gave a product that
         * was not on the first page and not in the search index, and the
         * listing and search assertions quietly checked nothing.
         */
        $row = collect($this->getJson('/api/v1/products')->json('data.result.data'))
            ->first(function ($item) {
                return (float)($item['selling'] ?? 0) > 0;
            });

        $product = $row ? Product::find($row['id']) : null;

        if (!$product) {
            $this->markTestSkipped('No published product on the shop listing to price against.');
        }

        BusinessProductPricing::create([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => round($product->selling * 0.5, 2),
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'final_price' => round($product->selling * 0.5, 2),
        ]);

        return $this->product = $product;
    }

    /**
     * What a shopper with no band is advertised.
     *
     * NOT products.selling. A tile quotes the cheapest price the shopper can
     * actually pay, which is the offer price where there is one, the cheapest
     * purchasable variant on a variable product, and the sale price while a
     * flash sale is running. Asserting products.selling meant asserting a
     * figure the shop has never quoted -- it passed only while the fixture
     * happened to have no offer and no sale, and turned red the moment a
     * promotion was switched on, with nothing wrong in the code.
     *
     * The same call ListingPricingTest uses, and the same one the surfaces
     * themselves go through, so what this test actually holds is the thing it
     * was written for: that the product page, the listing and search all say
     * the same thing, and that none of them leaks a band price to someone who
     * has not earned one.
     */
    private function retailDisplay($product)
    {
        return Pricing::round(Pricing::displayRange(
            Product::pricingRecord($product->id),
            $product->product_inventories,
            null
        )['min']);
    }

    /** What the band configured by banded() would charge. */
    private function bandPrice($product)
    {
        return round((float)$product->selling * 0.5, 2);
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Pricing Fixture',
            'email' => 'price.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    /** The headline price on the product page, as the storefront reads it. */
    private function detailPrice($product)
    {
        $response = $this->getJson("/api/v1/product/{$product->id}");

        $response->assertStatus(200);

        return (float)$response->json('data.display_price');
    }

    /** The price on the shop listing tile. */
    private function listingPrice($product)
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200);

        $row = collect($response->json('data.result.data'))
            ->firstWhere('id', $product->id);

        return $row ? (float)$row['display_price'] : null;
    }

    /** The price on a search result. */
    private function searchPrice($product)
    {
        $response = $this->getJson('/api/v1/search?q=' . urlencode($product->title));

        $response->assertStatus(200);

        $row = collect($response->json('data.product'))
            ->firstWhere('id', $product->id);

        return $row ? (float)$row['display_price'] : null;
    }

    /**
     * Every surface at once, so the test fails if any one of them drifts from
     * the others rather than only when a particular page is wrong.
     */
    private function assertEverySurfaceShows($expected, $product)
    {
        $this->assertSame($expected, $this->detailPrice($product), 'product page');

        /*
         * Asserted, not guarded. A missing row here means the comparison did
         * not happen, which is the failure mode this test exists to rule out.
         */
        $this->assertSame($expected, $this->listingPrice($product), 'shop listing');
        $this->assertSame($expected, $this->searchPrice($product), 'search results');
    }

    /**
     * The invariant the retail assertions rest on, stated on its own.
     *
     * retailDisplay() goes through the same helper the surfaces do, so on its
     * own it could only prove they agree with each other. This is the part
     * that cannot be satisfied by agreeing: the figure quoted to someone with
     * no entitlement must not be the band price, however the band is
     * configured. It is what the old products.selling expectation was reaching
     * for, without also asserting a figure the shop never quotes.
     */
    public function test_a_shopper_with_no_band_is_never_quoted_the_band_price()
    {
        $product = $this->banded();

        $retail = $this->retailDisplay($product);
        $band = $this->bandPrice($product);

        $this->assertGreaterThan(
            $band, $retail,
            'the band does not undercut retail, so this fixture proves nothing'
        );

        // A guest first, then a personal account: neither has earned a band.
        $this->assertSame($retail, $this->detailPrice($product), 'guest, product page');
        $this->assertSame($retail, $this->listingPrice($product), 'guest, shop listing');
        $this->assertSame($retail, $this->searchPrice($product), 'guest, search results');

        Passport::actingAs($this->customer(), ['user'], 'user');

        $this->assertSame($retail, $this->detailPrice($product), 'personal, product page');
        $this->assertSame($retail, $this->listingPrice($product), 'personal, shop listing');
        $this->assertSame($retail, $this->searchPrice($product), 'personal, search results');
    }

    public function test_every_surface_shows_a_business_account_the_same_price()
    {
        $product = $this->banded();

        Passport::actingAs($this->customer(['account_type' => 'business']), ['user'], 'user');

        $this->assertEverySurfaceShows(round((float)$product->selling * 0.5, 2), $product);
    }

    public function test_every_surface_shows_a_guest_the_retail_price()
    {
        $product = $this->banded();

        $this->assertEverySurfaceShows($this->retailDisplay($product), $product);
    }

    public function test_every_surface_shows_a_pending_trade_account_retail()
    {
        $product = $this->banded();

        $customer = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]);

        Passport::actingAs($customer, ['user'], 'user');

        $this->assertEverySurfaceShows($this->retailDisplay($product), $product);
    }

    public function test_a_guest_sees_the_retail_price_on_the_product_page()
    {
        $product = $this->banded();

        $this->assertSame($this->retailDisplay($product), $this->detailPrice($product));
    }

    public function test_a_personal_account_sees_the_retail_price_on_the_product_page()
    {
        $product = $this->banded();

        Passport::actingAs($this->customer(), ['user'], 'user');

        $this->assertSame($this->retailDisplay($product), $this->detailPrice($product));
    }

    public function test_a_business_account_sees_its_own_price_on_the_product_page()
    {
        $product = $this->banded();

        Passport::actingAs($this->customer(['account_type' => 'business']), ['user'], 'user');

        $this->assertSame(round((float)$product->selling * 0.5, 2), $this->detailPrice($product));
    }

    public function test_a_pending_trade_account_sees_the_retail_price()
    {
        $product = $this->banded();

        $customer = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]);

        Passport::actingAs($customer, ['user'], 'user');

        $this->assertSame($this->retailDisplay($product), $this->detailPrice($product));
    }

    public function test_an_approved_trade_account_sees_its_own_price()
    {
        $product = $this->banded();

        $customer = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
        ]);

        Passport::actingAs($customer, ['user'], 'user');

        $this->assertSame(round((float)$product->selling * 0.5, 2), $this->detailPrice($product));
    }

    public function test_a_suspended_business_account_is_back_to_retail()
    {
        $product = $this->banded();

        $customer = $this->customer([
            'account_type' => 'business',
            'status' => User::STATUS_SUSPENDED,
        ]);

        Passport::actingAs($customer, ['user'], 'user');

        $this->assertSame($this->retailDisplay($product), $this->detailPrice($product));
    }
}
