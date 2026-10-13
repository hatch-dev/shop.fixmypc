<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Helper\AccountStatus;
use App\Models\User;
use App\Models\UserBusinessProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * What the admin customer record hands back, per account type.
 *
 * The screen could show a customer's name, email and company name and nothing
 * else they had filled in: a business account's sector, PO requirement and
 * credit request were collected at signup and then visible nowhere, and the
 * person deciding a trade application had no way to read the application.
 *
 * Two things these tests hold down. The payload must be account-type aware —
 * a business account must not come back carrying empty trade fields that read
 * like the customer skipped them — and it must not carry the account's live
 * verification code, which it did.
 */
class AdminCustomerPreviewTest extends TestCase
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

    private function customer($attributes = [], $profile = null)
    {
        $user = User::create(array_merge([
            'name' => 'Preview Fixture',
            'email' => 'preview.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_NOT_REQUIRED,
            // The live sign-in code. Present on every real account.
            'code' => '123456',
            'pref_email_offers' => true,
            'pref_sms_offers' => false,
            'pref_recommendations' => true,
        ], $attributes))->fresh();

        if ($profile) {
            UserBusinessProfile::create(array_merge(['user_id' => $user->id], $profile));
        }

        return $user->fresh();
    }

    private function businessCustomer($attributes = [])
    {
        return $this->customer(array_merge([
            'name' => 'Daniel Murphy',
            'account_type' => 'business',
            'verification_status' => User::VERIFICATION_PENDING,
        ], $attributes), [
            'company_name' => 'Murphy IT Solutions Ltd',
            'vat_number' => 'IE1234567A',
            'cro_number' => '123456',
            'billing_address' => '25 Main Street, Dublin, D02 X285, Ireland',
            'sector' => 'Professional services / office',
            'requires_po' => true,
            'credit_application' => true,
        ]);
    }

    private function tradeCustomer($attributes = [])
    {
        return $this->customer(array_merge([
            'name' => "James O'Brien",
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
            'verification_status' => User::VERIFICATION_PENDING,
            'pref_trade_list' => true,
        ], $attributes), [
            'company_name' => "O'Brien Electrical Services Ltd",
            'vat_number' => 'IE7654321B',
            'cro_number' => '654321',
            'billing_address' => '42 Industrial Estate, Cork, T12 AB12, Ireland',
            'trade' => 'Field engineer / sole trader',
            'expected_spend' => '€2,000 – €5,000',
            'website' => 'https://example.com',
            'resale_confirmed' => true,
            'requires_po' => true,
        ]);
    }

    private function preview($customer)
    {
        $this->admin();

        $response = $this->getJson("/api/admin/user/find/{$customer->id}");

        $response->assertStatus(200);

        return $response;
    }

    /** Every field on the record, flattened to label => value. */
    private function fields($response)
    {
        $flat = [];

        foreach ($response->json('data.registration.sections') ?? [] as $section) {
            foreach ($section['fields'] as $field) {
                $flat[$field['label']] = $field;
            }
        }

        return $flat;
    }

    private function sectionKeys($response)
    {
        return array_column($response->json('data.registration.sections') ?? [], 'key');
    }

    // ---- the leak this closed ----------------------------------------------

    public function test_the_record_does_not_carry_the_live_sign_in_code()
    {
        $customer = $this->businessCustomer();

        $body = $this->preview($customer)->getContent();

        $this->assertStringNotContainsString('123456,', $body,
            'The live verification code must not be serialised.');
        $this->assertNull($this->preview($customer)->json('data.code'));
        $this->assertNull($this->preview($customer)->json('data.code_expires_at'));
        $this->assertNull($this->preview($customer)->json('data.code_attempts'));
    }

    public function test_the_record_does_not_carry_credentials_or_two_factor_secrets()
    {
        $customer = $this->businessCustomer();

        $response = $this->preview($customer);

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $secret) {
            $this->assertNull($response->json('data.' . $secret), "{$secret} must not be exposed.");
        }
    }

    // ---- personal ------------------------------------------------------------

    public function test_a_personal_account_carries_no_company_or_trade_sections()
    {
        $customer = $this->customer();

        $response = $this->preview($customer);

        $keys = $this->sectionKeys($response);

        $this->assertContains('personal', $keys);
        $this->assertContains('preferences', $keys);
        $this->assertContains('verification', $keys);
        // The point of the exercise: no empty company or trade rows that read
        // like the customer failed to answer them.
        $this->assertNotContains('business', $keys);
        $this->assertNotContains('address', $keys);
        $this->assertNotContains('trade', $keys);
    }

    public function test_a_personal_account_reports_its_contact_details()
    {
        $customer = $this->customer(['name' => 'Ordinary Customer']);

        $fields = $this->fields($this->preview($customer));

        $this->assertSame('Ordinary Customer', $fields['Full name']['value']);
        $this->assertSame($customer->email, $fields['Email address']['value']);
        $this->assertSame($customer->phone, $fields['Phone number']['value']);
    }

    // ---- business ------------------------------------------------------------

    public function test_a_business_account_reports_every_value_it_registered_with()
    {
        $customer = $this->businessCustomer();

        $fields = $this->fields($this->preview($customer));

        $this->assertSame('Daniel Murphy', $fields['Full name']['value']);
        $this->assertSame('Murphy IT Solutions Ltd', $fields['Company name']['value']);
        $this->assertSame('IE1234567A', $fields['VAT number']['value']);
        $this->assertSame('123456', $fields['Company registration number']['value']);
        $this->assertSame('Professional services / office', $fields['Sector']['value']);
        $this->assertSame('25 Main Street, Dublin, D02 X285, Ireland', $fields['Billing address']['value']);
        $this->assertTrue($fields['Purchase order required at checkout']['value']);
        $this->assertTrue($fields['Credit account requested']['value']);
    }

    public function test_a_business_account_is_not_given_trade_fields()
    {
        $customer = $this->businessCustomer();

        $response = $this->preview($customer);

        $this->assertNotContains('trade', $this->sectionKeys($response));

        $fields = $this->fields($response);

        foreach (['Trade type', 'Expected monthly spend', 'Website', 'Resale confirmed'] as $tradeOnly) {
            $this->assertArrayNotHasKey($tradeOnly, $fields,
                "{$tradeOnly} belongs to trade accounts and must not appear on a business record.");
        }
    }

    public function test_a_business_account_keeps_its_marketing_preferences()
    {
        $customer = $this->businessCustomer([
            'pref_email_offers' => false,
            'pref_recommendations' => true,
        ]);

        $fields = $this->fields($this->preview($customer));

        $this->assertFalse($fields['Email offers']['value']);
        $this->assertTrue($fields['Product recommendations']['value']);
        // A trade-only preference must not appear on a business account.
        $this->assertArrayNotHasKey('Trade mailing list', $fields);
    }

    public function test_an_optional_field_left_blank_is_reported_as_not_provided()
    {
        $customer = $this->customer([
            'name' => 'Sparse Business',
            'account_type' => 'business',
            'verification_status' => User::VERIFICATION_PENDING,
        ], [
            'company_name' => 'Sparse Ltd',
            'sector' => 'Other',
            'billing_address' => '1 Nowhere Lane',
            // vat_number and cro_number deliberately absent.
        ]);

        $fields = $this->fields($this->preview($customer));

        $this->assertFalse($fields['VAT number']['provided']);
        $this->assertNull($fields['VAT number']['value']);
        $this->assertFalse($fields['Company registration number']['provided']);
        // A boolean "No" is an answer, not a blank.
        $this->assertTrue($fields['Purchase order required at checkout']['provided']);
        $this->assertFalse($fields['Purchase order required at checkout']['value']);
    }

    // ---- trade ---------------------------------------------------------------

    public function test_a_trade_account_reports_its_whole_application()
    {
        $customer = $this->tradeCustomer();

        $response = $this->preview($customer);
        $fields = $this->fields($response);

        $this->assertContains('trade', $this->sectionKeys($response));

        $this->assertSame("O'Brien Electrical Services Ltd", $fields['Company name']['value']);
        $this->assertSame('IE7654321B', $fields['VAT number']['value']);
        $this->assertSame('654321', $fields['Company registration number']['value']);
        $this->assertSame('Field engineer / sole trader', $fields['Trade type']['value']);
        $this->assertSame('€2,000 – €5,000', $fields['Expected monthly spend']['value']);
        $this->assertSame('https://example.com', $fields['Website']['value']);
        $this->assertTrue($fields['Resale confirmed']['value']);
        $this->assertSame('42 Industrial Estate, Cork, T12 AB12, Ireland', $fields['Billing address']['value']);
        $this->assertTrue($fields['Trade mailing list']['value']);
    }

    /**
     * Sector is a business-only question. Showing it on a trade record would
     * invite a reviewer to read the blank as something the applicant skipped.
     */
    public function test_a_trade_account_is_not_given_the_business_only_sector()
    {
        $customer = $this->tradeCustomer();

        $this->assertArrayNotHasKey('Sector', $this->fields($this->preview($customer)));
    }

    public function test_a_pending_application_reports_pending_and_no_decision()
    {
        $customer = $this->tradeCustomer();

        $response = $this->preview($customer);

        $this->assertSame(User::TRADE_PENDING, $response->json('data.account.trade_status'));
        $this->assertFalse($response->json('data.account.has_business_pricing'));

        $fields = $this->fields($response);

        $this->assertTrue($fields['Applied on']['provided']);
        $this->assertFalse($fields['Decision recorded']['provided']);
        $this->assertFalse($fields['Note sent to the customer']['provided']);
    }

    public function test_an_approved_application_reports_the_decision_and_entitlement()
    {
        $customer = $this->tradeCustomer();

        AccountStatus::applyTrade($customer, User::TRADE_APPROVED, 'Verified against CRO listing.');

        $response = $this->preview($customer->fresh());

        $this->assertSame(User::TRADE_APPROVED, $response->json('data.account.trade_status'));
        $this->assertTrue($response->json('data.account.has_business_pricing'));
        // Approving is also what marks the company details checked.
        $this->assertSame(User::VERIFICATION_VERIFIED, $response->json('data.account.verification_status'));

        $fields = $this->fields($response);

        $this->assertTrue($fields['Decision recorded']['provided']);
        $this->assertSame('Verified against CRO listing.', $fields['Note sent to the customer']['value']);
    }

    public function test_a_rejected_application_reports_rejected_and_no_entitlement()
    {
        $customer = $this->tradeCustomer();

        AccountStatus::applyTrade($customer, User::TRADE_REJECTED, 'Could not verify the company.');

        $response = $this->preview($customer->fresh());

        $this->assertSame(User::TRADE_REJECTED, $response->json('data.account.trade_status'));
        $this->assertFalse($response->json('data.account.has_business_pricing'));
        // The account itself stays open and usable at retail.
        $this->assertSame(User::STATUS_ACTIVE, $response->json('data.account.status'));
    }

    public function test_a_suspended_trade_status_reports_suspended_and_no_entitlement()
    {
        $customer = $this->tradeCustomer();

        AccountStatus::applyTrade($customer, User::TRADE_APPROVED);
        AccountStatus::applyTrade($customer->fresh(), User::TRADE_SUSPENDED);

        $response = $this->preview($customer->fresh());

        $this->assertSame(User::TRADE_SUSPENDED, $response->json('data.account.trade_status'));
        $this->assertFalse($response->json('data.account.has_business_pricing'));
        $this->assertSame(User::STATUS_ACTIVE, $response->json('data.account.status'));
    }

    public function test_a_suspended_account_reports_suspended_without_touching_the_application()
    {
        $customer = $this->tradeCustomer();

        AccountStatus::applyTrade($customer, User::TRADE_APPROVED);
        AccountStatus::applyAccount($customer->fresh(), User::STATUS_SUSPENDED, 'Payment dispute under review');

        $response = $this->preview($customer->fresh());

        $this->assertSame(User::STATUS_SUSPENDED, $response->json('data.account.status'));
        // The application still stands underneath the suspension.
        $this->assertSame(User::TRADE_APPROVED, $response->json('data.account.trade_status'));
        $this->assertFalse($response->json('data.account.has_business_pricing'));
    }

    /**
     * The suspension reason is a staff remark. It has to reach the reviewer,
     * and it must never be presented as something the customer submitted.
     */
    public function test_the_internal_status_note_is_not_mixed_into_the_submitted_data()
    {
        $customer = $this->businessCustomer();

        AccountStatus::applyAccount($customer, User::STATUS_SUSPENDED, 'INTERNAL: credit check failed, ticket 4471');

        $response = $this->preview($customer->fresh());

        $this->assertSame('INTERNAL: credit check failed, ticket 4471',
            $response->json('data.account.status_note'));

        foreach ($this->fields($response) as $label => $field) {
            $this->assertNotSame('INTERNAL: credit check failed, ticket 4471', $field['value'],
                "The internal status note leaked into the submitted field '{$label}'.");
        }
    }

    // ---- shape and access ----------------------------------------------------

    public function test_the_record_names_what_registration_never_asked_for()
    {
        $trade = $this->tradeCustomer();

        $notCollected = $this->preview($trade)->json('data.registration.not_collected');

        $this->assertNotEmpty($notCollected);
        $this->assertTrue(
            (bool)preg_grep('/first and last name/i', $notCollected),
            'The record should say plainly that first/last names are not collected separately.'
        );
        $this->assertTrue(
            (bool)preg_grep('/years in business/i', $notCollected),
            'The record should say plainly that years in business is not collected.'
        );
    }

    public function test_the_reported_account_type_is_the_one_the_customer_registered_with()
    {
        foreach (['personal', 'business', 'trade'] as $type) {
            $customer = $type === 'business' ? $this->businessCustomer()
                : ($type === 'trade' ? $this->tradeCustomer() : $this->customer());

            $response = $this->preview($customer);

            $this->assertSame($type, $response->json('data.account.account_type'));
            $this->assertSame($type, $response->json('data.registration.account_type'));
        }
    }

    public function test_an_anonymous_caller_cannot_read_a_customer_record()
    {
        $customer = $this->businessCustomer();

        $this->app['auth']->forgetGuards();

        $response = $this->getJson("/api/admin/user/find/{$customer->id}");

        /*
         * The refusal is carried in the body, not the HTTP code — this API
         * answers 201 either way, so asserting on the status alone would pass
         * against a wide-open route.
         */
        $this->assertSame(204, $response->json('status'));
        $this->assertSame('Unauthenticated.', $response->json('message'));
        $this->assertNull($response->json('data.registration'));
    }

    public function test_the_trade_queue_carries_the_account_state_each_card_shows()
    {
        $customer = $this->tradeCustomer();

        AccountStatus::applyAccount($customer, User::STATUS_SUSPENDED, 'On hold');

        $this->admin();

        $response = $this->getJson('/api/admin/trade-application/all?status=pending&limit=50');

        $response->assertStatus(200);

        $row = collect($response->json('data.data'))->firstWhere('id', $customer->id);

        $this->assertNotNull($row, 'The applicant is missing from the queue.');
        /*
         * Without this the card rendered every account as "Active", because
         * the row carried no account state at all.
         */
        $this->assertSame(User::STATUS_SUSPENDED, $row['account']['status']);
        $this->assertSame(User::VERIFICATION_PENDING, $row['account']['verification_status']);
    }
}
