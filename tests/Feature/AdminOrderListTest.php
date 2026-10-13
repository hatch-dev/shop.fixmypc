<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The admin order list, and the filter chips above it.
 *
 * The chips were all folded into one `orWhere` group, so they widened the
 * result instead of narrowing it. Ticking "Paid" and "Cash on delivery"
 * asked the database for "payment_done = 1 OR order_method = 2" — which
 * returns every paid order of any method *and* every cash order including
 * the unpaid ones. An admin filtering for paid orders was shown unpaid ones,
 * which is the one thing a payment filter must never do.
 *
 * The rows are built here rather than driven through checkout: what is under
 * test is the query, and a handful of orders with known shapes exercises every
 * combination far more precisely than three real baskets would.
 */
class AdminOrderListTest extends TestCase
{
    use DatabaseTransactions;

    private $customer;

    private function admin()
    {
        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    private function customer()
    {
        if ($this->customer) {
            return $this->customer;
        }

        return $this->customer = User::create([
            'name' => 'Filter Fixture',
            'email' => 'filter.' . uniqid() . '@example.test',
            'phone' => '0871234567',
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'business',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    /**
     * One order with a known method / payment state.
     *
     * Marked with a company name unique to this run, so the assertions can
     * pick this test's rows out of whatever else is in the database.
     */
    private function order($method, $paid, $cancelled = false)
    {
        $order = Order::create([
            'status' => config('constants.orderStatus.PENDING'),
            'order_method' => $method,
            'user_id' => $this->customer()->id,
            'payment_done' => $paid ? 1 : 0,
            'total_amount' => 100,
            'currency' => 'EUR',
            'order' => 'FILTER' . uniqid(),
            'account_type' => 'business',
            'company_name' => $this->marker(),
        ]);

        /*
         * `cancelled` is deliberately not fillable — it is set by the
         * cancellation flow, not by whatever creates an order — so it has to be
         * written past the guard rather than passed to create(), which drops it
         * silently and leaves the fixture uncancelled.
         */
        if ($cancelled) {
            $order->forceFill(['cancelled' => 1])->save();
        }

        return $order->fresh();
    }

    private function marker()
    {
        return 'Filter Fixture Co';
    }

    /** The list as the admin screen receives it, this test's rows only. */
    private function list($query = '')
    {
        $this->admin();

        $response = $this->getJson(
            '/api/admin/order/all?orderby=created_at&type=desc&limit=200' . $query
        );

        $this->assertSame(200, $response->json('status'),
            'the order list did not load: ' . json_encode($response->json('message')));

        return collect($response->json('data.data'))
            ->filter(function ($row) {
                return ($row['company_name'] ?? null) === $this->marker();
            })
            ->values();
    }

    // ------------------------------------------------------------- the chips

    public function test_the_paid_filter_never_returns_an_unpaid_order()
    {
        $cod = (int)config('constants.paymentMethod.CASH_ON_DELIVERY');

        $paidCard = $this->order((int)config('constants.paymentMethod.STRIPE'), true);
        $unpaidCash = $this->order($cod, false);
        $paidCash = $this->order($cod, true);

        // The combination that broke: a payment state and a payment method.
        $rows = $this->list('&filter=paid,cash_on_delivery');

        $ids = $rows->pluck('id')->all();

        $this->assertContains($paidCash->id, $ids, 'a paid cash order belongs here');
        $this->assertNotContains($unpaidCash->id, $ids,
            'the Paid filter returned an unpaid order');
        $this->assertNotContains($paidCard->id, $ids,
            'the Cash on delivery filter returned a card order');
    }

    public function test_two_methods_widen_and_a_payment_state_narrows()
    {
        $cod = (int)config('constants.paymentMethod.CASH_ON_DELIVERY');
        $paypal = (int)config('constants.paymentMethod.PAYPAL');

        $paidCash = $this->order($cod, true);
        $paidPaypal = $this->order($paypal, true);
        $unpaidPaypal = $this->order($paypal, false);
        $paidStripe = $this->order((int)config('constants.paymentMethod.STRIPE'), true);

        $ids = $this->list('&filter=paid,cash_on_delivery,paypal')->pluck('id')->all();

        $this->assertContains($paidCash->id, $ids);
        $this->assertContains($paidPaypal->id, $ids);
        $this->assertNotContains($unpaidPaypal->id, $ids, 'unpaid slipped through');
        $this->assertNotContains($paidStripe->id, $ids, 'a method nobody asked for');
    }

    public function test_paid_and_unpaid_together_ask_nothing_about_payment()
    {
        $cod = (int)config('constants.paymentMethod.CASH_ON_DELIVERY');

        $paid = $this->order($cod, true);
        $unpaid = $this->order($cod, false);

        $ids = $this->list('&filter=paid,unpaid')->pluck('id')->all();

        $this->assertContains($paid->id, $ids);
        $this->assertContains($unpaid->id, $ids);
    }

    public function test_cancelled_narrows_as_well()
    {
        $cod = (int)config('constants.paymentMethod.CASH_ON_DELIVERY');

        $live = $this->order($cod, false);
        $cancelled = $this->order($cod, false, true);

        $ids = $this->list('&filter=cancelled,cash_on_delivery')->pluck('id')->all();

        $this->assertContains($cancelled->id, $ids);
        $this->assertNotContains($live->id, $ids,
            'the Cancelled filter returned a live order');
    }

    /**
     * SumUp is a card. It was absent from the card_payment branch, so every
     * order taken on the shop's own card terminal was hidden by the filter
     * meant to find card orders.
     */
    public function test_a_sumup_order_counts_as_a_card_payment()
    {
        $sumup = $this->order((int)config('constants.paymentMethod.SUMUP'), true);

        $ids = $this->list('&filter=card_payment')->pluck('id')->all();

        $this->assertContains($sumup->id, $ids);
    }

    // ------------------------------------------------------- what a row says

    public function test_every_row_names_its_customer_and_account_type()
    {
        $order = $this->order((int)config('constants.paymentMethod.CASH_ON_DELIVERY'), false);

        $row = $this->list()->firstWhere('id', $order->id);

        $this->assertNotNull($row, 'the order is missing from the list');

        // No address on this order at all — the list used to read only that.
        $this->assertSame('Filter Fixture', $row['customer']['name']);
        $this->assertSame('business', $row['customer']['account_type']);
        $this->assertNotEmpty($row['customer']['email']);
    }

    /**
     * Section 28: the customer screen links here, so the endpoint has to be
     * able to answer "what has this person ordered".
     */
    public function test_the_list_can_be_narrowed_to_one_customer()
    {
        $cod = (int)config('constants.paymentMethod.CASH_ON_DELIVERY');

        $mine = $this->order($cod, false);

        $somebodyElse = User::create([
            'name' => 'Another Customer',
            'email' => 'other.' . uniqid() . '@example.test',
            'phone' => '0879999999',
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ]);

        $theirs = Order::create([
            'status' => config('constants.orderStatus.PENDING'),
            'order_method' => $cod,
            'user_id' => $somebodyElse->id,
            'payment_done' => 0,
            'total_amount' => 100,
            'currency' => 'EUR',
            'order' => 'FILTER' . uniqid(),
            'account_type' => 'personal',
            'company_name' => $this->marker(),
        ]);

        $ids = $this->list('&user_id=' . $this->customer()->id)->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids,
            'another customer\'s order came back');
    }

    // ----------------------------------------------------------- deleting

    /**
     * Deleting used to destroy the order row, its line items, its frozen
     * prices, its voucher and flash-discount rows, its cancellation and its
     * entire status history, with no undo. A mis-click on the wrong row in the
     * list took a financial record with it.
     */
    public function test_deleting_an_order_hides_it_but_keeps_the_record()
    {
        $cod = (int)config('constants.paymentMethod.CASH_ON_DELIVERY');

        $kept = $this->order($cod, false);
        $deleted = $this->order($cod, false);

        $this->admin();

        $response = $this->deleteJson('/api/admin/order/delete/' . $deleted->id);

        $this->assertSame(200, $response->json('status'),
            'the delete failed: ' . json_encode($response->json('message')));

        $ids = $this->list()->pluck('id')->all();

        $this->assertContains($kept->id, $ids);
        $this->assertNotContains($deleted->id, $ids, 'a deleted order is still listed');

        // The row, and everything hanging off it, is still there.
        $this->assertDatabaseHas('orders', ['id' => $deleted->id]);

        $this->assertNotNull(
            Order::withTrashed()->find($deleted->id)->deleted_at,
            'the order was hard deleted'
        );
    }

    public function test_a_deleted_order_cannot_be_opened_or_deleted_again()
    {
        $deleted = $this->order((int)config('constants.paymentMethod.CASH_ON_DELIVERY'), false);

        $this->admin();
        $this->deleteJson('/api/admin/order/delete/' . $deleted->id);

        /*
         * Asserted on the payload, not the HTTP status: Validation::
         * nothingFoundLang wraps its refusal in a 200 envelope with a null
         * `data` and the message in `message`, which is this API's convention.
         */
        $find = $this->getJson("/api/admin/order/find/{$deleted->id}");

        $this->assertNull($find->json('data.id'), 'a deleted order still opens');

        $again = $this->deleteJson('/api/admin/order/delete/' . $deleted->id);

        $this->assertNotTrue($again->json('data'),
            'a deleted order was deleted a second time');
    }

    /**
     * The whole point of keeping the row: clearing deleted_at brings the order
     * back, with its line items and history intact.
     */
    public function test_a_deleted_order_can_be_restored()
    {
        $deleted = $this->order((int)config('constants.paymentMethod.CASH_ON_DELIVERY'), false);

        $this->admin();
        $this->deleteJson('/api/admin/order/delete/' . $deleted->id);

        Order::withTrashed()->find($deleted->id)->restore();

        $this->assertContains($deleted->id, $this->list()->pluck('id')->all(),
            'the restored order did not come back to the list');
    }

    /**
     * The audit trail says what happened to it, which is the thing the old
     * hard delete threw away.
     */
    public function test_deleting_records_who_deleted_it()
    {
        $deleted = $this->order((int)config('constants.paymentMethod.CASH_ON_DELIVERY'), false);

        $admin = $this->admin();
        $this->deleteJson('/api/admin/order/delete/' . $deleted->id);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $deleted->id,
            'to_status' => (int)config('constants.orderStatus.CANCELLED'),
            'changed_by' => $admin->id,
            'changed_by_type' => 'admin',
        ]);
    }

    public function test_the_list_does_not_carry_the_whole_user_record()
    {
        $this->order((int)config('constants.paymentMethod.CASH_ON_DELIVERY'), false);

        $row = $this->list()->first();

        $this->assertArrayNotHasKey('user', $row);
        $this->assertArrayNotHasKey('password', $row['customer']);
    }
}
