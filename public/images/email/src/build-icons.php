<?php
/**
 * Generates the PNG icons and illustrations used by the email templates.
 *
 * Email needs raster images: Gmail strips inline SVG and Outlook cannot draw
 * it at all, so every icon in a template has to be a PNG on a reachable URL.
 * These are drawn as SVG here, tinted with the store's own primary colour
 * from site_settings, and rasterised at 2x through headless Chrome.
 *
 * Re-run it after changing the brand colour:
 *
 *     php public/images/email/src/build-icons.php
 *
 * Chrome is only needed to build the assets, never to send an email.
 */

$root = dirname(__DIR__, 4);

require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$brand = \App\Models\Helper\MailBrand::make();

$P = $brand->primary;                                   // brand primary
$PD = $brand->primary_dark;                             // darker shade
$PS = $brand->primary_soft;                             // very light tint
$PE = $brand->primary_edge;                             // light tint / borders
$INK = '#0f2547';                                       // deep navy for line art
$GREEN = '#22c55e';
$AMBER = '#fbbf24';
$WHITE = '#ffffff';
$GREY = '#94a3b8';

$out = dirname(__FILE__, 2);
$srcDir = __DIR__;

/**
 * Confetti dots scattered around an illustration, same in every hero so the
 * set reads as one family.
 */
$confetti = function () use ($P, $GREEN, $AMBER) {
    return '
    <circle cx="24"  cy="46" r="4"   fill="' . $P . '"/>
    <circle cx="14"  cy="86" r="3.4" fill="' . $P . '" opacity=".55"/>
    <circle cx="46"  cy="24" r="3"   fill="' . $GREEN . '"/>
    <circle cx="172" cy="36" r="4"   fill="' . $GREEN . '" opacity=".8"/>
    <circle cx="186" cy="74" r="3.4" fill="' . $AMBER . '"/>
    <circle cx="150" cy="18" r="3"   fill="' . $AMBER . '"/>
    <circle cx="60"  cy="112" r="3"  fill="' . $P . '" opacity=".35"/>
    <circle cx="164" cy="110" r="3.4" fill="' . $P . '" opacity=".45"/>';
};

/** The green "done" badge that sits on the corner of a hero illustration. */
$tick = function ($cx, $cy, $r = 20) use ($GREEN, $WHITE) {
    return '
    <circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($r + 4) . '" fill="#ffffff"/>
    <circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . $GREEN . '"/>
    <path d="M' . ($cx - 8.5) . ' ' . $cy . ' l6 6 11 -12" fill="none" stroke="' . $WHITE . '"
          stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>';
};

$icons = [];

/* ------------------------------------------------------------------ heroes */

$icons['hero-verify'] = [200, 140, '
    ' . $confetti() . '
    <!-- letter peeking out of the envelope -->
    <rect x="74" y="26" width="56" height="44" rx="6" fill="' . $WHITE . '" stroke="' . $PE . '" stroke-width="2"/>
    <rect x="84" y="38" width="36" height="4" rx="2" fill="' . $PE . '"/>
    <rect x="84" y="48" width="28" height="4" rx="2" fill="' . $PE . '"/>
    <!-- envelope -->
    <path d="M62 62 h80 a8 8 0 0 1 8 8 v34 a8 8 0 0 1 -8 8 h-80 a8 8 0 0 1 -8 -8 v-34 a8 8 0 0 1 8 -8 z"
          fill="' . $P . '"/>
    <path d="M54 70 l48 30 48 -30" fill="none" stroke="' . $WHITE . '" stroke-width="3.2"
          stroke-linecap="round" stroke-linejoin="round" opacity=".85"/>
    ' . $tick(146, 100) . '
'];

$icons['hero-lock'] = [200, 140, '
    ' . $confetti() . '
    <path d="M84 60 v-12 a16 16 0 0 1 32 0 v12" fill="none" stroke="' . $INK . '"
          stroke-width="7" stroke-linecap="round"/>
    <rect x="68" y="58" width="64" height="54" rx="12" fill="' . $P . '"/>
    <circle cx="100" cy="80" r="7" fill="' . $WHITE . '"/>
    <rect x="97" y="84" width="6" height="14" rx="3" fill="' . $WHITE . '"/>
'];

$icons['hero-order'] = [200, 140, '
    ' . $confetti() . '
    <path d="M100 34 l42 20 -42 20 -42 -20 z" fill="' . $PE . '"/>
    <path d="M58 54 v40 l42 20 v-40 z" fill="' . $P . '"/>
    <path d="M142 54 v40 l-42 20 v-40 z" fill="' . $PD . '"/>
    ' . $tick(146, 100) . '
'];

$icons['hero-truck'] = [200, 140, '
    ' . $confetti() . '
    <rect x="42" y="52" width="66" height="44" rx="8" fill="' . $P . '"/>
    <path d="M108 64 h22 l18 18 v14 a4 4 0 0 1 -4 4 h-36 z" fill="' . $PD . '"/>
    <rect x="114" y="68" width="18" height="13" rx="3" fill="' . $WHITE . '" opacity=".55"/>
    <circle cx="68" cy="102" r="11" fill="' . $INK . '"/>
    <circle cx="68" cy="102" r="4.5" fill="' . $WHITE . '"/>
    <circle cx="132" cy="102" r="11" fill="' . $INK . '"/>
    <circle cx="132" cy="102" r="4.5" fill="' . $WHITE . '"/>
    <rect x="20" y="62" width="18" height="5" rx="2.5" fill="' . $P . '" opacity=".45"/>
    <rect x="14" y="76" width="24" height="5" rx="2.5" fill="' . $P . '" opacity=".3"/>
'];

$icons['hero-store'] = [200, 140, '
    ' . $confetti() . '
    <path d="M58 46 h84 l10 20 h-104 z" fill="' . $PD . '"/>
    <rect x="58" y="66" width="84" height="48" rx="6" fill="' . $P . '"/>
    <rect x="76" y="82" width="20" height="32" rx="3" fill="' . $WHITE . '" opacity=".85"/>
    <rect x="108" y="82" width="20" height="16" rx="3" fill="' . $WHITE . '" opacity=".5"/>
    ' . $tick(146, 100) . '
'];

$icons['hero-alert'] = [200, 140, '
    ' . $confetti() . '
    <circle cx="100" cy="74" r="40" fill="' . $P . '"/>
    <rect x="95" y="52" width="10" height="26" rx="5" fill="' . $WHITE . '"/>
    <circle cx="100" cy="90" r="5.5" fill="' . $WHITE . '"/>
'];

/* ------------------------------------------------------------- small icons */

$icons['icon-shield'] = [40, 40, '
    <path d="M20 4 l13 5 v10 c0 8 -5.5 14.5 -13 17 -7.5 -2.5 -13 -9 -13 -17 v-10 z"
          fill="none" stroke="' . $GREEN . '" stroke-width="3" stroke-linejoin="round"/>
    <path d="M14 20 l4.5 4.5 8 -9" fill="none" stroke="' . $GREEN . '" stroke-width="3"
          stroke-linecap="round" stroke-linejoin="round"/>
'];

$icons['icon-headset'] = [44, 44, '
    <path d="M8 26 v-4 a14 14 0 0 1 28 0 v4" fill="none" stroke="' . $P . '"
          stroke-width="3.2" stroke-linecap="round"/>
    <rect x="4" y="24" width="9" height="13" rx="4.5" fill="' . $P . '"/>
    <rect x="31" y="24" width="9" height="13" rx="4.5" fill="' . $P . '"/>
    <path d="M36 37 v1 a4 4 0 0 1 -4 4 h-7" fill="none" stroke="' . $P . '"
          stroke-width="3.2" stroke-linecap="round"/>
'];

$icons['icon-mail'] = [32, 32, '
    <rect x="3" y="7" width="26" height="19" rx="4" fill="none" stroke="' . $P . '" stroke-width="2.6"/>
    <path d="M5 10 l11 8 11 -8" fill="none" stroke="' . $P . '" stroke-width="2.6"
          stroke-linecap="round" stroke-linejoin="round"/>
'];

$icons['icon-phone'] = [32, 32, '
    <path d="M9 4 h5 l3 7 -3.5 2.5 a14 14 0 0 0 6 6 l2.5 -3.5 7 3 v5 a3 3 0 0 1 -3.4 3
             C15 26 6 17 6 7.4 A3 3 0 0 1 9 4 z" fill="' . $P . '"/>
'];

$icons['icon-warn'] = [40, 40, '
    <path d="M20 5 L36 33 H4 Z" fill="none" stroke="#b4791b" stroke-width="3" stroke-linejoin="round"/>
    <rect x="18.2" y="14" width="3.6" height="10" rx="1.8" fill="#b4791b"/>
    <circle cx="20" cy="28" r="2.1" fill="#b4791b"/>
'];

$icons['icon-clock'] = [28, 28, '
    <circle cx="14" cy="14" r="10.5" fill="none" stroke="' . $GREY . '" stroke-width="2.4"/>
    <path d="M14 8 v6.5 l4 2.5" fill="none" stroke="' . $GREY . '" stroke-width="2.4"
          stroke-linecap="round" stroke-linejoin="round"/>
'];

/* ------------------------------------------------------------------ render */

$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe';

if (!file_exists($chrome)) {
    fwrite(STDERR, "Chrome not found at $chrome\n");
    exit(1);
}

$scale = 2; // retina; templates set width/height to the 1x size

foreach ($icons as $name => [$w, $h, $body]) {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" '
        . 'width="' . $w . '" height="' . $h . '">' . $body . '</svg>';

    file_put_contents($srcDir . '/' . $name . '.svg', $svg);

    // An HTML wrapper pins the drawing to the top-left at an exact size, so
    // the screenshot needs no cropping.
    $html = '<!doctype html><meta charset="utf-8">'
        . '<style>html,body{margin:0;padding:0;background:transparent;}'
        . 'svg{display:block;width:' . ($w * $scale) . 'px;height:' . ($h * $scale) . 'px;}</style>'
        . $svg;

    $tmp = $srcDir . '/.render.html';
    file_put_contents($tmp, $html);

    $png = $out . '/' . $name . '.png';

    $cmd = '"' . $chrome . '"'
        . ' --headless --disable-gpu --hide-scrollbars --force-device-scale-factor=1'
        . ' --default-background-color=00000000'
        . ' --window-size=' . ($w * $scale) . ',' . ($h * $scale)
        . ' --screenshot="' . $png . '"'
        . ' "file:///' . str_replace('\\', '/', $tmp) . '"';

    exec($cmd . ' 2>&1', $ignored, $status);

    if (!file_exists($png)) {
        fwrite(STDERR, "FAILED $name\n");
        continue;
    }

    $size = getimagesize($png);
    printf("%-14s %sx%s  %6d bytes\n", $name, $size[0], $size[1], filesize($png));
}

@unlink($srcDir . '/.render.html');

echo "\nWritten to public/images/email/\n";
