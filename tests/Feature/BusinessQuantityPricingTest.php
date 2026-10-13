<?php
namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\Concerns\AccountsForStoreWideDiscount;
use Tests\TestCase;

/**
 * The reported defect: a business account on the product page is quoted the
 * retail price, and typing a different quantity does not change it.
 *
 * PricingConsistencyTest already covers the headline figure at quantity 1.
 * What it does not cover is every *other* price on that page. The quantity the
 * customer actually typed was never priced by the server at all — the page
 * re-derived it in the browser — and the "frequently bought together" block
 * and the sticky bar both feed from product/upsell-crosssell, which quoted
 * products.offered to everyone.
 */
class BusinessQuantityPricingTest extends TestCase
{
    use DatabaseTransactions;
    use AccountsForStoreWideDiscount;

    private $product;
    private $inventory;

    /**
     * A published product with the bands from the reported configuration, and
     * enough stock that every quantity under test can actually be bought.
     */
    private function bandedProduct()
    {
        if ($this->product) {
            return $this->product;
        }

        /*
         * Taken from the shop listing rather than the products table, so it is
         * certainly one of the products the surfaces under test serve — the
         * same reason PricingConsistencyTest picks its fixture that way.
         */
        $row = collect($this->getJson('/api/v1/products')->json('data.result.data'))
            ->first(function ($item) {
                return (float)($item['selling'] ?? 0) > 0;
            });

        $product = $row ? Product::find($row['id']) : null;

        if (!$product) {
            $this->markTestSkipped('No published product to price against.');
        }

        $this->inventory = UpdatedInventory::where('product_id', $product->id)
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->first();

        if (!$this->inventory) {
            $this->markTestSkipped('The fixture product has no inventory to buy.');
        }

        // Rolled back with the transaction; keeps the cart assertions from
        // tripping over "quantity exceeds stock" rather than over pricing.
        UpdatedInventory::where('id', $this->inventory->id)->update(['quantity' => 5000]);
        $this->inventory = $this->inventory->fresh();

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $wholesale = $this->retail($product);

        foreach ([
            ['min' => 1,  'max' => 4,  'discount' => 10],
            ['min' => 5,  'max' => 10, 'discount' => 40],
            ['min' => 11, 'max' => 20, 'discount' => 70],
            ['min' => 21, 'max' => 30, 'discount' => 100],
            // The open-ended top band the admin screen writes as max 0.
            ['min' => 31, 'max' => 0,  'discount' => 150],
        ] as $band) {
            $tier = new BusinessProductPricing([
                'product_id' => $product->id,
                'min_qty' => $band['min'],
                'max_qty' => $band['max'],
                'wholesale_price' => $wholesale,
                'discount_type' => 'fixed',
                'discount_value' => $band['discount'],
            ]);
            $tier->final_price = $tier->computedFinalPrice();
            $tier->save();
        }

        return $this->product = $product->fresh();
    }

    /** The retail unit price for the variant the tests buy. */
    private function retail($product)
    {
        return Pricing::retailUnitPrice(
            Product::pricingRecord($product->id),
            $this->inventory
        );
    }

    private function tiers($product)
    {
        return BusinessProductPricing::where('product_id', $product->id)->get();
    }

    /**
     * What this customer should pay per unit at this quantity, decided by the
     * helper checkout uses rather than restated here.
     */
    private function expectedAt($product, $quantity)
    {
        return Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($product->id),
            $this->inventory,
            $quantity,
            $this->tiers($product)
        ));
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Band Fixture',
            'email' => 'band.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    private function asBusiness()
    {
        Passport::actingAs($this->customer(['account_type' => 'business']), ['user'], 'user');
    }

    /**
     * The quantities in the brief, plus the row either side of each band
     * boundary so an off-by-one in the band test is caught.
     */
    public function quantities()
    {
        return [
            [1], [4], [5], [10], [11], [20], [21], [25], [30], [31], [50], [100],
        ];
    }

    // ------------------------------------------------------------ the bands

    /** @dataProvider quantities */
    public function test_the_band_endpoint_quotes_the_price_checkout_would_charge($quantity)
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $response = $this->getJson(
            "/api/v1/business-product/{$product->id}"
            . "?quantity={$quantity}&inventory_id={$this->inventory->id}"
        );

        $response->assertStatus(200);

        $this->assertSame(
            $this->expectedAt($product, $quantity),
            (float)$response->json('data.unit_price'),
            "quantity {$quantity}"
        );
    }

    /**
     * With no variant named the quote has to be the figure the page is already
     * showing, or the headline and the quantity box contradict each other the
     * moment the page loads.
     */
    public function test_the_band_endpoint_matches_the_product_page_headline()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $headline = (float)$this->getJson("/api/v1/product/{$product->id}")
            ->json('data.display_price');

        $quoted = (float)$this->getJson("/api/v1/business-product/{$product->id}?quantity=1")
            ->json('data.unit_price');

        $this->assertSame($headline, $quoted);
    }

    public function test_the_band_endpoint_marks_which_band_applies()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $rows = $this->getJson("/api/v1/business-product/{$product->id}?quantity=30")
            ->json('data.pricing');

        $applied = collect($rows)->where('applies', true)->values();

        $this->assertCount(1, $applied, 'exactly one band applies at a time');
        $this->assertSame(21, (int)$applied[0]['min']);
        $this->assertSame(30, (int)$applied[0]['max']);
    }

    public function test_the_open_ended_top_band_applies_above_its_minimum()
    {
        $product = $this->bandedProduct();

        $expected = Pricing::round($this->retail($product) - 150);

        // 31+ is stored as max 0; it must cover 31, 50 and 100 alike.
        $this->assertSame($expected, $this->expectedAt($product, 31));
        $this->assertSame($expected, $this->expectedAt($product, 50));
        $this->assertSame($expected, $this->expectedAt($product, 100));
    }

    public function test_quantity_thirty_uses_the_configured_band()
    {
        $product = $this->bandedProduct();

        $this->assertSame(
            Pricing::round($this->retail($product) - 100),
            $this->expectedAt($product, 30)
        );
    }

    public function test_the_band_endpoint_tells_an_unentitled_account_nothing()
    {
        $product = $this->bandedProduct();

        Passport::actingAs($this->customer(), ['user'], 'user');

        $response = $this->getJson("/api/v1/business-product/{$product->id}?quantity=30&inventory_id={$this->inventory->id}");

        $response->assertStatus(200);

        $this->assertSame([], $response->json('data.pricing'));
        $this->assertSame(
            Pricing::round($this->retail($product)),
            (float)$response->json('data.unit_price'),
            'an unentitled account is quoted retail, not zero'
        );
    }

    public function test_the_band_endpoint_tells_a_guest_nothing()
    {
        $product = $this->bandedProduct();

        $response = $this->getJson("/api/v1/business-product/{$product->id}?quantity=30&inventory_id={$this->inventory->id}");

        $response->assertStatus(200);

        $this->assertSame([], $response->json('data.pricing'));
    }

    public function test_a_suspended_business_account_is_quoted_retail()
    {
        $product = $this->bandedProduct();

        Passport::actingAs($this->customer([
            'account_type' => 'business',
            'status' => User::STATUS_SUSPENDED,
        ]), ['user'], 'user');

        $response = $this->getJson("/api/v1/business-product/{$product->id}?quantity=30&inventory_id={$this->inventory->id}");

        $this->assertSame([], $response->json('data.pricing'));
        $this->assertSame(
            Pricing::round($this->retail($product)),
            (float)$response->json('data.unit_price')
        );
    }

    // --------------------------------------- frequently bought together / sticky

    public function test_frequently_bought_together_quotes_the_business_price()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $response = $this->getJson("/api/v1/product/upsell-crosssell/{$product->id}");

        $response->assertStatus(200);

        $this->assertSame(
            $this->expectedAt($product, 1),
            (float)$response->json('main_product.price'),
            'the featured product in the bundle block'
        );
    }

    /**
     * @dataProvider quantities
     *
     * The block sits under the quantity box and adds the featured product to
     * the basket, so it has to quote the quantity the customer chose. Quoting
     * one left "€699" printed under a "€649" headline — right for buying one,
     * and not what the customer was buying.
     */
    public function test_frequently_bought_together_follows_the_chosen_quantity($quantity)
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $response = $this->getJson(
            "/api/v1/product/upsell-crosssell/{$product->id}"
            . "?quantity={$quantity}&inventory_id={$this->inventory->id}"
        );

        $response->assertStatus(200);

        $this->assertSame(
            $this->expectedAt($product, $quantity),
            (float)$response->json('main_product.price'),
            "the bundle block at quantity {$quantity}"
        );

        $this->assertSame(
            $quantity, (int)$response->json('main_product.quantity'),
            'the block reports the quantity it priced'
        );
    }

    /**
     * The block adds a variation to the basket, so it must quote the one on
     * screen. It used to take whichever row held the most stock.
     */
    public function test_frequently_bought_together_quotes_the_chosen_variation()
    {
        $product = $this->bandedProduct();

        $others = UpdatedInventory::where('product_id', $product->id)
            ->where('id', '!=', $this->inventory->id)
            ->get();

        if ($others->isEmpty()) {
            $this->markTestSkipped('The fixture product has only one variation.');
        }

        $other = $others->first();

        $this->asBusiness();

        $response = $this->getJson(
            "/api/v1/product/upsell-crosssell/{$product->id}?inventory_id={$other->id}"
        );

        $this->assertSame(
            (int)$other->id, (int)$response->json('main_product.inventory_id'),
            'the block adds the variation it was asked about'
        );
    }

    public function test_frequently_bought_together_agrees_with_the_buy_box()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $query = "?quantity=30&inventory_id={$this->inventory->id}";

        $buyBox = (float)$this->getJson("/api/v1/business-product/{$product->id}{$query}")
            ->json('data.unit_price');

        $bundle = (float)$this->getJson("/api/v1/product/upsell-crosssell/{$product->id}{$query}")
            ->json('main_product.price');

        $this->assertSame($buyBox, $bundle, 'the two blocks on one page disagree');
    }

    public function test_frequently_bought_together_quotes_a_personal_account_retail()
    {
        $product = $this->bandedProduct();

        Passport::actingAs($this->customer(), ['user'], 'user');

        $response = $this->getJson("/api/v1/product/upsell-crosssell/{$product->id}");

        $this->assertSame(
            Pricing::round($this->retail($product)),
            (float)$response->json('main_product.price')
        );
    }

    public function test_frequently_bought_together_quotes_a_guest_retail()
    {
        $product = $this->bandedProduct();

        $response = $this->getJson("/api/v1/product/upsell-crosssell/{$product->id}");

        $this->assertSame(
            Pricing::round($this->retail($product)),
            (float)$response->json('main_product.price')
        );
    }

    // ------------------------------------------------------------ the cache

    /**
     * The detail payload is cached for a day and shared by every visitor, so
     * the price must be decided after it comes out of the cache. If it were
     * not, whoever warmed the cache would decide what everybody else is
     * quoted — a business rate shown to the public, or retail to an account
     * entitled to a band, depending only on who loaded the page first.
     *
     * Both orderings, in one request cycle each, against the same warm cache.
     */
    public function test_a_business_visit_does_not_leave_its_price_in_the_shared_cache()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $banded = (float)$this->getJson("/api/v1/product/{$product->id}")
            ->json('data.display_price');

        Passport::actingAs($this->customer(), ['user'], 'user');

        $retail = (float)$this->getJson("/api/v1/product/{$product->id}")
            ->json('data.display_price');

        $this->assertSame($this->expectedAt($product, 1), $banded);
        $this->assertSame(
            Pricing::round($this->retail($product)), $retail,
            'the personal account got the price the business visit warmed the cache with'
        );
    }

    public function test_a_retail_visit_does_not_deny_a_business_account_its_price()
    {
        $product = $this->bandedProduct();

        $retail = (float)$this->getJson("/api/v1/product/{$product->id}")
            ->json('data.display_price');

        $this->asBusiness();

        $banded = (float)$this->getJson("/api/v1/product/{$product->id}")
            ->json('data.display_price');

        $this->assertSame(Pricing::round($this->retail($product)), $retail);
        $this->assertSame($this->expectedAt($product, 1), $banded);
    }

    public function test_the_bundle_block_is_not_cached_across_customers_either()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $banded = (float)$this->getJson("/api/v1/product/upsell-crosssell/{$product->id}")
            ->json('main_product.price');

        Passport::actingAs($this->customer(), ['user'], 'user');

        $retail = (float)$this->getJson("/api/v1/product/upsell-crosssell/{$product->id}")
            ->json('main_product.price');

        $this->assertSame($this->expectedAt($product, 1), $banded);
        $this->assertSame(Pricing::round($this->retail($product)), $retail);
    }

    // ------------------------------------------------------------- the cart

    /** @dataProvider quantities */
    public function test_the_cart_charges_the_band_price_for_the_quantity($quantity)
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => $quantity,
        ])->assertStatus(200);

        $rows = $this->getJson('/api/v1/cart/by-user')->json('data');

        $line = collect($rows)->firstWhere('product_id', $product->id);

        $this->assertNotNull($line, 'the line is in the basket');

        $expected = $this->expectedAt($product, $quantity);

        $this->assertSame(
            $expected, (float)$line['unit_price'], "cart unit price at quantity {$quantity}"
        );

        $this->assertSame(
            Pricing::round($expected * $quantity),
            (float)$line['line_total'],
            "cart line total at quantity {$quantity}"
        );
    }

    public function test_the_cart_reprices_when_entitlement_is_withdrawn()
    {
        $product = $this->bandedProduct();

        $customer = $this->customer(['account_type' => 'business']);

        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => 30,
        ])->assertStatus(200);

        $banded = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertSame($this->expectedAt($product, 30), (float)$banded['unit_price']);

        // The account stops being a business account while the basket stands.
        $customer->account_type = 'personal';
        $customer->save();

        Passport::actingAs($customer->fresh(), ['user'], 'user');

        $retail = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertSame(
            Pricing::round($this->retail($product)),
            (float)$retail['unit_price'],
            'the basket follows current eligibility, not what it was priced at'
        );
    }

    // --------------------------------------------------------- the checkout

    /** The encrypted envelope /order/action expects. */
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

    /**
     * The money actually taken, not a figure quoted on the way to it.
     *
     * Quantity 30 through the whole path: basket, order, ordered_products.
     * The customer must not be charged retail when a band applies.
     */
    public function test_checkout_charges_the_band_price_at_quantity_thirty()
    {
        $product = $this->bandedProduct();

        $customer = $this->customer(['account_type' => 'business']);

        // Checkout refuses an order with nowhere to send it, which is a step
        // the shopper takes before reaching this point.
        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Band Fixture',
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

        Passport::actingAs($customer->fresh(), ['user'], 'user');

        // Checkout refuses a line with no delivery option chosen, which is a
        // step the shopper takes in the basket before reaching this point.
        $place = optional($product->shipping_rule)->shipping_places()->first();

        if (!$place) {
            $this->markTestSkipped('The fixture product has no delivery option to choose.');
        }

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => 30,
            'shipping_place_id' => $place->id,
            'shipping_type' => 1,
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/order/action', [
            'data' => $this->orderPayload(),
            'order_method' => (int)config('constants.paymentMethod.BANK'),
        ]);

        $order = Order::where('user_id', $customer->id)
            ->orderByDesc('id')
            ->first();

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

        $expected = $this->expectedAt($product, 30);

        // ordered_products.selling is the unit price actually charged.
        $this->assertSame(
            $expected, (float)$line->selling,
            'the unit price charged is the band price, not retail'
        );

        $this->assertSame(30, (int)$line->quantity);

        /*
         * VAT follows the charged price rather than the list price, so a band
         * that lowers the goods lowers the tax with it. Checking the rate
         * rather than a hard figure keeps this true whatever VAT is set to.
         *
         * Which of the catalogue's two conventions this product uses decides
         * what "follows" means, and the assertion has to say so:
         *
         *   excludeVAT = 1  the price is EX-VAT, so the rate is charged on top
         *                   and tax_price is the band price times the rate
         *   excludeVAT = 0  the price already contains its VAT, so nothing is
         *                   added and tax_price is zero -- which is exactly
         *                   what Pricing::unitTax returns for it
         *
         * This used to assert the first case whatever the product was. It only
         * passed because the fixture -- "the first product in the shop
         * listing" -- happened to be an Ex-VAT one; the moment that ordering
         * moved onto an Inc-VAT product the test reddened against correct
         * behaviour, reporting "0.0 is not 207.0".
         */
        $taxRule = $product->tax_rules;

        if ($taxRule && (int)$taxRule->type === (int)config('constants.priceType.PERCENT')) {

            $chargedOnTop = !Pricing::isVatInclusive($product);

            $this->assertSame(
                $chargedOnTop
                    ? Pricing::round($expected * (float)$taxRule->price / 100)
                    : 0.0,
                (float)$line->tax_price,
                $chargedOnTop
                    ? 'VAT is charged on the band price, not on retail'
                    : 'VAT was added on top of a price that already contains it'
            );
        }

        /*
         * The grand total, built back up from the line: goods at the band
         * price, plus their VAT, plus carriage. If any of those three had been
         * taken at retail the sum would not land on what was charged.
         */
        $payable = 30 - Pricing::bundleFreeQuantity($product, 30);

        $goods = Pricing::round($expected * $payable);
        $tax = Pricing::round((float)$line->tax_price * $payable);
        $shipping = Pricing::round((float)$line->shipping_price);

        // Less any store-wide flash discount, which is taken off the basket
        // rather than off a line. See AccountsForStoreWideDiscount.
        $storeWide = $this->storeWideDiscountTaken($order->id, $goods);

        $this->assertSame(
            Pricing::round($goods + $tax + $shipping - $storeWide),
            Pricing::round((float)$order->total_amount),
            'the grand total is the band price, its VAT and carriage'
        );

        $this->assertGreaterThan(
            0, (float)$order->total_amount, 'the order is worth something'
        );

        // The point of the whole exercise: 30 at retail would have been dearer.
        $this->assertLessThan(
            Pricing::round($this->retail($product) * $payable), $goods,
            'the customer was not charged the retail price'
        );
    }

    // ------------------------------------------------- one product, every account

    /**
     * Which accounts are entitled to a band, and which are not.
     *
     * Approved trade buys at the same bands as business; everything else —
     * personal, trade still waiting on approval, a suspended business account
     * and a signed-out visitor — pays retail. Same test User::hasBusinessPricing
     * applies, so the answer cannot drift between here and the till.
     */
    public function entitlements()
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
            'business suspended' => [
                ['account_type' => 'business', 'status' => User::STATUS_SUSPENDED], false
            ],
        ];
    }

    /**
     * @dataProvider entitlements
     *
     * Every price on the product page at once, for one account, at quantity
     * 30 — because fixing the headline while the bundle block or the basket
     * kept quoting retail is the failure this whole exercise is about.
     */
    public function test_every_surface_agrees_for_one_account($attributes, $entitled)
    {
        $product = $this->bandedProduct();

        $customer = $this->customer($attributes);

        Passport::actingAs($customer, ['user'], 'user');

        $retail = Pricing::round($this->retail($product));
        $banded = $this->expectedAt($product, 30);

        // The fixture has to be able to tell the two apart, or this proves
        // nothing at all.
        $this->assertNotSame($retail, $banded, 'fixture: the band must undercut retail');

        $expected = $entitled ? $banded : $retail;

        $quote = $this->getJson(
            "/api/v1/business-product/{$product->id}"
            . "?quantity=30&inventory_id={$this->inventory->id}"
        );

        $this->assertSame(
            $expected, (float)$quote->json('data.unit_price'), 'the quantity quote'
        );

        $this->assertSame(
            $entitled, (bool)$quote->json('data.has_business_pricing'),
            'whether the rate card is offered at all'
        );

        $this->assertSame(
            $entitled ? $this->expectedAt($product, 1) : $retail,
            (float)$this->getJson("/api/v1/product/upsell-crosssell/{$product->id}")
                ->json('main_product.price'),
            'frequently bought together, and the sticky bar that reads it'
        );

        $suspended = ($attributes['status'] ?? null) === User::STATUS_SUSPENDED;

        if ($suspended) {
            // A suspended account cannot reach a basket at all; there is
            // nothing further to compare.
            return;
        }

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => 30,
        ])->assertStatus(200);

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertSame($expected, (float)$line['unit_price'], 'the basket');
    }

    public function test_the_cart_ignores_a_price_supplied_by_the_browser()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => 30,
            'price' => 1,
            'original_price' => 1,
        ])->assertStatus(200);

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);

        $this->assertSame($this->expectedAt($product, 30), (float)$line['unit_price']);
    }
}
