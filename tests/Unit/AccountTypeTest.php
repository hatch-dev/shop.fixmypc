<?php

namespace Tests\Unit;

use App\Models\Helper\AccountStatus;
use App\Models\Helper\Pricing;
use App\Models\Helper\Validation;
use App\Models\User;
use Tests\TestCase;

/**
 * Account type, account status, verification status and pricing entitlement.
 *
 * The four are separate questions that used to be answered by one column, and
 * these are the rules that keep them apart. None of it needs a database.
 */
class AccountTypeTest extends TestCase
{
    private function user($attributes = [])
    {
        $user = new User();

        $user->forceFill(array_merge([
            'account_type' => 'personal',
            'trade_status' => null,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_NOT_REQUIRED,
        ], $attributes));

        return $user;
    }

    private function tier($min, $max, $price)
    {
        $tier = new \App\Models\BusinessProductPricing();

        $tier->forceFill([
            'min_qty' => $min,
            'max_qty' => $max,
            'wholesale_price' => $price,
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]);

        return $tier;
    }

    // ---- entitlement -------------------------------------------------------

    public function test_a_personal_account_never_gets_business_pricing()
    {
        $this->assertFalse($this->user()->hasBusinessPricing());
    }

    public function test_a_business_account_gets_business_pricing_immediately()
    {
        $this->assertTrue($this->user(['account_type' => 'business'])->hasBusinessPricing());
    }

    public function test_a_pending_trade_account_does_not_get_trade_pricing()
    {
        $user = $this->user([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ]);

        $this->assertFalse($user->hasTradePricing());
        $this->assertFalse($user->hasBusinessPricing());
    }

    public function test_a_rejected_trade_account_does_not_get_trade_pricing()
    {
        $user = $this->user([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_REJECTED,
        ]);

        $this->assertFalse($user->hasBusinessPricing());
    }

    public function test_an_approved_trade_account_gets_trade_pricing()
    {
        $user = $this->user([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
        ]);

        $this->assertTrue($user->hasTradePricing());
        $this->assertTrue($user->hasBusinessPricing());
    }

    /**
     * Suspending an approved trade account has to take the pricing with it —
     * this is the whole reason suspension is not just a label.
     */
    public function test_suspending_an_approved_trade_account_removes_trade_pricing()
    {
        $user = $this->user([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->assertFalse($user->hasTradePricing());
        $this->assertFalse($user->hasBusinessPricing());
        $this->assertFalse($user->canCheckout());
    }

    public function test_suspending_a_business_account_removes_business_pricing()
    {
        $user = $this->user([
            'account_type' => 'business',
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->assertFalse($user->hasBusinessPricing());
        $this->assertFalse($user->canCheckout());
    }

    public function test_withdrawing_trade_status_removes_trade_pricing()
    {
        $user = $this->user([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_SUSPENDED,
        ]);

        $this->assertFalse($user->hasBusinessPricing());
        // The account itself is still usable; only the pricing is gone.
        $this->assertTrue($user->canCheckout());
    }

    /**
     * Rows written before the status column existed carry NULL. Treating those
     * as unusable would have locked out every customer already registered.
     */
    public function test_an_account_with_no_stored_status_is_active()
    {
        $user = $this->user(['account_type' => 'business', 'status' => null]);

        $this->assertTrue($user->isActive());
        $this->assertTrue($user->canCheckout());
        $this->assertTrue($user->hasBusinessPricing());
    }

    // ---- state transitions -------------------------------------------------

    public function test_an_active_account_can_be_suspended_and_brought_back()
    {
        $this->assertNull(AccountStatus::accountError('active', 'suspended'));
        $this->assertNull(AccountStatus::accountError('suspended', 'active'));
    }

    public function test_a_rejected_account_cannot_be_reactivated()
    {
        $this->assertNotNull(AccountStatus::accountError('rejected', 'active'));
    }

    public function test_an_active_account_cannot_jump_straight_to_rejected()
    {
        $this->assertNotNull(AccountStatus::accountError('active', 'rejected'));
    }

    public function test_an_unknown_account_status_is_refused()
    {
        $this->assertNotNull(AccountStatus::accountError('active', 'deleted'));
        $this->assertNotNull(AccountStatus::accountError('active', ''));
        $this->assertNotNull(AccountStatus::accountError('active', null));
        $this->assertNotNull(AccountStatus::accountError('active', ['active']));
    }

    /** Re-sending the current status is a no-op, not an error. */
    public function test_moving_an_account_to_the_status_it_already_has_is_allowed()
    {
        $this->assertNull(AccountStatus::accountError('active', 'active'));
        $this->assertNull(AccountStatus::tradeError('approved', 'approved'));
    }

    public function test_the_trade_ladder_allows_the_whole_review_cycle()
    {
        $this->assertNull(AccountStatus::tradeError('pending', 'approved'));
        $this->assertNull(AccountStatus::tradeError('pending', 'rejected'));
        $this->assertNull(AccountStatus::tradeError('approved', 'suspended'));
        $this->assertNull(AccountStatus::tradeError('suspended', 'approved'));
        $this->assertNull(AccountStatus::tradeError('rejected', 'pending'));
    }

    /**
     * Suspending an application nobody has approved is not a state anything
     * downstream knows how to answer for.
     */
    public function test_a_pending_application_cannot_be_suspended()
    {
        $this->assertNotNull(AccountStatus::tradeError('pending', 'suspended'));
    }

    /** An application with no recorded status is a pending one. */
    public function test_a_null_trade_status_is_treated_as_pending()
    {
        $this->assertNull(AccountStatus::tradeError(null, 'approved'));
        $this->assertNotNull(AccountStatus::tradeError(null, 'suspended'));
    }

    // ---- pricing security --------------------------------------------------

    /**
     * The bands are handed in by the caller, and the caller gets them from
     * BusinessProductPricing::forCustomer — which returns nothing unless the
     * account is entitled. An unentitled customer therefore prices at retail
     * even when the product has bands.
     */
    public function test_no_bands_means_the_retail_price_stands()
    {
        $product = (object)['selling' => 100.0, 'offered' => 0.0, 'price' => null, 'end_time' => null];

        $this->assertSame(100.0, Pricing::unitPrice($product, null, 10, collect()));
        $this->assertSame(100.0, Pricing::unitPrice($product, null, 10, null));
    }

    public function test_a_band_applies_at_its_own_quantity_only()
    {
        $product = (object)['selling' => 100.0, 'offered' => 0.0, 'price' => null, 'end_time' => null];
        $tiers = [$this->tier(10, 0, 70.0)];

        $this->assertSame(100.0, Pricing::unitPrice($product, null, 9, $tiers));
        $this->assertSame(70.0, Pricing::unitPrice($product, null, 10, $tiers));
    }

    /**
     * A band must never charge a business customer more than a walk-up
     * consumer, which is what happens when a flash sale undercuts the agreed
     * wholesale rate.
     */
    public function test_a_band_never_raises_the_price_above_retail()
    {
        $product = (object)[
            'selling' => 100.0, 'offered' => 0.0,
            'price' => 40.0, 'end_time' => '2099-01-01'
        ];

        $tiers = [$this->tier(1, 0, 70.0)];

        $this->assertSame(40.0, Pricing::unitPrice($product, null, 5, $tiers));
    }

    public function test_the_cheapest_overlapping_band_wins()
    {
        $product = (object)['selling' => 100.0, 'offered' => 0.0, 'price' => null, 'end_time' => null];

        $tiers = [$this->tier(1, 20, 80.0), $this->tier(5, 30, 65.0)];

        $this->assertSame(65.0, Pricing::unitPrice($product, null, 10, $tiers));
    }

    // ---- listing prices ----------------------------------------------------

    /**
     * The tile, the search result and the product page have to advertise the
     * price the basket will charge. They were showing retail to everyone.
     */
    public function test_a_listing_shows_the_entitled_price_not_the_retail_one()
    {
        $product = (object)['selling' => 100.0, 'offered' => 0.0, 'price' => null, 'end_time' => null];

        $retail = Pricing::displayRange($product, null, null);
        $trade = Pricing::displayRange($product, null, [$this->tier(1, 0, 72.0)]);

        $this->assertSame(100.0, $retail['min']);
        $this->assertSame(72.0, $trade['min']);
    }

    /** A reduced price needs the price it undercut shown beside it. */
    public function test_a_listing_strikes_through_the_retail_price_a_band_undercuts()
    {
        $product = (object)['selling' => 100.0, 'offered' => 0.0, 'price' => null, 'end_time' => null];

        $trade = Pricing::displayRange($product, null, [$this->tier(1, 0, 72.0)]);

        $this->assertSame(100.0, $trade['compare']);
    }

    public function test_a_listing_for_an_unentitled_customer_is_unchanged()
    {
        $product = (object)['selling' => 100.0, 'offered' => 80.0, 'price' => null, 'end_time' => null];

        $before = Pricing::displayRange($product, null);
        $after = Pricing::displayRange($product, null, collect());

        $this->assertSame($before, $after);
        $this->assertSame(80.0, $after['min']);
        $this->assertSame(100.0, $after['compare']);
    }

    // ---- company identifier validation -------------------------------------

    public function test_a_real_vat_number_is_accepted_however_it_is_spaced()
    {
        $this->assertTrue(Validation::looksLikeVatNumber('IE1234567X'));
        $this->assertTrue(Validation::looksLikeVatNumber('IE 1234567 FA'));
        $this->assertTrue(Validation::looksLikeVatNumber('GB-123-4567-89'));
        $this->assertTrue(Validation::looksLikeVatNumber('DE123456789'));
    }

    /**
     * The column was collecting free text: "n/a", "none yet", a contact name.
     * Requiring two digits is what tells a number from a sentence.
     */
    public function test_free_text_is_not_a_vat_number()
    {
        $this->assertFalse(Validation::looksLikeVatNumber('n/a'));
        $this->assertFalse(Validation::looksLikeVatNumber('none yet'));
        $this->assertFalse(Validation::looksLikeVatNumber('will send later'));
        $this->assertFalse(Validation::looksLikeVatNumber('applied for'));
        $this->assertFalse(Validation::looksLikeVatNumber(''));
        $this->assertFalse(Validation::looksLikeVatNumber('IE1'));
        $this->assertFalse(Validation::looksLikeVatNumber(null));
        $this->assertFalse(Validation::looksLikeVatNumber(['IE1234567X']));
    }

    public function test_a_company_number_is_digits()
    {
        $this->assertTrue(Validation::looksLikeCompanyNumber('123456'));
        $this->assertTrue(Validation::looksLikeCompanyNumber('123 456'));
        $this->assertFalse(Validation::looksLikeCompanyNumber('IE123456'));
        $this->assertFalse(Validation::looksLikeCompanyNumber('abc'));
        $this->assertFalse(Validation::looksLikeCompanyNumber('12'));
        $this->assertFalse(Validation::looksLikeCompanyNumber(null));
    }

    // ---- what the storefront is told ---------------------------------------

    public function test_the_summary_never_claims_pricing_the_backend_will_not_honour()
    {
        $pending = $this->user([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_PENDING,
        ])->accountSummary();

        $this->assertFalse($pending['has_business_pricing']);
        $this->assertTrue($pending['can_checkout']);
        $this->assertSame('trade', $pending['account_type']);

        $approved = $this->user([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
        ])->accountSummary();

        $this->assertTrue($approved['has_business_pricing']);
    }

    public function test_a_suspended_account_is_told_so_and_is_offered_no_pricing()
    {
        $summary = $this->user([
            'account_type' => 'business',
            'status' => User::STATUS_SUSPENDED,
        ])->accountSummary();

        $this->assertSame('suspended', $summary['status']);
        $this->assertFalse($summary['has_business_pricing']);
        $this->assertFalse($summary['can_checkout']);
        $this->assertStringContainsString('suspended', strtolower($summary['headline']));
    }

    public function test_a_personal_account_has_nothing_to_verify()
    {
        $summary = $this->user()->accountSummary();

        $this->assertSame('not_required', $summary['verification_status']);
        $this->assertNull($summary['headline']);
    }

    // ---- tax follows the price actually charged ----------------------------

    /*
     * VAT is charged on what the customer pays, so a band that lowers the unit
     * price has to lower the tax with it. The two are computed in separate
     * steps in OrdersController, which is exactly the shape of code where one
     * gets the band price and the other keeps the retail one.
     *
     * This store adds VAT on top only when the product is flagged excludeVAT;
     * anything else is treated as VAT-inclusive and nothing is added. That is
     * a business decision the audit recorded as TAX-01 and it is unchanged
     * here — business and trade customers are taxed by the same rule as
     * everyone else, on their own price.
     */
    private function taxRule($rate, $type = null)
    {
        // PERCENT by default. constants.priceType is FLAT=1, PERCENT=2, so a
        // literal 1 here quietly makes every rate a flat per-unit charge --
        // which is a fixed figure whatever the price, and would have made the
        // band tests below pass for the wrong reason.
        $type = $type ?? (int)\Illuminate\Support\Facades\Config::get('constants.priceType.PERCENT');

        return (object)['price' => $rate, 'type' => $type];
    }

    public function test_vat_is_charged_on_the_band_price_not_the_retail_price()
    {
        $product = (object)['selling' => 100.0, 'offered' => 0.0, 'price' => null, 'end_time' => null];
        $rule = $this->taxRule(23);

        $retailPrice = Pricing::unitPrice($product, null, 10, null);
        $tradePrice = Pricing::unitPrice($product, null, 10, [$this->tier(10, 0, 60.0)]);

        $this->assertSame(23.0, Pricing::unitTax($rule, $retailPrice, true));
        $this->assertSame(13.8, Pricing::unitTax($rule, $tradePrice, true));
    }

    public function test_a_product_not_flagged_for_vat_is_untaxed_for_every_account_type()
    {
        $rule = $this->taxRule(23);

        $this->assertSame(0.0, Pricing::unitTax($rule, 100.0, false));
        $this->assertSame(0.0, Pricing::unitTax($rule, 60.0, false));
    }

    public function test_a_flat_tax_rule_is_unaffected_by_the_band()
    {
        // A flat charge is per unit and does not scale with the price.
        $flat = $this->taxRule(5, (int)\Illuminate\Support\Facades\Config::get('constants.priceType.FLAT'));

        $this->assertSame(5.0, Pricing::unitTax($flat, 100.0, true));
        $this->assertSame(5.0, Pricing::unitTax($flat, 60.0, true));
    }

    public function test_a_percentage_discount_band_taxes_on_the_discounted_figure()
    {
        $product = (object)['selling' => 200.0, 'offered' => 0.0, 'price' => null, 'end_time' => null];

        $tier = new \App\Models\BusinessProductPricing();
        $tier->forceFill([
            'min_qty' => 1, 'max_qty' => 0,
            'wholesale_price' => 150.0,
            'discount_type' => 'percentage',
            'discount_value' => 20,
        ]);

        // 150 less 20% = 120, capped by the 200 retail price.
        $price = Pricing::unitPrice($product, null, 1, [$tier]);

        $this->assertSame(120.0, $price);
        $this->assertSame(27.6, Pricing::unitTax($this->taxRule(23), $price, true));
    }

    // ---- what a failure is allowed to say ----------------------------------

    /**
     * Controllers here answer their catch blocks with the exception message,
     * so a database failure during registration would otherwise hand the
     * caller the table name, the column and the whole INSERT with its values.
     * Validation::error scrubs that; these check it still does for the account
     * paths, which are the ones that touch company details.
     */
    public function test_an_internal_failure_is_not_described_to_the_caller()
    {
        $leaks = [
            "SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row (SQL: insert into `users` (`email`) values (a@b.c))",
            'Call to a member function businessProfile() on null',
            'Undefined property: App\Models\User::$trade_status',
            "syntax error, unexpected ';'",
        ];

        foreach ($leaks as $leak) {
            $response = Validation::error(null, $leak);

            $this->assertNotSame($leak, $response->message, 'raw message reached the caller');
            $this->assertStringNotContainsString('SQL', $response->message);
            $this->assertStringNotContainsString('users', $response->message);
            $this->assertStringNotContainsString('App\Models', $response->message);
        }
    }

    /** A message actually meant for the customer is left alone. */
    public function test_a_real_message_is_passed_through_untouched()
    {
        $message = 'That VAT number is already registered to another account.';

        $this->assertSame($message, Validation::error(null, $message)->message);
    }

    // ---- the reviewer's note, as the customer receives it -------------------

    /*
     * The note is customer-facing by design: the dashboard has shown it since
     * trade accounts shipped, and the decision email now carries it too. What
     * it is not is guaranteed to be short or to be there at all, so the email
     * asks for it through this.
     */
    public function test_a_blank_note_is_no_note_at_all()
    {
        $this->assertNull(\App\Models\Helper\MailHelper::customerSafeNote(null));
        $this->assertNull(\App\Models\Helper\MailHelper::customerSafeNote(''));
        $this->assertNull(\App\Models\Helper\MailHelper::customerSafeNote('   '));
        $this->assertNull(\App\Models\Helper\MailHelper::customerSafeNote("\n\t "));
        $this->assertNull(\App\Models\Helper\MailHelper::customerSafeNote(42));
        $this->assertNull(\App\Models\Helper\MailHelper::customerSafeNote(['note']));
    }

    public function test_a_normal_note_is_passed_through_tidied()
    {
        $this->assertSame(
            'We could not match the VAT number you gave us.',
            \App\Models\Helper\MailHelper::customerSafeNote(
                "  We could not match\n  the VAT number you gave us.  "
            )
        );
    }

    public function test_a_very_long_note_is_cut_rather_than_sent_whole()
    {
        $note = str_repeat('a', 900);

        $trimmed = \App\Models\Helper\MailHelper::customerSafeNote($note);

        $this->assertSame(600, mb_strlen($trimmed));
        $this->assertStringEndsWith('…', $trimmed);
    }

    public function test_a_note_at_the_limit_is_left_alone()
    {
        $note = str_repeat('a', 600);

        $this->assertSame($note, \App\Models\Helper\MailHelper::customerSafeNote($note));
    }
}
