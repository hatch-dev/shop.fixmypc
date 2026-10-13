<?php

namespace Tests\Feature;

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
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The Essentials Bundle discount, from the label to the money taken.
 *
 * The bundle quotes a discounted price, so the discount has to survive every
 * pricing pass between the product page and the gateway. It cannot be stored
 * on a line: `carts.price` is rewritten from the catalogue on the basket read,
 * on add-to-cart, on every quantity change, at the voucher preview, twice at
 * checkout and again when a guest basket merges at sign-in. So a line only
 * remembers WHICH bundle it came in under, and the amount is re-derived.
 *
 * These are the cases where a re-derived discount goes wrong: it is quoted and
 * not charged, charged and not recorded (so the invoice disagrees for ever),
 * or kept after the shopper has taken the set apart.
 */
class BundleCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private $place;

    /* ---- fixtures ---------------------------------------------------- */

    /**
     * Buyable products that can actually reach checkout: published, priced,
     * and carrying a shipping rule with a place.
     */
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
                ->orderBy('id')
                ->first();

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
            'name' => 'Bundle Checkout',
            'email' => 'bundle.checkout.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ]);

        $address = UserAddress::create([
            'user_id' => $user->id,
            'name' => 'Bundle Checkout',
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

    /** A bundle over $items, assigned to $primary. */
    private function bundle($primary, array $items, $discountValue = 10, $type = 'percentage')
    {
        $bundle = BundleDeal::create([
            'title' => 'QA Checkout Bundle ' . uniqid(),
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

    /** Give every basket line a shipping place, so checkout will accept it. */
    private function shipAll($customer)
    {
        Cart::where('user_id', $customer->id)->update([
            'shipping_place_id' => $this->place->id,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
            'selected' => (int)config('constants.status.PUBLIC'),
        ]);
    }

    /**
     * Place the order. The payload is AES-encrypted and zero-padded to a
     * 16-byte boundary, which is what the endpoint expects.
     */
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

    /** Add a bundle to this customer's basket and return the quoted price. */
    private function takeBundle($customer, $primaryId, $bundleId)
    {
        Passport::actingAs($customer, ['user'], 'user');

        $quoted = collect($this->getJson("/api/v1/product/upsell-crosssell/{$primaryId}")
            ->json('bundle_deal'))->firstWhere('id', $bundleId);

        $this->assertNotNull($quoted, 'the bundle was not offered, so nothing was quoted');

        $response = $this->postJson('/api/v1/cart/bundle', [
            'product_id' => $primaryId,
            'bundle_id' => $bundleId,
        ]);

        $this->assertSame(200, (int)$response->json('status'),
            'the bundle was refused: ' . json_encode($response->json('message')));

        return $quoted;
    }

    /* ---- the money --------------------------------------------------- */

    /**
     * The discount the block quoted is the discount the order takes.
     *
     * The one case the whole design exists for. Everything else here is about
     * keeping this true when something moves.
     */
    public function test_the_order_charges_the_price_the_bundle_quoted()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        $quoted = $this->takeBundle($customer, $primary['product_id'], $bundle->id);

        $this->shipAll($customer);

        $rows = Cart::where('user_id', $customer->id)->get();

        $linesTotal = Pricing::round($rows->sum(function ($row) {
            return (float)$row->price * (int)$row->quantity;
        }));

        // The block said: these lines, less the bundle discount.
        $this->assertEquals($linesTotal, (float)$quoted['lines_total']);
        $this->assertEquals(
            Pricing::round($linesTotal - (float)$quoted['discount']),
            (float)$quoted['price'],
            'the quoted price is not the lines less the discount'
        );

        $this->checkout();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'checkout did not complete');

        $recorded = (float)OrderedBundleDiscount::where('order_id', $order->id)->sum('discount');

        $this->assertEquals(
            (float)$quoted['discount'], $recorded,
            'the order did not take the discount the bundle quoted'
        );
    }

    /**
     * Recorded, not just subtracted.
     *
     * OrderSummary re-derives every order total from the order's OWN rows. A
     * discount that comes off the amount charged but is written nowhere makes
     * the confirmation, the invoice and the admin order detail all add up to
     * more than was taken -- quietly, and for ever.
     */
    public function test_the_summary_agrees_with_what_was_charged()
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

        $recorded = (float)OrderedBundleDiscount::where('order_id', $order->id)->sum('discount');

        $this->assertGreaterThan(0, $recorded, 'nothing was recorded to explain the discount');

        $summary = OrderSummary::for($order->fresh());

        $this->assertEquals(
            Pricing::round((float)$order->total_amount),
            Pricing::round((float)$summary['total']),
            'the summary total does not match the amount the order charged'
        );
    }

    /**
     * Take the set apart and the set price goes with it.
     *
     * The discount is for the bundle, not for the items. A shopper who removes
     * one is charged the ordinary prices for what is left -- otherwise the
     * cheapest route to any single product would be to take a bundle and
     * delete the rest.
     */
    public function test_removing_an_item_ends_the_discount()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        $this->takeBundle($customer, $primary['product_id'], $bundle->id);
        $this->shipAll($customer);

        $rows = Cart::where('user_id', $customer->id)->get();

        $this->assertGreaterThan(0, BundleDiscount::totalFor($rows),
            'the complete bundle earned nothing to begin with');

        Cart::where('user_id', $customer->id)
            ->where('product_id', $lines[0]['product_id'])
            ->delete();

        $this->assertSame(
            0.0, BundleDiscount::totalFor(Cart::where('user_id', $customer->id)->get()),
            'the bundle price survived the bundle being broken up'
        );
    }

    /**
     * An item bought separately still completes the set.
     *
     * The check is against the whole basket rather than only the lines still
     * carrying the marker, so removing an item and adding it back on its own
     * restores the bundle instead of stranding it.
     */
    public function test_an_item_re_added_on_its_own_restores_the_bundle()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        $this->takeBundle($customer, $primary['product_id'], $bundle->id);
        $this->shipAll($customer);

        Cart::where('user_id', $customer->id)
            ->where('product_id', $lines[0]['product_id'])
            ->delete();

        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $lines[0]['product_id'],
            'inventory_id' => $lines[0]['inventory_id'],
            'quantity' => 1,
            'shipping_place_id' => $this->place->id,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);

        $this->assertGreaterThan(
            0, BundleDiscount::totalFor(Cart::where('user_id', $customer->id)->get()),
            'the bundle did not come back when its last item did'
        );
    }

    /**
     * A deselected line is not being bought, so it cannot complete a bundle.
     * Checkout only charges the selected ones, and the discount has to be
     * worked out over the same set or it would exceed the order.
     */
    public function test_a_deselected_line_does_not_earn_the_discount()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        $this->takeBundle($customer, $primary['product_id'], $bundle->id);
        $this->shipAll($customer);

        Passport::actingAs($customer, ['user'], 'user');

        $discount = (float)$this->getJson('/api/v1/cart/bundle-discount')->json('data.total');

        $this->assertGreaterThan(0, $discount);

        Cart::where('user_id', $customer->id)
            ->where('product_id', $lines[0]['product_id'])
            ->update(['selected' => 2]);

        $this->assertSame(
            0.0,
            (float)$this->getJson('/api/v1/cart/bundle-discount')->json('data.total'),
            'a bundle earned its discount on a line that is not being bought'
        );
    }

    /** A fixed discount larger than the goods cannot pay the customer. */
    public function test_a_discount_can_never_exceed_the_bundle()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle(
            $primary['product_id'], collect($lines)->pluck('product_id')->all(),
            999999, 'fixed'
        );

        $customer = $this->customer();

        $quoted = $this->takeBundle($customer, $primary['product_id'], $bundle->id);

        $this->assertEquals(0.0, (float)$quoted['price'],
            'an oversized discount should floor the bundle at zero, not below');

        $this->shipAll($customer);

        $rows = Cart::where('user_id', $customer->id)->get();

        $subtotal = Pricing::round($rows->sum(function ($row) {
            return (float)$row->price * (int)$row->quantity;
        }));

        $this->assertEquals($subtotal, BundleDiscount::totalFor($rows),
            'the discount exceeded the goods it was for');
    }

    /** Nothing is granted for a basket holding no bundle at all. */
    public function test_an_ordinary_basket_earns_nothing()
    {
        $lines = $this->buyableProducts(2);

        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $lines[0]['product_id'],
            'inventory_id' => $lines[0]['inventory_id'],
            'quantity' => 1,
            'shipping_place_id' => $this->place->id,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);

        $this->assertSame(
            0.0, BundleDiscount::totalFor(Cart::where('user_id', $customer->id)->get())
        );
    }
}
