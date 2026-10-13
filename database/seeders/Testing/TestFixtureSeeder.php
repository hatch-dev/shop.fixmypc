<?php

namespace Database\Seeders\Testing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\ClientRepository;

/**
 * The known data the isolated test suite starts from. Run by `php artisan test:db`
 * after schema.sql and reference.sql have been loaded; never run it against a
 * database that holds anything you want to keep.
 *
 * Ids come from Fixture, so tests and this seeder cannot disagree. Rows are
 * written with DB::table rather than models: model events and mutators would
 * make the fixture depend on the code under test, and a bug there would then
 * show up as "fixture missing" instead of as the failing test it should be.
 *
 * Money follows the admin save (ProductsController::vatInclusiveColumns):
 * vatInclusivePrice is selling plus the rule's rate whatever excludeVAT says;
 * excludeVAT decides whether the listed price is Ex-VAT or Inc-VAT.
 */
class TestFixtureSeeder extends Seeder
{
    private $now;

    public function run()
    {
        $this->now = now()->format('Y-m-d H:i:s');

        $this->passportClients();
        $this->admins();
        $this->adoptReferenceRows();
        $this->paymentSettings();
        $this->customers();
        $this->catalogue();
        $this->products();

        DB::statement('CREATE TABLE IF NOT EXISTS `_fixture_version` (`version` INT NOT NULL)');
        DB::table('_fixture_version')->delete();
        DB::table('_fixture_version')->insert(['version' => Fixture::VERSION]);
    }

    /** Passport needs a personal access client before any createToken() call. */
    private function passportClients()
    {
        $clients = app(ClientRepository::class);
        $clients->createPersonalAccessClient(null, 'Test Personal Access Client', 'http://localhost');
        $clients->createPasswordGrantClient(null, 'Test Password Grant Client', 'http://localhost', 'users');
    }

    /**
     * reference.sql is copied from the dev database, where rows name whichever
     * admin last saved them (settings says admin 4). Those admins do not exist
     * here, so hand every such row to the fixture superadmin instead of leaving
     * it pointing at nobody.
     */
    private function adoptReferenceRows()
    {
        $tables = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('column_name', 'admin_id')
            ->pluck('table_name');

        foreach ($tables as $table) {
            DB::table($table)
                ->whereNotNull('admin_id')
                ->whereNotIn('admin_id', [Fixture::ADMIN_SUPER_ID, Fixture::ADMIN_VENDOR_ID])
                ->update(['admin_id' => Fixture::ADMIN_SUPER_ID]);
        }
    }

    /**
     * Every gateway the checkout offers, with dummy keys. No real credential
     * exists in the test database, so a call that slipped past the HTTP fake
     * would be refused by the gateway rather than charge anyone.
     */
    private function paymentSettings()
    {
        DB::table('payments')->insert([
            'id' => 1,
            'admin_id' => Fixture::ADMIN_SUPER_ID,
            'created_at' => $this->now,
            'updated_at' => $this->now,
            'cash_on_delivery' => 1,
            'paypal' => 0, 'paypal_key' => 'test-paypal-key', 'paypal_secret' => 'test-paypal-secret',
            'razorpay' => 0, 'razorpay_key' => 'rzp_test_fixture', 'razorpay_secret' => 'test-razorpay-secret',
            'stripe' => 0, 'stripe_key' => 'pk_test_fixture', 'stripe_secret' => 'sk_test_fixture',
            'flutterwave' => 0, 'fw_environment' => 'development',
            'fw_public_key' => 'FLWPUBK_TEST-fixture-X', 'fw_secret_key' => 'FLWSECK_TEST-fixture-X',
            'fw_encryption_key' => 'FLWSECK_TESTfixture',
            'iyzico_payment' => 0, 'ip_base_url' => 'https://sandbox-api.iyzipay.com',
            'ip_api_key' => 'sandbox-fixture', 'ip_secret_key' => 'sandbox-fixture',
            'bank' => 1, 'bank_name' => 'Fixture Bank', 'account_name' => 'FIXTURE STORE LTD',
            'branch_name' => 'TEST', 'account_number' => 'IE00 TEST 0000 0000 0000 00',
            'iban' => 'IE00 TEST 0000 0000 0000 00', 'bic_swift' => 'TESTIE2D',
            'default' => 2,
            'payfast_payment' => 0, 'payfast_base_url' => 'https://sandbox.payfast.co.za',
            'payfast_merchant_id' => '0', 'payfast_merchant_key' => 'fixture', 'payfast_passphrase' => 'fixture',
            'sumup' => 1, 'sumup_api_key' => 'sup_sk_test_fixture',
            'sumup_merchant_email' => 'merchant@fixture.test', 'sumup_merchant_code' => 'MFIXTURE',
        ]);
    }

    private function admins()
    {
        $password = Hash::make(Fixture::PASSWORD);

        foreach ([
            [Fixture::ADMIN_SUPER_ID, 'Super Admin', 'superadmin', Fixture::ADMIN_SUPER_EMAIL, 'superadmin'],
            [Fixture::ADMIN_VENDOR_ID, 'Vendor Admin', 'vendor', Fixture::ADMIN_VENDOR_EMAIL, 'vendor'],
        ] as [$id, $name, $username, $email, $role]) {
            DB::table('admins')->insert([
                'id' => $id, 'name' => $name, 'username' => $username, 'email' => $email,
                'password' => $password, 'active' => 1, 'verified' => 1,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            DB::table('model_has_roles')->insert([
                'role_id' => DB::table('roles')->where('name', $role)->where('guard_name', 'admin')->value('id'),
                'model_type' => \App\Models\Admin::class,
                'model_id' => $id,
            ]);
        }
    }

    /** One customer per account state that changes what the shop does. */
    private function customers()
    {
        $password = Hash::make(Fixture::PASSWORD);

        $rows = [
            // id, name, account_type, status, verification, trade_status, verified
            [Fixture::USER_PERSONAL_ID, 'Pat Personal', 'personal', 'active', 'not_required', null, 1],
            [Fixture::USER_BUSINESS_ID, 'Bea Business', 'business', 'active', 'verified', null, 1],
            [Fixture::USER_TRADE_APPROVED_ID, 'Tom Trade', 'trade', 'active', 'verified', 'approved', 1],
            [Fixture::USER_TRADE_PENDING_ID, 'Tess Pending', 'trade', 'active', 'pending', 'pending', 1],
            [Fixture::USER_SUSPENDED_ID, 'Sam Suspended', 'personal', 'suspended', 'not_required', null, 1],
            [Fixture::USER_UNVERIFIED_ID, 'Una Unverified', 'personal', 'active', 'not_required', null, 0],
        ];

        foreach ($rows as [$id, $name, $type, $status, $verification, $trade, $verified]) {
            DB::table('users')->insert([
                'id' => $id,
                'name' => $name,
                'email' => Fixture::email($id),
                'phone' => '0870000' . $id,
                'password' => $password,
                'account_type' => $type,
                'status' => $status,
                'verification_status' => $verification,
                'trade_status' => $trade,
                'verified' => $verified,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);

            if ($type !== 'personal') {
                DB::table('user_business_profiles')->insert([
                    'user_id' => $id,
                    'company_name' => $name . ' Ltd',
                    'vat_number' => 'IE' . $id . '0000A',
                    'billing_address' => '1 Fixture Street, Dublin',
                    'sector' => 'Retail',
                    'requires_po' => 0,
                    'resale_confirmed' => $type === 'trade' ? 1 : 0,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }
        }

        DB::table('guest_users')->insert([
            'user_token' => Fixture::GUEST_TOKEN,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    private function catalogue()
    {
        DB::table('brands')->insert([
            'id' => Fixture::BRAND_ID, 'title' => 'Fixturo', 'slug' => 'fixturo',
            'status' => 1, 'featured' => 0, 'admin_id' => Fixture::ADMIN_SUPER_ID,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);

        foreach ([
            [Fixture::CATEGORY_PHONES_ID, 'Phones', 'phones'],
            [Fixture::CATEGORY_COMPUTERS_ID, 'Computers', 'computers'],
        ] as $order => [$id, $title, $slug]) {
            DB::table('categories')->insert([
                'id' => $id, 'title' => $title, 'slug' => $slug, 'status' => 1,
                'parent' => null, 'featured' => 0, 'in_footer' => 0, 'in_frontend' => 1,
                'searchable' => 1, 'display_order' => $order, 'show_in_nav' => 1,
                'admin_id' => Fixture::ADMIN_SUPER_ID,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
        }

        foreach ([
            [Fixture::ATTRIBUTE_STORAGE_ID, 'Storage', [
                [Fixture::VALUE_128GB_ID, '128GB', null],
                [Fixture::VALUE_256GB_ID, '256GB', null],
            ]],
            [Fixture::ATTRIBUTE_COLOUR_ID, 'Colour', [
                [Fixture::VALUE_BLACK_ID, 'Black', '#000000'],
                [Fixture::VALUE_WHITE_ID, 'White', '#ffffff'],
            ]],
        ] as [$id, $title, $values]) {
            DB::table('attributes')->insert([
                'id' => $id, 'title' => $title, 'admin_id' => Fixture::ADMIN_SUPER_ID,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            foreach ($values as $rank => [$valueId, $valueTitle, $color]) {
                DB::table('attribute_values')->insert([
                    'id' => $valueId, 'title' => $valueTitle, 'color' => $color,
                    'attribute_id' => $id, 'rank' => $rank, 'admin_id' => Fixture::ADMIN_SUPER_ID,
                    'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }
        }
    }

    private function products()
    {
        $this->product(Fixture::PRODUCT_PHONE_ID, 'Fixturo Phone X', Fixture::CATEGORY_PHONES_ID, 500, [
            'tax_rule_id' => Fixture::TAX_VAT23_ID, 'excludeVAT' => 0,
        ], [
            [Fixture::PHONE_128_BLACK_ID, 500, 10, [Fixture::VALUE_128GB_ID, Fixture::VALUE_BLACK_ID]],
            [Fixture::PHONE_128_WHITE_ID, 500, 10, [Fixture::VALUE_128GB_ID, Fixture::VALUE_WHITE_ID]],
            [Fixture::PHONE_256_BLACK_ID, 600, 10, [Fixture::VALUE_256GB_ID, Fixture::VALUE_BLACK_ID]],
            [Fixture::PHONE_256_WHITE_ID, 600, 10, [Fixture::VALUE_256GB_ID, Fixture::VALUE_WHITE_ID]],
        ]);

        $this->product(Fixture::PRODUCT_LAPTOP_ID, 'Fixturo Laptop Pro', Fixture::CATEGORY_COMPUTERS_ID, 1000, [
            'tax_rule_id' => Fixture::TAX_VAT23_ID, 'excludeVAT' => 1, 'wholesale_price' => 1000,
        ], [
            [Fixture::LAPTOP_INVENTORY_ID, 1000, 100, []],
        ]);
        foreach ([
            [Fixture::LAPTOP_BAND_10_ID, 10, 49, 900],
            [Fixture::LAPTOP_BAND_50_ID, 50, 999999, 850],
        ] as [$id, $min, $max, $price]) {
            DB::table('business_product_pricing')->insert([
                'id' => $id, 'product_id' => Fixture::PRODUCT_LAPTOP_ID,
                'min_qty' => $min, 'max_qty' => $max,
                'wholesale_price' => 1000, 'discount_type' => 'fixed',
                'discount_value' => 1000 - $price, 'final_price' => $price,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
        }

        $this->product(Fixture::PRODUCT_REFURB_ID, 'Fixturo Phone 8 Refurbished', Fixture::CATEGORY_PHONES_ID, 300, [
            'tax_rule_id' => Fixture::TAX_MARGIN_ID, 'excludeVAT' => 0,
        ], [
            [Fixture::REFURB_INVENTORY_ID, 300, 5, []],
        ]);

        $this->product(Fixture::PRODUCT_BACKORDER_ID, 'Fixturo Tablet', Fixture::CATEGORY_COMPUTERS_ID, 200, [
            'tax_rule_id' => Fixture::TAX_VAT23_ID, 'excludeVAT' => 0, 'back_order' => 1,
        ], [
            [Fixture::BACKORDER_INVENTORY_ID, 200, 0, []],
        ]);

        $this->product(Fixture::PRODUCT_SOLD_OUT_ID, 'Fixturo Earbuds', Fixture::CATEGORY_PHONES_ID, 150, [
            'tax_rule_id' => Fixture::TAX_VAT23_ID, 'excludeVAT' => 0, 'back_order' => 0,
        ], [
            [Fixture::SOLD_OUT_INVENTORY_ID, 150, 0, []],
        ]);

        $this->product(Fixture::PRODUCT_UNPUBLISHED_ID, 'Fixturo Prototype', Fixture::CATEGORY_PHONES_ID, 100, [
            'tax_rule_id' => Fixture::TAX_VAT23_ID, 'excludeVAT' => 0, 'status' => 2,
        ], [
            [Fixture::UNPUBLISHED_INVENTORY_ID, 100, 10, []],
        ]);
    }

    /**
     * A product, its primary category and its stock rows. Each variant is
     * [inventory id, price, quantity, attribute value ids].
     */
    private function product($id, $title, $categoryId, $price, array $overrides, array $variants)
    {
        $rate = $overrides['tax_rule_id'] === Fixture::TAX_VAT23_ID ? 23 : 0;
        $inclusive = number_format(round($price * (100 + $rate) / 100, 2), 2, '.', '');

        DB::table('products')->insert(array_merge([
            'id' => $id,
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title),
            'short_heading' => $title,
            'description' => '<p>' . $title . ' fixture.</p>',
            'unit' => 'piece',
            'selling' => $price,
            'offered' => $price,
            'purchased' => round($price * 0.6, 2),
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'vatInclusivePrice' => $inclusive,
            'vatInclusiveOfferedPrice' => $inclusive,
            'status' => 1,
            'category_id' => 0,
            'brand_id' => Fixture::BRAND_ID,
            'shipping_rule_id' => Fixture::SHIPPING_RULE_ID,
            'back_order' => 0,
            'procurement' => 0,
            'admin_id' => Fixture::ADMIN_SUPER_ID,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ], $overrides));

        DB::table('product_categories')->insert([
            'product_id' => $id, 'category_id' => $categoryId, 'primary' => 1,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);

        foreach ($variants as [$inventoryId, $variantPrice, $quantity, $valueIds]) {
            DB::table('updated_inventories')->insert([
                'id' => $inventoryId, 'product_id' => $id, 'price' => $variantPrice,
                'quantity' => $quantity, 'sku' => 'FIX-' . $inventoryId, 'is_active' => 1,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            foreach ($valueIds as $valueId) {
                DB::table('inventory_attributes')->insert([
                    'inventory_id' => $inventoryId, 'attribute_value_id' => $valueId,
                    'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }
        }
    }
}
