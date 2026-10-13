<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessProductPricing;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The admin screen that writes the quantity bands, which is where every
 * business and trade price the shop charges actually comes from.
 *
 * The rest of the suite covers who is entitled to a band price once it exists.
 * Nothing covered the writing of them, so the validation that keeps two bands
 * from both claiming quantity 10, and the derivation that keeps the browser
 * from naming its own final_price, were only ever exercised by hand.
 */
class BusinessPricingAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        $this->app['auth']->forgetGuards();

        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    private function asGuest()
    {
        $this->app['auth']->forgetGuards();
    }

    private function product()
    {
        $product = Product::where('status', 1)
            ->whereNotNull('selling')->where('selling', '>', 0)
            ->first();

        if (!$product) {
            $this->markTestSkipped('No published product to attach bands to.');
        }

        return $product;
    }

    /** A second product, for the cases about moving bands between products. */
    private function otherProduct($not)
    {
        $product = Product::where('status', 1)
            ->whereNotNull('selling')->where('selling', '>', 0)
            ->where('id', '!=', $not->id)
            ->first();

        if (!$product) {
            $this->markTestSkipped('Only one published product to work with.');
        }

        return $product;
    }

    private function save($productId, array $rows, $previousId = null)
    {
        $this->admin();

        $url = '/api/admin/business-products/action' . ($previousId ? "/{$previousId}" : '');

        return $this->postJson($url, [
            'product_id' => $productId,
            'pricing' => $rows,
        ]);
    }

    private function bandsOf($productId)
    {
        return BusinessProductPricing::where('product_id', $productId)
            ->orderBy('min_qty')->get();
    }

    // ---- who may write a price ---------------------------------------------

    /**
     * A refused admin call answers HTTP 201 and carries the refusal in the
     * body, so asserting on the HTTP code alone passes for the wrong reason:
     * every one of these would read as "refused" even if the route were open.
     */
    private function assertRefused($response)
    {
        $this->assertSame(204, $response->json('status'));
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }

    public function test_an_anonymous_caller_cannot_read_the_bands()
    {
        $product = $this->product();
        $this->asGuest();

        $this->assertRefused($this->getJson('/api/admin/business-products/all'));
        $this->assertRefused(
            $this->getJson("/api/admin/business-products/find/{$product->id}")
        );
    }

    public function test_an_anonymous_caller_cannot_write_or_delete_bands()
    {
        $product = $this->product();
        $this->asGuest();

        $this->assertRefused($this->postJson('/api/admin/business-products/action', [
            'product_id' => $product->id,
            'pricing' => [['min' => 1, 'max' => 0, 'wholesale_price' => 1]],
        ]));

        $this->assertRefused(
            $this->deleteJson("/api/admin/business-products/delete/{$product->id}")
        );

        $this->assertCount(0, $this->bandsOf($product->id));
    }

    // ---- storing bands ------------------------------------------------------

    public function test_a_band_is_stored_and_read_back_in_quantity_order()
    {
        $product = $this->product();

        $response = $this->save($product->id, [
            ['min' => 10, 'max' => 0, 'wholesale_price' => 50, 'discount_type' => 'fixed', 'discount_value' => 0],
            ['min' => 1, 'max' => 9, 'wholesale_price' => 80, 'discount_type' => 'fixed', 'discount_value' => 0],
        ]);

        $response->assertStatus(200);

        $pricing = $response->json('data.pricing');

        $this->assertCount(2, $pricing);
        // Ordered by min_qty, not by the order the screen happened to send.
        $this->assertSame(1, $pricing[0]['min']);
        $this->assertSame(10, $pricing[1]['min']);
        $this->assertSame(0, $pricing[1]['max'], 'A max of 0 is the open-ended top band.');
    }

    /**
     * final_price is what the customer is charged, so it is derived from the
     * band's own inputs. A browser naming its own figure must not be believed.
     */
    public function test_the_final_price_is_derived_not_taken_from_the_browser()
    {
        $product = $this->product();

        $response = $this->save($product->id, [[
            'min' => 1, 'max' => 0,
            'wholesale_price' => 100,
            'discount_type' => 'fixed',
            'discount_value' => 25,
            'final_price' => 1,
        ]]);

        $response->assertStatus(200);

        $this->assertSame(75.0, (float)$response->json('data.pricing.0.final_price'));
        $this->assertSame(75.0, (float)$this->bandsOf($product->id)->first()->final_price);
    }

    public function test_a_percentage_band_derives_its_own_final_price()
    {
        $product = $this->product();

        $this->save($product->id, [[
            'min' => 1, 'max' => 0,
            'wholesale_price' => 200,
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]])->assertStatus(200);

        $band = $this->bandsOf($product->id)->first();

        $this->assertSame('percentage', $band->discount_type);
        $this->assertSame(180.0, (float)$band->final_price);
    }

    public function test_a_discount_larger_than_the_price_floors_at_zero()
    {
        $product = $this->product();

        $this->save($product->id, [[
            'min' => 1, 'max' => 0,
            'wholesale_price' => 10,
            'discount_type' => 'fixed',
            'discount_value' => 40,
        ]])->assertStatus(200);

        $this->assertSame(0.0, (float)$this->bandsOf($product->id)->first()->final_price);
    }

    /**
     * The screen sends the whole ladder every time, so saving two bands over
     * three must leave two — appending instead would silently re-create the
     * overlaps the validation exists to prevent.
     */
    public function test_saving_replaces_the_whole_ladder_rather_than_appending()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 1, 'max' => 5, 'wholesale_price' => 90],
            ['min' => 6, 'max' => 10, 'wholesale_price' => 80],
            ['min' => 11, 'max' => 0, 'wholesale_price' => 70],
        ])->assertStatus(200);

        $this->assertCount(3, $this->bandsOf($product->id));

        $this->save($product->id, [
            ['min' => 1, 'max' => 0, 'wholesale_price' => 60],
        ])->assertStatus(200);

        $bands = $this->bandsOf($product->id);

        $this->assertCount(1, $bands);
        $this->assertSame(60.0, (float)$bands->first()->wholesale_price);
    }

    public function test_an_empty_ladder_clears_the_product_of_bands()
    {
        $product = $this->product();

        $this->save($product->id, [['min' => 1, 'max' => 0, 'wholesale_price' => 50]])
            ->assertStatus(200);

        $this->save($product->id, [])->assertStatus(200);

        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_moving_a_ladder_to_another_product_leaves_none_behind()
    {
        $product = $this->product();
        $other = $this->otherProduct($product);

        $this->save($product->id, [['min' => 1, 'max' => 0, 'wholesale_price' => 50]])
            ->assertStatus(200);

        // The screen passes the product the ladder used to be on as the id.
        $this->save($other->id, [['min' => 1, 'max' => 0, 'wholesale_price' => 40]], $product->id)
            ->assertStatus(200);

        $this->assertCount(0, $this->bandsOf($product->id));
        $this->assertCount(1, $this->bandsOf($other->id));
    }

    // ---- what cannot be stored ---------------------------------------------

    public function test_a_ladder_without_a_product_is_refused()
    {
        $this->admin();

        $response = $this->postJson('/api/admin/business-products/action', [
            'pricing' => [['min' => 1, 'max' => 0, 'wholesale_price' => 10]],
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('Choose a product', $response->json('message'));
    }

    public function test_a_product_that_is_not_there_is_refused()
    {
        $response = $this->save(99999999, [['min' => 1, 'max' => 0, 'wholesale_price' => 10]]);

        $this->assertSame(201, $response->json('status'));
        $this->assertCount(0, $this->bandsOf(99999999));
    }

    /**
     * The one that matters: two rows both covering quantity 10 leave the price
     * depending on row order.
     */
    public function test_overlapping_bands_are_refused_and_nothing_is_stored()
    {
        $product = $this->product();

        $response = $this->save($product->id, [
            ['min' => 1, 'max' => 10, 'wholesale_price' => 90],
            ['min' => 5, 'max' => 20, 'wholesale_price' => 80],
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('overlaps', $response->json('message'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_a_second_open_ended_band_is_an_overlap()
    {
        $product = $this->product();

        $response = $this->save($product->id, [
            ['min' => 10, 'max' => 0, 'wholesale_price' => 70],
            ['min' => 20, 'max' => 0, 'wholesale_price' => 60],
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_touching_bands_that_do_not_overlap_are_allowed()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 1, 'max' => 9, 'wholesale_price' => 90],
            ['min' => 10, 'max' => 0, 'wholesale_price' => 80],
        ])->assertStatus(200);

        $this->assertCount(2, $this->bandsOf($product->id));
    }

    // ---- the open-ended band ------------------------------------------------

    /**
     * The ladder the business actually runs, stored as it is written.
     *
     * The top band has no maximum, so there is no quantity above which the
     * customer falls back to the retail price. That fall-back is the defect
     * this whole feature exists to close.
     */
    public function test_the_ladder_the_business_asked_for_is_storable()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 500, 'discount_value' => 50],
            ['min' => 11, 'max' => 20, 'wholesale_price' => 500, 'discount_value' => 70],
            ['min' => 21, 'max' => 0, 'wholesale_price' => 500, 'discount_value' => 80],
        ])->assertStatus(200);

        $bands = $this->bandsOf($product->id);

        $this->assertCount(3, $bands);
        $this->assertSame(21, (int)$bands[2]->min_qty);
        $this->assertSame(0, (int)$bands[2]->max_qty, 'The top band must have no maximum.');

        // The rule the whole thing turns on, stated as a price rather than a
        // column value: no quantity above the top band's minimum costs more.
        foreach ([21, 22, 30, 31, 50, 100, 1000] as $quantity) {
            $this->assertTrue(
                $bands[2]->coversQuantity($quantity),
                "The open-ended band does not cover {$quantity} units."
            );
        }

        $this->assertFalse($bands[2]->coversQuantity(20));
    }

    /**
     * An open-ended band covers every quantity above its minimum, so a band
     * above it could never apply. Refused as its own rule, because "row 2
     * overlaps row 1" does not tell an admin which row to change.
     */
    public function test_an_open_ended_band_that_is_not_the_last_is_refused()
    {
        $product = $this->product();

        $response = $this->save($product->id, [
            ['min' => 5, 'max' => 0, 'wholesale_price' => 90],
            ['min' => 11, 'max' => 20, 'wholesale_price' => 80],
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('final one', $response->json('message'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_two_open_ended_bands_are_refused_by_name()
    {
        $product = $this->product();

        $response = $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 90],
            ['min' => 11, 'max' => 0, 'wholesale_price' => 80],
            ['min' => 21, 'max' => 0, 'wholesale_price' => 70],
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('Only the final band', $response->json('message'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    /**
     * The order the screen sends rows in is the order they were typed, so the
     * rule has to hold however they arrive.
     */
    public function test_an_open_ended_band_sent_first_is_still_judged_by_quantity()
    {
        $product = $this->product();

        $response = $this->save($product->id, [
            ['min' => 21, 'max' => 0, 'wholesale_price' => 70],
            ['min' => 5, 'max' => 10, 'wholesale_price' => 90],
            ['min' => 11, 'max' => 20, 'wholesale_price' => 80],
        ]);

        // Sent first, but it is still the highest band, so this is valid.
        $response->assertStatus(200);

        $bands = $this->bandsOf($product->id);

        $this->assertCount(3, $bands);
        $this->assertSame(0, (int)$bands[2]->max_qty);
    }

    // ---- gaps ---------------------------------------------------------------

    /**
     * 5-10 followed by 21-and-over reads as a ladder and behaves as a cliff:
     * an order of 11 matches nothing and is charged the full retail price.
     */
    public function test_a_gap_between_bands_is_refused()
    {
        $product = $this->product();

        $response = $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 90],
            ['min' => 21, 'max' => 0, 'wholesale_price' => 70],
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('gap', $response->json('message'));
        // Named, so the admin does not have to work out which quantities.
        $this->assertStringContainsString('11-20 units', $response->json('message'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_a_gap_of_a_single_quantity_is_refused()
    {
        $product = $this->product();

        $response = $this->save($product->id, [
            ['min' => 21, 'max' => 30, 'wholesale_price' => 80],
            ['min' => 32, 'max' => 0, 'wholesale_price' => 70],
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('31 unit', $response->json('message'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    /**
     * A gap below the first band is the point, not a mistake: a ladder that
     * starts at 5 is saying bulk rates begin at five units and 1-4 pays
     * retail.
     */
    public function test_a_ladder_that_starts_above_one_is_allowed()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 90],
            ['min' => 11, 'max' => 0, 'wholesale_price' => 80],
        ])->assertStatus(200);

        $this->assertCount(2, $this->bandsOf($product->id));
    }

    // ---- editing a ladder that already exists -------------------------------

    /**
     * Turning "21-30" into "21 and over" is a change of maximum on one band,
     * not a new band. The row that was there is the row that is updated, so
     * the ladder does not grow a duplicate tier and created_at - which the
     * ladder list is ordered by - is not reset by a price correction.
     */
    public function test_opening_the_top_band_edits_the_row_rather_than_adding_one()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 500, 'discount_value' => 50],
            ['min' => 11, 'max' => 20, 'wholesale_price' => 500, 'discount_value' => 70],
            ['min' => 21, 'max' => 30, 'wholesale_price' => 500, 'discount_value' => 80],
        ])->assertStatus(200);

        $before = $this->bandsOf($product->id);
        $topId = $before[2]->id;

        // The same three rows back, with the maximum taken off the last one.
        $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 500, 'discount_value' => 50],
            ['min' => 11, 'max' => 20, 'wholesale_price' => 500, 'discount_value' => 70],
            ['min' => 21, 'max' => 0, 'wholesale_price' => 500, 'discount_value' => 80],
        ])->assertStatus(200);

        $after = $this->bandsOf($product->id);

        $this->assertCount(3, $after, 'Opening a band must not add a duplicate tier.');
        $this->assertSame($topId, $after[2]->id, 'The band that was there is the one edited.');
        $this->assertSame(0, (int)$after[2]->max_qty);
        $this->assertSame(420.0, (float)$after[2]->final_price);

        // And the bands below it are untouched, ids included.
        $this->assertSame($before[0]->id, $after[0]->id);
        $this->assertSame($before[1]->id, $after[1]->id);
    }

    /**
     * Deleting the top band leaves a ladder that stops, which the shop will
     * store - and another final tier can be put on straight afterwards.
     */
    public function test_the_top_band_can_be_removed_and_replaced()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 90],
            ['min' => 11, 'max' => 20, 'wholesale_price' => 80],
            ['min' => 21, 'max' => 0, 'wholesale_price' => 70],
        ])->assertStatus(200);

        $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 90],
            ['min' => 11, 'max' => 20, 'wholesale_price' => 80],
        ])->assertStatus(200);

        $this->assertCount(2, $this->bandsOf($product->id));

        $this->save($product->id, [
            ['min' => 5, 'max' => 10, 'wholesale_price' => 90],
            ['min' => 11, 'max' => 20, 'wholesale_price' => 80],
            ['min' => 21, 'max' => 0, 'wholesale_price' => 60],
        ])->assertStatus(200);

        $bands = $this->bandsOf($product->id);

        $this->assertCount(3, $bands);
        $this->assertSame(0, (int)$bands[2]->max_qty);
        $this->assertSame(60.0, (float)$bands[2]->wholesale_price);
    }

    /**
     * Opening the top band changes a range, never a price. The fixed and
     * percentage discounts have to come out the same either way, or "make this
     * tier open-ended" would quietly be a repricing.
     */
    public function test_opening_a_band_does_not_change_what_it_charges()
    {
        $product = $this->product();

        foreach ([['fixed', 80.0, 420.0], ['percentage', 10.0, 450.0]] as $case) {
            list($type, $value, $expected) = $case;

            $this->save($product->id, [
                ['min' => 1, 'max' => 20, 'wholesale_price' => 500, 'discount_type' => $type, 'discount_value' => $value],
                ['min' => 21, 'max' => 30, 'wholesale_price' => 500, 'discount_type' => $type, 'discount_value' => $value],
            ])->assertStatus(200);

            $bounded = (float)$this->bandsOf($product->id)[1]->final_price;

            $this->save($product->id, [
                ['min' => 1, 'max' => 20, 'wholesale_price' => 500, 'discount_type' => $type, 'discount_value' => $value],
                ['min' => 21, 'max' => 0, 'wholesale_price' => 500, 'discount_type' => $type, 'discount_value' => $value],
            ])->assertStatus(200);

            $open = (float)$this->bandsOf($product->id)[1]->final_price;

            $this->assertSame($expected, $bounded, "A bounded {$type} band charges the wrong price.");
            $this->assertSame($bounded, $open, "Opening a {$type} band changed what it charges.");
        }
    }

    public function test_a_minimum_below_one_is_refused()
    {
        $product = $this->product();

        $response = $this->save($product->id, [['min' => 0, 'max' => 5, 'wholesale_price' => 10]]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('minimum quantity', $response->json('message'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_a_maximum_below_the_minimum_is_refused()
    {
        $product = $this->product();

        $response = $this->save($product->id, [['min' => 10, 'max' => 5, 'wholesale_price' => 10]]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('maximum quantity', $response->json('message'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_a_negative_price_or_discount_is_refused()
    {
        $product = $this->product();

        $this->assertSame(201, $this->save($product->id, [
            ['min' => 1, 'max' => 0, 'wholesale_price' => -5],
        ])->json('status'));

        $this->assertSame(201, $this->save($product->id, [
            ['min' => 1, 'max' => 0, 'wholesale_price' => 10, 'discount_value' => -1],
        ])->json('status'));

        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_a_percentage_discount_over_one_hundred_is_refused()
    {
        $product = $this->product();

        $response = $this->save($product->id, [[
            'min' => 1, 'max' => 0,
            'wholesale_price' => 10,
            'discount_type' => 'percentage',
            'discount_value' => 120,
        ]]);

        $this->assertSame(201, $response->json('status'));
        $this->assertStringContainsString('percentage', $response->json('message'));
        $this->assertCount(0, $this->bandsOf($product->id));
    }

    /**
     * A refused ladder must not have taken the old one down with it: the
     * delete and the inserts share a transaction for exactly this reason.
     */
    public function test_a_refused_ladder_leaves_the_stored_one_standing()
    {
        $product = $this->product();

        $this->save($product->id, [['min' => 1, 'max' => 0, 'wholesale_price' => 50]])
            ->assertStatus(200);

        $this->save($product->id, [
            ['min' => 1, 'max' => 10, 'wholesale_price' => 40],
            ['min' => 5, 'max' => 20, 'wholesale_price' => 30],
        ]);

        $bands = $this->bandsOf($product->id);

        $this->assertCount(1, $bands);
        $this->assertSame(50.0, (float)$bands->first()->wholesale_price);
    }

    // ---- reading and removing ----------------------------------------------

    public function test_find_returns_a_products_ladder_in_quantity_order()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 11, 'max' => 0, 'wholesale_price' => 70],
            ['min' => 1, 'max' => 10, 'wholesale_price' => 90],
        ])->assertStatus(200);

        $this->admin();

        $response = $this->getJson("/api/admin/business-products/find/{$product->id}");

        $response->assertStatus(200);

        $pricing = $response->json('data.pricing');

        $this->assertCount(2, $pricing);
        $this->assertSame(1, $pricing[0]['min']);
        $this->assertSame(11, $pricing[1]['min']);
    }

    public function test_the_list_shows_one_row_per_product_with_its_band_count()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 1, 'max' => 5, 'wholesale_price' => 90],
            ['min' => 6, 'max' => 0, 'wholesale_price' => 80],
        ])->assertStatus(200);

        $this->admin();

        $response = $this->getJson('/api/admin/business-products/all');

        $response->assertStatus(200);

        $row = collect($response->json('data.data'))
            ->firstWhere('product_id', $product->id);

        $this->assertNotNull($row, 'The product with bands is missing from the list.');
        $this->assertSame(2, (int)$row['total_ranges']);
    }

    public function test_deleting_removes_every_band_on_the_product()
    {
        $product = $this->product();

        $this->save($product->id, [
            ['min' => 1, 'max' => 5, 'wholesale_price' => 90],
            ['min' => 6, 'max' => 0, 'wholesale_price' => 80],
        ])->assertStatus(200);

        $this->admin();

        $this->deleteJson("/api/admin/business-products/delete/{$product->id}")
            ->assertStatus(200);

        $this->assertCount(0, $this->bandsOf($product->id));
    }

    public function test_deleting_accepts_several_products_at_once()
    {
        $product = $this->product();
        $other = $this->otherProduct($product);

        $this->save($product->id, [['min' => 1, 'max' => 0, 'wholesale_price' => 50]])
            ->assertStatus(200);
        $this->save($other->id, [['min' => 1, 'max' => 0, 'wholesale_price' => 40]])
            ->assertStatus(200);

        $this->admin();

        $this->deleteJson("/api/admin/business-products/delete/{$product->id},{$other->id}")
            ->assertStatus(200);

        $this->assertCount(0, $this->bandsOf($product->id));
        $this->assertCount(0, $this->bandsOf($other->id));
    }

    // ---- the band the admin wrote is the one the shop quotes ----------------

    /**
     * A band never charges above the retail ladder, so the band has to be
     * priced off this product's own retail figure. Writing a flat 100 against
     * a product that sells for 10 quotes 10, and the test would be measuring
     * the cap rather than the band the admin wrote.
     */
    public function test_a_band_written_here_is_the_price_a_business_account_is_quoted()
    {
        $product = $this->product();

        $retail = Pricing::retailUnitPrice(Product::pricingRecord($product->id));

        if ($retail <= 0) {
            $this->markTestSkipped('No retail price to put a band under.');
        }

        $wholesale = Pricing::round($retail / 2);
        $expected = Pricing::round($wholesale * 0.8);

        $this->save($product->id, [[
            'min' => 1, 'max' => 0,
            'wholesale_price' => $wholesale,
            'discount_type' => 'percentage',
            'discount_value' => 20,
        ]])->assertStatus(200);

        $customer = User::create([
            'name' => 'Band Reader',
            'email' => 'band.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'business',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_PENDING,
        ])->fresh();

        // Drop the admin identity, or the storefront request still sees it.
        $this->app['auth']->forgetGuards();

        $response = $this->actingAs($customer, 'user')
            ->getJson("/api/v1/business-product/{$product->id}");

        $response->assertStatus(200);

        $quoted = (float)$response->json('data.pricing.0.final_price');

        $this->assertSame($expected, $quoted);
        // And it is the band talking, not the retail ladder showing through.
        $this->assertLessThan($retail, $quoted);
    }

    public function test_a_personal_account_is_quoted_none_of_it()
    {
        $product = $this->product();

        $this->save($product->id, [[
            'min' => 1, 'max' => 0, 'wholesale_price' => 100,
            'discount_type' => 'percentage', 'discount_value' => 20,
        ]])->assertStatus(200);

        $customer = User::create([
            'name' => 'Retail Reader',
            'email' => 'retail.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();

        $this->app['auth']->forgetGuards();

        $response = $this->actingAs($customer, 'user')
            ->getJson("/api/v1/business-product/{$product->id}");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.pricing'));
    }
}
