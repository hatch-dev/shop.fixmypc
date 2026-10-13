<?php

namespace Tests\Feature;

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * What a customer cannot do to their own account or their own prices.
 *
 * Account type, trade approval, verification and pricing are all worth money,
 * and the storefront is not where any of them are decided. Each test here
 * sends the request a customer would have to send to cheat, and checks the
 * server's answer rather than the browser's.
 */
class AccountSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Security Fixture',
            'email' => 'sec.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
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

    // ---- the account itself -------------------------------------------------

    /**
     * The profile endpoint updates a name, a phone number and an avatar. It
     * must not become a way to change what kind of customer you are.
     */
    public function test_a_customer_cannot_upgrade_their_own_account_type()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/user/update-profile', [
            'name' => 'Still Me',
            'phone' => $customer->phone,
            'account_type' => 'business',
            'trade_status' => User::TRADE_APPROVED,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_VERIFIED,
        ]);

        $fresh = $customer->fresh();

        $this->assertSame('personal', $fresh->account_type);
        $this->assertNull($fresh->trade_status);
        $this->assertSame(User::VERIFICATION_NOT_REQUIRED, $fresh->verification_status);
        $this->assertFalse($fresh->hasBusinessPricing());
    }

    public function test_a_customer_cannot_lift_their_own_suspension_through_the_profile()
    {
        $customer = $this->customer([
            'account_type' => 'business',
            'status' => User::STATUS_SUSPENDED,
        ]);

        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/user/update-profile', [
            'name' => 'Still Me',
            'phone' => $customer->phone,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertSame(User::STATUS_SUSPENDED, $customer->fresh()->status);
        $this->assertFalse($customer->fresh()->canCheckout());
    }

    // ---- prices -------------------------------------------------------------

    /**
     * The basket takes a product, a variation and a quantity. The price is the
     * server's to decide, and a price in the request is ignored.
     */
    public function test_a_price_sent_with_an_add_to_basket_is_ignored()
    {
        $product = $this->bandedProduct();
        $inventory = UpdatedInventory::where('product_id', $product->id)->first();
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
            // What an attacker would send.
            'price' => 0.01,
            'original_price' => 0.01,
        ]);

        $line = Cart::where('user_id', $customer->id)->first();

        $this->assertNotNull($line);
        $this->assertSame(round((float)$product->selling, 2), round((float)$line->price, 2));
    }

    /**
     * Reading the basket re-prices it, so a row edited directly cannot survive
     * to checkout either.
     */
    public function test_a_tampered_basket_row_is_re_priced_on_read()
    {
        $product = $this->bandedProduct();
        $inventory = UpdatedInventory::where('product_id', $product->id)->first();
        $customer = $this->customer();

        $cart = Cart::create([
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'user_id' => $customer->id,
            'quantity' => 1,
            'selected' => 1,
            'shipping_type' => 1,
            'price' => 0.01,
            'original_price' => 0.01,
        ]);

        Passport::actingAs($customer, ['user'], 'user');

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('id', $cart->id);

        $this->assertSame(round((float)$product->selling, 2), (float)$line['unit_price']);
        $this->assertSame(round((float)$product->selling, 2), round((float)$cart->fresh()->price, 2));
    }

    /**
     * A personal account asking for a business price by quantity gets the
     * retail price, because the bands are never loaded for it at all.
     */
    public function test_a_personal_account_cannot_reach_band_pricing_by_quantity()
    {
        $product = $this->bandedProduct();
        $inventory = UpdatedInventory::where('product_id', $product->id)->first();
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 50,
        ]);

        $line = Cart::where('user_id', $customer->id)->first();

        $this->assertSame(round((float)$product->selling, 2), round((float)$line->price, 2));
    }

    public function test_a_business_account_does_reach_band_pricing_by_quantity()
    {
        $product = $this->bandedProduct();
        $inventory = UpdatedInventory::where('product_id', $product->id)->first();
        $customer = $this->customer(['account_type' => 'business']);

        Passport::actingAs($customer, ['user'], 'user');

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 5,
        ]);

        $line = Cart::where('user_id', $customer->id)->first();

        $this->assertSame(round((float)$product->selling * 0.5, 2), round((float)$line->price, 2));
    }
}
