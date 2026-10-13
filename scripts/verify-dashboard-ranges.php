<?php
/**
 * Dashboard date-range verification.
 *
 * Seeds a spread of orders (including exact day boundaries, month/year edges and
 * cancelled rows) inside a transaction, drives the real controller for every
 * preset and a battery of custom ranges, cross-checks each answer against an
 * independently written SQL query, then rolls everything back.
 *
 * Run it after touching resolveDateRange(), bucketExpression(), buildBuckets()
 * or the Sales Overview queries:
 *
 *   php scripts/verify-dashboard-ranges.php
 *   TZ_TEST=UTC php scripts/verify-dashboard-ranges.php
 *
 * It writes to the orders table inside a transaction and rolls back, so it is
 * safe against a live database - but it is not a phpunit test because it drives
 * the controller directly (the auth:admin middleware rejects a hand-made guard
 * user, see the reflection block below).
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Admin;
use App\Http\Controllers\AdminsController;

$TZ = getenv('TZ_TEST') ?: 'Asia/Kolkata';
$tz = new DateTimeZone($TZ);
$utc = new DateTimeZone('UTC');

$admin = Admin::find(1);

function callDashboard($app, $admin, $params)
{
    $req = Request::create('http://localhost/api/admin/dashboard', 'GET', $params);
    $req->headers->set('Accept', 'application/json');
    $app->instance('request', $req);
    Illuminate\Support\Facades\Auth::guard('admin')->setUser($admin);
    $c = new AdminsController();
    $r = new ReflectionObject($c);
    foreach (['user' => $admin, 'isSuperAdmin' => true, 'isVendor' => false] as $k => $v) {
        $p = $r->getProperty($k); $p->setAccessible(true); $p->setValue($c, $v);
    }
    return json_decode($c->dashboard($req)->getContent(), true);
}

// local "Y-m-d H:i:s" in $TZ -> UTC string for insertion
function toUtc($localStr, $tz)
{
    $d = new DateTime($localStr, $tz);
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s');
}

DB::beginTransaction();

// ---------------------------------------------------------------- seed
$seed = [];
$n = 0;
$mk = function ($localDateTime, $amount, $cancelled = 0) use (&$seed, &$n, $tz) {
    $seed[] = [
        'status' => 1,
        'total_amount' => $amount,
        'order_method' => 1,
        'currency' => 'EUR',
        'payment_done' => 1,
        'cancelled' => $cancelled,
        'created_at' => toUtc($localDateTime, $tz),
        'updated_at' => toUtc($localDateTime, $tz),
        'order' => 'TEST' . str_pad(++$n, 5, '0', STR_PAD_LEFT),
        'viewed' => 0,
    ];
};

// exact day boundaries
$mk('2026-09-01 00:00:00', 100.00);
$mk('2026-09-01 23:59:59', 200.00);
$mk('2026-09-02 00:00:00', 300.00);
// month boundary
$mk('2026-08-31 23:59:59', 111.00);
$mk('2026-09-30 23:59:59', 222.00);
// year boundary
$mk('2025-12-31 23:59:59', 500.00);
$mk('2026-01-01 00:00:00', 600.00);
$mk('2024-12-31 23:59:59', 700.00);
// cancelled rows that must never count as revenue
$mk('2026-09-01 12:00:00', 9999.00, 1);
$mk('2026-09-05 12:00:00', 8888.00, 1);
// a heavy day
for ($i = 0; $i < 120; $i++) {
    $mk(sprintf('2026-09-03 %02d:%02d:%02d', $i % 24, ($i * 7) % 60, ($i * 13) % 60), 10.00 + $i);
}
// scattered history across two years
for ($i = 0; $i < 60; $i++) {
    $d = (new DateTime('2024-10-05 09:00:00', $tz))->modify('+' . ($i * 11) . ' days');
    $mk($d->format('Y-m-d H:i:s'), 50.00 + $i);
}

DB::table('orders')->insert($seed);
echo "seeded " . count($seed) . " orders (TZ=$TZ)\n\n";

// ------------------------------------------------- independent SQL oracle
function oracle($startLocal, $endLocal, $tz)
{
    $utc = new DateTimeZone('UTC');
    $from = (new DateTime($startLocal . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $to = (new DateTime($endLocal . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

    $row = DB::selectOne(
        "SELECT COALESCE(SUM(total_amount),0) v, COUNT(DISTINCT id) c
         FROM orders
         WHERE created_at >= ? AND created_at < ? AND cancelled <> 1",
        [$from, $to]
    );
    $can = DB::selectOne(
        "SELECT COALESCE(SUM(total_amount),0) v, COUNT(DISTINCT id) c
         FROM orders
         WHERE created_at >= ? AND created_at < ? AND cancelled = 1",
        [$from, $to]
    );
    return ['total' => round((float)$row->v, 2), 'orders' => (int)$row->c,
            'cancelled_orders' => (int)$can->c, 'from' => $from, 'to' => $to];
}

$pass = 0; $fail = 0;
$check = function ($label, $cond, $detail = '') use (&$pass, &$fail) {
    if ($cond) { $pass++; }
    else { $fail++; echo "  FAIL: $label $detail\n"; }
};

function runCase(&$pass, &$fail, $check, $app, $admin, $TZ, $tz, $label, $params)
{
    $j = callDashboard($app, $admin, array_merge(
        ['dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all'], $params));

    if (($j['status'] ?? 0) !== 200) {
        echo "$label: HTTP ERROR " . ($j['message'] ?? '?') . "\n";
        $fail++;
        return;
    }

    $c = $j['data']['chart_data'];
    $r = $c['range']; $s = $c['summary']; $b = $c['revenue'];
    $o = oracle($r['start'], $r['end'], $tz);

    $sumB = round(array_sum(array_column($b, 'value')), 2);
    $sumO = array_sum(array_column($b, 'orders'));

    printf("%-46s %s..%s %-5s %3db  total=%-11s orders=%-4d\n",
        $label, $r['start'], $r['end'], $r['unit'], count($b), number_format($s['total'], 2), $s['orders']);

    $check("$label total", abs($s['total'] - $o['total']) < 0.01, "got {$s['total']} want {$o['total']}");
    $check("$label orders", $s['orders'] === $o['orders'], "got {$s['orders']} want {$o['orders']}");
    $check("$label cancelled", $s['cancelled_orders'] === $o['cancelled_orders'],
        "got {$s['cancelled_orders']} want {$o['cancelled_orders']}");
    $check("$label bucketsum", abs($sumB - $s['total']) < 0.01, "buckets $sumB vs total {$s['total']}");
    $check("$label bucketorders", $sumO === $s['orders'], "buckets $sumO vs total {$s['orders']}");
    $check("$label reconciled", $s['reconciled'] === true);
    $check("$label utc_from", $r['utc_from'] === $o['from'], "{$r['utc_from']} vs {$o['from']}");
    $check("$label utc_to", $r['utc_to'] === $o['to'], "{$r['utc_to']} vs {$o['to']}");
    $check("$label no-empty-buckets", count($b) > 0);

    // buckets must be contiguous, ascending and unique
    $keys = array_column($b, 'key');
    $check("$label unique buckets", count($keys) === count(array_unique($keys)));
    $sorted = $keys; sort($sorted);
    $check("$label ascending", $keys === $sorted);

    // every bucket must lie inside the window (first/last may be clamped)
    $check("$label bucket span", $b[0]['start'] === $r['start'] && end($b)['end'] === $r['end'],
        "first={$b[0]['start']} last=" . end($b)['end']);
}

echo "=== PRESETS ===\n";
foreach (['d7', 'd14', 'd30', 'd90', 'm3', 'm6', 'm7', 'm12', 'm24'] as $p) {
    runCase($pass, $fail, $check, $app, $admin, $TZ, $tz, "preset $p", ['range_preset' => $p]);
}

echo "\n=== CUSTOM RANGES ===\n";
$customs = [
    'same day (busy)' => ['2026-09-03', '2026-09-03'],
    'same day (empty)' => ['2026-07-04', '2026-07-04'],
    'one day boundary' => ['2026-09-01', '2026-09-01'],
    '7 days' => ['2026-09-01', '2026-09-07'],
    '30 days' => ['2026-08-16', '2026-09-14'],
    'month boundary' => ['2026-08-31', '2026-09-01'],
    'year boundary' => ['2025-12-31', '2026-01-01'],
    'crosses two months' => ['2026-08-15', '2026-09-15'],
    'crosses two years' => ['2025-11-01', '2026-02-28'],
    'no data at all' => ['2023-01-01', '2023-03-31'],
    'ends today' => ['2026-09-01', '2026-09-14'],
    'whole of Sep' => ['2026-09-01', '2026-09-30'],
    'two full years' => ['2024-10-01', '2026-09-14'],
];
foreach ($customs as $label => [$a, $z]) {
    runCase($pass, $fail, $check, $app, $admin, $TZ, $tz, $label,
        ['range_preset' => 'custom', 'range_start' => $a, 'range_end' => $z]);
}

echo "\n=== BOUNDARY INCLUSION ===\n";
// 1 Sep contains orders at exactly 00:00:00 and 23:59:59 local
$j = callDashboard($app, $admin, ['dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'custom', 'range_start' => '2026-09-01', 'range_end' => '2026-09-01']);
$s = $j['data']['chart_data']['summary'];
printf("  1 Sep only: total=%s orders=%d (expect 300.00 / 2 - the 00:00:00 and 23:59:59 rows, cancelled excluded)\n",
    $s['total'], $s['orders']);
$check('midnight+lastsecond included', abs($s['total'] - 300.00) < 0.01 && $s['orders'] === 2,
    "got {$s['total']}/{$s['orders']}");

// the day before must not steal the 00:00:00 order
$j = callDashboard($app, $admin, ['dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'custom', 'range_start' => '2026-08-31', 'range_end' => '2026-08-31']);
$s2 = $j['data']['chart_data']['summary'];
printf("  31 Aug only: total=%s orders=%d (expect 111.00 / 1)\n", $s2['total'], $s2['orders']);
$check('no bleed from next day', abs($s2['total'] - 111.00) < 0.01 && $s2['orders'] === 1,
    "got {$s2['total']}/{$s2['orders']}");

echo "\n=== GUARDS ===\n";
// end before start, future end, garbage
foreach ([
    'end before start' => ['2026-09-10', '2026-09-01'],
    'future end' => ['2026-09-01', '2030-01-01'],
    'both future' => ['2029-01-01', '2030-01-01'],
    'garbage dates' => ['not-a-date', 'nonsense'],
] as $label => [$a, $z]) {
    $j = callDashboard($app, $admin, ['dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
        'range_preset' => 'custom', 'range_start' => $a, 'range_end' => $z]);
    if (($j['status'] ?? 0) !== 200) { echo "  $label -> ERROR " . $j['message'] . "\n"; $fail++; continue; }
    $r = $j['data']['chart_data']['range'];
    printf("  %-18s -> %s .. %s (%s, %d buckets)\n", $label, $r['start'], $r['end'], $r['unit'],
        count($j['data']['chart_data']['revenue']));
    $check("$label start<=end", $r['start'] <= $r['end']);
    $check("$label not future", $r['end'] <= (new DateTime('now', $tz))->format('Y-m-d'));
}

echo "\n=== ZERO-SALE DAYS PRESENT ===\n";
$j = callDashboard($app, $admin, ['dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'custom', 'range_start' => '2026-09-01', 'range_end' => '2026-09-10']);
$b = $j['data']['chart_data']['revenue'];
$zeros = count(array_filter($b, fn($x) => $x['value'] == 0));
printf("  10-day window: %d buckets, %d of them zero-value, labels: %s\n",
    count($b), $zeros, implode(',', array_column($b, 'label')));
$check('all 10 days present', count($b) === 10, 'got ' . count($b));
$check('zero days kept', $zeros > 0);

echo "\n=== TIMEZONE SHIFT ===\n";
// The same calendar day in a different zone must select different rows.
foreach (['Asia/Kolkata', 'UTC', 'Pacific/Honolulu'] as $zone) {
    $j = callDashboard($app, $admin, ['dashboard' => 'true', 'time_zone' => $zone, 'order_type' => 'all',
        'range_preset' => 'custom', 'range_start' => '2026-09-01', 'range_end' => '2026-09-01']);
    $s3 = $j['data']['chart_data']['summary'];
    $r3 = $j['data']['chart_data']['range'];
    printf("  %-18s 1 Sep -> total=%-9s orders=%d  utc[%s .. %s)\n",
        $zone, $s3['total'], $s3['orders'], $r3['utc_from'], $r3['utc_to']);
    $o3 = oracle('2026-09-01', '2026-09-01', new DateTimeZone($zone));
    $check("$zone matches oracle", abs($s3['total'] - $o3['total']) < 0.01 && $s3['orders'] === $o3['orders'],
        "got {$s3['total']}/{$s3['orders']} want {$o3['total']}/{$o3['orders']}");
}

DB::rollBack();

echo "\nrows after rollback: " . DB::table('orders')->count() . " (expect 12)\n";
echo ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
