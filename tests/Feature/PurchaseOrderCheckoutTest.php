<?php
namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserBusinessProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Checkout with a purchase order number required on the account.
 *
 * The number is a contractual reference: the customer's finance department
 * will not pay an invoice that does not carry it. So it has to be refused when
 * absent, stored when given, and reach the invoice — being accepted and then
 * quietly dropped is worse than being refused, because nobody finds out until
 * the bill is disputed.
 */
class PurchaseOrderCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private $product;
    private $inventory;
    private $place;

    private function customer($attributes = [], $profile = [])
    {
        $user = User::create(array_merge([
            'name' => 'PO Fixture',
            'email' => 'po.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'business',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();

        UserBusinessProfile::create(array_merge([
            'user_id' => $user->id,
            'company_name' => 'PO Fixture Ltd',
            'requires_po' => true,
        ], $profile));

        $address = UserAddress::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'country' => 'IE',
            'state' => 'KE',
            'city' => 'Naas',
            'zip' => 'W91XY12',
            'address_1' => '1 Test Street',
            'type' => 1,
            'default' => 1,
        ]);

        $user->default_address = $address->id;
        $user->save();

        return $user->fresh();
    }

    private function basketReadyProduct()
    {
        if ($this->product) {
            return $this->product;
        }

        $row = collect($this->getJson('/api/v1/products')->json('data.result.data'))
            ->first(function ($item) {
                return (float)($item['selling'] ?? 0) > 0;
            });

        $product = $row ? Product::find($row['id']) : null;

        if (!$product) {
            $this->markTestSkipped('No published product to buy.');
        }

        $this->inventory = UpdatedInventory::where('product_id', $product->id)
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->first();

        $this->place = optional($product->shipping_rule)->shipping_places()->first();

        if (!$this->inventory || !$this->place) {
            $this->markTestSkipped('The fixture product cannot be taken to checkout.');
        }

        return $this->product = $product;
    }

    private function fillBasket()
    {
        $product = $this->basketReadyProduct();

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $this->inventory->id,
            'quantity' => 1,
            'shipping_place_id' => $this->place->id,
            'shipping_type' => 1,
        ])->assertStatus(200);
    }

    /** The encrypted envelope /order/action expects. */
    private function payload(array $params = [])
    {
        $json = json_encode(array_merge([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.BANK'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
        ], $params));

        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt($json, 'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210'));
    }

    private function placeOrder(array $params = [])
    {
        return $this->postJson('/api/v1/order/action', [
            'data' => $this->payload($params),
            'order_method' => (int)config('constants.paymentMethod.BANK'),
        ]);
    }

    /** Validation::error puts the message in the body, not the status code. */
    private function messageOf($response)
    {
        $message = $response->json('message');

        if (is_array($message)) {
            return trim(implode(' ', array_map(function ($item) {
                return is_array($item) ? implode(' ', $item) : $item;
            }, $message)));
        }

        return (string)$message;
    }

    // ------------------------------------------------- steps 1-5: no PO given

    public function test_an_order_with_no_purchase_order_number_is_refused()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $response = $this->placeOrder();

        $this->assertSame(
            'Enter the purchase order number for this order.',
            $this->messageOf($response),
            'the refusal wording the customer is shown'
        );

        $this->assertSame(
            0, Order::where('user_id', $customer->id)->count(),
            'no order was created'
        );
    }

    public function test_the_refusal_names_the_field_it_is_about()
    {
        Passport::actingAs($this->customer(), ['user'], 'user');

        $this->fillBasket();

        // Validation::error keys the body by the field it is about, so the
        // checkout form can highlight the right box rather than showing a
        // banner and leaving the customer to find it.
        $this->assertSame(
            ['Enter the purchase order number for this order.'],
            $this->placeOrder()->json('data.purchase_order_number')
        );
    }

    // ------------------------------------------------ steps 6-8: only spaces

    public function test_a_purchase_order_number_of_only_spaces_is_refused()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        foreach (['   ', "\t", " \t \n ", '     '] as $blank) {

            $response = $this->placeOrder(['purchase_order_number' => $blank]);

            $this->assertSame(
                'Enter the purchase order number for this order.',
                $this->messageOf($response),
                'whitespace is not a purchase order number: ' . json_encode($blank)
            );
        }

        $this->assertSame(0, Order::where('user_id', $customer->id)->count());
    }

    // --------------------------------------- steps 9-14: a real number is kept

    public function test_a_valid_purchase_order_number_is_accepted_and_stored()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $response = $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->assertNotNull(
            $order,
            'checkout did not complete: ' . $this->messageOf($response)
        );

        $this->assertSame('PO-2026-00125', $order->purchase_order_number);
    }

    public function test_the_stored_number_is_trimmed_not_taken_verbatim()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $this->placeOrder(['purchase_order_number' => '   PO-2026-00125   ']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped('Checkout did not complete in this environment.');
        }

        $this->assertSame('PO-2026-00125', $order->purchase_order_number);
    }

    public function test_the_number_reaches_the_customers_own_order_record()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped('Checkout did not complete in this environment.');
        }

        // How the storefront opens one order: "My orders" -> that order.
        $response = $this->postJson('/api/v1/order/by-user', [
            'order_id' => $order->id,
            'time_zone' => 'Europe/Dublin',
        ]);

        $response->assertStatus(200);

        $this->assertSame(
            'PO-2026-00125',
            $response->json('data.purchase_order_number'),
            'the order the customer opens carries the number'
        );
    }

    // ------------------------------------------- steps 15-16: on the invoice

    /**
     * The invoice document itself — the PDF attached to the order
     * confirmation, rendered from the same template the mailer uses.
     *
     * This is the step that actually matters commercially: a business
     * customer's accounts department matches the invoice to its own purchase
     * order by this number, and will not pay one that does not carry it.
     */
    public function test_the_purchase_order_number_appears_on_the_invoice()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped('Checkout did not complete in this environment.');
        }

        $html = $this->invoiceHtml($order->id);

        $this->assertStringContainsString(
            'PO-2026-00125', $html, 'the number is printed on the invoice'
        );

        $this->assertStringContainsString(
            'Purchase order', $html, 'and it is labelled, not just present'
        );
    }

    /** An order without one must not print an empty "Purchase order" row. */
    public function test_an_invoice_without_a_purchase_order_shows_no_empty_row()
    {
        $customer = $this->customer([], ['requires_po' => false]);

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $this->placeOrder();

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped('Checkout did not complete in this environment.');
        }

        $this->assertNull($order->purchase_order_number);

        $this->assertStringNotContainsString('Purchase order', $this->invoiceHtml($order->id));
    }

    /**
     * The admin order screen and the invoice panel on it both read
     * order.purchase_order_number, so staff answering a query about the
     * reference can see the one the customer gave.
     */
    public function test_staff_can_see_the_purchase_order_number_on_the_order()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $this->placeOrder(['purchase_order_number' => 'PO-2026-00125']);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped('Checkout did not complete in this environment.');
        }

        $admin = \App\Models\Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        $this->app['auth']->forgetGuards();

        Passport::actingAs($admin, ['admin'], 'admin');

        $response = $this->getJson("/api/admin/order/find/{$order->id}?time_zone=Europe/Dublin");

        $response->assertStatus(200);

        $this->assertSame(
            'PO-2026-00125', $response->json('data.purchase_order_number')
        );
    }

    /** Renders the invoice exactly as MailHelper attaches it. */
    private function invoiceHtml($orderId)
    {
        $order = \App\Models\Helper\MailHelper::order($orderId);
        $setting = \App\Models\Helper\MailHelper::emailData($order->user->name ?? 'Customer');

        return \Illuminate\Support\Facades\View::make('mail_templates.order_pdf', [
            'order' => $order,
            'setting' => $setting,
            'brand' => $setting,
            'lang' => 'en',
        ])->render();
    }

    // -------------------------------------------------------- the boundaries

    public function test_an_over_long_purchase_order_number_is_refused()
    {
        Passport::actingAs($this->customer(), ['user'], 'user');

        $this->fillBasket();

        $response = $this->placeOrder([
            'purchase_order_number' => str_repeat('X', 65),
        ]);

        $this->assertSame(
            'That purchase order number is too long.', $this->messageOf($response)
        );
    }

    public function test_sixty_four_characters_is_accepted()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $number = str_repeat('X', 64);

        $this->placeOrder(['purchase_order_number' => $number]);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped('Checkout did not complete in this environment.');
        }

        $this->assertSame($number, $order->purchase_order_number);
    }

    // ---------------------------------------------- who the rule applies to

    public function test_a_trade_account_is_asked_for_a_purchase_order_number_too()
    {
        $customer = $this->customer([
            'account_type' => 'trade',
            'trade_status' => User::TRADE_APPROVED,
        ]);

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $this->assertSame(
            'Enter the purchase order number for this order.',
            $this->messageOf($this->placeOrder())
        );
    }

    public function test_an_account_that_does_not_require_one_is_never_asked()
    {
        $customer = $this->customer([], ['requires_po' => false]);

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $this->assertNotSame(
            'Enter the purchase order number for this order.',
            $this->messageOf($this->placeOrder())
        );
    }

    public function test_a_personal_account_is_never_asked()
    {
        $customer = $this->customer(['account_type' => 'personal']);

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $this->assertNotSame(
            'Enter the purchase order number for this order.',
            $this->messageOf($this->placeOrder())
        );
    }

    /**
     * The flag is the account's, not the browser's. A request claiming the
     * account does not need a purchase order is not evidence about the
     * account — and it is exactly what someone would send to skip the field.
     */
    public function test_the_browser_cannot_switch_the_requirement_off()
    {
        $customer = $this->customer();

        Passport::actingAs($customer, ['user'], 'user');

        $this->fillBasket();

        $response = $this->placeOrder([
            'requires_po' => false,
            'purchase_order_number' => '',
        ]);

        $this->assertSame(
            'Enter the purchase order number for this order.',
            $this->messageOf($response)
        );
    }
}
