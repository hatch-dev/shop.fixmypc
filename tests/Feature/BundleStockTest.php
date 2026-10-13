<?php

namespace Tests\Feature;

use App\Models\BundleDeal;
use App\Models\BundleDealProducts;
use App\Models\Cart;
use App\Models\Helper\CartHold;
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
 * Stock, on both sides of an Essentials Bundle.
 *
 * BundleStorefrontTest covers what is OFFERED: a bundle holding a sold-out
 * item is not shown and cannot be added. What it does not cover is everything
 * after that point, which is where a stock rule actually gets tested --
 * because the shop carries on selling while a basket sits there:
 *
 *   - the set is quoted and added, and THEN an item sells out;
 *   - the shopper asks for more of a bundle item than exists;
 *   - the same bundle is taken twice with one unit on the shelf;
 *   - the order completes, and the shelf has to move by the right amount.
 *
 * A bundle makes each of these worse than it is for a single line, because
 * all-or-nothing means a partial write is not a smaller sale -- it is a set
 * the customer was never quoted.
 */
class BundleStockTest extends TestCase
{
    use DatabaseTransactions;

    private $place;

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

            // A known shelf: plenty, and no backorder unless a test says so.
            UpdatedInventory::where('id', $inventory->id)
                ->update(['quantity' => 50, 'is_active' => 0]);

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
            'name' => 'Bundle Stock',
            'email' => 'bundle.stock.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ]);

        $address = UserAddress::create([
            'user_id' => $user->id,
            'name' => 'Bundle Stock',
            'phone' => $user->phone,
            'country' => 'IE', 'state' => 'Dublin', 'city' => 'Dublin',
            'zip' => 'D02X286', 'address_1' => '26 Main Street',
            'type' => 1, 'default' => 1,
        ]);

        $user->default_address = $address->id;
        $user->save();

        return $user->fresh();
    }

    private function bundle($primary, array $items)
    {
        $bundle = BundleDeal::create([
            'title' => 'QA Stock Bundle ' . uniqid(),
            'description' => 'Everything you need.',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'buy' => 0, 'free' => 0,
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

        return $this->postJson('/api/v1/cart/bundle', [
            'product_id' => $primaryId,
            'bundle_id' => $bundleId,
        ]);
    }

    /* ---- the shelf moving under a basket ----------------------------- */

    /**
     * An item that sells out after the bundle is in the basket stops the
     * order rather than shipping short.
     *
     * The block refuses to OFFER a bundle holding a sold-out item, but that
     * test happens once, when the page renders. The shop keeps selling while
     * the basket sits there, so the guard that matters is the one at
     * checkout.
     */
    public function test_an_item_selling_out_after_the_add_stops_the_order()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        $this->assertSame(200, (int)$this->takeBundle(
            $customer, $primary['product_id'], $bundle->id)->json('status'));

        $this->shipAll($customer);

        /*
         * The basket's reservation ran out, and the last unit went to somebody
         * else (no backorder on this one). While the reservation held, nobody
         * else could have had it - and the order rightly goes through on the
         * reserved unit; refusing it then was a bug, fixed 2026-10-09.
         */
        CartHold::releaseAll($customer->id, null);
        UpdatedInventory::where('id', $lines[0]['inventory_id'])
            ->update(['quantity' => 0, 'is_active' => 0]);

        $before = Order::where('user_id', $customer->id)->count();

        $response = $this->checkout();

        $this->assertSame(201, (int)$response->json('status'),
            'checkout completed with a sold-out item in the basket');

        $this->assertSame($before, Order::where('user_id', $customer->id)->count(),
            'an order was created for goods that cannot be shipped');
    }

    /**
     * A backordered item does not stop it.
     *
     * `is_active` on a variant means backorder is allowed, and those lines
     * are meant to go negative. The bundle must not become unsellable just
     * because the shelf is empty on a product the shop is happy to owe.
     */
    public function test_a_backordered_item_does_not_stop_the_order()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        // Since basket reservations (CartHold, 2026-09-26) the unit is taken
        // when the bundle goes into the basket, so the item has to be out of
        // stock and backorderable before that, not just before checkout.
        UpdatedInventory::where('id', $lines[0]['inventory_id'])
            ->update(['quantity' => 0, 'is_active' => 1]);

        $this->assertSame(200, (int)$this->takeBundle(
            $customer, $primary['product_id'], $bundle->id)->json('status'));

        $this->shipAll($customer);

        $this->checkout();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a backordered bundle item blocked the order');

        $this->assertLessThan(0,
            (int)UpdatedInventory::find($lines[0]['inventory_id'])->quantity,
            'a backordered line did not go negative');
    }

    /* ---- the shelf after the sale ------------------------------------ */

    /**
     * Every line of the bundle comes off the shelf, once.
     *
     * A bundle writes several lines in one transaction, so this is where a
     * set could plausibly be counted twice, or not at all.
     */
    public function test_the_order_takes_every_bundle_item_off_the_shelf()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        /*
         * Measured before the bundle goes into the basket: since basket
         * reservations (CartHold) the units leave the shelf on add, and the
         * order uses those same units - one each, never a second time.
         */
        $productIds = collect(array_merge([$primary], $lines))->pluck('product_id')->all();
        $shelf = UpdatedInventory::whereIn('product_id', $productIds)->pluck('quantity', 'id')
            ->map(function ($q) { return (int)$q; })->all();

        $this->assertSame(200, (int)$this->takeBundle(
            $customer, $primary['product_id'], $bundle->id)->json('status'));

        $this->shipAll($customer);

        // The variants the bundle actually put in the basket.
        $before = [];

        foreach (Cart::where('user_id', $customer->id)->get() as $row) {
            $before[(int)$row->inventory_id] = $shelf[(int)$row->inventory_id];
        }

        $this->assertNotEmpty($before);

        $this->checkout();

        $this->assertNotNull(Order::where('user_id', $customer->id)->orderByDesc('id')->first(),
            'checkout did not complete');

        foreach ($before as $inventoryId => $quantity) {
            $this->assertSame(
                $quantity - 1,
                (int)UpdatedInventory::find($inventoryId)->quantity,
                "inventory {$inventoryId} did not move by exactly one"
            );
        }
    }

    /* ---- asking for more than exists --------------------------------- */

    /**
     * A bundle cannot be taken twice off one unit of stock.
     *
     * The second add merges into the existing line, taking it to two. All or
     * nothing means the whole second bundle is refused -- and because the
     * write is one transaction, nothing from it may survive.
     */
    public function test_a_second_bundle_is_refused_whole_when_stock_runs_out()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        $this->assertSame(200, (int)$this->takeBundle(
            $customer, $primary['product_id'], $bundle->id)->json('status'));

        // One of them had a single unit, now held by the first bundle in this
        // basket (CartHold): none left free, and it cannot be backordered.
        UpdatedInventory::where('id', $lines[0]['inventory_id'])
            ->update(['quantity' => 0, 'is_active' => 0]);

        $snapshot = function () use ($customer) {
            return Cart::where('user_id', $customer->id)->orderBy('id')
                ->get()->mapWithKeys(function ($row) {
                    return [(int)$row->id => (int)$row->quantity];
                })->all();
        };

        $before = $snapshot();

        $response = $this->takeBundle($customer, $primary['product_id'], $bundle->id);

        $this->assertSame(201, (int)$response->json('status'),
            'a second bundle was taken off a shelf holding one unit');

        $this->assertSame($before, $snapshot(),
            'the refused bundle still moved quantities: it did not roll back whole');
    }

    /**
     * Raising one bundle line beyond the shelf is refused.
     *
     * The ordinary quantity control, on a line that arrived as part of a set.
     */
    public function test_a_bundle_line_cannot_be_raised_beyond_stock()
    {
        $lines = $this->buyableProducts(3);
        $primary = array_shift($lines);

        $bundle = $this->bundle($primary['product_id'], collect($lines)->pluck('product_id')->all());

        $customer = $this->customer();

        $this->assertSame(200, (int)$this->takeBundle(
            $customer, $primary['product_id'], $bundle->id)->json('status'));

        UpdatedInventory::where('id', $lines[0]['inventory_id'])
            ->update(['quantity' => 2, 'is_active' => 0]);

        Passport::actingAs($customer, ['user'], 'user');

        $line = Cart::where('user_id', $customer->id)
            ->where('inventory_id', $lines[0]['inventory_id'])->first();

        $response = $this->postJson('/api/v1/cart/action', [
            'id' => $line->id,
            'product_id' => $lines[0]['product_id'],
            'inventory_id' => $lines[0]['inventory_id'],
            'quantity' => 99,
        ]);

        $this->assertSame(201, (int)$response->json('status'),
            'a bundle line was raised to 99 against a shelf of 2');

        $this->assertLessThanOrEqual(2, (int)Cart::find($line->id)->quantity,
            'the line was written beyond the stock that exists');
    }
}
