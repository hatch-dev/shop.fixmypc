<?php
namespace Tests\Feature;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The hop between the priced basket and the checkout summary.
 *
 * /checkout showed a dash on every money row and "Updating prices..."
 * underneath, over a basket /cart had priced correctly a moment earlier. The
 * basket endpoint was never at fault. /shipping posts to /cart/update-shipping
 * and pushes straight to /checkout, and the storefront REPLACES its basket
 * with that response (store/cart.js INSERT_CART_SHIPPING) - so a response
 * without the prices on it does not omit a few fields, it strips the unit
 * price, the list price and the whole VAT split out of the basket on the last
 * hop before the summary that reads them.
 *
 * Nothing downstream can recover from that on its own: /checkout only refetches
 * when the basket is EMPTY, and the basket is not empty, only unpriced.
 *
 * So this holds update-shipping to the same contract as by-user. The expected
 * values are taken from by-user rather than written down, because the point is
 * not what the figures are - it is that the two endpoints cannot disagree.
 */
class CheckoutBasketRetrievalTest extends TestCase
{
    use DatabaseTransactions;

    /** Exactly the fields CheckoutRight's cartPrice() reads off a line. */
    const SUMMARY_FIELDS = [
        'unit_price', 'original_price', 'line_total',
        'vat_rate', 'vat_inclusive',
        'unit_ex_vat', 'unit_vat', 'unit_inc_vat',
        'line_ex_vat', 'line_vat', 'line_inc_vat',
        'line_list', 'line_list_ex_vat', 'line_list_inc_vat',
    ];

    private function customer()
    {
        $user = User::where('email', 'business.test01@yopmail.com')->first();

        if (!$user) {
            $this->markTestSkipped('the reported account is not in this database');
        }

        Passport::actingAs($user, ['*'], 'user');

        return $user;
    }

    private function basket($user)
    {
        $rows = Cart::where('user_id', $user->id)->where('selected', 1)->get();

        if ($rows->isEmpty()) {
            $this->markTestSkipped('that account has no basket to price');
        }

        return $rows;
    }

    /**
     * The payload /shipping posts: every selected line, keeping the delivery
     * method it already has, so the call changes nothing about the order.
     */
    private function updateShipping($rows)
    {
        $cart = $rows->map(function ($row) {
            return [
                'cart' => $row->id,
                'shipping_type' => (int)$row->shipping_type,
                'shipping_place' => $row->shipping_place_id
                    ? ['id' => $row->shipping_place_id]
                    : null,
            ];
        })->values()->all();

        $response = $this->postJson('/api/v1/cart/update-shipping',
            ['cart' => $cart], ['language' => 'en']);

        $this->assertSame(200, $response->json('status'),
            'the shipping update was refused: ' . json_encode($response->json('data')));

        return collect($response->json('data'));
    }

    private function byUser()
    {
        return collect($this->getJson('/api/v1/cart/by-user', ['language' => 'en'])->json('data'));
    }

    /**
     * The regression itself. Every one of these was absent from the response,
     * and none of them fails loudly in the browser: a missing key renders as
     * 0.00 or, where the summary is careful, as a dash and a spinner.
     */
    public function test_the_shipping_update_answers_with_a_priced_basket()
    {
        $user = $this->customer();
        $rows = $this->basket($user);

        $updated = $this->updateShipping($rows);

        $this->assertSame($rows->count(), $updated->count());

        foreach ($updated as $row) {
            foreach (self::SUMMARY_FIELDS as $key) {
                $this->assertArrayHasKey($key, $row,
                    "'$key' missing from /cart/update-shipping for product {$row['product_id']}"
                    . ' - the checkout summary has nothing to show for this line');
            }
        }
    }


    /**
     * A basket the summary would refuse to price.
     *
     * CheckoutRight counts a line with no `vat_rate` as stale and shows a dash
     * on every money row rather than inventing a figure. That is the component
     * behaving correctly; this asserts it is never given the chance.
     */
    public function test_no_line_comes_back_without_its_vat_treatment()
    {
        $user = $this->customer();
        $rows = $this->basket($user);

        foreach ($this->updateShipping($rows) as $row) {
            $this->assertNotNull($row['vat_rate'] ?? null,
                "product {$row['product_id']} came back with no VAT rate - the summary "
                . 'will show a dash and "Updating prices..." for the whole basket');

            $this->assertEqualsWithDelta(
                (float)$row['line_ex_vat'] + (float)$row['line_vat'],
                (float)$row['line_inc_vat'],
                0.02,
                "ex + VAT != inc for product {$row['product_id']}"
            );
        }
    }

    /**
     * The point of the fix, rather than a symptom of the bug.
     *
     * Choosing delivery or collection does not change what a product costs, so
     * the two endpoints have to answer with the same money. If they ever
     * disagree the summary moves between /cart and /checkout without the
     * customer having done anything.
     */
    public function test_both_endpoints_price_the_basket_identically()
    {
        $user = $this->customer();
        $rows = $this->basket($user);

        $before = $this->byUser()->keyBy('id');
        $after = $this->updateShipping($rows)->keyBy('id');

        foreach ($before as $id => $row) {
            $other = $after->get($id);

            $this->assertNotNull($other, "line $id vanished from the shipping response");

            foreach (self::SUMMARY_FIELDS as $key) {
                if (in_array($key, ['vat_inclusive'], true)) {
                    $this->assertSame((bool)$row[$key], (bool)$other[$key],
                        "'$key' differs between /cart and the checkout hop on line $id");
                    continue;
                }

                $this->assertEqualsWithDelta(
                    (float)($row[$key] ?? 0), (float)($other[$key] ?? 0), 0.02,
                    "'$key' differs between /cart and the checkout hop on line $id"
                );
            }
        }
    }


    /**
     * The repricing must not have been bought by charging a different price.
     *
     * repriceForCustomer writes a drifted figure back to carts.price, so this
     * runs the call twice: the second answer has to match the first, and the
     * stored row has to match both. A basket whose prices move every time the
     * delivery method is confirmed would be a worse bug than the one fixed.
     */
    public function test_confirming_shipping_twice_changes_nothing()
    {
        $user = $this->customer();
        $rows = $this->basket($user);

        $first = $this->updateShipping($rows)->keyBy('id');
        $second = $this->updateShipping($rows)->keyBy('id');

        foreach ($first as $id => $row) {
            $this->assertEqualsWithDelta(
                (float)$row['unit_price'], (float)$second->get($id)['unit_price'], 0.005,
                "line $id was priced differently the second time"
            );

            $this->assertEqualsWithDelta(
                (float)$row['unit_price'], (float)Cart::find($id)->price, 0.005,
                "line $id does not match what was stored for it"
            );
        }
    }
}
