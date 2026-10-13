<?php

namespace Tests\Feature;

use App\Models\BundleDeal;
use App\Models\BundleDealProducts;
use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Helper\BundleDiscount;
use App\Models\Helper\OrderSummary;
use App\Models\Helper\Pricing;
use App\Models\Helper\Utils;
use App\Models\Order;
use App\Models\OrderedBundleDiscount;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The Essentials Bundle discount AFTER the money has been taken, and on the
 * ways into the basket that BundleStorefrontTest does not use.
 *
 * BundleCheckoutTest proves the right amount is charged and recorded. What it
 * does not prove is that anybody is ever TOLD about it: the confirmation
 * email, the PDF invoice and the admin order screen all rebuild their rows
 * from OrderSummary, and a discount that is subtracted from the total but
 * itemised nowhere leaves a customer holding a VAT document whose lines do
 * not add up to the amount charged.
 *
 * The other half is the basket. A line only remembers WHICH bundle it came in
 * under, so anything that rewrites a basket line has to carry that marker
 * with it -- and the guest-to-customer merge at sign-in rewrites every line
 * there is.
 */
class BundleAuditTest extends TestCase
{
    use DatabaseTransactions;

    private $place;

    /* ---- fixtures (same shape as BundleCheckoutTest) ------------------ */

    private function buyableProducts($count = 3)
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
            $this->markTestSkipped("Need $count products that can reach checkout.");
        }

        return $usable;
    }

    private function customer()
    {
        $user = User::create([
            'name' => 'Bundle Audit',
            'email' => 'bundle.audit.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ]);

        $address = UserAddress::create([
            'user_id' => $user->id,
            'name' => 'Bundle Audit',
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

        return $user->fresh();
    }

    private function bundle($primary, array $items, $discountValue = 10, $type = 'percentage')
    {
        $bundle = BundleDeal::create([
            'title' => 'QA Audit Bundle ' . uniqid(),
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

    private function shipAll($customer)
    {
        Cart::where('user_id', $customer->id)->update([
            'shipping_place_id' => $this->place->id,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
            'selected' => (int)config('constants.status.PUBLIC'),
        ]);
    }

    private function checkout()
    {
        $json = json_encode([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ]);

        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return $this->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC',
                hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
                hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);
    }

    private function takeBundle($customer, $primaryId, $bundleId)
    {
        Passport::actingAs($customer, ['user'], 'user');

        $response = $this->postJson('/api/v1/cart/bundle', [
            'product_id' => $primaryId,
            'bundle_id' => $bundleId,
        ]);

        $this->assertSame(200, (int)$response->json('status'),
            'the bundle was refused: ' . json_encode($response->json('message')));

        return $response;
    }

    /** An order that carries a bundle discount, and the order itself. */
    private function orderWithBundle()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        $this->takeBundle($customer, $primary['product_id'], $bundle->id);
        $this->shipAll($customer);
        $this->checkout();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'checkout did not complete');

        $granted = (float)OrderedBundleDiscount::where('order_id', $order->id)->sum('discount');

        $this->assertGreaterThan(0, $granted,
            'this order took no bundle discount, so it proves nothing about reporting it');

        return [$order, $granted, $customer];
    }

    /* ---- telling anybody about it ------------------------------------ */

    /**
     * The order summary states the bundle discount as its own figure.
     *
     * Every other order-wide discount comes back from OrderSummary under its
     * own key -- voucher, flash_discount, loyalty_discount, points_discount --
     * because the confirmation, the email, the PDF invoice and the admin order
     * screen all rebuild their rows from it. The bundle discount is subtracted
     * from `total` and folded into `order_wide_discount`, and that is all: no
     * caller can name it, so no document can show it.
     */
    public function test_the_summary_states_the_bundle_discount_as_its_own_figure()
    {
        [$order, $granted] = $this->orderWithBundle();

        $summary = OrderSummary::for($order->fresh());

        $this->assertArrayHasKey('bundle_discount', $summary,
            'OrderSummary subtracts the bundle discount but will not name it, '
            . 'so nothing downstream can itemise it');

        $this->assertEqualsWithDelta($granted, (float)$summary['bundle_discount'], 0.01);
    }

    /**
     * The rows of an order add up to the total charged.
     *
     * This is the defect the missing key causes, stated as arithmetic rather
     * than as a key name: take everything the email prints, and it should
     * reconcile with the amount taken. A discount that is subtracted but never
     * itemised leaves an unexplained gap of exactly its own size.
     */
    public function test_the_emailed_totals_reconcile_with_what_was_charged()
    {
        [$order, $granted] = $this->orderWithBundle();

        $p = Utils::calcPrice($order->fresh());

        // Exactly what mail_templates/partials/totals.blade.php prints.
        $stated = (float)$p['inc_vat']
            + (float)$p['shipping_price']
            - (float)($p['voucher_price'] ?? 0)
            - (float)($p['flash_discount'] ?? 0)
            - (float)($p['bundle_discount'] ?? 0)
            - (float)($p['loyalty_discount'] ?? 0)
            - (float)($p['points_discount'] ?? 0);

        $this->assertEqualsWithDelta(
            (float)$p['total_price'], $stated, 0.02,
            'the emailed rows do not account for the total: the bundle discount '
            . "of {$granted} is taken off the total but printed on no row"
        );
    }

    /**
     * The admin order screen can show the bundle discount.
     *
     * Same gap, on the screen somebody answering the phone is looking at.
     */
    public function test_the_admin_order_detail_itemises_the_bundle_discount()
    {
        [$order, $granted] = $this->orderWithBundle();

        $summary = OrderSummary::decorate($order->fresh());

        $this->assertArrayHasKey('bundle_discount', $summary,
            'the admin order detail builds `calculations` from these keys, so '
            . 'without one the bundle discount cannot appear on the invoice');

        $this->assertEqualsWithDelta($granted, (float)$summary['bundle_discount'], 0.01);
    }

    /* ---- the marker surviving a basket rewrite ------------------------ */

    /**
     * A guest who took a bundle keeps it after signing in.
     *
     * CartMerge rewrites every line: a guest line with no counterpart is
     * adopted (keeping its columns, and so its marker), but one that matches
     * a line the account already had is FOLDED IN -- the quantities are added
     * and the guest row is deleted. Nothing carries `bundle_deal_id` across
     * that fold, so a shopper who already had one of the bundle's products in
     * their account basket loses the discount by signing in.
     */
    public function test_a_guest_bundle_survives_signing_in()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);
        $items = collect($lines)->pluck('product_id')->all();

        $bundle = $this->bundle($primary['product_id'], $items);

        $customer = $this->customer();

        /*
         * The account already holds one of the bundle's products, bought on
         * its own. This is the ordinary case -- it is why BundleDiscount
         * checks the whole basket rather than only the marked lines.
         */
        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $lines[0]['product_id'],
            'inventory_id' => $lines[0]['inventory_id'],
            'quantity' => 1,
        ]);

        $this->assertGreaterThan(0, Cart::where('user_id', $customer->id)->count(),
            'the account basket was not set up');

        // Now the same person, signed out, takes the whole bundle as a guest.
        $this->app['auth']->forgetGuards();

        $guestToken = (string)\Illuminate\Support\Str::uuid();
        GuestUser::create(['user_token' => $guestToken]);

        $take = $this->postJson('/api/v1/cart/bundle', [
            'user_token' => $guestToken,
            'product_id' => $primary['product_id'],
            'bundle_id' => $bundle->id,
        ]);

        $this->assertSame(200, (int)$take->json('status'),
            'the guest could not take the bundle: ' . json_encode($take->json('message')));

        $guestRows = Cart::where('user_token', $guestToken)->get();

        $quoted = BundleDiscount::totalFor($guestRows);

        $this->assertGreaterThan(0, $quoted,
            'the guest basket earned no bundle discount, so the merge proves nothing');

        // Sign in: the guest basket merges into the account's.
        Passport::actingAs($customer, ['user'], 'user');

        $merge = $this->postJson('/api/v1/cart/merge', ['user_token' => $guestToken]);

        $this->assertSame(200, (int)$merge->json('status'),
            'the merge failed: ' . json_encode($merge->json('message')));

        $merged = Cart::where('user_id', $customer->id)->get();

        $marked = $merged->filter(function ($row) { return !empty($row->bundle_deal_id); });

        $this->assertSame(
            $guestRows->count(), $marked->count(),
            'the merge dropped bundle_deal_id from a line: a guest line that '
            . 'folds into one the account already had loses its marker, so the '
            . 'set is no longer priced as a set'
        );

        $this->assertEqualsWithDelta(
            $quoted, BundleDiscount::totalFor($merged), 0.01,
            'signing in shrank the bundle discount the guest was quoted'
        );
    }

    /* ---- the marker being overwritten --------------------------------- */

    /**
     * Two bundles that share a product are both paid for.
     *
     * `carts.bundle_deal_id` holds ONE bundle, and addCrossSellLine sets it
     * unconditionally on a line it merges into. So when a product belongs to
     * two bundles offered under the same item and the shopper takes both, the
     * second overwrites the first on the shared line -- the first bundle's
     * group loses that line, its subtotal shrinks, and it pays out less than
     * the block quoted for it.
     */
    public function test_two_bundles_sharing_a_product_are_both_paid_for()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $shared = $lines[0]['product_id'];
        $other = $lines[1]['product_id'];

        $first = $this->bundle($primary['product_id'], [$shared, $other]);
        $second = $this->bundle($primary['product_id'], [$shared]);

        // Both offered under the same product.
        Product::where('id', $primary['product_id'])
            ->update(['bundle_deal_id' => $first->id . ',' . $second->id]);

        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $cards = collect($this->getJson(
            "/api/v1/product/upsell-crosssell/{$primary['product_id']}"
        )->json('bundle_deal'));

        $quotedFirst = (float)optional($cards->firstWhere('id', $first->id))['discount'];
        $quotedSecond = (float)optional($cards->firstWhere('id', $second->id))['discount'];

        if ($quotedFirst <= 0 || $quotedSecond <= 0) {
            $this->markTestSkipped('Both bundles must quote a discount for this to mean anything.');
        }

        $this->takeBundle($customer, $primary['product_id'], $first->id);
        $this->takeBundle($customer, $primary['product_id'], $second->id);

        $rows = Cart::where('user_id', $customer->id)->get();

        /*
         * Per bundle, not as a total. Taking the second bundle also raises
         * the shared line to two, which enlarges the SECOND bundle's payout
         * by roughly what the first one lost -- so the two errors cancel in
         * the total and only the breakdown shows them.
         */
        $paid = collect(BundleDiscount::forRows($rows)['bundles'])->keyBy('id');

        $this->assertEqualsWithDelta(
            $quotedFirst,
            (float)optional($paid->get((int)$first->id))['discount'],
            0.01,
            'taking the second bundle moved the shared line onto it, so the '
            . 'first bundle no longer pays the discount it quoted'
        );
    }

    /* ---- the discount against quantity -------------------------------- */

    /**
     * The discount is for the set, not per unit of it.
     *
     * BundleDiscount bases the percentage on `unit price x quantity` of the
     * marked lines. A bundle is a fixed SET -- addBundle writes one of each
     * item -- so raising one item to five afterwards multiplies the saving
     * against goods that were never part of the offer. The set was quoted
     * once; it should pay out once.
     */
    public function test_raising_a_quantity_does_not_enlarge_the_bundle_discount()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $quoted = (float)collect($this->getJson(
            "/api/v1/product/upsell-crosssell/{$primary['product_id']}"
        )->json('bundle_deal'))->firstWhere('id', $bundle->id)['discount'];

        $this->takeBundle($customer, $primary['product_id'], $bundle->id);

        $row = Cart::where('user_id', $customer->id)
            ->whereNotNull('bundle_deal_id')->orderBy('id')->first();

        $this->assertNotNull($row, 'nothing in the basket carries the bundle');

        Cart::where('id', $row->id)->update(['quantity' => 5]);

        $after = BundleDiscount::totalFor(Cart::where('user_id', $customer->id)->get());

        $this->assertEqualsWithDelta($quoted, $after, 0.01,
            'raising one line to five multiplied the bundle saving: the set '
            . "was quoted {$quoted} and now pays {$after}");
    }

    /**
     * A bundle taken twice pays twice.
     *
     * The companion to the case above, and the reason the base counts
     * COMPLETE SETS rather than simply charging for one. Raising a single
     * item must not enlarge the saving; holding two of everything the bundle
     * asks for is two bundles, and should.
     */
    public function test_a_bundle_taken_twice_pays_twice()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $quoted = (float)collect($this->getJson(
            "/api/v1/product/upsell-crosssell/{$primary['product_id']}"
        )->json('bundle_deal'))->firstWhere('id', $bundle->id)['discount'];

        $this->takeBundle($customer, $primary['product_id'], $bundle->id);

        $once = BundleDiscount::totalFor(Cart::where('user_id', $customer->id)->get());

        $this->assertEqualsWithDelta($quoted, $once, 0.01,
            'one set did not pay the quoted discount');

        $this->takeBundle($customer, $primary['product_id'], $bundle->id);

        $twice = BundleDiscount::totalFor(Cart::where('user_id', $customer->id)->get());

        $this->assertEqualsWithDelta($quoted * 2, $twice, 0.01,
            'the second set of the same bundle earned nothing');
    }
}
