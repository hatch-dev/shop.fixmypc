<?php

namespace Tests\Feature;

use App\Console\Commands\CancelUnpaidBankTransfers;
use App\Models\Admin;
use App\Models\Helper\OrderEmailEvent;
use App\Models\Helper\OrderMailer;
use App\Models\Order;
use App\Models\OrderEmailLog;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The bank transfer path, end to end at the API.
 *
 * Each test pins a bug found in the 2026-09-26 audit of this flow.
 */
class BankTransferFlowTest extends TestCase
{
    use DatabaseTransactions;

    const SECRET_FIELDS = [
        'stripe_secret', 'paypal_secret', 'razorpay_secret', 'fw_secret_key',
        'fw_encryption_key', 'ip_api_key', 'ip_secret_key', 'payfast_merchant_id',
        'payfast_merchant_key', 'payfast_passphrase', 'sumup_api_key',
        'sumup_merchant_email', 'sumup_merchant_code', 'admin_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Payment::query()->update(['bank' => 1]);
    }

    private function payload(array $params)
    {
        $json = json_encode($params);

        // Zero padding: see AccountTypeApiTest::orderPayload.
        $padded = $json . str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt(
            $padded,
            'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'),
            OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210')
        );
    }

    private function guestOrder(array $overrides = [])
    {
        return Order::create(array_merge([
            'order' => 'TEST' . uniqid(),
            'status' => config('constants.orderStatus.PENDING'),
            'order_method' => config('constants.paymentMethod.BANK'),
            'total_amount' => 149.99,
            'currency' => 'EUR',
            'payment_done' => 0,
            'user_token' => 'bank-test-' . uniqid(),
            'contact_name' => 'Test Customer',
            'contact_email' => 'bank.test@example.com',
            'contact_phone' => '+353871234567',
        ], $overrides));
    }

    private function confirmBank(Order $order)
    {
        return $this->postJson('/api/v1/order/payment-done', [
            'data' => $this->payload([
                'id' => $order->id,
                'payment_token' => $order->id,
                'order_method' => config('constants.paymentMethod.BANK'),
                'user_token' => $order->user_token,
            ]),
        ]);
    }

    /**
     * Both public endpoints returned the whole payments row, so every
     * gateway's secret key -- live Stripe and SumUp included -- went to any
     * browser that asked.
     */
    public function test_the_public_payment_endpoints_expose_no_secret()
    {
        foreach (['/api/v1/payment-gateway', '/api/v1/payment/find'] as $url) {

            $data = $this->getJson($url)->assertOk()->json('data');

            $this->assertIsArray($data, $url);

            foreach (self::SECRET_FIELDS as $field) {
                $this->assertArrayNotHasKey($field, $data, "$url leaks $field");
            }

            $this->assertSame(array_merge(Payment::PUBLIC_FIELDS, ['bank_transfer_hours']), array_keys($data), $url);
        }
    }

    /**
     * Confirming a bank order fell through to the "no gateway branch"
     * refusal, and paymentDone() turned that into a "your payment failed"
     * email to every bank transfer customer.
     */
    public function test_confirming_a_bank_order_sends_no_payment_failed_email()
    {
        $order = $this->guestOrder();

        $this->confirmBank($order)
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.awaiting_transfer', true);

        $this->assertNull(
            OrderEmailLog::where('order_id', $order->id)
                ->where('event', OrderEmailEvent::PAYMENT_FAILED)
                ->first(),
            'A bank transfer customer was told their payment failed'
        );
    }

    /** The money has not arrived; only an admin can say it has. */
    public function test_confirming_a_bank_order_does_not_mark_it_paid()
    {
        $order = $this->guestOrder();

        $this->confirmBank($order)->assertOk();

        $this->assertSame(0, (int)$order->fresh()->payment_done);
    }

    /** An unpaid order switching to bank transfer from its order page. */
    public function test_switching_an_unpaid_order_to_bank_records_the_method()
    {
        $order = $this->guestOrder([
            'order_method' => config('constants.paymentMethod.SUMUP'),
        ]);

        $this->confirmBank($order)->assertOk();

        $this->assertSame(
            (int)config('constants.paymentMethod.BANK'),
            (int)$order->fresh()->order_method
        );
    }

    public function test_a_paid_order_is_not_switched_to_bank()
    {
        $order = $this->guestOrder([
            'order_method' => config('constants.paymentMethod.SUMUP'),
            'payment_done' => 1,
        ]);

        $this->confirmBank($order)->assertOk();

        $this->assertSame(
            (int)config('constants.paymentMethod.SUMUP'),
            (int)$order->fresh()->order_method
        );
    }

    public function test_someone_elses_order_is_refused()
    {
        $order = $this->guestOrder([
            'order_method' => config('constants.paymentMethod.SUMUP'),
        ]);

        $this->postJson('/api/v1/order/payment-done', [
            'data' => $this->payload([
                'id' => $order->id,
                'payment_token' => $order->id,
                'order_method' => config('constants.paymentMethod.BANK'),
                'user_token' => 'not-the-owner',
            ]),
        ])->assertJsonMissing(['awaiting_transfer' => true]);

        $this->assertSame(
            (int)config('constants.paymentMethod.SUMUP'),
            (int)$order->fresh()->order_method
        );
    }

    /**
     * The receipt said "Bank transfer" and nothing else: no account, no
     * amount, no reference, once the bank details page was closed.
     */
    public function test_an_unpaid_bank_order_email_carries_the_bank_details()
    {
        Payment::query()->update(['iban' => 'IE76 TEST 9903 6004 9378 32', 'bic_swift' => 'TESTIE23']);

        $bank = OrderMailer::bankTransferDetails($this->guestOrder());

        $this->assertSame('IE76 TEST 9903 6004 9378 32', $bank['iban']);
        $this->assertSame('TESTIE23', $bank['bic_swift']);
    }

    public function test_the_iban_falls_back_to_one_typed_into_account_number()
    {
        Payment::query()->update(['iban' => null, 'account_number' => 'IE76 TEST 9903 6004 9378 32']);

        $bank = OrderMailer::bankTransferDetails($this->guestOrder());

        $this->assertSame('IE76 TEST 9903 6004 9378 32', $bank['iban']);
        $this->assertArrayNotHasKey('account_number', $bank, 'The IBAN was listed twice');
    }

    public function test_bank_details_are_left_out_once_paid_or_for_another_method()
    {
        $this->assertNull(OrderMailer::bankTransferDetails($this->guestOrder(['payment_done' => 1])));

        $this->assertNull(OrderMailer::bankTransferDetails($this->guestOrder([
            'order_method' => config('constants.paymentMethod.SUMUP'),
        ])));
    }

    // ------------------------------------------------ the unpaid-transfer sweep

    /** An order placed $hours ago, on PHP's clock (MySQL's NOW() is hours off). */
    private function placedHoursAgo($hours, array $overrides = [])
    {
        $order = $this->guestOrder($overrides);

        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => now()->subHours($hours)->format('Y-m-d H:i:s'),
        ]);

        return $order;
    }

    private function status(Order $order)
    {
        return (int)$order->fresh()->status;
    }

    /**
     * The page promised this with a countdown, and nothing did it: unpaid
     * orders held their stock until someone noticed.
     */
    public function test_an_overdue_unpaid_bank_order_is_cancelled()
    {
        $order = $this->placedHoursAgo(CancelUnpaidBankTransfers::holdHours() + 1);

        $this->assertGreaterThanOrEqual(1, CancelUnpaidBankTransfers::sweep());

        $this->assertSame((int)config('constants.orderStatus.CANCELLED'), $this->status($order));

        $history = OrderStatusHistory::where('order_id', $order->id)->latest('id')->first();
        $this->assertSame('system', $history->changed_by_type);
        $this->assertSame((int)config('constants.orderStatus.CANCELLED'), (int)$history->to_status);

        $this->assertNotNull(
            OrderEmailLog::where('order_id', $order->id)
                ->where('event', OrderEmailEvent::ORDER_CANCELLED)
                ->first(),
            'The customer was not told their order was cancelled'
        );
    }

    public function test_an_order_inside_the_window_is_left_alone()
    {
        $order = $this->placedHoursAgo(CancelUnpaidBankTransfers::holdHours() - 1);

        CancelUnpaidBankTransfers::sweep();

        $this->assertSame((int)config('constants.orderStatus.PENDING'), $this->status($order));
    }

    /** The customer told us they paid; the order is waiting on us, not them. */
    public function test_an_order_with_a_transfer_reference_is_left_alone()
    {
        $order = $this->placedHoursAgo(CancelUnpaidBankTransfers::holdHours() + 5, ['trans_id' => 'REF123']);

        CancelUnpaidBankTransfers::sweep();

        $this->assertSame((int)config('constants.orderStatus.PENDING'), $this->status($order));
    }

    public function test_paid_confirmed_and_card_orders_are_left_alone()
    {
        $late = CancelUnpaidBankTransfers::holdHours() + 5;

        $paid = $this->placedHoursAgo($late, ['payment_done' => 1]);
        $confirmed = $this->placedHoursAgo($late, ['status' => config('constants.orderStatus.CONFIRMED')]);
        $card = $this->placedHoursAgo($late, ['order_method' => config('constants.paymentMethod.SUMUP')]);

        CancelUnpaidBankTransfers::sweep();

        $this->assertSame((int)config('constants.orderStatus.PENDING'), $this->status($paid));
        $this->assertSame((int)config('constants.orderStatus.CONFIRMED'), $this->status($confirmed));
        $this->assertSame((int)config('constants.orderStatus.PENDING'), $this->status($card));
    }

    public function test_a_second_sweep_does_not_cancel_twice()
    {
        $order = $this->placedHoursAgo(CancelUnpaidBankTransfers::holdHours() + 1);

        CancelUnpaidBankTransfers::sweep();
        CancelUnpaidBankTransfers::sweep();

        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)
            ->where('to_status', config('constants.orderStatus.CANCELLED'))
            ->count());
    }

    /** The page's countdown and the sweep aim at the same moment. */
    public function test_the_deadline_is_the_placed_time_plus_the_hold_window()
    {
        $deadline = CancelUnpaidBankTransfers::deadlineFor('2026-09-26 10:00:00');

        $this->assertSame(
            '2026-09-26 10:00:00',
            $deadline->copy()->subHours(CancelUnpaidBankTransfers::holdHours())->format('Y-m-d H:i:s')
        );
    }

    // ------------------------------------------------ admin confirms the money

    private function actAsAdmin()
    {
        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        Passport::actingAs($admin, ['admin'], 'admin');
    }

    /** The receipt went out when the order was placed, as it does for real. */
    private function receiptAlreadySent(Order $order)
    {
        DB::table('order_email_log')->insert([
            'order_id' => $order->id,
            'event' => OrderEmailEvent::ORDER_PLACED,
            'status' => OrderEmailLog::STATUS_SENT,
            'recipient' => $order->contact_email,
            'attempts' => 1,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function markPaid(Order $order, $paid = true)
    {
        return $this->postJson('/api/admin/order/payment-status', [
            'id' => $order->id,
            'payment_done' => $paid ? 1 : 0,
        ]);
    }

    private function paymentReceivedCount(Order $order)
    {
        return OrderEmailLog::where('order_id', $order->id)
            ->where('event', OrderEmailEvent::PAYMENT_RECEIVED)
            ->count();
    }

    /**
     * An admin marking off a transfer told the customer nothing: the
     * "payment received" email existed and only the gateways ever sent it.
     */
    public function test_marking_a_bank_order_paid_tells_the_customer()
    {
        $this->actAsAdmin();

        $order = $this->guestOrder();
        $this->receiptAlreadySent($order);

        $this->markPaid($order)->assertOk()->assertJsonPath('status', 200);

        $this->assertSame(1, (int)$order->fresh()->payment_done);
        $this->assertSame(1, $this->paymentReceivedCount($order));
    }

    public function test_toggling_paid_back_and_forth_emails_once()
    {
        $this->actAsAdmin();

        $order = $this->guestOrder();
        $this->receiptAlreadySent($order);

        $this->markPaid($order);
        $this->markPaid($order, false);
        $this->markPaid($order);

        $this->assertSame(1, $this->paymentReceivedCount($order));
    }

    // ------------------------------------------------ cancelled orders

    private function cancelledStatus()
    {
        return (int)config('constants.orderStatus.CANCELLED');
    }

    private function pay(Order $order, $method)
    {
        return $this->postJson('/api/v1/order/payment-done', [
            'data' => $this->payload([
                'id' => $order->id,
                'payment_token' => $order->id,
                'order_method' => $method,
                'user_token' => $order->user_token,
            ]),
        ]);
    }

    /**
     * The order page kept its Pay buttons on an order an admin or the sweep
     * had cancelled, and the server took the money.
     */
    public function test_a_cancelled_order_cannot_switch_to_bank_transfer()
    {
        $order = $this->guestOrder([
            'order_method' => config('constants.paymentMethod.SUMUP'),
            'status' => $this->cancelledStatus(),
        ]);

        $this->pay($order, config('constants.paymentMethod.BANK'))
            ->assertJsonMissing(['awaiting_transfer' => true]);

        $this->assertSame((int)config('constants.paymentMethod.SUMUP'), (int)$order->fresh()->order_method);
    }

    public function test_a_cancelled_order_cannot_be_paid_from_the_wallet()
    {
        $order = $this->guestOrder(['status' => $this->cancelledStatus()]);

        $body = $this->pay($order, config('constants.paymentMethod.WALLET'))->json();

        $this->assertNotSame(200, $body['status'] ?? null);
        $this->assertStringContainsString('cancelled', json_encode($body));
        $this->assertSame(0, (int)$order->fresh()->payment_done);
    }

    /** Our refusal, not the card's: no "your payment failed" email. */
    public function test_refusing_a_cancelled_order_sends_no_payment_failed_email()
    {
        $order = $this->guestOrder(['status' => $this->cancelledStatus()]);

        $this->pay($order, config('constants.paymentMethod.WALLET'));

        $this->assertNull(OrderEmailLog::where('order_id', $order->id)
            ->where('event', OrderEmailEvent::PAYMENT_FAILED)->first());
    }

    /** A staff-cancelled order has no cancelled flag; the flag check missed it. */
    public function test_a_staff_cancelled_order_cannot_be_marked_paid()
    {
        $this->actAsAdmin();

        $order = $this->guestOrder(['status' => $this->cancelledStatus()]);

        $this->markPaid($order);

        $this->assertSame(0, (int)$order->fresh()->payment_done);
    }

    // ------------------------------------------------ cash on an existing order

    /** One line, fulfilled as collection (PICKUP) or delivery (LOCATION). */
    private function withLine(Order $order, $shippingType)
    {
        $inventory = \App\Models\UpdatedInventory::first();

        \App\Models\OrderedProduct::create([
            'order_id' => $order->id,
            'product_id' => $inventory->product_id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
            'selling' => 10,
            'shipping_place_id' => DB::table('shipping_places')->value('id'),
            'shipping_type' => $shippingType,
        ]);

        return $order;
    }

    private function paymentFailedSent(Order $order)
    {
        return OrderEmailLog::where('order_id', $order->id)
            ->where('event', OrderEmailEvent::PAYMENT_FAILED)
            ->exists();
    }

    /**
     * Same bug as the bank transfer: choosing cash on the order page fell
     * through to the "no gateway branch" refusal and emailed "payment failed".
     */
    public function test_choosing_cash_for_a_collection_order_sends_no_failure_email()
    {
        $order = $this->withLine($this->guestOrder([
            'order_method' => config('constants.paymentMethod.SUMUP'),
        ]), config('constants.shippingTypeIn.PICKUP'));

        $this->pay($order, config('constants.paymentMethod.CASH_ON_DELIVERY'))
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.pay_on_collection', true);

        $fresh = $order->fresh();
        $this->assertSame((int)config('constants.paymentMethod.CASH_ON_DELIVERY'), (int)$fresh->order_method);
        $this->assertSame(0, (int)$fresh->payment_done, 'Cash is paid at collection, not now');
        $this->assertFalse($this->paymentFailedSent($order));
    }

    public function test_cash_is_refused_for_a_delivery_order_without_a_failure_email()
    {
        $order = $this->withLine($this->guestOrder([
            'order_method' => config('constants.paymentMethod.SUMUP'),
        ]), config('constants.shippingTypeIn.LOCATION'));

        $body = $this->pay($order, config('constants.paymentMethod.CASH_ON_DELIVERY'))->json();

        $this->assertNotSame(200, $body['status'] ?? null);
        $this->assertSame((int)config('constants.paymentMethod.SUMUP'), (int)$order->fresh()->order_method);
        $this->assertFalse($this->paymentFailedSent($order), 'A rule refusal is not a failed payment');
    }

    // ------------------------------------------------ the transfer reference

    private function sendReference(Order $order, $reference)
    {
        return $this->postJson('/api/v1/order/transaction', [
            'data' => $this->payload([
                'id' => $order->id,
                'trans_id' => $reference,
                'user_token' => $order->user_token,
            ]),
        ]);
    }

    /**
     * The first reference was final and every later one was dropped while
     * the customer was told it had saved.
     */
    public function test_a_mistyped_reference_can_be_corrected()
    {
        $order = $this->guestOrder();

        $this->sendReference($order, 'REF-TYPO')->assertJsonPath('status', 200);
        $this->sendReference($order, 'REF-RIGHT')->assertJsonPath('status', 200);

        $this->assertSame('REF-RIGHT', $order->fresh()->trans_id);
    }

    public function test_a_card_order_takes_no_transfer_reference()
    {
        $order = $this->guestOrder(['order_method' => config('constants.paymentMethod.SUMUP')]);

        $body = $this->sendReference($order, 'REF123')->json();

        $this->assertNotSame(200, $body['status'] ?? null);
        $this->assertNull($order->fresh()->trans_id);
    }

    public function test_a_paid_order_keeps_its_reference()
    {
        $order = $this->guestOrder(['payment_done' => 1, 'trans_id' => 'VERIFIED']);

        $this->sendReference($order, 'OVERWRITE');

        $this->assertSame('VERIFIED', $order->fresh()->trans_id);
    }

    // ------------------------------------------------ staff hear about references

    /** Messages sent to the shop about this order, read off the array transport. */
    private function staffNotices(Order $order)
    {
        $messages = \Illuminate\Support\Facades\Mail::getSwiftMailer()->getTransport()->messages();
        $messages = is_array($messages) ? $messages : $messages->all();

        return array_values(array_filter($messages, function ($message) use ($order) {
            return strpos($message->getSubject(), 'Bank transfer to check: order ' . $order->order) === 0;
        }));
    }

    /**
     * The reference sat on the order until someone opened it, and the sweep
     * now leaves such an order for staff, so staff have to be told.
     */
    public function test_staff_are_told_when_a_reference_arrives()
    {
        \Illuminate\Support\Facades\Mail::getSwiftMailer()->getTransport()->flush();

        $order = $this->guestOrder();

        $this->sendReference($order, 'REF-ONE')->assertJsonPath('status', 200);

        $notices = $this->staffNotices($order);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('REF-ONE', $notices[0]->getBody());
        $this->assertStringContainsString('/admin/orders/' . $order->id, $notices[0]->getBody());
    }

    public function test_a_corrected_reference_is_news_and_a_repeated_one_is_not()
    {
        \Illuminate\Support\Facades\Mail::getSwiftMailer()->getTransport()->flush();

        $order = $this->guestOrder();

        $this->sendReference($order, 'REF-TYPO');
        $this->sendReference($order, 'REF-TYPO');
        $this->sendReference($order, 'REF-RIGHT');

        $notices = $this->staffNotices($order);
        $this->assertCount(2, $notices);
        $this->assertStringContainsString('Previous reference: REF-TYPO', $notices[1]->getBody());
    }

    // ------------------------------------------------ charged for a cancelled order

    private function alerts(Order $order)
    {
        $messages = \Illuminate\Support\Facades\Mail::getSwiftMailer()->getTransport()->messages();
        $messages = is_array($messages) ? $messages : $messages->all();

        return array_values(array_filter($messages, function ($message) use ($order) {
            return $message->getSubject() === 'Action needed: payment taken for cancelled order ' . $order->order;
        }));
    }

    /** SumUp answers that the checkout was paid, for the order's amount. */
    private function sumupSaysPaid($checkoutId, $amount)
    {
        Payment::query()->update(['sumup' => 1, 'sumup_api_key' => 'sup_sk_test']);

        \Illuminate\Support\Facades\Http::fake([
            'api.sumup.com/v0.1/checkouts/' . $checkoutId => \Illuminate\Support\Facades\Http::response([
                'id' => $checkoutId, 'status' => 'PAID', 'amount' => $amount,
                'currency' => 'EUR', 'transaction_code' => 'TX-' . $checkoutId,
            ]),
        ]);
    }

    private function payBySumup(Order $order, $checkoutId)
    {
        return $this->postJson('/api/v1/order/payment-done', [
            'data' => $this->payload([
                'id' => $order->id,
                'payment_token' => $checkoutId,
                'order_method' => config('constants.paymentMethod.SUMUP'),
                'user_token' => $order->user_token,
            ]),
        ]);
    }

    /**
     * The card is charged while the order is being cancelled. It used to be
     * a log line: nobody was told, and the customer got "payment received"
     * for an order that was not going anywhere.
     */
    public function test_a_card_payment_on_a_cancelled_order_alerts_the_shop()
    {
        \Illuminate\Support\Facades\Mail::getSwiftMailer()->getTransport()->flush();

        $order = $this->guestOrder([
            'order_method' => config('constants.paymentMethod.SUMUP'),
            'status' => $this->cancelledStatus(),
        ]);
        $this->receiptAlreadySent($order);
        $this->sumupSaysPaid('chk_cancelled_1', 149.99);

        $this->payBySumup($order, 'chk_cancelled_1')->assertJsonPath('status', 200);

        $this->assertSame(1, (int)$order->fresh()->payment_done, 'The money moved; it has to be on record');

        $alerts = $this->alerts($order);
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('TX-chk_cancelled_1', $alerts[0]->getBody());
        $this->assertStringContainsString('/admin/orders/' . $order->id, $alerts[0]->getBody());

        $this->assertSame(0, $this->paymentReceivedCount($order),
            'The customer was told their payment was received for a cancelled order');
    }

    public function test_a_card_payment_on_an_open_order_raises_no_alert()
    {
        \Illuminate\Support\Facades\Mail::getSwiftMailer()->getTransport()->flush();

        $order = $this->guestOrder(['order_method' => config('constants.paymentMethod.SUMUP')]);
        $this->receiptAlreadySent($order);
        $this->sumupSaysPaid('chk_open_1', 149.99);

        $this->payBySumup($order, 'chk_open_1')->assertJsonPath('status', 200);

        $this->assertSame(1, (int)$order->fresh()->payment_done);
        $this->assertCount(0, $this->alerts($order));
        $this->assertSame(1, $this->paymentReceivedCount($order));
    }

    // ------------------------------------------------ the admin list chips

    private function listed($filter, array $orders)
    {
        $this->actAsAdmin();

        $marker = 'bt-' . uniqid();
        DB::table('orders')->whereIn('id', collect($orders)->pluck('id'))->update(['company_name' => $marker]);

        $response = $this->getJson('/api/admin/order/all?orderby=created_at&type=desc&limit=200&filter=' . $filter);
        $this->assertSame(200, $response->json('status'), json_encode($response->json('message')));

        return collect($response->json('data.data'))
            ->where('company_name', $marker)
            ->pluck('id')
            ->sort()->values()->all();
    }

    public function test_the_awaiting_transfer_chip_lists_only_open_unpaid_bank_orders()
    {
        $waiting = $this->guestOrder();
        $withReference = $this->guestOrder(['trans_id' => 'REF123']);
        $paid = $this->guestOrder(['payment_done' => 1]);
        $cancelled = $this->guestOrder(['status' => $this->cancelledStatus()]);
        $card = $this->guestOrder(['order_method' => config('constants.paymentMethod.SUMUP')]);

        $ids = $this->listed('awaiting_transfer', [$waiting, $withReference, $paid, $cancelled, $card]);

        $this->assertSame(collect([$waiting->id, $withReference->id])->sort()->values()->all(), $ids);
    }

    /** The chip read only the customer's flag and hid every other cancellation. */
    public function test_the_cancelled_chip_includes_staff_and_sweep_cancellations()
    {
        // `cancelled` is not mass-assignable; set it the way the customer's
        // cancellation does.
        $byCustomer = $this->guestOrder();
        DB::table('orders')->where('id', $byCustomer->id)->update(['cancelled' => 1]);
        $byShop = $this->guestOrder(['status' => $this->cancelledStatus()]);
        $open = $this->guestOrder();

        $ids = $this->listed('cancelled', [$byCustomer, $byShop, $open]);

        $this->assertSame(collect([$byCustomer->id, $byShop->id])->sort()->values()->all(), $ids);
    }

    // ------------------------------------------------ a transfer that arrives late

    /** An overdue bank order with one real stock line, cancelled by the sweep. */
    private function sweptOrder()
    {
        $inventory = \App\Models\UpdatedInventory::where('quantity', '>=', 3)->first();

        if (!$inventory) {
            $this->markTestSkipped('No inventory row with stock to build the order on.');
        }

        $order = $this->placedHoursAgo(CancelUnpaidBankTransfers::holdHours() + 1);

        \App\Models\OrderedProduct::create([
            'order_id' => $order->id,
            'product_id' => $inventory->product_id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
            'selling' => 10,
            'shipping_place_id' => DB::table('shipping_places')->value('id'),
            'shipping_type' => 1,
        ]);

        $before = (int)$inventory->fresh()->quantity;

        CancelUnpaidBankTransfers::sweep();

        $this->assertSame($this->cancelledStatus(), $this->status($order));
        $this->assertSame($before + 1, (int)$inventory->fresh()->quantity, 'The sweep did not give the stock back');

        return [$order, $inventory];
    }

    public function test_a_late_transfer_reinstates_a_swept_order()
    {
        $this->actAsAdmin();
        [$order, $inventory] = $this->sweptOrder();
        $this->receiptAlreadySent($order);
        $stock = (int)$inventory->fresh()->quantity;

        $this->markPaid($order)->assertJsonPath('data.reinstated', true);

        $fresh = $order->fresh();
        $this->assertSame((int)config('constants.orderStatus.PENDING'), (int)$fresh->status);
        $this->assertSame(1, (int)$fresh->payment_done);
        $this->assertSame($stock - 1, (int)$inventory->fresh()->quantity, 'The stock was not taken again');
        $this->assertSame(1, $this->paymentReceivedCount($order));
    }

    /** The release guard used to see the first cancellation and stop. */
    public function test_cancelling_a_reinstated_order_gives_the_stock_back_again()
    {
        $this->actAsAdmin();
        [$order, $inventory] = $this->sweptOrder();
        $this->markPaid($order);
        $stock = (int)$inventory->fresh()->quantity;

        $this->postJson('/api/admin/order/update-status', [
            'id' => $order->id,
            'status' => $this->cancelledStatus(),
        ])->assertOk();

        $this->assertSame($this->cancelledStatus(), $this->status($order));
        $this->assertSame($stock + 1, (int)$inventory->fresh()->quantity);
    }

    public function test_a_swept_order_that_used_points_is_not_reinstated()
    {
        $this->actAsAdmin();
        [$order] = $this->sweptOrder();

        \App\Models\PointTransaction::create([
            'user_id' => DB::table('users')->value('id'),
            'type' => 'debit',
            'points' => 100,
            'value' => 1,
            'source' => 'order',
            'reference_id' => $order->id,
        ]);

        $body = $this->markPaid($order)->json();

        $this->assertNotSame(200, $body['status'] ?? null);
        $this->assertStringContainsString('refund', json_encode($body));
        $this->assertSame($this->cancelledStatus(), $this->status($order));
        $this->assertSame(0, (int)$order->fresh()->payment_done);
    }

    public function test_a_swept_order_whose_stock_has_gone_is_not_reinstated()
    {
        $this->actAsAdmin();
        [$order, $inventory] = $this->sweptOrder();

        \App\Models\UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 0]);

        $body = $this->markPaid($order)->json();

        $this->assertStringContainsString('no longer', json_encode($body));
        $this->assertSame($this->cancelledStatus(), $this->status($order));
        $this->assertSame(0, (int)$inventory->fresh()->quantity);
    }

    public function test_marking_unpaid_sends_nothing()
    {
        $this->actAsAdmin();

        $order = $this->guestOrder(['payment_done' => 1]);
        $this->receiptAlreadySent($order);

        $this->markPaid($order, false);

        $this->assertSame(0, $this->paymentReceivedCount($order));
    }
}
