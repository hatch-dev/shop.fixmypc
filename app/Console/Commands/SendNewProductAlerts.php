<?php

namespace App\Console\Commands;

use App\Models\Helper\NewProductAlerts;
use Illuminate\Console\Command;

/** Email members about products that have just gone on sale (Helper\NewProductAlerts). */
class SendNewProductAlerts extends Command
{
    protected $signature = 'membership:new-product-alerts';

    protected $description = 'Email members about new products that have just gone on sale';

    public function handle()
    {
        $result = NewProductAlerts::send();

        $this->info($result['products'] . ' new product(s), emailed to ' . $result['recipients'] . ' member(s).');

        return 0;
    }
}
