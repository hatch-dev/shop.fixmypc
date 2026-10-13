<?php
namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\CompareList;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\RecentlyViewedProduct;
use App\Models\User;
use App\Models\UserWishlist;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Every surface that shows a product card, priced for whoever is looking.
 *
 * PricingConsistencyTest covers the shop listing, search and the product page.
 * This covers the rest — the wishlist, recently viewed, the compare list and a
 * flash sale — which rendered the same tile and never priced it at all, so an
 * entitled account was advertised the retail price on all four while the
 * product page, the basket and the till used its own rate.
 *
 * A tile advertises the price of buying one, so a band is applied at quantity
 * 1. That is the whole quantity story on a listing; the basket prices the
 * quantity the customer actually chose.
 */
class ListingPricingTest extends TestCase
{
    use DatabaseTransactions;

    private $product;

    /** A published product with a band that covers a single unit. */
    private function bandedProduct()
    {
        if ($this->product) {
            return $this->product;
        }

        $row = collect($this->getJson('/api/v1/products')->json('data.result.data'))
            ->first(function ($item) {
                return (float)($item['selling'] ?? 0) > 0;
            });

        $product = $row ? Product::find($row['id']) : null;

        if (!$product) {
            $this->markTestSkipped('No published product on the shop listing.');
        }

        BusinessProductPricing::where('product_id', $product->id)->delete();

        /*
         * A band from 1, because a tile is a quantity of one — a rate card
         * that starts at 5 would make every surface here show retail and the
         * assertions would pass without testing anything.
         */
        $band = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => round((float)$product->selling * 0.5, 2),
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]);
        $band->final_price = $band->computedFinalPrice();
        $band->save();

        return $this->product = $product->fresh();
    }

    private function bandPrice($product)
    {
        return Pricing::round((float)$product->selling * 0.5);
    }

    private function retailPrice($product)
    {
        return Pricing::round(Pricing::displayRange(
            Product::pricingRecord($product->id),
            $product->product_inventories,
            null
        )['min']);
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Listing Fixture',
            'email' => 'listing.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    /**
     * Who is entitled to a band and who is not — the same question
     * User::hasBusinessPricing answers for the basket and the till.
     */
    public function accounts()
    {
        return [
            'personal' => [['account_type' => 'personal'], false],
            'business' => [['account_type' => 'business'], true],
            'trade approved' => [
                ['account_type' => 'trade', 'trade_status' => User::TRADE_APPROVED], true
            ],
            'trade pending' => [
                ['account_type' => 'trade', 'trade_status' => User::TRADE_PENDING], false
            ],
            'trade rejected' => [
                ['account_type' => 'trade', 'trade_status' => User::TRADE_REJECTED], false
            ],
            'trade suspended' => [
                ['account_type' => 'trade', 'trade_status' => User::TRADE_APPROVED,
                 'status' => User::STATUS_SUSPENDED], false
            ],
            'business suspended' => [
                ['account_type' => 'business', 'status' => User::STATUS_SUSPENDED], false
            ],
        ];
    }

    /** flash_sales.admin_id is NOT NULL; any staff row will do as the author. */
    private function anyAdminId()
    {
        $id = \App\Models\Admin::query()->value('id');

        if (!$id) {
            $this->markTestSkipped('No admin row to author a flash sale with.');
        }

        return $id;
    }

    private function signIn($attributes)
    {
        $customer = $this->customer($attributes);

        Passport::actingAs($customer, ['user'], 'user');

        return $customer;
    }

    private function expected($product, $entitled)
    {
        return $entitled ? $this->bandPrice($product) : $this->retailPrice($product);
    }

    /** Pull one product's card price out of a listing response. */
    private function cardPrice($rows, $productId, $relation = null)
    {
        $row = collect($rows)->first(function ($item) use ($productId, $relation) {
            $product = $relation ? ($item[$relation] ?? null) : $item;

            return $product && (int)($product['id'] ?? 0) === (int)$productId;
        });

        if (!$row) {
            return null;
        }

        $product = $relation ? $row[$relation] : $row;

        return array_key_exists('display_price', $product)
            ? (float)$product['display_price']
            : null;
    }

    // ------------------------------------------------------- the shop listing

    /** @dataProvider accounts */
    public function test_the_shop_listing_prices_the_card($attributes, $entitled)
    {
        $product = $this->bandedProduct();

        $this->signIn($attributes);

        $this->assertSame(
            $this->expected($product, $entitled),
            $this->cardPrice(
                $this->getJson('/api/v1/products')->json('data.result.data'), $product->id
            )
        );
    }

    public function test_the_shop_listing_gives_a_guest_the_retail_price()
    {
        $product = $this->bandedProduct();

        $this->assertSame(
            $this->retailPrice($product),
            $this->cardPrice(
                $this->getJson('/api/v1/products')->json('data.result.data'), $product->id
            )
        );
    }

    /** @dataProvider accounts */
    public function test_search_prices_the_card($attributes, $entitled)
    {
        $product = $this->bandedProduct();

        $this->signIn($attributes);

        $this->assertSame(
            $this->expected($product, $entitled),
            $this->cardPrice(
                $this->getJson('/api/v1/search?q=' . urlencode($product->title))
                    ->json('data.product'),
                $product->id
            )
        );
    }

    // --------------------------------------------------------- the wishlist

    /** @dataProvider accounts */
    public function test_the_wishlist_prices_the_card($attributes, $entitled)
    {
        $product = $this->bandedProduct();

        $customer = $this->signIn($attributes);

        UserWishlist::create(['user_id' => $customer->id, 'product_id' => $product->id]);

        $rows = $this->getJson('/api/v1/user/wishlist/all')->json('data.data');

        $this->assertNotNull($rows, 'the wishlist did not answer');

        $this->assertSame(
            $this->expected($product, $entitled),
            $this->cardPrice($rows, $product->id, 'product'),
            'the wishlist tile'
        );
    }

    // --------------------------------------------------- recently viewed

    /** @dataProvider accounts */
    public function test_recently_viewed_prices_the_card($attributes, $entitled)
    {
        $product = $this->bandedProduct();

        $customer = $this->signIn($attributes);

        RecentlyViewedProduct::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
        ]);

        $response = $this->getJson('/api/v1/user/recently-viewed');

        /*
         * A suspended account is refused the account area outright, so there
         * is no tile to price. That is the correct answer, not a gap — assert
         * it rather than stepping over it.
         */
        if (($attributes['status'] ?? null) === User::STATUS_SUSPENDED) {
            $this->assertNotSame(
                200, $response->status(),
                'a suspended account reached the account area'
            );
            return;
        }

        $response->assertStatus(200);

        $this->assertSame(
            $this->expected($product, $entitled),
            $this->cardPrice($response->json('data.data'), $product->id, 'product'),
            'the recently viewed tile'
        );
    }

    // ------------------------------------------------------ the compare list

    /** @dataProvider accounts */
    public function test_the_compare_list_prices_the_card($attributes, $entitled)
    {
        $product = $this->bandedProduct();

        $customer = $this->signIn($attributes);

        CompareList::create(['user_id' => $customer->id, 'product_id' => $product->id]);

        $response = $this->getJson('/api/v1/user/compare-list/all?order_by=id&type=desc');

        // As above: a suspended account has no account area to be priced in.
        if (($attributes['status'] ?? null) === User::STATUS_SUSPENDED) {
            $this->assertNotSame(200, $response->status());
            return;
        }

        $response->assertStatus(200);

        $this->assertSame(
            $this->expected($product, $entitled),
            $this->cardPrice($response->json('data.data'), $product->id, 'product'),
            'the compare tile'
        );
    }

    // ------------------------------------------- listing agrees with the page

    /** @dataProvider accounts */
    public function test_the_card_and_the_product_page_agree($attributes, $entitled)
    {
        $product = $this->bandedProduct();

        $this->signIn($attributes);

        $card = $this->cardPrice(
            $this->getJson('/api/v1/products')->json('data.result.data'), $product->id
        );

        $page = (float)$this->getJson("/api/v1/product/{$product->id}")
            ->json('data.display_price');

        $this->assertSame($card, $page, 'the card and the product page disagree');
    }

    /**
     * A tile is one unit. The basket's quantity must not leak into it — a
     * customer with 30 in the basket still sees the single-unit price on a
     * category page.
     */
    public function test_a_card_is_priced_at_one_unit_not_at_the_basket_quantity()
    {
        $product = $this->bandedProduct();

        // A second, deeper band that only a large order reaches.
        $deep = new BusinessProductPricing([
            'product_id' => $product->id,
            'min_qty' => 10,
            'max_qty' => 0,
            'wholesale_price' => round((float)$product->selling * 0.25, 2),
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]);
        $deep->final_price = $deep->computedFinalPrice();
        $deep->save();

        // The 1+ band now has to stop at 9, or the two overlap.
        BusinessProductPricing::where('product_id', $product->id)
            ->where('min_qty', 1)
            ->update(['max_qty' => 9]);

        $this->signIn(['account_type' => 'business']);

        $this->assertSame(
            $this->bandPrice($product),
            $this->cardPrice(
                $this->getJson('/api/v1/products')->json('data.result.data'), $product->id
            ),
            'the card was priced at a quantity nobody asked for'
        );
    }

    // ------------------------------------------------------- a flash sale

    /**
     * A sale price and a quantity band are not alternatives.
     *
     * Pricing::displayRange settles the precedence: the sale price overrides
     * the retail ladder, and a band may take it lower still but never raises
     * it — so a trade account is not quietly charged more than a walk-up
     * shopper during a promotion. The flash sale page applied neither.
     */
    public function test_a_flash_sale_card_is_priced_for_the_customer()
    {
        $product = $this->bandedProduct();

        $sale = \App\Models\FlashSale::create([
            'title' => 'Listing fixture sale',
            'status' => 1,
            'admin_id' => $this->anyAdminId(),
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);

        // A sale price between the band price and retail, so the three
        // possible answers are all different and the test can tell them apart.
        $salePrice = Pricing::round($this->bandPrice($product) * 1.2);

        \App\Models\FlashSaleProduct::create([
            'flash_sale_id' => $sale->id,
            'product_id' => $product->id,
            'admin_id' => $this->anyAdminId(),
            'price' => $salePrice,
        ]);

        $read = function () use ($sale, $product) {
            return $this->cardPrice(
                $this->getJson("/api/v1/flash-sale/{$sale->id}")->json('data.data'),
                $product->id
            );
        };

        // A guest and a personal account pay the sale price.
        $this->assertSame($salePrice, $read(), 'a guest');

        Passport::actingAs($this->customer(), ['user'], 'user');
        $this->assertSame($salePrice, $read(), 'a personal account');

        // A business account's band undercuts it, so the band wins.
        $this->signIn(['account_type' => 'business']);
        $this->assertSame($this->bandPrice($product), $read(), 'a business account');
    }

    public function test_a_flash_sale_never_charges_a_band_customer_more()
    {
        $product = $this->bandedProduct();

        $sale = \App\Models\FlashSale::create([
            'title' => 'Deep sale',
            'status' => 1,
            'admin_id' => $this->anyAdminId(),
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);

        // This time the sale is cheaper than the agreed wholesale rate.
        $salePrice = Pricing::round($this->bandPrice($product) * 0.5);

        \App\Models\FlashSaleProduct::create([
            'flash_sale_id' => $sale->id,
            'product_id' => $product->id,
            'admin_id' => $this->anyAdminId(),
            'price' => $salePrice,
        ]);

        $this->signIn(['account_type' => 'business']);

        $this->assertSame(
            $salePrice,
            $this->cardPrice(
                $this->getJson("/api/v1/flash-sale/{$sale->id}")->json('data.data'),
                $product->id
            ),
            'the band was applied over a cheaper sale price'
        );
    }

    // ----------------------------------------------------------- the cache

    /**
     * Listing responses are shared. The price has to be decided per request,
     * or whoever loads the page first decides what everyone else is quoted.
     */
    public function test_a_business_visit_does_not_leave_its_price_behind()
    {
        $product = $this->bandedProduct();

        $this->signIn(['account_type' => 'business']);

        $banded = $this->cardPrice(
            $this->getJson('/api/v1/products')->json('data.result.data'), $product->id
        );

        Passport::actingAs($this->customer(), ['user'], 'user');

        $retail = $this->cardPrice(
            $this->getJson('/api/v1/products')->json('data.result.data'), $product->id
        );

        $this->assertSame($this->bandPrice($product), $banded);
        $this->assertSame(
            $this->retailPrice($product), $retail,
            'a personal account was served the business visit price'
        );
    }

    public function test_a_guest_visit_does_not_deny_a_business_account_its_price()
    {
        $product = $this->bandedProduct();

        $guest = $this->cardPrice(
            $this->getJson('/api/v1/products')->json('data.result.data'), $product->id
        );

        $this->signIn(['account_type' => 'business']);

        $banded = $this->cardPrice(
            $this->getJson('/api/v1/products')->json('data.result.data'), $product->id
        );

        $this->assertSame($this->retailPrice($product), $guest);
        $this->assertSame($this->bandPrice($product), $banded);
    }

    // --------------------------------------------- filters, sorting, paging

    /**
     * The price is decided from the rows a query returns, so it cannot depend
     * on which rows those are — but the filters each take their own code path
     * through the listing, and a path that skipped the decoration would send
     * the card back to retail on the second click.
     */
    public function test_filtering_sorting_and_paging_keep_the_price()
    {
        $product = $this->bandedProduct();

        $this->signIn(['account_type' => 'business']);

        $expected = $this->bandPrice($product);

        $queries = [
            '',
            '?page=1',
            '?per_page=50',
            '?orderby=price&type=asc',
            '?orderby=price&type=desc',
            '?orderby=created_at&type=desc',
            '?q=' . urlencode($product->title),
            '?in_stock=1',
            '?brand=' . (int)$product->brand_id,
        ];

        foreach ($queries as $query) {
            $rows = $this->getJson('/api/v1/products' . $query)->json('data.result.data');

            $price = $this->cardPrice($rows, $product->id);

            if (is_null($price)) {
                // The filter excluded the fixture; nothing to compare.
                continue;
            }

            $this->assertSame($expected, $price, 'listing' . $query);
        }
    }

    // ------------------------------------------------ the home collections row

    /**
     * The home collections row is a listing like any other.
     *
     * It renders the shared tile now, and a tile reads the decoration: the
     * price for the customer looking at it, and the VAT split beside it. The
     * row itself is only a join record - product_collections carries a title
     * and a price and nothing else - so everything the tile needs hangs off
     * the nested product, and that is what has to be decorated.
     *
     * @dataProvider accounts
     */
    public function test_the_home_collections_price_the_card($attributes, $entitled)
    {
        $this->signIn($attributes);

        $rows = $this->homeCollectionRows();

        if (empty($rows)) {
            $this->markTestSkipped('No home collection holds any product.');
        }

        /*
         * Compared against the shop listing rather than against a figure
         * restated here. The listing is already proven correct for each
         * account type by the tests above, and "the same product costs the
         * same on the home page as in the category" is the actual requirement
         * — a promotional row quietly serving retail to a banded customer is
         * the defect this guards.
         */
        $listing = $this->getJson('/api/v1/products?limit=100')->json('data.result.data');

        $compared = 0;

        foreach ($rows as $row) {
            $productId = (int)($row['product']['id'] ?? 0);

            if (!$productId) {
                continue;
            }

            $onListing = $this->cardPrice($listing, $productId);

            if (is_null($onListing)) {
                // Not on the page requested; nothing to compare it with.
                continue;
            }

            $this->assertSame(
                $onListing,
                $this->cardPrice([$row], $productId, 'product'),
                "home collection and shop listing disagree on product {$productId}"
            );

            $compared++;
        }

        $this->assertGreaterThan(
            0, $compared, 'at least one collection card was compared'
        );
    }

    /**
     * And carries the VAT split the tile prints beside that price.
     *
     * Without these the collections row is the one place on the storefront
     * that quotes a price and cannot say which side of VAT it sits on - and
     * this catalogue runs both conventions.
     */
    public function test_the_home_collections_carry_the_vat_split()
    {
        $rows = $this->homeCollectionRows();

        if (empty($rows)) {
            $this->markTestSkipped('No home collection holds any product.');
        }

        foreach ($rows as $row) {
            $product = $row['product'] ?? null;

            if (!$product) {
                continue;
            }

            foreach ([
                'display_price', 'display_price_ex_vat', 'display_price_inc_vat',
                'vat_rate', 'vat_inclusive', 'in_stock',
            ] as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $product,
                    "collection product {$product['id']} must carry {$field}"
                );
            }
        }
    }

    /**
     * The VAT figures a tile prints are the server's, and they agree.
     *
     * The tile renders "Inc-VAT €859.77 (VAT 23% €160.77)" straight from these
     * fields — it never applies a rate itself, because the catalogue runs two
     * conventions and more than one rate. So the contract is that the three
     * figures reconcile and that the rate is the product's own rule, whatever
     * that rule happens to be.
     *
     * @dataProvider accounts
     */
    public function test_the_listing_vat_figures_reconcile($attributes, $entitled)
    {
        $this->signIn($attributes);

        $rows = $this->getJson('/api/v1/products?limit=50')->json('data.result.data');

        $checked = 0;

        foreach (($rows ?: []) as $row) {
            $rate = (float)($row['vat_rate'] ?? 0);
            $ex = (float)($row['display_price_ex_vat'] ?? 0);
            $vat = (float)($row['display_price_vat'] ?? 0);
            $inc = (float)($row['display_price_inc_vat'] ?? 0);

            $this->assertEqualsWithDelta(
                $ex + $vat, $inc, 0.011,
                "product {$row['id']}: ex + VAT must equal inc"
            );

            /*
             * Which side the advertised price sits on decides which of the two
             * the tile leads with, so it has to be the one the customer is
             * actually quoted.
             */
            $this->assertEqualsWithDelta(
                $row['vat_inclusive'] ? $inc : $ex,
                (float)$row['display_price'],
                0.011,
                "product {$row['id']}: the advertised price must be the side it is quoted on"
            );

            if ($rate <= 0) {
                // Zero-rated or margin scheme: no tax either way, nothing to show.
                $this->assertEqualsWithDelta(0.0, $vat, 0.005);
                $this->assertEqualsWithDelta($ex, $inc, 0.005);
            } else {
                $this->assertEqualsWithDelta(
                    Pricing::vatBreakdown(
                        (float)$row['display_price'], $rate, (bool)$row['vat_inclusive']
                    )['vat'],
                    $vat,
                    0.011,
                    "product {$row['id']}: the VAT shown is not the VAT the rule gives"
                );
            }

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'no listing rows were checked');
    }

    /** Every product row across every collection the home page returns. */
    private function homeCollectionRows()
    {
        $collections = $this->getJson('/api/v1/home')
            ->assertStatus(200)
            ->json('data.collections');

        $rows = [];

        foreach (($collections ?: []) as $collection) {
            foreach (($collection['product_collections'] ?? []) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
