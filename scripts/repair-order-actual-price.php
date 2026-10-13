<?php
/**
 * Repair ordered_products_inventories.actual_price on orders placed before the
 * list-price fix.
 *
 * Until that fix, actual_price was written from products.selling - the
 * product-level figure - while the basket and the checkout summary measured the
 * same discount against the variant's own list price. On a variation product
 * the two disagree, so those orders record a Subtotal and a Product Discount
 * the customer was never shown. (The Total is unaffected: both figures were
 * inflated by the same amount.)
 *
 * WHAT THIS DOES AND DOES NOT DO
 *
 * It rewrites actual_price only, and only where it can be shown to be wrong:
 * where the line is a variation, where the variant still carries a price, and
 * where the stored actual_price is the product's selling price rather than the
 * variant's. It never touches `price` - the amount actually charged - nor any
 * total, and it never changes an order's total_amount.
 *
 * It is still a rewrite of a financial record, so it does nothing without
 * --apply. Run it without, read the table, and decide.
 *
 * IT CANNOT BE PERFECT. The variant's price today is not necessarily the
 * variant's price on the day the order was placed; where the catalogue has
 * moved since, this will restore a figure that is right in shape but possibly
 * not to the cent. That is why it is opt-in rather than a migration: for most
 * installs the honest answer is to leave old orders as they are and let the fix
 * apply from here on.
 *
 *   php scripts/repair-order-actual-price.php                 # show what would change
 *   php scripts/repair-order-actual-price.php --order=3549    # one order
 *   php scripts/repair-order-actual-price.php --apply         # write it
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Helper\Pricing;
use App\Models\OrderedProduct;
use App\Models\OrderedProductsInventory;
use App\Models\Product;
use App\Models\UpdatedInventory;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
$onlyOrder = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--order=') === 0) {
        $onlyOrder = (int)substr($arg, strlen('--order='));
    }
}

$query = OrderedProductsInventory::query()->orderBy('order_id');

if ($onlyOrder) {
    $query->where('order_id', $onlyOrder);
}

$rows = $query->get();

$changes = [];

foreach ($rows as $row) {

    $inventory = UpdatedInventory::with('inventory_attributes')->find($row->inventory_id);

    // Only a real variation can disagree with the product-level price.
    if (!$inventory || !Pricing::isVariant($inventory)) {
        continue;
    }

    $variantPrice = Pricing::inventoryPrice($inventory);

    if (!($variantPrice > 0)) {
        continue;
    }

    $product = Product::find($row->product_id);

    if (!$product) {
        continue;
    }

    $stored = (float)$row->actual_price;
    $charged = (float)$row->price;

    // What the order should have frozen, by the same rule checkout used.
    $correct = Pricing::listPrice($product, $inventory, $charged);

    if (abs($stored - $correct) < 0.005) {
        continue;
    }

    /*
     * Only where the stored figure is the product's selling price. Anything
     * else is a number this script cannot account for, and guessing at it would
     * be worse than leaving it.
     */
    if (abs($stored - (float)$product->selling) >= 0.005) {
        continue;
    }

    $quantity = (int)$row->quantity;

    $changes[] = [
        'id' => $row->id,
        'order' => $row->order_id,
        'product' => $row->product_id,
        'qty' => $quantity,
        'charged' => $charged,
        'was' => $stored,
        'now' => $correct,
        'subtotal_was' => Pricing::round($stored * $quantity),
        'subtotal_now' => Pricing::round($correct * $quantity),
        'discount_was' => Pricing::round(max(0, ($stored - $charged) * $quantity)),
        'discount_now' => Pricing::round(max(0, ($correct - $charged) * $quantity)),
    ];
}

if (!$changes) {
    echo "Nothing to repair.\n";
    exit(0);
}

printf("%-7s %-7s %-11s %5s %10s %10s %12s %12s %12s %12s\n",
    'row', 'order', 'product', 'qty', 'charged', 'list was', 'list now',
    'subtotal was', 'subtotal now', 'discount now');

foreach ($changes as $c) {
    printf("%-7d %-7d %-11d %5d %10.2f %10.2f %12.2f %12.2f %12.2f %12.2f\n",
        $c['id'], $c['order'], $c['product'], $c['qty'], $c['charged'],
        $c['was'], $c['now'], $c['subtotal_was'], $c['subtotal_now'], $c['discount_now']);
}

echo "\n" . count($changes) . " line(s) affected.\n";

if (!$apply) {
    echo "Dry run. Re-run with --apply to write these values.\n";
    exit(0);
}

DB::transaction(function () use ($changes) {
    foreach ($changes as $c) {
        OrderedProductsInventory::where('id', $c['id'])
            ->update(['actual_price' => $c['now']]);
    }
});

echo "Written.\n";

/*
 * The totals are untouched on purpose, but say so out loud: if any order's
 * recorded total no longer follows from its own rows, that is a separate
 * problem this script has not caused and will not hide.
 */
foreach (array_unique(array_column($changes, 'order')) as $orderId) {
    $order = App\Models\Order::with('ordered_products')->find($orderId);

    if (!$order) {
        continue;
    }

    $summary = App\Models\Helper\OrderSummary::for($order);

    if (abs((float)$order->total_amount - $summary['total']) >= 0.02) {
        printf("  order %d: total_amount %.2f but its rows now sum to %.2f\n",
            $orderId, (float)$order->total_amount, $summary['total']);
    }
}
