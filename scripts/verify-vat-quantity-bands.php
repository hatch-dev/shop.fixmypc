<?php
/**
 * VAT across the quantity bands, product to invoice.
 *
 * Section 29/36 of the VAT brief: for each quantity, work out which band
 * applies, then check that the ex-VAT, VAT and inc-VAT figures agree all the
 * way from the catalogue to the placed order.
 *
 * Each quantity places a real order through the real checkout inside a
 * transaction that is rolled back, so the comparison is against what the shop
 * would actually have charged, not against a re-implementation of it.
 *
 *   php scripts/verify-vat-quantity-bands.php <user_id> <product_id> [qty,qty,...]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$userId = (int)($argv[1] ?? 0);
$productId = (int)($argv[2] ?? 0);
$quantities = array_map('intval', explode(',', $argv[3] ?? '10,11,20,21,30,31,50'));

$user = User::find($userId);
$product = Product::find($productId);

if (!$user || !$product) {
    fwrite(STDERR, "usage: verify-vat-quantity-bands.php <user_id> <product_id> [qty,...]\n");
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

DB::beginTransaction();

try {
    UpdatedInventory::where('id', $inventory->id)->update(['quantity' => 100000]);
    $inventory = $inventory->fresh();

    $rule = $product->tax_rules;
    $rate = Pricing::vatRate($rule);
    $inclusive = Pricing::isVatInclusive($product);

    echo "Customer : {$user->name}  ({$user->account_type})\n";
    echo "Product  : {$product->title}\n";
    printf("VAT      : %s%%, price is quoted %s\n\n",
        rtrim(rtrim(number_format($rate, 2), '0'), '.'),
        $inclusive ? 'INC-VAT' : 'EX-VAT');

    echo "Bands configured for this product:\n";

    foreach (BusinessProductPricing::where('product_id', $product->id)->orderBy('min_qty')->get() as $band) {
        printf("  %3d - %-5s  final %8s\n", $band->min_qty,
            $band->max_qty ? $band->max_qty : 'up', number_format($band->final_price, 2));
    }

    echo "\n";
    printf("%4s %-9s %10s %10s %10s %12s %12s %12s  %s\n",
        'qty', 'band', 'unit ex', 'unit vat', 'unit inc', 'line ex', 'line vat', 'order total', '');

    $allOk = true;

    foreach ($quantities as $qty) {

        Cart::where('user_id', $user->id)->delete();

        asUser($kernel, $user, 'POST', '/api/v1/cart/action', [
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => $qty,
            'shipping_place_id' => $place->id,
            'shipping_type' => 2,
        ]);

        $basket = asUser($kernel, $user, 'GET', '/api/v1/cart/by-user')['data'] ?? [];
        $row = $basket[0] ?? null;

        if (!$row) {
            printf("%4d  basket empty\n", $qty);
            $allOk = false;
            continue;
        }

        /* Which band the server picked, named from the configured rows. */
        $band = BusinessProductPricing::where('product_id', $product->id)
            ->where('min_qty', '<=', $qty)
            ->where(function ($q) use ($qty) {
                $q->where('max_qty', '>=', $qty)->orWhere('max_qty', 0);
            })
            ->orderBy('final_price')
            ->first();

        $bandLabel = $band
            ? $band->min_qty . '-' . ($band->max_qty ?: 'up')
            : 'retail';

        $orderIdBefore = (int)Order::where('user_id', $user->id)->max('id');

        $payload = json_encode([
            'user_token' => null,
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
            'voucher' => '',
            'time_zone' => 'Europe/Dublin',
            'purchase_order_number' => 'PO-VAT-' . $qty,
        ]);
        $payload .= str_repeat(' ', (16 - strlen($payload) % 16) % 16);

        asUser($kernel, $user, 'POST', '/api/v1/order/action', [
            'data' => openssl_encrypt($payload, 'AES-128-CBC',
                hex2bin('0123456470abcdef0123456789abcdef'), OPENSSL_ZERO_PADDING,
                hex2bin('abcdef1876343516abcdef9876543210')),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);

        $order = Order::where('user_id', $user->id)
            ->where('id', '>', $orderIdBefore)->orderByDesc('id')->first();

        if (!$order) {
            printf("%4d  %-9s  checkout refused\n", $qty, $bandLabel);
            $allOk = false;
            continue;
        }

        $confirmation = asUser($kernel, $user, 'POST', '/api/v1/order/by-user', [
            'order_id' => $order->id,
            'time_zone' => 'Europe/Dublin',
        ])['data'];

        $c = $confirmation['calculations'];
        $line = $confirmation['ordered_products'][0];

        /*
         * Three things have to hold at every quantity:
         *   the basket and the order agree on the unit split;
         *   ex + VAT = inc on the line;
         *   net + VAT + shipping = the total the order was charged.
         */
        $basketMatches = abs((float)$row['unit_ex_vat'] - (float)$line['unit_ex_vat']) < 0.01
            && abs((float)$row['unit_inc_vat'] - (float)$line['unit_inc_vat']) < 0.01;

        $lineAdds = abs(((float)$line['ex_vat'] + (float)$line['vat']) - (float)$line['inc_vat']) < 0.02;

        $orderAdds = abs(
            ((float)$c['ex_vat'] + (float)$c['vat'] + (float)$c['shipping']) - (float)$c['total']
        ) < 0.02;

        $ok = $basketMatches && $lineAdds && $orderAdds;
        $allOk = $allOk && $ok;

        printf("%4d %-9s %10s %10s %10s %12s %12s %12s  %s\n",
            $qty, $bandLabel,
            number_format($line['unit_ex_vat'], 2),
            number_format((float)$line['unit_inc_vat'] - (float)$line['unit_ex_vat'], 2),
            number_format($line['unit_inc_vat'], 2),
            number_format($line['ex_vat'], 2),
            number_format($line['vat'], 2),
            number_format((float)$c['total'], 2),
            $ok ? 'ok' : ($basketMatches ? '' : 'BASKET/ORDER DIFFER ')
                . ($lineAdds ? '' : 'LINE DOES NOT ADD UP ')
                . ($orderAdds ? '' : 'ORDER DOES NOT ADD UP'));
    }

    echo "\n" . ($allOk
        ? 'Every quantity reconciles: basket = order, ex + VAT = inc, net + VAT + shipping = total.'
        : 'MISMATCH - see the rows above.') . "\n";

} finally {
    DB::rollBack();
    echo "\n(rolled back: nothing was written)\n";
}
