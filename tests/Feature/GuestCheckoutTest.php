<?php

namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Guest checkout, and the basket it hands over at sign-in.
 *
 * Guests are the account type that existed before any of this, so the tests
 * here are mostly about what must NOT have changed: a guest still shops, still
 * pays retail, and still cannot reach anything a business or trade account is
 * entitled to.
 */
class GuestCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private function guestToken()
    {
        $token = (string)Str::uuid();

        GuestUser::create(['user_token' => $token]);

        return $token;
    }

    private function bandedProduct()
    {
        $product = Product::where('status', 1)
            ->whereNotNull('selling')->where('selling', '>', 0)
            ->whereIn('id', function ($query) {
                $query->select('product_id')->from('updated_inventories');
            })->first();

        if (!$product) {
            $this->markTestSkipped('No published product with inventory to price against.');
        }

        BusinessProductPricing::create([
            'product_id' => $product->id,
            'min_qty' => 1,
            'max_qty' => 0,
            'wholesale_price' => round($product->selling * 0.5, 2),
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'final_price' => round($product->selling * 0.5, 2),
        ]);

        return $product;
    }

    private function inventoryFor($product)
    {
        return UpdatedInventory::where('product_id', $product->id)->first();
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Guest Fixture',
            'email' => 'guest.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    // ---- the guest basket still works ---------------------------------------

    public function test_a_guest_can_build_a_basket_and_is_charged_retail()
    {
        $product = $this->bandedProduct();
        $token = $this->guestToken();

        Cart::create([
            'product_id' => $product->id,
            'inventory_id' => $this->inventoryFor($product)->id,
            'user_token' => $token,
            'quantity' => 3,
            'selected' => 1,
            'shipping_type' => 1,
            'price' => $product->selling,
            'original_price' => $product->selling,
        ]);

        $response = $this->getJson('/api/v1/cart/by-user?user_token=' . $token);

        $response->assertStatus(200);

        $line = collect($response->json('data'))->first();

        $this->assertNotNull($line);
        // A band exists on this product; a guest is entitled to none of it.
        $this->assertSame(round((float)$product->selling, 2), (float)$line['unit_price']);
    }

    /**
     * The basket a guest built is priced at retail. Merged onto an entitled
     * account at sign-in it has to be re-priced, or the customer keeps paying
     * retail on everything they put in the basket before they logged in.
     */
    public function test_a_guest_basket_re_prices_when_it_lands_on_a_business_account()
    {
        $product = $this->bandedProduct();
        $token = $this->guestToken();
        $customer = $this->customer(['account_type' => 'business']);

        $cart = Cart::create([
            'product_id' => $product->id,
            'inventory_id' => $this->inventoryFor($product)->id,
            'user_token' => $token,
            'quantity' => 2,
            'selected' => 1,
            'shipping_type' => 1,
            // The retail figure the line was built with as a guest.
            'price' => $product->selling,
            'original_price' => $product->selling,
        ]);

        // What sign-in does to a guest basket.
        $cart->update(['user_id' => $customer->id, 'user_token' => null]);

        Passport::actingAs($customer, ['user'], 'user');

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('id', $cart->id);

        $this->assertSame(round((float)$product->selling * 0.5, 2), (float)$line['unit_price']);
        // Written back, so carts.price is a record rather than a fossil.
        $this->assertSame(round((float)$product->selling * 0.5, 2), round((float)$cart->fresh()->price, 2));
    }

    public function test_a_guest_basket_landing_on_a_personal_account_stays_at_retail()
    {
        $product = $this->bandedProduct();
        $customer = $this->customer();

        $cart = Cart::create([
            'product_id' => $product->id,
            'inventory_id' => $this->inventoryFor($product)->id,
            'user_id' => $customer->id,
            'quantity' => 2,
            'selected' => 1,
            'shipping_type' => 1,
            'price' => $product->selling,
            'original_price' => $product->selling,
        ]);

        Passport::actingAs($customer, ['user'], 'user');

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('id', $cart->id);

        $this->assertSame(round((float)$product->selling, 2), (float)$line['unit_price']);
    }

    // ---- a guest reaches nothing an account is entitled to --------------------

    public function test_a_guest_is_offered_no_quantity_bands()
    {
        $product = $this->bandedProduct();

        $this->getJson("/api/v1/business-product/{$product->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.pricing', []);
    }

    public function test_a_guest_profile_reports_no_pricing_entitlement()
    {
        $token = $this->guestToken();

        $account = $this->getJson('/api/v1/user/profile?user_token=' . $token)
            ->json('data.account');

        $this->assertNotNull($account);
        $this->assertSame('personal', $account['account_type']);
        $this->assertFalse($account['has_business_pricing']);
        // A guest can still buy; that is the whole point of guest checkout.
        $this->assertTrue($account['can_checkout']);
    }

    public function test_a_forged_guest_token_still_reaches_nothing()
    {
        $response = $this->getJson('/api/v1/cart/by-user?user_token=' . Str::uuid());

        $this->assertNotSame(200, $response->json('status'));
    }
}
