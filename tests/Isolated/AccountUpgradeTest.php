<?php

namespace Tests\Isolated;

use App\Models\AccountUpgradeRequest;
use App\Models\Helper\AccountUpgrade;
use App\Models\User;
use App\Models\UserBusinessProfile;
use Database\Seeders\Testing\Fixture;
use Tests\IsolatedTestCase;

/**
 * The rules of a customer upgrading their own account type
 * (App\Models\Helper\AccountUpgrade), exercised below the HTTP layer.
 *
 * The two properties everything else rests on:
 *  - business is immediate, trade is an application that changes nothing
 *    until staff approve it;
 *  - a business customer applying for trade keeps business pricing the whole
 *    time, and a rejection leaves their verified details untouched.
 */
class AccountUpgradeTest extends IsolatedTestCase
{
    private function user($id)
    {
        return User::findOrFail($id);
    }

    private function businessDetails($overrides = [])
    {
        return array_merge([
            'company_name' => 'Upgrade Fixture Ltd',
            'vat_number' => 'IE1234567T',
            'cro_number' => '654321',
            'billing_address' => '2 Upgrade Road, Cork',
            'sector' => 'IT services',
            'requires_po' => '1',
            'credit_application' => '0',
        ], $overrides);
    }

    private function tradeDetails($overrides = [])
    {
        return array_merge([
            'company_name' => 'Trade Fixture Ltd',
            'vat_number' => 'IE7654321T',
            'cro_number' => '111222',
            'billing_address' => '3 Trade Lane, Galway',
            'trade' => 'Computer repair shop',
            'expected_spend' => '1000-5000',
            'website' => 'https://trade.example.test',
            'resale_confirmed' => true,
            'pref_trade_list' => true,
        ], $overrides);
    }

    // ---- what is on offer -------------------------------------------------

    public function test_each_type_is_offered_only_the_upgrades_the_rules_allow()
    {
        $this->assertSame(['business', 'trade'], AccountUpgrade::options($this->user(Fixture::USER_PERSONAL_ID)));
        $this->assertSame(['trade'], AccountUpgrade::options($this->user(Fixture::USER_BUSINESS_ID)));
        $this->assertSame([], AccountUpgrade::options($this->user(Fixture::USER_TRADE_APPROVED_ID)));
        $this->assertSame([], AccountUpgrade::options($this->user(Fixture::USER_TRADE_PENDING_ID)));
    }

    public function test_suspended_and_unconfirmed_accounts_are_offered_nothing()
    {
        $this->assertSame([], AccountUpgrade::options($this->user(Fixture::USER_SUSPENDED_ID)));
        $this->assertSame([], AccountUpgrade::options($this->user(Fixture::USER_UNVERIFIED_ID)));
    }

    public function test_downgrades_and_trade_to_business_are_refused()
    {
        $trade = $this->user(Fixture::USER_TRADE_APPROVED_ID);
        $business = $this->user(Fixture::USER_BUSINESS_ID);

        $this->assertSame(AccountUpgrade::NOT_ALLOWED_MESSAGE, AccountUpgrade::eligibilityError($trade, 'business'));
        $this->assertSame(AccountUpgrade::NOT_ALLOWED_MESSAGE, AccountUpgrade::eligibilityError($trade, 'personal'));
        $this->assertSame(AccountUpgrade::NOT_ALLOWED_MESSAGE, AccountUpgrade::eligibilityError($business, 'personal'));
        $this->assertSame(AccountUpgrade::NOT_ALLOWED_MESSAGE, AccountUpgrade::eligibilityError($business, 'business'));
        $this->assertSame(AccountUpgrade::NOT_ALLOWED_MESSAGE, AccountUpgrade::eligibilityError($business, 'nonsense'));
    }

    // ---- personal -> business ---------------------------------------------

    public function test_personal_to_business_is_immediate_and_switches_band_pricing_on()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);
        $this->assertFalse($user->hasBusinessPricing());

        $error = AccountUpgrade::upgradeToBusiness($user, $this->businessDetails(), $upgrade);

        $this->assertNull($error);
        $fresh = $user->fresh();
        $this->assertSame('business', $fresh->account_type);
        $this->assertSame(User::VERIFICATION_PENDING, $fresh->verification_status);
        $this->assertTrue($fresh->hasBusinessPricing());
        // The model handed in is brought up to date as well.
        $this->assertSame('business', $user->account_type);

        $this->assertSame(AccountUpgradeRequest::STATUS_COMPLETED, $upgrade->status);
        $this->assertSame('personal', $upgrade->from_type);

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();
        $this->assertSame('Upgrade Fixture Ltd', $profile->company_name);
        $this->assertSame('IT services', $profile->sector);
        $this->assertTrue($profile->requires_po);
        $this->assertFalse($profile->credit_application);
    }

    public function test_a_business_upgrade_stores_no_trade_answers()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);

        AccountUpgrade::upgradeToBusiness($user, $this->businessDetails([
            'trade' => 'Smuggled in', 'expected_spend' => '50000+', 'resale_confirmed' => '1',
        ]), $upgrade);

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();
        $this->assertNull($profile->trade);
        $this->assertNull($profile->expected_spend);
        $this->assertFalse($profile->resale_confirmed);
        $this->assertArrayNotHasKey('trade', $upgrade->details);
    }

    public function test_a_business_account_cannot_upgrade_to_business_again()
    {
        $error = AccountUpgrade::upgradeToBusiness($this->user(Fixture::USER_BUSINESS_ID), $this->businessDetails());

        $this->assertSame(AccountUpgrade::NOT_ALLOWED_MESSAGE, $error);
        $this->assertSame(0, AccountUpgradeRequest::count());
    }

    public function test_a_suspended_account_cannot_upgrade()
    {
        $error = AccountUpgrade::upgradeToBusiness($this->user(Fixture::USER_SUSPENDED_ID), $this->businessDetails());

        $this->assertNotNull($error);
        $this->assertSame('personal', $this->user(Fixture::USER_SUSPENDED_ID)->account_type);
    }

    // ---- applying for trade -----------------------------------------------

    public function test_a_trade_application_from_personal_changes_nothing_on_the_account()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);

        $error = AccountUpgrade::applyForTrade($user, $this->tradeDetails(['sector' => 'Not a trade field']), $upgrade);

        $this->assertNull($error);
        $fresh = $user->fresh();
        $this->assertSame('personal', $fresh->account_type);
        $this->assertNull($fresh->trade_status);
        $this->assertSame(User::VERIFICATION_NOT_REQUIRED, $fresh->verification_status);
        $this->assertFalse($fresh->hasBusinessPricing());
        $this->assertNull(UserBusinessProfile::where('user_id', $user->id)->first());

        $this->assertTrue($upgrade->isPending());
        $this->assertSame('personal', $upgrade->from_type);
        $this->assertSame('Computer repair shop', $upgrade->details['trade']);
        $this->assertArrayNotHasKey('sector', $upgrade->details);
    }

    public function test_a_business_customer_keeps_business_pricing_while_applying()
    {
        $user = $this->user(Fixture::USER_BUSINESS_ID);
        $before = UserBusinessProfile::where('user_id', $user->id)->first()->toArray();

        $this->assertNull(AccountUpgrade::applyForTrade($user, $this->tradeDetails(), $upgrade));

        $fresh = $user->fresh();
        $this->assertSame('business', $fresh->account_type);
        $this->assertSame(User::VERIFICATION_VERIFIED, $fresh->verification_status);
        $this->assertTrue($fresh->hasBusinessPricing());
        $this->assertSame($before, UserBusinessProfile::where('user_id', $user->id)->first()->toArray());
        $this->assertSame('business', $upgrade->from_type);
    }

    public function test_an_application_without_the_resale_declaration_is_refused()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);

        $error = AccountUpgrade::applyForTrade($user, $this->tradeDetails(['resale_confirmed' => '0']));

        $this->assertNotNull($error);
        $this->assertSame(0, AccountUpgradeRequest::where('user_id', $user->id)->count());
    }

    public function test_only_one_application_may_wait_and_it_blocks_other_upgrades()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);
        AccountUpgrade::applyForTrade($user, $this->tradeDetails());

        $this->assertSame(AccountUpgrade::PENDING_MESSAGE, AccountUpgrade::applyForTrade($user, $this->tradeDetails()));
        $this->assertSame(AccountUpgrade::PENDING_MESSAGE, AccountUpgrade::upgradeToBusiness($user, $this->businessDetails()));
        $this->assertSame([], AccountUpgrade::options($user));
        $this->assertSame(1, AccountUpgradeRequest::where('user_id', $user->id)->count());
    }

    public function test_a_withdrawn_application_frees_the_customer_to_apply_again()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);
        AccountUpgrade::applyForTrade($user, $this->tradeDetails());

        $this->assertNull(AccountUpgrade::withdraw($user, $withdrawn));
        $this->assertSame(AccountUpgradeRequest::STATUS_WITHDRAWN, $withdrawn->status);
        $this->assertNotNull(AccountUpgrade::withdraw($user), 'nothing left to withdraw');

        $this->assertSame(['business', 'trade'], AccountUpgrade::options($user));
    }

    // ---- staff decision ---------------------------------------------------

    public function test_approving_a_personal_application_makes_an_approved_trade_account()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);
        AccountUpgrade::applyForTrade($user, $this->tradeDetails(), $upgrade);

        $error = AccountUpgrade::decide($upgrade, 'approved', 'Checked CRO', Fixture::ADMIN_SUPER_ID, $transitioned);

        $this->assertNull($error);
        $this->assertTrue($transitioned);
        $fresh = $user->fresh();
        $this->assertSame('trade', $fresh->account_type);
        $this->assertSame(User::TRADE_APPROVED, $fresh->trade_status);
        $this->assertSame(User::VERIFICATION_VERIFIED, $fresh->verification_status);
        $this->assertTrue($fresh->pref_trade_list);
        $this->assertTrue($fresh->hasBusinessPricing());

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();
        $this->assertSame('Trade Fixture Ltd', $profile->company_name);
        $this->assertSame('Computer repair shop', $profile->trade);
        $this->assertTrue($profile->resale_confirmed);

        $this->assertSame('approved', $upgrade->status);
        $this->assertSame(Fixture::ADMIN_SUPER_ID, (int)$upgrade->reviewed_by);
        $this->assertSame('Checked CRO', $upgrade->review_note);
    }

    public function test_approving_a_business_application_replaces_the_company_details_but_keeps_the_po_rule()
    {
        $user = $this->user(Fixture::USER_BUSINESS_ID);
        UserBusinessProfile::where('user_id', $user->id)->update(['requires_po' => 1]);

        AccountUpgrade::applyForTrade($user, $this->tradeDetails(['company_name' => 'Renamed Ltd']), $upgrade);
        AccountUpgrade::decide($upgrade, 'approved', null, Fixture::ADMIN_SUPER_ID);

        $profile = UserBusinessProfile::where('user_id', $user->id)->first();
        $this->assertSame('Renamed Ltd', $profile->company_name);
        $this->assertTrue($profile->requires_po);
        $this->assertSame('Retail', $profile->sector);
        $this->assertSame('trade', $user->fresh()->account_type);
    }

    public function test_a_rejected_business_application_leaves_the_account_exactly_as_it_was()
    {
        $user = $this->user(Fixture::USER_BUSINESS_ID);
        $before = $user->fresh()->only(['account_type', 'trade_status', 'verification_status', 'pref_trade_list']);
        $profileBefore = UserBusinessProfile::where('user_id', $user->id)->first()->toArray();

        AccountUpgrade::applyForTrade($user, $this->tradeDetails(['company_name' => 'Someone Else Ltd']), $upgrade);
        $error = AccountUpgrade::decide($upgrade, 'rejected', 'VAT number not found', Fixture::ADMIN_SUPER_ID, $transitioned);

        $this->assertNull($error);
        $this->assertTrue($transitioned);
        $this->assertSame($before, $user->fresh()->only(array_keys($before)));
        $this->assertSame($profileBefore, UserBusinessProfile::where('user_id', $user->id)->first()->toArray());
        $this->assertTrue($user->fresh()->hasBusinessPricing());
        $this->assertSame('rejected', $upgrade->status);

        // A rejection is not the end: they may apply again.
        $this->assertSame(['trade'], AccountUpgrade::options($user->fresh()));
    }

    public function test_a_second_identical_decision_is_a_quiet_no_op()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);
        AccountUpgrade::applyForTrade($user, $this->tradeDetails(), $upgrade);
        $stale = AccountUpgradeRequest::find($upgrade->id);

        AccountUpgrade::decide($upgrade, 'approved', null, Fixture::ADMIN_SUPER_ID, $first);
        $error = AccountUpgrade::decide($stale, 'approved', null, Fixture::ADMIN_SUPER_ID, $second);

        $this->assertTrue($first);
        $this->assertNull($error);
        $this->assertFalse($second, 'the double click must not send a second email');
    }

    public function test_a_decided_application_cannot_be_decided_the_other_way()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);
        AccountUpgrade::applyForTrade($user, $this->tradeDetails(), $upgrade);
        $stale = AccountUpgradeRequest::find($upgrade->id);

        AccountUpgrade::decide($upgrade, 'rejected', null, Fixture::ADMIN_SUPER_ID);
        $error = AccountUpgrade::decide($stale, 'approved', null, Fixture::ADMIN_SUPER_ID, $transitioned);

        $this->assertStringContainsString('already been rejected', $error);
        $this->assertFalse($transitioned);
        $this->assertSame('personal', $user->fresh()->account_type);
    }

    public function test_a_withdrawn_application_cannot_be_approved()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);
        AccountUpgrade::applyForTrade($user, $this->tradeDetails(), $upgrade);
        AccountUpgrade::withdraw($user);

        $error = AccountUpgrade::decide($upgrade, 'approved', null, Fixture::ADMIN_SUPER_ID);

        $this->assertStringContainsString('withdrawn', $error);
        $this->assertSame('personal', $user->fresh()->account_type);
    }

    public function test_an_unknown_decision_is_refused()
    {
        $user = $this->user(Fixture::USER_PERSONAL_ID);
        AccountUpgrade::applyForTrade($user, $this->tradeDetails(), $upgrade);

        $this->assertNotNull(AccountUpgrade::decide($upgrade, 'pending'));
        $this->assertTrue($upgrade->fresh()->isPending());
    }
}
