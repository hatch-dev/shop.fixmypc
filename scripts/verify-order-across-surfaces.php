<?php
/**
 * One order, read by every surface that shows it, side by side.
 *
 * Checkout -> customer order confirmation -> admin order list -> admin order
 * detail -> invoice. Section 31 of the admin audit asks for exactly this
 * comparison; running it is cheaper and more reliable than doing it by eye
 * across four screens.
 *
 * Everything happens inside a transaction that is rolled back, so no order,
 * stock movement or cart row survives the run.
 *
 *   php scripts/verify-order-across-surfaces.php <user_id> <product_id> [qty] [1=delivery|2=collection]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admin;
use App\Models\Cart;
use App\Models\Helper\Pricing;
use App\Models\Helper\Utils;
use App\Models\Order;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$userId = (int)($argv[1] ?? 0);
$productId = (int)($argv[2] ?? 0);
$quantity = (int)($argv[3] ?? 3);
$shippingType = (int)($argv[4] ?? 2);

$user = User::find($userId);
$product = Product::find($productId);

if (!$user || !$product) {
    fwrite(STDERR, "usage: verify-order-across-surfaces.php <user_id> <product_id> [qty] [1|2]\n");
    exit(1);
}

$inventory = UpdatedInventory::with('inventory_attributes')
    ->where('product_id', $product->id)->orderByDesc('quantity')->first();

$place = optional($product->shipping_rule)->shipping_places()->first();

if (!$inventory || !$place) {
    fwrite(STDERR, "that product cannot be taken to checkout\n");
    exit(1);
}

function asUser($kernel, $user, $method, $uri, array $params = [])
{
    Laravel\Passport\Passport::actingAs($user, ['user'], 'user');

    $request = Illuminate\Http\Request::create($uri, $method, $params);
    $request->headers->set('Accept', 'application/json');

    return json_decode($kernel->handle($request)->getContent(), true);
}

function asAdmin($kernel, $admin, $uri, array $params = [])
{
    Laravel\Passport\Passport::actingAs($admin, ['admin'], 'admin');

    $request = Illuminate\Http\Request::create($uri, 'GET', $params);
    $request->headers->set('Accept', 'application/json');

    return json_decode($kernel->handle($request)->getContent(), true);
}

function money($v)
{
    return number_format((float)$v, 2);
}

DB::beginTransaction();

try {
    Cart::where('user_id', $user->id)->delete();
    UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 5000]);

    $orderIdBefore = (int)Order::where('user_id', $user->id)->max('id');

    if ($shippingType === 1 && !$user->default_address) {
        $address = App\Models\UserAddress::create([
            'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'phone' => $user->phone ?: '0871234567', 'country' => 'Ireland',
            'state' => 'Dublin', 'city' => 'Dublin', 'zip' => 'D02X286',
            'address_1' => '26 Main Street', 'type' => 1, 'default' => 1,
        ]);
        User::where('id', $user->id)->update(['default_address' => $address->id]);
        $user = $user->fresh();
    }

    echo "Customer   : {$user->name} <{$user->email}>  ({$user->account_type})\n";
    echo "Product    : {$product->title}\n";
    echo "Quantity   : {$quantity}\n";
    echo "Fulfilment : " . ($shippingType === 2 ? 'collection' : 'delivery') . "\n\n";

    asUser($kernel, $user, 'POST', '/api/v1/cart/action', [
        'product_id' => $product->id,
        'inventory_id' => $inventory->id,
        'quantity' => $quantity,
        'shipping_place_id' => $place->id,
        'shipping_type' => $shippingType,
    ]);

    // ---------------------------------------------------- 1. what checkout shows

    $basket = asUser($kernel, $user, 'GET', '/api/v1/cart/by-user')['data'] ?? [];

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
            $checkout['tax'] += Pricing::unitTax((object)$taxRule, (float)$row['unit_price'], true) * $qty;
        }
    }

    $checkout['discount'] = $checkout['subtotal'] - $checkout['goods'];
    $checkout['total'] = $checkout['goods'] + $checkout['shipping'] + $checkout['tax'];

    // -------------------------------------------------------- place the order

    $payload = json_encode([
        'user_token' => null,
        'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        'voucher' => '',
        'time_zone' => 'Europe/Dublin',
        'purchase_order_number' => 'PO-2026-00125',
    ]);
    $payload .= str_repeat(' ', (16 - strlen($payload) % 16) % 16);

    $placed = asUser($kernel, $user, 'POST', '/api/v1/order/action', [
        'data' => openssl_encrypt($payload, 'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210')),
        'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
    ]);

    $order = Order::where('user_id', $user->id)->where('id', '>', $orderIdBefore)
        ->orderByDesc('id')->first();

    if (!$order) {
        throw new RuntimeException('checkout refused: ' . json_encode($placed['message'] ?? $placed));
    }

    // ------------------------------------------ 2. the customer's confirmation

    $confirmation = asUser($kernel, $user, 'POST', '/api/v1/order/by-user', [
        'order_id' => $order->id,
        'time_zone' => 'Europe/Dublin',
    ])['data']['calculations'];

    // ---------------------------------------------------- 3 & 4. the admin

    $admin = Admin::whereHas('roles', function ($q) {
        $q->where('name', 'superadmin');
    })->first();

    if (!$admin) {
        throw new RuntimeException('no superadmin to read the admin screens with');
    }

    $detailPayload = asAdmin($kernel, $admin, "/api/admin/order/find/{$order->id}",
        ['time_zone' => 'Europe/Dublin']);

    if (($detailPayload['status'] ?? null) !== 200) {
        throw new RuntimeException('admin detail: ' . json_encode($detailPayload['message'] ?? ''));
    }

    $detail = $detailPayload['data'];
    $adminDetail = $detail['calculations'];

    $listPayload = asAdmin($kernel, $admin, '/api/admin/order/all',
        ['orderby' => 'created_at', 'type' => 'desc', 'time_zone' => 'Europe/Dublin']);

    $listRow = collect($listPayload['data']['data'] ?? [])->firstWhere('id', $order->id);

    // ------------------------------------------ 5. the invoice / order email

    $invoice = Utils::calcPrice($order->fresh()->load('ordered_products'));

    // ---------------------------------------------------------------- report

    printf("%-18s %12s %12s %12s %12s\n", '',
        'CHECKOUT', 'CUSTOMER', 'ADMIN', 'INVOICE');

    $rows = [
        'Subtotal' => ['subtotal', 'subtotal', 'original_subtotal'],
        'Product Discount' => ['discount', 'discount', 'discount'],
        'Shipping' => ['shipping', 'shipping', 'shipping_price'],
        'Tax' => ['tax', 'tax', 'tax'],
    ];

    $allMatch = true;

    foreach ($rows as $label => [$checkoutKey, $summaryKey, $invoiceKey]) {
        $values = [
            Pricing::round($checkout[$checkoutKey]),
            (float)$confirmation[$summaryKey],
            (float)$adminDetail[$summaryKey],
            (float)$invoice[$invoiceKey],
        ];

        $match = max($values) - min($values) < 0.02;
        $allMatch = $allMatch && $match;

        printf("%-18s %12s %12s %12s %12s   %s\n", $label,
            money($values[0]), money($values[1]), money($values[2]), money($values[3]),
            $match ? 'match' : 'DIFFERENT');
    }

    // Order-wide discounts are decided at order creation, not carried on the
    // basket rows, so they are reported as the order recorded them.
    $orderWide = 0.0;

    foreach (['voucher', 'flash_discount', 'loyalty_discount', 'points_discount', 'bundle_offer'] as $key) {
        $amount = (float)($confirmation[$key] ?? 0);

        if ($amount > 0) {
            $orderWide += $amount;
            printf("%-18s %12s %12s %12s %12s   %s\n", ucfirst(str_replace('_', ' ', $key)),
                '-', '-' . money($amount), '-' . money($amount), '-' . money($amount), 'order-wide');
        }
    }

    $totals = [
        Pricing::round($checkout['total'] - $orderWide),
        (float)$confirmation['total'],
        (float)$adminDetail['total'],
        (float)$invoice['total_price'],
    ];

    $match = max($totals) - min($totals) < 0.02;
    $allMatch = $allMatch && $match;

    printf("%-18s %12s %12s %12s %12s   %s\n", 'Total',
        money($totals[0]), money($totals[1]), money($totals[2]), money($totals[3]),
        $match ? 'match' : 'DIFFERENT');

    printf("%-18s %12s %12s %12s %12s   %s\n", 'orders.total_amount', '', '', '',
        money($order->total_amount),
        abs((float)$order->total_amount - $totals[1]) < 0.02 ? 'match' : 'DIFFERENT');

    // ------------------------------------------------- non-money consistency

    echo "\nWhat each surface says about the order\n";

    $facts = [
        'PO number' => [
            $order->purchase_order_number,
            $detail['purchase_order_number'],
            $listRow['purchase_order_number'] ?? null,
        ],
        'Account type' => [
            $order->account_type,
            $detail['customer']['account_type'],
            $listRow['customer']['account_type'] ?? null,
        ],
        'Company' => [
            $order->company_name,
            $detail['customer']['company_name'],
            $listRow['customer']['company_name'] ?? null,
        ],
        'Customer name' => [
            $user->name,
            $detail['customer']['name'],
            $listRow['customer']['name'] ?? null,
        ],
        'Customer phone' => [
            $user->phone,
            $detail['customer']['phone'],
            $listRow['customer']['phone'] ?? null,
        ],
    ];

    printf("  %-16s %-28s %-28s %s\n", '', 'ORDER ROW', 'ADMIN DETAIL', 'ADMIN LIST');

    foreach ($facts as $label => [$stored, $detailValue, $listValue]) {
        $same = ((string)$stored === (string)$detailValue)
            && ((string)$stored === (string)$listValue);
        $allMatch = $allMatch && $same;

        printf("  %-16s %-28s %-28s %-20s %s\n", $label,
            (string)$stored ?: '-', (string)$detailValue ?: '-', (string)$listValue ?: '-',
            $same ? 'match' : 'DIFFERENT');
    }

    // Per-line figures, which is where the admin screen used to say least.
    $line = $detail['ordered_products'][0];

    echo "\nOrder line, as the admin screen renders it\n";
    printf("  %-22s %s\n", 'SKU', $line['updated_inventory']['sku'] ?? '-');
    printf("  %-22s %s\n", 'Quantity', $line['quantity']);
    printf("  %-22s %s\n", 'Original unit price', money($line['actual_price']));
    printf("  %-22s %s\n", 'Discount', money($line['line_discount']));
    printf("  %-22s %s\n", 'Unit price', money($line['price']));
    printf("  %-22s %s\n", 'Tax', money($line['tax_total']));
    printf("  %-22s %s\n", 'Line total', money($line['line_total']));

    echo "\nStatus and workflow\n";
    printf("  %-22s %s\n", 'Order status', $detail['status']);
    printf("  %-22s %s\n", 'Allowed moves', implode(', ', $detail['allowed_transitions']));
    printf("  %-22s %s\n", 'Payment status', $detail['payment_done'] ? 'paid' : 'unpaid');
    printf("  %-22s %s\n", 'Transaction id', $detail['trans_id'] ?: 'N/A');
    printf("  %-22s %d entry(s)\n", 'Status history', count($detail['status_trail']));

    echo "\n" . ($allMatch
        ? 'Every surface agrees.'
        : 'MISMATCH - see the rows marked DIFFERENT above.') . "\n";

} finally {
    DB::rollBack();
    echo "\n(rolled back: nothing was written)\n";
}
