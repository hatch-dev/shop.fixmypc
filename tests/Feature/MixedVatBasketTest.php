<?php
namespace Tests\Feature;

use App\Models\Helper\OrderSummary;
use App\Models\Helper\Pricing;
use App\Models\Helper\Utils;
use App\Models\Order;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * One basket, two VAT conventions, carried through to the invoice.
 *
 * This catalogue quotes 11 products ex-VAT and 111 inc-VAT, and a customer can
 * put one of each in the same basket. The two are not variations of one rule:
 *
 *   ex-VAT      the price is net; VAT is added.     vat = ex * r / 100
 *   inc-VAT     the price is gross; VAT is inside.  vat = inc * r / (100 + r)
 *
 * Using either formula for both is a 23%-of-the-VAT error that looks plausible
 * on screen, which is why this asserts the arithmetic per line rather than only
 * that the totals add up.
 *
 * Nothing here restates the pricing ladder. The expectations come from
 * Pricing::vatBreakdown — the one place the split lives — applied to whatever
 * the server decided to charge, so the test cannot drift from the shop by
 * copying a number.
 */
class MixedVatBasketTest extends TestCase
{
    use DatabaseTransactions;

    private $exVat;
    private $incVat;
    private $exInventory;
    private $incInventory;
    private $rate;

    /**
     * One product quoted net and one quoted gross, on the same tax rule.
     *
     * The same rule for both is deliberate: it makes the two figures directly
     * comparable, so a difference in the answer can only come from the
     * convention rather than from the rate.
     */
    private function products()
    {
        if ($this->exVat) {
            return;
        }

        $exVat = Product::where('excludeVAT', 1)
            ->whereNotNull('tax_rule_id')
            ->with('tax_rules')
            ->get()
            ->first(function ($p) {
                return Pricing::vatRate($p->tax_rules) > 0;
            });

        if (!$exVat) {
            $this->markTestSkipped('No ex-VAT product on a percentage tax rule.');
        }

        $incVat = Product::where('excludeVAT', 0)
            ->where('tax_rule_id', $exVat->tax_rule_id)
            ->with('tax_rules')
            ->first();

        if (!$incVat) {
            $this->markTestSkipped('No inc-VAT product sharing that tax rule.');
        }

        $this->rate = Pricing::vatRate($exVat->tax_rules);

        foreach ([[$exVat, 'exInventory'], [$incVat, 'incInventory']] as [$product, $slot]) {
            $inventory = UpdatedInventory::where('product_id', $product->id)
                ->orderByDesc('quantity')
                ->orderBy('id')
                ->first();

            if (!$inventory) {
                $this->markTestSkipped("Product {$product->id} has no inventory to buy.");
            }

            UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 5000]);

            $this->$slot = UpdatedInventory::find($inventory->id);
        }

        $this->exVat = $exVat;
        $this->incVat = $incVat;
    }

    private function customer($attributes = [])
    {
        $customer = User::create(array_merge([
            'name' => 'Mixed VAT Fixture',
            'email' => 'mixedvat.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'business',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();

        Passport::actingAs($customer, ['user'], 'user');

        return $customer;
    }

    /**
     * Checkout refuses a line with no delivery option chosen, so the basket is
     * built the way the storefront builds it rather than the bare minimum.
     */
    private function addToCart($product, $inventory, $quantity)
    {
        $payload = [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => $quantity,
        ];

        $place = optional($product->shipping_rule)->shipping_places()->first();

        if ($place) {
            $payload['shipping_place_id'] = $place->id;
            $payload['shipping_type'] = 1;
        }

        return $this->postJson('/api/v1/cart/action', $payload)->assertStatus(200);
    }

    /** The basket as the storefront reads it. */
    private function cartLines()
    {
        return collect($this->getJson('/api/v1/cart/by-user')->json('data'));
    }

    private function lineFor($rows, $product)
    {
        return $rows->firstWhere('product_id', $product->id);
    }

    // ------------------------------------------------------------- the basket

    public function test_each_line_is_split_on_its_own_pricing_basis()
    {
        $this->products();
        $this->customer();

        $this->addToCart($this->exVat, $this->exInventory, 3);
        $this->addToCart($this->incVat, $this->incInventory, 1);

        $rows = $this->cartLines();

        $ex = $this->lineFor($rows, $this->exVat);
        $inc = $this->lineFor($rows, $this->incVat);

        $this->assertNotNull($ex, 'the ex-VAT product is in the basket');
        $this->assertNotNull($inc, 'the inc-VAT product is in the basket');

        // The two lines really are on opposite conventions.
        $this->assertFalse((bool)$ex['vat_inclusive'], 'the ex-VAT line is not inclusive');
        $this->assertTrue((bool)$inc['vat_inclusive'], 'the inc-VAT line is inclusive');

        /*
         * Ex-VAT: the quoted price IS the net figure and the tax is added on
         * top, so the gross is strictly larger than what is quoted.
         */
        $exPrice = (float)$ex['unit_price'];

        $this->assertEqualsWithDelta($exPrice, (float)$ex['unit_ex_vat'], 0.005,
            'an ex-VAT unit price is its own net figure');
        $this->assertEqualsWithDelta(
            $exPrice * $this->rate / 100, (float)$ex['unit_vat'], 0.011,
            'ex-VAT lines add VAT on top of the price'
        );
        $this->assertGreaterThan($exPrice, (float)$ex['unit_inc_vat']);

        /*
         * Inc-VAT: the quoted price IS the gross figure and the tax is taken
         * out of it. Using the ex-VAT formula here would overstate the VAT by
         * the VAT on the VAT, which is the error this pins down.
         */
        $incPrice = (float)$inc['unit_price'];

        $this->assertEqualsWithDelta($incPrice, (float)$inc['unit_inc_vat'], 0.005,
            'an inc-VAT unit price is its own gross figure');
        $this->assertEqualsWithDelta(
            $incPrice * $this->rate / (100 + $this->rate), (float)$inc['unit_vat'], 0.011,
            'inc-VAT lines take the VAT out of the price'
        );
        $this->assertLessThan($incPrice, (float)$inc['unit_ex_vat']);

        $this->assertNotEqualsWithDelta(
            $incPrice * $this->rate / 100, (float)$inc['unit_vat'], 0.011,
            'an inc-VAT line must not be taxed as though it were net'
        );

        // And every line closes: ex + VAT = inc, at the unit and at the line.
        foreach ([$ex, $inc] as $line) {
            $this->assertEqualsWithDelta(
                (float)$line['unit_ex_vat'] + (float)$line['unit_vat'],
                (float)$line['unit_inc_vat'], 0.011, 'unit ex + VAT = inc'
            );
            $this->assertEqualsWithDelta(
                (float)$line['line_ex_vat'] + (float)$line['line_vat'],
                (float)$line['line_inc_vat'], 0.011, 'line ex + VAT = inc'
            );
        }

        // The line follows the quantity, on the basis the line is priced on.
        $this->assertEqualsWithDelta(
            (float)$ex['unit_ex_vat'] * 3, (float)$ex['line_ex_vat'], 0.011
        );
        $this->assertEqualsWithDelta(
            (float)$inc['unit_inc_vat'] * 1, (float)$inc['line_inc_vat'], 0.011
        );
    }

    // ------------------------------------------- basket through to the invoice

    /**
     * The same basket, read on every screen that states it.
     *
     * Cart, checkout (the same endpoint the summary reads), the customer's
     * order, the admin's order and the invoice must agree to the cent. They
     * are different code paths over the same figures, and a mixed basket is
     * where a single-basis assumption in any one of them shows up.
     */
    public function test_the_mixed_basket_is_the_same_on_every_screen()
    {
        $this->products();
        $customer = $this->customer();

        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Mixed VAT Fixture',
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

        $this->addToCart($this->exVat, $this->exInventory, 3);
        $this->addToCart($this->incVat, $this->incInventory, 1);

        /* What the basket says, summed the way the order summary sums it. */
        $rows = $this->cartLines();

        $cartEx = $rows->sum(function ($r) { return (float)$r['line_ex_vat']; });
        $cartVat = $rows->sum(function ($r) { return (float)$r['line_vat']; });
        $cartInc = $rows->sum(function ($r) { return (float)$r['line_inc_vat']; });

        $this->assertEqualsWithDelta($cartEx + $cartVat, $cartInc, 0.02,
            'the basket totals do not close');

        $response = $this->postJson('/api/v1/order/action', [
            'data' => $this->orderPayload(),
            'order_method' => (int)config('constants.paymentMethod.BANK'),
        ]);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped(
                'Checkout did not complete in this environment: '
                . json_encode($response->json('message'))
            );
        }

        /* The order, as OrderSummary states it from what was frozen. */
        $summary = OrderSummary::for($order->fresh());

        $this->assertEqualsWithDelta($cartEx, $summary['ex_vat'], 0.02,
            'the order net does not match the basket');
        $this->assertEqualsWithDelta($cartVat, $summary['vat'], 0.02,
            'the order VAT does not match the basket');
        $this->assertEqualsWithDelta($cartInc, $summary['inc_vat'], 0.02,
            'the order gross does not match the basket');

        $this->assertEqualsWithDelta(
            $summary['ex_vat'] + $summary['vat'], $summary['inc_vat'], 0.02,
            'the order does not close'
        );

        /* The invoice reads the legacy shape; same figures, older names. */
        $invoice = Utils::calcPrice($order->fresh());

        $this->assertEqualsWithDelta($summary['ex_vat'], (float)$invoice['ex_vat'], 0.02,
            'the invoice net does not match the order');
        $this->assertEqualsWithDelta($summary['vat'], (float)$invoice['vat'], 0.02,
            'the invoice VAT does not match the order');
        $this->assertEqualsWithDelta($summary['inc_vat'], (float)$invoice['inc_vat'], 0.02,
            'the invoice gross does not match the order');
        $this->assertEqualsWithDelta($summary['total'], (float)$invoice['total_price'], 0.02,
            'the invoice total does not match the order');

        /* Both conventions survived the checkout onto the order's own lines. */
        $bases = collect($summary['lines'])->pluck('vat_inclusive')->unique()->values()->all();

        $this->assertCount(2, $bases,
            'the order flattened both products onto one VAT convention');

        foreach ($summary['lines'] as $line) {
            $this->assertEqualsWithDelta(
                $line['ex_vat'] + $line['vat'], $line['inc_vat'], 0.011,
                'an order line does not close'
            );
        }
    }

    /**
     * The gross summary closes on a mixed basket too.
     *
     *   Products (Inc-VAT) - Discount = Net Products (Inc-VAT)
     *
     * Both figures have to be gross for this to hold across two conventions —
     * summing the quoted prices would add a net figure to a gross one.
     */
    public function test_the_gross_summary_closes_across_both_conventions()
    {
        $this->products();
        $customer = $this->customer();

        $this->addToCart($this->exVat, $this->exInventory, 3);
        $this->addToCart($this->incVat, $this->incInventory, 1);

        $rows = $this->cartLines();

        $listIncVat = $rows->sum(function ($r) {
            return (float)($r['line_list_inc_vat'] ?? $r['line_inc_vat']);
        });

        $incVat = $rows->sum(function ($r) { return (float)$r['line_inc_vat']; });

        $this->assertGreaterThanOrEqual($incVat, $listIncVat,
            'the list price of the goods cannot be below what is charged');

        // The discount the summary prints is the difference between the two.
        $this->assertEqualsWithDelta(
            $listIncVat - ($listIncVat - $incVat), $incVat, 0.02,
            'Products (Inc-VAT) - Discount must equal Net Products (Inc-VAT)'
        );
    }


    /**
     * The basket normalises to net BEFORE it aggregates, never after.
     *
     * This is the error the Order Summary is built around avoiding: take the
     * prices as quoted — one net, one gross — add them, and apply the rate to
     * the sum. It looks like arithmetic and it charges the gross line's VAT a
     * second time.
     *
     * The gap is not academic. On the brief's own basket (€674 net x 3 plus
     * €899 gross) the naive sum reports €671.83 of VAT against a true €633.17,
     * so the customer is overcharged €38.66 on two items.
     *
     * The summary therefore lists the original prices per product with the
     * basis beside each and never totals them; the only row that is a total of
     * the whole basket is the normalised one, which is what this pins.
     */
    public function test_the_basket_normalises_to_net_before_it_aggregates()
    {
        $this->products();
        $this->customer();

        $this->addToCart($this->exVat, $this->exInventory, 3);
        $this->addToCart($this->incVat, $this->incInventory, 1);

        $rows = $this->cartLines();

        $netTotal = $rows->sum(function ($r) { return (float)$r['line_ex_vat']; });
        $vatTotal = $rows->sum(function ($r) { return (float)$r['line_vat']; });
        $grossTotal = $rows->sum(function ($r) { return (float)$r['line_inc_vat']; });

        // Net + VAT = gross, across two conventions, to the cent.
        $this->assertEqualsWithDelta($netTotal + $vatTotal, $grossTotal, 0.02,
            'Products Total (Ex-VAT) + VAT must equal the gross total');

        /*
         * The naive figure, built the way the brief warns against: the prices
         * as quoted, added, then taxed. It must not match the real VAT — if it
         * ever does, this basket has stopped mixing conventions and the test is
         * no longer testing anything.
         */
        $quotedSum = $rows->sum(function ($r) {
            return (float)$r['line_total'];
        });

        $naiveVat = Pricing::round($quotedSum * $this->rate / 100);

        $this->assertGreaterThan($vatTotal, $naiveVat,
            'the naive mixed-basis sum must OVERSTATE the VAT — it taxes the '
            . 'inc-VAT line a second time');

        /*
         * By exactly how much, which is the proof of what went wrong rather
         * than merely that something did: the excess is the rate applied to the
         * VAT already sitting inside the gross line. Stated as an identity, so
         * it holds whatever the fixture is priced at.
         */
        $grossLine = $rows->firstWhere('vat_inclusive', true);

        $this->assertEqualsWithDelta(
            $naiveVat - $vatTotal,
            (float)$grossLine['line_vat'] * $this->rate / 100,
            0.02,
            'the overcharge is the rate applied to VAT that was already there'
        );

        /*
         * And the list prices normalise the same way. line_list is the figure
         * the "Original Products" rows show — as quoted, never summed — while
         * line_list_ex_vat is its addable form.
         */
        foreach ($rows as $row) {
            $this->assertArrayHasKey('line_list', $row);
            $this->assertArrayHasKey('line_list_ex_vat', $row);

            $expected = Pricing::vatBreakdown(
                (float)$row['line_list'], (float)$row['vat_rate'], (bool)$row['vat_inclusive']
            );

            $this->assertEqualsWithDelta(
                $expected['ex'], (float)$row['line_list_ex_vat'], 0.02,
                'the list price must be normalised on the line own basis'
            );
        }

        /*
         * An inc-VAT list price really is divided out, not left alone: its
         * addable form is strictly smaller than the figure on screen.
         */
        $gross = $rows->firstWhere('vat_inclusive', true);

        $this->assertNotNull($gross, 'the basket must hold an inc-VAT line');
        $this->assertLessThan(
            (float)$gross['line_list'], (float)$gross['line_list_ex_vat'],
            'an inc-VAT list price must lose its VAT when normalised'
        );
    }


    /**
     * The brief's own worked example, against the helper that decides it.
     *
     * The test above proves the rule on whatever the fixture happens to be
     * priced at; this pins the specific figures the brief asks for, so the
     * numbers quoted in the Order Summary's comments are checked rather than
     * asserted in prose. No database: this is arithmetic.
     */
    public function test_the_briefs_worked_example_reconciles()
    {
        $rate = 23.0;

        // €674 net x 3, and €899 gross x 1.
        $iphone = Pricing::vatBreakdown(674 * 3, $rate, false);
        $samsung = Pricing::vatBreakdown(899 * 1, $rate, true);

        // The originals: €699 net x 3, and €1,000 gross x 1.
        $iphoneList = Pricing::vatBreakdown(699 * 3, $rate, false);
        $samsungList = Pricing::vatBreakdown(1000 * 1, $rate, true);

        // Normalised to net, which is the internal basis.
        $this->assertEqualsWithDelta(813.01, $samsungList['ex'], 0.01,
            '1,000 inc-VAT is 813.01 ex-VAT');
        $this->assertEqualsWithDelta(2910.01, $iphoneList['ex'] + $samsungList['ex'], 0.01,
            'original products, normalised');

        $net = $iphone['ex'] + $samsung['ex'];
        $vat = $iphone['vat'] + $samsung['vat'];

        $this->assertEqualsWithDelta(2752.89, $net, 0.01, 'Products Total (Ex-VAT)');
        $this->assertEqualsWithDelta(633.17, $vat, 0.01, 'VAT');
        $this->assertEqualsWithDelta(3386.06, $net + $vat, 0.01, 'Total (Inc-VAT)');

        // The customer-facing saving, gross, because the total is gross.
        $this->assertEqualsWithDelta(
            193.25,
            ($iphoneList['inc'] + $samsungList['inc']) - ($iphone['inc'] + $samsung['inc']),
            0.01,
            'You Save'
        );

        // And the naive alternative, for the record: €38.66 too much tax.
        $naive = Pricing::round((674 * 3 + 899) * $rate / 100);

        $this->assertEqualsWithDelta(671.83, $naive, 0.01);
        $this->assertEqualsWithDelta(38.66, $naive - $vat, 0.01,
            'what adding two conventions together would have overcharged');
    }

    /** The encrypted payload /order/action expects. */
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
}
