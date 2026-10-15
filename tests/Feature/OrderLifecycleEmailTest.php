<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderEmailLog;
use App\Models\Product;
use App\Models\ShippingPlace;
use App\Models\ShippingRule;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The customer hears about their order, end to end (2026-10-14).
 *
 * OrderEmailDeliveryTest proves the dispatcher: one email per event, retries,
 * bad addresses. This proves the flows that call it - an order placed through
 * the real checkout endpoint, then moved through every status the admin
 * dropdown offers through the real admin endpoint - and that each step
 * reaches the customer's inbox: a row marked sent, and a message addressed to
 * them in the mailer.
 *
 * phpunit.xml runs the queue inline (sync) and mail into the array transport,
 * so "sent" here means the job ran and the mailer accepted the message.
 */
class OrderLifecycleEmailTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Admin::orderBy('id')->first();
        $rule = ShippingRule::create(['title' => 'Test France lifecycle', 'admin_id' => $admin->id]);

        ShippingPlace::create([
            'country' => 'FR', 'state' => 'ALL', 'price' => 6, 'day_needed' => 3,
            'pickup_point' => 0, 'pickup_price' => 0,
            'shipping_rule_id' => $rule->id, 'admin_id' => $admin->id,
        ]);

        $this->mails()->flush();
    }

    private function mails()
    {
        return app('mailer')->getSwiftMailer()->getTransport();
    }

    /** Messages the mailer accepted for this address, as subjects. */
    private function subjectsTo($email)
    {
        return collect($this->mails()->messages())
            ->filter(function ($message) use ($email) {
                return array_key_exists($email, $message->getTo() ?: []);
            })
            ->map(function ($message) { return $message->getSubject(); })
            ->values()
            ->all();
    }

    private function customer()
    {
        $customer = User::create([
            'name' => 'Lifecycle Fixture',
            'email' => 'lifecycle.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();

        $address = UserAddress::create([
            'user_id' => $customer->id, 'name' => 'Lifecycle Fixture', 'email' => $customer->email,
            'phone' => $customer->phone, 'country' => 'FR', 'state' => 'NOR', 'city' => 'Rouen',
            'zip' => '76000', 'address_1' => '1 Rue de Test', 'type' => 1, 'default' => 1,
        ]);

        $customer->default_address = $address->id;
        $customer->save();
        $customer->test_address = $address->id;

        return $customer;
    }

    /** Place an order through the storefront API; returns the Order. */
    private function placeOrder($customer, $method)
    {
        $this->app['auth']->forgetGuards();
        Passport::actingAs($customer->fresh(), ['user'], 'user');

        $product = Product::where('status', 1)->whereHas('product_inventories')->orderBy('id')->first();
        if (!$product) {
            $this->markTestSkipped('No published product with inventory.');
        }
        Product::where('id', $product->id)->update(['sell_countries' => null]);
        $inventory = UpdatedInventory::where('product_id', $product->id)->orderBy('id')->first();
        UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 5000]);

        $this->postJson('/api/v1/cart/action', [
            'product_id' => $product->id, 'inventory_id' => $inventory->id, 'quantity' => 1,
        ])->assertStatus(200);

        $cart = Cart::where('user_id', $customer->id)->orderByDesc('id')->first();

        $this->postJson('/api/v1/cart/update-shipping', [
            'cart' => [['cart' => $cart->id, 'shipping_type' => 1, 'shipping_place' => null]],
            'selected_address' => $customer->test_address,
        ])->assertJsonPath('status', 200);

        $json = json_encode([
            'user_token' => null, 'order_method' => $method, 'voucher' => '', 'time_zone' => 'Europe/Dublin',
        ]);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);
        $data = openssl_encrypt($json, 'AES-128-CBC', hex2bin('0123456470abcdef0123456789abcdef'),
            OPENSSL_ZERO_PADDING, hex2bin('abcdef1876343516abcdef9876543210'));

        $response = $this->postJson('/api/v1/order/action', ['data' => $data, 'order_method' => $method]);

        $order = Order::where('user_id', $customer->id)->orderByDesc('id')->first();

        if (!$order) {
            $this->markTestSkipped("Payment method $method is not accepted here: "
                . json_encode($response->json('data.form') ?? $response->json('message')));
        }

        return $order;
    }

    private function setStatus($order, $status)
    {
        $this->app['auth']->forgetGuards();
        Passport::actingAs(Admin::orderBy('id')->first(), ['admin'], 'admin');

        return $this->postJson('/api/admin/order/update-status', ['id' => $order->id, 'status' => $status]);
    }

    private function logged($order, $event)
    {
        return OrderEmailLog::where('order_id', $order->id)->where('event', $event)->get();
    }

    private function assertSentOnce($order, $event, $email)
    {
        $rows = $this->logged($order, $event);

        $this->assertCount(1, $rows, "$event: expected one log row");
        $this->assertSame(OrderEmailLog::STATUS_SENT, $rows->first()->status,
            "$event: " . $rows->first()->status . ' ' . $rows->first()->last_error);
        $this->assertSame($email, $rows->first()->recipient);
    }

    // ---- placing an order --------------------------------------------------

    public function provideOfflineMethods()
    {
        return [
            'bank transfer' => ['BANK'],
            'cash on delivery' => ['CASH_ON_DELIVERY'],
        ];
    }

    /** @dataProvider provideOfflineMethods */
    public function test_placing_an_order_emails_the_customer_their_receipt($method)
    {
        $customer = $this->customer();
        $order = $this->placeOrder($customer, (int)config('constants.paymentMethod.' . $method));

        $this->assertSentOnce($order, 'order_placed', $customer->email);

        $subjects = $this->subjectsTo($customer->email);
        $this->assertCount(1, $subjects, 'exactly one email to the customer: ' . json_encode($subjects));
        $this->assertStringContainsString($order->order, $subjects[0]);
    }

    // ---- the admin moving it along -----------------------------------------

    public function test_every_status_the_admin_sets_emails_the_customer_once()
    {
        $customer = $this->customer();
        $order = $this->placeOrder($customer, (int)config('constants.paymentMethod.BANK'));
        $s = config('constants.orderStatus');

        $steps = [
            [$s['CONFIRMED'], 'order_confirmed'],
            [$s['PICKED_UP'], 'order_picked_up'],
            [$s['ON_THE_WAY'], 'order_shipped'],
            [$s['DELIVERED'], 'order_delivered'],
        ];

        foreach ($steps as [$status, $event]) {
            $this->setStatus($order, $status)->assertJsonPath('status', 200);
            $this->assertSame($status, (int)$order->fresh()->status);
            $this->assertSentOnce($order, $event, $customer->email);
        }

        // receipt + one email per step, nothing twice
        $this->assertCount(1 + count($steps), $this->subjectsTo($customer->email),
            json_encode($this->subjectsTo($customer->email)));
    }

    public function test_setting_the_same_status_again_sends_nothing_more()
    {
        $customer = $this->customer();
        $order = $this->placeOrder($customer, (int)config('constants.paymentMethod.BANK'));
        $confirmed = config('constants.orderStatus.CONFIRMED');

        $this->setStatus($order, $confirmed)->assertJsonPath('status', 200);
        $this->setStatus($order, $confirmed)->assertJsonPath('status', 200);

        $this->assertCount(1, $this->logged($order, 'order_confirmed'));
        $this->assertCount(2, $this->subjectsTo($customer->email), 'receipt + one confirmation');
    }

    public function test_cancelling_an_order_emails_the_customer()
    {
        $customer = $this->customer();
        $order = $this->placeOrder($customer, (int)config('constants.paymentMethod.BANK'));

        $this->setStatus($order, config('constants.orderStatus.CONFIRMED'))->assertJsonPath('status', 200);
        $this->setStatus($order, config('constants.orderStatus.CANCELLED'))->assertJsonPath('status', 200);

        $this->assertSentOnce($order, 'order_cancelled', $customer->email);
    }

    public function test_a_status_the_order_cannot_move_to_is_refused_and_emails_nobody()
    {
        $customer = $this->customer();
        $order = $this->placeOrder($customer, (int)config('constants.paymentMethod.BANK'));

        // pending straight to delivered is not a move the dropdown offers
        $response = $this->setStatus($order, config('constants.orderStatus.DELIVERED'));

        $this->assertNotSame(200, $response->json('status'));
        $this->assertSame((int)config('constants.orderStatus.PENDING'), (int)$order->fresh()->status);
        $this->assertCount(0, $this->logged($order, 'order_delivered'));
    }
}
