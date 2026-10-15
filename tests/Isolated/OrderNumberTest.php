<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\Helper\Utils;
use App\Models\Order;
use Database\Seeders\Testing\Fixture;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Order numbers (2026-10-15): ORD and seven random digits, e.g. ORD1578945.
 * They used to be date + five random letters + the customer id
 * (20261410bEBSj5367), which leaked the customer id. Old orders keep theirs.
 */
class OrderNumberTest extends IsolatedTestCase
{
    const FORMAT = '/^ORD[1-9][0-9]{6}$/';

    public function test_a_number_is_ORD_and_seven_random_digits()
    {
        $numbers = [];
        for ($i = 0; $i < 300; $i++) {
            $numbers[] = Utils::generateOrderNumber();
        }

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression(self::FORMAT, $number);
        }
        $this->assertGreaterThan(290, count(array_unique($numbers)), 'random, not a counter or a constant');
    }

    public function test_a_placed_order_gets_an_ORD_number()
    {
        $userId = Fixture::USER_PERSONAL_ID;
        Cart::whereNull('admin_id')->delete();

        $this->assertApiOk($this->asCustomer($userId)->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_PHONE_ID,
            'inventory_id' => Fixture::PHONE_128_BLACK_ID,
            'quantity' => 1,
        ]));

        $place = DB::table('shipping_places')->where('shipping_rule_id', Fixture::SHIPPING_RULE_ID)->value('id');
        DB::table('shipping_places')->where('id', $place)->update(['pickup_point' => 1]);
        Cart::where('user_id', $userId)->update([
            'shipping_place_id' => $place,
            'shipping_type' => (int)config('constants.shippingTypeIn.PICKUP'),
        ]);

        $json = json_encode(['user_token' => null, 'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '', 'time_zone' => 'Europe/Dublin']);
        $json .= str_repeat(' ', (16 - strlen($json) % 16) % 16);

        $this->assertApiOk($this->asCustomer($userId)->postJson('/api/v1/order/action', [
            'data' => openssl_encrypt($json, 'AES-128-CBC', hex2bin('0123456470abcdef0123456789abcdef'),
                OPENSSL_ZERO_PADDING, hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]));

        $number = Order::where('user_id', $userId)->latest('id')->value('order');
        $this->assertMatchesRegularExpression(self::FORMAT, (string)$number);
    }

    /** The backstop for two orders drawing the same number at once. */
    public function test_the_database_refuses_a_second_order_with_the_same_number()
    {
        $this->assertCount(1, DB::select("SHOW INDEX FROM orders WHERE Key_name = 'orders_order_unique'"));

        $row = [
            'order' => 'ORD1578945',
            'user_id' => Fixture::USER_PERSONAL_ID,
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('orders')->insert($row);
        $this->assertSame(1, DB::table('orders')->where('order', 'ORD1578945')->count(), 'the first one is accepted');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('orders_order_unique');
        DB::table('orders')->insert($row);
    }
}
