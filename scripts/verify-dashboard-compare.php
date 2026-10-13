<?php
/**
 * Verification for the dashboard's quick presets and the previous-period
 * comparison.
 *
 * Seeds two windows with deliberately different totals so the percentages have
 * a known right answer, then checks every quick preset (Today, Yesterday, This
 * Week, This Month, Last 30 Days, This Year) and the comparison figures against
 * an independently written SQL query.
 *
 *   php scripts/verify-dashboard-compare.php
 *   TZ_TEST=Pacific/Honolulu php scripts/verify-dashboard-compare.php
 *
 * Runs inside a transaction that is rolled back. Companions:
 * verify-dashboard-ranges.php and verify-dashboard-custom-range.php.
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
    return (new DateTime($TODAY, $tz))->modify("-$back days")->format('Y-m-d');
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

/** Aggregate straight from SQL over a half-open UTC window. */
function agg($fromUtc, $toUtc)
{
    $r = DB::selectOne(
        "SELECT COALESCE(SUM(total_amount),0) v, COUNT(DISTINCT id) c
         FROM orders WHERE created_at >= ? AND created_at < ? AND cancelled <> 1",
        [$fromUtc, $toUtc]
    );
    return ['total' => round((float)$r->v, 2), 'orders' => (int)$r->c];
}

function pct($cur, $prev)
{
    if ((float)$prev == 0.0) {
        return (float)$cur > 0 ? 100.0 : 0.0;
    }
    return round((((float)$cur - (float)$prev) / (float)$prev) * 100, 1);
}

echo "timezone $TZ, today is $TODAY\n";

DB::beginTransaction();

/*
 * The database already holds real orders, and some of them fall inside the
 * windows under test. Capture what is there first so the known-answer block
 * can state "baseline plus what we seeded" rather than pretending the table
 * starts empty.
 */
$baselineCur = null;
$baselinePrev = null;

$rows = [];
$n = 0;
$mk = function ($local, $amount, $cancelled = 0) use (&$rows, &$n, $tz, $utcZone) {
    $at = (new DateTime($local, $tz))->setTimezone($utcZone)->format('Y-m-d H:i:s');
    $rows[] = [
        'status' => 1, 'total_amount' => $amount, 'order_method' => 1, 'currency' => 'EUR',
        'payment_done' => 1, 'cancelled' => $cancelled,
        'created_at' => $at, 'updated_at' => $at,
        'order' => 'CMP' . str_pad(++$n, 6, '0', STR_PAD_LEFT), 'viewed' => 0,
    ];
};

/*
 * Current 30-day window (days 0..29 back) gets a known total, and the previous
 * 30-day window (days 30..59 back) a different one, so the percentage has an
 * answer that can be worked out by hand as well as by SQL.
 */
$mk($TODAY . ' 10:00:00', 100.00);          // today
$mk($TODAY . ' 23:59:59', 50.00);           // today, last instant
$mk($day(1) . ' 12:00:00', 200.00);         // yesterday
$mk($day(5) . ' 09:00:00', 300.00);         // inside this-week-ish
$mk($day(20) . ' 09:00:00', 400.00);        // inside last 30 days
$mk($day(29) . ' 00:00:00', 150.00);        // first day of the 30-day window
$mk($day(35) . ' 12:00:00', 500.00);        // previous 30-day window
$mk($day(50) . ' 12:00:00', 250.00);        // previous 30-day window
$mk($day(70) . ' 12:00:00', 900.00);        // older than both windows
$mk($day(3) . ' 12:00:00', 7777.00, 1);     // cancelled - never revenue

// Measure the windows before seeding, using the API's own bounds so the
// baseline is taken over exactly the range the assertions will use.
$probe = call($app, $admin, [
    'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'd30', 'compare' => 1,
]);
$probeRange = $probe['data']['chart_data']['range'];
$probePrev = $probe['data']['chart_data']['summary']['previous'];
$baselineCur = agg($probeRange['utc_from'], $probeRange['utc_to']);
$baselinePrev = agg($probePrev['utc_from'], $probePrev['utc_to']);

DB::table('orders')->insert($rows);
echo 'seeded ' . count($rows) . " orders\n\n";

$pass = 0;
$fail = 0;
$check = function ($what, $ok, $detail = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; } else { $fail++; echo "    FAIL $what $detail\n"; }
};

echo "=== QUICK PRESETS ===\n";
$expected = [
    'today' => [$TODAY, $TODAY],
    'yesterday' => [$day(1), $day(1)],
    'week' => [(new DateTime($TODAY, $tz))->modify('-' . ((int)(new DateTime($TODAY, $tz))->format('N') - 1) . ' days')->format('Y-m-d'), $TODAY],
    'month' => (new DateTime($TODAY, $tz))->format('Y-m-01'),
    'd30' => [$day(29), $TODAY],
    'year' => (new DateTime($TODAY, $tz))->format('Y-01-01'),
];

foreach (['today', 'yesterday', 'week', 'month', 'd30', 'year'] as $preset) {
    $j = call($app, $admin, [
        'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
        'range_preset' => $preset, 'compare' => 1,
    ]);

    if (($j['status'] ?? 0) !== 200) {
        echo "$preset: ERROR " . ($j['message'] ?? '?') . "\n";
        $fail++;
        continue;
    }

    $c = $j['data']['chart_data'];
    $r = $c['range'];
    $s = $c['summary'];
    $p = $s['previous'];

    $cur = agg($r['utc_from'], $r['utc_to']);
    $prv = agg($p['utc_from'], $p['utc_to']);

    printf(
        "%-10s %s..%s %-5s  now=%-9s/%-2d  prev=%-9s/%-2d  rev %+.1f%%  ord %+.1f%%\n",
        $preset, $r['start'], $r['end'], $r['unit'],
        number_format($s['total'], 2), $s['orders'],
        number_format($p['total'], 2), $p['orders'],
        $p['change']['total'], $p['change']['orders']
    );

    $check("$preset revenue", abs($s['total'] - $cur['total']) < 0.01, "got {$s['total']} want {$cur['total']}");
    $check("$preset orders", $s['orders'] === $cur['orders'], "got {$s['orders']} want {$cur['orders']}");
    $check("$preset prev revenue", abs($p['total'] - $prv['total']) < 0.01, "got {$p['total']} want {$prv['total']}");
    $check("$preset prev orders", $p['orders'] === $prv['orders'], "got {$p['orders']} want {$prv['orders']}");
    $check("$preset revenue pct", abs($p['change']['total'] - pct($cur['total'], $prv['total'])) < 0.05,
        "got {$p['change']['total']} want " . pct($cur['total'], $prv['total']));
    $check("$preset orders pct", abs($p['change']['orders'] - pct($cur['orders'], $prv['orders'])) < 0.05,
        "got {$p['change']['orders']} want " . pct($cur['orders'], $prv['orders']));
    $check("$preset baseline flag", $p['baseline_empty'] === ($prv['orders'] === 0));

    // the comparison window must sit immediately before, same length, no overlap
    $check("$preset prev abuts", $p['utc_to'] === $r['utc_from'], "{$p['utc_to']} vs {$r['utc_from']}");
    $spanCur = strtotime($r['utc_to'] . ' UTC') - strtotime($r['utc_from'] . ' UTC');
    $spanPrev = strtotime($p['utc_to'] . ' UTC') - strtotime($p['utc_from'] . ' UTC');
    $check("$preset prev same length", $spanCur === $spanPrev, "$spanCur vs $spanPrev");
    $check("$preset chart reconciled", $s['reconciled'] === true);

    // shape expectations
    if ($preset === 'today') {
        $check('today is one day', $r['start'] === $TODAY && $r['end'] === $TODAY);
    }
    if ($preset === 'yesterday') {
        $check('yesterday is one day', $r['start'] === $day(1) && $r['end'] === $day(1));
    }
    if ($preset === 'week') {
        $check('week starts Monday', (new DateTime($r['start']))->format('N') === '1', 'starts ' . $r['start']);
    }
    if ($preset === 'month') {
        $check('month starts on the 1st', $r['start'] === $expected['month'], 'starts ' . $r['start']);
        $check('month ends today', $r['end'] === $TODAY);
    }
    if ($preset === 'year') {
        $check('year starts 1 Jan', $r['start'] === $expected['year'], 'starts ' . $r['start']);
    }
    if ($preset === 'd30') {
        $check('d30 spans 30 days', $r['span_days'] === 30, 'span ' . $r['span_days']);
    }
}

echo "\n=== KNOWN-ANSWER COMPARISON (last 30 days) ===\n";
$j = call($app, $admin, [
    'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all',
    'range_preset' => 'd30', 'compare' => 1,
]);
$s = $j['data']['chart_data']['summary'];
$p = $s['previous'];

/*
 * Seeded into the current window: 100 + 50 + 200 + 300 + 400 + 150 over six
 * orders; into the previous one: 500 + 250 over two. The 7777 cancelled order
 * must appear in neither. Whatever the table already held in those windows is
 * added on, so the arithmetic holds against a database with real data in it.
 */
$expCurTotal = round($baselineCur['total'] + 1200.00, 2);
$expCurOrders = $baselineCur['orders'] + 6;
$expPrevTotal = round($baselinePrev['total'] + 750.00, 2);
$expPrevOrders = $baselinePrev['orders'] + 2;

printf("  baseline already present: current %s / %d, previous %s / %d\n",
    number_format($baselineCur['total'], 2), $baselineCur['orders'],
    number_format($baselinePrev['total'], 2), $baselinePrev['orders']);
printf("  current  %s / %d orders (expect %s / %d)\n",
    number_format($s['total'], 2), $s['orders'], number_format($expCurTotal, 2), $expCurOrders);
printf("  previous %s / %d orders (expect %s / %d)\n",
    number_format($p['total'], 2), $p['orders'], number_format($expPrevTotal, 2), $expPrevOrders);
printf("  revenue change %+.1f%% (expect %+.1f%%)\n", $p['change']['total'], pct($expCurTotal, $expPrevTotal));
printf("  orders change  %+.1f%% (expect %+.1f%%)\n", $p['change']['orders'], pct($expCurOrders, $expPrevOrders));

$check('known current total', abs($s['total'] - $expCurTotal) < 0.01, "got {$s['total']} want $expCurTotal");
$check('known current orders', $s['orders'] === $expCurOrders, "got {$s['orders']} want $expCurOrders");
$check('known previous total', abs($p['total'] - $expPrevTotal) < 0.01, "got {$p['total']} want $expPrevTotal");
$check('known previous orders', $p['orders'] === $expPrevOrders, "got {$p['orders']} want $expPrevOrders");
$check('known revenue pct', abs($p['change']['total'] - pct($expCurTotal, $expPrevTotal)) < 0.05,
    "got {$p['change']['total']}");
$check('known orders pct', abs($p['change']['orders'] - pct($expCurOrders, $expPrevOrders)) < 0.05,
    "got {$p['change']['orders']}");
// the 7777 cancelled order sits in the current window and must not be in it
$check('cancelled excluded from revenue', $s['total'] < 7777.00 || abs($s['total'] - $expCurTotal) < 0.01);

echo "\n=== COMPARE OFF BY DEFAULT ===\n";
$j = call($app, $admin, [
    'dashboard' => 'true', 'time_zone' => $TZ, 'order_type' => 'all', 'range_preset' => 'd30',
]);
$check('no comparison unless asked', $j['data']['chart_data']['summary']['previous'] === null);
echo "  previous = " . var_export($j['data']['chart_data']['summary']['previous'], true) . "\n";

echo "\n=== LIFETIME UNAFFECTED ===\n";
$life = agg('1970-01-01 00:00:00', '2100-01-01 00:00:00');
foreach (['today', 'month', 'd30', 'year'] as $preset) {
    $j = call($app, $admin, [
        'dashboard' => 'false', 'time_zone' => $TZ, 'order_type' => 'all',
        'range_preset' => $preset, 'compare' => 1,
    ]);
    $k = $j['data']['dashboard'];
    $check("lifetime orders steady under $preset", (int)$k['orders'] === DB::table('orders')->count(),
        "got {$k['orders']}");
}
printf("  lifetime orders=%d sells=%s (unchanged by any range)\n",
    DB::table('orders')->count(), number_format((float)$life['total'], 2));

DB::rollBack();

echo "\nrows after rollback: " . DB::table('orders')->count() . "\n";
echo ($fail === 0 ? 'ALL PASS' : 'FAILURES') . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
