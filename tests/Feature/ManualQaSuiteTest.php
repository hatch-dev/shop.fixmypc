<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Helper\AccountStatus;
use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\TaxRules;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserBusinessProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Executable QA pass over Personal / Business / Trade accounts.
 *
 * Every case runs against the real application and the real database and
 * records what actually happened, so a "Pass" means the backend was checked,
 * not that a screen looked right. Cases are recorded rather than asserted:
 * one failure must not stop the run, because the point is the whole picture.
 *
 * Results are written to storage/app/qa-results.json for the report.
 */
class ManualQaSuiteTest extends TestCase
{
    use DatabaseTransactions;

    protected static $results = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$results = [];
    }

    public static function tearDownAfterClass(): void
    {
        /*
         * A literal path, not storage_path(): the application container is
         * already gone by the time tearDownAfterClass runs, and resolving a
         * bound path there throws "Target class [path.storage] does not exist"
         * -- which loses the whole run's results at the last step.
         */
        file_put_contents(
            dirname(__DIR__, 2) . '/storage/app/qa-results.json',
            json_encode(self::$results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::getSwiftMailer()->getTransport()->flush();
    }

    /**
     * Record one case. $fn returns [bool passed, string actual].
     *
     * Exceptions are caught and recorded as failures with their message: an
     * unhandled error in a flow IS a defect, and losing the remaining cases to
     * it would hide more than it reveals.
     */
    protected function qa(array $meta, callable $fn)
    {
        /*
         * Empty the outbox before every case. Several modules send mail in more
         * than one case, and reading messages[0] afterwards picked up the
         * earlier case's email -- which made correct behaviour look broken and,
         * worse, would have made a genuinely missing email look fine.
         */
        try {
            Mail::getSwiftMailer()->getTransport()->flush();
        } catch (\Throwable $e) {
            // The Mail facade is mocked in the transport-failure cases.
        }

        try {
            $outcome = $fn();
            $passed = (bool)$outcome[0];
            $actual = (string)$outcome[1];

        } catch (\Throwable $e) {
            $passed = false;
            $actual = 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage();
        }

        self::$results[] = array_merge([
            'id' => '?', 'module' => '?', 'scenario' => '?',
            'pre' => '', 'data' => '', 'steps' => '', 'expected' => '',
            'severity' => 'Medium', 'notes' => '',
        ], $meta, ['actual' => $actual, 'status' => $passed ? 'Pass' : 'Fail']);

        // Each recorded case is a check; count it so PHPUnit does not report
        // the module as risky for making no assertions.
        $this->addToAssertionCount(1);

        return $passed;
    }

    protected function blocked(array $meta, $reason)
    {
        self::$results[] = array_merge([
            'id' => '?', 'module' => '?', 'scenario' => '?',
            'pre' => '', 'data' => '', 'steps' => '', 'expected' => '',
            'severity' => 'Medium', 'notes' => '',
        ], $meta, ['actual' => $reason, 'status' => 'Blocked']);
    }

    /* ================= fixtures ================= */

    protected function customer($attributes = [], $profile = null)
    {
        $user = User::create(array_merge([
            'name' => 'QA Customer',
            'email' => 'qa.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
            'verification_status' => User::VERIFICATION_NOT_REQUIRED,
        ], $attributes));

        if ($profile) {
            UserBusinessProfile::create(array_merge([
                'user_id' => $user->id,
                'company_name' => 'QA Company Ltd',
                'vat_number' => 'IE' . random_int(1000000, 9999999) . 'X',
            ], is_array($profile) ? $profile : []));
        }

        return $user->fresh();
    }

    protected function admin()
    {
        $admin = Admin::whereHas('roles', function ($q) {
            $q->where('name', 'superadmin');
        })->first();

        $this->app['auth']->forgetGuards();

        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    /**
     * Become this customer, and nobody else.
     *
     * forgetGuards() first because Passport::actingAs sets one guard and
     * leaves the others standing: after acting as an admin, the admin guard
     * still resolves, and CartsController::byUser scopes the basket to
     * admin_id whenever an admin is signed in -- so the customer's own lines
     * vanish from the response and a passing case reads as a failing one.
     */
    protected function asCustomer($user)
    {
        $this->app['auth']->forgetGuards();

        Passport::actingAs($user->fresh(), ['user'], 'user');
    }

    /** Drop every identity, for the cases that test an anonymous caller. */
    protected function asGuest()
    {
        $this->app['auth']->forgetGuards();
    }

    /** The seeded product that already carries real quantity bands. */
    protected function bandedProduct()
    {
        return Product::find(99240288);
    }

    /** A product priced ex-VAT, so tax is actually added on top. */
    protected function vatExclusiveProduct()
    {
        return Product::where('excludeVAT', 1)->where('status', 1)
            ->whereIn('id', function ($q) {
                $q->select('product_id')->from('updated_inventories');
            })->first();
    }

    /**
     * A variation that can actually be bought.
     *
     * The first inventory row on the seeded banded product is out of stock
     * with backorder off, so add-to-basket correctly refuses it. Picking it
     * blindly made a working basket look broken.
     */
    protected function inventoryFor($product)
    {
        return UpdatedInventory::where('product_id', $product->id)
            ->where(function ($q) {
                $q->where('quantity', '>', 0)->orWhere('is_active', 1);
            })
            ->orderBy('quantity', 'desc')
            ->first()
            ?: UpdatedInventory::where('product_id', $product->id)->first();
    }

    protected function outbox()
    {
        $m = Mail::getSwiftMailer()->getTransport()->messages();

        return is_array($m) ? $m : $m->all();
    }

    protected function signup(array $payload)
    {
        return $this->postJson('/api/v1/user/signup', $payload);
    }

    protected function basePayload($overrides = [])
    {
        return array_merge([
            'name' => 'QA Person',
            'email' => 'qa.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => 'Abcdefghij1',
            'terms' => 1,
            'account_type' => 'personal',
        ], $overrides);
    }

    protected function businessPayload($overrides = [])
    {
        return $this->basePayload(array_merge([
            'account_type' => 'business',
            'company_name' => 'QA Supplies Ltd',
            'sector' => 'IT and electronics',
            'billing_address' => '1 Main Street, Kilcock',
        ], $overrides));
    }

    protected function tradePayload($overrides = [])
    {
        return $this->basePayload(array_merge([
            'account_type' => 'trade',
            'company_name' => 'QA Electrical',
            'trade' => 'Electrician',
            'vat_number' => 'IE' . random_int(1000000, 9999999) . 'X',
            'billing_address' => '2 High Street, Maynooth',
            'expected_spend' => '1000-5000',
            'resale_confirmed' => 1,
        ], $overrides));
    }

    /** Unit price the storefront advertises for a product, as this caller. */
    protected function detailPrice($productId)
    {
        return (float)$this->getJson("/api/v1/product/{$productId}")->json('data.display_price');
    }

    /** Unit price the basket charges at a given quantity, as this caller. */
    protected function cartUnitPrice($user, $product, $quantity)
    {
        $cart = Cart::create([
            'product_id' => $product->id,
            'inventory_id' => $this->inventoryFor($product)->id,
            'user_id' => $user->id,
            'quantity' => $quantity,
            'selected' => 1,
            'shipping_type' => 1,
            'price' => $product->selling,
            'original_price' => $product->selling,
        ]);

        $this->asCustomer($user);

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('id', $cart->id);

        $price = $line ? (float)$line['unit_price'] : null;

        $cart->delete();

        return $price;
    }

    /** Bands the storefront will advertise to this caller. */
    protected function bandsFor($user, $productId)
    {
        $this->asCustomer($user);

        return $this->getJson("/api/v1/business-product/{$productId}")->json('data.pricing');
    }

    protected function dashboard($user)
    {
        $this->asCustomer($user);

        return $this->getJson('/api/v1/user/profile')->json('data.account');
    }

    protected function review($user, $status, $note = null)
    {
        $this->admin();

        return $this->postJson("/api/admin/trade-application/action/{$user->id}", [
            'trade_status' => $status,
            'trade_review_note' => $note,
        ]);
    }

    protected function setAccountStatus($user, $status, $note = null)
    {
        $this->admin();

        return $this->postJson("/api/admin/user/status/{$user->id}", [
            'status' => $status,
            'status_note' => $note,
        ]);
    }

    /* ============================================================
     | 1. ACCOUNT TYPE SELECTION
     * ========================================================== */

    public function test_module_account_type()
    {
        foreach (['personal', 'business', 'trade'] as $type) {

            $this->qa([
                'id' => 'AT-' . strtoupper(substr($type, 0, 3)) . '-01',
                'module' => 'Account type',
                'scenario' => ucfirst($type) . ' account type is stored as chosen',
                'pre' => 'No account on the address',
                'data' => "account_type={$type}",
                'steps' => 'POST /api/v1/user/signup with a complete payload for the type',
                'expected' => "users.account_type = {$type}",
                'severity' => 'High',
            ], function () use ($type) {
                $payload = $type === 'business' ? $this->businessPayload()
                    : ($type === 'trade' ? $this->tradePayload() : $this->basePayload());

                $this->signup($payload);

                $user = User::where('email', $payload['email'])->first();

                return [
                    $user && $user->account_type === $type,
                    $user ? "account_type={$user->account_type}" : 'user not created',
                ];
            });
        }

        $this->qa([
            'id' => 'AT-DEF-01',
            'module' => 'Account type',
            'scenario' => 'Signup with no account_type defaults to Personal',
            'pre' => 'Legacy caller that sends no account_type',
            'data' => 'name, email, password only',
            'steps' => 'POST /api/v1/user/signup omitting account_type',
            'expected' => 'Account created as personal, legacy validation used',
            'severity' => 'Medium',
        ], function () {
            $payload = $this->basePayload();
            unset($payload['account_type']);

            $this->signup($payload);

            $user = User::where('email', $payload['email'])->first();

            return [
                $user && $user->account_type === 'personal',
                $user ? "account_type={$user->account_type}" : 'user not created',
            ];
        });

        $this->qa([
            'id' => 'AT-INV-01',
            'module' => 'Account type',
            'scenario' => 'Unknown account_type is refused, not silently defaulted',
            'pre' => 'None',
            'data' => "account_type='wholesale'",
            'steps' => 'POST /api/v1/user/signup with an invented type',
            'expected' => 'Validation error on account_type; no account created',
            'severity' => 'High',
        ], function () {
            $payload = $this->basePayload(['account_type' => 'wholesale']);

            $response = $this->signup($payload);

            $created = User::where('email', $payload['email'])->exists();

            return [
                $response->json('status') === 201
                    && isset($response->json('data.fields')['account_type'])
                    && !$created,
                'status=' . $response->json('status')
                    . ' fields=' . implode(',', array_keys($response->json('data.fields') ?? []))
                    . ' created=' . ($created ? 'yes' : 'no'),
            ];
        });

        $this->qa([
            'id' => 'AT-INV-02',
            'module' => 'Account type',
            'scenario' => 'Array injected as account_type does not crash or elevate',
            'pre' => 'None',
            'data' => 'account_type=["trade"]',
            'steps' => 'POST signup with an array account_type',
            'expected' => 'Refused cleanly, no account, no 500',
            'severity' => 'High',
        ], function () {
            $payload = $this->basePayload(['account_type' => ['trade']]);

            $response = $this->signup($payload);

            return [
                $response->status() === 200 && $response->json('status') === 201,
                'http=' . $response->status() . ' status=' . $response->json('status'),
            ];
        });
    }

    public function test_module_account_type_security()
    {
        $this->qa([
            'id' => 'AT-SEC-01',
            'module' => 'Account type',
            'scenario' => 'Signup cannot self-approve trade or self-verify',
            'pre' => 'None',
            'data' => 'trade_status=approved, verified=1, verification_status=verified',
            'steps' => 'POST signup for a trade account with privileged fields injected',
            'expected' => 'trade_status=pending, verified=0, verification=pending, not entitled',
            'severity' => 'Critical',
        ], function () {
            $payload = $this->tradePayload([
                'trade_status' => User::TRADE_APPROVED,
                'verified' => 1,
                'status' => User::STATUS_ACTIVE,
                'verification_status' => User::VERIFICATION_VERIFIED,
            ]);

            $this->signup($payload);

            $user = User::where('email', $payload['email'])->first();

            return [
                $user
                    && $user->trade_status === User::TRADE_PENDING
                    && (int)$user->verified === 0
                    && $user->verification_status === User::VERIFICATION_PENDING
                    && !$user->hasBusinessPricing(),
                $user ? "trade_status={$user->trade_status} verified={$user->verified} "
                    . "verification={$user->verification_status} entitled="
                    . ($user->hasBusinessPricing() ? 'yes' : 'no') : 'not created',
            ];
        });

        $this->qa([
            'id' => 'AT-SEC-02',
            'module' => 'Account type',
            'scenario' => 'Personal signup carrying stale trade fields creates no trade profile',
            'pre' => 'Customer switched Trade -> Personal before submitting; form posts whole model',
            'data' => 'account_type=personal + company_name, trade, vat_number, resale_confirmed',
            'steps' => 'POST signup as personal with the trade block still attached',
            'expected' => 'personal account, no user_business_profiles row, trade_status null',
            'severity' => 'High',
        ], function () {
            $payload = $this->basePayload([
                'account_type' => 'personal',
                'company_name' => 'Left Over Ltd',
                'trade' => 'Electrician',
                'vat_number' => 'IE1234567X',
                'resale_confirmed' => 1,
                'sector' => 'IT',
            ]);

            $this->signup($payload);

            $user = User::where('email', $payload['email'])->first();

            return [
                $user && $user->account_type === 'personal'
                    && is_null($user->trade_status)
                    && is_null($user->businessProfile),
                $user ? "type={$user->account_type} trade_status="
                    . var_export($user->trade_status, true)
                    . ' profile=' . ($user->businessProfile ? 'present' : 'none') : 'not created',
            ];
        });

        $this->qa([
            'id' => 'AT-SEC-03',
            'module' => 'Account type',
            'scenario' => 'Trade signup does not persist business-only fields',
            'pre' => 'Customer switched Business -> Trade, business fields still posted',
            'data' => 'account_type=trade + sector, requires_po, credit_application',
            'steps' => 'POST signup as trade with the business block attached',
            'expected' => 'sector null; requires_po and credit_application false',
            'severity' => 'Medium',
        ], function () {
            $payload = $this->tradePayload([
                'sector' => 'Should not stick',
                'requires_po' => 1,
                'credit_application' => 1,
            ]);

            $this->signup($payload);

            $profile = User::where('email', $payload['email'])->first()->businessProfile;

            return [
                $profile && is_null($profile->sector)
                    && !$profile->requires_po && !$profile->credit_application,
                $profile ? 'sector=' . var_export($profile->sector, true)
                    . ' requires_po=' . var_export($profile->requires_po, true)
                    . ' credit=' . var_export($profile->credit_application, true) : 'no profile',
            ];
        });

        $this->qa([
            'id' => 'AT-SEC-04',
            'module' => 'Account type',
            'scenario' => 'Signed-in customer cannot change their own account type',
            'pre' => 'Active personal account',
            'data' => 'update-profile with account_type=business, trade_status=approved',
            'steps' => 'Authenticate as customer, POST /api/v1/user/update-profile',
            'expected' => 'account_type unchanged, not entitled to business pricing',
            'severity' => 'Critical',
        ], function () {
            $user = $this->customer();

            $this->asCustomer($user);

            $this->postJson('/api/v1/user/update-profile', [
                'name' => 'QA Customer',
                'phone' => $user->phone,
                'account_type' => 'business',
                'trade_status' => User::TRADE_APPROVED,
            ]);

            $fresh = $user->fresh();

            return [
                $fresh->account_type === 'personal' && !$fresh->hasBusinessPricing(),
                "type={$fresh->account_type} entitled="
                    . ($fresh->hasBusinessPricing() ? 'yes' : 'no'),
            ];
        });
    }

    /* ============================================================
     | 3/4. BUSINESS AND TRADE REGISTRATION VALIDATION
     * ========================================================== */

    public function test_module_registration_validation()
    {
        /*
         * Each row: id, what is wrong with the payload, which field must be
         * reported. A null field means "must be accepted".
         */
        $cases = [
            ['REG-B-01', 'Valid business registration', 'business', [], null, 'High'],
            ['REG-B-02', 'Business with no company name', 'business', ['company_name' => ''], 'company_name', 'High'],
            ['REG-B-03', 'Business with no sector', 'business', ['sector' => ''], 'sector', 'Medium'],
            ['REG-B-04', 'Business with no billing address', 'business', ['billing_address' => ''], 'billing_address', 'High'],
            ['REG-B-05', 'Business with malformed email', 'business', ['email' => 'not-an-email'], 'email', 'High'],
            ['REG-B-06', 'Business with no phone', 'business', ['phone' => ''], 'phone', 'Medium'],
            ['REG-B-07', 'Business with terms unticked', 'business', ['terms' => 0], 'terms', 'Medium'],
            ['REG-B-08', 'Business password too short', 'business', ['password' => 'Ab1'], 'password', 'High'],
            ['REG-B-09', 'Business password with no capital', 'business', ['password' => 'abcdefghij1'], 'password', 'High'],
            ['REG-B-10', 'Business password with no digit', 'business', ['password' => 'Abcdefghijk'], 'password', 'High'],
            ['REG-B-11', 'Business VAT is free text', 'business', ['vat_number' => 'will send later'], 'vat_number', 'High'],
            ['REG-B-12', 'Business VAT is n/a', 'business', ['vat_number' => 'n/a'], 'vat_number', 'High'],
            ['REG-B-13', 'Business VAT valid with spaces', 'business', ['vat_number' => 'IE 1234567 FA'], null, 'Medium'],
            ['REG-B-14', 'Business CRO is letters', 'business', ['cro_number' => 'ABC123'], 'cro_number', 'Medium'],
            ['REG-B-15', 'Business CRO valid with spaces', 'business', ['cro_number' => '123 456'], null, 'Low'],
            ['REG-B-16', 'Business website malformed', 'business', ['website' => 'nope'], 'website', 'Low'],
            ['REG-B-17', 'Business VAT omitted entirely', 'business', [], null, 'Medium'],

            ['REG-T-01', 'Valid trade application', 'trade', [], null, 'High'],
            ['REG-T-02', 'Trade with no company name', 'trade', ['company_name' => ''], 'company_name', 'High'],
            ['REG-T-03', 'Trade with no trade category', 'trade', ['trade' => ''], 'trade', 'High'],
            ['REG-T-04', 'Trade with no VAT number', 'trade', ['vat_number' => ''], 'vat_number', 'High'],
            ['REG-T-05', 'Trade with invalid VAT', 'trade', ['vat_number' => '???'], 'vat_number', 'High'],
            ['REG-T-06', 'Trade with no expected spend', 'trade', ['expected_spend' => ''], 'expected_spend', 'Medium'],
            ['REG-T-07', 'Trade without resale confirmation', 'trade', ['resale_confirmed' => 0], 'resale_confirmed', 'High'],
            ['REG-T-08', 'Trade with no business address', 'trade', ['billing_address' => ''], 'billing_address', 'High'],
        ];

        foreach ($cases as list($id, $scenario, $type, $overrides, $field, $severity)) {

            $this->qa([
                'id' => $id,
                'module' => ucfirst($type) . ' registration',
                'scenario' => $scenario,
                'pre' => 'No account on the address',
                'data' => $overrides ? json_encode($overrides) : 'complete valid payload',
                'steps' => 'POST /api/v1/user/signup',
                'expected' => $field
                    ? "Rejected with a validation error on '{$field}'; no account created"
                    : 'Accepted; account created with the right type',
                'severity' => $severity,
            ], function () use ($type, $overrides, $field) {

                $payload = $type === 'business'
                    ? $this->businessPayload($overrides)
                    : $this->tradePayload($overrides);

                $response = $this->signup($payload);

                $status = $response->json('status');
                $fields = array_keys($response->json('data.fields') ?? []);
                $created = User::where('email', $payload['email'])->first();

                if ($field) {
                    return [
                        $status === 201 && in_array($field, $fields, true) && !$created,
                        "status={$status} fields=" . implode(',', $fields)
                            . ' created=' . ($created ? 'yes' : 'no'),
                    ];
                }

                return [
                    $status === 200 && $created && $created->account_type === $type,
                    "status={$status} fields=" . implode(',', $fields)
                        . ' created=' . ($created ? $created->account_type : 'no'),
                ];
            });
        }
    }

    public function test_module_registration_edge_cases()
    {
        $this->qa([
            'id' => 'REG-E-01',
            'module' => 'Registration edge cases',
            'scenario' => 'Duplicate email on a verified account is refused',
            'pre' => 'A verified account already holds the address',
            'data' => 'Existing verified email',
            'steps' => 'POST signup as business using that address',
            'expected' => 'Validation error on email; the existing account is untouched',
            'severity' => 'High',
        ], function () {
            $existing = $this->customer(['account_type' => 'personal']);

            $response = $this->signup($this->businessPayload(['email' => $existing->email]));

            $after = $existing->fresh();

            return [
                $response->json('status') === 201
                    && isset($response->json('data.fields')['email'])
                    && $after->account_type === 'personal',
                'status=' . $response->json('status')
                    . ' existing_type=' . $after->account_type,
            ];
        });

        $this->qa([
            'id' => 'REG-E-02',
            'module' => 'Registration edge cases',
            'scenario' => 'Duplicate VAT number across verified accounts is refused',
            'pre' => 'A verified business account already holds VAT IE5551234Q',
            'data' => 'vat_number=IE5551234Q',
            'steps' => 'POST trade signup with the same VAT number',
            'expected' => 'Validation error on vat_number',
            'severity' => 'High',
        ], function () {
            $existing = $this->customer(['account_type' => 'business'],
                ['company_name' => 'Already Registered Ltd', 'vat_number' => 'IE5551234Q']);

            $response = $this->signup($this->tradePayload(['vat_number' => 'IE5551234Q']));

            return [
                $response->json('status') === 201
                    && isset($response->json('data.fields')['vat_number']),
                'status=' . $response->json('status')
                    . ' fields=' . implode(',', array_keys($response->json('data.fields') ?? [])),
            ];
        });

        $this->qa([
            'id' => 'REG-E-03',
            'module' => 'Registration edge cases',
            'scenario' => 'Duplicate phone on a verified account is refused',
            'pre' => 'A verified account already holds the number',
            'data' => 'Existing verified phone',
            'steps' => 'POST business signup with that phone',
            'expected' => 'Validation error on phone',
            'severity' => 'Medium',
        ], function () {
            $existing = $this->customer();

            $response = $this->signup($this->businessPayload(['phone' => $existing->phone]));

            return [
                isset($response->json('data.fields')['phone']),
                'fields=' . implode(',', array_keys($response->json('data.fields') ?? [])),
            ];
        });

        $this->qa([
            'id' => 'REG-E-04',
            'module' => 'Registration edge cases',
            'scenario' => 'Re-registering an unverified address overwrites it rather than duplicating',
            'pre' => 'An unverified signup exists on the address',
            'data' => 'Same email, this time as trade',
            'steps' => 'POST business signup, then POST trade signup on the same address',
            'expected' => 'One user row, now trade, still unverified',
            'severity' => 'Medium',
            'notes' => 'This is how somebody who lost their code starts again.',
        ], function () {
            $payload = $this->businessPayload();

            $this->signup($payload);
            $this->signup($this->tradePayload(['email' => $payload['email'], 'phone' => $payload['phone']]));

            $rows = User::where('email', $payload['email'])->get();

            return [
                $rows->count() === 1 && $rows->first()->account_type === 'trade'
                    && (int)$rows->first()->verified === 0,
                'rows=' . $rows->count()
                    . ' type=' . ($rows->first()->account_type ?? '-')
                    . ' verified=' . ($rows->first()->verified ?? '-'),
            ];
        });

        $this->qa([
            'id' => 'REG-E-05',
            'module' => 'Registration edge cases',
            'scenario' => 'Suspended account cannot be laundered by re-registering',
            'pre' => 'Unverified account on the address, status suspended',
            'data' => 'Same email',
            'steps' => 'POST business signup on the suspended address',
            'expected' => 'Refused; status stays suspended',
            'severity' => 'Critical',
        ], function () {
            $suspended = $this->customer([
                'account_type' => 'business',
                'verified' => false,
                'status' => User::STATUS_SUSPENDED,
            ]);

            $response = $this->signup($this->businessPayload(['email' => $suspended->email]));

            return [
                $response->json('status') === 201
                    && $suspended->fresh()->status === User::STATUS_SUSPENDED,
                'status=' . $response->json('status')
                    . ' account_status=' . $suspended->fresh()->status,
            ];
        });

        $this->qa([
            'id' => 'REG-E-06',
            'module' => 'Registration edge cases',
            'scenario' => 'Double-clicked submit creates one account, not two',
            'pre' => 'None',
            'data' => 'Identical business payload posted twice',
            'steps' => 'POST signup twice with the same payload',
            'expected' => 'Exactly one users row',
            'severity' => 'High',
        ], function () {
            $payload = $this->businessPayload();

            $this->signup($payload);
            $this->signup($payload);

            $count = User::where('email', $payload['email'])->count();

            return [$count === 1, "user rows={$count}"];
        });
    }

    public function test_module_registration_input_hygiene()
    {
        $long = str_repeat('A', 300);

        $rows = [
            ['REG-H-01', 'Very long company name', ['company_name' => $long], 'Low'],
            ['REG-H-02', 'Company name with special characters', ['company_name' => "O'Brien & Sons (Kilcock) Ltd — #1"], 'Low'],
            ['REG-H-03', 'Leading and trailing spaces on company name', ['company_name' => '   Spaced Ltd   '], 'Low'],
            ['REG-H-04', 'Unicode company name', ['company_name' => 'Ó Briain Teoranta 日本'], 'Low'],
            ['REG-H-05', 'HTML injected into company name', ['company_name' => '<script>alert(1)</script>'], 'Medium'],
            ['REG-H-06', 'Very long billing address', ['billing_address' => $long . ' ' . $long], 'Low'],
        ];

        foreach ($rows as list($id, $scenario, $overrides, $severity)) {

            $this->qa([
                'id' => $id,
                'module' => 'Business registration',
                'scenario' => $scenario,
                'pre' => 'None',
                'data' => json_encode(array_map(function ($v) {
                    return is_string($v) && strlen($v) > 60 ? substr($v, 0, 57) . '...' : $v;
                }, $overrides)),
                'steps' => 'POST /api/v1/user/signup as business',
                'expected' => 'Either accepted and stored without corruption, or rejected cleanly. No 500.',
                'severity' => $severity,
            ], function () use ($overrides) {

                $payload = $this->businessPayload($overrides);

                $response = $this->signup($payload);

                if ($response->status() !== 200) {
                    return [false, 'HTTP ' . $response->status()];
                }

                $user = User::where('email', $payload['email'])->first();

                if (!$user) {
                    return [
                        $response->json('status') === 201,
                        'rejected cleanly, status=' . $response->json('status'),
                    ];
                }

                $stored = $user->businessProfile ? $user->businessProfile->company_name : null;

                return [
                    true,
                    'accepted; stored company_name=' . var_export(
                        is_string($stored) && strlen($stored) > 60
                            ? substr($stored, 0, 57) . '...' : $stored, true),
                ];
            });
        }

        $this->qa([
            'id' => 'REG-H-07',
            'module' => 'Business registration',
            'scenario' => 'Whitespace-only company name is not accepted as a name',
            'pre' => 'None',
            'data' => "company_name='   '",
            'steps' => 'POST signup as business',
            'expected' => 'Rejected on company_name',
            'severity' => 'Medium',
            'notes' => 'TrimStrings middleware should reduce this to empty and required should fire.',
        ], function () {
            $response = $this->signup($this->businessPayload(['company_name' => '   ']));

            return [
                isset($response->json('data.fields')['company_name']),
                'fields=' . implode(',', array_keys($response->json('data.fields') ?? [])),
            ];
        });

        $this->qa([
            'id' => 'REG-H-08',
            'module' => 'Business registration',
            'scenario' => 'Email with surrounding whitespace is normalised, not rejected',
            'pre' => 'None',
            'data' => "email='  name@example.test  '",
            'steps' => 'POST signup as business',
            'expected' => 'Accepted, stored trimmed',
            'severity' => 'Low',
        ], function () {
            $email = 'qa.' . uniqid() . '@example.test';

            $response = $this->signup($this->businessPayload(['email' => '  ' . $email . '  ']));

            $user = User::where('email', $email)->first();

            return [
                $response->json('status') === 200 && $user !== null,
                'status=' . $response->json('status') . ' stored=' . ($user ? 'yes' : 'no'),
            ];
        });
    }

    /* ============================================================
     | 10. PRICING ACROSS ACCOUNT TYPES
     * ========================================================== */

    /** The six account states under test, as fresh customers. */
    protected function pricingCohort()
    {
        return [
            'Personal' => $this->customer(),
            'Business' => $this->customer(['account_type' => 'business',
                'verification_status' => User::VERIFICATION_PENDING], true),
            'Trade pending' => $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true),
            'Trade approved' => $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_APPROVED], true),
            'Trade rejected' => $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_REJECTED], true),
            'Trade suspended' => $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_SUSPENDED], true),
            'Account suspended' => $this->customer(['account_type' => 'business',
                'status' => User::STATUS_SUSPENDED], true),
        ];
    }

    public function test_module_pricing_by_account_type()
    {
        $product = $this->bandedProduct();

        if (!$product) {
            $this->blocked([
                'id' => 'PRC-00', 'module' => 'Pricing',
                'scenario' => 'Banded product fixture',
                'severity' => 'High',
            ], 'Seeded banded product 99240288 is not present in this database.');

            return;
        }

        $entitled = ['Business', 'Trade approved'];

        $cohort = $this->pricingCohort();

        // Band 5-10 on this product: wholesale 500 less 50 fixed = 450.
        $bandPriceAtFive = 450.0;
        $retail = round((float)$product->selling, 2);

        foreach ($cohort as $label => $user) {

            $shouldBeEntitled = in_array($label, $entitled, true);

            $this->qa([
                'id' => 'PRC-ENT-' . strtoupper(substr(md5($label), 0, 4)),
                'module' => 'Pricing',
                'scenario' => "Entitlement flag for {$label}",
                'pre' => "Customer in state: {$label}",
                'data' => 'User::hasBusinessPricing()',
                'steps' => 'Read the entitlement the whole pricing path asks',
                'expected' => $shouldBeEntitled ? 'true' : 'false',
                'severity' => 'Critical',
            ], function () use ($user, $shouldBeEntitled) {
                $actual = $user->fresh()->hasBusinessPricing();

                return [$actual === $shouldBeEntitled, 'hasBusinessPricing=' . var_export($actual, true)];
            });

            $this->qa([
                'id' => 'PRC-PDP-' . strtoupper(substr(md5($label), 0, 4)),
                'module' => 'Pricing',
                'scenario' => "Product page price for {$label}",
                'pre' => "Customer in state: {$label}; product has bands from qty 5",
                'data' => "product={$product->id}, quantity 1",
                'steps' => 'GET /api/v1/product/{id} authenticated as the customer',
                'expected' => 'Retail price, because no band covers quantity 1',
                'severity' => 'High',
                'notes' => 'Bands on this product start at qty 5, so every account sees retail at qty 1.',
            ], function () use ($user, $product, $retail) {
                $this->asCustomer($user);

                $price = $this->detailPrice($product->id);

                return [abs($price - $retail) < 0.005, "display_price={$price} (retail {$retail})"];
            });

            $this->qa([
                'id' => 'PRC-CART-' . strtoupper(substr(md5($label), 0, 4)),
                'module' => 'Pricing',
                'scenario' => "Basket unit price at qty 5 for {$label}",
                'pre' => "Customer in state: {$label}",
                'data' => "product={$product->id}, quantity 5 (band 5-10 = 450.00)",
                'steps' => 'Create a basket line at qty 5, GET /api/v1/cart/by-user',
                'expected' => $shouldBeEntitled ? '450.00' : (string)$retail,
                'severity' => 'Critical',
            ], function () use ($user, $product, $shouldBeEntitled, $bandPriceAtFive, $retail) {
                $price = $this->cartUnitPrice($user, $product, 5);

                $want = $shouldBeEntitled ? $bandPriceAtFive : $retail;

                return [
                    !is_null($price) && abs($price - $want) < 0.005,
                    'unit_price=' . var_export($price, true) . " (expected {$want})",
                ];
            });

            $this->qa([
                'id' => 'PRC-BAND-' . strtoupper(substr(md5($label), 0, 4)),
                'module' => 'Pricing',
                'scenario' => "Advertised bands for {$label}",
                'pre' => "Customer in state: {$label}",
                'data' => "GET /api/v1/business-product/{$product->id}",
                'steps' => 'Read the band table the product page renders',
                'expected' => $shouldBeEntitled ? 'Bands returned' : 'Empty list',
                'severity' => 'High',
            ], function () use ($user, $product, $shouldBeEntitled) {
                $bands = $this->bandsFor($user, $product->id);

                $has = is_array($bands) && count($bands) > 0;

                return [$has === $shouldBeEntitled, 'bands=' . count((array)$bands)];
            });
        }
    }

    /* ============================================================
     | 11. QUANTITY BAND BOUNDARIES
     * ========================================================== */

    public function test_module_quantity_bands()
    {
        $product = $this->bandedProduct();

        if (!$product) {
            $this->blocked([
                'id' => 'QTY-00', 'module' => 'Quantity bands',
                'scenario' => 'Banded product fixture', 'severity' => 'High',
            ], 'Seeded banded product 99240288 is not present.');

            return;
        }

        $retail = round((float)$product->selling, 2);

        /*
         * Bands actually configured on this product:
         *   5-10   wholesale 500 less 50 fixed = 450
         *   11-20  wholesale 500 less 70 fixed = 430
         *   21-30  wholesale 500 less 80 fixed = 420
         * There is deliberately NO band for 1-4 and none above 30, so those
         * quantities must fall back to the retail ladder.
         */
        $expected = [
            1 => $retail, 2 => $retail, 4 => $retail,
            5 => 450.0, 6 => 450.0, 9 => 450.0, 10 => 450.0,
            11 => 430.0, 20 => 430.0,
            21 => 420.0, 30 => 420.0,
            31 => $retail, 50 => $retail, 51 => $retail,
        ];

        $business = $this->customer(['account_type' => 'business'], true);
        $personal = $this->customer();

        foreach ($expected as $qty => $want) {

            $this->qa([
                'id' => 'QTY-B-' . str_pad($qty, 3, '0', STR_PAD_LEFT),
                'module' => 'Quantity bands',
                'scenario' => "Business basket unit price at quantity {$qty}",
                'pre' => 'Business account; product 99240288 with bands 5-10, 11-20, 21-30',
                'data' => "quantity={$qty}",
                'steps' => 'Create the basket line at that quantity, read /api/v1/cart/by-user',
                'expected' => number_format($want, 2) . ($want === $retail ? ' (retail, no band covers it)' : ' (band)'),
                'severity' => 'High',
            ], function () use ($business, $product, $qty, $want) {
                $price = $this->cartUnitPrice($business, $product, $qty);

                return [
                    !is_null($price) && abs($price - $want) < 0.005,
                    'unit_price=' . var_export($price, true) . ' expected=' . $want,
                ];
            });
        }

        foreach ([5, 11, 21, 50] as $qty) {

            $this->qa([
                'id' => 'QTY-P-' . str_pad($qty, 3, '0', STR_PAD_LEFT),
                'module' => 'Quantity bands',
                'scenario' => "Personal account gets no band at quantity {$qty}",
                'pre' => 'Personal account; same banded product',
                'data' => "quantity={$qty}",
                'steps' => 'Create the basket line, read /api/v1/cart/by-user',
                'expected' => number_format($retail, 2) . ' at every quantity',
                'severity' => 'Critical',
                'notes' => 'Buying in bulk must not be a route to trade rates.',
            ], function () use ($personal, $product, $qty, $retail) {
                $price = $this->cartUnitPrice($personal, $product, $qty);

                return [
                    !is_null($price) && abs($price - $retail) < 0.005,
                    'unit_price=' . var_export($price, true) . ' expected=' . $retail,
                ];
            });
        }

        $this->qa([
            'id' => 'QTY-DIR-01',
            'module' => 'Quantity bands',
            'scenario' => 'Increasing then decreasing quantity re-prices both ways',
            'pre' => 'Business account, one basket line',
            'data' => 'qty 1 -> 5 -> 1 (the seeded variant holds 6 units, no backorder)',
            'steps' => 'POST /api/v1/cart/action with deltas, reading the stored price each time',
            'expected' => 'retail at 1, 450.00 at 5, retail again at 1',
            'severity' => 'High',
        ], function () use ($business, $product, $retail) {
            $this->asCustomer($business);

            $inventory = $this->inventoryFor($product);

            $seen = [];

            foreach ([1 => $retail, 4 => 450.0, -4 => $retail] as $delta => $want) {
                $this->postJson('/api/v1/cart/action', [
                    'product_id' => $product->id,
                    'inventory_id' => $inventory->id,
                    'quantity' => $delta,
                ]);

                $row = Cart::where('user_id', $business->id)
                    ->where('product_id', $product->id)->first();

                $seen[] = ($row ? $row->quantity : '?') . '=>' . ($row ? round($row->price, 2) : '?');
            }

            Cart::where('user_id', $business->id)->delete();

            return [
                $seen === ['1=>' . $retail, '5=>450', '1=>' . $retail],
                implode(' ', $seen),
            ];
        });
    }

    /* ============================================================
     | 12. CART REPRICING
     * ========================================================== */

    protected function lineIn($user, $product, $qty)
    {
        return Cart::create([
            'product_id' => $product->id,
            'inventory_id' => $this->inventoryFor($product)->id,
            'user_id' => $user->id,
            'quantity' => $qty,
            'selected' => 1,
            'shipping_type' => 1,
            'price' => $product->selling,
            'original_price' => $product->selling,
        ]);
    }

    protected function readLine($user, $cartId)
    {
        $this->asCustomer($user);

        $line = collect($this->getJson('/api/v1/cart/by-user')->json('data'))
            ->firstWhere('id', $cartId);

        return $line ? (float)$line['unit_price'] : null;
    }

    public function test_module_cart_repricing_a_to_c()
    {
        $product = $this->bandedProduct();
        $retail = round((float)$product->selling, 2);

        $this->qa([
            'id' => 'CART-A-01',
            'module' => 'Cart repricing',
            'scenario' => 'Scenario A: retail basket re-prices when the account gains entitlement',
            'pre' => 'Personal account with a qty-5 line priced at retail',
            'data' => 'qty 5, then account_type becomes business',
            'steps' => 'Create line, change account type in the database, re-read the basket',
            'expected' => 'Unit price moves from retail to the 450.00 band',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $user = $this->customer();
            $cart = $this->lineIn($user, $product, 5);

            $before = $this->readLine($user, $cart->id);

            User::where('id', $user->id)->update(['account_type' => 'business']);

            $after = $this->readLine($user, $cart->id);

            return [
                abs($before - $retail) < 0.005 && abs($after - 450.0) < 0.005,
                "before={$before} after={$after}",
            ];
        });

        $this->qa([
            'id' => 'CART-B-01',
            'module' => 'Cart repricing',
            'scenario' => 'Scenario B: guest basket handed to a business account re-prices',
            'pre' => 'Guest basket at retail, merged onto a business account at sign-in',
            'data' => 'qty 5 guest line, user_token cleared and user_id set',
            'steps' => 'Build the guest line, perform the merge, re-read the basket',
            'expected' => '450.00, and carts.price updated in the database',
            'severity' => 'Critical',
        ], function () use ($product) {
            $token = (string)Str::uuid();
            GuestUser::create(['user_token' => $token]);

            $cart = Cart::create([
                'product_id' => $product->id,
                'inventory_id' => $this->inventoryFor($product)->id,
                'user_token' => $token,
                'quantity' => 5,
                'selected' => 1,
                'shipping_type' => 1,
                'price' => $product->selling,
                'original_price' => $product->selling,
            ]);

            $business = $this->customer(['account_type' => 'business'], true);

            $cart->update(['user_id' => $business->id, 'user_token' => null]);

            $price = $this->readLine($business, $cart->id);
            $stored = round((float)$cart->fresh()->price, 2);

            return [
                abs($price - 450.0) < 0.005 && abs($stored - 450.0) < 0.005,
                "unit_price={$price} carts.price={$stored}",
            ];
        });

        $this->qa([
            'id' => 'CART-C-01',
            'module' => 'Cart repricing',
            'scenario' => 'Scenario C: basket re-prices when the trade application is approved',
            'pre' => 'Trade pending account with a qty-5 line',
            'data' => 'Trade status moves pending -> approved',
            'steps' => 'Create line, approve, re-read the basket',
            'expected' => 'retail before approval, 450.00 after',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $cart = $this->lineIn($user, $product, 5);

            $before = $this->readLine($user, $cart->id);

            AccountStatus::applyTrade($user, User::TRADE_APPROVED);

            $after = $this->readLine($user, $cart->id);

            return [
                abs($before - $retail) < 0.005 && abs($after - 450.0) < 0.005,
                "before={$before} after={$after}",
            ];
        });
    }

    public function test_module_cart_repricing_suspension()
    {
        $product = $this->bandedProduct();
        $retail = round((float)$product->selling, 2);

        $this->qa([
            'id' => 'CART-D-01',
            'module' => 'Cart repricing',
            'scenario' => 'Scenario D: trade prices leave the basket when trade is suspended',
            'pre' => 'Approved trade account with a qty-5 line at 450.00',
            'data' => 'Trade status moves approved -> suspended',
            'steps' => 'Create line, read it, suspend trade pricing, re-read',
            'expected' => '450.00 then retail; stored price updated too',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_APPROVED], true);

            $cart = $this->lineIn($user, $product, 5);
            $before = $this->readLine($user, $cart->id);

            AccountStatus::applyTrade($user, User::TRADE_SUSPENDED);

            $after = $this->readLine($user, $cart->id);
            $stored = round((float)$cart->fresh()->price, 2);

            return [
                abs($before - 450.0) < 0.005 && abs($after - $retail) < 0.005
                    && abs($stored - $retail) < 0.005,
                "before={$before} after={$after} carts.price={$stored}",
            ];
        });

        $this->qa([
            'id' => 'CART-D-02',
            'module' => 'Cart repricing',
            'scenario' => 'Suspending the whole account also strips trade prices from the basket',
            'pre' => 'Approved trade account with a qty-5 line at 450.00',
            'data' => 'Account status moves active -> suspended',
            'steps' => 'Create line, suspend the account, re-read the basket',
            'expected' => 'Retail price',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_APPROVED], true);

            $cart = $this->lineIn($user, $product, 5);
            $before = $this->readLine($user, $cart->id);

            AccountStatus::applyAccount($user, User::STATUS_SUSPENDED, 'QA suspension');

            $after = $this->readLine($user, $cart->id);

            return [
                abs($before - 450.0) < 0.005 && abs($after - $retail) < 0.005,
                "before={$before} after={$after}",
            ];
        });

        $this->qa([
            'id' => 'CART-D-03',
            'module' => 'Cart repricing',
            'scenario' => 'Reactivating the account restores trade prices in the basket',
            'pre' => 'Suspended approved-trade account with a qty-5 line',
            'data' => 'Account status suspended -> active',
            'steps' => 'Read while suspended, reactivate, re-read',
            'expected' => 'retail while suspended, 450.00 once active again',
            'severity' => 'High',
        ], function () use ($product, $retail) {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_APPROVED,
                'status' => User::STATUS_SUSPENDED], true);

            $cart = $this->lineIn($user, $product, 5);
            $while = $this->readLine($user, $cart->id);

            AccountStatus::applyAccount($user, User::STATUS_ACTIVE, 'QA reinstatement');

            $after = $this->readLine($user, $cart->id);

            return [
                abs($while - $retail) < 0.005 && abs($after - 450.0) < 0.005,
                "suspended={$while} reactivated={$after}",
            ];
        });
    }

    public function test_module_cart_price_authority()
    {
        $product = $this->bandedProduct();
        $retail = round((float)$product->selling, 2);

        $this->qa([
            'id' => 'CART-E-01',
            'module' => 'Cart repricing',
            'scenario' => 'Scenario E: catalogue price change is picked up by an existing basket',
            'pre' => 'Personal account with a line at the old price',
            'data' => 'The chosen variation is re-priced to 777.00 after the line was created',
            'steps' => 'Create line, change the price that governs it, re-read the basket',
            'expected' => 'Basket shows 777.00, not the price banked when the line was added',
            'severity' => 'High',
            'notes' => 'The buyable rows on this product are variations carrying their own '
                . 'price, and a variant price outranks products.selling by design, so the '
                . 'variant is the figure to move here.',
        ], function () use ($product) {
            $user = $this->customer();
            $inventory = $this->inventoryFor($product);
            $cart = $this->lineIn($user, $product, 1);

            $before = $this->readLine($user, $cart->id);

            $originalVariant = $inventory->price;

            UpdatedInventory::where('id', $inventory->id)->update(['price' => 777.00]);

            $after = $this->readLine($user, $cart->id);

            UpdatedInventory::where('id', $inventory->id)->update(['price' => $originalVariant]);

            return [abs($after - 777.00) < 0.005, "before={$before} after={$after}"];
        });

        $this->qa([
            'id' => 'CART-SEC-01',
            'module' => 'Cart repricing',
            'scenario' => 'A hand-edited basket row is corrected on read',
            'pre' => 'Personal account; carts.price tampered to 0.01',
            'data' => 'Direct UPDATE on carts.price and original_price',
            'steps' => 'Write 0.01 into the row, read the basket through the API',
            'expected' => 'Retail price returned and written back to the row',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $user = $this->customer();
            $cart = $this->lineIn($user, $product, 1);

            Cart::where('id', $cart->id)->update(['price' => 0.01, 'original_price' => 0.01]);

            $price = $this->readLine($user, $cart->id);
            $stored = round((float)$cart->fresh()->price, 2);

            return [
                abs($price - $retail) < 0.005 && abs($stored - $retail) < 0.005,
                "unit_price={$price} carts.price={$stored}",
            ];
        });

        $this->qa([
            'id' => 'CART-SEC-02',
            'module' => 'Cart repricing',
            'scenario' => 'A price submitted with add-to-basket is ignored',
            'pre' => 'Personal account',
            'data' => 'POST /api/v1/cart/action with price=0.01',
            'steps' => 'Add to basket sending a price, read the stored row',
            'expected' => 'Stored price is the catalogue price',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $user = $this->customer();

            $this->asCustomer($user);

            $this->postJson('/api/v1/cart/action', [
                'product_id' => $product->id,
                'inventory_id' => $this->inventoryFor($product)->id,
                'quantity' => 1,
                'price' => 0.01,
                'original_price' => 0.01,
            ]);

            $row = Cart::where('user_id', $user->id)->first();

            return [
                $row && abs((float)$row->price - $retail) < 0.005,
                'carts.price=' . ($row ? $row->price : 'no row'),
            ];
        });

        $this->qa([
            'id' => 'CART-ISO-01',
            'module' => 'Cart isolation',
            'scenario' => 'Customer A cannot see customer B basket lines',
            'pre' => 'Two customers, each with a basket line',
            'data' => 'Two accounts, one product',
            'steps' => 'Read the basket as A and check B line id is absent',
            'expected' => 'Only the caller own lines are returned',
            'severity' => 'Critical',
        ], function () use ($product) {
            $a = $this->customer();
            $b = $this->customer(['account_type' => 'business'], true);

            $lineA = $this->lineIn($a, $product, 1);
            $lineB = $this->lineIn($b, $product, 5);

            $this->asCustomer($a);

            $ids = collect($this->getJson('/api/v1/cart/by-user')->json('data'))->pluck('id');

            return [
                $ids->contains($lineA->id) && !$ids->contains($lineB->id),
                'ids=' . $ids->implode(','),
            ];
        });
    }

    /* ============================================================
     | 14. VAT / TAX
     * ========================================================== */

    public function test_module_tax_rules()
    {
        $rule23 = TaxRules::find(1);
        $rule0 = TaxRules::find(2);

        $this->qa([
            'id' => 'TAX-01',
            'module' => 'Tax',
            'scenario' => 'VAT-inclusive product adds no tax on top, for any account type',
            'pre' => 'Product with excludeVAT = 0',
            'data' => 'rule 1 (23%, percentage), unit 100.00, excludeVAT=0',
            'steps' => 'Pricing::unitTax with productTaxable false',
            'expected' => '0.00',
            'severity' => 'High',
            'notes' => 'Existing store behaviour recorded as TAX-01 in the earlier audit; unchanged.',
        ], function () use ($rule23) {
            $tax = Pricing::unitTax($rule23, 100.0, false);

            return [abs($tax) < 0.005, "unitTax={$tax}"];
        });

        $this->qa([
            'id' => 'TAX-02',
            'module' => 'Tax',
            'scenario' => 'VAT-exclusive product on rule 1 adds 23 percent',
            'pre' => 'Product with excludeVAT = 1 and tax_rule_id = 1',
            'data' => 'unit 100.00',
            'steps' => 'Pricing::unitTax with productTaxable true',
            'expected' => '23.00',
            'severity' => 'High',
        ], function () use ($rule23) {
            $tax = Pricing::unitTax($rule23, 100.0, true);

            return [abs($tax - 23.0) < 0.005, "unitTax={$tax}"];
        });

        $this->qa([
            'id' => 'TAX-03',
            'module' => 'Tax',
            'scenario' => 'A product on tax rule 2 uses rule 2, not rule 1',
            'pre' => 'tax_rules row 2 = VAT Margin at 0 percent',
            'data' => 'unit 100.00, rule 2, excludeVAT = 1',
            'steps' => 'Pricing::unitTax with the rule the product actually carries',
            'expected' => '0.00, because rule 2 charges 0 percent',
            'severity' => 'High',
            'notes' => 'Guards against assuming tax rule id 1 anywhere in the stack.',
        ], function () use ($rule0) {
            if (!$rule0) {
                return [false, 'tax rule 2 not present in this database'];
            }

            $tax = Pricing::unitTax($rule0, 100.0, true);

            return [abs($tax) < 0.005, "rule2 rate={$rule0->price} unitTax={$tax}"];
        });

        $this->qa([
            'id' => 'TAX-04',
            'module' => 'Tax',
            'scenario' => 'VAT is charged on the band price, not the retail price',
            'pre' => 'Business account buying into a band',
            'data' => 'retail 100.00, band 60.00 at qty 10, rule 1 at 23 percent',
            'steps' => 'Price the unit with bands, then compute tax on that figure',
            'expected' => '13.80, not 23.00',
            'severity' => 'Critical',
        ], function () use ($rule23) {
            $product = (object)['selling' => 100.0, 'offered' => 0.0, 'price' => null, 'end_time' => null];

            $tier = new BusinessProductPricing();
            $tier->forceFill([
                'min_qty' => 10, 'max_qty' => 0, 'wholesale_price' => 60.0,
                'discount_type' => 'fixed', 'discount_value' => 0,
            ]);

            $unit = Pricing::unitPrice($product, null, 10, [$tier]);
            $tax = Pricing::unitTax($rule23, $unit, true);

            return [abs($tax - 13.8) < 0.005, "unit={$unit} unitTax={$tax}"];
        });

        $this->qa([
            'id' => 'TAX-05',
            'module' => 'Tax',
            'scenario' => 'A flat tax rule does not scale with the band price',
            'pre' => 'A tax rule of type FLAT',
            'data' => 'flat 5.00 against unit 100.00 and unit 60.00',
            'steps' => 'Pricing::unitTax on both unit prices',
            'expected' => '5.00 in both cases',
            'severity' => 'Medium',
        ], function () {
            $flat = (object)['price' => 5, 'type' => (int)config('constants.priceType.FLAT')];

            $a = Pricing::unitTax($flat, 100.0, true);
            $b = Pricing::unitTax($flat, 60.0, true);

            return [abs($a - 5.0) < 0.005 && abs($b - 5.0) < 0.005, "at100={$a} at60={$b}"];
        });

        $this->qa([
            'id' => 'TAX-06',
            'module' => 'Tax',
            'scenario' => 'Missing tax rule yields no tax rather than an error',
            'pre' => 'Product whose tax_rules relation is null',
            'data' => 'taxRule = null, unit 100.00',
            'steps' => 'Pricing::unitTax(null, 100, true)',
            'expected' => '0.00 and no exception',
            'severity' => 'Medium',
        ], function () {
            $tax = Pricing::unitTax(null, 100.0, true);

            return [abs($tax) < 0.005, "unitTax={$tax}"];
        });

        $this->qa([
            'id' => 'TAX-07',
            'module' => 'Tax',
            'scenario' => 'Free bundle units are not taxed',
            'pre' => 'A buy-X-get-Y bundle on the line',
            'data' => 'qty 3, bundle buy 2 free 1, unit 100.00, rule 1',
            'steps' => 'bundleFreeQuantity then tax on the payable quantity',
            'expected' => 'Tax charged on 2 units (46.00), not 3',
            'severity' => 'High',
        ], function () use ($rule23) {
            $product = (object)[
                'selling' => 100.0, 'offered' => 0.0, 'price' => null, 'end_time' => null,
                'bundle_deal' => (object)['buy' => 2, 'free' => 1],
            ];

            $free = Pricing::bundleFreeQuantity($product, 3);
            $payable = 3 - $free;
            $total = Pricing::round(Pricing::unitTax($rule23, 100.0, true) * $payable);

            return [$free === 1 && abs($total - 46.0) < 0.005, "free={$free} payable={$payable} tax={$total}"];
        });
    }

    /* ============================================================
     | 5-9. TRADE LIFECYCLE AND NOTIFICATIONS
     * ========================================================== */

    public function test_module_trade_approval()
    {
        $product = $this->bandedProduct();

        $this->qa([
            'id' => 'TRD-APP-01',
            'module' => 'Trade approval',
            'scenario' => 'Authorised admin approves a pending application end to end',
            'pre' => 'Trade account, trade_status = pending, with a company profile',
            'data' => 'POST admin/trade-application/action with trade_status=approved',
            'steps' => 'Approve, then read status, entitlement, bands, dashboard and outbox',
            'expected' => 'approved, entitled, bands visible, dashboard approved, one email',
            'severity' => 'Critical',
        ], function () use ($product) {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING,
                'verification_status' => User::VERIFICATION_PENDING], true);

            $response = $this->review($user, User::TRADE_APPROVED, 'VAT certificate checked.');

            $fresh = $user->fresh();
            $account = $this->dashboard($user);
            $bands = $this->bandsFor($user, $product->id);
            $mails = $this->outbox();

            $ok = $response->json('status') === 200
                && $fresh->trade_status === User::TRADE_APPROVED
                && $fresh->verification_status === User::VERIFICATION_VERIFIED
                && $fresh->hasBusinessPricing()
                && $account['trade_status'] === 'approved'
                && $account['has_business_pricing'] === true
                && count($bands) > 0
                && count($mails) === 1;

            return [$ok, "http_status=" . $response->json('status')
                . " trade_status={$fresh->trade_status}"
                . " verification={$fresh->verification_status}"
                . ' entitled=' . var_export($fresh->hasBusinessPricing(), true)
                . " dashboard={$account['trade_status']}"
                . ' bands=' . count($bands)
                . ' emails=' . count($mails)];
        });

        $this->qa([
            'id' => 'TRD-APP-02',
            'module' => 'Trade approval',
            'scenario' => 'Approval email subject, recipient and content',
            'pre' => 'Pending trade application for Quinn Electrical Ltd',
            'data' => 'Approve with a note',
            'steps' => 'Approve and inspect the rendered message',
            'expected' => 'Subject exactly "Your Trade Account Has Been Approved", to the applicant, naming the company',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING],
                ['company_name' => 'Quinn Electrical Ltd']);

            $this->review($user, User::TRADE_APPROVED, 'Welcome aboard.');

            $mails = $this->outbox();

            if (!count($mails)) {
                return [false, 'no email generated'];
            }

            $m = $mails[0];
            $body = $m->getBody();

            foreach ($m->getChildren() as $c) {
                $body .= "\n" . $c->getBody();
            }

            $ok = $m->getSubject() === 'Your Trade Account Has Been Approved'
                && array_keys($m->getTo()) === [$user->email]
                && strpos($body, 'Quinn Electrical Ltd') !== false
                && strpos($body, 'Welcome aboard.') !== false
                && strpos($body, 'Trade pricing now applies automatically') !== false;

            return [$ok, 'subject=' . $m->getSubject()
                . ' to=' . implode(',', array_keys($m->getTo()))
                . ' company=' . (strpos($body, 'Quinn Electrical Ltd') !== false ? 'Y' : 'N')
                . ' note=' . (strpos($body, 'Welcome aboard.') !== false ? 'Y' : 'N')];
        });

        $this->qa([
            'id' => 'TRD-APP-03',
            'module' => 'Trade approval',
            'scenario' => 'Approval email carries a plain-text alternative',
            'pre' => 'Pending trade application',
            'data' => 'Approve',
            'steps' => 'Inspect the MIME parts of the message',
            'expected' => 'A text/plain child part is present and non-trivial',
            'severity' => 'Low',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_APPROVED);

            $mails = $this->outbox();

            if (!count($mails)) {
                return [false, 'no email'];
            }

            $types = [];

            foreach ($mails[0]->getChildren() as $c) {
                $types[] = $c->getContentType() . ':' . strlen($c->getBody());
            }

            return [
                count(array_filter($types, function ($t) {
                    return strpos($t, 'text/plain') === 0 && (int)explode(':', $t)[1] > 200;
                })) > 0,
                'parts=' . implode(' ', $types),
            ];
        });
    }

    public function test_module_trade_rejection()
    {
        $product = $this->bandedProduct();

        $this->qa([
            'id' => 'TRD-REJ-01',
            'module' => 'Trade rejection',
            'scenario' => 'Rejection updates status, withholds entitlement and emails once',
            'pre' => 'Trade account, trade_status = pending',
            'data' => 'trade_status=rejected with a customer-facing note',
            'steps' => 'Reject, then read status, entitlement, bands, dashboard and outbox',
            'expected' => 'rejected, not entitled, no bands, dashboard rejected, one email, still able to check out',
            'severity' => 'Critical',
        ], function () use ($product) {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_REJECTED, 'We could not match the VAT number you gave us.');

            $fresh = $user->fresh();
            $account = $this->dashboard($user);
            $bands = $this->bandsFor($user, $product->id);
            $mails = $this->outbox();

            $ok = $fresh->trade_status === User::TRADE_REJECTED
                && !$fresh->hasBusinessPricing()
                && $fresh->canCheckout()
                && $account['trade_status'] === 'rejected'
                && $account['has_business_pricing'] === false
                && count($bands) === 0
                && count($mails) === 1;

            return [$ok, "trade_status={$fresh->trade_status}"
                . ' entitled=' . var_export($fresh->hasBusinessPricing(), true)
                . ' can_checkout=' . var_export($fresh->canCheckout(), true)
                . " dashboard={$account['trade_status']}"
                . ' bands=' . count($bands) . ' emails=' . count($mails)];
        });

        $this->qa([
            'id' => 'TRD-REJ-02',
            'module' => 'Trade rejection',
            'scenario' => 'Rejection email subject, reason and support route',
            'pre' => 'Pending application',
            'data' => 'Reject with a customer-safe reason',
            'steps' => 'Reject and inspect the rendered message',
            'expected' => 'Subject exactly "Update on Your Trade Account Application", reason shown, support contact present',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_REJECTED, 'We could not match the VAT number you gave us.');

            $mails = $this->outbox();

            if (!count($mails)) {
                return [false, 'no email generated'];
            }

            $m = $mails[0];
            $body = $m->getBody();

            foreach ($m->getChildren() as $c) {
                $body .= "\n" . $c->getBody();
            }

            $ok = $m->getSubject() === 'Update on Your Trade Account Application'
                && array_keys($m->getTo()) === [$user->email]
                && strpos($body, 'could not match the VAT number') !== false
                && strpos($body, 'Not approved') !== false
                && strpos($body, 'contact us at') !== false
                && strpos($body, 'standard prices') !== false;

            return [$ok, 'subject=' . $m->getSubject()
                . ' reason=' . (strpos($body, 'could not match the VAT number') !== false ? 'Y' : 'N')
                . ' status=' . (strpos($body, 'Not approved') !== false ? 'Y' : 'N')
                . ' support=' . (strpos($body, 'contact us at') !== false ? 'Y' : 'N')];
        });

        $this->qa([
            'id' => 'TRD-REJ-03',
            'module' => 'Trade rejection',
            'scenario' => 'Rejection email exposes no internal identifiers',
            'pre' => 'Pending application with a VAT number on file',
            'data' => 'Reject with a note',
            'steps' => 'Scan the rendered message for ids, column names and the VAT number',
            'expected' => 'No user id, no column names, no VAT number',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING],
                ['company_name' => 'Quinn Electrical Ltd', 'vat_number' => 'IE9998887Z']);

            $this->review($user, User::TRADE_REJECTED, 'VAT number did not match.');

            $mails = $this->outbox();

            if (!count($mails)) {
                return [false, 'no email'];
            }

            $body = $mails[0]->getBody();

            foreach ($mails[0]->getChildren() as $c) {
                $body .= "\n" . $c->getBody();
            }

            $leaks = [];

            foreach (['user_id', 'trade_status', 'verification_status', 'IE9998887Z'] as $needle) {
                if (strpos($body, $needle) !== false) {
                    $leaks[] = $needle;
                }
            }

            return [count($leaks) === 0, $leaks ? 'LEAKED: ' . implode(',', $leaks) : 'no leaks found'];
        });

        $this->qa([
            'id' => 'TRD-REJ-04',
            'module' => 'Trade rejection',
            'scenario' => 'Rejection with no note omits the reason block entirely',
            'pre' => 'Pending application',
            'data' => 'trade_review_note = null',
            'steps' => 'Reject with no note and inspect the message',
            'expected' => 'No empty reason heading; the rest of the email still reads',
            'severity' => 'Low',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_REJECTED, null);

            $mails = $this->outbox();

            if (!count($mails)) {
                return [false, 'no email'];
            }

            $body = $mails[0]->getBody();

            return [
                strpos($body, 'What the reviewer said') === false
                    && strpos($body, 'not able to approve it') !== false,
                'reason_block=' . (strpos($body, 'What the reviewer said') !== false ? 'present' : 'absent'),
            ];
        });
    }

    public function test_module_notification_duplicates()
    {
        $this->qa([
            'id' => 'NOT-DUP-01',
            'module' => 'Notifications',
            'scenario' => 'Repeating the same approval does not re-email',
            'pre' => 'Pending application',
            'data' => 'Approve twice with different notes',
            'steps' => 'POST approve, POST approve again, count the outbox',
            'expected' => 'One email; the second call still succeeds and saves the new note',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_APPROVED, 'First note.');
            $second = $this->review($user, User::TRADE_APPROVED, 'Corrected note.');

            return [
                count($this->outbox()) === 1
                    && $second->json('status') === 200
                    && $second->json('data.notification.triggered') === false
                    && $user->fresh()->trade_review_note === 'Corrected note.',
                'emails=' . count($this->outbox())
                    . ' second_status=' . $second->json('status')
                    . ' triggered=' . var_export($second->json('data.notification.triggered'), true)
                    . ' note=' . $user->fresh()->trade_review_note,
            ];
        });

        $this->qa([
            'id' => 'NOT-DUP-02',
            'module' => 'Notifications',
            'scenario' => 'Reading the queue or the application sends nothing',
            'pre' => 'Application already approved, one email sent',
            'data' => 'GET queue, GET application, GET customer record',
            'steps' => 'Approve, then perform the reads an admin refresh would',
            'expected' => 'Outbox stays at one',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_APPROVED);

            $this->admin();
            $this->getJson('/api/admin/trade-application/all?status=approved&limit=50');
            $this->getJson('/api/admin/trade-application/find/' . $user->id);
            $this->getJson('/api/admin/user/find/' . $user->id);

            return [count($this->outbox()) === 1, 'emails=' . count($this->outbox())];
        });

        $this->qa([
            'id' => 'NOT-DUP-03',
            'module' => 'Notifications',
            'scenario' => 'Repeated rejection does not re-email',
            'pre' => 'Pending application',
            'data' => 'Reject three times',
            'steps' => 'POST reject x3, count the outbox',
            'expected' => 'One email',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_REJECTED);
            $this->review($user, User::TRADE_REJECTED);
            $this->review($user, User::TRADE_REJECTED);

            return [count($this->outbox()) === 1, 'emails=' . count($this->outbox())];
        });

        $this->qa([
            'id' => 'NOT-DUP-04',
            'module' => 'Notifications',
            'scenario' => 'Rejected to pending to approved produces a fresh approval email',
            'pre' => 'Pending application',
            'data' => 'reject, back to pending, approve',
            'steps' => 'Three decisions in sequence, inspect the outbox',
            'expected' => 'Two emails: rejection then approval. Back-to-pending emails nobody.',
            'severity' => 'High',
            'notes' => 'The duplicate rule must not block a legitimate later decision.',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_REJECTED);
            $this->review($user, User::TRADE_PENDING);
            $this->review($user, User::TRADE_APPROVED);

            $subjects = array_map(function ($m) {
                return $m->getSubject();
            }, $this->outbox());

            return [
                $subjects === [
                    'Update on Your Trade Account Application',
                    'Your Trade Account Has Been Approved',
                ],
                'emails=' . count($subjects) . ' subjects=' . implode(' | ', $subjects),
            ];
        });

        $this->qa([
            'id' => 'NOT-DUP-05',
            'module' => 'Notifications',
            'scenario' => 'A losing race reports no transition and sends nothing',
            'pre' => 'Two requests hold the application while it still says pending',
            'data' => 'Row moved to approved between load and write',
            'steps' => 'Load model, update the row underneath it, call applyTrade on the stale model',
            'expected' => 'transitioned = false and the stale note is not written',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $stale = User::find($user->id);

            User::where('id', $user->id)->update(['trade_status' => User::TRADE_APPROVED]);

            $transitioned = null;

            AccountStatus::applyTrade($stale, User::TRADE_APPROVED, 'Second click.', $transitioned);

            return [
                $transitioned === false
                    && $user->fresh()->trade_review_note !== 'Second click.',
                'transitioned=' . var_export($transitioned, true)
                    . ' note=' . var_export($user->fresh()->trade_review_note, true),
            ];
        });
    }

    /**
     * On its own because it mocks the Mail facade, and a Mockery facade mock
     * stands for the rest of the test method -- every later case in the same
     * method then died on getSwiftMailer(). A method boundary gives it a fresh
     * application.
     */
    public function test_module_notification_transport_failure()
    {
        $this->qa([
            'id' => 'NOT-FAIL-01',
            'module' => 'Notification failure',
            'scenario' => 'Mail transport failure does not reverse the approval',
            'pre' => 'Pending application; mailer throws on send',
            'data' => 'Swift_TransportException',
            'steps' => 'Mock Mail::send to throw, approve, read status and entitlement',
            'expected' => 'approved and entitled; response reports delivered=false',
            'severity' => 'Critical',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            Mail::shouldReceive('send')->once()
                ->andThrow(new \Swift_TransportException('Connection refused'));

            $response = $this->review($user, User::TRADE_APPROVED);

            $fresh = $user->fresh();

            return [
                $response->json('status') === 200
                    && $fresh->trade_status === User::TRADE_APPROVED
                    && $fresh->hasBusinessPricing()
                    && $response->json('data.notification.triggered') === true
                    && $response->json('data.notification.delivered') === false,
                'status=' . $response->json('status')
                    . " trade_status={$fresh->trade_status}"
                    . ' entitled=' . var_export($fresh->hasBusinessPricing(), true)
                    . ' delivered=' . var_export($response->json('data.notification.delivered'), true),
            ];
        });

    }

    public function test_module_notification_recipient_failures()
    {
        $this->qa([
            'id' => 'NOT-FAIL-02',
            'module' => 'Notification failure',
            'scenario' => 'Customer with no email address is still approved',
            'pre' => 'Trade application whose users.email is null',
            'data' => 'email = null',
            'steps' => 'Approve and inspect status, entitlement and outbox',
            'expected' => 'approved and entitled; no message handed to the mailer',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING, 'email' => null], true);

            $response = $this->review($user, User::TRADE_APPROVED);

            $fresh = $user->fresh();

            return [
                $fresh->trade_status === User::TRADE_APPROVED
                    && $fresh->hasBusinessPricing()
                    && count($this->outbox()) === 0
                    && $response->json('data.notification.delivered') === false,
                "trade_status={$fresh->trade_status}"
                    . ' emails=' . count($this->outbox())
                    . ' delivered=' . var_export($response->json('data.notification.delivered'), true),
            ];
        });

        $this->qa([
            'id' => 'NOT-FAIL-03',
            'module' => 'Notification failure',
            'scenario' => 'Customer with a malformed email is still rejected',
            'pre' => 'Trade application whose email is not an address',
            'data' => "email = 'not-an-address'",
            'steps' => 'Reject and inspect status and outbox',
            'expected' => 'rejected; nothing handed to the mailer, no exception',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING, 'email' => 'not-an-address'], true);

            $response = $this->review($user, User::TRADE_REJECTED);

            return [
                $user->fresh()->trade_status === User::TRADE_REJECTED
                    && count($this->outbox()) === 0
                    && $response->json('status') === 200,
                'trade_status=' . $user->fresh()->trade_status
                    . ' emails=' . count($this->outbox())
                    . ' status=' . $response->json('status'),
            ];
        });

        $this->qa([
            'id' => 'NOT-FAIL-04',
            'module' => 'Notification failure',
            'scenario' => 'Refused transition emails nobody and writes nothing',
            'pre' => 'Pending application',
            'data' => 'trade_status = suspended (illegal from pending)',
            'steps' => 'POST the illegal decision',
            'expected' => 'Refused, still pending, no email',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $response = $this->review($user, User::TRADE_SUSPENDED);

            return [
                $response->json('status') === 201
                    && $user->fresh()->trade_status === User::TRADE_PENDING
                    && count($this->outbox()) === 0,
                'status=' . $response->json('status')
                    . ' trade_status=' . $user->fresh()->trade_status
                    . ' emails=' . count($this->outbox()),
            ];
        });

        $this->qa([
            'id' => 'NOT-FAIL-05',
            'module' => 'Notification failure',
            'scenario' => 'Decision on a customer that does not exist emails nobody',
            'pre' => 'No such user id',
            'data' => 'id = 99999999',
            'steps' => 'POST approve for a missing id',
            'expected' => 'Handled cleanly, no email, no 500',
            'severity' => 'Medium',
        ], function () {
            $this->admin();

            $response = $this->postJson('/api/admin/trade-application/action/99999999', [
                'trade_status' => User::TRADE_APPROVED,
            ]);

            return [
                $response->status() === 200 && count($this->outbox()) === 0,
                'http=' . $response->status() . ' emails=' . count($this->outbox()),
            ];
        });
    }

    /* ============================================================
     | 22. SECURITY / API MANIPULATION
     * ========================================================== */

    public function test_module_security_privilege()
    {
        $attempts = [
            ['SEC-01', 'Customer approves their own trade application',
                '/api/admin/trade-application/action/%d', ['trade_status' => 'approved'], 'Critical'],
            ['SEC-02', 'Customer rejects a decision on themselves',
                '/api/admin/trade-application/action/%d', ['trade_status' => 'rejected'], 'Critical'],
            ['SEC-03', 'Customer lifts their own account suspension',
                '/api/admin/user/status/%d', ['status' => 'active'], 'Critical'],
            ['SEC-04', 'Customer marks their own details verified',
                '/api/admin/user/verification/%d', ['verification_status' => 'verified'], 'Critical'],
        ];

        foreach ($attempts as list($id, $scenario, $path, $payload, $severity)) {

            $this->qa([
                'id' => $id,
                'module' => 'Security',
                'scenario' => $scenario,
                'pre' => 'Suspended trade account, pending application, authenticated as that customer',
                'data' => json_encode($payload),
                'steps' => 'POST the admin endpoint with only a customer bearer token',
                'expected' => 'Refused; no field changes; no email',
                'severity' => $severity,
            ], function () use ($path, $payload) {
                $user = $this->customer([
                    'account_type' => 'trade',
                    'trade_status' => User::TRADE_PENDING,
                    'status' => User::STATUS_SUSPENDED,
                    'verification_status' => User::VERIFICATION_PENDING,
                ], true);

                $this->asCustomer($user);

                $this->postJson(sprintf($path, $user->id), $payload);

                $fresh = $user->fresh();

                $unchanged = $fresh->trade_status === User::TRADE_PENDING
                    && $fresh->status === User::STATUS_SUSPENDED
                    && $fresh->verification_status === User::VERIFICATION_PENDING
                    && count($this->outbox()) === 0;

                return [$unchanged, "trade_status={$fresh->trade_status}"
                    . " status={$fresh->status}"
                    . " verification={$fresh->verification_status}"
                    . ' emails=' . count($this->outbox())];
            });
        }

        $this->qa([
            'id' => 'SEC-05',
            'module' => 'Security',
            'scenario' => 'Anonymous caller cannot reach the trade review queue',
            'pre' => 'No authentication',
            'data' => 'GET /api/admin/trade-application/all',
            'steps' => 'Call the admin endpoint with no token',
            'expected' => 'Not a 200 payload of applications',
            'severity' => 'Critical',
        ], function () {
            $this->asGuest();

            $response = $this->getJson('/api/admin/trade-application/all');

            return [
                $response->json('status') !== 200,
                'status=' . var_export($response->json('status'), true),
            ];
        });

        $this->qa([
            'id' => 'SEC-06',
            'module' => 'Security',
            'scenario' => 'Customer cannot read another customer trade record',
            'pre' => 'Two customers; A authenticated',
            'data' => 'GET /api/admin/user/find/{B}',
            'steps' => 'Call the admin customer endpoint as customer A',
            'expected' => 'Refused; B company details not returned',
            'severity' => 'Critical',
        ], function () {
            $a = $this->customer();
            $b = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_APPROVED],
                ['company_name' => 'Private Company Ltd']);

            $this->asCustomer($a);

            $response = $this->getJson('/api/admin/user/find/' . $b->id);

            $body = $response->getContent();

            return [
                $response->json('status') !== 200
                    && strpos($body, 'Private Company Ltd') === false,
                'status=' . var_export($response->json('status'), true)
                    . ' leaked=' . (strpos($body, 'Private Company Ltd') !== false ? 'YES' : 'no'),
            ];
        });
    }

    public function test_module_security_entitlement_and_checkout()
    {
        $product = $this->bandedProduct();

        $this->qa([
            'id' => 'SEC-07',
            'module' => 'Security',
            'scenario' => 'Pending trade account cannot obtain bands by calling the endpoint directly',
            'pre' => 'Trade account, application pending',
            'data' => 'GET /api/v1/business-product/{id}',
            'steps' => 'Call the band endpoint with the customer token',
            'expected' => 'Empty pricing list',
            'severity' => 'Critical',
        ], function () use ($product) {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $bands = $this->bandsFor($user, $product->id);

            return [count((array)$bands) === 0, 'bands=' . count((array)$bands)];
        });

        $this->qa([
            'id' => 'SEC-08',
            'module' => 'Security',
            'scenario' => 'Suspended account cannot place an order',
            'pre' => 'Suspended business account',
            'data' => 'POST /api/v1/order/action with a valid encrypted payload',
            'steps' => 'Submit an order as the suspended customer',
            'expected' => 'Refused with the suspension message; no order row',
            'severity' => 'Critical',
        ], function () {
            $user = $this->customer(['account_type' => 'business',
                'status' => User::STATUS_SUSPENDED], true);

            $this->asCustomer($user);

            $response = $this->postJson('/api/v1/order/action', [
                'data' => $this->orderPayload(),
                'order_method' => (int)config('constants.paymentMethod.BANK'),
            ]);

            $orders = \App\Models\Order::where('user_id', $user->id)->count();

            return [
                strpos((string)$response->json('message'), 'cannot place orders') !== false
                    && $orders === 0,
                'message=' . $response->json('message') . ' orders=' . $orders,
            ];
        });

        $this->qa([
            'id' => 'SEC-09',
            'module' => 'Security',
            'scenario' => 'Active account is not stopped by the suspension gate',
            'pre' => 'Active business account',
            'data' => 'Same order payload as SEC-08',
            'steps' => 'Submit an order as an active customer',
            'expected' => 'Refused for some other reason, never the suspension message',
            'severity' => 'High',
            'notes' => 'Mirror of SEC-08: without it the gate could be deleted and SEC-08 would still pass.',
        ], function () {
            $user = $this->customer(['account_type' => 'business'], true);

            $this->asCustomer($user);

            $response = $this->postJson('/api/v1/order/action', [
                'data' => $this->orderPayload(),
                'order_method' => (int)config('constants.paymentMethod.BANK'),
            ]);

            return [
                strpos((string)$response->json('message'), 'cannot place orders') === false,
                'message=' . $response->json('message'),
            ];
        });

        $this->qa([
            'id' => 'SEC-10',
            'module' => 'Security',
            'scenario' => 'Guest is offered no band pricing',
            'pre' => 'No authentication',
            'data' => 'GET /api/v1/business-product/{id}',
            'steps' => 'Call the band endpoint anonymously',
            'expected' => 'Empty pricing list',
            'severity' => 'High',
        ], function () use ($product) {
            $this->asGuest();

            $bands = $this->getJson('/api/v1/business-product/' . $product->id)
                ->json('data.pricing');

            return [count((array)$bands) === 0, 'bands=' . count((array)$bands)];
        });

        $this->qa([
            'id' => 'SEC-11',
            'module' => 'Security',
            'scenario' => 'Internal failure detail is not returned to the caller',
            'pre' => 'None',
            'data' => 'A raw SQL error string passed through Validation::error',
            'steps' => 'Ask the helper every controller catch block uses',
            'expected' => 'Generic message; no SQL, no table names',
            'severity' => 'High',
        ], function () {
            $leak = 'SQLSTATE[23000]: Integrity constraint violation: 1452 '
                . '(SQL: insert into `users` (`email`) values (a@b.c))';

            $message = \App\Models\Helper\Validation::error(null, $leak)->message;

            return [
                $message !== $leak
                    && strpos($message, 'SQL') === false
                    && strpos($message, 'users') === false,
                'message=' . $message,
            ];
        });
    }

    /** The obfuscation wrapper the storefront puts around an order payload. */
    protected function orderPayload(array $params = null)
    {
        // Bank transfer, because it is one of the methods enabled on this
        // install; a disabled method is refused at the gateway check before the
        // request reaches the suspension or purchase-order gates.
        $params = $params ?: ['user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.BANK'),
            'voucher' => '', 'time_zone' => 'Europe/Dublin'];

        $json = json_encode($params);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt($json, 'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210'));
    }

    /* ============================================================
     | 23. STATUS TRANSITIONS
     * ========================================================== */

    public function test_module_transitions()
    {
        $trade = [
            ['pending', 'approved', true, 'High'],
            ['pending', 'rejected', true, 'High'],
            ['pending', 'suspended', false, 'High'],
            ['approved', 'suspended', true, 'High'],
            ['approved', 'rejected', true, 'Medium'],
            ['approved', 'pending', false, 'Medium'],
            ['suspended', 'approved', true, 'High'],
            ['suspended', 'rejected', true, 'Medium'],
            ['suspended', 'pending', false, 'Low'],
            ['rejected', 'pending', true, 'Medium'],
            ['rejected', 'approved', true, 'Medium'],
            ['rejected', 'suspended', false, 'Low'],
        ];

        foreach ($trade as list($from, $to, $legal, $severity)) {

            $this->qa([
                'id' => 'TRN-T-' . strtoupper(substr($from, 0, 3) . substr($to, 0, 3)),
                'module' => 'Status transitions',
                'scenario' => "Trade {$from} -> {$to}",
                'pre' => "Trade account at trade_status={$from}",
                'data' => "trade_status={$to}",
                'steps' => 'AccountStatus::tradeError then apply through the admin API',
                'expected' => $legal ? 'Allowed' : 'Refused with a readable reason',
                'severity' => $severity,
            ], function () use ($from, $to, $legal) {
                $error = AccountStatus::tradeError($from, $to);

                $allowed = is_null($error);

                return [$allowed === $legal, $allowed ? 'allowed' : 'refused: ' . $error];
            });
        }

        $account = [
            ['pending', 'active', true, 'Medium'],
            ['pending', 'rejected', true, 'Medium'],
            ['pending', 'suspended', true, 'Low'],
            ['active', 'suspended', true, 'High'],
            ['active', 'rejected', false, 'Medium'],
            ['active', 'pending', false, 'Low'],
            ['suspended', 'active', true, 'High'],
            ['suspended', 'rejected', false, 'Low'],
            ['rejected', 'active', false, 'High'],
            ['rejected', 'pending', false, 'Medium'],
        ];

        foreach ($account as list($from, $to, $legal, $severity)) {

            $this->qa([
                'id' => 'TRN-A-' . strtoupper(substr($from, 0, 3) . substr($to, 0, 3)),
                'module' => 'Status transitions',
                'scenario' => "Account {$from} -> {$to}",
                'pre' => "Account at status={$from}",
                'data' => "status={$to}",
                'steps' => 'AccountStatus::accountError',
                'expected' => $legal ? 'Allowed' : 'Refused with a readable reason',
                'severity' => $severity,
            ], function () use ($from, $to, $legal) {
                $error = AccountStatus::accountError($from, $to);

                $allowed = is_null($error);

                return [$allowed === $legal, $allowed ? 'allowed' : 'refused: ' . $error];
            });
        }

        $this->qa([
            'id' => 'TRN-X-01',
            'module' => 'Status transitions',
            'scenario' => 'Unknown target status is refused for both ladders',
            'pre' => 'Any account',
            'data' => "to = 'platinum', '', null, array",
            'steps' => 'Ask both ladders',
            'expected' => 'Every one refused with a list of the valid choices',
            'severity' => 'High',
        ], function () {
            $bad = ['platinum', '', null, ['approved']];

            foreach ($bad as $value) {
                if (is_null(AccountStatus::tradeError('pending', $value))
                    || is_null(AccountStatus::accountError('active', $value))) {
                    return [false, 'accepted: ' . var_export($value, true)];
                }
            }

            return [true, 'all four refused'];
        });

        $this->qa([
            'id' => 'TRN-X-02',
            'module' => 'Status transitions',
            'scenario' => 'Re-sending the current status is a no-op, not an error',
            'pre' => 'Approved trade account',
            'data' => 'approved -> approved, active -> active',
            'steps' => 'Ask both ladders for a same-status move',
            'expected' => 'Allowed, because admin UIs double-submit',
            'severity' => 'Medium',
        ], function () {
            return [
                is_null(AccountStatus::tradeError('approved', 'approved'))
                    && is_null(AccountStatus::accountError('active', 'active')),
                'trade=' . var_export(AccountStatus::tradeError('approved', 'approved'), true)
                    . ' account=' . var_export(AccountStatus::accountError('active', 'active'), true),
            ];
        });

        $this->qa([
            'id' => 'TRN-X-03',
            'module' => 'Status transitions',
            'scenario' => 'Legacy NULL trade status is treated as pending and can be approved',
            'pre' => 'Trade account whose trade_status is NULL',
            'data' => 'trade_status = null -> approved',
            'steps' => 'Approve through the admin API and count the email',
            'expected' => 'Approved, one email sent',
            'severity' => 'High',
            'notes' => 'A `= pending` comparison never matches NULL in SQL.',
        ], function () {
            $user = $this->customer(['account_type' => 'trade'], true);

            User::where('id', $user->id)->update(['trade_status' => null]);

            $this->review($user->fresh(), User::TRADE_APPROVED);

            return [
                $user->fresh()->trade_status === User::TRADE_APPROVED
                    && count($this->outbox()) === 1,
                'trade_status=' . $user->fresh()->trade_status
                    . ' emails=' . count($this->outbox()),
            ];
        });
    }

    /* ============================================================
     | 20-21. DASHBOARD AND ADMIN MANAGEMENT
     * ========================================================== */

    public function test_module_dashboard()
    {
        $states = [
            ['DSH-01', 'Personal', [], null, 'personal', 'active', null, false],
            ['DSH-02', 'Business', ['account_type' => 'business'], true, 'business', 'active', null, true],
            ['DSH-03', 'Trade pending', ['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true, 'trade', 'active', 'pending', false],
            ['DSH-04', 'Trade approved', ['account_type' => 'trade',
                'trade_status' => User::TRADE_APPROVED], true, 'trade', 'active', 'approved', true],
            ['DSH-05', 'Trade rejected', ['account_type' => 'trade',
                'trade_status' => User::TRADE_REJECTED], true, 'trade', 'active', 'rejected', false],
            ['DSH-06', 'Trade suspended', ['account_type' => 'trade',
                'trade_status' => User::TRADE_SUSPENDED], true, 'trade', 'active', 'suspended', false],
            ['DSH-07', 'Account suspended', ['account_type' => 'business',
                'status' => User::STATUS_SUSPENDED], true, 'business', 'suspended', null, false],
        ];

        foreach ($states as list($id, $label, $attrs, $profile, $type, $status, $tradeStatus, $entitled)) {

            $this->qa([
                'id' => $id,
                'module' => 'Customer dashboard',
                'scenario' => "Dashboard reports the backend state for {$label}",
                'pre' => "Customer in state: {$label}",
                'data' => 'GET /api/v1/user/profile',
                'steps' => 'Authenticate as the customer and read data.account',
                'expected' => "account_type={$type}, status={$status}, trade_status="
                    . var_export($tradeStatus, true) . ', entitled=' . var_export($entitled, true)
                    . ', plus a headline where one applies',
                'severity' => 'High',
            ], function () use ($attrs, $profile, $type, $status, $tradeStatus, $entitled) {
                $user = $this->customer($attrs, $profile);

                $account = $this->dashboard($user);

                $ok = $account['account_type'] === $type
                    && $account['status'] === $status
                    && $account['trade_status'] === $tradeStatus
                    && $account['has_business_pricing'] === $entitled;

                return [$ok, 'type=' . $account['account_type']
                    . ' status=' . $account['status']
                    . ' trade=' . var_export($account['trade_status'], true)
                    . ' entitled=' . var_export($account['has_business_pricing'], true)
                    . ' headline=' . ($account['headline'] ? 'present' : 'none')];
            });
        }

        $this->qa([
            'id' => 'DSH-08',
            'module' => 'Customer dashboard',
            'scenario' => 'Suspended account is told it cannot order',
            'pre' => 'Suspended business account',
            'data' => 'GET /api/v1/user/profile',
            'steps' => 'Read data.account.can_checkout and the headline',
            'expected' => 'can_checkout false and a headline naming the suspension',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'business',
                'status' => User::STATUS_SUSPENDED], true);

            $account = $this->dashboard($user);

            return [
                $account['can_checkout'] === false
                    && stripos((string)$account['headline'], 'suspended') !== false,
                'can_checkout=' . var_export($account['can_checkout'], true)
                    . ' headline=' . $account['headline'],
            ];
        });

        $this->qa([
            'id' => 'DSH-09',
            'module' => 'Customer dashboard',
            'scenario' => 'Guest profile reports no entitlement but can still check out',
            'pre' => 'Guest token only',
            'data' => 'GET /api/v1/user/profile?user_token=...',
            'steps' => 'Read data.account for a guest',
            'expected' => 'personal, active, not entitled, can_checkout true',
            'severity' => 'Medium',
        ], function () {
            $token = (string)Str::uuid();
            GuestUser::create(['user_token' => $token]);

            $this->asGuest();

            $account = $this->getJson('/api/v1/user/profile?user_token=' . $token)
                ->json('data.account');

            return [
                $account
                    && $account['account_type'] === 'personal'
                    && $account['has_business_pricing'] === false
                    && $account['can_checkout'] === true,
                $account ? 'type=' . $account['account_type']
                    . ' entitled=' . var_export($account['has_business_pricing'], true)
                    . ' can_checkout=' . var_export($account['can_checkout'], true)
                    : 'no account object returned',
            ];
        });

        $this->qa([
            'id' => 'DSH-10',
            'module' => 'Customer dashboard',
            'scenario' => 'Dashboard does not expose the password hash or 2FA secrets',
            'pre' => 'Any signed-in customer',
            'data' => 'GET /api/v1/user/profile',
            'steps' => 'Scan the whole response body',
            'expected' => 'No password, no two_factor_secret, no recovery codes',
            'severity' => 'Critical',
        ], function () {
            $user = $this->customer(['account_type' => 'business'], true);

            $this->asCustomer($user);

            $body = $this->getJson('/api/v1/user/profile')->getContent();

            $leaks = [];

            foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes'] as $needle) {
                if (strpos($body, '"' . $needle . '"') !== false) {
                    $leaks[] = $needle;
                }
            }

            return [count($leaks) === 0, $leaks ? 'LEAKED: ' . implode(',', $leaks) : 'clean'];
        });
    }

    public function test_module_admin_queue()
    {
        $this->qa([
            'id' => 'ADM-01',
            'module' => 'Admin management',
            'scenario' => 'Trade queue defaults to applications still waiting',
            'pre' => 'One pending and one approved trade account',
            'data' => 'GET /api/admin/trade-application/all',
            'steps' => 'Call with no status filter',
            'expected' => 'Pending application listed, approved one absent',
            'severity' => 'Medium',
        ], function () {
            $pending = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);
            $approved = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_APPROVED], true);

            $this->admin();

            $ids = collect($this->getJson('/api/admin/trade-application/all?limit=200')
                ->json('data.data'))->pluck('id');

            return [
                $ids->contains($pending->id) && !$ids->contains($approved->id),
                'pending_listed=' . ($ids->contains($pending->id) ? 'Y' : 'N')
                    . ' approved_listed=' . ($ids->contains($approved->id) ? 'Y' : 'N'),
            ];
        });

        foreach (['approved', 'rejected', 'suspended'] as $status) {

            $this->qa([
                'id' => 'ADM-02-' . strtoupper(substr($status, 0, 3)),
                'module' => 'Admin management',
                'scenario' => "Trade queue can be filtered to {$status}",
                'pre' => "One trade account at trade_status={$status}",
                'data' => "GET /api/admin/trade-application/all?status={$status}",
                'steps' => 'Call with the status filter',
                'expected' => 'The account appears under its own status',
                'severity' => 'Medium',
            ], function () use ($status) {
                $user = $this->customer(['account_type' => 'trade',
                    'trade_status' => $status], true);

                $this->admin();

                $ids = collect($this->getJson("/api/admin/trade-application/all?status={$status}&limit=200")
                    ->json('data.data'))->pluck('id');

                return [$ids->contains($user->id), 'listed=' . ($ids->contains($user->id) ? 'Y' : 'N')];
            });
        }

        $this->qa([
            'id' => 'ADM-03',
            'module' => 'Admin management',
            'scenario' => 'Unknown queue filter is refused rather than ignored',
            'pre' => 'Authenticated admin',
            'data' => 'status=platinum',
            'steps' => 'GET the queue with an invented status',
            'expected' => 'Error response, not a silently unfiltered list',
            'severity' => 'Low',
        ], function () {
            $this->admin();

            $response = $this->getJson('/api/admin/trade-application/all?status=platinum');

            return [
                $response->json('status') === 201,
                'status=' . var_export($response->json('status'), true),
            ];
        });

        $this->qa([
            'id' => 'ADM-04',
            'module' => 'Admin management',
            'scenario' => 'Queue search finds an application by email',
            'pre' => 'A pending trade account',
            'data' => 'q = the customer email',
            'steps' => 'GET the queue with a search term',
            'expected' => 'The application is returned',
            'severity' => 'Low',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->admin();

            $ids = collect($this->getJson('/api/admin/trade-application/all?q='
                . urlencode($user->email) . '&limit=50')->json('data.data'))->pluck('id');

            return [$ids->contains($user->id), 'found=' . ($ids->contains($user->id) ? 'Y' : 'N')];
        });
    }

    public function test_module_admin_records()
    {
        $this->qa([
            'id' => 'ADM-05',
            'module' => 'Admin management',
            'scenario' => 'Admin customer record carries account state and company details',
            'pre' => 'Trade account with a company profile',
            'data' => 'GET /api/admin/user/find/{id}',
            'steps' => 'Read data.account and data.business_profile',
            'expected' => 'Type, status, verification, trade status and the company on file',
            'severity' => 'Medium',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING,
                'verification_status' => User::VERIFICATION_PENDING],
                ['company_name' => 'Quinn Electrical Ltd', 'vat_number' => 'IE1122334D']);

            $this->admin();

            $body = $this->getJson('/api/admin/user/find/' . $user->id);
            $account = $body->json('data.account');

            return [
                $account
                    && $account['account_type'] === 'trade'
                    && $account['trade_status'] === 'pending'
                    && $account['has_business_pricing'] === false
                    && $body->json('data.business_profile.company_name') === 'Quinn Electrical Ltd',
                $account ? 'type=' . $account['account_type']
                    . ' trade=' . $account['trade_status']
                    . ' company=' . $body->json('data.business_profile.company_name')
                    : 'no account object',
            ];
        });

        $this->qa([
            'id' => 'ADM-06',
            'module' => 'Admin management',
            'scenario' => 'Admin customer list can be filtered by account type and status',
            'pre' => 'A suspended business account exists',
            'data' => 'GET /api/admin/user/all?account_type=business&status=suspended',
            'steps' => 'Call the filtered list',
            'expected' => 'The account is listed with its account object',
            'severity' => 'Medium',
        ], function () {
            $user = $this->customer(['account_type' => 'business',
                'status' => User::STATUS_SUSPENDED], true);

            $this->admin();

            $rows = collect($this->getJson('/api/admin/user/all?account_type=business&status=suspended&limit=200')
                ->json('data.data'));

            $row = $rows->firstWhere('id', $user->id);

            return [
                $row && isset($row['account']) && $row['account']['status'] === 'suspended',
                $row ? 'listed with status=' . $row['account']['status'] : 'not listed',
            ];
        });

        $this->qa([
            'id' => 'ADM-07',
            'module' => 'Admin management',
            'scenario' => 'Business verification recorded without changing entitlement',
            'pre' => 'Business account, details unchecked',
            'data' => 'POST /api/admin/user/verification/{id} verified',
            'steps' => 'Mark verified, then read entitlement',
            'expected' => 'verification_status=verified; still entitled',
            'severity' => 'Medium',
        ], function () {
            $user = $this->customer(['account_type' => 'business',
                'verification_status' => User::VERIFICATION_PENDING], true);

            $this->admin();

            $this->postJson('/api/admin/user/verification/' . $user->id,
                ['verification_status' => User::VERIFICATION_VERIFIED]);

            $fresh = $user->fresh();

            return [
                $fresh->verification_status === User::VERIFICATION_VERIFIED
                    && $fresh->hasBusinessPricing(),
                "verification={$fresh->verification_status} entitled="
                    . var_export($fresh->hasBusinessPricing(), true),
            ];
        });

        $this->qa([
            'id' => 'ADM-08',
            'module' => 'Admin management',
            'scenario' => 'Personal account cannot be marked verified',
            'pre' => 'Personal account',
            'data' => 'POST /api/admin/user/verification/{id} verified',
            'steps' => 'Attempt to verify a personal account',
            'expected' => 'Refused; verification stays not_required',
            'severity' => 'Low',
        ], function () {
            $user = $this->customer();

            $this->admin();

            $response = $this->postJson('/api/admin/user/verification/' . $user->id,
                ['verification_status' => User::VERIFICATION_VERIFIED]);

            return [
                $response->json('status') === 201
                    && $user->fresh()->verification_status === User::VERIFICATION_NOT_REQUIRED,
                'status=' . $response->json('status')
                    . ' verification=' . $user->fresh()->verification_status,
            ];
        });
    }

    public function test_module_admin_status_control()
    {
        $this->qa([
            'id' => 'ADM-09',
            'module' => 'Admin management',
            'scenario' => 'Suspend then reactivate through the admin API',
            'pre' => 'Active approved-trade account',
            'data' => 'POST user/status suspended, then active',
            'steps' => 'Suspend, check entitlement, reactivate, check again',
            'expected' => 'Entitlement off then on; the trade decision is never touched',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_APPROVED], true);

            $this->setAccountStatus($user, User::STATUS_SUSPENDED, 'QA');
            $suspended = $user->fresh();

            $this->setAccountStatus($user, User::STATUS_ACTIVE, 'QA');
            $active = $user->fresh();

            return [
                !$suspended->hasBusinessPricing()
                    && $suspended->trade_status === User::TRADE_APPROVED
                    && $active->hasBusinessPricing()
                    && $active->status === User::STATUS_ACTIVE,
                'suspended_entitled=' . var_export($suspended->hasBusinessPricing(), true)
                    . ' trade_kept=' . $suspended->trade_status
                    . ' reactivated_entitled=' . var_export($active->hasBusinessPricing(), true),
            ];
        });

        $this->qa([
            'id' => 'ADM-10',
            'module' => 'Admin management',
            'scenario' => 'Illegal account transition is refused by the API',
            'pre' => 'Active account',
            'data' => 'POST user/status rejected',
            'steps' => 'Attempt active -> rejected',
            'expected' => 'Refused; status stays active',
            'severity' => 'Medium',
        ], function () {
            $user = $this->customer(['account_type' => 'business'], true);

            $response = $this->setAccountStatus($user, User::STATUS_REJECTED);

            return [
                $response->json('status') === 201
                    && $user->fresh()->status === User::STATUS_ACTIVE,
                'status=' . $response->json('status')
                    . ' account_status=' . $user->fresh()->status,
            ];
        });

        $this->qa([
            'id' => 'ADM-11',
            'module' => 'Admin management',
            'scenario' => 'Suspension note is recorded with a timestamp',
            'pre' => 'Active business account',
            'data' => "status_note = 'Unpaid invoices'",
            'steps' => 'Suspend with a note and read the row',
            'expected' => 'status_note stored and status_changed_at set',
            'severity' => 'Low',
        ], function () {
            $user = $this->customer(['account_type' => 'business'], true);

            $this->setAccountStatus($user, User::STATUS_SUSPENDED, 'Unpaid invoices');

            $fresh = $user->fresh();

            return [
                $fresh->status_note === 'Unpaid invoices' && !is_null($fresh->status_changed_at),
                'note=' . var_export($fresh->status_note, true)
                    . ' changed_at=' . ($fresh->status_changed_at ? 'set' : 'null'),
            ];
        });
    }

    /* ============================================================
     | 29. END-TO-END MATRIX
     * ========================================================== */

    public function test_module_end_to_end()
    {
        $product = $this->bandedProduct();
        $retail = round((float)$product->selling, 2);

        $this->qa([
            'id' => 'E2E-TRADE-01',
            'module' => 'End to end',
            'scenario' => 'Apply, wait, be approved, be told, and buy at trade rates',
            'pre' => 'No account on the address',
            'data' => 'Full trade signup payload, then admin approval',
            'steps' => 'signup -> verify -> check pending pricing -> queue -> approve -> check everything',
            'expected' => 'pending shows retail and no bands; approved shows bands, 450.00 at qty 5, and one email',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $payload = $this->tradePayload();

            $this->signup($payload);

            $user = User::where('email', $payload['email'])->first();

            if (!$user || $user->trade_status !== User::TRADE_PENDING) {
                return [false, 'signup did not create a pending trade application'];
            }

            User::where('id', $user->id)->update(['verified' => true]);
            $user = $user->fresh();

            Mail::getSwiftMailer()->getTransport()->flush();

            $pendingPrice = $this->cartUnitPrice($user, $product, 5);
            $pendingBands = count((array)$this->bandsFor($user, $product->id));

            $this->admin();

            $queued = collect($this->getJson('/api/admin/trade-application/all?status=pending&limit=200')
                ->json('data.data'))->pluck('id')->contains($user->id);

            $this->review($user, User::TRADE_APPROVED, 'VAT certificate checked.');

            $approvedPrice = $this->cartUnitPrice($user, $product, 5);
            $approvedBands = count((array)$this->bandsFor($user, $product->id));
            $account = $this->dashboard($user);
            $mails = $this->outbox();

            $ok = abs($pendingPrice - $retail) < 0.005
                && $pendingBands === 0
                && $queued
                && abs($approvedPrice - 450.0) < 0.005
                && $approvedBands > 0
                && $account['trade_status'] === 'approved'
                && count($mails) === 1
                && $mails[0]->getSubject() === 'Your Trade Account Has Been Approved';

            return [$ok, "pending_price={$pendingPrice} pending_bands={$pendingBands}"
                . ' in_queue=' . ($queued ? 'Y' : 'N')
                . " approved_price={$approvedPrice} approved_bands={$approvedBands}"
                . ' dashboard=' . $account['trade_status']
                . ' emails=' . count($mails)];
        });


        $this->qa([
            'id' => 'E2E-TRADE-02',
            'module' => 'End to end',
            'scenario' => 'Apply, be rejected, be told, and keep buying at retail',
            'pre' => 'Pending trade application',
            'data' => 'Admin rejection with a customer-safe reason',
            'steps' => 'Reject, then check pricing, dashboard, checkout access and the email',
            'expected' => 'retail pricing, no bands, dashboard rejected, one rejection email, can still check out',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $user = $this->customer(['account_type' => 'trade',
                'trade_status' => User::TRADE_PENDING], true);

            $this->review($user, User::TRADE_REJECTED, 'We could not match the VAT number you gave us.');

            $price = $this->cartUnitPrice($user, $product, 5);
            $bands = count((array)$this->bandsFor($user, $product->id));
            $account = $this->dashboard($user);
            $mails = $this->outbox();

            $ok = abs($price - $retail) < 0.005
                && $bands === 0
                && $account['trade_status'] === 'rejected'
                && $user->fresh()->canCheckout()
                && count($mails) === 1
                && $mails[0]->getSubject() === 'Update on Your Trade Account Application';

            return [$ok, "price={$price} bands={$bands}"
                . ' dashboard=' . $account['trade_status']
                . ' can_checkout=' . var_export($user->fresh()->canCheckout(), true)
                . ' emails=' . count($mails)];
        });

        $this->qa([
            'id' => 'E2E-PERSONAL-01',
            'module' => 'End to end',
            'scenario' => 'Personal customer registers and buys at retail throughout',
            'pre' => 'No account on the address',
            'data' => 'Personal signup, then the banded product at qty 1 and qty 50',
            'steps' => 'signup -> verify -> product page -> basket at two quantities',
            'expected' => 'Retail everywhere; no bands at any quantity',
            'severity' => 'Critical',
        ], function () use ($product, $retail) {
            $payload = $this->basePayload();

            $this->signup($payload);

            $user = User::where('email', $payload['email'])->first();

            User::where('id', $user->id)->update(['verified' => true]);
            $user = $user->fresh();

            $this->asCustomer($user);

            $pdp = $this->detailPrice($product->id);
            $one = $this->cartUnitPrice($user, $product, 1);
            $fifty = $this->cartUnitPrice($user, $product, 50);
            $bands = count((array)$this->bandsFor($user, $product->id));

            $ok = $user->account_type === 'personal'
                && abs($pdp - $retail) < 0.005
                && abs($one - $retail) < 0.005
                && abs($fifty - $retail) < 0.005
                && $bands === 0;

            return [$ok, "pdp={$pdp} qty1={$one} qty50={$fifty} bands={$bands}"];
        });

        $this->qa([
            'id' => 'E2E-BUSINESS-01',
            'module' => 'End to end',
            'scenario' => 'Business customer registers and is entitled immediately',
            'pre' => 'No account on the address',
            'data' => 'Business signup, then the banded product at qty 5',
            'steps' => 'signup -> verify -> dashboard -> bands -> basket',
            'expected' => 'Entitled from day one; 450.00 at qty 5; verification pending',
            'severity' => 'Critical',
        ], function () use ($product) {
            $payload = $this->businessPayload();

            $this->signup($payload);

            $user = User::where('email', $payload['email'])->first();

            User::where('id', $user->id)->update(['verified' => true]);
            $user = $user->fresh();

            $account = $this->dashboard($user);
            $bands = count((array)$this->bandsFor($user, $product->id));
            $price = $this->cartUnitPrice($user, $product, 5);

            $ok = $user->account_type === 'business'
                && $user->verification_status === User::VERIFICATION_PENDING
                && $account['has_business_pricing'] === true
                && $bands > 0
                && abs($price - 450.0) < 0.005;

            return [$ok, "type={$user->account_type}"
                . " verification={$user->verification_status}"
                . ' entitled=' . var_export($account['has_business_pricing'], true)
                . " bands={$bands} qty5={$price}"];
        });
    }

    /* ============================================================
     | 13. DISCOUNTS
     * ========================================================== */

    public function test_module_discounts()
    {
        $percent = (int)config('constants.priceType.PERCENT');
        $flat = (int)config('constants.priceType.FLAT');

        $this->qa([
            'id' => 'DSC-01',
            'module' => 'Discounts',
            'scenario' => 'A percentage voucher is taken off the discounted subtotal',
            'pre' => 'Voucher of 10 percent, no cap',
            'data' => 'subtotal 450.00 (a band price), voucher 10 percent',
            'steps' => 'Pricing::voucherDiscount',
            'expected' => '45.00, computed on what is charged rather than on list price',
            'severity' => 'High',
        ], function () use ($percent) {
            $voucher = (object)['type' => $percent, 'price' => 10, 'capped_price' => null];

            $discount = Pricing::voucherDiscount($voucher, 450.0);

            return [abs($discount - 45.0) < 0.005, "discount={$discount}"];
        });

        $this->qa([
            'id' => 'DSC-02',
            'module' => 'Discounts',
            'scenario' => 'A fractional voucher cap is honoured to the cent',
            'pre' => 'Voucher capped at 10.99',
            'data' => 'subtotal 450.00, voucher 50 percent, cap 10.99',
            'steps' => 'Pricing::voucherDiscount',
            'expected' => '10.99, not 10.00',
            'severity' => 'High',
            'notes' => 'Earlier audit finding CPN-04; re-checked as a regression.',
        ], function () use ($percent) {
            $voucher = (object)['type' => $percent, 'price' => 50, 'capped_price' => 10.99];

            $discount = Pricing::voucherDiscount($voucher, 450.0);

            return [abs($discount - 10.99) < 0.005, "discount={$discount}"];
        });

        $this->qa([
            'id' => 'DSC-03',
            'module' => 'Discounts',
            'scenario' => 'A discount never exceeds the value of the goods',
            'pre' => 'Flat voucher larger than the basket',
            'data' => 'subtotal 20.00, flat voucher 100.00',
            'steps' => 'Pricing::voucherDiscount',
            'expected' => '20.00, never more',
            'severity' => 'High',
        ], function () use ($flat) {
            $voucher = (object)['type' => $flat, 'price' => 100, 'capped_price' => null];

            $discount = Pricing::voucherDiscount($voucher, 20.0);

            return [abs($discount - 20.0) < 0.005, "discount={$discount}"];
        });

        $this->qa([
            'id' => 'DSC-04',
            'module' => 'Discounts',
            'scenario' => 'A store-wide flash discount respects its minimum basket value',
            'pre' => 'Flash discount requiring a 100.00 basket',
            'data' => 'subtotal 99.99 then 100.00',
            'steps' => 'Pricing::flashDiscount either side of the floor',
            'expected' => '0.00 below the floor, 10.00 at it',
            'severity' => 'Medium',
        ], function () {
            $flashRule = (object)['type' => 'percentage', 'value' => 10,
                'min_cart_value' => 100, 'max_discount' => null];

            $below = Pricing::flashDiscount($flashRule, 99.99);
            $at = Pricing::flashDiscount($flashRule, 100.0);

            return [abs($below) < 0.005 && abs($at - 10.0) < 0.005, "below={$below} at={$at}"];
        });
    }

    public function test_module_discounts_with_bands()
    {
        $percent = (int)config('constants.priceType.PERCENT');

        $this->qa([
            'id' => 'DSC-05',
            'module' => 'Discounts',
            'scenario' => 'A loyalty group discount is capped and floored like the others',
            'pre' => 'Loyalty group, 20 percent, capped at 25.00, floor 50.00',
            'data' => 'subtotal 40.00 then 400.00',
            'steps' => 'Pricing::loyaltyDiscount either side of the floor',
            'expected' => '0.00 below the floor; 25.00 (the cap) on a large basket',
            'severity' => 'Medium',
        ], function () {
            $group = (object)['discount_type' => 'percentage', 'discount_value' => 20,
                'min_cart_value' => 50, 'max_discount' => 25];

            $below = Pricing::loyaltyDiscount($group, 40.0);
            $large = Pricing::loyaltyDiscount($group, 400.0);

            return [abs($below) < 0.005 && abs($large - 25.0) < 0.005, "below={$below} large={$large}"];
        });

        $this->qa([
            'id' => 'DSC-06',
            'module' => 'Discounts',
            'scenario' => 'Band pricing and a voucher combine without double counting',
            'pre' => 'Business account, band price 450.00 at qty 5, 10 percent voucher',
            'data' => 'unit 450.00 x 5 = 2250.00, voucher 10 percent',
            'steps' => 'Price the line with bands, then apply the voucher to that subtotal',
            'expected' => '225.00 off; the voucher never applies to the list price',
            'severity' => 'High',
        ], function () use ($percent) {
            $product = (object)['selling' => 505.0, 'offered' => 505.0,
                'price' => null, 'end_time' => null];

            $tier = new BusinessProductPricing();
            $tier->forceFill(['min_qty' => 5, 'max_qty' => 10, 'wholesale_price' => 500,
                'discount_type' => 'fixed', 'discount_value' => 50]);

            $unit = Pricing::unitPrice($product, null, 5, [$tier]);
            $subtotal = Pricing::round($unit * 5);

            $voucher = (object)['type' => $percent, 'price' => 10, 'capped_price' => null];

            $discount = Pricing::voucherDiscount($voucher, $subtotal);

            return [
                abs($unit - 450.0) < 0.005 && abs($subtotal - 2250.0) < 0.005
                    && abs($discount - 225.0) < 0.005,
                "unit={$unit} subtotal={$subtotal} discount={$discount}",
            ];
        });

        $this->qa([
            'id' => 'DSC-07',
            'module' => 'Discounts',
            'scenario' => 'A null discount rule yields nothing rather than an error',
            'pre' => 'No voucher, no flash, no loyalty group',
            'data' => 'null passed to each helper',
            'steps' => 'Call all three with null',
            'expected' => '0.00 from each, no exception',
            'severity' => 'Medium',
        ], function () {
            $a = Pricing::voucherDiscount(null, 100.0);
            $b = Pricing::flashDiscount(null, 100.0);
            $c = Pricing::loyaltyDiscount(null, 100.0);

            return [
                abs($a) < 0.005 && abs($b) < 0.005 && abs($c) < 0.005,
                "voucher={$a} flash={$b} loyalty={$c}",
            ];
        });

        $this->qa([
            'id' => 'DSC-08',
            'module' => 'Discounts',
            'scenario' => 'A band never charges above the retail ladder during a sale',
            'pre' => 'Flash sale price below the agreed wholesale rate',
            'data' => 'flash 40.00, band 70.00, qty 5',
            'steps' => 'Pricing::unitPrice with both in play',
            'expected' => '40.00 -- the customer never pays more than a walk-up shopper',
            'severity' => 'High',
        ], function () {
            $product = (object)['selling' => 100.0, 'offered' => 0.0,
                'price' => 40.0, 'end_time' => '2099-01-01'];

            $tier = new BusinessProductPricing();
            $tier->forceFill(['min_qty' => 1, 'max_qty' => 0, 'wholesale_price' => 70,
                'discount_type' => 'fixed', 'discount_value' => 0]);

            $unit = Pricing::unitPrice($product, null, 5, [$tier]);

            return [abs($unit - 40.0) < 0.005, "unit={$unit}"];
        });
    }

    /* ============================================================
     | 15. PURCHASE ORDER NUMBER
     * ========================================================== */

    public function test_module_purchase_order_storage()
    {
        $this->qa([
            'id' => 'PO-01',
            'module' => 'Purchase order',
            'scenario' => 'Orders can carry a purchase order number',
            'pre' => 'orders.purchase_order_number column exists',
            'data' => 'PO-TEST-001',
            'steps' => 'Create an order row with the number and read it back',
            'expected' => 'Stored and returned unchanged',
            'severity' => 'Medium',
        ], function () {
            $user = $this->customer(['account_type' => 'business'], true);

            $order = \App\Models\Order::create([
                'user_id' => $user->id,
                'status' => 1,
                'order_method' => 1,
                'total_amount' => 100.00,
                'order' => 'QA' . uniqid(),
                'purchase_order_number' => 'PO-TEST-001',
            ]);

            return [
                $order->fresh()->purchase_order_number === 'PO-TEST-001',
                'stored=' . var_export($order->fresh()->purchase_order_number, true),
            ];
        });

        $this->qa([
            'id' => 'PO-02',
            'module' => 'Purchase order',
            'scenario' => 'Whether a PO is required comes from the stored profile, not the request',
            'pre' => 'Business account with requires_po set',
            'data' => 'user_business_profiles.requires_po = 1, then a request trying to clear it',
            'steps' => 'POST update-profile with requires_po=0 and re-read the profile',
            'expected' => 'The stored flag is unchanged; the browser cannot switch it off',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'business'],
                ['company_name' => 'PO Ltd', 'requires_po' => true]);

            $this->asCustomer($user);

            $this->postJson('/api/v1/user/update-profile', [
                'name' => $user->name,
                'phone' => $user->phone,
                'requires_po' => 0,
            ]);

            $profile = $user->fresh()->businessProfile;

            return [
                $profile && (bool)$profile->requires_po === true,
                'requires_po=' . var_export($profile ? $profile->requires_po : null, true),
            ];
        });
    }

    public function test_module_purchase_order_checkout_gate()
    {
        $this->qa([
            'id' => 'PO-03',
            'module' => 'Purchase order',
            'scenario' => 'Checkout refuses a business order with no PO when one is required',
            'pre' => 'Business account with requires_po set',
            'data' => 'Order payload with no purchase_order_number',
            'steps' => 'POST /api/v1/order/action',
            'expected' => 'Refused, naming the purchase order number',
            'severity' => 'High',
        ], function () {
            $user = $this->customer(['account_type' => 'business'],
                ['company_name' => 'PO Ltd', 'requires_po' => true]);

            $this->asCustomer($user);

            $response = $this->postJson('/api/v1/order/action', [
                'data' => $this->orderPayload(),
                'order_method' => (int)config('constants.paymentMethod.BANK'),
            ]);

            return [
                stripos((string)$response->json('message'), 'purchase order') !== false,
                'message=' . $response->json('message'),
            ];
        });

        $this->qa([
            'id' => 'PO-04',
            'module' => 'Purchase order',
            'scenario' => 'An over-long PO number is refused',
            'pre' => 'Business account with requires_po set',
            'data' => '65 characters',
            'steps' => 'POST the order with an over-long number',
            'expected' => 'Refused as too long',
            'severity' => 'Low',
        ], function () {
            $user = $this->customer(['account_type' => 'business'],
                ['company_name' => 'PO Ltd', 'requires_po' => true]);

            $this->asCustomer($user);

            $response = $this->postJson('/api/v1/order/action', [
                'data' => $this->orderPayload(['user_token' => null,
                    'order_method' => (int)config('constants.paymentMethod.BANK'),
                    'voucher' => '', 'time_zone' => 'Europe/Dublin',
                    'purchase_order_number' => str_repeat('X', 65)]),
                'order_method' => (int)config('constants.paymentMethod.BANK'),
            ]);

            return [
                stripos((string)$response->json('message'), 'too long') !== false,
                'message=' . $response->json('message'),
            ];
        });


        $this->qa([
            'id' => 'PO-05',
            'module' => 'Purchase order',
            'scenario' => 'A personal account is never asked for a PO number',
            'pre' => 'Personal account, no business profile',
            'data' => 'Order payload with no purchase_order_number',
            'steps' => 'POST /api/v1/order/action',
            'expected' => 'Refused for some other reason, never for a missing PO',
            'severity' => 'Medium',
        ], function () {
            $user = $this->customer();

            $this->asCustomer($user);

            $response = $this->postJson('/api/v1/order/action', [
                'data' => $this->orderPayload(),
                'order_method' => (int)config('constants.paymentMethod.BANK'),
            ]);

            return [
                stripos((string)$response->json('message'), 'purchase order') === false,
                'message=' . $response->json('message'),
            ];
        });

        $this->qa([
            'id' => 'PO-06',
            'module' => 'Purchase order',
            'scenario' => 'A business account without requires_po is not asked either',
            'pre' => 'Business account, requires_po false',
            'data' => 'Order payload with no purchase_order_number',
            'steps' => 'POST /api/v1/order/action',
            'expected' => 'Never refused for a missing PO',
            'severity' => 'Medium',
        ], function () {
            $user = $this->customer(['account_type' => 'business'],
                ['company_name' => 'No PO Ltd', 'requires_po' => false]);

            $this->asCustomer($user);

            $response = $this->postJson('/api/v1/order/action', [
                'data' => $this->orderPayload(),
                'order_method' => (int)config('constants.paymentMethod.BANK'),
            ]);

            return [
                stripos((string)$response->json('message'), 'purchase order') === false,
                'message=' . $response->json('message'),
            ];
        });
    }

    /* ============================================================
     | 25. RESPONSIVE / UI (static review of the shipped markup)
     * ========================================================== */

    /**
     * These check the actual files for the things that break a layout on a
     * phone. They are a static review, not a device test -- that distinction
     * is recorded honestly in the blocked cases below.
     */
    protected function sourceOf($relative)
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        return is_file($path) ? file_get_contents($path) : null;
    }

    public function test_module_responsive_static_review()
    {
        $targets = [
            ['UI-01', 'Customer dashboard account panel', 'frontend-panel/pages/user/dashboard.vue',
                ['.acct-panel', '@media (max-width: 575px)'], 'High'],
            ['UI-02', 'Checkout purchase order field', 'frontend-panel/assets/css/cart-flow.css',
                ['.po-input', '@media (max-width: 575px)'], 'Medium'],
            ['UI-03', 'Admin trade review queue', 'admin-panel/pages/trade-applications/index.vue',
                ['ta-facts', 'minmax('], 'Medium'],
            ['UI-04', 'Admin customer list', 'admin-panel/pages/registered-users/index.vue',
                ['acct-pill', 'text-overflow'], 'Medium'],
        ];

        foreach ($targets as list($id, $label, $file, $needles, $severity)) {

            $this->qa([
                'id' => $id,
                'module' => 'Responsive UI',
                'scenario' => "{$label} carries a small-screen rule",
                'pre' => 'Shipped source file',
                'data' => $file,
                'steps' => 'Read the file and look for the layout rules that make it reflow',
                'expected' => 'Present: ' . implode(', ', $needles),
                'severity' => $severity,
            ], function () use ($file, $needles) {
                $source = $this->sourceOf($file);

                if (is_null($source)) {
                    return [false, 'file not found: ' . $file];
                }

                $missing = [];

                foreach ($needles as $needle) {
                    if (strpos($source, $needle) === false) {
                        $missing[] = $needle;
                    }
                }

                return [count($missing) === 0,
                    $missing ? 'missing: ' . implode(', ', $missing) : 'all present'];
            });
        }

        $this->qa([
            'id' => 'UI-05',
            'module' => 'Responsive UI',
            'scenario' => 'New storefront CSS declares no fixed width wider than a phone',
            'pre' => 'The stylesheet added for the purchase order block',
            'data' => 'frontend-panel/assets/css/cart-flow.css, the purchase order section',
            'steps' => 'Scan the added rules for width or min-width in px above 400',
            'expected' => 'None; the field is width 100 percent inside the existing shell',
            'severity' => 'Medium',
        ], function () {
            $source = $this->sourceOf('frontend-panel/assets/css/cart-flow.css');

            if (is_null($source)) {
                return [false, 'file not found'];
            }

            $section = strstr($source, '/* ---------- purchase order ---------- */');

            if (!$section) {
                return [false, 'purchase order section not found'];
            }

            preg_match_all('/(?:^|[^-])(?:min-)?width:\s*(\d+)px/i', $section, $m);

            $wide = array_filter($m[1], function ($px) {
                return (int)$px > 400;
            });

            return [count($wide) === 0,
                $wide ? 'fixed widths found: ' . implode(',', $wide) . 'px' : 'no fixed widths'];
        });

        $this->qa([
            'id' => 'UI-06',
            'module' => 'Responsive UI',
            'scenario' => 'Decision emails inherit the responsive email layout',
            'pre' => 'The two trade templates',
            'data' => 'resources/views/mail_templates/trade_approved.blade.php and trade_rejected',
            'steps' => 'Check both extend the shared base layout rather than rolling their own',
            'expected' => 'Both extend mail_templates.layout.base, which carries the 620px breakpoint',
            'severity' => 'Medium',
        ], function () {
            $ok = true;
            $detail = [];

            foreach (['trade_approved', 'trade_rejected'] as $name) {
                $source = $this->sourceOf("resources/views/mail_templates/{$name}.blade.php");

                $extends = $source && strpos($source, "@extends('mail_templates.layout.base')") !== false;

                $ok = $ok && $extends;
                $detail[] = $name . '=' . ($extends ? 'extends base' : 'DOES NOT extend base');
            }

            $base = $this->sourceOf('resources/views/mail_templates/layout/base.blade.php');

            $hasBreakpoint = $base && strpos($base, '@media only screen and (max-width: 620px)') !== false;

            $detail[] = 'base breakpoint=' . ($hasBreakpoint ? 'present' : 'MISSING');

            return [$ok && $hasBreakpoint, implode(' ', $detail)];
        });

        $this->qa([
            'id' => 'UI-07',
            'module' => 'Responsive UI',
            'scenario' => 'Decision emails carry a viewport meta tag',
            'pre' => 'Shared email layout',
            'data' => 'layout/base.blade.php',
            'steps' => 'Check the head for a device-width viewport',
            'expected' => 'Present, so the email scales on a phone',
            'severity' => 'Medium',
        ], function () {
            $base = $this->sourceOf('resources/views/mail_templates/layout/base.blade.php');

            $has = $base && strpos($base, 'name="viewport" content="width=device-width') !== false;

            return [$has, $has ? 'viewport present' : 'viewport MISSING'];
        });
    }

    /* ============================================================
     | WHAT THIS PASS COULD NOT EXECUTE
     * ========================================================== */

    /**
     * Recorded as Blocked with the reason, rather than passed on assumption or
     * quietly dropped. Each says what would be needed to run it.
     */
    public function test_module_blocked_scope()
    {
        $blocked = [
            ['PAY-01', 'Payment', 'Successful card payment charges the account-correct total',
                'Critical', 'No gateway sandbox credentials are configured in this environment '
                . '(.env carries no Stripe/PayPal/SumUp test keys), and the order endpoint '
                . 'verifies the charge with the provider before it will create an order. '
                . 'Needs a sandbox account to run.'],

            ['PAY-02', 'Payment', 'Failed, cancelled and retried payment leave no duplicate order',
                'Critical', 'Same blocker as PAY-01: the provider round trip cannot be exercised '
                . 'without sandbox credentials.'],

            ['ORD-01', 'Order', 'Order totals, lines, tax and PO match the checkout summary',
                'High', 'Reaching order creation needs a payable gateway plus a configured '
                . 'shipping rule and place for the product; the storefront blocks checkout '
                . 'before that. The pricing that WOULD be used is covered by PRC/QTY/TAX above, '
                . 'which exercise the same Pricing methods OrdersController calls.'],

            ['INV-01', 'Invoice', 'Invoice PDF totals match the order, showing PO and VAT number',
                'High', 'Depends on a created order (ORD-01). The template changes were verified '
                . 'by reading the rendered blade, but the end-to-end PDF was not produced.'],

            ['RESP-01', 'Responsive UI', 'Registration, dashboard, checkout and admin on real devices',
                'Medium', 'No browser is driven in this pass. UI-01 to UI-07 statically verify the '
                . 'breakpoints, overflow rules and fixed widths in the shipped files, which is '
                . 'evidence about the source, not about rendering on a device.'],

            ['BRW-01', 'Browser/session', 'Back/forward navigation, multiple tabs, session expiry',
                'Medium', 'Requires a real browser session. The server-side halves that matter '
                . 'are covered: basket isolation between customers (CART-ISO-01), guest handover '
                . 'repricing (CART-B-01) and re-read repricing (CART-SEC-01).'],

            ['REF-01', 'Returns/refunds', 'Returns and refunds still work for all account types',
                'Medium', 'Not touched by this work and not exercised here. The refund path '
                . '(Helper\Refunder, CancellationsController) was not modified in any of the '
                . 'three changesets.'],

            ['TAX-08', 'Tax', 'Country-based VAT: billing vs shipping country, reverse charge',
                'Medium', 'The feature does not exist. Tax is decided per product '
                . '(products.excludeVAT plus tax_rules) with no country dimension anywhere in '
                . 'the schema or the pricing code. Raised as a product gap, not a defect.'],

            ['DOC-01', 'Trade application', 'Supporting document upload, file size and type limits',
                'Low', 'The feature does not exist. Nothing in the schema stores files against '
                . 'an account, and the trade form collects no upload. Raised as a gap.'],
        ];

        foreach ($blocked as list($id, $module, $scenario, $severity, $reason)) {
            $this->blocked([
                'id' => $id,
                'module' => $module,
                'scenario' => $scenario,
                'pre' => 'See reason',
                'data' => '-',
                'steps' => '-',
                'expected' => '-',
                'severity' => $severity,
            ], $reason);
        }

        $this->addToAssertionCount(1);
    }
}
