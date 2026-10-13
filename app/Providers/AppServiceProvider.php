<?php

namespace App\Providers;

use Laravel\Passport\Console\ClientCommand;
use Laravel\Passport\Console\InstallCommand;
use Laravel\Passport\Console\KeysCommand;
use Laravel\Passport\Passport;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /*ADD THIS LINES*/
        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        /*
         * MailBrand memoises the store's name, logo, colours and contact
         * details for the life of the process. That is what we want inside
         * one request — an order email otherwise re-queries the same tables
         * for its header, body and footer — but a queue worker outlives many
         * jobs, and would keep sending the branding that was current when it
         * booted. Clear it at each job boundary.
         */
        \Illuminate\Support\Facades\Queue::before(function () {
            \App\Models\Helper\MailBrand::forget();
        });
    }
}
