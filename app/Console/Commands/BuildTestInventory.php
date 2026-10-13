<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Build the whole-app test checklist: every API route and every page, grouped
 * by module, with who may call it and which tests reach it.
 *
 * Writes two files, both generated - edit tests/coverage/modules.php or add
 * tests, then re-run:
 *
 *   tests/coverage/inventory.json  one record per route and page
 *   tests/COVERAGE.md              the readable checklist
 *
 * Coverage is found by reading test source for URL literals ('/api/v1/cart/action',
 * "/api/v1/product/{$id}", '/api/admin/order/find/' . $id). A test that builds
 * its URL entirely from variables is not seen, so the counts are a floor.
 * Being reached by a test is also not the same as being tested well: the
 * checklist says where nothing exists yet, not where enough does.
 */
class BuildTestInventory extends Command
{
    protected $signature = 'test:inventory
                            {--check : Exit non-zero if any route or page is unassigned (for CI)}
                            {--unmatched : List URLs written in tests that match no route}';

    protected $description = 'Regenerate the test coverage checklist (tests/COVERAGE.md) from the routes, pages and tests';

    const CONTROLLER_NS = 'App\\Http\\Controllers\\';

    const TIERS = [
        1 => 'Tier 1: money and access',
        2 => 'Tier 2: core operations',
        3 => 'Tier 3: content and system',
    ];

    /** @var array */
    private $map;

    public function handle()
    {
        $this->map = require base_path('tests/coverage/modules.php');
        $this->map['modules']['unassigned'] = ['Unassigned: add to tests/coverage/modules.php', 1];

        $calls = $this->scanTests();
        $routes = $this->routes($calls);

        if ($this->option('unmatched')) {
            $this->listUnmatched($calls);
        }
        $pages = array_merge(
            $this->pages('storefront', base_path('frontend-panel/pages')),
            $this->pages('admin', base_path('admin-panel/pages'))
        );

        $dir = base_path('tests/coverage');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents("$dir/inventory.json", json_encode([
            'routes' => $routes,
            'pages' => $pages,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents(base_path('tests/COVERAGE.md'), $this->markdown($routes, $pages));

        $this->summary($routes, $pages);

        $unassigned = collect($routes)->where('module', 'unassigned')->count()
            + collect($pages)->where('module', 'unassigned')->count();
        if ($unassigned) {
            $this->warn("$unassigned route(s)/page(s) are unassigned: add them to tests/coverage/modules.php.");
        }

        return $this->option('check') && $unassigned ? 1 : 0;
    }

    // ---------------------------------------------------------------- routes

    private function routes(array $calls): array
    {
        $out = [];

        /** @var Route $route */
        foreach (app('router')->getRoutes() as $route) {
            $handler = ltrim(Str::after($route->getActionName(), self::CONTROLLER_NS), '\\');
            $middleware = $route->gatherMiddleware();
            $uri = $route->uri();
            $access = $this->access($middleware);
            $regex = $this->uriRegex($uri);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $tests = ['isolated' => [], 'legacy' => []];
                foreach ($calls as $call) {
                    if (($call['method'] === null || $call['method'] === $method) && preg_match($regex, $call['path'])) {
                        $tests[$call['suite']][$call['file']] = true;
                    }
                }

                $module = $this->moduleFor($handler, $uri);
                $out[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'handler' => $handler,
                    'module' => $module,
                    'tier' => $this->map['modules'][$module][1],
                    'access' => $access,
                    'review' => $this->reviewReason($uri, $access, $handler),
                    'tests' => ['isolated' => array_keys($tests['isolated']), 'legacy' => array_keys($tests['legacy'])],
                ];
            }
        }

        usort($out, function ($a, $b) {
            return [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']];
        });

        return $out;
    }

    /** Who may call a route, read from its middleware. */
    private function access(array $middleware): string
    {
        $has = function ($prefix) use ($middleware) {
            foreach ($middleware as $m) {
                if (is_string($m) && Str::startsWith($m, $prefix)) {
                    return true;
                }
            }
            return false;
        };

        if ($has('installer')) return 'installer only';
        if ($has('auth:admin')) return 'admin';
        if ($has('auth:user')) return 'customer';
        if ($has('auth')) return 'signed in';
        if ($has('optionalAuth') || $has('guest.token')) return 'anyone (sign-in optional)';
        return 'anyone';
    }

    private function moduleFor(string $handler, string $uri): string
    {
        foreach ($this->map['uris'] ?? [] as $prefix => $module) {
            if (Str::startsWith($uri, $prefix)) {
                return $module;
            }
        }

        $controller = Str::before($handler, '@');
        $controllers = $this->map['controllers'];

        if (isset($controllers[$handler])) return $controllers[$handler];
        if (isset($controllers[$controller])) return $controllers[$controller];
        foreach ($controllers as $key => $module) {
            if (Str::startsWith($handler, $key)) {
                return $module; // namespace prefixes such as Laravel\Passport
            }
        }
        return 'unassigned';
    }

    /**
     * Routes whose access looks wrong for where they live. Not a verdict: the
     * controller may check on its own. Each one needs a test that pins it.
     */
    private function reviewReason(string $uri, string $access, string $handler): ?string
    {
        $intended = $this->map['public_by_design'] ?? [];
        if (in_array($uri, $intended, true)) {
            return null;
        }

        if (Str::startsWith($uri, 'api/admin') && $access !== 'admin') {
            return 'admin URL without admin auth';
        }
        if (Str::startsWith($uri, 'api/v1/user/') && !in_array($access, ['customer', 'signed in'], true)) {
            return 'customer-area URL without customer auth';
        }
        return null;
    }

    /** A route URI as a regex over a request path with no leading slash. */
    private function uriRegex(string $uri): string
    {
        $parts = preg_split('#(/?\{[^}]+\})#', $uri, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('#^(/?)\{[^}]+?(\?)?\}$#', $part, $m)) {
                $segment = $m[1] === '/' ? '/[^/]+' : '[^/]+';
                $regex .= !empty($m[2]) ? "(?:$segment)?" : $segment;
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return '#^' . $regex . '$#';
    }

    // ----------------------------------------------------------------- tests

    /**
     * Every API call written in test source: [suite, file, method|null, path].
     * A path segment built from a variable becomes "_", and a literal ending
     * in "/" is taken to be followed by one (the '/find/' . $id form).
     */
    private function scanTests(): array
    {
        $calls = [];
        $verbs = 'getJson|postJson|putJson|patchJson|deleteJson|get|post|put|patch|delete';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;
            $relative = str_replace('\\', '/', Str::after($file->getPathname(), base_path('tests') . DIRECTORY_SEPARATOR));
            if (Str::startsWith($relative, 'coverage/')) continue; // the module map names URL prefixes too
            $suite = Str::startsWith($relative, 'Isolated/') ? 'isolated' : 'legacy';
            $source = file_get_contents($file->getPathname());

            preg_match_all(
                '#(?:(?<verb>' . $verbs . ')\(\s*|json\(\s*[\'"](?<jverb>[A-Za-z]+)[\'"]\s*,\s*|call\(\s*[\'"](?<cverb>[A-Za-z]+)[\'"]\s*,\s*)?(?<q>[\'"])/?(?<path>api/[^\'"]*)\k<q>'
                . '(?:\s*\.\s*\$[A-Za-z_]\w*(?:->\w+|\[[^\]]*\])*\s*\.\s*(?<q2>[\'"])(?<tail>/[^\'"]*)\k<q2>)?#',
                $source, $matches, PREG_SET_ORDER
            );

            foreach ($matches as $m) {
                $verb = $m['verb'] ?: ($m['jverb'] ?? '') ?: ($m['cverb'] ?? '');
                $method = $verb === '' ? null : strtoupper(preg_replace('/Json$/', '', $verb));

                // '/quotes/' . $id . '/decide': the variable is one segment, the tail follows it.
                $path = $m['path'] . (!empty($m['tail']) ? '_' . $m['tail'] : '');
                $path = Str::before($path, '?');
                $path = preg_replace('#\{\$[^}]*\}|\$[A-Za-z_][A-Za-z0-9_]*(->[A-Za-z_]+|\[[^\]]*\])*#', '_', $path);
                if (Str::endsWith($path, '/')) {
                    $path .= '_';
                }

                $calls[] = ['suite' => $suite, 'file' => $relative, 'method' => $method, 'path' => $path];
            }
        }

        return $calls;
    }

    /**
     * Test URLs no route answers: a test calling a route that was removed or
     * renamed, or a URL form this scanner cannot read.
     */
    private function listUnmatched(array $calls)
    {
        $regexes = [];
        foreach (app('router')->getRoutes() as $route) {
            $regexes[] = [$this->uriRegex($route->uri()), $route->methods()];
        }

        $unmatched = [];
        foreach ($calls as $call) {
            $hit = false;
            foreach ($regexes as [$regex, $methods]) {
                if (($call['method'] === null || in_array($call['method'], $methods, true)) && preg_match($regex, $call['path'])) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $unmatched[($call['method'] ?? 'ANY') . ' ' . $call['path']][$call['file']] = true;
            }
        }

        ksort($unmatched);
        $this->line(count($unmatched) . ' test URL(s) match no route:');
        foreach ($unmatched as $url => $files) {
            $this->line("  $url  <- " . implode(', ', array_keys($files)));
        }
    }

    // ----------------------------------------------------------------- pages

    private function pages(string $panel, string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $prefixes = $this->map['pages'][$panel];
        uksort($prefixes, function ($a, $b) { return strlen($b) - strlen($a); });

        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'vue') continue;
            $relative = str_replace('\\', '/', Str::after($file->getPathname(), $root . DIRECTORY_SEPARATOR));
            $key = Str::beforeLast($relative, '.vue');

            $module = 'unassigned';
            foreach ($prefixes as $prefix => $candidate) {
                if ($prefix === '' || Str::startsWith($key, $prefix)) {
                    $module = $candidate;
                    break;
                }
            }

            $out[] = [
                'panel' => $panel,
                'file' => $relative,
                'path' => $this->nuxtPath($key),
                'module' => $module,
                'tier' => $this->map['modules'][$module][1],
            ];
        }

        usort($out, function ($a, $b) { return $a['file'] <=> $b['file']; });
        return $out;
    }

    /** Nuxt 2 file-based routing: index -> '', _id -> :id, _main/ is a layout group. */
    private function nuxtPath(string $key): string
    {
        $segments = [];
        foreach (explode('/', $key) as $segment) {
            if ($segment === 'index' || $segment === '_main') continue;
            $segments[] = Str::startsWith($segment, '_') ? ':' . substr($segment, 1) : $segment;
        }
        return '/' . implode('/', $segments);
    }

    // -------------------------------------------------------------- markdown

    private function status(array $route): string
    {
        if ($route['tests']['isolated']) return '✅';
        if ($route['tests']['legacy']) return '🟡';
        return '⬜';
    }

    private function markdown(array $routes, array $pages): string
    {
        $routes = collect($routes);
        $pages = collect($pages);
        $modules = collect($this->map['modules'])
            ->filter(function ($m, $key) use ($routes, $pages) {
                return $key !== 'unassigned' || $routes->where('module', $key)->isNotEmpty() || $pages->where('module', $key)->isNotEmpty();
            })
            ->sortBy(function ($m) { return $m[1]; });

        $count = function ($set) {
            return [
                $set->count(),
                $set->filter(function ($r) { return $this->status($r) === '✅'; })->count(),
                $set->filter(function ($r) { return $this->status($r) === '🟡'; })->count(),
                $set->filter(function ($r) { return $this->status($r) === '⬜'; })->count(),
            ];
        };

        $md = [];
        $md[] = '# Test coverage checklist';
        $md[] = '';
        $md[] = 'Generated by `php artisan test:inventory`. Do not edit by hand: change';
        $md[] = '`tests/coverage/modules.php` or add tests, then re-run it.';
        $md[] = '';
        $md[] = 'Legend: ✅ reached by the isolated suite · 🟡 reached only by a legacy test (dev database) · ⬜ no test found';
        $md[] = '';
        $md[] = 'A route counts as reached when a test\'s source names its URL. That is a floor (a URL built';
        $md[] = 'entirely from variables is not seen) and a low bar (reached is not the same as tested well).';
        $md[] = 'Pages have no browser tests yet; the page lists are the plan for the Playwright step.';
        $md[] = '';
        $md[] = '## Summary';
        $md[] = '';
        $md[] = '| Tier | Module | API routes | ✅ | 🟡 | ⬜ | Pages |';
        $md[] = '|---|---|---:|---:|---:|---:|---:|';
        foreach ($modules as $key => [$title, $tier]) {
            [$n, $ok, $legacy, $none] = $count($routes->where('module', $key));
            $md[] = "| $tier | [$title](#" . $this->anchor($title) . ") | $n | $ok | $legacy | $none | " . $pages->where('module', $key)->count() . ' |';
        }
        [$n, $ok, $legacy, $none] = $count($routes);
        $md[] = "| | **Total** | **$n** | **$ok** | **$legacy** | **$none** | **{$pages->count()}** |";
        $md[] = '';

        $review = $routes->whereNotNull('review');
        $md[] = '## Access review';
        $md[] = '';
        $md[] = 'Routes whose access looks wrong for where they live. The controller may still refuse the';
        $md[] = 'caller itself, so this is a list to confirm, not a list of bugs. Each one needs a test that pins';
        $md[] = 'who is refused. Mark an intended one in `public_by_design` in `tests/coverage/modules.php`.';
        $md[] = '';
        if ($review->isEmpty()) {
            $md[] = 'Nothing to review.';
        } else {
            $md[] = '| | Method | URI | Handler | Access | Why listed |';
            $md[] = '|---|---|---|---|---|---|';
            foreach ($review as $r) {
                $md[] = "| {$this->status($r)} | {$r['method']} | `{$r['uri']}` | {$r['handler']} | {$r['access']} | {$r['review']} |";
            }
        }
        $md[] = '';

        foreach (self::TIERS as $tier => $tierTitle) {
            $inTier = $modules->filter(function ($m) use ($tier) { return $m[1] === $tier; });
            if ($inTier->isEmpty()) continue;

            $md[] = "## $tierTitle";
            $md[] = '';
            foreach ($inTier as $key => [$title]) {
                $set = $routes->where('module', $key);
                [$n, $ok, $legacy, $none] = $count($set);
                $md[] = "### $title";
                $md[] = '';
                $md[] = "**API**: $n routes · ✅ $ok · 🟡 $legacy · ⬜ $none";
                $md[] = '';
                if ($set->isNotEmpty()) {
                    $md[] = '| | Method | URI | Handler | Access | Tests |';
                    $md[] = '|---|---|---|---|---|---|';
                    foreach ($set as $r) {
                        $files = array_merge($r['tests']['isolated'], $r['tests']['legacy']);
                        $names = array_map(function ($f) { return basename($f, '.php'); }, array_slice($files, 0, 3));
                        $more = count($files) > 3 ? ' +' . (count($files) - 3) : '';
                        $flag = $r['review'] ? ' ⚠️' : '';
                        $md[] = "| {$this->status($r)} | {$r['method']} | `{$r['uri']}` | {$r['handler']} | {$r['access']}$flag | " . implode(', ', $names) . "$more |";
                    }
                    $md[] = '';
                }

                $modulePages = $pages->where('module', $key);
                if ($modulePages->isNotEmpty()) {
                    $md[] = '**Pages**: ' . $modulePages->count();
                    $md[] = '';
                    foreach ($modulePages as $p) {
                        $md[] = "- [ ] {$p['panel']} `{$p['path']}` ({$p['file']})";
                    }
                    $md[] = '';
                }
            }
        }

        return implode("\n", $md) . "\n";
    }

    private function anchor(string $title): string
    {
        // GitHub's heading ids: lower case, punctuation dropped, each space a hyphen.
        return str_replace(' ', '-', preg_replace('/[^a-z0-9 \-]/', '', strtolower($title)));
    }

    private function summary(array $routes, array $pages)
    {
        $rows = [];
        foreach ($this->map['modules'] as $key => [$title, $tier]) {
            $set = collect($routes)->where('module', $key);
            $pageCount = collect($pages)->where('module', $key)->count();
            if ($set->isEmpty() && !$pageCount) continue;
            $rows[] = [
                $tier, $title, $set->count(),
                $set->filter(function ($r) { return $this->status($r) === '✅'; })->count(),
                $set->filter(function ($r) { return $this->status($r) === '🟡'; })->count(),
                $set->filter(function ($r) { return $this->status($r) === '⬜'; })->count(),
                $pageCount,
            ];
        }
        $this->table(['Tier', 'Module', 'Routes', 'Isolated', 'Legacy only', 'None', 'Pages'], $rows);
        $this->line('Access review: ' . collect($routes)->whereNotNull('review')->count() . ' route(s). Wrote tests/COVERAGE.md and tests/coverage/inventory.json.');
    }
}
