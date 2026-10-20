<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        /*
         * The mail worker.
         *
         * --stop-when-empty rather than a daemon: this deploys to cPanel over
         * SSH, where there is no supervisor to keep a long-running worker
         * alive and a process that dies at 3am stays dead. A worker that
         * drains the queue and exits is restarted by the next tick whatever
         * happened to the last one.
         *
         * withoutOverlapping so a slow send does not accumulate workers, and
         * runInBackground so a full queue does not hold up the rest of the
         * schedule. --tries=1 because SendOrderEmail declares its own retry
         * policy; a second ceiling here would silently override it.
         */
        $queue = \App\Models\Helper\OrderEmailDispatcher::queueName();

        $schedule->command("queue:work --queue={$queue} --stop-when-empty --tries=1 --max-time=55")
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        /*
         * The recovery sweep. Only catches what the queue itself lost -- see
         * RetryStuckOrderEmails, which is careful about what it will and will
         * not reopen.
         */
        $schedule->command('orders:retry-emails')
            ->everyThirtyMinutes()
            ->withoutOverlapping();

        /*
         * Switch off flash sales whose window has closed, so the admin
         * list stops showing a finished sale as Public. The storefront
         * already ignores them either way (FlashSaleWindow), so this is
         * about the two agreeing rather than about what is charged.
         *
         * FlashSalesController::all runs the same sweep when the list is
         * opened, so the state is right even where this schedule is not
         * ticking.
         */
        $schedule->command('flash-sale:expire')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        /*
         * Publish categories whose scheduled time has arrived. The field has
         * collected a date since it was added and nothing ever read it, so
         * "Schedule Publishing" did nothing at all.
         *
         * CategoriesController::all runs the same sweep when the list is
         * opened, so a schedule still comes due where this is not ticking --
         * the same arrangement flash-sale:expire has, and for the same
         * reason: there is no supervisor on the cPanel host.
         */
        $schedule->command('categories:publish-due')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        /*
         * Cancel bank transfer orders not paid within the hold window. Also
         * swept from the admin order list, for the same no-cron reason.
         */
        $schedule->command('orders:cancel-unpaid-transfers')
            ->everyFifteenMinutes()
            ->withoutOverlapping();

        /*
         * Basket reservations: reminder and emptying on the admin timings; plus
         * abandoned Buy Now lines (BasketUpkeep). Also swept from API traffic
         * (middleware cart.hold:sweep) for the no-cron case.
         */
        $schedule->command('cart:sweep-holds')
            ->everyMinute()
            ->withoutOverlapping();

        /*
         * Memberships: re-read open Stripe subscriptions (the net under the
         * webhook) and close granted ones past their end date.
         */
        $schedule->command('membership:sync')
            ->everySixHours()
            ->withoutOverlapping();

        /*
         * Members are emailed when new products go on sale, within a minute;
         * products published together go in one email.
         */
        $schedule->command('membership:new-product-alerts')
            ->everyMinute()
            ->withoutOverlapping();

        /*
         * Shipment statuses from AfterShip: the net under its webhook, and
         * the only update path until the webhook is configured.
         */
        $schedule->command('aftership:sync')
            ->hourly()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
