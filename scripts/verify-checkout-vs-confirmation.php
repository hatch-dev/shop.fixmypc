<?php
/**
 * Walk a real basket through checkout and out the other side, and print the
 * checkout figures beside the order confirmation figures.
 *
 * This is the comparison in section 15 of the brief, run against the live
 * database rather than fixtures: sign in as a customer, add a product, read the
 * basket the checkout summary is drawn from, place the order, then read the
 * order back the way the confirmation screen reads it.
 *
 * Everything happens inside a transaction that is rolled back, so no order, no
 * stock movement and no cart row survives the run.
 *
 *   php scripts/verify-checkout-vs-confirmation.php <user_id> <product_id> [qty]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Cart;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\OrderedProductsInventory;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$userId = (int)($argv[1] ?? 0);
$productId = (int)($argv[2] ?? 0);
$quantity = (int)($argv[3] ?? 10);
// 1 = delivered to an address, 2 = collected from the store.
$shippingType = (int)($argv[4] ?? 1);

$user = User::find($userId);
$product = Product::find($productId);

if (!$user || !$product) {
    fwrite(STDERR, "usage: verify-checkout-vs-confirmation.php <user_id> <product_id> [qty]\n");
    exit(1);
}

$inventory = UpdatedInventory::with('inventory_attributes')
    ->where('product_id', $product->id)
    ->orderByDesc('quantity')
    ->first();

$place = optional($product->shipping_rule)->shipping_places()->first();

if (!$inventory || !$place) {
    fwrite(STDERR, "that product cannot be taken to checkout\n");
    exit(1);
}

/** Call the API the storefront calls, as this signed-in customer. */
function api($kernel, $user, $method, $uri, array $params = [])
{
    Laravel\Passport\Passport::actingAs($user, ['user'], 'user');

    $request = Illuminate\Http\Request::create($uri, $method, $params);
    $request->headers->set('Accept', 'application/json');

    return json_decode($kernel->handle($request)->getContent(), true);
}

function money($value)
{
    return number_format((float)$value, 2);
}

DB::beginTransaction();

try {
    // A clean basket, and enough stock to be assessed on price rather than
    // refused for quantity.
    Cart::where('user_id', $user->id)->delete();

    // The customer's existing orders, so a refused checkout cannot be mistaken
    // for a successful one by finding their last real order instead.
    $orderIdBefore = (int)Order::where('user_id', $user->id)->max('id');
    UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 5000]);

    /*
     * A delivered order needs somewhere to deliver to. Customers who have only
     * ever collected have no address on file, and checkout rightly refuses
     * them - so give this run one, inside the transaction that is thrown away.
     */
    if ($shippingType === 1 && !$user->default_address) {
        $address = App\Models\UserAddress::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?: '0871234567',
            'country' => 'Ireland',
            'state' => 'Dublin',
            'city' => 'Dublin',
            'zip' => 'D02X286',
            'address_1' => '26 Main Street',
            'type' => 1,
            'default' => 1,
        ]);

        User::where('id', $user->id)->update(['default_address' => $address->id]);
        $user = $user->fresh();
    }

    echo "Customer   : {$user->name} <{$user->email}>  ({$user->account_type})\n";
    echo "Product    : {$product->title}\n";
    echo "Quantity   : {$quantity}\n";
    echo "Fulfilment : " . ($shippingType === 2 ? 'collection' : 'delivery') . "\n\n";

    api($kernel, $user, 'POST', '/api/v1/cart/action', [
        'product_id' => $product->id,
        'inventory_id' => $inventory->id,
        'quantity' => $quantity,
        'shipping_place_id' => $place->id,
        'shipping_type' => $shippingType,
    ]);

    // ---------------------------------------------------- what checkout shows

    $basket = api($kernel, $user, 'GET', '/api/v1/cart/by-user')['data'] ?? [];

    if (!$basket) {
        throw new RuntimeException('the basket is empty');
    }

    $checkout = ['subtotal' => 0.0, 'goods' => 0.0, 'tax' => 0.0, 'shipping' => 0.0];

    foreach ($basket as $row) {
        $qty = (int)$row['quantity'];

        $checkout['subtotal'] += (float)$row['original_price'] * $qty;
        $checkout['goods'] += (float)$row['unit_price'] * $qty;
        $checkout['shipping'] += (float)($shippingType === 2
            ? ($row['shipping_place']['pickup_price'] ?? 0)
            : ($row['shipping_place']['price'] ?? 0));

        $taxRule = $row['flash_product']['tax_rules'] ?? null;

        if ($taxRule && (int)($row['flash_product']['excludeVAT'] ?? 0) === 1) {
            $checkout['tax'] += Pricing::unitTax(
                (object)$taxRule, (float)$row['unit_price'], true
            ) * $qty;
        }

        printf("  unit price %10s   list price %10s   qty %d\n",
            money($row['unit_price']), money($row['original_price']), $qty);
    }

    $checkout['discount'] = $checkout['subtotal'] - $checkout['goods'];

    /*
     * The order-wide discounts - a voucher, the store-wide flash discount, a
     * loyalty group, points - are decided by the server when the order is
     * created, not carried on the basket rows this script reads. The checkout
     * screen shows them as their own rows off the cart store, and the
     * confirmation shows them off the order, so they are compared here as the
     * confirmation reports them rather than recomputed.
     */
    $checkout['total'] = $checkout['goods'] + $checkout['shipping'] + $checkout['tax'];

    // ------------------------------------------------------- place the order

    $payloadJson = json_encode([
        'user_token' => null,
        'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        'voucher' => '',
        'time_zone' => 'Europe/Dublin',
        'purchase_order_number' => 'PO-VERIFY-0001',
    ]);
    $payloadJson .= str_repeat(' ', (16 - strlen($payloadJson) % 16) % 16);

    $placed = api($kernel, $user, 'POST', '/api/v1/order/action', [
        'data' => openssl_encrypt($payloadJson, 'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210')),
        'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
    ]);

    $order = Order::where('user_id', $user->id)
        ->where('id', '>', $orderIdBefore)
        ->orderByDesc('id')
        ->first();

    if (!$order) {
        throw new RuntimeException('checkout refused: ' . json_encode($placed['message'] ?? $placed));
    }

    // ------------------------------------------- what the confirmation shows

    $confirmation = api($kernel, $user, 'POST', '/api/v1/order/by-user', [
        'order_id' => $order->id,
        'time_zone' => 'Europe/Dublin',
    ]);

    if (($confirmation['status'] ?? null) !== 200) {
        throw new RuntimeException('could not read the order back: '
            . json_encode($confirmation['message'] ?? $confirmation));
    }

    $shown = $confirmation['data']['calculations'];

    echo "\n";
    printf("%-18s %14s %14s   %s\n", '', 'CHECKOUT', 'CONFIRMATION', '');

    $rows = [
        'Subtotal' => 'subtotal',
        'Product Discount' => 'discount',
        'Shipping' => 'shipping',
        'Tax' => 'tax',
    ];

    $allMatch = true;

    foreach ($rows as $label => $key) {
        $left = Pricing::round($checkout[$key]);
        $right = (float)$shown[$key];
        $match = abs($left - $right) < 0.02;
        $allMatch = $allMatch && $match;

        printf("%-18s %14s %14s   %s\n", $label, money($left), money($right),
            $match ? 'match' : 'DIFFERENT');
    }

    // Decided at order creation; shown on both screens, not recomputed here.
    $orderWide = 0.0;

    foreach ([
        'Voucher' => 'voucher',
        'Flash Discount' => 'flash_discount',
        'Loyalty' => 'loyalty_discount',
        'Cherry Points' => 'points_discount',
        'Bundle Offer' => 'bundle_offer',
    ] as $label => $key) {
        $amount = (float)($shown[$key] ?? 0);

        if ($amount > 0) {
            $orderWide += $amount;
            printf("%-18s %14s %14s   %s\n", $label, '-', '-' . money($amount),
                'order-wide');
        }
    }

    $checkout['total'] = Pricing::round($checkout['total'] - $orderWide);

    $left = $checkout['total'];
    $right = (float)$shown['total'];
    $match = abs($left - $right) < 0.02;
    $allMatch = $allMatch && $match;

    printf("%-18s %14s %14s   %s\n", 'Total', money($left), money($right),
        $match ? 'match' : 'DIFFERENT');

    printf("%-18s %14s %14s   %s\n", 'orders.total_amount', '',
        money($order->total_amount),
        abs((float)$order->total_amount - (float)$shown['total']) < 0.02 ? 'match' : 'DIFFERENT');

    $stored = OrderedProductsInventory::where('order_id', $order->id)->first();

    echo "\nStored on the order\n";
    printf("  actual_price (list)  %10s\n", money($stored->actual_price));
    printf("  price (charged)      %10s\n", money($stored->price));
    printf("  quantity             %10d\n", (int)$stored->quantity);
    printf("  PO number            %10s\n", $order->purchase_order_number);
    printf("  account type         %10s\n", $order->account_type);
    printf("  company              %s\n", $order->company_name);

    echo "\n" . ($allMatch ? 'Checkout and confirmation agree on every row.'
        : 'MISMATCH - see the rows marked DIFFERENT above.') . "\n";

} finally {
    DB::rollBack();
    echo "\n(rolled back: nothing was written)\n";
}
