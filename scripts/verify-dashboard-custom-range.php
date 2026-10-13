<?php
/**
 * Verification for the Sales Overview custom date-range picker.
 *
 * Seeds orders on the exact instants a picked range can open and close - the
 * first and last second of a start day and an end day, month edges, year edges -
 * then drives the real controller with the ranges a user would pick and
 * cross-checks revenue, order count, average order value, chart values and date
 * labels against an independently written SQL query.
 *
 *   php scripts/verify-dashboard-custom-range.php
 *   TZ_TEST=Pacific/Honolulu php scripts/verify-dashboard-custom-range.php
 *
 * Everything happens inside a transaction that is rolled back, so it is safe
 * against a live database. Companion to verify-dashboard-ranges.php, which
 * covers the preset windows still used by Order Statistics.
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
$utcZone = new DateTimeZone('UTC');
$admin = Admin::find(1);

/*
 * "Today" is whatever day it is where the viewer is, not where the server is:
 * Honolulu can still be on the 13th while Sydney is on the 14th, and the API
 * clamps an end date in the future. Express every case relative to the test
 * timezone rather than hardcoding a date.
 */
$TODAY = (new DateTime('now', $tz))->format('Y-m-d');
$shift = function ($days) use ($TODAY, $tz) {
    return (new DateTime($TODAY, $tz))->modify(($days >= 0 ? '-' : '+') . abs($days) . ' days')->format('Y-m-d');
};

function call($app, $admin, $params)
{
    $req = Request::create('http://localhost/api/admin/dashboard', 'GET', $params);
    $req->headers->set('Accept', 'application/json');
    $app->instance('request', $req);
    Illuminate\Support\Facades\Auth::guard('admin')->setUser($admin);
    $c = new AdminsController();
    $r = new ReflectionObject($c);
    foreach (['user' => $admin, 'isSuperAdmin' => true, 'isVendor' => false] as $k => $v) {
        $p = $r->getProperty($k);
        $p->setAccessible(true);
        $p->setValue($c, $v);
    }
    return json_decode($c->dashboard($req)->getContent(), true);
}

function oracle($from, $to, $tz)
{
    $utc = new DateTimeZone('UTC');
    $a = (new DateTime($from . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $z = (new DateTime($to . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    $r = DB::selectOne(
        "SELECT COALESCE(SUM(total_amount),0) v, COUNT(DISTINCT id) c
         FROM orders WHERE created_at >= ? AND created_at < ? AND cancelled <> 1",
        [$a, $z]
    );
    return [
        'total' => round((float)$r->v, 2),
        'orders' => (int)$r->c,
        'avg' => (int)$r->c ? round((float)$r->v / (int)$r->c, 2) : 0,
        'from' => $a,
        'to' => $z,
    ];
}

echo "timezone $TZ, today is $TODAY\n";

DB::beginTransaction();

$rows = [];
$n = 0;
$mk = function ($local, $amount, $cancelled = 0) use (&$rows, &$n, $tz, $utcZone) {
    $at = (new DateTime($local, $tz))->setTimezone($utcZone)->format('Y-m-d H:i:s');
    $rows[] = [
        'status' => 1, 'total_amount' => $amount, 'order_method' => 1, 'currency' => 'EUR',
        'payment_done' => 1, 'cancelled' => $cancelled,
        'created_at' => $at, 'updated_at' => $at,
        'order' => 'PT' . str_pad(++$n, 6, '0', STR_PAD_LEFT), 'viewed' => 0,
    ];
};

$START = '2026-08-17';
$mk($START . ' 00:00:00', 40.00);          // first instant of a start day
$mk($START . ' 23:59:59', 60.00);          // last instant of a start day
$mk($TODAY . ' 00:00:00', 25.00);          // first instant of today as an end day
$mk($TODAY . ' 23:59:59', 75.00);          // last instant of an end day
$mk($shift(30) . ' 23:59:59', 999.00);     // outside a 29-day-back window
$mk($shift(-1) . ' 00:00:00', 888.00);     // tomorrow - never selectable
$mk('2026-07-31 23:59:59', 10.00);         // month edge
$mk('2026-08-01 00:00:00', 20.00);
$mk('2025-12-31 23:59:59', 30.00);         // year edge
$mk('2026-01-01 00:00:00', 50.00);
$mk('2026-08-20 12:00:00', 5000.00, 1);    // cancelled, must not be revenue
for ($i = 0; $i < 40; $i++) {              // a busy single day
    $mk(sprintf('2026-08-25 %02d:%02d:00', $i % 24, ($i * 7) % 60), 12.50);
}

DB::table('orders')->insert($rows);
echo 'seeded ' . count($rows) . " orders\n\n";

$pass = 0;
$fail = 0;
$check = function ($what, $ok, $detail = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; } else { $fail++; echo "    FAIL $what $detail\n"; }
};

$cases = [
    'default last 30 days' => [$shift(29), $TODAY],
    'single day (busy)' => ['2026-08-25', '2026-08-25'],
    'single day (start boundary)' => [$START, $START],
    'single day (end = today)' => [$TODAY, $TODAY],
    '7-day range' => [$START, '2026-08-23'],
    '30-day range' => [$shift(29), $TODAY],
    'month to month' => ['2026-07-15', '2026-08-15'],
    'month edge pair' => ['2026-07-31', '2026-08-01'],
    'year to year' => ['2025-12-31', '2026-01-01'],
    'year span' => ['2025-06-01', $TODAY],
    'ends today' => ['2026-08-01', $TODAY],
    'orders on start date' => [$START, '2026-08-19'],
    'orders on end date' => [$shift(2), $TODAY],
    'range with no data' => ['2026-03-01', '2026-03-31'],
];

foreach ($cases as $label => [$a, $z]) {
    $j = call($app, $admin, [
        'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
        'range_preset' => 'custom', 'range_start' => $a, 'range_end' => $z,
    ]);

    if (($j['status'] ?? 0) !== 200) {
        echo "$label: ERROR " . ($j['message'] ?? '?') . "\n";
        $fail++;
        continue;
    }

    $c = $j['data']['chart_data'];
    $r = $c['range'];
    $s = $c['summary'];
    $b = $c['revenue'];
    $o = oracle($a, $z, $tz);

    $sumB = round(array_sum(array_column($b, 'value')), 2);
    $sumO = array_sum(array_column($b, 'orders'));

    printf(
        "%-30s %s..%s %-5s %3db  total=%-10s orders=%-4d avg=%s\n",
        $label, $r['start'], $r['end'], $r['unit'], count($b),
        number_format($s['total'], 2), $s['orders'], number_format($s['average_order'], 2)
    );

    $check("$label range echoed", $r['start'] === $a && $r['end'] === $z, "{$r['start']}..{$r['end']}");
    $check("$label utc window", $r['utc_from'] === $o['from'] && $r['utc_to'] === $o['to']);
    $check("$label revenue", abs($s['total'] - $o['total']) < 0.01, "got {$s['total']} want {$o['total']}");
    $check("$label order count", $s['orders'] === $o['orders'], "got {$s['orders']} want {$o['orders']}");
    $check("$label avg order", abs($s['average_order'] - $o['avg']) < 0.01, "got {$s['average_order']} want {$o['avg']}");
    $check("$label chart sum", abs($sumB - $s['total']) < 0.01, "buckets $sumB vs {$s['total']}");
    $check("$label chart orders", $sumO === $s['orders'], "buckets $sumO vs {$s['orders']}");
    $check("$label reconciled", $s['reconciled'] === true);
    $check("$label first label in range", $b[0]['start'] === $a, "first bucket starts {$b[0]['start']}");
    $check("$label last label in range", end($b)['end'] === $z, 'last bucket ends ' . end($b)['end']);
}

echo "\n=== DAY BOUNDARIES ===\n";
$j = call($app, $admin, [
    'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'custom', 'range_start' => $START, 'range_end' => $TODAY,
]);
$byKey = [];
foreach ($j['data']['chart_data']['revenue'] as $x) {
    $byKey[$x['key']] = $x;
}
printf("  %s (start day): %s / %s orders - expect 100.00 / 2\n",
    $START, $byKey[$START]['value'] ?? 'MISSING', $byKey[$START]['orders'] ?? '-');
printf("  %s (end day):   %s / %s orders - expect 100.00 / 2\n",
    $TODAY, $byKey[$TODAY]['value'] ?? 'MISSING', $byKey[$TODAY]['orders'] ?? '-');

$check('start day keeps 00:00:00 and 23:59:59', isset($byKey[$START])
    && abs($byKey[$START]['value'] - 100.00) < 0.01 && $byKey[$START]['orders'] === 2);
$check('end day keeps 00:00:00 and 23:59:59', isset($byKey[$TODAY])
    && abs($byKey[$TODAY]['value'] - 100.00) < 0.01 && $byKey[$TODAY]['orders'] === 2);
$check('tomorrow never appears', !isset($byKey[$shift(-1)]));

echo "\n=== FUTURE END DATE CLAMPED ===\n";
$j = call($app, $admin, [
    'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'custom', 'range_start' => $shift(6), 'range_end' => $shift(-30),
]);
$r = $j['data']['chart_data']['range'];
printf("  asked to %s, got %s..%s\n", $shift(-30), $r['start'], $r['end']);
$check('future end clamped to today', $r['end'] === $TODAY, "got {$r['end']}");

echo "\n=== CANCELLED EXCLUDED ===\n";
$j = call($app, $admin, [
    'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'custom', 'range_start' => '2026-08-20', 'range_end' => '2026-08-20',
]);
$s = $j['data']['chart_data']['summary'];
printf("  20 Aug: total=%s orders=%d, cancelled=%d worth %s\n",
    $s['total'], $s['orders'], $s['cancelled_orders'], $s['cancelled_amount']);
$check('cancelled not counted as revenue', abs($s['total']) < 0.01 && $s['orders'] === 0);
$check('cancelled still reported', $s['cancelled_orders'] === 1 && abs($s['cancelled_amount'] - 5000.00) < 0.01);

DB::rollBack();

echo "\nrows after rollback: " . DB::table('orders')->count() . "\n";
echo ($fail === 0 ? 'ALL PASS' : 'FAILURES') . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
