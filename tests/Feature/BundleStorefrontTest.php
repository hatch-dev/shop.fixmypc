<?php

namespace Tests\Feature;

use App\Models\BundleDeal;
use App\Models\BundleDealProducts;
use App\Models\Cart;
use App\Models\Helper\BundleDiscount;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Services\BundleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The Essentials Bundle, as a shopper meets it.
 *
 * A bundle is ALL OR NOTHING, and every case here comes back to that. It is
 * the difference between this and the recommendation block beside it: a
 * cross-sell that loses a product is a shorter list, but a five-item bundle
 * that loses an item is not the bundle whose price is on the label -- so it is
 * not shown, and it cannot be half-added.
 *
 * The other half is the one rule the whole card exists under: the figure
 * quoted has to be the figure charged. The block used to price bundle items at
 * the PRODUCT level while the basket charges the variant, and then took the
 * admin's percentage off the result -- a discount nothing in the basket could
 * honour, since carts.price is rewritten from the catalogue on every pass.
 *
 * As everywhere in this codebase, a refusal is status 201 in the BODY under an
 * HTTP 200, so every negative case reads the body.
 */
class BundleStorefrontTest extends TestCase
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
            'name' => 'Bundle Fixture',
            'email' => 'bundle.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    private function guestToken()
    {
        $this->asGuest();

        $token = $this->postJson('/api/v1/guest/token')->json('data.user_token');

        $this->assertNotEmpty($token, 'The guest token endpoint issued nothing.');

        return $token;
    }

    /**
     * Products with exactly one buyable variant, so "the variant the bundle
     * quotes" is unambiguous in every case that is not about variants.
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
            ->groupBy('p.id')
            ->havingRaw('COUNT(*) = 1')
            ->select('p.id as product_id', DB::raw('MIN(ui.id) as inventory_id'))
            /*
             * Ordered, because LIMIT without it is whatever the engine hands
             * back -- and that changes as earlier tests write carts and bundle
             * rows. A fixture that quietly picks different products from one
             * run to the next fails somewhere unrelated and looks like a bug
             * in the thing under test.
             */
            ->orderBy('p.id')
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

    /**
     * A bundle holding $items, assigned to $primary -- the shape an admin
     * makes under Bundle Deals and then on the product.
     */
    private function bundle($primary, array $items, $discountValue = 10)
    {
        $bundle = BundleDeal::create([
            'title' => 'QA Essentials ' . uniqid(),
            'description' => 'Everything you need.',
            'discount_type' => 'percentage',
            'discount_value' => $discountValue,
            'buy' => 0,
            'free' => 0,
            // NOT NULL with no default, and $hidden on the model rather than
            // optional.
            'admin_id' => DB::table('admins')->value('id'),
        ]);

        foreach ($items as $item) {
            BundleDealProducts::create([
                'bundle_deal_id' => $bundle->id,
                'product_id' => $item,
            ]);
        }

        Product::where('id', $primary)->update(['bundle_deal_id' => (string)$bundle->id]);

        return $bundle;
    }

    /** The bundle payload the product page is given. */
    private function blockFor($primary, $token = null)
    {
        $url = "/api/v1/product/upsell-crosssell/{$primary}";

        if ($token) {
            $url .= "?user_token={$token}";
        }

        return $this->getJson($url)->json('bundle_deal');
    }

    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }


    /* ---- the block --------------------------------------------------- */

    public function test_a_bundle_is_shown_with_its_items_and_their_variants()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $this->asGuest();

        $bundles = $this->blockFor($primary['product_id']);

        $this->assertCount(1, $bundles, 'the bundle was not offered');

        $ids = collect($bundles[0]['items'])->pluck('id')->map('intval')->all();

        $this->assertSame(collect($lines)->pluck('product_id')->all(), $ids);

        /*
         * The variant travels with the price. Without it the block cannot say
         * what the basket should add, which is why the section did nothing at
         * all before.
         */
        foreach ($bundles[0]['items'] as $index => $item) {
            $this->assertSame(
                $lines[$index]['inventory_id'], (int)$item['inventory_id'],
                'the bundle quoted a different variant from the buyable one'
            );
        }
    }

    /**
     * The headline is the sum of the items, and the items are priced the way
     * the basket prices them.
     */
    public function test_the_quoted_total_is_the_sum_of_what_the_basket_charges()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $this->asGuest();

        $bundle = $this->blockFor($primary['product_id'])[0];

        $expected = 0;

        foreach ($lines as $line) {
            $expected += Pricing::round(Pricing::unitPrice(
                Product::pricingRecord($line['product_id']),
                UpdatedInventory::find($line['inventory_id']),
                1,
                null
            ));
        }

        $this->assertEquals(
            Pricing::round($expected), (float)$bundle['lines_total'],
            'the bundle lines total is not the sum of its lines at basket prices'
        );

        $this->assertEquals(
            Pricing::round($expected),
            Pricing::round(collect($bundle['items'])->sum('price')),
            'the headline disagrees with the items under it'
        );

        /*
         * And the price on the label is that, less the bundle's own discount.
         * The discount is real -- carts.bundle_deal_id marks the lines and
         * Helper\BundleDiscount re-derives it at the basket and again at
         * checkout -- so the block is allowed to quote it.
         */
        $this->assertEquals(
            Pricing::round($expected - (float)$bundle['discount']),
            (float)$bundle['price'],
            'the quoted price is not the lines less the discount'
        );
    }

    /**
     * The admin's percentage is the discount, and it is applied to the label.
     *
     * It is only safe to quote because nothing banks it on a line: each line
     * remembers which bundle it came in under and the amount is re-derived
     * wherever money is worked out. BundleCheckoutTest proves the other half
     * -- that the order then charges exactly this.
     */
    public function test_the_admin_discount_is_applied_to_the_quoted_price()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all(), 25);

        $this->asGuest();

        $bundle = $this->blockFor($primary['product_id'])[0];

        $sum = Pricing::round(collect($bundle['items'])->sum('price'));

        $this->assertEquals($sum, (float)$bundle['lines_total']);

        $this->assertEquals(
            Pricing::round($sum * 0.25), (float)$bundle['discount'],
            'the admin percentage was not the discount'
        );

        $this->assertEquals(
            Pricing::round($sum * 0.75), (float)$bundle['price'],
            'the label does not show the discounted price'
        );

        $this->assertEquals(25.0, (float)$bundle['discount_value']);
    }

    public function test_a_bundle_with_a_sold_out_item_is_not_offered()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        UpdatedInventory::where('id', $lines[1]['inventory_id'])
            ->update(['quantity' => 0, 'is_active' => 0]);

        $this->asGuest();

        $this->assertSame([], $this->blockFor($primary['product_id']),
            'a bundle missing an item is still that bundle at that price');
    }

    /** Backorder is buyable everywhere else in this shop, and here too. */
    public function test_an_item_on_backorder_keeps_the_bundle()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        UpdatedInventory::where('id', $lines[1]['inventory_id'])
            ->update(['quantity' => 0, 'is_active' => 1]);

        $this->asGuest();

        $this->assertCount(1, $this->blockFor($primary['product_id']),
            'a backorderable item was treated as out of stock');
    }

    public function test_an_unpublished_item_is_not_offered()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        Product::where('id', $lines[0]['product_id'])->update(['status' => 2]);

        $this->asGuest();

        $this->assertSame([], $this->blockFor($primary['product_id']));
    }

    public function test_a_product_with_no_bundle_is_offered_none()
    {
        $lines = $this->lines(1);

        Product::where('id', $lines[0]['product_id'])->update(['bundle_deal_id' => null]);

        $this->asGuest();

        $this->assertSame([], $this->blockFor($lines[0]['product_id']));
    }


    /* ---- taking one -------------------------------------------------- */

    public function test_a_guest_can_add_a_whole_bundle()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $token = $this->guestToken();

        $response = $this->postJson('/api/v1/cart/bundle', [
            'user_token' => $token,
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ]);

        $this->assertSame(200, $this->bodyStatus($response),
            'the bundle was refused: ' . json_encode($response->json('message')));

        $inBasket = Cart::where('user_token', $token)
            ->pluck('product_id')->map('intval')->sort()->values()->all();

        $this->assertSame(
            collect($lines)->pluck('product_id')->sort()->values()->all(), $inBasket,
            'the basket does not hold exactly the bundle'
        );
    }

    /** The label and the till, compared directly. */
    public function test_the_basket_charges_what_the_bundle_quoted()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $this->asGuest();

        $block = $this->blockFor($primary['product_id']);

        $this->assertCount(1, $block, 'the bundle was not offered, so there is nothing to compare');

        $quoted = (float)$block[0]['price'];
        $linesTotal = (float)$block[0]['lines_total'];
        $discount = (float)$block[0]['discount'];

        $token = $this->guestToken();

        $this->postJson('/api/v1/cart/bundle', [
            'user_token' => $token,
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ]);

        $rows = Cart::where('user_token', $token)->get();

        $charged = Pricing::round($rows->sum(function ($row) {
            return (float)$row->price * (int)$row->quantity;
        }));

        /*
         * The lines carry catalogue prices -- the bundle discount is a
         * basket-level figure re-derived from carts.bundle_deal_id, not
         * something written onto a row, because a row's price is rewritten on
         * every pricing pass. So the two halves are checked separately, and
         * together they have to make the quoted price.
         */
        $this->assertEquals($linesTotal, $charged,
            'the basket lines do not add up to what the bundle quoted for them');

        $this->assertEquals(
            $discount, BundleDiscount::totalFor($rows),
            'the basket does not grant the discount the bundle quoted'
        );

        $this->assertEquals(
            $quoted, Pricing::round($charged - BundleDiscount::totalFor($rows)),
            'the bundle quoted one figure and the basket holds another'
        );
    }

    public function test_a_bundle_not_assigned_to_this_product_is_refused()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        // Ask for it under a product it is not offered under.
        $other = $lines[0]['product_id'];

        Product::where('id', $other)->update(['bundle_deal_id' => null]);

        $token = $this->guestToken();

        $response = $this->postJson('/api/v1/cart/bundle', [
            'user_token' => $token,
            'product_id' => $other,
            'bundle_id' => $bundle->id,
        ]);

        $this->assertSame(201, $this->bodyStatus($response));
        $this->assertSame(0, Cart::where('user_token', $token)->count());
    }

    /**
     * The whole point of all-or-nothing: an item that sold out between the
     * block and the click takes the bundle with it, and leaves the basket
     * exactly as it was rather than holding four fifths of a set price.
     */
    public function test_a_bundle_that_lost_an_item_adds_nothing_at_all()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $token = $this->guestToken();

        UpdatedInventory::where('id', $lines[1]['inventory_id'])
            ->update(['quantity' => 0, 'is_active' => 0]);

        $response = $this->postJson('/api/v1/cart/bundle', [
            'user_token' => $token,
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ]);

        $this->assertSame(201, $this->bodyStatus($response));

        $this->assertSame(
            0, Cart::where('user_token', $token)->count(),
            'part of an incomplete bundle reached the basket'
        );
    }

    public function test_taking_a_bundle_twice_merges_rather_than_duplicates()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $token = $this->guestToken();

        $payload = [
            'user_token' => $token,
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ];

        $this->postJson('/api/v1/cart/bundle', $payload);
        $this->postJson('/api/v1/cart/bundle', $payload);

        $rows = Cart::where('user_token', $token)->get();

        $this->assertCount(2, $rows, 'a second add created new lines');

        foreach ($rows as $row) {
            $this->assertSame(2, (int)$row->quantity);
        }
    }

    public function test_an_unidentified_caller_cannot_add_a_bundle()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $this->asGuest();

        $response = $this->postJson('/api/v1/cart/bundle', [
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ]);

        $this->assertNotSame(200, $this->bodyStatus($response));
    }

    /**
     * A bundle whose id ends in a zero is still that bundle.
     *
     * `products.bundle_deal_id` is a comma-separated VARCHAR, so reading it
     * means splitting and trimming strings. Collection::map calls back with
     * (value, KEY), so the tidy-looking `->map('trim')` is really
     * trim($id, $key) -- and trim's second argument is the list of characters
     * to strip. Bundle 160 was read as bundle 16, which does not exist, so the
     * Essentials Bundle silently disappeared for one product in ten and came
     * back by itself when the id changed. Nothing errored.
     */
    public function test_a_bundle_id_ending_in_zero_is_read_whole()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        // Built at an id ending in 0, rather than moved onto one: the child
        // rows carry a foreign key, so the parent cannot be renumbered.
        $roundId = ((int)(DB::table('bundle_deals')->max('id') / 10) + 1) * 10;

        DB::table('bundle_deals')->insert([
            'id' => $roundId,
            'title' => 'QA Round Id',
            'description' => 'Everything you need.',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'buy' => 0,
            'free' => 0,
            'admin_id' => DB::table('admins')->value('id'),
        ]);

        foreach ($lines as $line) {
            BundleDealProducts::create([
                'bundle_deal_id' => $roundId,
                'product_id' => $line['product_id'],
            ]);
        }

        Product::where('id', $primary['product_id'])
            ->update(['bundle_deal_id' => (string)$roundId]);

        $this->asGuest();

        $this->assertCount(
            1, $this->blockFor($primary['product_id']),
            "bundle {$roundId} was not found, so its trailing zero was eaten"
        );
    }

    /**
     * Several bundles on one product, which is what the comma-separated column
     * is for -- and the case where `->map('intval')` would turn every id after
     * the first into 0, because intval's second argument is the number base.
     */
    public function test_a_product_can_offer_more_than_one_bundle()
    {
        $lines = $this->lines(4);
        $primary = array_shift($lines);

        $first = $this->bundle($primary['product_id'], [$lines[0]['product_id']]);
        $second = $this->bundle($primary['product_id'], [$lines[1]['product_id']]);

        // bundle() assigns one at a time; both, as an admin would.
        Product::where('id', $primary['product_id'])
            ->update(['bundle_deal_id' => "{$first->id}, {$second->id}"]);

        $this->asGuest();

        $ids = collect($this->blockFor($primary['product_id']))->pluck('id')->map('intval')->sort()->values()->all();

        $this->assertSame(
            collect([$first->id, $second->id])->map('intval')->sort()->values()->all(), $ids,
            'both assigned bundles should be offered'
        );
    }

    /** Section 13: the block must not query per bundle item. */
    public function test_the_query_count_does_not_grow_with_the_bundle_size()
    {
        $lines = $this->lines(4);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], [$lines[0]['product_id']]);

        $this->asGuest();

        $queries = function () use ($primary) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/product/upsell-crosssell/{$primary['product_id']}");
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $forOne = $queries();

        foreach ([$lines[1], $lines[2]] as $line) {
            BundleDealProducts::create([
                'bundle_deal_id' => $bundle->id,
                'product_id' => $line['product_id'],
            ]);
        }

        $forThree = $queries();

        $this->assertLessThanOrEqual(
            $forOne + 3, $forThree,
            'two extra bundle items cost ' . ($forThree - $forOne)
                . ' extra queries; the bundle is reading per item'
        );
    }

    /* ---- simple products and variation products ---------------------- */

    /**
     * A bundle may hold simple and variation products together.
     *
     * `bundle_deal_products` stores a PRODUCT, never a variant, so an item
     * with thirty-six variations is as valid a member as a cable with one.
     * The server resolves the variant itself and sends its id with the price,
     * which is what stops the basket charging for a different one.
     */
    public function test_a_bundle_may_mix_simple_and_variation_products()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $variantProduct = Product::whereHas('product_inventories', null, '>=', 2)
            ->where('status', config('constants.status.PUBLIC'))
            ->whereNotIn('id', collect($lines)->pluck('product_id'))
            ->where('id', '!=', $primary['product_id'])
            ->first();

        if (!$variantProduct) {
            $this->markTestSkipped('No multi-variant product to bundle.');
        }

        UpdatedInventory::where('product_id', $variantProduct->id)->update(['quantity' => 50]);

        $this->bundle($primary['product_id'], [
            $lines[0]['product_id'],      // one variant
            (int)$variantProduct->id,     // many
        ]);

        $this->asGuest();

        $block = $this->blockFor($primary['product_id']);

        $this->assertCount(1, $block, 'a mixed bundle was not offered');

        $item = collect($block[0]['items'])->firstWhere('id', (int)$variantProduct->id);

        $this->assertNotNull($item, 'the variation product was dropped from the set');

        $this->assertGreaterThan(0, (int)$item['inventory_id'],
            'no variant was resolved, so the basket would have nothing to charge');

        $this->assertTrue(
            UpdatedInventory::where('id', $item['inventory_id'])
                ->where('product_id', $variantProduct->id)->exists(),
            'the resolved variant does not belong to the product it was chosen for'
        );
    }

    /**
     * For a variation product the CHEAPEST purchasable variant is chosen, and
     * the shopper is never asked which one they want.
     *
     * Deliberate -- it is the same choice Pricing::displayRange makes for a
     * headline price, and it is what lets the block quote a figure the basket
     * will honour. Pinned here because it is a real limitation rather than an
     * accident: cross-sell asks the customer to pick when a product has more
     * than one option (`requires_option`), and upsell lets the admin name a
     * variant outright (`upsells.inventory_id`). A bundle does neither, and
     * the card carries no variant label, so a set containing a product sold
     * in sizes silently means "the cheapest size".
     */
    public function test_a_variation_product_is_taken_at_its_cheapest_buyable_variant()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $variantProduct = Product::whereHas('product_inventories', null, '>=', 3)
            ->where('status', config('constants.status.PUBLIC'))
            ->where('id', '!=', $primary['product_id'])
            ->whereNotIn('id', collect($lines)->pluck('product_id'))
            ->first();

        if (!$variantProduct) {
            $this->markTestSkipped('No product with three variants to bundle.');
        }

        $variants = UpdatedInventory::where('product_id', $variantProduct->id)
            ->orderBy('id')->get();

        // A known ladder, all buyable.
        foreach ($variants->take(3)->values() as $index => $variant) {
            UpdatedInventory::where('id', $variant->id)->update([
                'price' => [300, 200, 100][$index],
                'quantity' => 50,
                'is_active' => 0,
            ]);
        }

        UpdatedInventory::where('product_id', $variantProduct->id)
            ->whereNotIn('id', $variants->take(3)->pluck('id'))
            ->update(['quantity' => 0, 'is_active' => 0]);

        $cheapest = $variants->get(2);

        $this->bundle($primary['product_id'], [
            $lines[0]['product_id'], (int)$variantProduct->id,
        ]);

        $this->asGuest();

        $item = collect($this->blockFor($primary['product_id'])[0]['items'])
            ->firstWhere('id', (int)$variantProduct->id);

        $this->assertSame((int)$cheapest->id, (int)$item['inventory_id'],
            'the set did not take the cheapest buyable variant');

        /*
         * And the card SAYS which variant it is.
         *
         * The set stores a product and picks the variant, so without this the
         * shopper is sold one of several without being told which.
         */
        $this->assertSame(
            ['id', 'name', 'slug', 'image', 'inventory_id', 'variant', 'sku', 'price', 'old_price'],
            array_keys($item),
            'the item payload changed shape -- if a field was added, the '
            . 'product page should be showing it'
        );

        $this->assertNotNull($item['variant'],
            'a product with three variations was offered without naming one');

        $expected = $cheapest->inventory_attributes
            ->map(function ($attribute) { return $attribute->attribute_value->title ?? null; })
            ->filter()->implode(' / ');

        if ($expected !== '') {
            $this->assertSame($expected, $item['variant'],
                'the label does not describe the variant that was chosen');
        }
    }

    /**
     * A product with no variations is not given an empty label.
     *
     * The page draws the line only when there is something to say, so null
     * here is the difference between a clean card and a stray separator.
     */
    public function test_a_simple_product_carries_no_variant_label()
    {
        $lines = $this->lines(3);
        $primary = array_shift($lines);

        $simple = collect($lines)->first(function ($line) {
            return UpdatedInventory::where('product_id', $line['product_id'])->count() === 1;
        });

        if (!$simple) {
            $this->markTestSkipped('No single-variant product among the fixtures.');
        }

        /*
         * The SKU is deliberately LEFT in place. This catalogue's SKUs are
         * values like "1" and "1m", and the label falls back to them for a
         * variant picker -- where a row must say something. A bundle card
         * shows one already-chosen variant, so a fallback there reads as a
         * defect: "Car Phone Holder (1)".
         */
        $this->bundle($primary['product_id'], [$simple['product_id']]);

        $this->asGuest();

        $item = collect($this->blockFor($primary['product_id'])[0]['items'])
            ->firstWhere('id', (int)$simple['product_id']);

        $this->assertNull($item['variant'],
            'a product with no variation attributes was labelled anyway -- '
            . 'probably the SKU fallback, which is noise on a bundle card');

        // The SKU is still reported, for anything that wants to identify the
        // line; it just is not shown as if it described the variant.
        $this->assertNotNull($item['sku'], 'the SKU stopped being reported');
    }
}
