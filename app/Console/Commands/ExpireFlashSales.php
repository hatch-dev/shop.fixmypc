<?php

namespace App\Console\Commands;

use App\Models\FlashSale;
use App\Models\Helper\FlashSaleWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Switch off flash sales whose window has closed.
 *
 * The storefront never needed this -- FlashSaleWindow already refuses a sale
 * outside its dates, so a lapsed sale stops discounting the moment it ends
 * whatever its status says. What it fixes is the admin list, where a sale
 * that finished hours ago still read "Public", so the switch on screen
 * disagreed with what the shop was actually doing.
 *
 * Only PUBLIC rows are touched. A sale the admin had already switched off is
 * left alone, and so is one that has not started yet -- it has an end_time
 * in the future and is waiting its turn, not finished.
 */
class ExpireFlashSales extends Command
{
    protected $signature = 'flash-sale:expire';

    protected $description = 'Switch off flash sales whose end time has passed';

    public function handle()
    {
        $expired = self::sweep();

        $this->info($expired === 0
            ? 'No flash sales have lapsed.'
            : "Switched off {$expired} lapsed flash sale(s).");

        return 0;
    }

    /**
     * The sweep itself, so the admin list can run it too.
     *
     * One UPDATE that matches nothing almost every time it runs. It compares
     * against FlashSaleWindow::now() rather than the database's NOW(), because
     * MySQL's session timezone and the app's are not the same here and every
     * other comparison against these columns is made in the app's.
     *
     * @return int how many rows were switched off
     */
    public static function sweep()
    {
        $expired = FlashSale::where('status', Config::get('constants.status.PUBLIC'))
            ->where('end_time', '<', FlashSaleWindow::now())
            ->update(['status' => Config::get('constants.status.PRIVATE')]);

        if ($expired > 0) {
            Log::info('Flash sales expired', ['count' => $expired]);
        }

        return $expired;
    }
}
