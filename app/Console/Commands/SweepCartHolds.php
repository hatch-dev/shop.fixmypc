<?php

namespace App\Console\Commands;

use App\Models\Helper\BasketUpkeep;
use Illuminate\Console\Command;

/**
 * Basket reservations: remind at 5 minutes, empty at 20, release anything a
 * deleted line left held (App\Models\Helper\CartHold). Also clears abandoned
 * Buy Now lines and guest lines that never had a reservation
 * (App\Models\Helper\BasketUpkeep).
 *
 * Scheduled every minute. The cPanel host's cron may not be ticking, so the
 * same sweep also runs from API traffic (middleware cart.hold:sweep), at most
 * once a minute - the pattern the flash-sale and category jobs use.
 */
class SweepCartHolds extends Command
{
    protected $signature = 'cart:sweep-holds {--all : Run the hourly guest-basket pass now as well}';

    protected $description = 'Send basket reservation reminders and release expired reservations';

    public function handle()
    {
        $done = BasketUpkeep::sweep((bool)$this->option('all'));

        $this->info(sprintf('Reminded %d, expired %d, reconciled %d, cleared %d Buy Now line(s) and %d unreserved guest line(s).',
            $done['reminded'], $done['expired'], $done['reconciled'],
            $done['buy_now_expired'], $done['guest_lines_expired']));

        return 0;
    }
}
