<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The Savers Club page's API: wallet history in words, the statement
 * download, the top-up limits, and the qualifying products list.
 */
class SaversClubTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    private $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::create([
            'name' => 'Savers Club',
            'email' => 'fixmypc-savers-' . uniqid() . '@yopmail.com',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();

        Passport::actingAs($this->customer, ['user'], 'user');
    }

    private function txn($source, $type, $amount, $balance, $referenceId = null)
    {
        return WalletTransaction::create([
            'user_id' => $this->customer->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balance,
            'source' => $source,
            'reference_id' => $referenceId,
            'status' => 'success',
        ]);
    }

    public function test_transactions_are_described_in_words_not_source_keys()
    {
        $order = Order::create([
            'order' => 'SAVERS' . uniqid(),
            'user_id' => $this->customer->id,
            'status' => config('constants.orderStatus.PENDING'),
            'order_method' => config('constants.paymentMethod.WALLET'),
            'total_amount' => 20,
            'currency' => 'EUR',
            'payment_done' => 1,
            'contact_name' => 'Aoife Murphy',
            'contact_email' => $this->customer->email,
            'contact_phone' => '+353871234567',
        ]);

        $this->txn('admin', 'credit', 100, 100);
        $this->txn('topup', 'credit', 25, 125, 'TOPUP-REF');
        $this->txn('order', 'debit', 20, 105, $order->id);
        $this->txn('refund', 'credit', 20, 125, $order->id);
        $this->txn('gift_voucher', 'debit', 10, 115);

        $rows = collect($this->getJson('/api/v1/user/wallet/transactions')->assertOk()->json('data'))
            ->pluck('description')->all();

        $this->assertSame([
            'Gift card purchase',
            'Refund for order #' . $order->order,
            'Payment for order #' . $order->order,
            'Wallet top-up',
            'Adjustment by FixMyPC',
        ], $rows);

        // order and refund rows carry the order to link to; the rest do not
        $links = collect($this->getJson('/api/v1/user/wallet/transactions')->json('data'))->pluck('order_id')->all();
        $this->assertSame([null, $order->id, $order->id, null, null], $links);

        // a deleted order keeps its number in the text but loses the link
        $order->delete();
        $after = collect($this->getJson('/api/v1/user/wallet/transactions')->json('data'));
        $this->assertSame('Payment for order #' . $order->order, $after[2]['description']);
        $this->assertNull($after[2]['order_id']);
    }

    public function test_transactions_sort_and_filter()
    {
        $this->txn('admin', 'credit', 100, 100);
        $this->txn('gift_voucher', 'debit', 10, 90);
        $this->txn('topup', 'credit', 25, 115);

        $amounts = function ($query) {
            return collect($this->getJson('/api/v1/user/wallet/transactions' . $query)->assertOk()->json('data'))
                ->pluck('amount')->all();
        };

        $this->assertSame(['25.00', '10.00', '100.00'], $amounts(''));
        $this->assertSame(['100.00', '10.00', '25.00'], $amounts('?order=asc'));
        $this->assertSame(['25.00', '100.00'], $amounts('?type=credit'));
        $this->assertSame(['10.00'], $amounts('?type=debit'));
        $this->assertSame(['100.00', '25.00'], $amounts('?type=credit&order=asc'));
        // anything else is ignored rather than breaking the list
        $this->assertSame(['25.00', '10.00', '100.00'], $amounts('?type=bogus&order=sideways'));

        // the paginator's next-page link keeps the choice
        for ($i = 0; $i < 12; $i++) {
            $this->txn('topup', 'credit', 1, 1);
        }
        $next = $this->getJson('/api/v1/user/wallet/transactions?type=credit&order=asc')->json('next_page_url');
        $this->assertStringContainsString('type=credit', $next);
        $this->assertStringContainsString('order=asc', $next);
    }

    public function test_points_activity_sort_and_filter()
    {
        foreach ([['credit', 120], ['debit', 30], ['credit', 5]] as [$type, $pts]) {
            \App\Models\PointTransaction::create([
                'user_id' => $this->customer->id, 'type' => $type, 'points' => $pts,
                'value' => $pts * 0.01, 'source' => 'order', 'reference_id' => null,
            ]);
        }

        $points = function ($query) {
            return collect($this->getJson('/api/v1/user/points/history' . $query)->assertOk()->json('data'))
                ->pluck('points')->map(function ($p) { return (int)$p; })->all();
        };

        $this->assertSame([5, 30, 120], $points(''));
        $this->assertSame([120, 30, 5], $points('?order=asc'));
        $this->assertSame([5, 120], $points('?type=credit'));
        $this->assertSame([30], $points('?type=debit'));
    }

    public function test_the_list_reports_its_range_for_the_page_counter()
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->txn('admin', 'credit', 1, $i);
        }

        $page = $this->getJson('/api/v1/user/wallet/transactions?page=3')->json();

        $this->assertSame(21, $page['from']);
        $this->assertSame(23, $page['to']);
        $this->assertSame(23, $page['total']);
    }

    public function test_the_statement_is_a_csv_of_the_whole_history()
    {
        // more than one page of the on-screen list (10 a page)
        for ($i = 1; $i <= 12; $i++) {
            $this->txn('admin', 'credit', 1, $i);
        }
        $this->txn('gift_voucher', 'debit', 2.5, 9.5);

        $res = $this->get('/api/v1/user/wallet/statement');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $res->headers->get('Content-Disposition'));

        $lines = array_values(array_filter(explode("\n", $res->getContent())));
        $this->assertCount(14, $lines); // header + 13 rows
        $this->assertSame('Date,Description,Type,"Amount (EUR)","Balance (EUR)",Status', $lines[0]);
        $this->assertStringContainsString('"Gift card purchase",Debit,-2.50,9.50,Success', $lines[1]);
    }

    public function test_the_statement_holds_only_the_callers_rows()
    {
        $other = User::create([
            'name' => 'Someone Else',
            'email' => 'fixmypc-savers-other-' . uniqid() . '@yopmail.com',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'status' => User::STATUS_ACTIVE,
        ]);
        WalletTransaction::create([
            'user_id' => $other->id, 'type' => 'credit', 'amount' => 999, 'balance_after' => 999,
            'source' => 'admin', 'status' => 'success',
        ]);
        $this->txn('topup', 'credit', 5, 5);

        $csv = $this->get('/api/v1/user/wallet/statement')->getContent();

        $this->assertStringNotContainsString('999.00', $csv);
        $this->assertStringContainsString('"Wallet top-up",Credit,5.00,5.00', $csv);
    }

    /** @dataProvider badTopups */
    public function test_a_top_up_outside_the_limits_is_refused_before_sumup($amount)
    {
        Http::fake();

        $res = $this->postJson('/api/create-sumup-checkout', [
            'amount' => $amount, 'currency' => 'EUR', 'is_topup' => true,
        ]);

        $this->assertNull($res->json('id'));
        Http::assertNothingSent();
    }

    public function badTopups()
    {
        return [
            'below the minimum' => [0.5],
            'above the maximum' => [1000.01],
            'fractions of a cent' => [12.345],
            'negative' => [-20],
            'not a number' => ['abc'],
        ];
    }

    public function test_a_top_up_inside_the_limits_reaches_sumup()
    {
        Http::fake(['api.sumup.com/*' => Http::response(['id' => 'chk_test', 'checkout_reference' => 'r'], 200)]);

        $res = $this->postJson('/api/create-sumup-checkout', [
            'amount' => 1000, 'currency' => 'EUR', 'is_topup' => true,
        ]);

        $this->assertSame('chk_test', $res->json('id'));
    }

    /** What SumUp answers when asked about a checkout. */
    private function sumupSays(array $checkout)
    {
        Http::fake(['api.sumup.com/v0.1/checkouts/*' => Http::response(array_merge([
            'status' => 'PAID',
            'amount' => 50,
            'currency' => 'EUR',
            'description' => 'Top-up Payment #TOPUP-TEST',
            'checkout_reference' => 'TOPUP-' . uniqid(),
            'transaction_code' => 'TX' . uniqid(),
        ], $checkout), 200)]);
    }

    private function balance()
    {
        return (float)\App\Models\Wallet::where('user_id', $this->customer->id)->value('balance');
    }

    public function test_a_paid_top_up_credits_the_wallet_once()
    {
        $this->sumupSays(['checkout_reference' => 'TOPUP-ONCE-' . uniqid()]);

        $first = $this->postJson('/api/v1/user/wallet/topup/confirm', ['checkout_id' => 'chk_1', 'amount' => 50]);
        $first->assertOk();
        $this->assertSame('Wallet credited successfully', $first->json('message'));
        $this->assertSame(50.0, $this->balance());

        // the page reloading, or a double click, confirms the same checkout again
        $again = $this->postJson('/api/v1/user/wallet/topup/confirm', ['checkout_id' => 'chk_1', 'amount' => 50]);
        $again->assertOk();
        $this->assertSame('Wallet already credited for this payment', $again->json('message'));
        $this->assertSame(50.0, $this->balance());

        $rows = $this->getJson('/api/v1/user/wallet/transactions')->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Wallet top-up', $rows[0]['description']);
        $this->assertSame('50.00', $rows[0]['balance_after']);
    }

    public function test_a_declined_card_credits_nothing()
    {
        $this->sumupSays(['status' => 'FAILED']);

        $res = $this->postJson('/api/v1/user/wallet/topup/confirm', ['checkout_id' => 'chk_2', 'amount' => 50]);

        $res->assertStatus(422);
        $this->assertSame(0.0, $this->balance());
    }

    public function test_an_order_payment_cannot_be_used_as_a_top_up()
    {
        $this->sumupSays(['description' => 'Order Payment #ORDER-TEST']);

        $this->postJson('/api/v1/user/wallet/topup/confirm', ['checkout_id' => 'chk_3', 'amount' => 50])
            ->assertStatus(422);
        $this->assertSame(0.0, $this->balance());
    }

    public function test_the_browser_cannot_raise_the_amount_credited()
    {
        // paid 25 at SumUp, the browser claims 500
        $this->sumupSays(['amount' => 25]);

        $this->postJson('/api/v1/user/wallet/topup/confirm', ['checkout_id' => 'chk_4', 'amount' => 500])
            ->assertStatus(422);
        $this->assertSame(0.0, $this->balance());
    }

    public function test_qualifying_products_run_cheapest_first_across_pages_with_a_real_price()
    {
        $prices = [];
        foreach ([1, 2] as $page) {
            $res = $this->getJson('/api/v1/user/product/qualifying?page=' . $page)->assertOk();
            foreach ($res->json('data') as $p) {
                $this->assertArrayHasKey('price', $p);
                $this->assertGreaterThan(0, (float)$p['price']);
                $this->assertContains($p['vat_side'], ['inc', 'ex', null]);
                $this->assertSame((int)round($p['price'] * 20), $p['required_points']);
                // what the card says those points take off: points / 100, i.e. 20% of the price
                $this->assertSame(number_format($p['required_points'] / 100, 2, '.', ''), $p['points_value']);
                $prices[] = (float)$p['price'];
            }
        }

        $this->assertNotEmpty($prices);
        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices);
    }
    public function test_a_guest_cannot_start_a_top_up_or_gift_card_checkout()
    {
        Http::fake();
        $this->app["auth"]->forgetGuards();

        foreach (["is_topup", "is_gift_voucher"] as $flag) {
            $res = $this->withHeaders(["Authorization" => "Bearer not-a-token"])
                ->postJson("/api/create-sumup-checkout", ["amount" => 25, "currency" => "EUR", $flag => true]);
            $this->assertSame(401, $res->status(), $flag);
            $this->assertSame("Please sign in to continue.", $res->json("message"));
        }

        Http::assertNothingSent();
    }

    public function test_a_guest_can_still_pay_for_an_order()
    {
        Http::fake(["api.sumup.com/*" => Http::response(["id" => "chk_guest"], 200)]);
        $this->app["auth"]->forgetGuards();

        $res = $this->postJson("/api/create-sumup-checkout", ["amount" => 49.99, "currency" => "EUR"]);

        $this->assertSame("chk_guest", $res->json("id"));
    }

    public function test_points_history_says_what_happened_to_each_order()
    {
        $order = Order::create([
            "order" => "PTS" . uniqid(),
            "user_id" => $this->customer->id,
            "status" => config("constants.orderStatus.DELIVERED"),
            "order_method" => config("constants.paymentMethod.WALLET"),
            "total_amount" => 120,
            "currency" => "EUR",
            "payment_done" => 1,
            "contact_name" => "Aoife Murphy",
            "contact_email" => $this->customer->email,
            "contact_phone" => "+353871234567",
        ]);
        foreach ([["order", "debit", 300], ["order", "credit", 120], ["order_reversal", "credit", 300], ["order_reversal", "debit", 120]] as [$source, $type, $pts]) {
            \App\Models\PointTransaction::create([
                "user_id" => $this->customer->id, "type" => $type, "points" => $pts,
                "value" => $pts * 0.01, "source" => $source, "reference_id" => $order->id,
            ]);
        }

        $rows = $this->getJson("/api/v1/user/points/history")->assertOk()->json("data");

        $this->assertSame([
            "Removed: order #" . $order->order . " cancelled",
            "Returned: order #" . $order->order . " cancelled",
            "Earned on order #" . $order->order,
            "Used on order #" . $order->order,
        ], array_column($rows, "description"));
        $this->assertSame([120, 300, 120, 300], array_map("intval", array_column($rows, "points")));
    }

    public function test_the_points_balance_never_shows_below_zero()
    {
        // rows as they were written before refunds capped the take-back:
        // earned 700 on one order, spent them on another, then the first was refunded
        foreach ([["order", "credit", 700], ["order", "debit", 700], ["order_reversal", "debit", 700]] as [$source, $type, $pts]) {
            \App\Models\PointTransaction::create([
                "user_id" => $this->customer->id, "type" => $type, "points" => $pts,
                "value" => $pts * 0.01, "source" => $source, "reference_id" => null,
            ]);
        }

        $res = $this->getJson("/api/v1/user/points")->assertOk();
        $this->assertSame(0, $res->json("points"));
        $this->assertSame("0.00", $res->json("value"));

        $this->assertSame(0, $this->getJson("/api/v1/user/product/qualifying")->json("points"));

        // a legacy shortfall is still netted off before the balance shows above 0
        \App\Models\PointTransaction::create([
            "user_id" => $this->customer->id, "type" => "credit", "points" => 50,
            "value" => 0.5, "source" => "order", "reference_id" => null,
        ]);
        $this->assertSame(0, $this->getJson("/api/v1/user/points")->json("points"));
    }

    public function test_points_on_the_way_count_only_orders_that_will_earn()
    {
        $status = function ($k) { return (int)config("constants.orderStatus.$k"); };
        $make = function (array $o) {
            $order = Order::create(array_merge([
                'order' => 'PEND' . uniqid(),
                'user_id' => $this->customer->id,
                'status' => (int)config('constants.orderStatus.PENDING'),
                'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
                'total_amount' => 100,
                'currency' => 'EUR',
                'payment_done' => 0,
                'contact_name' => 'Aoife Murphy',
                'contact_email' => $this->customer->email,
                'contact_phone' => '+353871234567',
            ], $o));
            return $order;
        };

        $make(['total_amount' => 736.77]);                                               // COD, pending: 736
        $make(['total_amount' => 49.99, 'status' => $status('ON_THE_WAY'),
            'order_method' => (int)config('constants.paymentMethod.WALLET'), 'payment_done' => 1]); // paid: 49
        $make(['order_method' => (int)config('constants.paymentMethod.WALLET')]);        // card/wallet never paid
        $make(['status' => $status('CANCELLED')]);
        $make(['status' => $status('REFUNDED')]);
        $cancelledFlag = $make([]);
        $cancelledFlag->cancelled = 1;
        $cancelledFlag->save();
        $make([])->delete();                                                              // deleted
        $credited = $make(['status' => $status('PICKED_UP')]);                           // already credited
        \App\Models\PointTransaction::create([
            'user_id' => $this->customer->id, 'type' => 'credit', 'points' => 100,
            'value' => 1, 'source' => 'order', 'reference_id' => $credited->id,
        ]);
        $make(['status' => $status('DELIVERED'), 'payment_done' => 1]);                 // delivered

        $res = $this->getJson('/api/v1/user/points')->assertOk();

        $this->assertSame(736 + 49, $res->json('pending_points'));
        $this->assertSame(2, $res->json('pending_orders'));
        $this->assertSame(100, $res->json('points'));
    }

    public function test_pricing_records_keep_every_id_passed_as_text()
    {
        // ->map("intval") used the list position as the number base: the 2nd id became 0
        $ids = \App\Models\Product::where("status", 1)->orderBy("id")->limit(4)->pluck("id")->map(function ($id) { return (string)$id; })->all();

        $records = \App\Models\Product::pricingRecords($ids);

        $this->assertSame(array_map("intval", $ids), $records->keys()->map(function ($k) { return (int)$k; })->sort()->values()->all());
    }
}
