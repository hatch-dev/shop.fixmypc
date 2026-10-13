<?php

namespace Database\Seeders\Testing;

/**
 * The known data TestFixtureSeeder puts in the test database, by id.
 *
 * Tests name what they need from here instead of picking "the first product
 * on the listing" out of whatever the database holds - that habit is why the
 * legacy suite moved whenever an admin edited a product. Ids are fixed, so a
 * failure message points at the same row on every machine.
 *
 * Every account shares PASSWORD. Emails use the reserved .test domain, so
 * even a mail that escaped the array transport could not be delivered.
 */
final class Fixture
{
    const PASSWORD = 'Fixture-Pass-123';

    // Admins (guard: admin)
    const ADMIN_SUPER_ID = 1;
    const ADMIN_SUPER_EMAIL = 'superadmin@fixture.test';
    const ADMIN_VENDOR_ID = 2;
    const ADMIN_VENDOR_EMAIL = 'vendor@fixture.test';

    // Customers (guard: user) - one per account state that changes behaviour
    const USER_PERSONAL_ID = 101;          // personal, active: retail prices
    const USER_BUSINESS_ID = 102;          // business, active: band prices at signup
    const USER_TRADE_APPROVED_ID = 103;    // trade, approved: band prices
    const USER_TRADE_PENDING_ID = 104;     // trade, awaiting review: retail prices
    const USER_SUSPENDED_ID = 105;         // personal, suspended: cannot sign in
    const USER_UNVERIFIED_ID = 106;        // personal, email never confirmed

    const GUEST_TOKEN = 'fixture-guest-token-0001';

    // Catalogue
    const BRAND_ID = 501;
    const CATEGORY_PHONES_ID = 601;
    const CATEGORY_COMPUTERS_ID = 602;

    const ATTRIBUTE_STORAGE_ID = 701;
    const ATTRIBUTE_COLOUR_ID = 702;
    const VALUE_128GB_ID = 711;
    const VALUE_256GB_ID = 712;
    const VALUE_BLACK_ID = 721;
    const VALUE_WHITE_ID = 722;

    const TAX_VAT23_ID = 1;                // from reference.sql: 23%
    const TAX_MARGIN_ID = 2;               // from reference.sql: 0%, margin scheme
    const SHIPPING_RULE_ID = 1;            // from reference.sql: Nationwide, EUR 16

    /** Inc-VAT phone (excludeVAT = 0), four variants: 128/256GB x Black/White. */
    const PRODUCT_PHONE_ID = 1001;
    const PHONE_128_BLACK_ID = 2001;       // EUR 500, 10 in stock
    const PHONE_128_WHITE_ID = 2002;       // EUR 500, 10 in stock
    const PHONE_256_BLACK_ID = 2003;       // EUR 600, 10 in stock
    const PHONE_256_WHITE_ID = 2004;       // EUR 600, 10 in stock

    /** Ex-VAT laptop (excludeVAT = 1): 23% is added at checkout. Has trade bands. */
    const PRODUCT_LAPTOP_ID = 1002;
    const LAPTOP_INVENTORY_ID = 2011;      // EUR 1000 Ex-VAT, 100 in stock
    const LAPTOP_BAND_10_ID = 3001;        // 10-49 units: EUR 900
    const LAPTOP_BAND_50_ID = 3002;        // 50+ units:   EUR 850

    /** Margin-scheme refurbished phone: 0% rule, nothing added or contained. */
    const PRODUCT_REFURB_ID = 1003;
    const REFURB_INVENTORY_ID = 2021;      // EUR 300, 5 in stock

    /** Out of stock but open for back order. */
    const PRODUCT_BACKORDER_ID = 1004;
    const BACKORDER_INVENTORY_ID = 2031;   // EUR 200, 0 in stock

    /** Out of stock, no back order: cannot be bought. */
    const PRODUCT_SOLD_OUT_ID = 1005;
    const SOLD_OUT_INVENTORY_ID = 2041;    // EUR 150, 0 in stock

    /** Private (status 2): must never reach the storefront. */
    const PRODUCT_UNPUBLISHED_ID = 1006;
    const UNPUBLISHED_INVENTORY_ID = 2051; // EUR 100, 10 in stock

    /**
     * Written to the `_fixture_version` table as the seed's last step. Bump it
     * whenever this file or the seeder changes, so a test run against a
     * database seeded by an older copy stops and says to run `php artisan test:db`
     * instead of failing somewhere downstream.
     */
    const VERSION = 1;

    /** A customer's sign-in email, e.g. customer-101@fixture.test. */
    public static function email(int $userId): string
    {
        return "customer-{$userId}@fixture.test";
    }
}
