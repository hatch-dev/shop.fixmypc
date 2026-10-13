<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Helper\AccountStatus;
use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The trade application from end to end, driven through the admin API by an
 * admin who actually holds the permission, and what each decision does to the
 * customer's pricing and basket.
 *
 * The unit tests cover the rules; this covers the wiring — that approving in
 * the admin screen is what turns band pricing on in the shop, and that
 * withdrawing it turns it off again everywhere, including in a basket that was
 * priced while the entitlement stood.
 */
class TradeLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        $admin = Admin::with('roles')->whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        /*
         * The admin routes sit behind auth:admin AND scope:admin, so a plain
         * actingAs() is not enough: Passport refuses the request for want of a
         * scope and the controller is never reached. Getting this wrong is
         * quiet — every call comes back "Unauthenticated" with a 200-shaped
         * body, which reads like a refusal the test was expecting.
         */
        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Trade Fixture',
            'email' => 'trade.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_PENDING,
        ], $attributes))->fresh();
    }

    /** A product carrying a band that halves its price from the first unit. */
    private function bandedProduct()
    {
        /*
         * carts.inventory_id is a foreign key onto updated_inventories, so a
         * product with no variation row cannot be put in a basket at all.
         */
        $product = Product::where('status', 1)
            ->whereNotNull('selling')->where('selling', '>', 0)
            ->whereIn('id', function ($query) {
                $query->select('product_id')->from('updated_inventories');
            })
            ->first();

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

    private function review($customer, $status, $note = null)
    {
        $this->admin();

        return $this->postJson("/api/admin/trade-application/action/{$customer->id}", [
                'trade_status' => $status,
                'trade_review_note' => $note,
            ]);
    }

    private function setAccountStatus($customer, $status)
    {
        $this->admin();

        return $this->postJson("/api/admin/user/status/{$customer->id}", [
                'status' => $status,
            ]);
    }

    // ---- the review cycle ---------------------------------------------------

    public function test_approving_an_application_grants_pricing_and_records_the_check()
    {
        $customer = $this->customer();

        $this->review($customer, User::TRADE_APPROVED, 'Certificate checked.')
            ->assertStatus(200);

        $fresh = $customer->fresh();

        $this->assertSame(User::TRADE_APPROVED, $fresh->trade_status);
        $this->assertTrue($fresh->hasTradePricing());
        $this->assertTrue($fresh->hasBusinessPricing());
        // Approving the application is the act of verifying the details.
        $this->assertSame(User::VERIFICATION_VERIFIED, $fresh->verification_status);
        $this->assertSame('Certificate checked.', $fresh->trade_review_note);
        $this->assertNotNull($fresh->trade_reviewed_at);
    }

    public function test_rejecting_an_application_withholds_pricing()
    {
        $customer = $this->customer();

        $this->review($customer, User::TRADE_REJECTED, 'No VAT registration found.')
            ->assertStatus(200);

        $fresh = $customer->fresh();

        $this->assertSame(User::TRADE_REJECTED, $fresh->trade_status);
        $this->assertFalse($fresh->hasBusinessPricing());
        $this->assertSame(User::VERIFICATION_REJECTED, $fresh->verification_status);
        // Rejected for trade rates, but still a customer who may buy at retail.
        $this->assertTrue($fresh->canCheckout());
    }

    public function test_an_approved_application_can_be_suspended_and_reinstated()
    {
        $customer = $this->customer();

        $this->review($customer, User::TRADE_APPROVED)->assertStatus(200);
        $this->assertTrue($customer->fresh()->hasBusinessPricing());

        $this->review($customer, User::TRADE_SUSPENDED, 'Account on hold.')->assertStatus(200);

        $suspended = $customer->fresh();
        $this->assertSame(User::TRADE_SUSPENDED, $suspended->trade_status);
        $this->assertFalse($suspended->hasBusinessPricing());
        // The application still stands, so the account is still usable.
        $this->assertTrue($suspended->canCheckout());

        $this->review($customer, User::TRADE_APPROVED, 'Reinstated.')->assertStatus(200);
        $this->assertTrue($customer->fresh()->hasBusinessPricing());
    }

    /**
     * Suspending an application nobody has approved is not a state the pricing
     * code knows how to answer for, and the API says so rather than storing it.
     */
    public function test_a_pending_application_cannot_be_suspended()
    {
        $customer = $this->customer();

        $response = $this->review($customer, User::TRADE_SUSPENDED);

        $this->assertSame(201, $response->json('status'));
        $this->assertSame(User::TRADE_PENDING, $customer->fresh()->trade_status);
    }

    public function test_an_unknown_trade_status_is_refused()
    {
        $customer = $this->customer();

        $response = $this->review($customer, 'platinum');

        $this->assertSame(201, $response->json('status'));
        $this->assertSame(User::TRADE_PENDING, $customer->fresh()->trade_status);
    }

    // ---- account suspension, which is a different axis -----------------------

    public function test_suspending_the_account_removes_pricing_without_touching_the_application()
    {
        $customer = $this->customer();

        $this->review($customer, User::TRADE_APPROVED)->assertStatus(200);
        $this->setAccountStatus($customer, User::STATUS_SUSPENDED)->assertStatus(200);

        $suspended = $customer->fresh();

        $this->assertFalse($suspended->hasBusinessPricing());
        $this->assertFalse($suspended->canCheckout());
        // The trade decision is untouched, so reactivating restores it whole.
        $this->assertSame(User::TRADE_APPROVED, $suspended->trade_status);

        $this->setAccountStatus($customer, User::STATUS_ACTIVE)->assertStatus(200);

        $restored = $customer->fresh();

        $this->assertTrue($restored->hasBusinessPricing());
        $this->assertTrue($restored->canCheckout());
    }

    public function test_an_invalid_account_status_transition_is_refused()
    {
        $customer = $this->customer();

        // active -> rejected is not a move the ladder allows.
        $response = $this->setAccountStatus($customer, User::STATUS_REJECTED);

        $this->assertSame(201, $response->json('status'));
        $this->assertSame(User::STATUS_ACTIVE, $customer->fresh()->status);
    }

    // ---- the basket follows the entitlement ----------------------------------

    /**
     * The unit price was banked in carts.price when the line was written and
     * nothing revisited it, so a basket built before approval kept its retail
     * prices afterwards — and a basket built before suspension kept its trade
     * prices. Reading the basket re-prices it.
     */
    private function basketPrice($customer, $product, $quantity = 1)
    {
        $inventory = \App\Models\UpdatedInventory::where('product_id', $product->id)->first();

        $cart = Cart::create([
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'user_id' => $customer->id,
            'quantity' => $quantity,
            'selected' => 1,
            'shipping_type' => 1,
            'price' => $product->selling,
            'original_price' => $product->selling,
        ]);

        Passport::actingAs($customer->fresh(), ['user'], 'user');

        $response = $this->getJson('/api/v1/cart/by-user');

        $response->assertStatus(200);

        $line = collect($response->json('data'))
            ->firstWhere('id', $cart->id);

        return $line ? (float)$line['unit_price'] : null;
    }

    public function test_a_basket_is_priced_at_retail_while_the_application_is_pending()
    {
        $product = $this->bandedProduct();
        $customer = $this->customer();

        $this->assertSame(
            round((float)$product->selling, 2),
            $this->basketPrice($customer, $product)
        );
    }

    public function test_a_basket_re_prices_to_trade_rates_once_the_application_is_approved()
    {
        $product = $this->bandedProduct();
        $customer = $this->customer();

        AccountStatus::applyTrade($customer, User::TRADE_APPROVED);

        $this->assertSame(
            round((float)$product->selling * 0.5, 2),
            $this->basketPrice($customer, $product)
        );
    }

    /**
     * The one that matters most: a stale trade price must not survive in a
     * basket after the entitlement is gone, because checkout will charge
     * retail and the two figures have to agree.
     */
    public function test_a_basket_loses_trade_rates_when_the_account_is_suspended()
    {
        $product = $this->bandedProduct();
        $customer = $this->customer();

        AccountStatus::applyTrade($customer, User::TRADE_APPROVED);
        AccountStatus::applyAccount($customer, User::STATUS_SUSPENDED, 'On hold.');

        $this->assertSame(
            round((float)$product->selling, 2),
            $this->basketPrice($customer, $product)
        );
    }

    public function test_a_basket_loses_trade_rates_when_trade_status_is_withdrawn()
    {
        $product = $this->bandedProduct();
        $customer = $this->customer();

        AccountStatus::applyTrade($customer, User::TRADE_APPROVED);
        AccountStatus::applyTrade($customer, User::TRADE_SUSPENDED, 'Withdrawn.');

        $this->assertSame(
            round((float)$product->selling, 2),
            $this->basketPrice($customer, $product)
        );
    }

    /**
     * A guest basket merged onto an entitled account at sign-in was built at
     * retail prices and kept them.
     */
    public function test_a_personal_account_basket_stays_at_retail()
    {
        $product = $this->bandedProduct();

        $customer = $this->customer([
            'account_type' => 'personal',
            'trade_status' => null,
            'verification_status' => User::VERIFICATION_NOT_REQUIRED,
        ]);

        $this->assertSame(
            round((float)$product->selling, 2),
            $this->basketPrice($customer, $product)
        );
    }

    // ---- what the admin screens can see and do ------------------------------

    /**
     * The customer list showed an account's name, email and whether the email
     * was confirmed, and nothing about what kind of customer it was. Approving
     * a trade application without being able to read the application is not a
     * workflow.
     */
    public function test_the_admin_customer_record_carries_the_account_state()
    {
        $customer = $this->customer();

        \App\Models\UserBusinessProfile::create([
            'user_id' => $customer->id,
            'company_name' => 'Sparks Electrical',
            'vat_number' => 'IE1122334D',
            'trade' => 'Electrician',
        ]);

        $this->admin();

        $response = $this->getJson("/api/admin/user/find/{$customer->id}");

        $response->assertStatus(200);

        $account = $response->json('data.account');

        $this->assertSame('trade', $account['account_type']);
        $this->assertSame('active', $account['status']);
        $this->assertSame('pending', $account['trade_status']);
        $this->assertFalse($account['has_business_pricing']);
        // The application itself, so a decision can be made from this screen.
        $this->assertSame('Sparks Electrical', $response->json('data.business_profile.company_name'));
        $this->assertSame('IE1122334D', $response->json('data.business_profile.vat_number'));
    }

    public function test_the_admin_customer_list_carries_the_account_state()
    {
        $this->customer();
        $this->admin();

        $response = $this->getJson('/api/admin/user/all?account_type=trade&limit=5');

        $response->assertStatus(200);

        $rows = collect($response->json('data.data'));

        $this->assertTrue($rows->isNotEmpty());
        $this->assertNotNull($rows->first()['account']);
        $this->assertSame('trade', $rows->first()['account']['account_type']);
    }

    public function test_the_review_queue_can_be_filtered_by_every_trade_status()
    {
        $customer = $this->customer();

        AccountStatus::applyTrade($customer, User::TRADE_APPROVED);
        AccountStatus::applyTrade($customer, User::TRADE_SUSPENDED);

        $this->admin();

        $ids = collect($this->getJson('/api/admin/trade-application/all?status=suspended&limit=100')
            ->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($customer->id));

        // And is no longer in the queue of applications waiting for a decision.
        $pending = collect($this->getJson('/api/admin/trade-application/all?status=pending&limit=100')
            ->json('data.data'))->pluck('id');

        $this->assertFalse($pending->contains($customer->id));
    }

    /**
     * Business accounts have no application to approve — they buy at business
     * rates from day one — so this is the only place their company details can
     * be marked as checked.
     */
    public function test_a_business_account_can_be_marked_verified()
    {
        $customer = $this->customer([
            'account_type' => 'business',
            'trade_status' => null,
            'verification_status' => User::VERIFICATION_PENDING,
        ]);

        $this->admin();

        $this->postJson("/api/admin/user/verification/{$customer->id}", [
            'verification_status' => User::VERIFICATION_VERIFIED,
        ])->assertStatus(200);

        $this->assertSame(User::VERIFICATION_VERIFIED, $customer->fresh()->verification_status);
        // Verification is a record of a check, not an entitlement switch:
        // the account already had business pricing and still does.
        $this->assertTrue($customer->fresh()->hasBusinessPricing());
    }

    public function test_a_personal_account_has_nothing_to_verify()
    {
        $customer = $this->customer([
            'account_type' => 'personal',
            'trade_status' => null,
            'verification_status' => User::VERIFICATION_NOT_REQUIRED,
        ]);

        $this->admin();

        $response = $this->postJson("/api/admin/user/verification/{$customer->id}", [
            'verification_status' => User::VERIFICATION_VERIFIED,
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertSame(User::VERIFICATION_NOT_REQUIRED, $customer->fresh()->verification_status);
    }

    public function test_an_unknown_verification_status_is_refused()
    {
        $customer = $this->customer();

        $this->admin();

        $response = $this->postJson("/api/admin/user/verification/{$customer->id}", [
            'verification_status' => 'gold',
        ]);

        $this->assertSame(201, $response->json('status'));
        $this->assertSame(User::VERIFICATION_PENDING, $customer->fresh()->verification_status);
    }
}
