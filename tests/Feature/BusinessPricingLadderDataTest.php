<?php
namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\UpdatedInventory;
use Tests\TestCase;

/**
 * The bands that are actually configured, rather than a fixture of them.
 *
 * BusinessQuantityPricingTest proves the engine prices an open-ended band
 * correctly. It builds its own ladder to do it, so it stayed green while the
 * only ladder in the database stopped at 30 — and a band that stops is not
 * "no further discount". Pricing::businessPrice falls back to the retail
 * ladder the moment no band covers the quantity, so the 31st unit was charged
 * 505.00 against 420.00 at 30, and the customer paid 2,635.00 more for buying
 * one more.
 *
 * These assertions read the live rows, so they fail on a mis-typed band rather
 * than on a mis-written helper. That is deliberate: this was a data defect and
 * no amount of fixture-based testing would have caught it.
 */
class BusinessPricingLadderDataTest extends TestCase
{
    /** Quantities either side of every boundary a configured ladder has. */
    private const PROBES = [1, 4, 5, 9, 10, 11, 19, 20, 21, 29, 30, 31, 32, 49, 50, 51, 100, 500, 1000];

    /**
     * Every product with bands, as [product record, its bands].
     *
     * Product::pricingRecord is what the cart and the order both price from,
     * so the retail figure these tests cap against is the real one.
     */
    private function configuredLadders()
    {
        $ladders = BusinessProductPricing::all()->groupBy('product_id');

        if ($ladders->isEmpty()) {
            $this->markTestSkipped('No business pricing is configured to check.');
        }

        return $ladders->map(function ($bands, $productId) {
            return [Product::pricingRecord($productId), $bands];
        })->filter(function ($pair) {
            return !is_null($pair[0]);
        });
    }

    /**
     * The unit price a business account is charged, through the same helper
     * the basket, the checkout and the invoice all call.
     */
    private function unitPriceAt($product, $bands, $quantity)
    {
        $inventory = UpdatedInventory::withCount('inventory_attributes')
            ->where('product_id', $product->id)
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->first();

        return Pricing::unitPrice($product, $inventory, $quantity, $bands);
    }

    /**
     * Buying more may never cost more per unit.
     *
     * This is the acceptance criterion in one assertion. A gap anywhere in a
     * ladder — above the top band, or between two bands — shows up here as a
     * price that goes back up.
     */
    public function test_no_configured_ladder_ever_raises_the_unit_price()
    {
        foreach ($this->configuredLadders() as $productId => list($product, $bands)) {

            $previousQuantity = null;
            $previousPrice = null;

            foreach (self::PROBES as $quantity) {

                $price = $this->unitPriceAt($product, $bands, $quantity);

                if (!is_null($previousPrice)) {
                    $this->assertLessThanOrEqual(
                        $previousPrice + 0.005,
                        $price,
                        "Product {$productId}: {$quantity} units cost {$price} each "
                        . "against {$previousPrice} at {$previousQuantity}. Buying more "
                        . 'must never cost more per unit.'
                    );
                }

                $previousQuantity = $quantity;
                $previousPrice = $price;
            }
        }
    }

    /**
     * The cliff that was reported, named explicitly.
     *
     * The assertion above would catch it, but a failure there reads as "some
     * probe regressed". This one names the boundary the business asked about.
     */
    public function test_thirty_one_units_are_never_dearer_than_thirty()
    {
        foreach ($this->configuredLadders() as $productId => list($product, $bands)) {

            $thirty = $this->unitPriceAt($product, $bands, 30);
            $thirtyOne = $this->unitPriceAt($product, $bands, 31);

            $this->assertLessThanOrEqual(
                $thirty + 0.005,
                $thirtyOne,
                "Product {$productId}: 31 units are priced at {$thirtyOne} each "
                . "against {$thirty} at 30."
            );
        }
    }

    /**
     * Every ladder ends open.
     *
     * A top band with a real maximum is a cliff waiting to happen: the next
     * quantity up matches nothing and pays retail. Expressed as max_qty 0,
     * which is what BusinessProductPricing::coversQuantity reads as "and over".
     */
    public function test_every_ladder_ends_in_an_open_ended_band()
    {
        foreach ($this->configuredLadders() as $productId => list($product, $bands)) {

            $top = $bands->sortByDesc('min_qty')->first();

            $this->assertTrue(
                (int)$top->max_qty <= 0 || (int)$top->max_qty < (int)$top->min_qty,
                "Product {$productId}: the highest band stops at {$top->max_qty}, so "
                . 'any larger order is charged the full retail price. Leave the '
                . "maximum blank to make it open-ended."
            );
        }
    }

    /**
     * Above the top band's minimum there is one rate, and it does not matter
     * how far above.
     *
     * This is the requirement in the form the business stated it: 21, 30, 31,
     * 50, 100 and 500 are all the "21 and over" price. The monotonic check
     * above would pass on a ladder that kept getting cheaper as the quantity
     * climbed; this one says the open-ended band is genuinely flat, so nobody
     * has quietly added a further tier on top of it.
     */
    public function test_the_open_ended_band_charges_one_rate_however_large_the_order()
    {
        foreach ($this->configuredLadders() as $productId => list($product, $bands)) {

            $top = $bands->sortByDesc('min_qty')->first();
            $from = (int)$top->min_qty;

            $rate = $this->unitPriceAt($product, $bands, $from);

            foreach (self::PROBES as $quantity) {

                if ($quantity < $from) {
                    continue;
                }

                $this->assertSame(
                    $rate,
                    $this->unitPriceAt($product, $bands, $quantity),
                    "Product {$productId}: {$quantity} units are not on the same rate "
                    . "as {$from}, which the top band covers."
                );
            }

            // And far past anything a probe list would think to try.
            $this->assertSame(
                $rate,
                $this->unitPriceAt($product, $bands, 100000),
                "Product {$productId}: a very large order falls off the ladder."
            );
        }
    }

    /**
     * Below the bottom of the ladder, the retail price - and only the retail
     * price.
     *
     * A ladder starting at 5 is saying bulk rates begin at five units. Quoting
     * one for a single unit is the mirror image of the cliff: it is the figure
     * on every listing tile and every search result, so it is the one a
     * customer sees first and the shop loses money on quietly.
     */
    public function test_no_configured_ladder_discounts_below_its_own_floor()
    {
        foreach ($this->configuredLadders() as $productId => list($product, $bands)) {

            $floor = (int)$bands->min('min_qty');

            if ($floor <= 1) {
                continue;
            }

            $retail = Pricing::round(Pricing::retailUnitPrice(
                $product,
                UpdatedInventory::withCount('inventory_attributes')
                    ->where('product_id', $product->id)
                    ->orderByDesc('quantity')
                    ->orderBy('id')
                    ->first()
            ));

            for ($quantity = 1; $quantity < $floor; $quantity++) {
                $this->assertSame(
                    $retail,
                    $this->unitPriceAt($product, $bands, $quantity),
                    "Product {$productId}: {$quantity} units are being given a bulk "
                    . "rate, but the ladder does not start until {$floor}."
                );
            }
        }
    }

    /**
     * No quantity between the first band and the last falls through a gap.
     *
     * Bands are written by hand, so 21-30 followed by 32-and-over is a typo
     * nobody would see until a customer ordered 31.
     */
    public function test_no_configured_ladder_has_a_gap_between_bands()
    {
        foreach ($this->configuredLadders() as $productId => list($product, $bands)) {

            $ordered = $bands->sortBy('min_qty')->values();

            for ($quantity = (int)$ordered->first()->min_qty;
                 $quantity <= (int)$ordered->last()->min_qty;
                 $quantity++) {

                $covered = $ordered->contains(function ($band) use ($quantity) {
                    return $band->coversQuantity($quantity);
                });

                $this->assertTrue(
                    $covered,
                    "Product {$productId}: no band covers {$quantity} units, so that "
                    . 'order is charged the full retail price.'
                );
            }
        }
    }
}
