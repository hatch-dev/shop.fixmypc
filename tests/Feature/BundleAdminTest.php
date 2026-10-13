<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BundleDeal;
use App\Models\BundleDealProducts;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Services\BundleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Promotions -> Bundle Deals, through the real admin routes.
 *
 * BundleStorefrontTest and BundleCheckoutTest both build their bundles by
 * writing the rows directly, so nothing has ever exercised the screen an
 * administrator actually uses.
 *
 * These are the cases an admin module for a PRICING rule gets wrong: a rule
 * saved with nothing to price, a percentage that is not a percentage, a save
 * that writes whatever the browser sent straight to the table, a total on the
 * list that is not the total the shop charges, and a deletion that leaves a
 * live product pointing at a promotion that no longer exists.
 *
 * Authentication is a REAL personal access token rather than Passport's
 * actingAs. `scope:admin` re-issues a token into the request input when the
 * caller's is within a minute of expiring, and actingAs has no expiry at all,
 * so every actingAs request looks like a rollover -- which is a state the
 * save path does not survive. See
 * test_a_save_ignores_fields_that_are_not_its_own.
 */
class BundleAdminTest extends TestCase
{
    use DatabaseTransactions;

    private $headers = [];

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

        $this->headers = [
            'Authorization' => 'Bearer ' . $admin->createToken('admin', ['admin'])->accessToken,
        ];

        return $admin;
    }

    /** Published, priced products that no bundle already points at. */
    private function products($count = 3)
    {
        $products = Product::where('status', config('constants.status.PUBLIC'))
            ->where('selling', '>', 0)
            ->whereHas('product_inventories')
            ->whereNotIn('id', BundleDealProducts::pluck('product_id'))
            ->orderBy('id')
            ->limit($count)
            ->get();

        if ($products->count() < $count) {
            $this->markTestSkipped("Need $count unconfigured published products.");
        }

        return $products;
    }

    private function payload($products, $overrides = [])
    {
        return array_merge([
            'title' => 'QA Essentials ' . uniqid(),
            'description' => 'Everything you need with it.',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'buy' => 0,
            'free' => 0,
            'products' => collect($products)->map(function ($p) {
                return ['id' => (int)$p->id];
            })->values()->all(),
        ], $overrides);
    }

    /** The body's own status, which is where a refusal actually lives. */
    private function bodyStatus($response)
    {
        return (int)$response->json('status');
    }

    private function save($payload, $id = null)
    {
        return $this->postJson(
            '/api/admin/bundle-deal/action' . ($id ? "/{$id}" : ''),
            $payload,
            $this->headers
        );
    }

    private function productIdsOn($bundleId)
    {
        return BundleDealProducts::where('bundle_deal_id', $bundleId)
            ->pluck('product_id')->map('intval')->sort()->values()->all();
    }

    /* ---- the happy path ---------------------------------------------- */

    public function test_a_bundle_is_created_with_its_products()
    {
        $this->admin();

        $products = $this->products(3);

        $response = $this->save($this->payload($products));

        $this->assertSame(200, $this->bodyStatus($response),
            'the bundle was refused: ' . json_encode($response->json('message')));

        $id = (int)$response->json('data.id');

        $this->assertGreaterThan(0, $id, 'no bundle id came back');

        $this->assertSame(
            $products->pluck('id')->map('intval')->sort()->values()->all(),
            $this->productIdsOn($id),
            'the bundle did not keep the products it was given'
        );
    }

    /**
     * An edit replaces the set rather than adding to it.
     *
     * The rule prices a SET, so a stale member is not cosmetic: it is a
     * product the shopper must hold before the discount is granted at all.
     */
    public function test_an_edit_replaces_the_products()
    {
        $this->admin();

        $products = $this->products(3);
        $first = $products->take(2);
        $second = $products->slice(1);

        $id = (int)$this->save($this->payload($first))->json('data.id');

        $response = $this->save($this->payload($second, [
            'id' => $id,
            'title' => 'QA Essentials edited ' . uniqid(),
        ]), $id);

        $this->assertSame(200, $this->bodyStatus($response),
            'the edit was refused: ' . json_encode($response->json('message')));

        $this->assertSame(
            $second->pluck('id')->map('intval')->sort()->values()->all(),
            $this->productIdsOn($id),
            'the edit left the old products on the bundle'
        );
    }

    public function test_an_unauthenticated_caller_cannot_create()
    {
        $this->app['auth']->forgetGuards();

        $products = $this->products(2);

        $before = BundleDeal::count();

        $this->postJson('/api/admin/bundle-deal/action', $this->payload($products));

        $this->assertSame($before, BundleDeal::count(),
            'an unauthenticated caller created a bundle');
    }

    /* ---- what the save writes ---------------------------------------- */

    /**
     * A save writes the bundle's own fields and nothing else.
     *
     * The edit path ends with
     *
     *     BundleDeal::where('id', $id)->update($filtered)
     *
     * where $filtered is the whole request minus `products`. That is a query
     * builder, so $fillable does not apply: every key the caller sends whose
     * name matches a column is written, and every key whose name does not
     * match one takes the statement down with it.
     *
     * `scope:admin` re-issues the caller's access token INTO the request input
     * whenever the old one is within a minute of expiring, so the field that
     * breaks this is one the application puts there itself. The rows above it
     * have already been written by then and there is no transaction, so the
     * administrator is told the save failed on a save that went through.
     */
    public function test_a_save_ignores_fields_that_are_not_its_own()
    {
        $this->admin();

        $products = $this->products(2);

        $id = (int)$this->save($this->payload($products))->json('data.id');

        // Exactly what `scope:admin` merges in at a token rollover.
        $response = $this->save($this->payload($products, [
            'id' => $id,
            'token' => 'a-reissued-access-token',
        ]), $id);

        $this->assertSame(200, $this->bodyStatus($response),
            'a field the bundle does not own broke the save: '
            . json_encode($response->json('message')));
    }

    /**
     * Ownership is the server's to decide.
     *
     * `admin_id` is what scopes a vendor's bundles to the vendor, and the same
     * unfiltered update lets the caller set it to anybody.
     */
    public function test_a_save_cannot_reassign_the_owner()
    {
        $admin = $this->admin();

        $products = $this->products(2);

        $id = (int)$this->save($this->payload($products))->json('data.id');

        $other = Admin::where('id', '!=', $admin->id)->value('id');

        if (!$other) {
            $this->markTestSkipped('Only one admin, so ownership cannot move.');
        }

        $this->save($this->payload($products, ['id' => $id, 'admin_id' => $other]), $id);

        $this->assertSame((int)$admin->id, (int)BundleDeal::find($id)->admin_id,
            'the request body reassigned the bundle to another admin');
    }

    /* ---- validation --------------------------------------------------- */

    /**
     * A bundle with no products is not a bundle.
     *
     * Validation::bundleDeals asks for a title and a description and nothing
     * else. BundleService drops an empty bundle rather than showing an empty
     * card, so this is saved, listed and assignable while being unable ever
     * to appear -- a promotion that is switched on and cannot be seen.
     */
    public function test_a_bundle_with_no_products_is_refused()
    {
        $this->admin();

        $response = $this->save($this->payload([], ['products' => []]));

        $this->assertSame(201, $this->bodyStatus($response),
            'a bundle with no products was accepted');
    }

    /**
     * A percentage is a percentage.
     *
     * Pricing::bundleDiscount caps the payout at the value of the goods, so
     * nothing is over-refunded -- but 500% is stored verbatim and the bundle
     * silently becomes "free", which no one typing 500 into a box intended.
     */
    public function test_a_percentage_over_one_hundred_is_refused()
    {
        $this->admin();

        $products = $this->products(2);

        $response = $this->save($this->payload($products, [
            'discount_type' => 'percentage',
            'discount_value' => 500,
        ]));

        if ($this->bodyStatus($response) === 200) {
            $stored = (float)BundleDeal::find((int)$response->json('data.id'))->discount_value;

            $this->fail("a discount of {$stored}% was saved as a percentage");
        }

        $this->assertSame(201, $this->bodyStatus($response));
    }

    /* ---- deleting a live promotion ------------------------------------ */

    /**
     * A bundle a product still offers cannot be deleted.
     *
     * The guard the module already has, proved so the next case is clearly a
     * hole in it rather than a missing feature.
     */
    public function test_a_bundle_a_product_offers_cannot_be_deleted()
    {
        $this->admin();

        $products = $this->products(3);
        $primary = $products->first();

        $id = (int)$this->save($this->payload($products->slice(1)))->json('data.id');

        Product::where('id', $primary->id)->update(['bundle_deal_id' => (string)$id]);

        $this->deleteJson('/api/admin/bundle-deal/delete/' . $id, [], $this->headers);

        $this->assertNotNull(BundleDeal::find($id),
            'a bundle a live product offers was deleted');
    }

    /**
     * The same guard, when the product offers more than one bundle.
     *
     * `products.bundle_deal_id` is a comma-separated VARCHAR -- that is how
     * the admin assigns several, and BundleService reads it that way. The
     * delete guard asks
     *
     *     Product::where('bundle_deal_id', $id)->first()
     *
     * which is an equality test against the whole string, so it matches only
     * a product that offers this bundle and no other. Assign a second bundle
     * and the guard stops seeing either of them.
     */
    public function test_a_bundle_offered_alongside_another_cannot_be_deleted()
    {
        $this->admin();

        $products = $this->products(3);
        $primary = $products->first();
        $items = $products->slice(1);

        $first = (int)$this->save($this->payload($items))->json('data.id');
        $second = (int)$this->save($this->payload($items))->json('data.id');

        Product::where('id', $primary->id)
            ->update(['bundle_deal_id' => $first . ',' . $second]);

        $this->deleteJson('/api/admin/bundle-deal/delete/' . $second, [], $this->headers);

        $this->assertNotNull(BundleDeal::find($second),
            'a bundle was deleted while a live product still offered it, '
            . 'leaving products.bundle_deal_id pointing at nothing: '
            . Product::find($primary->id)->bundle_deal_id);
    }

    /* ---- the figure on the list --------------------------------------- */

    /**
     * The list states the price the shop will charge.
     *
     * `all()` adds up `products.offered ?: products.selling` -- the
     * product-level figure -- while the storefront and the basket price every
     * item at its cheapest purchasable VARIANT, through
     * Pricing::unitPrice. On any product whose variants carry their own
     * prices the two disagree, and the admin is quoted a total the shop never
     * charges. Same class of defect as the one BundleService was written to
     * end on the product page.
     */
    public function test_the_listed_total_is_the_total_the_shop_quotes()
    {
        $this->admin();

        [$primary, $item] = $this->variantPricedPair();

        $title = 'QA Variant Bundle ' . uniqid();

        $id = (int)$this->save($this->payload([$item], ['title' => $title]))->json('data.id');

        Product::where('id', $primary->id)->update(['bundle_deal_id' => (string)$id]);

        $row = collect($this->getJson(
            '/api/admin/bundle-deal/all?orderby=id&type=desc&q=' . urlencode($title),
            $this->headers
        )->json('data.data'))->firstWhere('id', $id);

        $this->assertNotNull($row, 'the bundle was not on the list');

        $card = collect((new BundleService())->forProduct(Product::find($primary->id)))
            ->firstWhere('id', $id);

        $this->assertNotNull($card, 'the storefront did not offer the bundle');

        $this->assertEqualsWithDelta(
            (float)$card['lines_total'], (float)$row['total_price'], 0.01,
            'the admin list adds up product-level prices while the shop '
            . 'charges per variant'
        );

        $this->assertEqualsWithDelta(
            (float)$card['price'], (float)$row['final_price'], 0.01,
            'the admin list and the storefront disagree about what the '
            . 'bundle sells for'
        );
    }

    /**
     * A product whose cheapest variant is not priced at the product-level
     * figure, plus another product to hang the bundle on.
     */
    private function variantPricedPair()
    {
        $candidates = Product::where('status', config('constants.status.PUBLIC'))
            ->where('selling', '>', 0)
            ->whereHas('product_inventories')
            ->whereNotIn('id', BundleDealProducts::pluck('product_id'))
            ->orderBy('id')
            ->limit(400)
            ->get();

        foreach ($candidates as $candidate) {

            $inventory = UpdatedInventory::where('product_id', $candidate->id)
                ->where('price', '>', 0)->orderBy('price')->first();

            if (!$inventory) {
                continue;
            }

            $productLevel = (float)($candidate->offered > 0
                ? $candidate->offered : $candidate->selling);

            if (abs((float)$inventory->price - $productLevel) <= 0.01) {
                continue;
            }

            $primary = $candidates->first(function ($other) use ($candidate) {
                return (int)$other->id !== (int)$candidate->id;
            });

            if ($primary) {
                return [$primary, $candidate];
            }
        }

        $this->markTestSkipped('No product whose variant price differs from its own.');
    }
}
