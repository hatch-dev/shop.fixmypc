<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The admin order list must not cost a query per order.
 *
 * Every row's summary is built from the order's own records, and each of those
 * lookups was being made one order at a time: the line prices, the voucher, the
 * flash discount, the loyalty discount and the points. Five round trips per
 * row, on a page of fifteen.
 *
 * The number asserted below is deliberately a ceiling rather than an exact
 * figure — this endpoint eager-loads a lot and the exact count is not the point.
 * What matters is that it does not grow with the number of orders on the page,
 * so the test loads two page sizes and compares.
 */
class AdminOrderListQueryCountTest extends TestCase
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

        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    private function customer()
    {
        return User::create([
            'name' => 'Query Count Fixture',
            'email' => 'qc.' . uniqid() . '@example.test',
            'phone' => '0871234567',
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'business',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function makeOrders($count, $user)
    {
        for ($i = 0; $i < $count; $i++) {
            Order::create([
                'status' => config('constants.orderStatus.PENDING'),
                'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
                'user_id' => $user->id,
                'payment_done' => 0,
                'total_amount' => 100,
                'currency' => 'EUR',
                'order' => 'QC' . uniqid(),
                'account_type' => 'business',
            ]);
        }
    }

    /** Queries run while loading the list, for one customer's orders only. */
    private function queriesFor($userId)
    {
        $this->admin();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson(
            '/api/admin/order/all?orderby=created_at&type=desc&user_id=' . $userId
        );

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame(200, $response->json('status'),
            'the list did not load: ' . json_encode($response->json('message')));

        return $count;
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_orders()
    {
        $small = $this->customer();
        $this->makeOrders(2, $small);

        $large = $this->customer();
        $this->makeOrders(12, $large);

        $forTwo = $this->queriesFor($small->id);
        $forTwelve = $this->queriesFor($large->id);

        /*
         * Ten more orders used to mean fifty more queries. A little drift is
         * fine — the eager loads themselves add a fixed number of statements
         * and some are skipped entirely when a relation comes back empty — but
         * the cost of an extra order must be far below one query, let alone
         * five.
         */
        $this->assertLessThanOrEqual(
            $forTwo + 5, $forTwelve,
            "ten extra orders cost " . ($forTwelve - $forTwo) . " extra queries; "
                . "the list is still querying per row"
        );
    }
}
