<?php

namespace App\Console\Commands;

use App\Models\Helper\BasketUpkeep;
use App\Models\Helper\CartHold;
use App\Models\Helper\OrderEmailDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Check the things that decide whether a correct email reaches the inbox.
 *
 * The application can be flawless and the mail still land in spam, because
 * most of what a receiving filter judges is not in the codebase at all: it is
 * three DNS records and one environment variable. Those are also the parts
 * that silently drift -- a domain is moved, a provider is switched, an SPF
 * record is rewritten by whoever last set up Outlook -- and nothing in the app
 * notices, because sending still "works" right up until a mailbox provider
 * decides it does not.
 *
 * Read-only. Run it after a deploy and after any DNS change.
 */
class CheckMailDeliverability extends Command
{
    protected $signature = 'mail:check';

    protected $description = 'Check queue, DNS and sender configuration for order email delivery';

    /** Set by any failing check, and returned as the exit code for CI. */
    private $problems = 0;

    public function handle()
    {
        $this->line('');
        $this->checkQueue();
        $this->checkBasketReminders();
        $this->checkSender();
        $this->checkDns();
        $this->line('');

        if ($this->problems) {
            $this->error("{$this->problems} problem(s) found.");
            return 1;
        }

        $this->info('No problems found.');

        return 0;
    }

    // ------------------------------------------------------------- the queue

    private function checkQueue()
    {
        $this->line('<comment>Queue</comment>');

        $connection = config('queue.default');

        if ($connection === 'sync') {
            $this->bad('QUEUE_CONNECTION is "sync"',
                'there is no queue: order mail is sent inside the request and a '
                    . 'refused connection loses the email outright');
        } else {
            $this->good("connection: {$connection}");
        }

        foreach (['jobs', 'failed_jobs', 'order_email_log'] as $table) {
            if (Schema::hasTable($table)) {
                $this->good("table {$table} exists");
            } else {
                $this->bad("table {$table} is missing", 'run the migrations');
            }
        }

        $this->good('order queue: ' . OrderEmailDispatcher::queueName()
            . ' (a worker must be running against this name)');

        /*
         * Whether a worker is actually draining it. The tables existing says
         * nothing about that: with no cron running `schedule:run`, every order
         * email sits as "queued" for ever and this check used to report all is
         * well (found 2026-10-14 - three weeks of receipts never sent locally).
         * The worker runs every minute, so ten minutes is already a stall.
         */
        if (Schema::hasTable('order_email_log')) {
            $stuck = \DB::table('order_email_log')
                ->where('status', 'queued')
                ->where('created_at', '<', now()->subMinutes(10));
            $count = (clone $stuck)->count();

            if ($count > 0) {
                $this->bad("{$count} order email(s) queued for over 10 minutes, oldest "
                    . (clone $stuck)->min('created_at'),
                    'no worker is draining the queue: add the cron entry '
                    . '"* * * * * php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1"');
            } else {
                $this->good('no order email waiting longer than 10 minutes');
            }
        }
    }

    // ------------------------------------------------------- basket reminders

    /**
     * Basket reminder emails are sent by the basket sweep and nothing else.
     * The scheduler runs it every minute - if cron runs `schedule:run`. Without
     * cron it only runs when someone happens to use the site, so a shopper who
     * adds an item and leaves is never reminded, and their basket is emptied
     * on the next visit instead (found on live 2026-10-15).
     */
    private function checkBasketReminders()
    {
        $this->line('<comment>Basket reminders</comment>');

        if (!Schema::hasTable('cart_holds')) {
            $this->bad('table cart_holds is missing', 'run the migrations');
            return;
        }

        $remind = CartHold::remindAfterMinutes();
        $expire = CartHold::expireAfterMinutes();
        $this->good("timings: reminder after {$remind} min, basket emptied after {$expire} min");

        $cron = 'add the cron entry "* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1"';
        $now = Carbon::parse(date('Y-m-d H:i:s'));

        $scheduled = Cache::get(BasketUpkeep::LAST_SCHEDULED_SWEEP_KEY);
        if (!$scheduled) {
            $this->bad('the scheduler has never run the basket sweep',
                'reminders only go out while someone is using the site: ' . $cron);
        } else if (Carbon::parse($scheduled)->diffInMinutes($now) > 3) {
            $this->bad("the scheduler last ran the basket sweep at {$scheduled}",
                'cron has stopped: check the entry in cPanel > Cron Jobs (' . $cron . ')');
        } else {
            $this->good("scheduler ran the basket sweep at {$scheduled}");
        }

        $last = Cache::get(BasketUpkeep::LAST_SWEEP_KEY);
        if ($last) {
            $this->good("last sweep: {$last['at']} (started by {$last['source']})");
        }

        // Baskets that should have had their reminder by now and have not.
        $overdue = \DB::table('cart_holds')
            ->whereNull('reminded_at')
            ->where('last_activity_at', '<', $now->copy()->subMinutes($remind + 2)->format('Y-m-d H:i:s'))
            ->where(function ($query) use ($now) {
                $query->whereNull('paying_until')->orWhere('paying_until', '<', $now->format('Y-m-d H:i:s'));
            });
        $count = (clone $overdue)->count();

        if ($count > 0) {
            $this->bad("{$count} basket(s) past their reminder time without a reminder, oldest added "
                . (clone $overdue)->min('last_activity_at'),
                'the sweep is not running often enough: ' . $cron);
        } else {
            $this->good('no basket waiting for an overdue reminder');
        }

        $this->line('      sent and skipped reminders are logged in storage/logs as '
            . '"Basket reminder sent" / "Basket reminder skipped" / "Basket reminder email failed"');
    }

    // ------------------------------------------------------------ the sender

    private function checkSender()
    {
        $this->line('');
        $this->line('<comment>Sender</comment>');

        $from = config('mail.from.address');

        if (!$from || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $this->bad('MAIL_FROM_ADDRESS is not a valid address', 'nothing will send');
            return;
        }

        $this->good("from: {$from}");

        $name = config('mail.from.name');

        /*
         * An all-caps From name is the most common reason a technically
         * correct email reads as bulk mail to a human, and MAIL_FROM_NAME
         * defaults to APP_NAME, which is usually shouted.
         */
        if ($name && $name === mb_strtoupper($name) && mb_strlen($name) > 3) {
            $this->warn('  ~ from name "' . $name . '" is all capitals; '
                . 'a store name reads better than a shouted env value');
        }
    }

    // --------------------------------------------------------------- the DNS

    private function checkDns()
    {
        $this->line('');
        $this->line('<comment>DNS</comment>');

        $from = config('mail.from.address');
        $domain = $from ? substr(strrchr($from, '@'), 1) : null;

        if (!$domain) {
            $this->bad('no sending domain to check', 'MAIL_FROM_ADDRESS is unset');
            return;
        }

        if (!function_exists('dns_get_record')) {
            $this->warn('  ~ dns_get_record() unavailable; DNS not checked');
            return;
        }

        $this->checkSpf($domain);
        $this->checkDmarc($domain);
        $this->checkDkim($domain);
    }

    private function checkSpf($domain)
    {
        $spf = null;

        foreach (@dns_get_record($domain, DNS_TXT) ?: [] as $record) {
            $text = isset($record['txt']) ? $record['txt'] : '';

            if (stripos($text, 'v=spf1') === 0) {
                $spf = $text;
            }
        }

        if (!$spf) {
            $this->bad("no SPF record on {$domain}",
                'receiving filters have nothing to check the sending host against');
            return;
        }

        /*
         * The check that matters: does SPF actually authorise the host the
         * application sends through? An SPF record that is present, valid and
         * does not name your own mail provider is worse than none -- it ends
         * in -all, so it tells the world to reject your own mail.
         */
        /*
         * The provider is identified from everything that names it, not from
         * the mailer's host alone.
         *
         * An API transport -- which is what this install uses -- has no 'host'
         * key at all, so reading only that resolved to an empty string, matched
         * no provider, and reported "SPF present" for a record that did not
         * authorise the sending service. A check that passes when it has not
         * checked anything is worse than no check.
         */
        $mailer = config('mail.default');

        $signature = implode(' ', array_filter([
            $mailer,
            (string)config('mail.mailers.' . $mailer . '.transport'),
            (string)config('mail.mailers.' . $mailer . '.host'),

            /*
             * MAIL_HOST via the smtp mailer rather than env() directly: env()
             * returns null once config is cached, which would quietly turn this
             * check back into the one that passed without checking.
             */
            (string)config('mail.mailers.smtp.host'),
        ]));

        $host = trim($signature);
        $expected = $this->includeFor($signature);

        if (!$expected) {
            $this->warn('  ~ could not tell which service sends this mail ("'
                . $host . '"), so SPF was not checked against it');
        }

        if ($expected && stripos($spf, $expected) === false) {

            $this->bad("SPF on {$domain} does not authorise {$host}",
                'add "' . $expected . '" before the final "all" mechanism');

            $this->line("      current: {$spf}");

        } else {
            $this->good('SPF present' . ($expected ? " and includes {$expected}" : ''));
        }

        // Ten DNS lookups is the RFC 7208 ceiling; over it, SPF permerrors.
        $lookups = preg_match_all('/(include:|exists:|redirect=|\bptr\b|\bmx\b|\ba\b)/i', $spf);

        if ($lookups >= 8) {
            $this->warn("  ~ SPF uses about {$lookups} DNS lookups; the limit is "
                . '10, over which SPF fails outright');
        }
    }

    /**
     * The include an ESP expects in SPF, keyed by the relay host in use.
     */
    private function includeFor($host)
    {
        $known = [
            'brevo.com' => 'include:spf.brevo.com',
            'sendinblue.com' => 'include:spf.sendinblue.com',
            'sendgrid.net' => 'include:sendgrid.net',
            'mailgun.org' => 'include:mailgun.org',
            'postmarkapp.com' => 'include:spf.mtasv.net',
            'amazonses.com' => 'include:amazonses.com',
            'gmail.com' => 'include:_spf.google.com',
        ];

        foreach ($known as $needle => $include) {
            if (stripos($host, $needle) !== false) {
                return $include;
            }
        }

        return null;
    }

    private function checkDmarc($domain)
    {
        $dmarc = null;

        foreach (@dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [] as $record) {
            $text = isset($record['txt']) ? $record['txt'] : '';

            if (stripos($text, 'v=DMARC1') === 0) {
                $dmarc = $text;
            }
        }

        if (!$dmarc) {
            $this->bad("no DMARC record on {$domain}",
                'Gmail and Yahoo have required one of bulk senders since Feb 2024');
            return;
        }

        $this->good('DMARC present');

        if (preg_match('/p=\s*none/i', $dmarc)) {
            $this->warn('  ~ DMARC policy is p=none, which asks receivers to enforce '
                . 'nothing; move to p=quarantine once the aggregate reports are clean');
        }
    }

    /**
     * DKIM is checked by selector, and the selector is chosen by the provider,
     * so this can only look for the common ones. An absent result here is
     * inconclusive rather than a failure, which is why it warns.
     */
    private function checkDkim($domain)
    {
        $selectors = ['mail', 'brevo', 'brevo1', 'brevo2', 'default', 'k1', 's1', 'google'];

        foreach ($selectors as $selector) {

            $records = @dns_get_record($selector . '._domainkey.' . $domain, DNS_TXT) ?: [];

            foreach ($records as $record) {
                $text = isset($record['txt']) ? $record['txt'] : '';

                if (stripos($text, 'p=') !== false) {
                    $this->good('DKIM present on selector "' . $selector . '"');
                    return;
                }
            }
        }

        $this->warn('  ~ no DKIM record found on the selectors this knows about; '
            . 'confirm the selector your provider signs with');
    }

    // ------------------------------------------------------------- reporting

    private function good($message)
    {
        $this->line("  <info>ok</info>  {$message}");
    }

    private function bad($message, $why)
    {
        $this->problems++;
        $this->line("  <fg=red>!!</>  {$message}");
        $this->line("      {$why}");
    }
}
