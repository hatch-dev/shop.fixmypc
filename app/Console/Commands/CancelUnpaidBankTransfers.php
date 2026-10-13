<?php

namespace App\Console\Commands;

use App\Models\Helper\OrderEmailDispatcher;
use App\Models\Helper\OrderEmailEvent;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cancel bank transfer orders whose money never arrived.
 *
 * The bank details page always promised this -- "if payment is not received
 * within 24 hours, your order will be automatically cancelled", with a
 * countdown -- and nothing did it, so unpaid orders sat on their stock until
 * someone noticed.
 *
 * Deliberately narrow. An order is only cancelled while it is:
 *   - bank transfer, unpaid, and still PENDING. Once staff have moved it on,
 *     they are handling it and it is theirs to cancel;
 *   - without a transfer reference. A customer who has told us "I paid, the
 *     reference is X" is waiting on us to check, not ignoring the order;
 *   - older than the hold window, measured on PHP's clock, never MySQL's
 *     NOW(), which runs hours apart from the app's here.
 *
 * A cancellation here is an ordinary one: stock, points and voucher come
 * back through restoreStockAndRewards(), the history says who did it, and the
 * customer gets the usual cancellation email with the reason on it.
 */
class CancelUnpaidBankTransfers extends Command
{
    protected $signature = 'orders:cancel-unpaid-transfers';

    protected $description = 'Cancel bank transfer orders not paid within the hold window';

    /** Enough to clear any backlog over a few passes without one slow request. */
    const BATCH = 25;

    public function handle()
    {
        $cancelled = self::sweep();

        $this->info($cancelled === 0
            ? 'No unpaid bank transfers are overdue.'
            : "Cancelled {$cancelled} unpaid bank transfer order(s).");

        return 0;
    }

    public static function holdHours()
    {
        return max(1, (int)Config::get('constants.bankTransferHoldHours', 24));
    }

    /** When an unpaid bank order placed at $placedAt is cancelled. */
    public static function deadlineFor($placedAt)
    {
        return $placedAt ? \Carbon\Carbon::parse($placedAt)->addHours(self::holdHours()) : null;
    }

    private static function overdue()
    {
        return Order::where('order_method', Config::get('constants.paymentMethod.BANK'))
            ->where('payment_done', 0)
            ->where('status', Config::get('constants.orderStatus.PENDING'))
            ->where(function ($q) {
                $q->whereNull('cancelled')->orWhere('cancelled', '!=', Config::get('constants.status.PUBLIC'));
            })
            ->where(function ($q) {
                $q->whereNull('trans_id')->orWhere('trans_id', '');
            })
            ->where('created_at', '<', now()->subHours(self::holdHours())->format('Y-m-d H:i:s'));
    }

    /**
     * The sweep itself, so the admin order list can run it too: there is no
     * supervisor on the cPanel host and cron may not be ticking.
     *
     * @return int how many orders were cancelled
     */
    public static function sweep()
    {
        $cancelledStatus = (int)Config::get('constants.orderStatus.CANCELLED');
        $done = 0;

        foreach (self::overdue()->orderBy('id')->limit(self::BATCH)->pluck('id') as $id) {

            try {
                $order = DB::transaction(function () use ($id, $cancelledStatus) {

                    // Re-read under a lock: an admin marking it paid, or the
                    // customer sending a reference, wins over the sweep.
                    $order = self::overdue()->where('id', $id)->lockForUpdate()->first();

                    if (!$order) {
                        return null;
                    }

                    $from = (int)$order->status;

                    Order::where('id', $order->id)->update(['status' => $cancelledStatus]);

                    $order->restoreStockAndRewards();
                    $order->refreshFlashAllowances();

                    OrderStatusHistory::create([
                        'order_id' => $order->id,
                        'from_status' => $from,
                        'to_status' => $cancelledStatus,
                        'changed_by' => null,
                        'changed_by_type' => 'system',
                        'note' => 'No bank transfer received within ' . self::holdHours() . ' hours',
                    ]);

                    return $order;
                });

            } catch (\Throwable $e) {
                Log::error('Unpaid bank transfer could not be cancelled', [
                    'order_id' => $id,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$order) {
                continue;
            }

            $done++;

            // Outside the transaction: a mail problem must not undo the cancel.
            try {
                OrderEmailDispatcher::dispatch($order->fresh(), OrderEmailEvent::ORDER_CANCELLED, null, [
                    'status' => $cancelledStatus,
                    'reason' => 'We did not receive your bank transfer within '
                        . self::holdHours() . ' hours of your order being placed.',
                ]);
            } catch (\Throwable $e) {
                Log::error('Cancellation email for unpaid bank transfer failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($done > 0) {
            Log::info('Unpaid bank transfers cancelled', ['count' => $done]);
        }

        return $done;
    }
}
