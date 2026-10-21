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
        // A translated column reads as the original when no translation exists.
        \Illuminate\Database\Connection::resolverFor('mysql', function ($pdo, $database, $prefix, $config) {
            return new \App\Database\TranslationFallbackMySqlConnection($pdo, $database, $prefix, $config);
        });
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

        /*
         * AfterShip settings saved in the admin replace the .env values.
         * Not while config is being cached or published: that would write the
         * decrypted secrets into bootstrap/cache/config.php.
         */
        $command = $this->app->runningInConsole() ? ($_SERVER['argv'][1] ?? '') : '';
        if (!in_array($command, ['config:cache', 'optimize', 'config:show'], true)) {
            \App\Models\Helper\AfterShipSettings::apply();
        }
    }
}
