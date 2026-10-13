<?php
/**
 * Verification for the Sales Overview chart's date sequence.
 *
 * Checks the property the chart depends on and nothing else can paper over: for
 * any range, the API returns one record per bucket, in strict chronological
 * order, with no gaps and no duplicates, starting exactly on the range start and
 * ending exactly on the range end - zero-sale days included.
 *
 * It also asserts that each record's own `date` is the date its label, tooltip,
 * revenue and order count all describe, and that the day's figures match an
 * independently written SQL query for that exact calendar day in the viewer's
 * timezone. That is what rules out an off-by-one from UTC conversion.
 *
 *   php scripts/verify-chart-sequence.php
 *   TZ_TEST=Pacific/Honolulu php scripts/verify-chart-sequence.php
 *
 * Runs inside a transaction that is rolled back.
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

$TODAY = (new DateTime('now', $tz))->format('Y-m-d');
$day = function ($back) use ($TODAY, $tz) {
    return (new DateTime($TODAY, $tz))->modify(($back >= 0 ? '-' : '+') . abs($back) . ' days')->format('Y-m-d');
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

/** Revenue and order count for one calendar day in $tz, straight from SQL. */
function dayFigures($date, $tz)
{
    $utc = new DateTimeZone('UTC');
    $from = (new DateTime($date . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $to = (new DateTime($date . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    $r = DB::selectOne(
        "SELECT COALESCE(SUM(total_amount),0) v, COUNT(DISTINCT id) c
         FROM orders WHERE created_at >= ? AND created_at < ? AND cancelled <> 1",
        [$from, $to]
    );
    return ['value' => round((float)$r->v, 2), 'orders' => (int)$r->c];
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
        'order' => 'SEQ' . str_pad(++$n, 6, '0', STR_PAD_LEFT), 'viewed' => 0,
    ];
};

// Orders hugging midnight on both sides, so a one-day slip would show up.
$mk($day(0) . ' 00:00:00', 11.00);
$mk($day(0) . ' 23:59:59', 12.00);
$mk($day(1) . ' 23:59:59', 13.00);
$mk($day(2) . ' 00:00:00', 14.00);
$mk($day(2) . ' 00:00:01', 15.00);
$mk($day(2) . ' 23:59:58', 16.00);
// several on one day
foreach ([1, 2, 3, 4, 5] as $i) {
    $mk($day(6) . sprintf(' %02d:30:00', 6 + $i), 10.00 * $i);
}
// month and year edges
$mk('2026-08-31 23:59:59', 21.00);
$mk('2026-09-01 00:00:00', 22.00);
$mk('2025-12-31 23:59:59', 23.00);
$mk('2026-01-01 00:00:00', 24.00);
$mk($day(3) . ' 12:00:00', 9999.00, 1);   // cancelled

DB::table('orders')->insert($rows);
echo 'seeded ' . count($rows) . " orders\n\n";

$pass = 0;
$fail = 0;
$check = function ($what, $ok, $detail = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; } else { $fail++; echo "    FAIL $what $detail\n"; }
};

$cases = [
    '1-day' => [$TODAY, $TODAY],
    '2-day' => [$day(1), $TODAY],
    '7-day' => [$day(6), $TODAY],
    '14-day' => [$day(13), $TODAY],
    '30-day' => [$day(29), $TODAY],
    'month boundary' => ['2026-08-28', '2026-09-03'],
    'year boundary' => ['2025-12-28', '2026-01-03'],
    'zero-sales run' => ['2026-05-01', '2026-05-20'],
    'ends today' => ['2026-08-01', $TODAY],
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
    $b = $c['revenue'];
    $unit = $r['unit'];

    // expected calendar days in the window
    $expectedDays = (int)(new DateTime($a))->diff(new DateTime($z))->days + 1;

    printf("%-16s %s..%s %-5s %3d records", $label, $r['start'], $r['end'], $unit, count($b));

    // every record must carry its own date
    $missingDate = count(array_filter($b, fn($x) => empty($x['date'])));
    $check("$label every record has a date", $missingDate === 0, "$missingDate missing");

    $dates = array_column($b, 'date');

    // strictly ascending, unique
    $sorted = $dates;
    sort($sorted);
    $check("$label chronological", $dates === $sorted);
    $check("$label unique", count($dates) === count(array_unique($dates)));

    // exact endpoints
    $check("$label starts on range start", $b[0]['date'] === $a, "{$b[0]['date']} vs $a");
    $check("$label ends on range end", end($b)['end'] === $z, end($b)['end'] . " vs $z");

    // day granularity: one record per calendar day, no gaps
    if ($unit === 'day') {
        $check("$label one record per day", count($b) === $expectedDays,
            count($b) . " vs $expectedDays");

        $gaps = 0;
        for ($i = 1; $i < count($dates); $i++) {
            $delta = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
            if ((int)round($delta) !== 1) { $gaps++; }
        }
        $check("$label no gaps", $gaps === 0, "$gaps gaps");

        // each record's figures must match SQL for that very date
        $mismatch = 0;
        $zeroDays = 0;
        foreach ($b as $rec) {
            $sql = dayFigures($rec['date'], $tz);
            if (abs($rec['value'] - $sql['value']) > 0.01 || (int)$rec['orders'] !== $sql['orders']) {
                $mismatch++;
                if ($mismatch <= 3) {
                    echo "\n      date {$rec['date']}: api {$rec['value']}/{$rec['orders']} vs sql {$sql['value']}/{$sql['orders']}";
                }
            }
            if ($rec['value'] == 0 && $rec['orders'] === 0) { $zeroDays++; }
        }
        $check("$label every day matches SQL", $mismatch === 0, "$mismatch mismatched");

        // the label must be the record's own date, not a neighbour's
        $labelMismatch = 0;
        foreach ($b as $rec) {
            $want = (new DateTime($rec['date']))->format('j M');
            $wantFull = (new DateTime($rec['date']))->format('j M Y');
            if ($rec['label'] !== $want || $rec['full_label'] !== $wantFull) {
                $labelMismatch++;
                if ($labelMismatch <= 3) {
                    echo "\n      {$rec['date']}: label \"{$rec['label']}\" full \"{$rec['full_label']}\" want \"$want\" / \"$wantFull\"";
                }
            }
        }
        $check("$label labels match own date", $labelMismatch === 0, "$labelMismatch wrong");

        printf("  zero-days=%-3d", $zeroDays);
    }

    // sum of records equals the period total, so nothing is dropped or doubled
    $sum = round(array_sum(array_column($b, 'value')), 2);
    $ord = array_sum(array_column($b, 'orders'));
    $check("$label records sum to total", abs($sum - $c['summary']['total']) < 0.01,
        "$sum vs {$c['summary']['total']}");
    $check("$label records sum to orders", $ord === $c['summary']['orders'],
        "$ord vs {$c['summary']['orders']}");

    echo "\n";
}

echo "\n=== MIDNIGHT ORDERS LAND ON THE RIGHT DAY ===\n";
$j = call($app, $admin, [
    'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'custom', 'range_start' => $day(3), 'range_end' => $TODAY,
]);
$byDate = [];
foreach ($j['data']['chart_data']['revenue'] as $rec) {
    $byDate[$rec['date']] = $rec;
}
foreach ([0 => 23.00, 1 => 13.00, 2 => 45.00] as $back => $want) {
    $d = $day($back);
    $got = $byDate[$d] ?? null;
    printf("  %s -> %s (expect %.2f)\n", $d, $got ? number_format($got['value'], 2) : 'MISSING', $want);
    $check("midnight orders on $d", $got && abs($got['value'] - $want) < 0.01,
        $got ? "got {$got['value']}" : 'missing');
}

echo "\n=== COMPARISON PERIOD IS THE SAME LENGTH ===\n";
$j = call($app, $admin, [
    'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'custom', 'range_start' => $day(6), 'range_end' => $TODAY, 'compare' => 1,
]);
$r = $j['data']['chart_data']['range'];
$p = $j['data']['chart_data']['summary']['previous'];
$curDays = (int)(new DateTime($r['start']))->diff(new DateTime($r['end']))->days + 1;
$prevDays = (int)(new DateTime($p['start']))->diff(new DateTime($p['end']))->days + 1;
printf("  current %s..%s (%d days), previous %s..%s (%d days)\n",
    $r['start'], $r['end'], $curDays, $p['start'], $p['end'], $prevDays);
$check('comparison same day count', $curDays === $prevDays, "$curDays vs $prevDays");
$check('comparison abuts current', $p['utc_to'] === $r['utc_from']);
$check('comparison does not overlap', $p['end'] < $r['start'], "{$p['end']} vs {$r['start']}");

DB::rollBack();

echo "\nrows after rollback: " . DB::table('orders')->count() . "\n";
echo ($fail === 0 ? 'ALL PASS' : 'FAILURES') . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
