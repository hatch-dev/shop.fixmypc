<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CrossSell;
use App\Models\CrossSellProduct;
use App\Models\Helper\Pricing;
use App\Models\Helper\Validation;
use App\Models\Product;
use App\Models\ProductCrossSell;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Cross-sell as a shopper meets it: the block after an add, and taking what is
 * in it.
 *
 * The cases are the ones a recommendation block gets wrong. It quotes a price
 * the basket then disagrees with. It offers a product that sold out while the
 * page was open. It offers a variable product and picks a colour for the
 * customer. It creates a second line for something already in the basket. It
 * lets the page name any product id it likes and adds it. And it takes the
 * basket down with it when it fails, which is the one thing section 27 says it
 * must never do.
 *
 * As everywhere in this codebase, a refusal is status 201 in the BODY under an
 * HTTP 200, so every negative case reads the body.
 */
class CrossSellStorefrontTest extends TestCase
{
    use DatabaseTransactions;

    /* ---- fixtures ---------------------------------------------------- */

    private function asGuest()
    {
        $this->app['auth']->forgetGuards();
    }

    private function asCustomer($user)
    {
        $this->app['auth']->forgetGuards();

        Passport::actingAs($user->fresh(), ['user'], 'user');
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Cross-sell Fixture',
            'email' => 'xsell.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    /** A guest token the server would have issued. */
    private function guestToken()
    {
        $this->asGuest();

        $token = $this->postJson('/api/v1/guest/token')->json('data.user_token');

        $this->assertNotEmpty($token, 'The guest token endpoint issued nothing.');

        return $token;
    }

    /**
     * Distinct products that can actually be bought, as [product, inventory]
     * pairs -- and each with exactly ONE buyable variant, so the cases about a
     * required choice are the ones that opt into it.
     */
    private function lines($count = 3)
    {
        $rows = DB::table('updated_inventories as ui')
            ->join('products as p', 'p.id', '=', 'ui.product_id')
            ->where('p.status', 1)
            ->whereNotNull('p.selling')->where('p.selling', '>', 0)
            ->where(function ($q) {
                $q->where('ui.quantity', '>', 20)->orWhere('ui.is_active', 1);
            })
            /*
             * Products with no cross-sells of their OWN, in either direction.
             *
             * 314 relationships came across from the legacy groups. A fixture
             * that ignored them would be asserting against whatever those
             * products already recommend, and every ordering and eligibility
             * case here would be reading somebody else's configuration.
             */
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('product_cross_sells')
                    ->whereColumn('product_cross_sells.product_id', 'p.id');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('cross_sell_products')
                    ->whereColumn('cross_sell_products.product_id', 'p.id');
            })
            ->groupBy('p.id')
            ->havingRaw('COUNT(*) = 1')
            ->select('p.id as product_id', DB::raw('MIN(ui.id) as inventory_id'))
            ->limit($count)
            ->get();

        if ($rows->count() < $count) {
            $this->markTestSkipped("Need $count single-variant purchasable products.");
        }

        return $rows->map(function ($row) {
            return [
                'product_id' => (int)$row->product_id,
                'inventory_id' => (int)$row->inventory_id,
            ];
        })->values()->all();
    }

    /** A product with two or more variants somebody could buy. */
    private function variableProduct()
    {
        $productId = DB::table('updated_inventories as ui')
            ->join('products as p', 'p.id', '=', 'ui.product_id')
            ->where('p.status', 1)
            ->where(function ($q) {
                $q->where('ui.quantity', '>', 20)->orWhere('ui.is_active', 1);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('cross_sell_products')
                    ->whereColumn('cross_sell_products.product_id', 'p.id');
            })
            ->groupBy('p.id')
            ->havingRaw('COUNT(*) >= 2')
            ->value('p.id');

        if (!$productId) {
            $this->markTestSkipped('No product with two purchasable variants.');
        }

        return (int)$productId;
    }

    /**
     * A live promotion offering $targets in order, assigned to $primary --
     * the shape an admin makes under Promotions -> Cross-sell and then on the
     * product. Returns the promotion.
     */
    private function link($primary, array $targets, $max = 4)
    {
        $crossSell = CrossSell::create([
            'name' => 'QA Storefront ' . uniqid(),
            'status' => CrossSell::STATUS_ACTIVE,
            'max_products' => $max,
        ]);

        $order = 0;

        foreach ($targets as $target) {
            CrossSellProduct::create([
                'cross_sell_id' => $crossSell->id,
                'product_id' => $target,
                'sort_order' => ++$order,
            ]);
        }

        ProductCrossSell::create([
            'product_id' => $primary,
            'cross_sell_id' => $crossSell->id,
            'sort_order' => 1,
            'status' => 1,
        ]);

        return $crossSell;
    }

    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }


    /* ---- the block --------------------------------------------------- */

    /** Section 28: nobody signs in to be shown an accessory. */
    public function test_a_guest_is_shown_recommendations()
    {
        $lines = $this->lines(3);

        $this->link($lines[0]['product_id'], [
            $lines[1]['product_id'], $lines[2]['product_id'],
        ]);

        $token = $this->guestToken();

        $response = $this->getJson(
            "/api/v1/product/{$lines[0]['product_id']}/cross-sells?user_token={$token}"
        );

        $this->assertSame(200, $this->bodyStatus($response));

        $ids = collect($response->json('data.items'))->pluck('id')->map('intval')->all();

        $this->assertSame(
            [$lines[1]['product_id'], $lines[2]['product_id']], $ids,
            'the guest was not shown the configured recommendations, in order'
        );
    }

    /** Section 6: the admin's order is the shopper's order. */
    public function test_the_configured_order_is_respected()
    {
        $lines = $this->lines(3);

        $this->link($lines[0]['product_id'], [
            $lines[1]['product_id'], $lines[2]['product_id'],
        ]);

        // Reverse it the way the admin screen would.
        CrossSellProduct::where('product_id', $lines[2]['product_id'])->update(['sort_order' => 1]);
        CrossSellProduct::where('product_id', $lines[1]['product_id'])->update(['sort_order' => 2]);

        $response = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells");

        $ids = collect($response->json('data.items'))->pluck('id')->map('intval')->all();

        $this->assertSame([$lines[2]['product_id'], $lines[1]['product_id']], $ids);
    }

    /** Section 5.4: switched off means not shown, and the row survives. */
    public function test_a_switched_off_recommendation_is_not_shown()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        ProductCrossSell::where('product_id', $lines[0]['product_id'])
            ->update(['status' => 2]);

        $response = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells");

        $this->assertSame([], $response->json('data.items'));

        $this->assertSame(
            1, ProductCrossSell::where('product_id', $lines[0]['product_id'])->count(),
            'switching a recommendation off deleted it'
        );
    }

    /** Section 5.4: an unpublished product is filtered at READ time. */
    public function test_an_unpublished_product_is_not_recommended()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        Product::where('id', $lines[1]['product_id'])->update(['status' => 2]);

        $response = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells");

        $this->assertSame([], $response->json('data.items'));
    }

    /** Section 17: nothing buyable, nothing recommended. */
    public function test_a_product_with_no_buyable_variant_is_not_recommended()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        DB::table('updated_inventories')
            ->where('product_id', $lines[1]['product_id'])
            ->update(['quantity' => 0, 'is_active' => 0]);

        $response = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells");

        $this->assertSame([], $response->json('data.items'));
    }

    /**
     * Section 7: the limit caps DISPLAY, and the extras are what covers a
     * sold-out recommendation rather than leaving a gap.
     */
    public function test_the_display_limit_caps_the_list()
    {
        $lines = $this->lines(3);

        $this->link($lines[0]['product_id'], [
            $lines[1]['product_id'], $lines[2]['product_id'],
        ]);

        CrossSell::query()->update(['max_products' => 1]);

        $response = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells");

        $this->assertCount(1, $response->json('data.items'));
    }


    /**
     * Section 15, and the reason this test exists at all.
     *
     * The price in the block has to be the price the basket charges. Asserted
     * against the catalogue through Pricing -- the same call
     * CartsController::serverSidePricing makes -- rather than against a number
     * typed here, because a figure typed into a test only proves the test.
     */
    public function test_the_quoted_price_is_the_price_the_basket_charges()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $response = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells");

        $item = $response->json('data.items.0');

        $this->assertNotNull($item, 'nothing was recommended to price');

        $expected = Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($lines[1]['product_id']),
            \App\Models\UpdatedInventory::find($item['inventory_id']),
            1,
            collect()
        ));

        $this->assertSame(
            $expected, Pricing::round((float)$item['price']),
            'the block quotes a price the basket would not charge'
        );
    }

    /**
     * Section 16. A business account with a band is quoted the band, and it is
     * quoted the SAME figure the basket will use.
     */
    public function test_a_business_account_is_quoted_its_own_price()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $retail = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells")
            ->json('data.items.0.price');

        $customer = $this->customer(['account_type' => 'business']);

        $this->asCustomer($customer);

        $business = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells")
            ->json('data.items.0.price');

        /*
         * Not asserted to be cheaper: this fixture product may have no band at
         * all, and Pricing::businessPrice never RAISES a price. What has to
         * hold is that the figure is the one that account will be charged --
         * so it is compared against the same call the basket makes.
         */
        $expected = Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($lines[1]['product_id']),
            \App\Models\UpdatedInventory::find($lines[1]['inventory_id']),
            1,
            \App\Models\BusinessProductPricing::forCustomer($customer, $lines[1]['product_id'])
        ));

        $this->assertSame($expected, Pricing::round((float)$business));
        $this->assertLessThanOrEqual(Pricing::round((float)$retail), Pricing::round((float)$business));
    }

/**
     * Section 16, the trade half.
     *
     * Trade goes through the same entitlement gate as business
     * (`hasBusinessPricing`), so what matters is that an APPROVED trade account
     * is quoted its bands and a PENDING one is not -- the trade status is a
     * separate fact from the account type, and reading only the type is how a
     * pending applicant gets trade rates.
     */
    public function test_an_approved_trade_account_is_quoted_its_own_price()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $customer = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
        ]);

        $this->asCustomer($customer);

        $quoted = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells")
            ->json('data.items.0.price');

        $expected = Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($lines[1]['product_id']),
            \App\Models\UpdatedInventory::find($lines[1]['inventory_id']),
            1,
            \App\Models\BusinessProductPricing::forCustomer($customer, $lines[1]['product_id'])
        ));

        $this->assertSame($expected, Pricing::round((float)$quoted));

        // And it is the figure the basket then charges.
        $this->postJson('/api/v1/cart/cross-sell', [
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [['product_id' => $lines[1]['product_id']]],
        ]);

        $row = Cart::where('user_id', $customer->id)
            ->where('product_id', $lines[1]['product_id'])
            ->first();

        $this->assertNotNull($row, 'the trade account could not take the recommendation');
        $this->assertSame($expected, Pricing::round((float)$row->price));
    }

    /**
     * A pending trade applicant is a retail customer until somebody approves
     * them, on this surface as on every other.
     */
    public function test_a_pending_trade_account_is_quoted_retail()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $guestPrice = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells")
            ->json('data.items.0.price');

        $this->asCustomer($this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]));

        $pendingPrice = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells")
            ->json('data.items.0.price');

        $this->assertSame(
            Pricing::round((float)$guestPrice), Pricing::round((float)$pendingPrice),
            'a pending trade applicant was quoted something other than retail'
        );
    }

    /**
     * Section 14. A product with a real choice arrives with no variant chosen
     * for the customer, and carries the options so they can choose.
     */
    public function test_a_variable_product_asks_the_customer_to_choose()
    {
        $lines = $this->lines(1);
        $variable = $this->variableProduct();

        if ($variable === $lines[0]['product_id']) {
            $this->markTestSkipped('The fixture products overlap.');
        }

        $this->link($lines[0]['product_id'], [$variable]);

        $item = $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells")
            ->json('data.items.0');

        $this->assertNotNull($item);
        $this->assertTrue($item['requires_option'], 'a variable product was offered without a choice');
        $this->assertNull($item['inventory_id'], 'a variant was picked on the customer behalf');
        $this->assertGreaterThan(1, count($item['options']));
    }

    /** Section 26: no recommendations is an empty list, not an error. */
    public function test_a_product_with_no_cross_sells_answers_with_an_empty_list()
    {
        $product = Product::where('status', 1)
            ->whereDoesntHave('crossSellAssignments')
            ->first();

        if (!$product) {
            $this->markTestSkipped('Every published product has cross-sells.');
        }

        $response = $this->getJson("/api/v1/product/{$product->id}/cross-sells");

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertSame([], $response->json('data.items'));
    }


    /* ---- taking them ------------------------------------------------- */

    /** Section 10 and 11: only what was ticked, priced by the server. */
    public function test_a_guest_can_add_the_recommendations_they_ticked()
    {
        $lines = $this->lines(3);

        $this->link($lines[0]['product_id'], [
            $lines[1]['product_id'], $lines[2]['product_id'],
        ]);

        $token = $this->guestToken();

        // The primary product first, the way the real flow does it.
        $this->postJson('/api/v1/cart/action', [
            'user_token' => $token,
            'product_id' => $lines[0]['product_id'],
            'inventory_id' => $lines[0]['inventory_id'],
            'quantity' => 1,
        ]);

        $response = $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [
                ['product_id' => $lines[1]['product_id']],
            ],
        ]);

        $this->assertSame(200, $this->bodyStatus($response), (string)$response->json('message'));

        $inBasket = Cart::where('user_token', $token)->pluck('product_id')->map('intval')->all();

        $this->assertContains($lines[1]['product_id'], $inBasket);

        // The one that was NOT ticked stays out of the basket.
        $this->assertNotContains($lines[2]['product_id'], $inBasket);
    }

    /**
     * Section 34. The endpoint adds RECOMMENDATIONS, not anything the page
     * names -- otherwise it is an unrestricted write wearing a cross-sell
     * name.
     */
    public function test_a_product_that_is_not_a_recommendation_is_refused()
    {
        $lines = $this->lines(3);

        // Only lines[1] is a recommendation of lines[0]. lines[2] is not.
        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $token = $this->guestToken();

        $response = $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [
                ['product_id' => $lines[2]['product_id']],
            ],
        ]);

        $this->assertSame(201, $this->bodyStatus($response));

        $this->assertSame(
            0, Cart::where('user_token', $token)
                ->where('product_id', $lines[2]['product_id'])->count(),
            'a product nobody recommended was added to the basket'
        );
    }

    /**
     * Section 12. An accessory already in the basket gains quantity; it does
     * not gain a second line.
     */
    public function test_taking_something_already_in_the_basket_merges_the_quantity()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $token = $this->guestToken();

        foreach ([$lines[0], $lines[1]] as $line) {
            $this->postJson('/api/v1/cart/action', [
                'user_token' => $token,
                'product_id' => $line['product_id'],
                'inventory_id' => $line['inventory_id'],
                'quantity' => 1,
            ]);
        }

        $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [
                ['product_id' => $lines[1]['product_id'], 'quantity' => 1],
            ],
        ]);

        $rows = Cart::where('user_token', $token)
            ->where('product_id', $lines[1]['product_id'])
            ->get();

        $this->assertCount(1, $rows, 'a duplicate basket line was created');
        $this->assertSame(2, (int)$rows->first()->quantity);
    }

    /**
     * Section 13. What the basket already holds is not offered again -- which
     * is also what stops a customer being invited to add it twice.
     */
    public function test_something_already_in_the_basket_is_not_recommended_again()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $token = $this->guestToken();

        $this->postJson('/api/v1/cart/action', [
            'user_token' => $token,
            'product_id' => $lines[1]['product_id'],
            'inventory_id' => $lines[1]['inventory_id'],
            'quantity' => 1,
        ]);

        $items = $this->getJson(
            "/api/v1/product/{$lines[0]['product_id']}/cross-sells?user_token={$token}"
        )->json('data.items');

        $this->assertSame([], $items);
    }


    /**
     * Section 14 and 34. A variable product cannot be added without a choice,
     * and a variant borrowed from a different product is refused.
     */
    public function test_a_variable_product_cannot_be_added_without_a_choice()
    {
        $lines = $this->lines(1);
        $variable = $this->variableProduct();

        if ($variable === $lines[0]['product_id']) {
            $this->markTestSkipped('The fixture products overlap.');
        }

        $this->link($lines[0]['product_id'], [$variable]);

        $token = $this->guestToken();

        $response = $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [['product_id' => $variable]],
        ]);

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertSame(0, Cart::where('user_token', $token)->count());
    }

    public function test_a_variant_belonging_to_another_product_is_refused()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $token = $this->guestToken();

        $response = $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [[
                'product_id' => $lines[1]['product_id'],
                // The primary product's variant, under the accessory's id.
                'inventory_id' => $lines[0]['inventory_id'],
            ]],
        ]);

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertSame(0, Cart::where('user_token', $token)->count());
    }

    /**
     * Section 17: the stock check runs AGAIN at the click, not only when the
     * block was drawn.
     */
    public function test_a_recommendation_that_sold_out_since_the_page_loaded_is_refused()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $token = $this->guestToken();

        // Drawn while it was buyable...
        $this->assertCount(
            1,
            $this->getJson("/api/v1/product/{$lines[0]['product_id']}/cross-sells")->json('data.items')
        );

        // ...and gone by the click.
        DB::table('updated_inventories')
            ->where('product_id', $lines[1]['product_id'])
            ->update(['quantity' => 0, 'is_active' => 0]);

        $response = $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [['product_id' => $lines[1]['product_id']]],
        ]);

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertSame(0, Cart::where('user_token', $token)->count());
    }

    /**
     * Partial success is a real outcome.
     *
     * Two ticks where one has sold out adds the other and says which one did
     * not go. Refusing both because of one would be worse for the shopper than
     * the thing being guarded against.
     */
    public function test_one_unavailable_product_does_not_block_the_others()
    {
        $lines = $this->lines(3);

        $this->link($lines[0]['product_id'], [
            $lines[1]['product_id'], $lines[2]['product_id'],
        ]);

        DB::table('updated_inventories')
            ->where('product_id', $lines[2]['product_id'])
            ->update(['quantity' => 0, 'is_active' => 0]);

        $token = $this->guestToken();

        $response = $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [
                ['product_id' => $lines[1]['product_id']],
                ['product_id' => $lines[2]['product_id']],
            ],
        ]);

        $this->assertSame(200, $this->bodyStatus($response));
        $this->assertCount(1, $response->json('data.added'));
        $this->assertCount(1, $response->json('data.skipped'));

        $this->assertSame(
            $lines[2]['product_id'],
            (int)$response->json('data.skipped.0.product_id'),
            'the response does not say which product could not be added'
        );
    }

    /** Section 34: the line is priced by the server, never by the page. */
    public function test_a_price_sent_by_the_page_is_ignored()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $token = $this->guestToken();

        $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [[
                'product_id' => $lines[1]['product_id'],
                'price' => 0.01,
            ]],
        ]);

        $row = Cart::where('user_token', $token)
            ->where('product_id', $lines[1]['product_id'])
            ->first();

        $this->assertNotNull($row);

        $expected = Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($lines[1]['product_id']),
            \App\Models\UpdatedInventory::find($row->inventory_id),
            1,
            collect()
        ));

        $this->assertSame($expected, Pricing::round((float)$row->price));
    }


    /**
     * Section 20. Removing the primary product leaves the accessory in the
     * basket. They are independent purchases; nothing links them once taken.
     */
    public function test_removing_the_primary_product_leaves_the_accessory_behind()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $token = $this->guestToken();

        $this->postJson('/api/v1/cart/action', [
            'user_token' => $token,
            'product_id' => $lines[0]['product_id'],
            'inventory_id' => $lines[0]['inventory_id'],
            'quantity' => 1,
        ]);

        $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [['product_id' => $lines[1]['product_id']]],
        ]);

        $primaryRow = Cart::where('user_token', $token)
            ->where('product_id', $lines[0]['product_id'])
            ->first();

        $this->deleteJson("/api/v1/cart/delete/{$primaryRow->id}", ['user_token' => $token]);

        $this->assertSame(
            1,
            Cart::where('user_token', $token)
                ->where('product_id', $lines[1]['product_id'])->count(),
            'the accessory was removed along with the product that recommended it'
        );
    }

    /**
     * Section 18. Once taken, a cross-sell line is an ORDINARY basket line --
     * it carries the same columns and is read back by the same endpoint as
     * everything else, with no marker of where it came from.
     */
    public function test_a_taken_recommendation_is_an_ordinary_basket_line()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $customer = $this->customer();

        $this->asCustomer($customer);

        $this->postJson('/api/v1/cart/cross-sell', [
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [['product_id' => $lines[1]['product_id']]],
        ]);

        $basket = $this->getJson('/api/v1/cart/by-user')->json('data');

        $row = collect($basket['carts'] ?? $basket)
            ->first(function ($line) use ($lines) {
                return (int)($line['product_id'] ?? 0) === $lines[1]['product_id'];
            });

        $this->assertNotNull($row, 'the cross-sell line is not in the ordinary basket read');

        /*
         * The fields the basket, the summary and checkout read -- the ones
         * byUser answers with for every line, cross-sell or not. carts.price
         * is a guarded column and is reported as unit_price, so that is the
         * name asserted here rather than the column's.
         */
        foreach (['quantity', 'unit_price', 'line_total', 'product_id', 'inventory_id'] as $field) {
            $this->assertArrayHasKey($field, $row, 'a cross-sell line is missing ' . $field);
        }

        // And nothing marks it as having come from a recommendation: once
        // taken, it is an ordinary line (section 18).
        $this->assertArrayNotHasKey('cross_sell', $row);
    }

    /**
     * Section 29. A guest basket built from recommendations survives signing
     * in, through the existing merge -- nothing about a cross-sell line is
     * special to it.
     */
    public function test_a_taken_recommendation_survives_signing_in()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $token = $this->guestToken();

        $this->postJson('/api/v1/cart/cross-sell', [
            'user_token' => $token,
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [['product_id' => $lines[1]['product_id']]],
        ]);

        $customer = $this->customer();

        $this->asCustomer($customer);

        $this->postJson('/api/v1/cart/merge', ['user_token' => $token]);

        $this->assertSame(
            1,
            Cart::where('user_id', $customer->id)
                ->where('product_id', $lines[1]['product_id'])->count(),
            'the accessory did not survive the guest basket merge'
        );
    }

    /** An unidentified caller has no basket to write to. */
    public function test_an_unidentified_caller_cannot_add_anything()
    {
        $lines = $this->lines(2);

        $this->link($lines[0]['product_id'], [$lines[1]['product_id']]);

        $this->asGuest();

        $before = Cart::count();

        $response = $this->postJson('/api/v1/cart/cross-sell', [
            'primary_product_id' => $lines[0]['product_id'],
            'products' => [['product_id' => $lines[1]['product_id']]],
        ]);

        $this->assertNotSame(200, $this->bodyStatus($response));
        $this->assertSame($before, Cart::count());
    }

    /**
     * Section 13 again, from the basket side: three primary products that all
     * recommend the same accessory offer it ONCE.
     */
    public function test_the_basket_deduplicates_a_shared_recommendation()
    {
        $lines = $this->lines(3);

        // Both lines[0] and lines[1] recommend lines[2].
        $this->link($lines[0]['product_id'], [$lines[2]['product_id']]);
        $this->link($lines[1]['product_id'], [$lines[2]['product_id']]);

        $token = $this->guestToken();

        foreach ([$lines[0], $lines[1]] as $line) {
            $this->postJson('/api/v1/cart/action', [
                'user_token' => $token,
                'product_id' => $line['product_id'],
                'inventory_id' => $line['inventory_id'],
                'quantity' => 1,
            ]);
        }

        $items = $this->getJson("/api/v1/cart/cross-sells?user_token={$token}")
            ->json('data.items');

        $ids = collect($items)->pluck('id')->map('intval')->all();

        $this->assertSame([$lines[2]['product_id']], $ids);
    }


    /* ---- cost -------------------------------------------------------- */

    /** Queries run while building the block for one product, as a guest. */
    private function queriesForBlock($primary)
    {
        $this->asGuest();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson("/api/v1/product/{$primary}/cross-sells");

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame(200, (int)$response->json('status'),
            'the block did not load: ' . json_encode($response->json('message')));

        return $count;
    }

    /**
     * Section 13: the block must not query per recommendation.
     *
     * Every product in it used to cost three more statements -- its pricing
     * record plus that record's two eager loads -- so a promotion offering
     * eight accessories ran twenty-four queries nobody could see. The cards
     * are priced from one batched read now, and the cost of one more
     * recommendation is close to nothing.
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_recommendations()
    {
        $lines = $this->lines(4);

        $primary = array_shift($lines);

        /*
         * The same primary and the same promotion both times, so the only
         * thing that differs between the two measurements is how many
         * products are being priced.
         */
        $promotion = $this->link($primary['product_id'], [$lines[0]['product_id']]);

        $forOne = $this->queriesForBlock($primary['product_id']);

        foreach ([$lines[1], $lines[2]] as $index => $line) {
            CrossSellProduct::create([
                'cross_sell_id' => $promotion->id,
                'product_id' => $line['product_id'],
                'sort_order' => $index + 2,
            ]);
        }

        $forThree = $this->queriesForBlock($primary['product_id']);

        /*
         * Some drift is fine: an eager load is skipped entirely when its
         * relation comes back empty, so two more products can legitimately
         * add a statement or two. Six more would mean the per-product read is
         * back.
         */
        $this->assertLessThanOrEqual(
            $forOne + 3, $forThree,
            "two extra recommendations cost " . ($forThree - $forOne)
                . " extra queries; the block is pricing per product again"
        );
    }

    /**
     * The batched pricing record has to BE the single one.
     *
     * This is the join the whole module's promise rests on -- that the price
     * on a card is the price the basket charges -- and it now has two callers.
     * A column present in one and missing from the other would not fail
     * anything loudly; it would quietly change a price.
     */
    public function test_the_batched_pricing_record_matches_the_single_one()
    {
        $lines = $this->lines(3);

        $ids = collect($lines)->pluck('product_id')->all();

        $batched = Product::pricingRecords($ids);

        foreach ($ids as $id) {
            $single = Product::pricingRecord($id);

            $this->assertNotNull($batched->get($id), "product {$id} missing from the batch");

            $this->assertSame(
                $single->getAttributes(),
                $batched->get($id)->getAttributes(),
                "the batched record for product {$id} differs from the single one"
            );
        }
    }
}
