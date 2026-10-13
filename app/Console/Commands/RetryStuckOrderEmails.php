<?php

namespace App\Console\Commands;

use App\Jobs\SendOrderEmail;
use App\Models\Order;
use App\Models\OrderEmailLog;
use App\Models\Helper\OrderEmailDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-queue order emails that were claimed but never sent.
 *
 * The retries inside SendOrderEmail cover a mail server that refuses; they do
 * not cover the queue itself going missing. A worker killed mid-job, a cron
 * that stopped running, a `jobs` row lost to a database restore, a deploy that
 * cleared the table -- in every one of those the log row is left saying
 * 'queued' and there is no job anywhere that will ever move it.
 *
 * This is the sweep that closes that gap, and it is deliberately the only
 * thing in the system that reopens a claim. It touches 'queued' rows only:
 *
 *   - 'sent' is finished.
 *   - 'skipped' had no address to write to; another attempt finds the same
 *     absence.
 *   - 'failed' was classified as a permanent refusal. Re-queueing those on a
 *     schedule is precisely the "retry indefinitely" behaviour the
 *     classification exists to prevent, and it is what gets a sending domain
 *     blocked. Those are replayed by hand, with --include-failed, once
 *     whatever was wrong has been fixed.
 */
class RetryStuckOrderEmails extends Command
{
    protected $signature = 'orders:retry-emails
                            {--minutes=30 : How long a row must have been queued to count as stuck}
                            {--limit=100 : Most rows to re-queue in one run}
                            {--include-failed : Also replay permanently failed rows (manual recovery)}';

    protected $description = 'Re-queue order emails that were claimed but never sent';

    public function handle()
    {
        $minutes = (int)$this->option('minutes');
        $limit = (int)$this->option('limit');

        $query = OrderEmailLog::query();

        if ($this->option('include-failed')) {

            $query->whereIn('status', [
                OrderEmailLog::STATUS_QUEUED,
                OrderEmailLog::STATUS_FAILED,
            ])->where('updated_at', '<', now()->subMinutes($minutes));

        } else {
            $query->stuck($minutes);
        }

        $rows = $query->orderBy('updated_at')->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing stuck.');
            return 0;
        }

        $requeued = 0;

        foreach ($rows as $row) {

            $order = Order::find($row->order_id);

            if (is_null($order)) {
                // The order is gone; the email has nothing left to describe.
                $this->markAbandoned($row, 'Order no longer exists');
                continue;
            }

            /*
             * Resolved fresh rather than reused from the row. A stuck email is
             * often stuck precisely because the address was wrong, and the
             * customer has since corrected it -- re-sending to the address
             * that failed would repeat the failure on purpose.
             */
            $contact = OrderEmailDispatcher::recipient($order);

            if (!$contact['email']) {
                $this->markAbandoned($row, 'No usable recipient address on the order');
                continue;
            }

            DB::table('order_email_log')->where('id', $row->id)->update([
                'status' => OrderEmailLog::STATUS_QUEUED,
                'recipient' => $contact['email'],
                'updated_at' => now(),
            ]);

            /*
             * Replayed without the language or the event context, because the
             * log row does not store either.
             *
             * The consequence is small and worth naming: a refund replayed
             * this way arrives in the default language and without the
             * "Refunded / £x" row, since that figure came from the context the
             * original dispatch carried. The message is still correct, just
             * less specific -- which is the right trade against widening the
             * table to store a serialised payload that would then have to be
             * kept in step with every event's shape.
             */
            SendOrderEmail::dispatch(
                $order->id,
                $row->event,
                $contact['email'],
                $contact['name'],
                null,
                []
            )->onQueue(OrderEmailDispatcher::queueName());

            $requeued++;

            Log::info('Stuck order email re-queued', [
                'order_id' => $order->id,
                'event' => $row->event,
                'previous_status' => $row->status,
                'attempts' => $row->attempts,
            ]);
        }

        $this->info("Re-queued {$requeued} order email(s).");

        return 0;
    }

    private function markAbandoned(OrderEmailLog $row, $reason)
    {
        DB::table('order_email_log')->where('id', $row->id)->update([
            'status' => OrderEmailLog::STATUS_SKIPPED,
            'last_error' => $reason,
            'failed_at' => now(),
            'updated_at' => now(),
        ]);

        $this->warn("Order {$row->order_id} / {$row->event}: {$reason}");
    }
}
