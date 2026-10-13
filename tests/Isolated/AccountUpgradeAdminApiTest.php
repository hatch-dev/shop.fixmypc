<?php

namespace Tests\Isolated;

use App\Models\AccountUpgradeRequest;
use App\Models\Helper\AccountUpgrade;
use App\Models\User;
use Database\Seeders\Testing\Fixture;
use Tests\IsolatedTestCase;

/**
 * /api/admin/trade-application/upgrades: the staff queue for trade
 * applications from accounts that already exist. The decision rules are
 * pinned in AccountUpgradeTest; these pin the queue, the side-by-side view
 * the reviewer decides from, and who may use it.
 */
class AccountUpgradeAdminApiTest extends IsolatedTestCase
{
    private function apply($userId, $overrides = [])
    {
        $error = AccountUpgrade::applyForTrade(User::findOrFail($userId), array_merge([
            'company_name' => 'Queue Fixture Ltd',
            'vat_number' => 'IE' . $userId . '0000A',
            'billing_address' => '1 Fixture Street, Dublin',
            'trade' => 'Computer repair shop',
            'expected_spend' => '1000-5000',
            'resale_confirmed' => true,
        ], $overrides), $upgrade);

        $this->assertNull($error);

        return $upgrade;
    }

    private function changes($response)
    {
        return collect($response->json('data.changes'))->keyBy('field');
    }

    public function test_the_queue_lists_waiting_applications_oldest_first()
    {
        $first = $this->apply(Fixture::USER_BUSINESS_ID);
        $second = $this->apply(Fixture::USER_PERSONAL_ID);

        $response = $this->asAdmin()->getJson('/api/admin/trade-application/upgrades');

        $this->assertApiOk($response);
        $this->assertSame([$first->id, $second->id], collect($response->json('data.data'))->pluck('id')->all());
        $this->assertSame('business', $response->json('data.data.0.from_type'));
        $this->assertSame('Bea Business', $response->json('data.data.0.user.name'));
        $this->assertTrue($response->json('data.data.0.user.account.has_business_pricing'));
    }

    public function test_completed_business_upgrades_are_in_their_own_tab()
    {
        AccountUpgrade::upgradeToBusiness(User::findOrFail(Fixture::USER_PERSONAL_ID), [
            'company_name' => 'Instant Ltd', 'billing_address' => 'x', 'sector' => 'Retail',
        ]);

        $pending = $this->asAdmin()->getJson('/api/admin/trade-application/upgrades');
        $completed = $this->asAdmin()->getJson('/api/admin/trade-application/upgrades?status=completed');

        $this->assertSame([], $pending->json('data.data'));
        $this->assertSame('business', $completed->json('data.data.0.to_type'));
    }

    public function test_an_unknown_status_filter_is_refused()
    {
        $this->assertApiRefused($this->asAdmin()->getJson('/api/admin/trade-application/upgrades?status=bogus'), 201, 'Unknown');
    }

    public function test_the_queue_searches_by_customer_email()
    {
        $this->apply(Fixture::USER_BUSINESS_ID);
        $this->apply(Fixture::USER_PERSONAL_ID);

        $response = $this->asAdmin()->getJson('/api/admin/trade-application/upgrades?q=' . urlencode(Fixture::email(Fixture::USER_PERSONAL_ID)));

        $this->assertSame([Fixture::USER_PERSONAL_ID], collect($response->json('data.data'))->pluck('user.id')->all());
    }

    public function test_the_reviewer_sees_what_a_business_changed()
    {
        $upgrade = $this->apply(Fixture::USER_BUSINESS_ID, ['company_name' => 'Renamed Ltd']);

        $response = $this->asAdmin()->getJson('/api/admin/trade-application/upgrades/' . $upgrade->id);

        $this->assertApiOk($response);
        $changes = $this->changes($response);
        $this->assertSame('Bea Business Ltd', $changes['company_name']['current']);
        $this->assertSame('Renamed Ltd', $changes['company_name']['submitted']);
        $this->assertTrue($changes['company_name']['changed']);
        $this->assertFalse($changes['vat_number']['changed'], 'same VAT number as the verified profile');
        $this->assertFalse($changes['billing_address']['changed']);
        $this->assertSame('Company name', $changes['company_name']['label']);
    }

    public function test_a_personal_application_shows_every_answer_as_new()
    {
        $upgrade = $this->apply(Fixture::USER_PERSONAL_ID);

        $changes = $this->changes($this->asAdmin()->getJson('/api/admin/trade-application/upgrades/' . $upgrade->id));

        $this->assertNull($changes['company_name']['current']);
        $this->assertTrue($changes['company_name']['changed']);
        $this->assertFalse($changes['cro_number']['changed'], 'left blank, so nothing new');
    }

    public function test_approving_makes_the_account_trade_and_reports_the_notification_once()
    {
        $upgrade = $this->apply(Fixture::USER_PERSONAL_ID);
        $url = '/api/admin/trade-application/upgrades/action/' . $upgrade->id;

        $first = $this->asAdmin()->postJson($url, ['decision' => 'approved', 'review_note' => 'CRO checked']);
        $second = $this->asAdmin()->postJson($url, ['decision' => 'approved']);

        $this->assertApiOk($first);
        $this->assertSame('approved', $first->json('data.status'));
        $this->assertSame('Super Admin', $first->json('data.reviewed_by.name'));
        $this->assertTrue($first->json('data.notification.triggered'));
        $this->assertSame('trade', $first->json('data.user.account.account_type'));

        $this->assertApiOk($second);
        $this->assertFalse($second->json('data.notification.triggered'), 'a double click must not email twice');

        $user = User::find(Fixture::USER_PERSONAL_ID);
        $this->assertSame(User::TRADE_APPROVED, $user->trade_status);
        $this->assertTrue($user->hasBusinessPricing());
    }

    public function test_rejecting_keeps_the_account_as_it_was()
    {
        $upgrade = $this->apply(Fixture::USER_BUSINESS_ID);

        $response = $this->asAdmin()->postJson('/api/admin/trade-application/upgrades/action/' . $upgrade->id, [
            'decision' => 'rejected', 'review_note' => 'Not a trade business',
        ]);

        $this->assertApiOk($response);
        $this->assertSame('Not a trade business', $response->json('data.review_note'));
        $this->assertSame('business', User::find(Fixture::USER_BUSINESS_ID)->account_type);
    }

    public function test_an_invalid_decision_is_refused()
    {
        $upgrade = $this->apply(Fixture::USER_PERSONAL_ID);

        $response = $this->asAdmin()->postJson('/api/admin/trade-application/upgrades/action/' . $upgrade->id, ['decision' => 'maybe']);

        $this->assertApiRefused($response, 201, 'approved or rejected');
        $this->assertTrue($upgrade->fresh()->isPending());
    }

    public function test_a_missing_application_is_reported_not_found()
    {
        $response = $this->asAdmin()->postJson('/api/admin/trade-application/upgrades/action/999999', ['decision' => 'approved']);

        $this->assertNotSame(200, (int)$response->json('status'));
    }

    public function test_an_admin_without_customer_permissions_cannot_decide()
    {
        $upgrade = $this->apply(Fixture::USER_PERSONAL_ID);

        $response = $this->asAdmin(Fixture::ADMIN_VENDOR_ID)
            ->postJson('/api/admin/trade-application/upgrades/action/' . $upgrade->id, ['decision' => 'approved']);

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertTrue($upgrade->fresh()->isPending());
    }

    public function test_a_customer_token_cannot_reach_the_queue()
    {
        $this->apply(Fixture::USER_BUSINESS_ID);

        $response = $this->asCustomer(Fixture::USER_BUSINESS_ID)->getJson('/api/admin/trade-application/upgrades');

        $this->assertNotSame(200, (int)$response->json('status'));
    }

    public function test_the_customer_record_carries_the_upgrade_history()
    {
        $upgrade = $this->apply(Fixture::USER_BUSINESS_ID);
        AccountUpgrade::decide($upgrade, 'rejected', 'Try again with a VAT cert', Fixture::ADMIN_SUPER_ID);

        $response = $this->asAdmin()->getJson('/api/admin/user/find/' . Fixture::USER_BUSINESS_ID);

        $this->assertApiOk($response);
        $this->assertSame('rejected', $response->json('data.upgrade_requests.0.status'));
        $this->assertSame('Super Admin', $response->json('data.upgrade_requests.0.reviewer.name'));
        $this->assertArrayNotHasKey('details', (array)$response->json('data.upgrade_requests.0'));
    }
}
