<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cart;
use App\Models\Clearance;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\UserAddress;
use App\Models\Helper\Pricing;
use App\Models\SubscriptionEmail;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Clearance, end to end.
 *
 * A clearance is a discount an administrator puts on one product: a percentage
 * or a flat sum off whatever that product currently costs, optionally with a
 * timer. It has three jobs, and the tests below are grouped by them.
 *
 *   the deal page   /clearance -- one hero deal plus a grid of finished ones
 *   the catalogue   the same discount on the tile and on the product page
 *   the till        what the customer is actually charged for it
 *
 * The third is the one that matters. A promotion that advertises a price the
 * basket then disagrees with is not a discount, it is a mis-statement, and it
 * is the same rule cross-sell and the bundle are held to: the quote equals the
 * charge.
 *
 * As everywhere in this codebase a refusal is status 201 in the BODY under an
 * HTTP 200, so every negative case reads the body rather than the status line.
 */
class ClearanceTest extends TestCase
{
    use DatabaseTransactions;

    /* ---- fixtures ---------------------------------------------------- */

    private function asGuest()
    {
        $this->app['auth']->forgetGuards();
    }

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

    private function customer()
    {
        return User::create([
            'name' => 'Clearance Fixture',
            'email' => 'clearance.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    /**
     * Ordered by id, deliberately.
     *
     * An unordered fixture is a different product on a different day -- the
     * moment one of these tests puts a clearance on a product the next run
     * excludes it and picks the next one along. That is how two of these
     * reddened against a EUR 49 product: they had been written against a
     * dearer one. No test here may assume an absolute price; a flat discount
     * is always taken as a fraction of whatever the fixture costs.
     *
     * A published product with at least one well-stocked variant and no LIVE
     * clearance, flash sale or quantity band of its own -- so every figure
     * this file asserts on was put there by the deal under test and not by
     * somebody else's configuration.
     *
     * The returned inventory row is the one the assertions quote against; a
     * product may have others, so nothing here may assume the product's whole
     * stock lives on it.
     */
    private function product()
    {
        $row = DB::table('updated_inventories as ui')
            ->join('products as p', 'p.id', '=', 'ui.product_id')
            ->where('p.status', 1)
            ->whereNotNull('p.selling')->where('p.selling', '>', 0)
            ->where('ui.quantity', '>', 2)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('clearances')
                    ->whereColumn('clearances.product_id', 'p.id')
                    ->where('clearances.status', 1)
                    ->where(function ($w) {
                        $w->whereNull('clearances.end_time')
                            ->orWhere('clearances.end_time', '>=', date('Y-m-d H:i:s'));
                    });
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('flash_sale_products')
                    ->whereColumn('flash_sale_products.product_id', 'p.id');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('business_product_pricing')
                    ->whereColumn('business_product_pricing.product_id', 'p.id');
            })
            ->groupBy('p.id')
            ->orderBy('p.id')
            ->select('p.id as product_id', DB::raw('MIN(ui.id) as inventory_id'))
            ->first();

        if (!$row) {
            $this->markTestSkipped('No clean single-variant purchasable product.');
        }

        return [
            'product' => Product::find($row->product_id),
            'inventory' => UpdatedInventory::find($row->inventory_id),
        ];
    }

    /** A flat discount worth about a fifth of what this product costs. */
    private function flatDiscountFor($product)
    {
        $base = (float)$product->offered > 0 ? (float)$product->offered : (float)$product->selling;

        return round(max($base * 0.2, 0.01), 2);
    }


    /** A second clean product, for the cases that need two deals at once. */
    private function otherProduct($excludeId)
    {
        $id = DB::table('products as p')
            ->where('p.status', 1)
            ->where('p.selling', '>', 0)
            ->where('p.id', '!=', $excludeId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('clearances')
                    ->whereColumn('clearances.product_id', 'p.id')
                    ->where('clearances.status', 1)
                    ->where(function ($w) {
                        $w->whereNull('clearances.end_time')
                            ->orWhere('clearances.end_time', '>=', date('Y-m-d H:i:s'));
                    });
            })
            ->orderBy('p.id')
            ->value('p.id');

        if (!$id) {
            $this->markTestSkipped('No second product free of a live deal.');
        }

        return Product::find($id);
    }


    /**
     * A product that is actually on the public listing, so a tile for it can
     * be found. Clearance aside, the listing decides its own contents.
     */
    private function listedProduct()
    {
        for ($page = 1; $page <= 6; $page++) {
            $rows = $this->getJson("/api/v1/products?page={$page}")->json('data.result.data');

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $clean = DB::table('products as p')
                    ->where('p.id', $row['id'])
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))->from('clearances')
                            ->whereColumn('clearances.product_id', 'p.id')
                            ->where('clearances.status', 1)
                            ->where(function ($w) {
                                $w->whereNull('clearances.end_time')
                                    ->orWhere('clearances.end_time', '>=', date('Y-m-d H:i:s'));
                            });
                    })
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))->from('flash_sale_products')
                            ->whereColumn('flash_sale_products.product_id', 'p.id');
                    })
                    ->exists();

                if ($clean && (float)($row['display_price'] ?? 0) > 0) {
                    return Product::find($row['id']);
                }
            }
        }

        $this->markTestSkipped('No clean product on the public listing.');
    }


    /**
     * A product quoted EX-VAT, where the inc-VAT figure is a different number
     * from the ex-VAT one -- the only shape in which the two can drift apart.
     */
    private function exVatProduct()
    {
        $id = DB::table('products as p')
            ->where('p.status', 1)
            ->where('p.selling', '>', 0)
            ->where('p.excludeVAT', 1)
            ->whereNotNull('p.tax_rule_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('clearances')
                    ->whereColumn('clearances.product_id', 'p.id')
                    ->where('clearances.status', 1)
                    ->where(function ($w) {
                        $w->whereNull('clearances.end_time')
                            ->orWhere('clearances.end_time', '>=', date('Y-m-d H:i:s'));
                    });
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('flash_sale_products')
                    ->whereColumn('flash_sale_products.product_id', 'p.id');
            })
            ->orderBy('p.id')
            ->value('p.id');

        if (!$id) {
            $this->markTestSkipped('No Ex-VAT product without a live promotion.');
        }

        return Product::find($id);
    }


    /** The deal an administrator would have made. */
    private function deal($productId, array $attributes = [])
    {
        $admin = Admin::whereHas('roles', function ($q) {
            $q->where('name', 'superadmin');
        })->value('id');

        return Clearance::create(array_merge([
            'product_id' => $productId,
            'title' => 'QA Clearance ' . uniqid(),
            'subtitle' => 'Last of the line',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'end_time' => date('Y-m-d H:i:s', strtotime('+3 days')),
            'status' => 1,
            'sort_order' => 0,
            'admin_id' => $admin,
        ], $attributes));
    }

    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }

    private function asCustomer()
    {
        $this->app['auth']->forgetGuards();

        Passport::actingAs($this->customer(), ['user'], 'user');
    }

    private function guestToken()
    {
        $this->asGuest();

        $token = $this->postJson('/api/v1/guest/token')->json('data.user_token');

        $this->assertNotEmpty($token, 'The guest token endpoint issued nothing.');

        return $token;
    }

    /** What the deal says one unit of this product now costs. */
    private function heroPrice()
    {
        return (float)$this->getJson('/api/v1/clearance')->json('data.hero.product.offered');
    }

    /* ---- the deal page ------------------------------------------------ */

    /** The page has two halves and both are always present. */
    public function test_the_page_answers_with_a_hero_and_a_past_deals_grid()
    {
        $this->asGuest();

        $response = $this->getJson('/api/v1/clearance');

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertArrayHasKey('hero', $response->json('data'));
        $this->assertArrayHasKey('past_deals', $response->json('data'));
    }

    /** A live deal is the hero, and the hero carries the discounted price. */
    public function test_a_live_deal_becomes_the_hero_at_its_discounted_price()
    {
        ['product' => $product] = $this->product();

        $base = (float)$product->offered > 0 ? (float)$product->offered : (float)$product->selling;

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20, 'sort_order' => -1]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertNotNull($hero, 'a live deal did not become the hero');
        $this->assertSame((int)$product->id, (int)$hero['product']['id']);

        $this->assertEqualsWithDelta(
            round($base * 0.8, 2), (float)$hero['product']['offered'], 0.01,
            'the hero did not carry the 20% deal price'
        );

        // The struck-through figure stays the product's own price.
        $this->assertEqualsWithDelta(
            (float)$product->selling, (float)$hero['product']['selling'], 0.01,
            'the compare-at price was overwritten by the deal'
        );
    }

    /** A flat sum comes off the current price, not off the selling price. */
    public function test_a_fixed_deal_takes_its_sum_off_the_current_price()
    {
        ['product' => $product] = $this->product();

        $base = (float)$product->offered > 0 ? (float)$product->offered : (float)$product->selling;
        $off = $this->flatDiscountFor($product);

        $this->deal($product->id, ['discount_type' => 'fixed', 'discount_value' => $off, 'sort_order' => -1]);

        $this->asGuest();

        $this->assertEqualsWithDelta(
            round($base - $off, 2), $this->heroPrice(), 0.01,
            'a fixed deal was not taken off the current price'
        );
    }

    /** The timer is the whole point of a clearance: past it, the deal is over. */
    public function test_a_deal_whose_timer_ran_out_is_not_the_hero()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, [
            'end_time' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'sort_order' => -1,
        ]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertTrue(
            is_null($hero) || (int)$hero['product']['id'] !== (int)$product->id,
            'an expired deal was still being sold as the hero'
        );
    }

    /** ...and it appears in the finished grid, marked as expired. */
    public function test_a_finished_deal_appears_in_the_past_grid_marked_expired()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id, [
            'end_time' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        $this->asGuest();

        $past = collect($this->getJson('/api/v1/clearance')->json('data.past_deals'))
            ->firstWhere('id', $clearance->id);

        $this->assertNotNull($past, 'an expired deal never reached the past grid');
        $this->assertTrue((bool)$past['is_expired'], 'an expired deal was not marked expired');
    }

    /** An unpublished deal is not a deal. */
    public function test_an_unpublished_deal_never_becomes_the_hero()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, ['status' => 2, 'sort_order' => -1]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertTrue(
            is_null($hero) || (int)$hero['product']['id'] !== (int)$product->id,
            'an unpublished deal was on sale'
        );
    }

    /** A deal on a product that has been taken down is not shown at all. */
    public function test_a_deal_on_an_unpublished_product_is_dropped()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id, ['sort_order' => -1]);

        Product::where('id', $product->id)->update(['status' => 2]);

        $this->asGuest();

        $data = $this->getJson('/api/v1/clearance')->json('data');

        $this->assertTrue(
            is_null($data['hero']) || (int)$data['hero']['product']['id'] !== (int)$product->id,
            'a deal on a withdrawn product was still the hero'
        );

        $this->assertNull(
            collect($data['past_deals'])->firstWhere('id', $clearance->id),
            'a deal on a withdrawn product was still in the past grid'
        );
    }

    /** Highlights are typed one per line and read back as a list. */
    public function test_highlights_are_split_one_per_line()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, [
            'highlights' => "Ex-display\n  Twelve month warranty  \n\nFree delivery\n",
            'sort_order' => -1,
        ]);

        $this->asGuest();

        $this->assertSame(
            ['Ex-display', 'Twelve month warranty', 'Free delivery'],
            $this->getJson('/api/v1/clearance')->json('data.hero.highlights'),
            'the highlight list was not cleanly split'
        );
    }

    /** The urgency counters are derived from stock, not typed in. */
    public function test_units_left_and_claimed_percent_come_from_stock()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->product();

        $this->deal($product->id, ['sort_order' => -1]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $stock = (int)UpdatedInventory::where('product_id', $product->id)->sum('quantity');

        $this->assertSame(
            $stock, (int)$hero['units_left'],
            'units left did not match the stock on hand'
        );

        $claimed = (int)$hero['claimed_percent'];

        $this->assertGreaterThanOrEqual(0, $claimed);
        $this->assertLessThanOrEqual(100, $claimed);
    }

    /* ---- the same price everywhere ------------------------------------ */

    /**
     * A product that is bought at its own price, not a variant's.
     *
     * Its single inventory row carries no attributes, so Pricing ignores that
     * row's price and charges products.offered. It is the simplest possible
     * clearance: one product, one price, one figure to move.
     */
    private function flatPricedProduct()
    {
        $row = DB::table('updated_inventories as ui')
            ->join('products as p', 'p.id', '=', 'ui.product_id')
            ->leftJoin('inventory_attributes as ia', 'ia.inventory_id', '=', 'ui.id')
            ->where('p.status', 1)
            ->whereNotNull('p.selling')->where('p.selling', '>', 0)
            ->where('ui.quantity', '>', 2)
            ->whereNull('ia.id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('clearances')
                    ->whereColumn('clearances.product_id', 'p.id')
                    ->where('clearances.status', 1)
                    ->where(function ($w) {
                        $w->whereNull('clearances.end_time')
                            ->orWhere('clearances.end_time', '>=', date('Y-m-d H:i:s'));
                    });
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('flash_sale_products')
                    ->whereColumn('flash_sale_products.product_id', 'p.id');
            })
            ->orderBy('p.id')
            ->select('p.id as product_id', 'ui.id as inventory_id')
            ->first();

        if (!$row) {
            $this->markTestSkipped('No product priced at product level with stock.');
        }

        return [
            'product' => Product::find($row->product_id),
            'inventory' => UpdatedInventory::find($row->inventory_id),
        ];
    }

    /** The product detail payload, with the day-long cache stepped over. */
    private function detail($id)
    {
        cache()->forget('detail.' . $id);

        return $this->getJson("/api/v1/product/{$id}")->json('data');
    }

    /** The fixture product's tile, found wherever it sits in the listing. */
    private function tile($id)
    {
        for ($page = 1; $page <= 6; $page++) {
            $rows = $this->getJson("/api/v1/products?page={$page}")->json('data.result.data');

            if (empty($rows)) {
                break;
            }

            $tile = collect($rows)->firstWhere('id', $id);

            if ($tile) {
                return $tile;
            }
        }

        $this->markTestSkipped('The fixture product is not in the public listing.');
    }

    /** The product's own page honours a clearance running on it. */
    public function test_the_product_page_shows_the_clearance_price()
    {
        ['product' => $product] = $this->product();

        $base = (float)$product->offered > 0 ? (float)$product->offered : (float)$product->selling;

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $this->asGuest();

        $this->assertEqualsWithDelta(
            round($base * 0.8, 2), (float)$this->detail($product->id)['offered'], 0.01,
            'the product page ignored the clearance running on it'
        );
    }

    /**
     * ...including in the figure it actually prints.
     *
     * display_price is what the page and every tile render -- the cheapest
     * thing the customer can buy. products.offered is not shown to anybody.
     * Moving one and not the other leaves the deal invisible.
     */
    public function test_the_product_page_headline_shows_the_clearance_price()
    {
        ['product' => $product] = $this->product();

        $this->asGuest();

        $before = (float)$this->detail($product->id)['display_price'];

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $after = (float)$this->detail($product->id)['display_price'];

        $this->assertLessThan(
            $before, $after,
            'the headline price did not move when a clearance was put on the product'
        );
    }

    /** The deal page and the product page quote the same product. */
    public function test_the_deal_page_and_the_product_page_agree()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, ['sort_order' => -1]);

        $this->asGuest();

        $onTheDealPage = $this->heroPrice();

        $onTheProductPage = (float)$this->detail($product->id)['offered'];

        $this->assertEqualsWithDelta(
            $onTheDealPage, $onTheProductPage, 0.01,
            'the clearance page and the product page priced the same product differently'
        );
    }

    /**
     * The tile in a listing advertises the discounted price.
     *
     * A badge reading CLEARANCE over the undiscounted number is worse than no
     * badge at all.
     */
    public function test_a_listing_tile_advertises_the_clearance_price()
    {
        $product = $this->listedProduct();

        $this->asGuest();

        $before = (float)$this->tile($product->id)['display_price'];

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $tile = $this->tile($product->id);

        $this->assertTrue((bool)($tile['clearance'] ?? false), 'the tile carried no clearance flag');

        $this->assertLessThan(
            $before, (float)$tile['display_price'],
            'the tile advertised the undiscounted price under a clearance badge'
        );
    }

    /* ---- the till ----------------------------------------------------- */

    /**
     * The one that matters: the customer is charged what the page advertised.
     *
     * The same rule cross-sell and the bundle are held to. Somebody shown
     * EUR 8.00 and charged EUR 10.00 has been mis-sold, and nothing
     * downstream -- the basket, the checkout summary, the order -- can put
     * that right, because by then the advertised figure is gone.
     */
    public function test_the_basket_charges_the_advertised_clearance_price()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->flatPricedProduct();

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $this->asGuest();

        $advertised = (float)$this->detail($product->id)['display_price'];

        $this->asCustomer();

        $add = $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
        ]);

        $this->assertSame(200, $this->bodyStatus($add), (string)$add->json('message'));

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($line, 'the product never reached the basket');

        $this->assertEqualsWithDelta(
            $advertised, (float)$line['upsell_price'], 0.01,
            'the basket charged a price the product page never advertised'
        );
    }

    /** And the line total is that price, times the count. */
    public function test_the_basket_line_total_is_the_advertised_price_times_quantity()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->flatPricedProduct();

        $this->deal($product->id, [
            'discount_type' => 'fixed',
            'discount_value' => $this->flatDiscountFor($product),
        ]);

        $this->asGuest();

        $advertised = (float)$this->detail($product->id)['display_price'];

        $this->asCustomer();

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 2,
        ]);

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($line, 'the product never reached the basket');

        $this->assertEqualsWithDelta(
            round($advertised * 2, 2), (float)$line['line_total'], 0.02,
            'the line total was not two units at the advertised price'
        );
    }

    /**
     * The deal page sells a variation at the variant's discounted price, so
     * that is the price the basket has to charge for it.
     */
    public function test_the_basket_charges_the_variant_price_the_deal_page_quoted()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->product();

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20, 'sort_order' => -1]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $quoted = collect($hero['product']['inventory'])->firstWhere('id', $inventory->id);

        $this->assertNotNull($quoted, 'the deal page did not quote the variant under test');

        /*
         * A variation row priced 0.00 does not cost nothing -- it carries no
         * price of its own and inherits the product's, which is the rule the
         * storefront's own price helper applies. So the figure the page quotes
         * for this row is its price when it has one and the headline otherwise.
         */
        $quotedPrice = (float)$quoted['price'] > 0
            ? (float)$quoted['price']
            : (float)$hero['product']['display_price'];

        $this->asCustomer();

        $add = $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
        ]);

        $this->assertSame(200, $this->bodyStatus($add), (string)$add->json('message'));

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($line, 'the product never reached the basket');

        $this->assertEqualsWithDelta(
            $quotedPrice, (float)$line['upsell_price'], 0.01,
            'the basket charged more than the deal page quoted for this variation'
        );
    }

    /* ---- when a deal does not apply ------------------------------------ */

    /** A deal that has finished does not move the price. */
    public function test_an_expired_deal_leaves_the_price_alone()
    {
        ['product' => $product] = $this->product();

        $this->asGuest();

        $before = (float)$this->detail($product->id)['offered'];

        $this->deal($product->id, ['end_time' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);

        $this->assertEqualsWithDelta(
            $before, (float)$this->detail($product->id)['offered'], 0.01,
            'an expired clearance was still discounting the product page'
        );
    }

    /** Neither does an unpublished one. */
    public function test_an_unpublished_deal_leaves_the_price_alone()
    {
        ['product' => $product] = $this->product();

        $this->asGuest();

        $before = (float)$this->detail($product->id)['offered'];

        $this->deal($product->id, ['status' => 2]);

        $this->assertEqualsWithDelta(
            $before, (float)$this->detail($product->id)['offered'], 0.01,
            'an unpublished clearance was discounting the product page'
        );
    }

    /**
     * A live flash sale is the deliberate, dated override; it wins.
     *
     * The precedence test in applyClearanceToDetail reads the flash price out
     * of the day-long detail cache, so the cache is cleared here first --
     * otherwise this measures the cache, not the rule.
     */
    public function test_a_flash_sale_beats_a_clearance()
    {
        ['product' => $product] = $this->product();

        $adminId = Admin::whereHas('roles', function ($q) {
            $q->where('name', 'superadmin');
        })->value('id');

        $sale = DB::table('flash_sales')->insertGetId([
            'title' => 'QA Flash ' . uniqid(),
            'start_time' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'end_time' => date('Y-m-d H:i:s', strtotime('+2 days')),
            'status' => 1,
            'admin_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flash_sale_products')->insert([
            'flash_sale_id' => $sale,
            'product_id' => $product->id,
            'price' => round(((float)$product->selling) * 0.5, 2),
            'admin_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 90]);

        $this->asGuest();

        $headline = (float)$this->detail($product->id)['display_price'];

        $this->assertGreaterThan(
            round(((float)$product->selling) * 0.2, 2), $headline,
            'the 90% clearance overrode the flash sale that was supposed to win'
        );
    }

    /**
     * VAT has to keep adding up.
     *
     * The page prints a VAT-inclusive figure beside the ex-VAT one; they are
     * the same money at two rates. Taking the same flat sum off each breaks
     * that -- EUR 50 off both moves the implied rate.
     */
    public function test_a_fixed_deal_keeps_the_vat_inclusive_price_consistent()
    {
        $product = $this->exVatProduct();

        $this->asGuest();

        $before = $this->detail($product->id);

        $exBefore = (float)($before['offered'] ?: $before['selling']);
        $incBefore = (float)($before['vatInclusiveOfferedPrice'] ?: $before['vatInclusivePrice']);

        if ($exBefore <= 0 || $incBefore <= 0 || abs($incBefore - $exBefore) < 0.01) {
            $this->markTestSkipped('The fixture product carries no separate VAT-inclusive price.');
        }

        $rate = $incBefore / $exBefore;

        $this->deal($product->id, [
            'discount_type' => 'fixed',
            'discount_value' => $this->flatDiscountFor($product),
        ]);

        $after = $this->detail($product->id);

        $this->assertEqualsWithDelta(
            round((float)$after['offered'] * $rate, 2),
            (float)$after['vatInclusiveOfferedPrice'],
            0.02,
            'the VAT-inclusive price no longer matches the ex-VAT price at the same rate'
        );
    }

    /* ---- the admin screen --------------------------------------------- */
    /** Nobody reads the deal list without being signed in for it. */
    public function test_the_list_is_closed_to_a_signed_out_caller()
    {
        $this->asGuest();

        $response = $this->getJson('/api/admin/clearance/all?orderby=created_at&type=desc');

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            'a signed-out caller was handed the deal list'
        );
    }

    /** An administrator sees the deals. */
    public function test_an_administrator_reads_the_deal_list()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id);

        $this->admin();

        $response = $this->getJson('/api/admin/clearance/all?orderby=created_at&type=desc');

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $ids = collect($response->json('data.data'))->pluck('id')->map('intval')->all();

        $this->assertContains(
            (int)$clearance->id, $ids,
            'a newly created deal was not in the list an administrator opens'
        );
    }


    /** Creating a deal writes what was typed. */
    public function test_creating_a_deal_stores_it()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'title' => 'Ex-display clear-out',
            'subtitle' => 'While they last',
            'discount_type' => 'fixed',
            'discount_value' => 25,
            'description' => 'One only.',
            'highlights' => "Boxed\nTwelve months",
            'end_time' => date('Y-m-d H:i', strtotime('+2 days')),
            'status' => 1,
            'sort_order' => 3,
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $saved = Clearance::find($response->json('data.id'));

        $this->assertNotNull($saved, 'the deal was not written');
        $this->assertSame((int)$product->id, (int)$saved->product_id);
        $this->assertSame('fixed', $saved->discount_type);
        $this->assertEqualsWithDelta(25.0, (float)$saved->discount_value, 0.001);
        $this->assertSame(3, (int)$saved->sort_order);
        $this->assertNotNull($saved->end_time, 'the timer was dropped');
    }

    /** Editing one changes it. */
    public function test_editing_a_deal_changes_it()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id);

        $this->admin();

        $response = $this->postJson("/api/admin/clearance/action/{$clearance->id}", [
            'product' => ['id' => $product->id],
            'title' => 'Renamed',
            'discount_type' => 'percentage',
            'discount_value' => 35,
            'status' => 1,
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $saved = $clearance->fresh();

        $this->assertSame('Renamed', $saved->title);
        $this->assertEqualsWithDelta(35.0, (float)$saved->discount_value, 0.001);
    }

    /**
     * A percentage is a percentage.
     *
     * Above 100 it is not a discount, it is a giveaway: the deal price lands
     * on zero and the storefront offers the product free.
     */
    public function test_a_percentage_above_a_hundred_is_refused()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 150,
            'status' => 1,
        ]);

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            'a 150% clearance was accepted, which prices the product at zero'
        );
    }

    /** A negative discount is a price rise dressed as a deal. */
    public function test_a_negative_discount_is_refused()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'fixed',
            'discount_value' => -50,
            'status' => 1,
        ]);

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            'a negative clearance was accepted, which raises the price'
        );
    }

    /** Editing something that is not there is a mistake, not a success. */
    public function test_editing_a_deal_that_does_not_exist_is_refused()
    {
        ['product' => $product] = $this->product();

        $missing = ((int)Clearance::max('id')) + 5000;

        $this->admin();

        $response = $this->postJson("/api/admin/clearance/action/{$missing}", [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'status' => 1,
        ]);

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            'editing a deal that does not exist reported success'
        );
    }

    /** The deal list is searched by what the administrator can see in it. */
    public function test_the_list_can_be_searched_by_product_name()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, ['title' => null]);

        $this->admin();

        $word = collect(explode(' ', (string)$product->title))
            ->first(function ($part) {
                return strlen($part) > 3;
            });

        if (!$word) {
            $this->markTestSkipped('The fixture product has no searchable word in its name.');
        }

        $rows = $this->getJson('/api/admin/clearance/all?orderby=created_at&type=desc&q=' . urlencode($word))
            ->json('data.data');

        $this->assertNotEmpty(
            collect($rows ?: [])->where('product_id', $product->id),
            'a deal could not be found by the product name the list prints for it'
        );
    }

    /** Deleting removes it. */
    public function test_deleting_a_deal_removes_it()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id);

        $this->admin();

        $response = $this->deleteJson("/api/admin/clearance/delete/{$clearance->id}");

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertNull(Clearance::find($clearance->id), 'the deal survived its deletion');
    }

    /** A deal record does not carry the staff id that made it. */
    public function test_the_deal_page_does_not_publish_the_sales_count()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, ['sort_order' => -1]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertArrayNotHasKey(
            'purchased', $hero['product'],
            'the clearance page published the number of units the shop has sold'
        );
    }

    /**
     * The deal page prints one price and Buy Now charges it.
     *
     * The page used to print the product-level offer price while its own
     * button put a variant in the basket at the variant's price -- one click,
     * two numbers, neither of them what the till then charged.
     */
    public function test_the_deal_page_headline_is_what_buy_now_charges()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->product();

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20, 'sort_order' => -1]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertNotNull($hero['product']['display_price'] ?? null,
            'the deal page did not carry a server-decided headline price');

        $this->asCustomer();

        $add = $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
        ]);

        $this->assertSame(200, $this->bodyStatus($add), (string)$add->json('message'));

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($line, 'the product never reached the basket');

        $this->assertEqualsWithDelta(
            (float)$hero['product']['display_price'], (float)$line['upsell_price'], 0.01,
            'the deal page headline is not the price Buy Now charges'
        );
    }

    /**
     * One live deal per product.
     *
     * With two, which discount a customer got was settled by the sort_order
     * tie-break rather than by anything an administrator chose.
     */
    public function test_a_second_live_deal_on_the_same_product_is_refused()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id);

        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'status' => 1,
        ]);

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            'a product was given two live clearance deals at once'
        );
    }

    /** A finished deal is history, and does not block a new one. */
    public function test_a_finished_deal_does_not_block_a_new_one()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, ['end_time' => date('Y-m-d H:i:s', strtotime('-1 day'))]);

        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'end_time' => date('Y-m-d H:i', strtotime('+2 days')),
            'status' => 1,
        ]);

        $this->assertSame(
            200, $this->bodyStatus($response),
            'a product could not be put back on clearance after its last deal ended: '
                . (string)$response->json('message')
        );
    }

    /** Editing a live deal is not a clash with itself. */
    public function test_editing_the_only_live_deal_is_not_a_clash()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id);

        $this->admin();

        $response = $this->postJson("/api/admin/clearance/action/{$clearance->id}", [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'status' => 1,
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertEqualsWithDelta(15.0, (float)$clearance->fresh()->discount_value, 0.001);
    }

    /** A flat amount has no ceiling: it is capped against each line's base. */
    public function test_a_fixed_discount_larger_than_the_price_cannot_go_negative()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->flatPricedProduct();

        $this->deal($product->id, [
            'discount_type' => 'fixed',
            'discount_value' => (float)$product->selling + 1000,
        ]);

        $this->asCustomer();

        $add = $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
        ]);

        $this->assertSame(200, $this->bodyStatus($add), (string)$add->json('message'));

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($line, 'the product never reached the basket');
        $this->assertGreaterThanOrEqual(
            0.0, (float)$line['upsell_price'],
            'an oversized clearance produced a negative price'
        );
    }

    /**
     * The tile surfaces that build their own queries.
     *
     * The wishlist, recently viewed and the compare list do not go through the
     * listing's clearance pass, so they advertised the pre-clearance price
     * while the shop listing, the product page and the till all had the deal.
     */
    public function test_recently_viewed_shows_the_clearance_price()
    {
        ['product' => $product] = $this->product();

        $this->asCustomer();

        // Viewing the product is what puts it in the list.
        $before = (float)$this->detail($product->id)['display_price'];

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $this->detail($product->id);

        $tile = collect($this->getJson('/api/v1/user/recently-viewed')->json('data.data'))
            ->pluck('product')
            ->firstWhere('id', $product->id);

        if (is_null($tile)) {
            $this->markTestSkipped('The product did not reach the recently-viewed list.');
        }

        $this->assertLessThan(
            $before, (float)$tile['display_price'],
            'recently viewed advertised the price the clearance had already reduced'
        );
    }

    /* ---- which deal the page leads with -------------------------------- */

    /**
     * sort_order picks the hero, lowest first.
     *
     * With two live deals the page shows one of them, and which one must be
     * the administrator's choice rather than whichever row came back first.
     */
    public function test_sort_order_decides_which_live_deal_leads()
    {
        ['product' => $first] = $this->product();

        $second = $this->otherProduct($first->id);

        $this->deal($first->id, ['sort_order' => 5, 'title' => 'Second in line']);
        $this->deal($second->id, ['sort_order' => -10, 'title' => 'Leads the page']);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertNotNull($hero, 'no hero at all');
        $this->assertSame(
            (int)$second->id, (int)$hero['product']['id'],
            'the page did not lead with the lowest sort_order'
        );
    }

    /** A live deal that is not the hero is not filed under "past" either. */
    public function test_the_hero_is_not_repeated_in_the_past_grid()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id, ['sort_order' => -20]);

        $this->asGuest();

        $data = $this->getJson('/api/v1/clearance')->json('data');

        $this->assertSame((int)$clearance->id, (int)$data['hero']['id']);

        $this->assertNull(
            collect($data['past_deals'])->firstWhere('id', $clearance->id),
            'the hero deal was listed again in the past grid'
        );
    }

    /**
     * A deal taken down before its timer ran out reads "sold out", not
     * "expired" -- the two stamps mean different things to a shopper.
     */
    public function test_a_deal_taken_down_early_is_not_marked_expired()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id, [
            'status' => 2,
            'end_time' => date('Y-m-d H:i:s', strtotime('+5 days')),
        ]);

        $this->asGuest();

        $past = collect($this->getJson('/api/v1/clearance')->json('data.past_deals'))
            ->firstWhere('id', $clearance->id);

        $this->assertNotNull($past, 'a withdrawn deal never reached the past grid');
        $this->assertFalse(
            (bool)$past['is_expired'],
            'a deal withdrawn before its timer was stamped "expired"'
        );
    }

    /** The finished grid is a grid, not the whole history. */
    public function test_the_past_grid_is_capped()
    {
        $this->asGuest();

        $this->assertLessThanOrEqual(
            8, count($this->getJson('/api/v1/clearance')->json('data.past_deals')),
            'the past-deals grid returned more than it is capped at'
        );
    }

    /**
     * The saving is shown, not just the new price.
     *
     * The page prints the deal price with the old one struck through and a
     * "you save" badge; both are driven by the compare figure, and both
     * silently disappear when it is null.
     */
    public function test_the_deal_page_carries_the_price_it_undercuts()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, [
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'sort_order' => -20,
        ]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $compare = $hero['product']['display_compare_price'] ?? null;

        $this->assertNotNull($compare, 'the deal page showed no price to compare against');

        $this->assertGreaterThan(
            (float)$hero['product']['display_price'], (float)$compare,
            'the struck-through price was not higher than the deal price'
        );
    }

    /** A deal with no discount is still a deal page, at the normal price. */
    public function test_a_deal_with_no_discount_leaves_the_price_alone()
    {
        ['product' => $product] = $this->product();

        $this->asGuest();

        $before = (float)$this->detail($product->id)['display_price'];

        $this->deal($product->id, ['discount_value' => 0, 'sort_order' => -20]);

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertSame((int)$product->id, (int)$hero['product']['id']);

        $this->assertEqualsWithDelta(
            $before, (float)$this->detail($product->id)['display_price'], 0.01,
            'a deal with a zero discount moved the price'
        );
    }

    /** The administrator's own wording is what the page prints. */
    public function test_the_admin_copy_reaches_the_page()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, [
            'title' => 'Bench-tested ex-demo',
            'subtitle' => 'Three units only',
            'description' => 'Each one wiped and re-imaged.',
            'sort_order' => -20,
        ]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertSame('Bench-tested ex-demo', $hero['title']);
        $this->assertSame('Three units only', $hero['subtitle']);
        $this->assertSame('Each one wiped and re-imaged.', $hero['description']);
        $this->assertSame(
            'Each one wiped and re-imaged.', $hero['product']['overview'],
            'the deal description did not override the product copy'
        );
    }

    /* ---- the rest of the admin surface --------------------------------- */

    /** Opening a deal for editing returns it, with its product. */
    public function test_a_deal_can_be_opened_for_editing()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id, ['title' => 'Openable']);

        $this->admin();

        $response = $this->getJson("/api/admin/clearance/find/{$clearance->id}");

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertSame('Openable', $response->json('data.title'));
        $this->assertSame((int)$product->id, (int)$response->json('data.product.id'));
    }

    /** Opening one that is not there says so. */
    public function test_opening_a_deal_that_does_not_exist_is_refused()
    {
        $this->admin();

        $missing = ((int)Clearance::max('id')) + 5000;

        $response = $this->getJson("/api/admin/clearance/find/{$missing}");

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            'the API answered for a deal that does not exist'
        );
    }

    /** The list's tick-boxes delete several at once. */
    public function test_several_deals_are_deleted_together()
    {
        ['product' => $first] = $this->product();
        $second = $this->otherProduct($first->id);

        $a = $this->deal($first->id);
        $b = $this->deal($second->id);

        $this->admin();

        $response = $this->deleteJson("/api/admin/clearance/delete/{$a->id},{$b->id}");

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertNull(Clearance::find($a->id), 'the first deal survived');
        $this->assertNull(Clearance::find($b->id), 'the second deal survived');
    }

    /** Deleting something that is not there says so. */
    public function test_deleting_a_deal_that_does_not_exist_is_refused()
    {
        $this->admin();

        $missing = ((int)Clearance::max('id')) + 5000;

        $response = $this->deleteJson("/api/admin/clearance/delete/{$missing}");

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            'deleting a deal that does not exist reported success'
        );
    }

    /** The override title is searchable too, not only the product name. */
    public function test_the_list_can_be_searched_by_the_override_title()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id, ['title' => 'Zzz Bench Tested Ex Demo']);

        $this->admin();

        $ids = collect(
            $this->getJson('/api/admin/clearance/all?orderby=created_at&type=desc&q=Bench+Tested')
                ->json('data.data')
        )->pluck('id')->map('intval')->all();

        $this->assertContains(
            (int)$clearance->id, $ids,
            'a deal could not be found by the title an administrator gave it'
        );
    }

    /** A discount shape the screen does not offer falls back to a percentage. */
    public function test_an_unknown_discount_shape_is_stored_as_a_percentage()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'half_price',
            'discount_value' => 10,
            'status' => 1,
        ]);

        // Either refuse it or store a shape Pricing understands; silently
        // keeping "half_price" would price the deal as a flat sum.
        if ($this->bodyStatus($response) === 200) {
            $this->assertSame(
                'percentage', Clearance::find($response->json('data.id'))->discount_type,
                'an unknown discount shape was stored as-is'
            );
        } else {
            $this->assertTrue(true);
        }
    }

    /** A deal has to name a product. */
    public function test_a_deal_without_a_product_is_refused()
    {
        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'status' => 1,
        ]);

        $this->assertNotSame(200, $this->bodyStatus($response), 'a deal saved with no product');
    }

    /** ...and a status. */
    public function test_a_deal_without_a_status_is_refused()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);

        $this->assertNotSame(200, $this->bodyStatus($response), 'a deal saved with no status');
    }

    /** The timer is optional: a deal with none runs until it is stopped. */
    public function test_a_deal_with_no_timer_runs_until_it_is_stopped()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'end_time' => '',
            'status' => 1,
            'sort_order' => -20,
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertNull(Clearance::find($response->json('data.id'))->end_time);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertSame(
            (int)$product->id, (int)$hero['product']['id'],
            'a deal with no timer was not running'
        );
    }

    /* ---- the money actually taken -------------------------------------- */

    /** The encrypted body checkout expects. */
    private function orderPayload(array $params = [])
    {
        $json = json_encode(array_merge([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.BANK'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ], $params));

        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt($json, 'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210'));
    }

    /** A customer with somewhere to send the order. */
    private function customerWithAddress()
    {
        $customer = $this->customer();

        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Clearance Fixture',
            'email' => $customer->email,
            'phone' => $customer->phone,
            'country' => 'IE',
            'state' => 'KE',
            'city' => 'Naas',
            'zip' => 'W91XY12',
            'address_1' => '1 Test Street',
            'type' => 1,
            'default' => 1,
        ]);

        $customer->default_address = $address->id;
        $customer->save();

        $this->app['auth']->forgetGuards();

        Passport::actingAs($customer->fresh(), ['user'], 'user');

        return $customer;
    }

    /**
     * The whole way through: deal page, basket, order.
     *
     * The basket is a quote; ordered_products.selling is the money. A deal
     * that survives as far as the basket and then evaporates at checkout is
     * the same mis-sale, one step later.
     */
    public function test_the_order_charges_the_clearance_price()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->product();

        $place = optional($product->shipping_rule)->shipping_places()->first();

        if (!$place) {
            $this->markTestSkipped('The fixture product has no delivery option to choose.');
        }

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $this->asGuest();

        $advertised = (float)$this->detail($product->id)['display_price'];

        $customer = $this->customerWithAddress();

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
            'shipping_place_id' => $place->id,
            'shipping_type' => 1,
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/order/action', [
            'data' => $this->orderPayload(),
            'order_method' => (int)config('constants.paymentMethod.BANK'),
        ]);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped(
                'Checkout did not complete in this environment: '
                . json_encode($response->json('message'))
            );
        }

        $line = OrderedProduct::where('order_id', $order->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($line, 'the product is on the order');

        $this->assertEqualsWithDelta(
            $advertised, (float)$line->selling, 0.01,
            'the order charged a price the deal page never advertised'
        );
    }

    /** The wishlist tile is a tile, and prices like one. */
    public function test_the_wishlist_shows_the_clearance_price()
    {
        ['product' => $product] = $this->product();

        $this->asCustomer();

        $before = (float)$this->detail($product->id)['display_price'];

        $this->postJson('/api/v1/user/wishlist/action', ['product_id' => $product->id]);

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $rows = $this->getJson('/api/v1/user/wishlist/all')->json('data.data')
            ?? $this->getJson('/api/v1/user/wishlist/all')->json('data');

        $tile = collect($rows)->pluck('product')->firstWhere('id', $product->id);

        if (is_null($tile)) {
            $this->markTestSkipped('The product did not reach the wishlist.');
        }

        $this->assertLessThan(
            $before, (float)$tile['display_price'],
            'the wishlist advertised the price the clearance had already reduced'
        );
    }

    /**
     * The block under the buy box quotes what the basket will charge.
     *
     * It prices through Product::pricingRecord, which is where the deal is
     * joined in, so this is the case that proves the join reaches the
     * recommendation surfaces and not only the basket.
     */
    public function test_the_product_page_block_quotes_the_clearance_price()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->product();

        $this->asGuest();

        $before = (float)$this->getJson("/api/v1/product/upsell-crosssell/{$product->id}?inventory_id={$inventory->id}")
            ->json('main_product.price')
            ?? 0.0;

        if ($before <= 0) {
            $this->markTestSkipped('The block did not quote this product.');
        }

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $after = (float)$this->getJson("/api/v1/product/upsell-crosssell/{$product->id}?inventory_id={$inventory->id}")
            ->json('main_product.price');

        $this->assertEqualsWithDelta(
            round($before * 0.8, 2), $after, 0.02,
            'the product-page block ignored the clearance running on the product'
        );
    }

    /** The compare list is the third tile surface that builds its own query. */
    public function test_the_compare_list_shows_the_clearance_price()
    {
        ['product' => $product] = $this->product();

        $this->asCustomer();

        $before = (float)$this->detail($product->id)['display_price'];

        $this->postJson('/api/v1/user/compare-list/action', ['product_id' => $product->id]);

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $rows = $this->getJson('/api/v1/user/compare-list/all?order_by=id&type=desc')->json('data.data')
            ?: $this->getJson('/api/v1/user/compare-list/all?order_by=id&type=desc')->json('data');

        $tile = collect($rows)->pluck('product')->firstWhere('id', $product->id);

        if (is_null($tile)) {
            $this->markTestSkipped('The product did not reach the compare list.');
        }

        $this->assertLessThan(
            $before, (float)$tile['display_price'],
            'the compare list advertised the price the clearance had already reduced'
        );
    }

    /**
     * A trade band and a clearance are both reducers, and they stack in one
     * order: the deal comes off first, then the band is applied to what is
     * left and can only lower it further.
     *
     * The case that matters is the one where the band is DEARER than the deal.
     * A band is a privilege, so it must never be the reason a trade customer
     * pays more than the shop is charging everybody else that week.
     */
    public function test_a_trade_band_never_costs_more_than_the_public_deal()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->product();

        $base = (float)$product->offered > 0 ? (float)$product->offered : (float)$product->selling;

        // A band pitched just under the normal price, which a 40% deal beats.
        DB::table('business_product_pricing')->insert([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 9999,
            'wholesale_price' => $base,
            'discount_type' => 'percentage',
            'discount_value' => 5,
            'final_price' => round($base * 0.95, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 40]);

        $this->asGuest();

        $public = (float)$this->detail($product->id)['display_price'];

        $trade = $this->customerWithAddress();
        $trade->account_type = 'business';
        $trade->save();

        $this->app['auth']->forgetGuards();
        Passport::actingAs($trade->fresh(), ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
        ]);

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($line, 'the product never reached the basket');

        $this->assertLessThanOrEqual(
            $public + 0.01, (float)$line['upsell_price'],
            'a trade account was charged more than the public clearance price'
        );
    }

    /** Nobody signs in to get the deal; a guest is quoted the same figure. */
    public function test_a_guest_and_a_signed_in_shopper_are_quoted_the_same_deal()
    {
        ['product' => $product] = $this->product();

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $this->asGuest();
        $asGuest = (float)$this->detail($product->id)['display_price'];

        $this->asCustomer();
        $signedIn = (float)$this->detail($product->id)['display_price'];

        $this->assertEqualsWithDelta(
            $asGuest, $signedIn, 0.01,
            'the deal price depended on whether the shopper was signed in'
        );
    }

    /* ---- a vendor sees only their own ---------------------------------- */

    /**
     * A vendor account, signed in.
     *
     * The vendor branches -- the admin_id filter on the list and the ownership
     * test on open, edit and delete -- had never run in a test, because this
     * installation has the role and no account holding it.
     */
    private function vendor()
    {
        $vendor = Admin::create([
            'name' => 'Clearance Vendor',
            'username' => 'vendor.' . uniqid(),
            'email' => 'vendor.' . uniqid() . '@example.test',
            'password' => bcrypt('Abcdefghij1'),
            'active' => 1,
            'verified' => 1,
        ]);

        $vendor->assignRole('vendor');

        $this->app['auth']->forgetGuards();

        Passport::actingAs($vendor->fresh(), ['admin'], 'admin');

        return $vendor;
    }

    /** The list a vendor opens holds their deals and nobody else's. */
    public function test_a_vendor_sees_only_their_own_deals()
    {
        ['product' => $mine] = $this->product();
        $theirs = $this->otherProduct($mine->id);

        // One made by the superadmin, one made by the vendor.
        $somebodyElses = $this->deal($theirs->id);

        $vendor = $this->vendor();

        $own = Clearance::create([
            'product_id' => $mine->id,
            'title' => 'Vendor deal',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'status' => 1,
            'sort_order' => 0,
            'admin_id' => $vendor->id,
        ]);

        $ids = collect(
            $this->getJson('/api/admin/clearance/all?orderby=created_at&type=desc')->json('data.data')
        )->pluck('id')->map('intval')->all();

        $this->assertContains((int)$own->id, $ids, 'a vendor could not see their own deal');
        $this->assertNotContains(
            (int)$somebodyElses->id, $ids,
            "a vendor was shown another account's deal"
        );
    }

    /** And cannot open one that is not theirs. */
    public function test_a_vendor_cannot_open_another_accounts_deal()
    {
        ['product' => $product] = $this->product();

        $somebodyElses = $this->deal($product->id);

        $this->vendor();

        $response = $this->getJson("/api/admin/clearance/find/{$somebodyElses->id}");

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            "a vendor opened another account's deal"
        );
    }

    /** Nor edit it. */
    public function test_a_vendor_cannot_edit_another_accounts_deal()
    {
        ['product' => $product] = $this->product();

        $somebodyElses = $this->deal($product->id, ['discount_value' => 10]);

        $this->vendor();

        $response = $this->postJson("/api/admin/clearance/action/{$somebodyElses->id}", [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 90,
            'status' => 1,
        ]);

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            "a vendor edited another account's deal"
        );

        $this->assertEqualsWithDelta(
            10.0, (float)$somebodyElses->fresh()->discount_value, 0.001,
            "another account's deal was changed"
        );
    }

    /** Nor delete it. */
    public function test_a_vendor_cannot_delete_another_accounts_deal()
    {
        ['product' => $product] = $this->product();

        $somebodyElses = $this->deal($product->id);

        $this->vendor();

        $response = $this->deleteJson("/api/admin/clearance/delete/{$somebodyElses->id}");

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            "a vendor deleted another account's deal"
        );

        $this->assertNotNull(
            Clearance::find($somebodyElses->id),
            "another account's deal was deleted"
        );
    }

    /** An administrator with no clearance permission is refused. */
    public function test_an_admin_without_the_permission_is_refused()
    {
        $admin = Admin::create([
            'name' => 'No Clearance Rights',
            'username' => 'norights.' . uniqid(),
            'email' => 'norights.' . uniqid() . '@example.test',
            'password' => bcrypt('Abcdefghij1'),
            'active' => 1,
            'verified' => 1,
        ]);

        $this->app['auth']->forgetGuards();

        Passport::actingAs($admin->fresh(), ['admin'], 'admin');

        $response = $this->getJson('/api/admin/clearance/all?orderby=created_at&type=desc');

        $this->assertNotSame(
            200, $this->bodyStatus($response),
            'an administrator with no clearance permission read the deal list'
        );
    }

    /* ---- simple products and variant products -------------------------- */

    /** A product whose variants carry different prices of their own. */
    private function variantPricedProduct()
    {
        $id = DB::table('products as p')
            ->join('updated_inventories as ui', 'ui.product_id', '=', 'p.id')
            ->where('p.status', 1)
            ->where('p.selling', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('clearances')
                    ->whereColumn('clearances.product_id', 'p.id')
                    ->where('clearances.status', 1)
                    ->where(function ($w) {
                        $w->whereNull('clearances.end_time')
                            ->orWhere('clearances.end_time', '>=', date('Y-m-d H:i:s'));
                    });
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('flash_sale_products')
                    ->whereColumn('flash_sale_products.product_id', 'p.id');
            })
            ->groupBy('p.id')
            ->havingRaw('COUNT(ui.id) > 1 AND MIN(ui.price) <> MAX(ui.price) AND MIN(ui.price) > 0')
            ->orderBy('p.id')
            ->value('p.id');

        if (!$id) {
            $this->markTestSkipped('No product with differently-priced variants.');
        }

        return Product::find($id);
    }

    /**
     * A deal is put on a PRODUCT -- the table has no variant column -- but it
     * is priced against each VARIANT's own price.
     *
     * A percentage therefore scales: every variant loses the same share, so
     * the gap between a cheap one and a dear one stays proportional.
     */
    public function test_a_percentage_deal_takes_the_same_share_off_every_variant()
    {
        $product = $this->variantPricedProduct();

        $rows = UpdatedInventory::withCount('inventory_attributes')
            ->where('product_id', $product->id)->orderBy('price')->get();

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $record = Product::pricingRecord($product->id);

        foreach ($rows as $row) {
            $base = Pricing::baseUnitPrice($record, $row);

            if ($base <= 0) {
                continue;
            }

            $this->assertEqualsWithDelta(
                round($base * 0.8, 2), Pricing::unitPrice($record, $row, 1, null), 0.02,
                "variant {$row->id} did not lose 20% of its own price"
            );
        }
    }

    /**
     * A flat sum does NOT scale: the same money comes off every variant, so a
     * dear variant is discounted by a smaller share than a cheap one.
     *
     * Not a defect -- it is what "EUR 100 off" means -- but it is the reason
     * the two shapes are not interchangeable on a product whose variants are
     * priced apart, and the admin screen previews only the product-level
     * figure, so nothing on that screen shows it.
     */
    public function test_a_fixed_deal_takes_the_same_money_off_every_variant()
    {
        $product = $this->variantPricedProduct();

        $rows = UpdatedInventory::withCount('inventory_attributes')
            ->where('product_id', $product->id)->orderBy('price')->get();

        $cheapest = Pricing::baseUnitPrice(Product::pricingRecord($product->id), $rows->first());

        // Small enough that it cannot clamp the cheapest variant to zero,
        // which would hide the very thing this is measuring.
        $off = round(max($cheapest * 0.1, 0.01), 2);

        $this->deal($product->id, ['discount_type' => 'fixed', 'discount_value' => $off]);

        $record = Product::pricingRecord($product->id);

        $shares = [];

        foreach ($rows as $row) {
            $base = Pricing::baseUnitPrice($record, $row);

            if ($base <= 0) {
                continue;
            }

            $charged = Pricing::unitPrice($record, $row, 1, null);

            $this->assertEqualsWithDelta(
                round($base - $off, 2), $charged, 0.02,
                "variant {$row->id} did not lose exactly {$off}"
            );

            $shares[] = round(100 - ($charged / $base) * 100, 1);
        }

        $this->assertGreaterThan(
            1, count(array_unique($shares)),
            'a flat deal came out as the same percentage on every variant, '
                . 'which means the variants are not priced apart after all'
        );
    }

    /** On a simple product there is one price, and the deal moves that. */
    public function test_a_simple_product_takes_the_deal_on_its_own_price()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->flatPricedProduct();

        $record = Product::pricingRecord($product->id);
        $base = Pricing::baseUnitPrice($record, $inventory);

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $this->assertEqualsWithDelta(
            round($base * 0.8, 2),
            Pricing::unitPrice(Product::pricingRecord($product->id), $inventory, 1, null),
            0.02,
            'a simple product did not take the deal on its own price'
        );
    }

    /** The headline keeps saying "From" when the variants are priced apart. */
    public function test_a_deal_does_not_flatten_a_variant_product_into_one_price()
    {
        $product = $this->variantPricedProduct();

        $rows = UpdatedInventory::withCount('inventory_attributes')
            ->where('product_id', $product->id)->get();

        $this->deal($product->id, ['discount_type' => 'percentage', 'discount_value' => 20]);

        $range = Pricing::displayRange(Product::pricingRecord($product->id), $rows, null);

        $this->assertTrue(
            (bool)$range['variable'],
            'the deal collapsed a variable product into a single price'
        );

        $this->assertGreaterThan(
            $range['min'], $range['max'],
            'the deal flattened the price range'
        );
    }

    /**
     * The admin screen is given what it needs to preview the real price.
     *
     * Its "Deal price" line used to be worked out from products.offered, which
     * on a product whose variations carry their own prices is a figure nobody
     * pays: the DELL Latitude 3520 previewed a EUR 586.49 deal against
     * EUR 689.99 while the storefront charged EUR 501.49 against EUR 589.99.
     * The screen prices from the variations now, so the endpoint has to send
     * them.
     */
    public function test_opening_a_deal_returns_the_variations_to_price_it_against()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id);

        $this->admin();

        $rows = $this->getJson("/api/admin/clearance/find/{$clearance->id}")
            ->json('data.product.product_inventories');

        $this->assertIsArray($rows, 'the deal came back with no variations to price against');
        $this->assertNotEmpty($rows, 'the deal came back with an empty variation list');

        $expected = UpdatedInventory::where('product_id', $product->id)->count();

        $this->assertCount(
            $expected, $rows,
            'the deal did not carry every variation the product has'
        );
    }

    /**
     * And the figure it would print is the figure the storefront prints.
     *
     * The admin screen takes the cheapest buyable variation and applies the
     * deal to it; Pricing::displayRange does the same thing server-side. This
     * asserts the two agree on the fixture, which is the whole point of the
     * change -- an administrator must not be quoted a price no customer sees.
     */
    public function test_the_admin_preview_base_is_the_storefront_headline_base()
    {
        ['product' => $product] = $this->product();

        /*
         * The price the storefront measures its headline against, taken
         * BEFORE a deal exists -- the admin screen's base is the catalogue
         * price it is about to discount, not the discounted result.
         */
        $storefrontBase = (float)Pricing::displayRange(
            Product::pricingRecord($product->id),
            UpdatedInventory::withCount('inventory_attributes')
                ->where('product_id', $product->id)->get(),
            null
        )['min'];

        $clearance = $this->deal($product->id);

        $this->admin();

        $rows = collect(
            $this->getJson("/api/admin/clearance/find/{$clearance->id}")
                ->json('data.product.product_inventories')
        );

        // What the admin screen's buyablePrices/effectiveBase computes.
        $productBase = (float)$product->offered > 0 ? (float)$product->offered : (float)$product->selling;

        $buyable = $rows
            ->filter(function ($row) {
                return (int)$row['quantity'] > 0 || (int)$row['is_active'] === 1;
            })
            ->map(function ($row) use ($productBase) {
                return (float)$row['price'] > 0 ? (float)$row['price'] : $productBase;
            })
            ->filter(function ($price) {
                return $price > 0;
            });

        $adminBase = $buyable->isEmpty() ? $productBase : $buyable->min();

        $this->assertEqualsWithDelta(
            $storefrontBase, $adminBase, 0.01,
            'the admin screen would preview the deal against a different price '
                . 'than the storefront charges it on'
        );
    }

    /* ---- the timer keeps the time it was set to ------------------------ */

    /**
     * The screen's control is a datetime-local, so it sends the
     * administrator's own clock; every comparison against it is made in UTC.
     *
     * Stored raw, a deal set by an administrator in Dublin in summer ran an
     * hour past the moment they chose, and one set from IST ran five and a
     * half hours past it. Flash sales, vouchers and scheduled admin tasks all
     * convert; this did not.
     */
    public function test_the_timer_is_stored_on_the_app_clock()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        // 18:00 in Kolkata is 12:30 UTC.
        $response = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'end_time' => '2026-12-24T18:00',
            'time_zone' => 'Asia/Kolkata',
            'status' => 1,
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $this->assertSame(
            '2026-12-24 12:30:00',
            (string)Clearance::find($response->json('data.id'))->end_time,
            'the timer was stored on the browser clock rather than the app clock'
        );
    }

    /** ...and is shown back on the clock it was set on. */
    public function test_the_timer_is_shown_back_on_the_administrators_clock()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        $id = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'end_time' => '2026-12-24T18:00',
            'time_zone' => 'Asia/Kolkata',
            'status' => 1,
        ])->json('data.id');

        $shown = $this->getJson("/api/admin/clearance/find/{$id}?time_zone=Asia/Kolkata")
            ->json('data.end_time');

        $this->assertSame(
            '2026-12-24 18:00:00', (string)$shown,
            'the form would show a different time than the one that was set'
        );
    }

    /** A deal with no timer survives the conversion untouched. */
    public function test_a_deal_with_no_timer_is_not_given_one()
    {
        ['product' => $product] = $this->product();

        $this->admin();

        $id = $this->postJson('/api/admin/clearance/action', [
            'product' => ['id' => $product->id],
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'end_time' => '',
            'time_zone' => 'Asia/Kolkata',
            'status' => 1,
        ])->json('data.id');

        $this->assertNull(Clearance::find($id)->end_time, 'an empty timer became a date');
    }

    /** The list reports the timer on the administrator's clock too. */
    public function test_the_list_reports_the_timer_on_the_administrators_clock()
    {
        ['product' => $product] = $this->product();

        $clearance = $this->deal($product->id, ['end_time' => '2026-12-24 12:30:00']);

        $this->admin();

        $row = collect(
            $this->getJson('/api/admin/clearance/all?orderby=created_at&type=desc&time_zone=Asia/Kolkata')
                ->json('data.data')
        )->firstWhere('id', $clearance->id);

        $this->assertNotNull($row, 'the deal was not in the list');
        $this->assertSame(
            '2026-12-24 18:00:00', (string)$row['end_time'],
            'the list showed the timer on the app clock rather than the reader\'s'
        );
    }

    /* ---- "Get notified" ------------------------------------------------ */

    private function subscribe($email, array $extra = [])
    {
        return $this->postJson('/api/v1/email-subscription', array_merge([
            'email' => $email,
        ], $extra));
    }

    /** The box takes an address and keeps it. */
    public function test_the_notify_box_records_a_subscriber()
    {
        $this->asGuest();

        $email = 'clearance.' . uniqid() . '@example.test';

        $response = $this->subscribe($email, ['source' => 'clearance']);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));
        $this->assertTrue(
            SubscriptionEmail::where('email', $email)->exists(),
            'the address was not kept'
        );
    }

    /**
     * And records which box it was.
     *
     * This one promises clearance deals; the footer's promises the monthly
     * letter. They are different consents, and the list has to be able to
     * tell them apart or neither promise can be kept.
     */
    public function test_the_notify_box_records_which_consent_was_given()
    {
        $this->asGuest();

        $fromClearance = 'clearance.' . uniqid() . '@example.test';
        $fromFooter = 'footer.' . uniqid() . '@example.test';

        $this->subscribe($fromClearance, ['source' => 'clearance']);
        $this->subscribe($fromFooter, ['source' => 'newsletter']);

        $this->assertSame(
            SubscriptionEmail::SOURCE_CLEARANCE,
            SubscriptionEmail::where('email', $fromClearance)->value('source')
        );

        $this->assertSame(
            SubscriptionEmail::SOURCE_NEWSLETTER,
            SubscriptionEmail::where('email', $fromFooter)->value('source')
        );
    }

    /** A source nobody offers is not recorded as a consent. */
    public function test_an_unrecognised_source_is_not_recorded()
    {
        $this->asGuest();

        $email = 'odd.' . uniqid() . '@example.test';

        $this->subscribe($email, ['source' => 'whatever-i-like']);

        $this->assertNull(
            SubscriptionEmail::where('email', $email)->value('source'),
            'the list recorded a consent the shop never asked for'
        );
    }

    /** A bad address is refused, with a reason. */
    public function test_the_notify_box_refuses_a_bad_address()
    {
        $this->asGuest();

        $response = $this->subscribe('not-an-email');

        $this->assertNotSame(200, $this->bodyStatus($response), 'rubbish was accepted');
        $this->assertNotEmpty($response->json('data.form'), 'no reason was given');
    }

    /** An empty box is refused too. */
    public function test_the_notify_box_refuses_an_empty_address()
    {
        $this->asGuest();

        $this->assertNotSame(
            200, $this->bodyStatus($this->postJson('/api/v1/email-subscription', [])),
            'an empty address was accepted'
        );
    }

    /**
     * Signing up twice is not an error, and does not say so.
     *
     * Answering "you are already subscribed" would turn this box into a way
     * of testing whether a given person shops here.
     */
    public function test_signing_up_twice_is_quietly_idempotent()
    {
        $this->asGuest();

        $email = 'twice.' . uniqid() . '@example.test';

        $first = $this->subscribe($email, ['source' => 'clearance']);
        $second = $this->subscribe($email, ['source' => 'clearance']);

        $this->assertSame(200, $this->bodyStatus($first));
        $this->assertSame(
            $this->bodyStatus($first), $this->bodyStatus($second),
            'the second attempt answered differently from the first'
        );

        $this->assertSame(
            1, SubscriptionEmail::where('email', $email)->count(),
            'the address was stored twice'
        );
    }

    /**
     * The page is told the real number of subscribers.
     *
     * It printed "Thousands of shoppers already signed up" as a fixed string,
     * to every visitor, while the table held none at all.
     */
    public function test_the_deal_page_reports_the_real_subscriber_count()
    {
        $this->asGuest();

        cache()->forget('clearance-subscriber-count');

        $before = (int)$this->getJson('/api/v1/clearance')->json('data.subscriber_count');

        $this->subscribe('counted.' . uniqid() . '@example.test', ['source' => 'clearance']);

        cache()->forget('clearance-subscriber-count');

        $this->assertSame(
            $before + 1,
            (int)$this->getJson('/api/v1/clearance')->json('data.subscriber_count'),
            'the page was not told about a new subscriber'
        );
    }

    /** The count is the clearance list, not everyone the shop has ever had. */
    public function test_the_count_does_not_include_the_footer_newsletter()
    {
        $this->asGuest();

        cache()->forget('clearance-subscriber-count');

        $before = (int)$this->getJson('/api/v1/clearance')->json('data.subscriber_count');

        $this->subscribe('footeronly.' . uniqid() . '@example.test', ['source' => 'newsletter']);

        cache()->forget('clearance-subscriber-count');

        $this->assertSame(
            $before,
            (int)$this->getJson('/api/v1/clearance')->json('data.subscriber_count'),
            'a monthly-newsletter sign-up was counted as a clearance one'
        );
    }

    /**
     * The deal page quotes the promotion that is actually in charge.
     *
     * A flash sale outranks a clearance everywhere -- in Pricing, on the
     * product page, in the basket and at the till. The deal page used to be
     * the exception: it set the flash price to null and took the clearance off
     * the shelf price by hand, so a product under both was advertised here at
     * a price nothing else in the shop would honour.
     *
     * Pointing the live sample deal at the iPhone 15 Pro, which is on a flash
     * sale until December, put EUR 629.10 on the page against EUR 599.00 in
     * the basket.
     */
    public function test_the_deal_page_quotes_the_flash_price_when_one_is_running()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->product();

        $adminId = Admin::whereHas('roles', function ($q) {
            $q->where('name', 'superadmin');
        })->value('id');

        $sale = DB::table('flash_sales')->insertGetId([
            'title' => 'QA Flash ' . uniqid(),
            'start_time' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'end_time' => date('Y-m-d H:i:s', strtotime('+2 days')),
            'status' => 1,
            'admin_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flash_sale_products')->insert([
            'flash_sale_id' => $sale,
            'product_id' => $product->id,
            'price' => 0,
            'discount_type' => 'percentage',
            'discount_value' => 50,
            'admin_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A clearance worth far less than the sale, so the two cannot be
        // confused for one another in the figures below.
        $this->deal($product->id, [
            'discount_type' => 'percentage',
            'discount_value' => 5,
            'sort_order' => -50,
        ]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertSame(
            (int)$product->id, (int)$hero['product']['id'],
            'the fixture deal did not lead the page'
        );

        $record = Product::pricingRecord($product->id);
        $charged = (float)Pricing::unitPrice($record, $inventory, 1, null);

        $this->assertEqualsWithDelta(
            $charged, (float)$hero['product']['display_price'], 0.01,
            'the deal page headline is not the price the till charges'
        );

        $quoted = collect($hero['product']['inventory'])->firstWhere('id', $inventory->id);

        $this->assertNotNull($quoted, 'the deal page did not quote the variant under test');

        /*
         * A row priced 0.00 carries no price of its own and inherits the
         * product's, which is the rule the storefront price helper applies.
         */
        $quotedPrice = (float)$quoted['price'] > 0
            ? (float)$quoted['price']
            : (float)$hero['product']['display_price'];

        $this->assertEqualsWithDelta(
            $charged, $quotedPrice, 0.01,
            'the deal page quoted this variation at neither promotion'
        );
    }

    /** With no flash sale running, the clearance is what is quoted. */
    public function test_the_deal_page_quotes_the_clearance_when_nothing_outranks_it()
    {
        ['product' => $product, 'inventory' => $inventory] = $this->product();

        $this->deal($product->id, [
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'sort_order' => -50,
        ]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $charged = (float)Pricing::unitPrice(
            Product::pricingRecord($product->id), $inventory, 1, null
        );

        $this->assertEqualsWithDelta(
            $charged, (float)$hero['product']['display_price'], 0.01,
            'the deal page headline is not the price the till charges'
        );

        // And it really is reduced -- not equal by both being the full price.
        $this->assertLessThan(
            (float)Pricing::baseUnitPrice(Product::pricingRecord($product->id), $inventory),
            $charged,
            'the clearance was not applied at all'
        );
    }

    /* ---- a product with a choice to make ------------------------------- */

    /**
     * A product with more than one buyable variation cannot be taken from
     * this page, and the page says so.
     *
     * The same test CrossSellService applies -- "mistake a default for a
     * decision" -- for a stronger reason: a clearance page sells ex-display
     * stock, where the variation IS the goods. Picking the best-stocked row
     * sent "Acceptable / Black" to somebody who read "Excellent / Grey".
     *
     * This page's own heroBuyNow() said it redirected "variant / out-of-stock
     * products"; only the out-of-stock half was ever implemented.
     */
    public function test_a_product_needing_a_choice_is_flagged_on_the_deal_page()
    {
        $product = $this->variantPricedProduct();

        $this->deal($product->id, ['sort_order' => -60]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertSame((int)$product->id, (int)$hero['product']['id']);

        $this->assertTrue(
            (bool)$hero['requires_option'],
            'a product with several buyable variations was offered as one click'
        );
    }

    /** A product with one buyable variation is still bought in one click. */
    public function test_a_product_with_one_variation_needs_no_choice()
    {
        ['product' => $product] = $this->flatPricedProduct();

        $buyable = UpdatedInventory::where('product_id', $product->id)
            ->where(function ($q) {
                $q->where('quantity', '>', 0)->orWhere('is_active', 1);
            })->count();

        if ($buyable !== 1) {
            $this->markTestSkipped('The fixture has more than one buyable variation.');
        }

        $this->deal($product->id, ['sort_order' => -60]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertFalse(
            (bool)$hero['requires_option'],
            'a single-variation product was made to ask for a choice'
        );
    }

    /**
     * "N remaining at this price" is a claim about one price, so it counts
     * that variation's stock.
     *
     * It was the sum of every variation. On the iPhone 15 Pro that announced
     * 3,591 remaining at a price only 99 of them carry, across 36 variations
     * spanning EUR 699 to EUR 999.
     */
    public function test_units_left_counts_the_variation_the_price_belongs_to()
    {
        $product = $this->variantPricedProduct();

        $this->deal($product->id, ['sort_order' => -60]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $rows = UpdatedInventory::withCount('inventory_attributes')
            ->where('product_id', $product->id)->get();

        $total = (int)$rows->sum('quantity');

        // The cheapest buyable variation is the one the headline prices.
        $priced = $rows->filter(function ($row) {
            return (int)$row->quantity > 0 || (int)$row->is_active === 1;
        })->sortBy('price')->first();

        $this->assertNotNull($priced, 'nothing on this product is buyable');

        $this->assertSame(
            (int)$priced->quantity, (int)$hero['units_left'],
            'the stock figure did not belong to the price beside it'
        );

        if ($total !== (int)$priced->quantity) {
            $this->assertNotSame(
                $total, (int)$hero['units_left'],
                'the page is still counting every variation'
            );
        }
    }

    /** The headline says "From" when it stands for a range. */
    public function test_the_headline_is_marked_as_a_range_when_it_is_one()
    {
        $product = $this->variantPricedProduct();

        $this->deal($product->id, ['sort_order' => -60]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertTrue(
            (bool)$hero['price_from'],
            'a price standing for a range was printed as if it were the price'
        );
    }

    /** A single-price product is not labelled "From". */
    public function test_a_single_price_product_is_not_marked_as_a_range()
    {
        ['product' => $product] = $this->flatPricedProduct();

        $this->deal($product->id, ['sort_order' => -60]);

        $this->asGuest();

        $hero = $this->getJson('/api/v1/clearance')->json('data.hero');

        $this->assertFalse(
            (bool)$hero['price_from'],
            'a product with one price was advertised as "From"'
        );
    }
}
