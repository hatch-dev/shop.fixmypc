<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Models\Helper\CartTrace;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Where the lines in an account's basket came from.
 *
 * Answers "this new account opened with products already in its cart" from
 * the cart-trace log (see App\Models\Helper\CartTrace): which guest basket was
 * handed over at sign-in, how old each line was, whether that basket held a
 * reservation, and which page action created each line in the first place.
 *
 * The log only knows what happened after tracing was switched on (2026-10-09).
 * For older lines the table still says one thing: a line created before its
 * account existed can only have arrived from a guest basket.
 */
class TraceCart extends Command
{
    protected $signature = 'cart:trace {who : Customer email or user id}';

    protected $description = 'Explain where the lines in a customer basket came from';

    public function handle()
    {
        $who = trim($this->argument('who'));

        $user = ctype_digit($who)
            ? User::find((int)$who)
            : User::where('email', $who)->first();

        if (!$user) {
            $this->error("No customer account for '{$who}'.");
            return 1;
        }

        $this->line('');
        $this->info("Account #{$user->id} {$user->email}, created {$user->created_at}");

        $entries = $this->readLog();
        $created = [];

        foreach ($entries as $entry) {
            if ($entry['event'] === 'line_created') {
                $created[$entry['context']['cart_id'] ?? 0] = $entry;
            }
        }

        $this->showBasket($user, $created);
        $this->showMerges($user, $entries, $created);

        return 0;
    }

    private function showBasket($user, array $created)
    {
        $lines = Cart::where('user_id', $user->id)->orderBy('id')->get();

        $this->line('');
        $this->comment('Basket now: ' . $lines->count() . ' line(s)');

        if ($lines->isEmpty()) {
            return;
        }

        $titles = Product::whereIn('id', $lines->pluck('product_id'))->pluck('title', 'id');
        $accountBorn = Carbon::parse($user->created_at);

        $rows = $lines->map(function ($line) use ($titles, $created, $accountBorn) {
            $origin = isset($created[$line->id])
                ? $created[$line->id]['context']['origin'] . ' (' . $this->owner($created[$line->id]['context']) . ')'
                : 'not in trace log';

            $before = $line->created_at && Carbon::parse($line->created_at)->lt($accountBorn);

            return [
                $line->id,
                mb_strimwidth((string)($titles[$line->product_id] ?? $line->product_id), 0, 40, '...'),
                $line->quantity,
                (string)$line->created_at,
                $before ? 'YES - came from a guest basket' : 'no',
                $origin,
            ];
        })->all();

        $this->table(['cart id', 'product', 'qty', 'added', 'older than account?', 'created by'], $rows);
    }

    private function showMerges($user, array $entries, array $created)
    {
        $merges = array_values(array_filter($entries, function ($entry) use ($user) {
            return $entry['event'] === 'guest_basket_merged'
                && (int)($entry['context']['user_id'] ?? 0) === (int)$user->id;
        }));

        $this->line('');
        $this->comment('Guest baskets handed to this account: ' . count($merges));

        foreach ($merges as $merge) {
            $c = $merge['context'];

            $this->line('');
            $this->line(sprintf(
                '  %s  via %s%s',
                $merge['at'], $c['endpoint'] ?? '?', !empty($c['new_account']) ? '  [at registration]' : ''
            ));
            $this->line(sprintf(
                '    guest token %s, issued %s (%s old)',
                $c['guest_token'] ?? '-', $c['guest_token_issued_at'] ?? '?', $c['guest_token_age'] ?? '?'
            ));
            $this->line('    ' . $this->basketLine($c));

            if (empty($c['lines'])) {
                $this->line('    nothing moved: the basket was empty');
                continue;
            }

            $rows = array_map(function ($line) use ($created) {
                $id = $line['cart_id'];

                return [
                    $id,
                    mb_strimwidth((string)($line['product'] ?? $line['product_id']), 0, 40, '...'),
                    $line['quantity'],
                    ($line['added_at'] ?? '?') . ' (' . ($line['age'] ?? '?') . ' old)',
                    isset($created[$id]) ? $created[$id]['context']['origin'] : 'before tracing',
                ];
            }, $c['lines']);

            $this->table(['cart id', 'product', 'qty', 'added to guest basket', 'created by'], $rows);
        }
    }

    /**
     * How old the guest basket was when it was handed over. Entries written
     * before 2026-10-09 carry the retired reservation state instead.
     */
    private function basketLine(array $c)
    {
        if (isset($c['guest_basket'])) {
            $b = $c['guest_basket'];

            if (empty($b['last_activity_at'])) {
                return 'guest basket: no activity recorded';
            }

            return sprintf('guest basket: %s, last active %s (%s before sign-in); would have expired %s',
                !empty($b['reserved']) ? 'reserved' : 'NOT reserved',
                $b['last_activity_at'], $b['idle'] ?? '?', $b['expires_at'] ?? '?');
        }

        $hold = $c['guest_reservation'] ?? null;

        if ($hold === null) {
            return 'guest basket: unknown';
        }

        return 'reservation (old system): ' . (!empty($hold['held'])
            ? 'held, idle ' . ($hold['idle'] ?? '?')
            : 'none - that basket never expired');
    }

    private function owner(array $context)
    {
        $owner = $context['owner'] ?? [];

        switch ($owner['type'] ?? null) {
            case 'guest':
                return 'as guest ' . ($owner['guest_token'] ?? '');
            case 'account':
                return 'signed in';
            case 'admin':
                return 'admin';
        }

        return '?';
    }

    /**
     * Every CartTrace entry, oldest first.
     *
     * The daily channel writes one file per day; each line is Monolog's
     * "[time] channel.LEVEL: message {context}" (an empty extra is omitted).
     */
    private function readLog()
    {
        // The daily driver adds -YYYY-MM-DD before the extension.
        $path = config('logging.channels.cart_trace.path', storage_path('logs/cart-trace.log'));
        $stem = substr($path, 0, -strlen('.' . pathinfo($path, PATHINFO_EXTENSION)));

        $files = glob($stem . '*.log') ?: [];
        sort($files);

        $entries = [];

        foreach ($files as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $raw) {
                if (!preg_match('/^\[([^\]]+)\]\s+\S+:\s+(line_created|guest_basket_merged)\s+(\{.*\})\s*(?:\[\])?\s*$/', $raw, $m)) {
                    continue;
                }

                $context = json_decode($m[3], true);

                if (is_array($context)) {
                    $entries[] = ['at' => $m[1], 'event' => $m[2], 'context' => $context];
                }
            }
        }

        return $entries;
    }
}
