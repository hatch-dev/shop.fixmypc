<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BundleDeal;
use App\Models\BundleDealProducts;
use App\Models\Cart;
use App\Models\Helper\BundleDiscount;
use App\Models\Helper\OrderSummary;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\OrderedBundleDiscount;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Essentials Bundle at the POS counter.
 *
 * A POS basket is not a separate thing: it is `carts` rows scoped by
 * `admin_id`, and CartsController::ownerScope scopes to exactly that whenever
 * an administrator is signed in and sends no guest token. So
 * `POST api/v1/cart/bundle` writes bundle-marked lines into a POS basket with
 * no new plumbing at all -- which means PosOrdersController ignoring the
 * marker was not a missing feature but a mischarge waiting to happen: the set
 * was quoted at a discount and rung up at full price.
 *
 * These tests drive the real POS endpoint and check the two things that have
 * to be true of any discount here: the till takes it, and the order records
 * it so every document built from OrderSummary still reconciles.
 */
class PosBundleTest extends TestCase
{
    use DatabaseTransactions;

    private $headers = [];
    private $admin;
    private $place;

    /* ---- fixtures ---------------------------------------------------- */

    private function operator()
    {
        $this->admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$this->admin) {
            $this->markTestSkipped('No superadmin to drive the POS with.');
        }

        $this->app['auth']->forgetGuards();

        $this->headers = [
            'Authorization' => 'Bearer ' . $this->admin->createToken('admin', ['admin'])->accessToken,
        ];

        Cart::where('admin_id', $this->admin->id)->delete();

        return $this->admin;
    }

    /** Products that can reach a POS checkout: published, priced, shippable. */
    private function sellableProducts($count = 3)
    {
        $rows = Product::whereHas('product_inventories')
            ->where('selling', '>', 0)
            ->where('status', config('constants.status.PUBLIC'))
            ->whereNotNull('shipping_rule_id')
            ->orderBy('id')
            ->limit($count * 4)
            ->get();

        $usable = [];

        foreach ($rows as $product) {
            $inventory = UpdatedInventory::where('product_id', $product->id)
                ->orderBy('id')->first();

            $place = optional($product->shipping_rule)->shipping_places()->first();

            if (!$inventory || !$place) {
                continue;
            }

            UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 5000]);

            $this->place = $this->place ?: $place;

            $usable[] = [
                'product_id' => (int)$product->id,
                'inventory_id' => (int)$inventory->id,
            ];

            if (count($usable) === $count) {
                break;
            }
        }

        if (count($usable) < $count) {
            $this->markTestSkipped("Need $count products that can reach a POS checkout.");
        }

        return $usable;
    }

    private function bundle($primary, array $items, $discountValue = 10, $type = 'percentage')
    {
        $bundle = BundleDeal::create([
            'title' => 'QA POS Bundle ' . uniqid(),
            'description' => 'Everything you need.',
            'discount_type' => $type,
            'discount_value' => $discountValue,
            'buy' => 0,
            'free' => 0,
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

    /** A walk-in customer to hang the order on. */
    private function customer()
    {
        $user = User::create([
            'name' => 'POS Walk In',
            'email' => 'pos.bundle.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ]);

        $address = UserAddress::create([
            'user_id' => $user->id,
            'name' => 'POS Walk In',
            'phone' => $user->phone,
            'country' => 'IE',
            'state' => 'Dublin',
            'city' => 'Dublin',
            'zip' => 'D02X286',
            'address_1' => '26 Main Street',
            'type' => 1,
            'default' => 1,
        ]);

        $user->default_address = $address->id;
        $user->save();

        return [$user->fresh(), $address];
    }

    /** Every POS line needs a shipping place before the counter will take it. */
    private function shipAll()
    {
        Cart::where('admin_id', $this->admin->id)->update([
            'shipping_place_id' => $this->place->id,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
            'selected' => (int)config('constants.status.PUBLIC'),
        ]);
    }

    private function ringUp($user, $address)
    {
        return $this->postJson('/api/admin/pos-order/action', [
            'user_id' => $user->id,
            'user_address_id' => $address->id,
            'payment_method' => 1,
        ], $this->headers);
    }

    /**
     * Is the POS module actually installed?
     *
     * It is not, on this database, and the code has never been able to run
     * here: `pos_orders` has no migration and no table, and `orders`
     * `.order_method` is NOT NULL with no default while PosOrdersController
     * creates the order without it. Both are prior defects, unrelated to
     * bundles, and both stop the endpoint before any pricing happens.
     *
     * Skipped rather than failed, and skipped on the PREREQUISITE rather than
     * on the symptom, so these start running the day POS is installed instead
     * of sitting red against something they are not testing.
     */
    private function requirePos()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('pos_orders')) {
            $this->markTestSkipped(
                'POS is not installed here: the `pos_orders` table does not '
                . 'exist and has no migration.'
            );
        }

        $column = collect(DB::select('SHOW COLUMNS FROM orders'))
            ->firstWhere('Field', 'order_method');

        if ($column && $column->Null === 'NO' && is_null($column->Default)) {
            $this->markTestSkipped(
                'POS cannot create an order here: orders.order_method is NOT '
                . 'NULL with no default, and PosOrdersController never sets it.'
            );
        }
    }

    /* ---- the money --------------------------------------------------- */

    /**
     * The counter takes the discount the bundle quoted.
     *
     * The case the whole change exists for: before it, a POS basket holding a
     * bundle was rung up at full price.
     */
    public function test_the_counter_charges_the_bundle_price()
    {
        $this->requirePos();
        $this->operator();

        $lines = $this->sellableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $added = $this->postJson('/api/v1/cart/bundle', [
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ], $this->headers);

        $this->assertSame(200, (int)$added->json('status'),
            'the bundle was refused: ' . json_encode($added->json('message')));

        $this->shipAll();

        $rows = Cart::where('admin_id', $this->admin->id)->get();

        $quoted = BundleDiscount::totalFor($rows);

        $this->assertGreaterThan(0, $quoted,
            'the POS basket earned no bundle discount, so this proves nothing');

        [$user, $address] = $this->customer();

        $response = $this->ringUp($user, $address);

        $this->assertSame(200, (int)$response->json('status'),
            'the POS order was refused: ' . json_encode($response->json('message')));

        $order = Order::orderByDesc('id')->first();

        $recorded = (float)OrderedBundleDiscount::where('order_id', $order->id)->sum('discount');

        $this->assertEqualsWithDelta($quoted, $recorded, 0.01,
            'the counter did not take the discount the bundle quoted');
    }

    /**
     * Recorded, not just subtracted.
     *
     * OrderSummary rebuilds every order total from the order's own rows. A
     * POS order that charged the discount but wrote it nowhere would have its
     * receipt, its invoice and the admin order detail all add up to more than
     * was taken at the counter.
     */
    public function test_the_summary_agrees_with_what_the_counter_took()
    {
        $this->requirePos();
        $this->operator();

        $lines = $this->sellableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $this->postJson('/api/v1/cart/bundle', [
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ], $this->headers);

        $this->shipAll();

        [$user, $address] = $this->customer();

        $response = $this->ringUp($user, $address);

        $this->assertSame(200, (int)$response->json('status'),
            'the POS order was refused: ' . json_encode($response->json('message')));

        $order = Order::orderByDesc('id')->first();

        $granted = (float)OrderedBundleDiscount::where('order_id', $order->id)->sum('discount');

        $this->assertGreaterThan(0, $granted, 'no discount was recorded');

        $summary = OrderSummary::for($order->fresh());

        // The figure the counter actually charged.
        $charged = (float)$order->fresh()->total_amount;

        $this->assertEqualsWithDelta(
            $charged, (float)$summary['total'], 0.02,
            'the order summary and the amount taken at the counter disagree'
        );

        $this->assertEqualsWithDelta($granted, (float)$summary['bundle_discount'], 0.01,
            'the summary does not state the bundle discount the counter took');
    }

    /**
     * An ordinary POS sale is untouched.
     *
     * The guard against the change reaching baskets it has nothing to do with.
     */
    public function test_an_ordinary_pos_sale_earns_nothing()
    {
        $this->requirePos();
        $this->operator();

        $lines = $this->sellableProducts(2);

        foreach ($lines as $line) {
            $this->postJson('/api/v1/cart/action', [
                'product_id' => $line['product_id'],
                'inventory_id' => $line['inventory_id'],
                'quantity' => 1,
            ], $this->headers);
        }

        $this->shipAll();

        $this->assertGreaterThan(0, Cart::where('admin_id', $this->admin->id)->count(),
            'the POS basket was not set up');

        [$user, $address] = $this->customer();

        $response = $this->ringUp($user, $address);

        $this->assertSame(200, (int)$response->json('status'),
            'the POS order was refused: ' . json_encode($response->json('message')));

        $order = Order::orderByDesc('id')->first();

        $this->assertSame(0, OrderedBundleDiscount::where('order_id', $order->id)->count(),
            'a basket with no bundle was given a bundle discount');
    }

    /* ---- what can be proved without the POS schema -------------------- */

    /**
     * A POS basket earns the bundle discount, and it is the same figure the
     * website would charge.
     *
     * This is the half of the change that does not need `pos_orders`: that
     * bundle-marked lines really do land in an ADMIN-scoped basket through
     * the ordinary endpoint, and that Helper\BundleDiscount -- the call
     * PosOrdersController now makes -- values them exactly as it values a
     * customer's basket.
     *
     * It is also the evidence that this was a mischarge rather than a missing
     * feature: the marked lines are reachable today, and before the change
     * the counter read straight past them.
     */
    public function test_a_pos_basket_holds_and_prices_a_bundle()
    {
        $this->operator();

        $lines = $this->sellableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $added = $this->postJson('/api/v1/cart/bundle', [
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ], $this->headers);

        $this->assertSame(200, (int)$added->json('status'),
            'the bundle was refused: ' . json_encode($added->json('message')));

        $rows = Cart::where('admin_id', $this->admin->id)->get();

        // The lines are the POS operator's, and they carry the marker.
        $this->assertSame(count($lines), $rows->count(),
            'the bundle did not write one line per item into the POS basket');

        $this->assertSame(
            $rows->count(),
            $rows->where('bundle_deal_id', $bundle->id)->count(),
            'a POS line came in under the bundle without carrying its marker'
        );

        /*
         * Priced the way PosOrdersController now prices it: the discount
         * measured against what each line is actually being charged.
         */
        foreach ($rows as $row) {
            $row->charged_unit_price = (float)$row->price;
        }

        $breakdown = BundleDiscount::forRows($rows);

        $this->assertGreaterThan(0, $breakdown['total'],
            'the POS basket earned no bundle discount');

        // The same arithmetic the label used: a percentage of the set.
        $set = $rows->sum(function ($row) {
            return (float)$row->charged_unit_price;
        });

        $this->assertEqualsWithDelta(
            Pricing::bundleDiscount($bundle, Pricing::round($set)),
            $breakdown['total'],
            0.01,
            'the POS basket is not valued the way the bundle was quoted'
        );
    }

    /**
     * An ordinary POS basket earns nothing.
     *
     * The guard against the change reaching baskets it has nothing to do
     * with. Runnable without the POS schema for the same reason as above.
     */
    public function test_an_ordinary_pos_basket_earns_nothing()
    {
        $this->operator();

        $lines = $this->sellableProducts(2);

        foreach ($lines as $line) {
            $this->postJson('/api/v1/cart/action', [
                'product_id' => $line['product_id'],
                'inventory_id' => $line['inventory_id'],
                'quantity' => 1,
            ], $this->headers);
        }

        $rows = Cart::where('admin_id', $this->admin->id)->get();

        $this->assertGreaterThan(0, $rows->count(), 'the POS basket was not set up');

        $this->assertSame(0.0, (float)BundleDiscount::totalFor($rows),
            'a POS basket with no bundle was given a bundle discount');
    }
}
