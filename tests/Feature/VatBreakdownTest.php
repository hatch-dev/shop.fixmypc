<?php
namespace Tests\Feature;

use App\Models\Helper\Pricing;
use Tests\TestCase;

/**
 * The one place ex-VAT, VAT and inc-VAT are worked out.
 *
 * This catalogue runs two conventions side by side and which one applies is
 * the single most important thing to know before printing a price:
 *
 *   excludeVAT = 1   the listed price is ex-VAT and the rate is added on top
 *   excludeVAT = 0   the listed price already contains the VAT
 *
 * Getting that backwards is not a rounding error — it is a 46% swing on the
 * VAT figure — so both directions are pinned here, along with the rounding
 * identity every screen depends on.
 */
class VatBreakdownTest extends TestCase
{
    /** The B2B convention: the price is net, the rate goes on top. */
    public function test_an_exclusive_price_has_vat_added_to_it()
    {
        $split = Pricing::vatBreakdown(699.00, 23, false);

        $this->assertSame(699.00, $split['ex']);
        $this->assertSame(160.77, $split['vat']);
        $this->assertSame(859.77, $split['inc']);
        $this->assertFalse($split['inclusive']);
    }

    /** The retail convention: the VAT is already inside the figure. */
    public function test_an_inclusive_price_has_its_vat_taken_out_of_it()
    {
        $split = Pricing::vatBreakdown(699.00, 23, true);

        $this->assertSame(568.29, $split['ex']);
        $this->assertSame(130.71, $split['vat']);
        $this->assertSame(699.00, $split['inc'],
            'an inclusive price is its own inc-VAT total');
        $this->assertTrue($split['inclusive']);
    }

    /**
     * The two are not interchangeable, which is the whole reason the treatment
     * is recorded on the order line rather than guessed at.
     */
    public function test_the_two_conventions_give_different_vat()
    {
        $exclusive = Pricing::vatBreakdown(699.00, 23, false);
        $inclusive = Pricing::vatBreakdown(699.00, 23, true);

        $this->assertNotEquals($exclusive['vat'], $inclusive['vat']);
        $this->assertNotEquals($exclusive['inc'], $inclusive['inc']);
    }

    /**
     * "VAT Margin" is a configured 0% rule, not an absent one. A margin-scheme
     * line carries no VAT whichever way round the price is quoted.
     */
    public function test_a_zero_rate_produces_no_vat_either_way()
    {
        foreach ([true, false] as $inclusive) {
            $split = Pricing::vatBreakdown(699.00, 0, $inclusive);

            $this->assertSame(0.0, $split['vat']);
            $this->assertSame(699.00, $split['ex']);
            $this->assertSame(699.00, $split['inc']);
        }
    }

    /**
     * ex + VAT = inc, at two decimals, for every rate and amount the shop can
     * produce. `inc` is derived from the rounded parts rather than rounded
     * separately, so a screen printing all three can never show a penny that
     * does not add up.
     */
    public function test_the_parts_always_add_up_to_the_whole()
    {
        $rates = [23, 13.5, 9, 4.8, 0];
        $amounts = [0.01, 0.99, 1.00, 19.99, 100.00, 333.33, 699.00, 1378.00, 99999.99];

        foreach ($rates as $rate) {
            foreach ($amounts as $amount) {
                foreach ([true, false] as $inclusive) {

                    $split = Pricing::vatBreakdown($amount, $rate, $inclusive);

                    $this->assertSame(
                        $split['inc'],
                        round($split['ex'] + $split['vat'], 2),
                        "ex + vat != inc for {$amount} at {$rate}% "
                            . ($inclusive ? 'inclusive' : 'exclusive')
                    );
                }
            }
        }
    }

    /** An inclusive split returns the original amount untouched. */
    public function test_an_inclusive_split_preserves_the_price_charged()
    {
        foreach ([0.01, 9.99, 100.00, 699.00, 1234.56] as $amount) {
            $split = Pricing::vatBreakdown($amount, 23, true);

            $this->assertSame(Pricing::round($amount), $split['inc'],
                "an inclusive price must not move: {$amount}");
        }
    }

    /**
     * The rate comes from the tax rule, never from a constant. A flat-amount
     * rule has no percentage to report and says so.
     */
    public function test_the_rate_comes_from_the_rule()
    {
        $percentage = (object)['type' => (int)config('constants.priceType.PERCENTAGE'), 'price' => 13.5];
        $flat = (object)['type' => (int)config('constants.priceType.FLAT'), 'price' => 5];

        $this->assertSame(13.5, Pricing::vatRate($percentage));
        $this->assertSame(0.0, Pricing::vatRate($flat),
            'a flat-amount rule has no percentage to quote');
        $this->assertSame(0.0, Pricing::vatRate(null));
    }

    /** Which convention a product uses is read off the product, not assumed. */
    public function test_the_convention_is_read_from_the_product()
    {
        $this->assertFalse(Pricing::isVatInclusive((object)['excludeVAT' => 1]));
        $this->assertTrue(Pricing::isVatInclusive((object)['excludeVAT' => 0]));

        // A product with the column missing is treated the retail way, which
        // is what the checkout already does with a null excludeVAT.
        $this->assertTrue(Pricing::isVatInclusive((object)[]));
    }
}
