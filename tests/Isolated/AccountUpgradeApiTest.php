<?php

namespace Tests\Isolated;

use App\Models\AccountUpgradeRequest;
use App\Models\Cart;
use App\Models\User;
use App\Models\UserBusinessProfile;
use Database\Seeders\Testing\Fixture;
use Tests\IsolatedTestCase;

/**
 * GET/POST /api/v1/user/account-upgrade and /withdraw: the customer's side of
 * changing their own account type. The rules themselves are pinned in
 * AccountUpgradeTest; these pin what the endpoints add - the form, the
 * password, what a hand-made request cannot smuggle in, and that the price in
 * the basket actually follows the change.
 */
class AccountUpgradeApiTest extends IsolatedTestCase
{
    private function upgrade($userId, array $body)
    {
        return $this->asCustomer($userId)->postJson('/api/v1/user/account-upgrade', $body);
    }

    private function businessBody($overrides = [])
    {
        return array_merge([
            'to_type' => 'business',
            'password' => Fixture::PASSWORD,
            'company_name' => 'Api Upgrade Ltd',
            'vat_number' => 'IE9876543W',
            'billing_address' => '4 Api Street, Limerick',
            'sector' => 'Education',
            'requires_po' => false,
        ], $overrides);
    }

    private function tradeBody($overrides = [])
    {
        return array_merge([
            'to_type' => 'trade',
            'password' => Fixture::PASSWORD,
            'company_name' => 'Api Trade Ltd',
            'vat_number' => 'IE5555555X',
            'billing_address' => '5 Api Lane, Sligo',
            'trade' => 'Computer repair shop',
            'expected_spend' => '1000-5000',
            'resale_confirmed' => true,
        ], $overrides);
    }

    private function fieldErrors($response)
    {
        return array_keys((array)$response->json('data.fields'));
    }

    // ---- reading the state ------------------------------------------------

    public function test_a_guest_cannot_read_or_submit()
    {
        $this->asGuest();

        $this->assertNotSame(200, (int)$this->getJson('/api/v1/user/account-upgrade')->json('status'));
        $this->assertNotSame(200, (int)$this->postJson('/api/v1/user/account-upgrade', $this->businessBody())->json('status'));
    }

    public function test_a_personal_customer_is_offered_both_upgrades()
    {
        $response = $this->asCustomer(Fixture::USER_PERSONAL_ID)->getJson('/api/v1/user/account-upgrade');

        $this->assertApiOk($response);
        $this->assertSame(['business', 'trade'], $response->json('data.options'));
        $this->assertNull($response->json('data.pending'));
        $this->assertSame('personal', $response->json('data.account.account_type'));
    }

    public function test_a_trade_customer_is_offered_nothing()
    {
        $response = $this->asCustomer(Fixture::USER_TRADE_APPROVED_ID)->getJson('/api/v1/user/account-upgrade');

        $this->assertApiOk($response);
        $this->assertSame([], $response->json('data.options'));
    }

    // ---- personal -> business ---------------------------------------------

    public function test_upgrading_to_business_answers_with_the_new_account()
    {
        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, $this->businessBody());

        $this->assertApiOk($response);
        $this->assertSame('business', $response->json('data.account.account_type'));
        $this->assertTrue($response->json('data.account.has_business_pricing'));
        $this->assertSame('pending', $response->json('data.account.verification_status'));
        $this->assertSame('Api Upgrade Ltd', $response->json('data.business_profile.company_name'));
        $this->assertSame(['trade'], $response->json('data.options'));
    }

    public function test_the_basket_is_repriced_at_the_band_after_upgrading()
    {
        $customer = $this->asCustomer(Fixture::USER_PERSONAL_ID);
        $this->assertApiOk($customer->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_LAPTOP_ID,
            'inventory_id' => Fixture::LAPTOP_INVENTORY_ID,
            'quantity' => 10,
        ]));
        $this->assertApiOk($this->asCustomer(Fixture::USER_PERSONAL_ID)->getJson('/api/v1/cart/by-user'));
        $retail = (float)Cart::where('user_id', Fixture::USER_PERSONAL_ID)->value('price');

        $this->assertApiOk($this->upgrade(Fixture::USER_PERSONAL_ID, $this->businessBody()));
        $this->assertApiOk($this->asCustomer(Fixture::USER_PERSONAL_ID)->getJson('/api/v1/cart/by-user'));
        $band = (float)Cart::where('user_id', Fixture::USER_PERSONAL_ID)->value('price');

        $this->assertEquals(1000.0, $retail);
        $this->assertEquals(900.0, $band, 'the 10-49 band, on the next pass, with nothing re-added');
    }

    public function test_a_wrong_password_is_refused_against_the_password_field()
    {
        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, $this->businessBody(['password' => 'Not-The-Password-1']));

        $this->assertApiRefused($response, 201, 'password');
        $this->assertSame(['password'], $this->fieldErrors($response));
        $this->assertSame('personal', User::find(Fixture::USER_PERSONAL_ID)->account_type);
    }

    public function test_the_business_answers_are_required()
    {
        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, $this->businessBody([
            'company_name' => '', 'sector' => '', 'billing_address' => '', 'password' => '',
        ]));

        $this->assertApiRefused($response, 201);
        $this->assertEqualsCanonicalizing(['company_name', 'sector', 'billing_address', 'password'], $this->fieldErrors($response));
        $this->assertSame(0, AccountUpgradeRequest::count());
    }

    public function test_a_malformed_vat_number_is_refused_as_at_signup()
    {
        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, $this->businessBody(['vat_number' => 'will send later']));

        $this->assertApiRefused($response, 201);
        $this->assertSame(['vat_number'], $this->fieldErrors($response));
    }

    public function test_a_vat_number_registered_to_another_account_is_refused()
    {
        $taken = UserBusinessProfile::where('user_id', Fixture::USER_BUSINESS_ID)->value('vat_number');

        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, $this->businessBody(['vat_number' => $taken]));

        $this->assertApiRefused($response, 201);
        $this->assertSame(['vat_number'], $this->fieldErrors($response));
        $this->assertStringContainsString('already registered', implode(' ', (array)$response->json('data.form')));
    }

    public function test_staff_decisions_cannot_be_smuggled_into_the_request()
    {
        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, $this->businessBody([
            'account_type' => 'trade',
            'trade_status' => 'approved',
            'verification_status' => 'verified',
            'status' => 'active',
            'verified' => 1,
        ]));

        $this->assertApiOk($response);
        $user = User::find(Fixture::USER_PERSONAL_ID);
        $this->assertSame('business', $user->account_type);
        $this->assertNull($user->trade_status);
        $this->assertSame('pending', $user->verification_status);
    }

    public function test_trade_to_business_is_refused_over_the_api_too()
    {
        $response = $this->upgrade(Fixture::USER_TRADE_APPROVED_ID, $this->businessBody());

        $this->assertApiRefused($response, 201, 'contact support');
        $this->assertSame('trade', User::find(Fixture::USER_TRADE_APPROVED_ID)->account_type);
    }

    public function test_an_unknown_type_is_refused_before_the_form_is_checked()
    {
        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, ['to_type' => 'platinum']);

        $this->assertApiRefused($response, 201, 'contact support');
        $this->assertSame([], $this->fieldErrors($response));
    }

    // ---- applying for trade -----------------------------------------------

    public function test_a_business_customer_applies_for_trade_and_keeps_business_pricing()
    {
        $response = $this->upgrade(Fixture::USER_BUSINESS_ID, $this->tradeBody());

        $this->assertApiOk($response);
        $this->assertSame('business', $response->json('data.account.account_type'));
        $this->assertTrue($response->json('data.account.has_business_pricing'));
        $this->assertSame('pending', $response->json('data.pending.status'));
        $this->assertSame('trade', $response->json('data.pending.to_type'));
        $this->assertSame('Api Trade Ltd', $response->json('data.pending.details.company_name'));
        $this->assertSame([], $response->json('data.options'));
    }

    public function test_a_business_customer_may_keep_their_own_vat_number()
    {
        $own = UserBusinessProfile::where('user_id', Fixture::USER_BUSINESS_ID)->value('vat_number');

        $response = $this->upgrade(Fixture::USER_BUSINESS_ID, $this->tradeBody(['vat_number' => $own]));

        $this->assertApiOk($response);
    }

    public function test_the_trade_answers_are_required()
    {
        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, $this->tradeBody([
            'trade' => '', 'expected_spend' => '', 'vat_number' => '', 'resale_confirmed' => false,
        ]));

        $this->assertApiRefused($response, 201);
        $this->assertEqualsCanonicalizing(['trade', 'expected_spend', 'vat_number', 'resale_confirmed'], $this->fieldErrors($response));
    }

    public function test_a_second_application_is_refused_while_one_waits()
    {
        $this->assertApiOk($this->upgrade(Fixture::USER_PERSONAL_ID, $this->tradeBody()));

        $this->assertApiRefused($this->upgrade(Fixture::USER_PERSONAL_ID, $this->tradeBody()), 201, 'already have an application');
        $this->assertApiRefused($this->upgrade(Fixture::USER_PERSONAL_ID, $this->businessBody()), 201, 'already have an application');
    }

    public function test_withdrawing_clears_the_application_and_restores_the_options()
    {
        $this->assertApiOk($this->upgrade(Fixture::USER_PERSONAL_ID, $this->tradeBody()));

        $response = $this->asCustomer(Fixture::USER_PERSONAL_ID)->postJson('/api/v1/user/account-upgrade/withdraw');

        $this->assertApiOk($response);
        $this->assertNull($response->json('data.pending'));
        $this->assertSame(['business', 'trade'], $response->json('data.options'));
    }

    public function test_withdrawing_with_nothing_waiting_is_refused()
    {
        $response = $this->asCustomer(Fixture::USER_PERSONAL_ID)->postJson('/api/v1/user/account-upgrade/withdraw');

        $this->assertApiRefused($response, 201, 'no application');
    }

    public function test_a_rejection_and_its_note_are_shown_to_the_customer()
    {
        $this->upgrade(Fixture::USER_PERSONAL_ID, $this->tradeBody());
        $upgrade = AccountUpgradeRequest::where('user_id', Fixture::USER_PERSONAL_ID)->first();
        \App\Models\Helper\AccountUpgrade::decide($upgrade, 'rejected', 'We could not find that VAT number.', Fixture::ADMIN_SUPER_ID);

        $response = $this->asCustomer(Fixture::USER_PERSONAL_ID)->getJson('/api/v1/user/account-upgrade');

        $this->assertSame('rejected', $response->json('data.last_decision.status'));
        $this->assertSame('We could not find that VAT number.', $response->json('data.last_decision.review_note'));
        $this->assertArrayNotHasKey('details', (array)$response->json('data.last_decision'));
        $this->assertSame(['business', 'trade'], $response->json('data.options'));
    }
    // ---- the PO rule after business -> trade ------------------------------

    /**
     * A business approved for trade keeps its PO rule, and checkout still asks
     * for a PO on any non-personal account - so the trade customer must be
     * able to switch it off. The profile endpoint used to accept requires_po
     * from business accounts only.
     */
    public function test_a_trade_account_can_switch_the_po_rule_off()
    {
        \App\Models\UserBusinessProfile::where("user_id", Fixture::USER_TRADE_APPROVED_ID)->update(["requires_po" => 1]);

        $response = $this->asCustomer(Fixture::USER_TRADE_APPROVED_ID)->postJson("/api/v1/user/update-profile", [
            "name" => "Tom Trade",
            "requires_po" => 0,
        ]);

        $this->assertApiOk($response);
        $this->assertFalse((bool)UserBusinessProfile::where("user_id", Fixture::USER_TRADE_APPROVED_ID)->value("requires_po"));
    }

    public function test_a_trade_account_still_cannot_apply_for_credit_from_the_profile()
    {
        $this->asCustomer(Fixture::USER_TRADE_APPROVED_ID)->postJson("/api/v1/user/update-profile", [
            "name" => "Tom Trade",
            "credit_application" => 1,
        ]);

        $this->assertFalse((bool)UserBusinessProfile::where("user_id", Fixture::USER_TRADE_APPROVED_ID)->value("credit_application"));
    }
}
