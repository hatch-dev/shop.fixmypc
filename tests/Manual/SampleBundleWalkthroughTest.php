<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BundleDeal;
use App\Models\BundleDealProducts;
use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Helper\BundleDiscount;
use App\Models\Helper\OrderSummary;
use App\Models\Helper\Utils;
use App\Models\Order;
use App\Models\OrderedBundleDiscount;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * A live walkthrough of one real Essentials Bundle. NOT a regression test:
 * it writes to the dev database and leaves the bundle behind on purpose.
 */
class SampleBundleWalkthroughTest extends TestCase
{
    const PRIMARY = 99492285;                      // Samsung Galaxy A54 5G
    const ITEMS = [61675263, 84335272, 77931326];  // holder, charger set, cable

    private $headers = [];
    private $admin;
    private $bundleId;
    private $guestToken;
    private $customer;
    private $orderId;
    private $stockBefore = [];

    private function say($line = '')
    {
        fwrite(STDERR, $line . "\n");
    }

    private function check($label, $ok, $detail = '')
    {
        $this->say(sprintf('  [%s] %-58s %s', $ok ? 'PASS' : 'FAIL', $label, $detail));

        if (!$ok) {
            $this->fail($label . ' -- ' . $detail);
        }
    }

    private function money($n)
    {
        return 'EUR ' . number_format((float)$n, 2);
    }

    private function asAdmin()
    {
        $this->admin = Admin::whereHas('roles', function ($q) {
            $q->where('name', 'superadmin');
        })->first();

        $this->app['auth']->forgetGuards();

        $this->headers = [
            'Authorization' => 'Bearer ' . $this->admin->createToken('admin', ['admin'])->accessToken,
        ];
    }

    private function asGuest()
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_walkthrough()
    {
        try {
            $this->stepCreate();
            $this->stepValidation();
            $this->stepAssign();
            $this->stepAdminList();
            $this->stepStorefront();
            $this->stepAddToBasket();
            $this->stepBasketDiscount();
            $this->stepBreakTheSet();
            $this->stepQuantity();
            $this->stepSignIn();
            $this->stepCheckout();
            $this->stepDocuments();
            $this->stepDeleteGuard();
            $this->stepSummary();

        } finally {
            $this->cleanUp();
        }
    }

    /* ---- 1. create it through the admin API -------------------------- */

    private function stepCreate()
    {
        $this->say();
        $this->say('1. ADMIN CREATE  (POST /api/admin/bundle-deal/action)');

        $this->asAdmin();

        // Idempotent: clear out anything a previous run of this walkthrough
        // left behind, so the sample is always exactly one bundle.
        $stale = BundleDeal::where('title', 'Car Essentials Bundle')->pluck('id');

        if ($stale->isNotEmpty()) {
            Product::whereIn('bundle_deal_id', $stale->map(fn($i) => (string)$i))
                ->update(['bundle_deal_id' => null]);
            Cart::whereIn('bundle_deal_id', $stale)->update(['bundle_deal_id' => null]);
            BundleDealProducts::whereIn('bundle_deal_id', $stale)->delete();
            BundleDeal::whereIn('id', $stale)->delete();

            $this->say('  (removed ' . $stale->count() . ' bundle(s) from an earlier run)');
        }

        $response = $this->postJson('/api/admin/bundle-deal/action', [
            'title' => 'Car Essentials Bundle',
            'description' => 'Everything you need to run your phone in the car: '
                . 'a magnetic holder, a fast charger and a Lightning cable.',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'buy' => 0,
            'free' => 0,
            'products' => collect(self::ITEMS)->map(function ($id) {
                return ['id' => $id];
            })->all(),
        ], $this->headers);

        $this->check('saved', (int)$response->json('status') === 200,
            json_encode($response->json('message')));

        $this->bundleId = (int)$response->json('data.id');

        $saved = BundleDealProducts::where('bundle_deal_id', $this->bundleId)
            ->pluck('product_id')->map('intval')->sort()->values()->all();

        $this->check('kept all three products', $saved === collect(self::ITEMS)->sort()->values()->all(),
            'ids ' . implode(', ', $saved));

        $bundle = BundleDeal::find($this->bundleId);

        $this->check('owner set by the server, not the body',
            (int)$bundle->admin_id === (int)$this->admin->id, "admin_id={$bundle->admin_id}");

        $this->say("  -> bundle #{$this->bundleId} \"{$bundle->title}\" at {$bundle->discount_value}% off");
    }

    /* ---- 2. the validation that was missing -------------------------- */

    private function stepValidation()
    {
        $this->say();
        $this->say('2. ADMIN VALIDATION');

        $this->asAdmin();

        $base = [
            'title' => 'QA reject me',
            'description' => 'd',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'buy' => 0, 'free' => 0,
            'products' => [['id' => self::ITEMS[0]]],
        ];

        $empty = $this->postJson('/api/admin/bundle-deal/action',
            array_merge($base, ['products' => []]), $this->headers);

        $this->check('a bundle with no products is refused',
            (int)$empty->json('status') === 201, json_encode($empty->json('message')));

        $wild = $this->postJson('/api/admin/bundle-deal/action',
            array_merge($base, ['discount_value' => 500]), $this->headers);

        $this->check('500% is refused',
            (int)$wild->json('status') === 201, json_encode($wild->json('message')));

        $stray = $this->postJson('/api/admin/bundle-deal/action/' . $this->bundleId,
            array_merge($base, [
                'title' => 'Car Essentials Bundle',
                // The real values, not $base's: this save is a genuine edit
                // and would otherwise quietly move the bundle to 10%.
                'discount_value' => 15,
                'products' => collect(self::ITEMS)->map(fn($id) => ['id' => $id])->all(),
                'token' => 'a-reissued-token',
                'admin_id' => 999999,
            ]), $this->headers);

        $this->check('a stray field does not break the save',
            (int)$stray->json('status') === 200, json_encode($stray->json('message')));

        $this->check('the body could not reassign the owner',
            (int)BundleDeal::find($this->bundleId)->admin_id === (int)$this->admin->id);
    }

    /* ---- 3. offer it under a product --------------------------------- */

    private function stepAssign()
    {
        $this->say();
        $this->say('3. ASSIGN TO A PRODUCT');

        Product::where('id', self::PRIMARY)->update([
            'bundle_deal_id' => (string)$this->bundleId,
        ]);

        $primary = Product::find(self::PRIMARY);

        $this->check('offered under ' . $primary->title,
            (string)$primary->bundle_deal_id === (string)$this->bundleId);
    }

    /* ---- 4. the admin list ------------------------------------------- */

    private function stepAdminList()
    {
        $this->say();
        $this->say('4. ADMIN LIST  (GET /api/admin/bundle-deal/all)');

        $this->asAdmin();

        $row = collect($this->getJson(
            '/api/admin/bundle-deal/all?orderby=id&type=desc&q=' . urlencode('Car Essentials'),
            $this->headers
        )->json('data.data'))->firstWhere('id', $this->bundleId);

        $this->check('listed', !is_null($row));

        $card = collect((new \App\Services\BundleService())
            ->forProduct(Product::find(self::PRIMARY)))->firstWhere('id', $this->bundleId);

        $this->check('list total = storefront total',
            abs((float)$row['total_price'] - (float)$card['lines_total']) < 0.01,
            $this->money($row['total_price']) . ' vs ' . $this->money($card['lines_total']));

        $this->check('list final = storefront price',
            abs((float)$row['final_price'] - (float)$card['price']) < 0.01,
            $this->money($row['final_price']) . ' vs ' . $this->money($card['price']));

        $this->check('flagged sellable', $row['sellable'] === true);
    }

    /* ---- 5. the block on the product page ---------------------------- */

    private function stepStorefront()
    {
        $this->say();
        $this->say('5. PRODUCT PAGE  (GET /api/v1/product/upsell-crosssell/' . self::PRIMARY . ')');

        $this->asGuest();

        $card = collect($this->getJson('/api/v1/product/upsell-crosssell/' . self::PRIMARY)
            ->json('bundle_deal'))->firstWhere('id', $this->bundleId);

        $this->check('the block offers it', !is_null($card));
        $this->check('all three items shown', count($card['items']) === 3);

        foreach ($card['items'] as $item) {
            $this->say(sprintf('       %-46s %s  (variant %d)',
                substr($item['name'], 0, 46), $this->money($item['price']), $item['inventory_id']));
        }

        $sum = collect($card['items'])->sum('price');

        $this->check('lines_total is the sum of the items',
            abs($sum - (float)$card['lines_total']) < 0.01, $this->money($card['lines_total']));

        $this->check('price = lines_total less the discount',
            abs((float)$card['lines_total'] - (float)$card['discount'] - (float)$card['price']) < 0.01,
            $this->money($card['lines_total']) . ' - ' . $this->money($card['discount'])
            . ' = ' . $this->money($card['price']));

        $this->check('the discount is 15% of the set',
            abs((float)$card['discount'] - round($sum * 0.15, 2)) < 0.02,
            $this->money($card['discount']));

        $this->check('every item carries the variant the basket will charge',
            collect($card['items'])->every(fn($i) => (int)$i['inventory_id'] > 0));
    }

    /* ---- 6. taking it as a guest ------------------------------------- */

    private function stepAddToBasket()
    {
        $this->say();
        $this->say('6. ADD TO BASKET  (POST /api/v1/cart/bundle, as a guest)');

        $this->asGuest();

        $this->guestToken = (string)Str::uuid();
        GuestUser::create(['user_token' => $this->guestToken]);

        $response = $this->postJson('/api/v1/cart/bundle', [
            'user_token' => $this->guestToken,
            'product_id' => self::PRIMARY,
            'bundle_id' => $this->bundleId,
        ]);

        $this->check('accepted', (int)$response->json('status') === 200,
            json_encode($response->json('message')));

        $rows = Cart::where('user_token', $this->guestToken)->get();

        $this->check('one line per item', $rows->count() === 3, $rows->count() . ' lines');

        $this->check('every line carries the bundle marker',
            $rows->where('bundle_deal_id', $this->bundleId)->count() === 3);

        $this->check('every line is quantity 1',
            $rows->every(fn($r) => (int)$r->quantity === 1));
    }

    /* ---- 7. what the basket says it is worth ------------------------- */

    private function stepBasketDiscount()
    {
        $this->say();
        $this->say('7. BASKET DISCOUNT  (GET /api/v1/cart/bundle-discount)');

        $this->asGuest();

        $body = $this->getJson('/api/v1/cart/bundle-discount?user_token=' . $this->guestToken)
            ->json('data');

        $this->check('one named row', count($body['bundles']) === 1,
            $body['bundles'][0]['title'] ?? '');

        $card = collect($this->getJson('/api/v1/product/upsell-crosssell/' . self::PRIMARY)
            ->json('bundle_deal'))->firstWhere('id', $this->bundleId);

        $this->check('the basket pays what the label quoted',
            abs((float)$body['total'] - (float)$card['discount']) < 0.01,
            'quoted ' . $this->money($card['discount']) . ', basket ' . $this->money($body['total']));
    }

    /* ---- 8. break the set, then restore it --------------------------- */

    private function stepBreakTheSet()
    {
        $this->say();
        $this->say('8. ALL OR NOTHING');

        $this->asGuest();

        $victim = Cart::where('user_token', $this->guestToken)->orderBy('id')->first();
        $keptProduct = (int)$victim->product_id;
        $keptInventory = (int)$victim->inventory_id;

        $this->deleteJson('/api/v1/cart/delete/' . $victim->id, [
            'user_token' => $this->guestToken,
        ]);

        $after = BundleDiscount::totalFor(Cart::where('user_token', $this->guestToken)->get());

        $this->check('removing one item ends the discount', $after == 0.0, $this->money($after));

        // Put it back on its own -- an ordinary add, no bundle marker.
        $this->postJson('/api/v1/cart/action', [
            'user_token' => $this->guestToken,
            'product_id' => $keptProduct,
            'inventory_id' => $keptInventory,
            'quantity' => 1,
        ]);

        $restored = BundleDiscount::totalFor(Cart::where('user_token', $this->guestToken)->get());

        $this->check('re-adding it on its own restores the discount',
            $restored > 0, $this->money($restored));
    }

    /* ---- 9. quantity must not inflate it ----------------------------- */

    private function stepQuantity()
    {
        $this->say();
        $this->say('9. QUANTITY');

        $rows = Cart::where('user_token', $this->guestToken)->get();
        $before = BundleDiscount::totalFor($rows);

        $line = $rows->firstWhere('bundle_deal_id', $this->bundleId);

        Cart::where('id', $line->id)->update(['quantity' => 5]);

        $after = BundleDiscount::totalFor(Cart::where('user_token', $this->guestToken)->get());

        $this->check('raising ONE item to 5 does not enlarge the saving',
            abs($before - $after) < 0.01,
            $this->money($before) . ' -> ' . $this->money($after));

        Cart::where('id', $line->id)->update(['quantity' => 1]);

        // Two of everything IS two sets.
        Cart::where('user_token', $this->guestToken)->update(['quantity' => 2]);

        $two = BundleDiscount::totalFor(Cart::where('user_token', $this->guestToken)->get());

        $this->check('two of everything pays twice',
            abs($two - ($before * 2)) < 0.02,
            $this->money($before) . ' x2 = ' . $this->money($two));

        Cart::where('user_token', $this->guestToken)->update(['quantity' => 1]);
    }

    /* ---- 10. signing in ---------------------------------------------- */

    private function stepSignIn()
    {
        $this->say();
        $this->say('10. SIGN-IN MERGE');

        $this->customer = User::create([
            'name' => 'Bundle Walkthrough',
            'email' => 'bundle.walkthrough.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ]);

        $address = UserAddress::create([
            'user_id' => $this->customer->id,
            'name' => 'Bundle Walkthrough',
            'phone' => $this->customer->phone,
            'country' => 'IE', 'state' => 'Dublin', 'city' => 'Dublin',
            'zip' => 'D02X286', 'address_1' => '26 Main Street',
            'type' => 1, 'default' => 1,
        ]);

        $this->customer->default_address = $address->id;
        $this->customer->save();
        $this->customer = $this->customer->fresh();

        // The account already owns one of the bundle's products, bought alone.
        Passport::actingAs($this->customer, ['user'], 'user');

        $owned = Cart::where('user_token', $this->guestToken)
            ->where('bundle_deal_id', $this->bundleId)->orderBy('id')->first();

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $owned->product_id,
            'inventory_id' => $owned->inventory_id,
            'quantity' => 1,
        ]);

        $guestRows = Cart::where('user_token', $this->guestToken)->get();

        $quoted = BundleDiscount::totalFor($guestRows);

        /*
         * Two of the three, not three: step 8 removed a line and re-added it
         * on its own, which is an ordinary add and carries no marker. The set
         * is still complete, which is the whole point of checking the basket
         * rather than the markers.
         */
        $markedBefore = $guestRows->where('bundle_deal_id', $this->bundleId)->count();

        $merge = $this->postJson('/api/v1/cart/merge', ['user_token' => $this->guestToken]);

        $this->check('merged', (int)$merge->json('status') === 200,
            json_encode($merge->json('message')));

        $merged = Cart::where('user_id', $this->customer->id)->get();

        $markedAfter = $merged->where('bundle_deal_id', $this->bundleId)->count();

        $this->check('no marker was dropped by the merge',
            $markedAfter === $markedBefore,
            "{$markedBefore} marked before, {$markedAfter} after");

        $after = BundleDiscount::totalFor($merged);

        $this->check('the discount survived the merge',
            abs($quoted - $after) < 0.01,
            'guest ' . $this->money($quoted) . ' -> signed in ' . $this->money($after));
    }

    /* ---- 11. checkout ------------------------------------------------ */

    private function stepCheckout()
    {
        $this->say();
        $this->say('11. CHECKOUT  (POST /api/v1/order/action)');

        Passport::actingAs($this->customer, ['user'], 'user');

        $place = optional(Product::find(self::ITEMS[0])->shipping_rule)
            ->shipping_places()->first();

        Cart::where('user_id', $this->customer->id)->update([
            'shipping_place_id' => $place->id,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
            'selected' => (int)config('constants.status.PUBLIC'),
        ]);

        $rows = Cart::where('user_id', $this->customer->id)->get();
        $expected = BundleDiscount::totalFor($rows);

        // Checkout decrements stock; note it so cleanUp can put it back.
        foreach (UpdatedInventory::whereIn('id', $rows->pluck('inventory_id'))->get() as $inv) {
            $this->stockBefore[$inv->id] = (int)$inv->quantity;
        }

        $json = json_encode([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ]);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        $response = $this->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC',
                hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
                hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);

        $this->check('order placed', (int)$response->json('status') === 200,
            json_encode($response->json('message')));

        $order = Order::where('user_id', $this->customer->id)->orderByDesc('id')->first();
        $this->orderId = $order->id;

        $recorded = (float)OrderedBundleDiscount::where('order_id', $order->id)->sum('discount');

        $this->check('the order took the discount the basket showed',
            abs($recorded - $expected) < 0.01,
            'basket ' . $this->money($expected) . ', charged ' . $this->money($recorded));

        $row = OrderedBundleDiscount::where('order_id', $order->id)->first();

        $this->check('recorded against the bundle, by name',
            (int)$row->bundle_deal_id === $this->bundleId && $row->title === 'Car Essentials Bundle',
            "\"{$row->title}\"");

        $this->say("  -> order #{$order->order} (id {$order->id})");
    }

    /* ---- 12. every document that states a total ---------------------- */

    private function stepDocuments()
    {
        $this->say();
        $this->say('12. THE DOCUMENTS');

        $order = Order::find($this->orderId);

        $summary = OrderSummary::for($order);
        $granted = (float)OrderedBundleDiscount::where('order_id', $order->id)->sum('discount');

        $this->check('OrderSummary names it',
            array_key_exists('bundle_discount', $summary)
            && abs((float)$summary['bundle_discount'] - $granted) < 0.01,
            $this->money($summary['bundle_discount'] ?? 0));

        $p = Utils::calcPrice($order);

        $this->check('calcPrice carries it to the email and the PDF',
            abs((float)($p['bundle_discount'] ?? 0) - $granted) < 0.01,
            $this->money($p['bundle_discount'] ?? 0));

        // Exactly the rows mail_templates/partials/totals.blade.php prints.
        $stated = (float)$p['inc_vat']
            + (float)$p['shipping_price']
            - (float)($p['voucher_price'] ?? 0)
            - (float)($p['flash_discount'] ?? 0)
            - (float)($p['bundle_discount'] ?? 0)
            - (float)($p['loyalty_discount'] ?? 0)
            - (float)($p['points_discount'] ?? 0);

        $this->check('the emailed rows add up to the total charged',
            abs($stated - (float)$p['total_price']) < 0.02,
            'rows ' . $this->money($stated) . ' vs total ' . $this->money($p['total_price']));

        $this->asAdmin();

        $detail = $this->getJson('/api/admin/order/find/' . $this->orderId, $this->headers);

        $shown = (float)($detail->json('data.calculations.bundle_discount') ?? -1);

        $this->check('the admin order detail states it',
            abs($shown - $granted) < 0.01, $this->money($shown));
    }

    /* ---- 13. deleting a live promotion ------------------------------- */

    private function stepDeleteGuard()
    {
        $this->say();
        $this->say('13. DELETE GUARD');

        $this->asAdmin();

        $this->deleteJson('/api/admin/bundle-deal/delete/' . $this->bundleId, [], $this->headers);

        $this->check('refused while a product offers it',
            !is_null(BundleDeal::find($this->bundleId)));

        // Now offered alongside another -- the comma-list case.
        Product::where('id', self::PRIMARY)
            ->update(['bundle_deal_id' => '2,' . $this->bundleId]);

        $this->deleteJson('/api/admin/bundle-deal/delete/' . $this->bundleId, [], $this->headers);

        $this->check('still refused when offered alongside another',
            !is_null(BundleDeal::find($this->bundleId)),
            'products.bundle_deal_id = ' . Product::find(self::PRIMARY)->bundle_deal_id);

        Product::where('id', self::PRIMARY)
            ->update(['bundle_deal_id' => (string)$this->bundleId]);
    }

    private function stepSummary()
    {
        $bundle = BundleDeal::find($this->bundleId);
        $primary = Product::find(self::PRIMARY);

        $this->say();
        $this->say('LEFT IN THE DATABASE');
        $this->say("  bundle_deals #{$this->bundleId}  \"{$bundle->title}\"  "
            . "{$bundle->discount_value}% off, 3 products");
        $this->say("  offered under product {$primary->id} ({$primary->title})");
    }

    /**
     * Put the database back, except for the sample bundle itself.
     *
     * Each section stands alone. The stock restore used to run last and was
     * skipped entirely when the customer delete threw, which left three
     * variants a unit light with nothing to say so.
     */
    private function cleanUp()
    {
        foreach ($this->stockBefore as $inventoryId => $quantity) {
            try {
                UpdatedInventory::where('id', $inventoryId)->update(['quantity' => $quantity]);
            } catch (\Throwable $e) {
                $this->say('  could not restore stock for inventory ' . $inventoryId
                    . ': ' . $e->getMessage());
            }
        }

        if ($this->orderId) {
            \DB::table('ordered_bundle_discount')->where('order_id', $this->orderId)->delete();
            \DB::table('ordered_products')->where('order_id', $this->orderId)->delete();
            \DB::table('ordered_products_inventories')->where('order_id', $this->orderId)->delete();

            /*
             * Through the query builder, not the model: Order uses
             * SoftDeletes, so Order::delete() only stamps deleted_at and
             * leaves the row -- which then blocks the customer delete below
             * on orders_user_id_foreign, while Order::where(...) can no
             * longer see it to explain why.
             */
            \DB::table('orders')->where('id', $this->orderId)->delete();
        }

        if ($this->customer) {
            Cart::where('user_id', $this->customer->id)->delete();
            UserAddress::where('user_id', $this->customer->id)->delete();
            User::where('id', $this->customer->id)->delete();
        }

        if ($this->guestToken) {
            Cart::where('user_token', $this->guestToken)->delete();
            GuestUser::where('user_token', $this->guestToken)->delete();
        }

        // The QA bundles from step 2 never saved; nothing to remove there.
        $this->say();
        $this->say('  cleaned up: the walkthrough order, its customer, both baskets and the stock it drew down.');
    }
}
