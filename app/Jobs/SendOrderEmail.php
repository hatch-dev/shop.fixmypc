<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderEmailLog;
use App\Models\Helper\MailFailure;
use App\Models\Helper\OrderMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Send one order email, off the request.
 *
 * Carries ids and strings, never an Eloquent model. SerializesModels would
 * store a model reference and re-resolve it on each attempt, which sounds
 * convenient and means the job silently dies if the row is deleted between
 * attempts. The order is loaded here, explicitly, and its absence handled.
 *
 * The recipient is captured at dispatch rather than re-derived on each
 * attempt, deliberately: an email raised for an order must go where it was
 * addressed when the event happened, not to whatever the account has been
 * edited to three retries later.
 */
class SendOrderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Four attempts, not the default one.
     *
     * Chosen against the backoff below: the last attempt lands roughly twenty
     * minutes after the first, which covers the failure this is actually for
     * -- a mail host that is briefly busy, restarting, or greylisting a first
     * contact -- without keeping a receipt in limbo for an hour.
     */
    public $tries = 4;

    /**
     * 1m, 5m, 15m. Increasing, because a provider that just refused for load
     * is least likely to accept immediately, and because hammering a host that
     * is rate-limiting you is how a temporary limit becomes a block.
     */
    public $backoff = [60, 300, 900];

    /*
     * There is deliberately no $retryUntil here.
     *
     * In Laravel 8 the two are not additive -- they are exclusive, and
     * retryUntil wins silently. Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts
     * returns early whenever retryUntil is set and still in the future, so
     * $tries is never consulted at all and the job keeps retrying until the
     * clock runs out. Declaring both reads like a belt and braces and is in
     * fact a way to have $tries mean nothing; verified by exhausting a job's
     * attempts against a refused connection and watching it be re-released at
     * attempt five.
     *
     * $tries is the better of the two to keep. The attempt count lives on the
     * jobs row, so it survives a worker being killed and restarted, which is
     * exactly the case retryUntil was there to guard against.
     */

    /** Give the transport time to answer before the worker gives up on it. */
    public $timeout = 120;

    public $orderId;
    public $event;
    public $recipient;
    public $name;
    public $lang;
    public $context;

    public function __construct($orderId, $event, $recipient, $name, $lang = null, array $context = [])
    {
        $this->orderId = $orderId;
        $this->event = $event;
        $this->recipient = $recipient;
        $this->name = $name;
        $this->lang = $lang;
        $this->context = $context;
    }

    /** Reads as the event it is in the worker output and in failed_jobs. */
    public function displayName()
    {
        return 'SendOrderEmail:' . $this->event;
    }

    public function handle()
    {
        $log = OrderEmailLog::where('order_id', $this->orderId)
            ->where('event', $this->event)
            ->first();

        /*
         * Already satisfied. A second worker picking up a duplicated job must
         * not send a second copy.
         */
        if ($log && $log->isSent()) {
            return;
        }

        $order = Order::find($this->orderId);

        if (is_null($order)) {
            $this->markFailed($log, 'Order no longer exists');
            return;
        }

        try {
            OrderMailer::send(
                $order,
                $this->event,
                $this->recipient,
                $this->name,
                $this->lang,
                $this->context
            );

            $this->markSent($log);

            Log::info('Order email sent', [
                'order_id' => $this->orderId,
                'event' => $this->event,
                'recipient' => MailFailure::maskAddress($this->recipient),
                'attempt' => $this->attempts(),
            ]);

        } catch (\Throwable $e) {

            $this->recordAttempt($log, $e);

            Log::warning('Order email attempt failed', [
                'order_id' => $this->orderId,
                'event' => $this->event,
                'recipient' => MailFailure::maskAddress($this->recipient),
                'attempt' => $this->attempts(),
                'classification' => MailFailure::classify($e),
                'message' => MailFailure::summarise($e),
            ]);

            if (!MailFailure::isTransient($e)) {
                /*
                 * A refusal that would be repeated identically. fail() sends
                 * the job straight to failed_jobs without consuming the
                 * remaining attempts -- the difference between one log line
                 * and four more rejections against the sending domain's
                 * reputation.
                 */
                $this->markFailed($log, MailFailure::summarise($e));
                $this->fail($e);

                return;
            }

            // Temporary. Re-throw so the worker applies the backoff above.
            throw $e;
        }
    }

    /**
     * The last attempt has been used, or fail() was called.
     *
     * Runs for both endings, so the log row is never left saying 'queued' for
     * a job that has stopped running.
     */
    public function failed(\Throwable $e)
    {
        $log = OrderEmailLog::where('order_id', $this->orderId)
            ->where('event', $this->event)
            ->first();

        $this->markFailed($log, MailFailure::summarise($e));

        Log::error('Order email permanently failed', [
            'order_id' => $this->orderId,
            'event' => $this->event,
            'recipient' => MailFailure::maskAddress($this->recipient),
            'attempts' => $this->attempts(),
            'message' => MailFailure::summarise($e),
        ]);
    }

    private function markSent($log)
    {
        if (!$log) {
            return;
        }

        DB::table('order_email_log')->where('id', $log->id)->update([
            'status' => OrderEmailLog::STATUS_SENT,
            'attempts' => $this->attempts(),
            'sent_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function markFailed($log, $reason)
    {
        if (!$log) {
            return;
        }

        DB::table('order_email_log')->where('id', $log->id)->update([
            'status' => OrderEmailLog::STATUS_FAILED,

            /*
             * GREATEST, not a plain write, because the count can only go up.
             *
             * When the worker retires a job for exceeding its attempts it does
             * so without calling handle(), and Laravel's CallQueuedHandler
             * reaches failed() on an instance it never called setJob() on --
             * so attempts() answers 1 whatever actually happened. Writing that
             * straight over the row would report a job that tried four times
             * as having tried once, which is the opposite of what someone
             * reading this row after an incident needs.
             */
            'attempts' => DB::raw('GREATEST(attempts, ' . (int)$this->attempts() . ')'),
            'last_error' => mb_substr((string)$reason, 0, 500),
            'failed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Keep the row current between attempts. Without this a job that has been
     * retrying for ten minutes looks identical to one that was queued and
     * never picked up, which is exactly the distinction the retry sweep needs.
     */
    private function recordAttempt($log, \Throwable $e)
    {
        if (!$log) {
            return;
        }

        DB::table('order_email_log')->where('id', $log->id)->update([
            'attempts' => $this->attempts(),
            'last_error' => mb_substr(MailFailure::summarise($e), 0, 500),
            'updated_at' => now(),
        ]);
    }
}
