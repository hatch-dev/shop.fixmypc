<?php
namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The basket, priced from the catalogue rather than from what it banked.
 *
 * carts.price is a record of what a line was priced at, not an instruction.
 * The quantity on a line moves, the customer's entitlement moves, and the
 * bands themselves move — so every read and every write reprices from the
 * catalogue, and the response says what it settled on.
 *
 * BusinessQuantityPricingTest covers the unit price the basket returns. This
 * covers what the basket does when the quantity *changes*, and what the write
 * response tells the storefront — which is where the reported defect was: the
 * server repriced correctly and nothing carried the answer to the page.
 */
class CartRepricingTest extends TestCase
{
    use DatabaseTransactions;

    private $product;
    private $inventory;

    /** Bands with a threshold either side of the reported quantity of 11. */
    private function bandedProduct()
    {
        if ($this->product) {
            return $this->product;
        }

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

        UpdatedInventory::where('id', $this->inventory->id)->update(['quantity' => 5000]);
        $this->inventory = $this->inventory->fresh();

        BusinessProductPricing::where('product_id', $product->id)->delete();

        $retail = $this->retail($product);

        foreach ([
            ['min' => 1,  'max' => 4,  'discount' => 0],
            ['min' => 5,  'max' => 9,  'discount' => 30],
            ['min' => 10, 'max' => 24, 'discount' => 70],
            ['min' => 25, 'max' => 0,  'discount' => 100],
        ] as $band) {
            $tier = new BusinessProductPricing([
                'product_id' => $product->id,
                'min_qty' => $band['min'],
                'max_qty' => $band['max'],
                'wholesale_price' => $retail,
                'discount_type' => 'fixed',
                'discount_value' => $band['discount'],
            ]);
            $tier->final_price = $tier->computedFinalPrice();
            $tier->save();
        }

        return $this->product = $product->fresh();
    }

    private function retail($product)
    {
        return Pricing::retailUnitPrice(
            Product::pricingRecord($product->id), $this->inventory
        );
    }

    /**
     * The struck-through "was" figure for a line, which is NOT the retail
     * price.
     *
     * Pricing::listPrice is what CartsController writes to original_price and
     * what the order freezes as actual_price -- the figure a discount is
     * measured against. retailUnitPrice is a different question: what this
     * line would be charged with no band, sale included. The two coincide
     * until a flash sale is running on the fixture, and then the sale is on
     * both sides of the subtraction and the line renders no discount at all.
     */
    private function strikethrough($product, $charged = null)
    {
        return Pricing::listPrice(
            Product::pricingRecord($product->id), $this->inventory, $charged
        );
    }

    private function expectedAt($product, $quantity)
    {
        return Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($product->id),
            $this->inventory,
            $quantity,
            BusinessProductPricing::where('product_id', $product->id)->get()
        ));
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Cart Fixture',
            'email' => 'cart.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    private function asBusiness()
    {
        $customer = $this->customer(['account_type' => 'business']);

        Passport::actingAs($customer, ['user'], 'user');

        return $customer;
    }

    /** One step of the basket's +/- control. */
    private function step($product, $by)
    {
        return $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => $by,
        ]);
    }

    private function line($product)
    {
        return collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('product_id', $product->id);
    }

    public function quantities()
    {
        return [[1], [2], [4], [5], [9], [10], [11], [24], [25], [30], [50], [100]];
    }

    // ------------------------------------------- what the write response says

    /**
     * The defect, at the boundary the report names.
     *
     * A line sitting at 10 stepped to 11 has to come back priced at 11. The
     * server always repriced it; the response only named `quantity`, so the
     * storefront kept the previous rate and multiplied it by the new count.
     */
    public function test_a_quantity_change_answers_with_the_price_for_the_new_quantity()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $this->step($product, 10)->assertStatus(200);

        $response = $this->step($product, 1);

        $response->assertStatus(200);

        $expected = $this->expectedAt($product, 11);

        $this->assertSame(11, (int)$response->json('data.quantity'));

        $this->assertSame(
            $expected, (float)$response->json('data.unit_price'),
            'the unit price for quantity 11'
        );

        $this->assertSame(
            $expected, (float)$response->json('data.price'),
            'and under the name the basket has always used'
        );

        $this->assertSame(
            Pricing::round($expected * 11),
            (float)$response->json('data.line_total'),
            'the line total the server settled on'
        );

        $original = (float)$response->json('data.original_price');

        $this->assertSame(
            Pricing::round($this->strikethrough($product, $expected)),
            $original,
            'the struck-through figure is the one the order will freeze'
        );

        // The promise listPrice makes: never below the charged price, so a
        // line can never render as a negative discount.
        $this->assertGreaterThanOrEqual(
            $expected, $original,
            'the struck-through figure is below the price being charged'
        );
    }

    /** @dataProvider quantities */
    public function test_the_write_response_is_priced_at_every_quantity($quantity)
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $response = $this->step($product, $quantity);

        $expected = $this->expectedAt($product, $quantity);

        $this->assertSame(
            $expected, (float)$response->json('data.unit_price'),
            "quantity {$quantity}"
        );

        $this->assertSame(
            Pricing::round($expected * $quantity),
            (float)$response->json('data.line_total'),
            "line total at quantity {$quantity}"
        );
    }

    // ------------------------------------------------ stepping up and down

    public function test_stepping_up_one_at_a_time_crosses_every_threshold()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $this->step($product, 1)->assertStatus(200);

        $seen = [1 => (float)$this->line($product)['unit_price']];

        for ($quantity = 2; $quantity <= 30; $quantity++) {

            $response = $this->step($product, 1);

            $this->assertSame($quantity, (int)$response->json('data.quantity'));

            $seen[$quantity] = (float)$response->json('data.unit_price');
        }

        foreach ([1, 4, 5, 9, 10, 11, 24, 25, 30] as $quantity) {
            $this->assertSame(
                $this->expectedAt($product, $quantity), $seen[$quantity],
                "stepping up, at quantity {$quantity}"
            );
        }

        // The thresholds actually moved the price, or this proves nothing.
        $this->assertNotSame($seen[4], $seen[5]);
        $this->assertNotSame($seen[9], $seen[10]);
        $this->assertNotSame($seen[24], $seen[25]);
    }

    public function test_stepping_down_moves_the_price_back_up_through_the_bands()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $this->step($product, 30)->assertStatus(200);

        foreach ([[5, 25], [1, 24], [14, 10], [1, 9], [4, 5], [4, 1]] as [$by, $quantity]) {

            $response = $this->step($product, -$by);

            $this->assertSame($quantity, (int)$response->json('data.quantity'));

            $this->assertSame(
                $this->expectedAt($product, $quantity),
                (float)$response->json('data.unit_price'),
                "stepping down, at quantity {$quantity}"
            );
        }
    }

    // --------------------------------------------- the basket read agrees

    /** @dataProvider quantities */
    public function test_the_basket_read_agrees_with_the_write($quantity)
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $written = $this->step($product, $quantity)->json('data');

        $read = $this->line($product);

        $this->assertSame(
            (float)$written['unit_price'], (float)$read['unit_price'],
            "read and write disagree at quantity {$quantity}"
        );

        $this->assertSame(
            (float)$written['line_total'], (float)$read['line_total']
        );
    }

    // ----------------------------------------------------- stale prices

    /**
     * The stored figure is a record, not an instruction. Whatever is in
     * carts.price, the basket answers with what the catalogue says now.
     */
    public function test_a_price_banked_on_the_row_is_not_trusted()
    {
        $product = $this->bandedProduct();

        $customer = $this->asBusiness();

        $this->step($product, 11)->assertStatus(200);

        /*
         * Scoped to this customer's own row.
         *
         * The fixture is a real catalogue product, so anyone else's basket can
         * hold it too — and an unscoped update wrote 1 onto their line while
         * the read below picked whichever row came first. That made the test
         * fail for whoever had last added the product in a browser, which is
         * not what it is here to detect.
         */
        $row = Cart::where('product_id', $product->id)
            ->where('user_id', $customer->id);

        // Someone, or something, left a wrong figure on the row.
        (clone $row)->update([
            'price' => 1,
            'original_price' => 1,
        ]);

        $line = $this->line($product);

        $this->assertSame(
            $this->expectedAt($product, 11), (float)$line['unit_price'],
            'the basket paid attention to the banked figure'
        );

        // And the repriced figure is written back, so the row stops lying.
        $this->assertSame(
            $this->expectedAt($product, 11),
            (float)(clone $row)->value('price')
        );
    }

    public function test_the_browser_cannot_name_the_price()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $response = $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => 11,
            'price' => 1,
            'unit_price' => 1,
            'original_price' => 1,
            'line_total' => 11,
            'discount' => 9999,
            'subtotal' => 1,
            'tax' => 0,
            'total' => 1,
        ]);

        $expected = $this->expectedAt($product, 11);

        $this->assertSame($expected, (float)$response->json('data.unit_price'));
        $this->assertSame(Pricing::round($expected * 11), (float)$response->json('data.line_total'));
        $this->assertSame($expected, (float)$this->line($product)['unit_price']);
    }

    public function test_entitlement_withdrawn_reprices_the_standing_basket()
    {
        $product = $this->bandedProduct();

        $customer = $this->asBusiness();

        $this->step($product, 11)->assertStatus(200);

        $this->assertSame(
            $this->expectedAt($product, 11), (float)$this->line($product)['unit_price']
        );

        $customer->account_type = 'personal';
        $customer->save();

        Passport::actingAs($customer->fresh(), ['user'], 'user');

        $this->assertSame(
            Pricing::round($this->retail($product)),
            (float)$this->line($product)['unit_price'],
            'the basket kept a band price the account is no longer entitled to'
        );
    }

    public function test_a_band_changing_reprices_the_standing_basket()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $this->step($product, 11)->assertStatus(200);

        $before = (float)$this->line($product)['unit_price'];

        // Staff re-cut the 10-24 band.
        $band = BusinessProductPricing::where('product_id', $product->id)
            ->where('min_qty', 10)
            ->first();

        $band->discount_value = 120;
        $band->final_price = $band->computedFinalPrice();
        $band->save();

        $after = (float)$this->line($product)['unit_price'];

        $this->assertNotSame($before, $after, 'the basket ignored the new band');
        $this->assertSame($this->expectedAt($product, 11), $after);
    }

    // --------------------------------------------------- other accounts

    /** @dataProvider quantities */
    public function test_a_personal_account_is_never_banded($quantity)
    {
        $product = $this->bandedProduct();

        Passport::actingAs($this->customer(), ['user'], 'user');

        $response = $this->step($product, $quantity);

        $this->assertSame(
            Pricing::round($this->retail($product)),
            (float)$response->json('data.unit_price'),
            "a personal account was banded at quantity {$quantity}"
        );
    }

    public function test_an_approved_trade_account_is_banded_like_a_business_one()
    {
        $product = $this->bandedProduct();

        Passport::actingAs($this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
        ]), ['user'], 'user');

        $response = $this->step($product, 11);

        $this->assertSame(
            $this->expectedAt($product, 11), (float)$response->json('data.unit_price')
        );
    }

    public function test_a_trade_account_awaiting_approval_is_not()
    {
        $product = $this->bandedProduct();

        Passport::actingAs($this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]), ['user'], 'user');

        $this->assertSame(
            Pricing::round($this->retail($product)),
            (float)$this->step($product, 11)->json('data.unit_price')
        );
    }

    // --------------------------------------------- more than one product

    public function test_each_line_is_priced_on_its_own_quantity()
    {
        $product = $this->bandedProduct();

        // A second product, with no bands of its own.
        $other = Product::where('status', 1)
            ->where('id', '!=', $product->id)
            ->whereHas('product_inventories', function ($query) {
                $query->where('quantity', '>', 5);
            })
            ->first();

        if (!$other) {
            $this->markTestSkipped('Only one buyable product in the catalogue.');
        }

        $otherInventory = UpdatedInventory::where('product_id', $other->id)
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->first();

        $this->asBusiness();

        $this->step($product, 11)->assertStatus(200);

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $other->id,
            'inventory_id' => $otherInventory->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $rows = collect($this->getJson('/api/v1/cart/by-user')->json('data'));

        $banded = $rows->firstWhere('product_id', $product->id);
        $plain = $rows->firstWhere('product_id', $other->id);

        $this->assertSame(
            $this->expectedAt($product, 11), (float)$banded['unit_price'],
            'the banded line'
        );

        $this->assertSame(
            Pricing::round(Pricing::unitPrice(
                Product::pricingRecord($other->id), $otherInventory, 2, collect()
            )),
            (float)$plain['unit_price'],
            'the line with no bands of its own'
        );

        // Changing one line does not disturb the other.
        $this->step($product, 14); // 11 -> 25, a new band

        $rows = collect($this->getJson('/api/v1/cart/by-user')->json('data'));

        $this->assertSame(
            $this->expectedAt($product, 25),
            (float)$rows->firstWhere('product_id', $product->id)['unit_price']
        );

        $this->assertSame(
            (float)$plain['unit_price'],
            (float)$rows->firstWhere('product_id', $other->id)['unit_price'],
            'the untouched line was repriced by a change to another line'
        );
    }

    // ----------------------------------------------- the basket and the till

    /**
     * @dataProvider quantities
     *
     * What the basket shows and what checkout charges are decided by the same
     * helper from the same bands, so they cannot drift. Asserted per quantity
     * because the reported defect only appeared once a quantity crossed a
     * band boundary.
     */
    public function test_the_basket_and_the_checkout_price_agree($quantity)
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $basket = (float)$this->step($product, $quantity)->json('data.unit_price');

        // What OrdersController::action would charge for this line: the same
        // Pricing call, from the same bands, at the same quantity.
        $charged = Pricing::round(Pricing::unitPrice(
            Product::pricingRecord($product->id),
            $this->inventory,
            $quantity,
            BusinessProductPricing::tierMap(
                auth('user')->user(), [$product->id]
            )->get($product->id)
        ));

        $this->assertSame($charged, $basket, "quantity {$quantity}");
    }

    /**
     * The product page, the basket and the till, on one quantity.
     *
     * Three surfaces, one figure — this is the comparison the brief asks for
     * and the one that was failing when each surface priced for itself.
     */
    public function test_the_product_page_and_the_basket_agree_at_quantity_eleven()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        $quoted = (float)$this->getJson(
            "/api/v1/business-product/{$product->id}"
            . "?quantity=11&inventory_id={$this->inventory->id}"
        )->json('data.unit_price');

        $basket = (float)$this->step($product, 11)->json('data.unit_price');

        $this->assertSame($this->expectedAt($product, 11), $quoted, 'the product page');
        $this->assertSame($quoted, $basket, 'the product page and the basket disagree');
    }

    // ------------------------------------------------- no duplicate lines

    public function test_stepping_the_quantity_never_creates_a_second_line()
    {
        $product = $this->bandedProduct();

        $this->asBusiness();

        foreach ([1, 1, 1, 5, -2, 10, -1] as $by) {
            $this->step($product, $by);
        }

        $rows = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->where('product_id', $product->id);

        $this->assertCount(1, $rows, 'the basket grew a duplicate line');

        $this->assertSame(15, (int)$rows->first()['quantity']);

        $this->assertSame(
            $this->expectedAt($product, 15), (float)$rows->first()['unit_price']
        );
    }
}
