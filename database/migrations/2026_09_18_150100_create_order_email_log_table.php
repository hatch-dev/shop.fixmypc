<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (order, event). The idempotency key for order mail, and the
 * only record of what was actually sent.
 *
 * The unique index on (order_id, event) is the whole mechanism: the dispatcher
 * claims an event by inserting, and a duplicate trigger -- the browser
 * returning from a gateway while the webhook is mid-flight, an admin pressing
 * the status button twice, a retried request -- collides with the index and is
 * dropped. That is a guarantee the database makes; a "have we sent this?"
 * SELECT followed by an INSERT is not, because two requests can both read
 * "no".
 *
 * Deliberately NOT keyed on the recipient. The question is "has order 10025
 * been told it shipped", not "has this address been written to", and keying on
 * the address would send a second copy the moment a customer corrected their
 * email.
 *
 * orders.confirmation_email_sent_at stays where it is. It is the pre-existing
 * claim for ORDER_PLACED and is backfilled into this table below, so an order
 * that was confirmed before this migration ran does not get a second receipt.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('order_email_log')) {
            return;
        }

        Schema::create('order_email_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Matches orders.id exactly; MySQL refuses a foreign key whose
            // integer widths differ.
            $table->unsignedInteger('order_id');

            // The event key, e.g. 'order_placed'. Not an enum: adding a new
            // event should be a line of PHP, not a table alter on a live box.
            $table->string('event', 40);

            /*
             * queued  - claimed, handed to the queue, not yet accepted
             * sent    - the mailer accepted it
             * failed  - permanently; will not be retried automatically
             * skipped - nothing to send to (no address, or an unusable one)
             */
            $table->string('status', 12)->default('queued');

            /*
             * Stored so a failed send can be replayed, and so support can
             * answer "where did it go" without re-deriving the recipient from
             * an order that may have changed since. Never written to the log
             * file -- see OrderEmailDispatcher::redact().
             */
            $table->string('recipient')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);

            // Truncated before it is stored; a provider stack trace can run to
            // kilobytes and none of it after the first line helps.
            $table->string('last_error', 500)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'event'], 'order_email_log_order_event_unique');

            // "What is stuck?" -- the only query a retry sweep needs.
            $table->index(['status', 'updated_at']);

            $table->foreign('order_id')
                ->references('id')->on('orders')
                ->cascadeOnDelete();
        });

        /*
         * Backfill. Every order already stamped as confirmed is recorded as
         * having had its ORDER_PLACED email, so the first run of the new
         * dispatcher does not re-send a receipt for every historical order.
         */
        \Illuminate\Support\Facades\DB::statement("
            INSERT INTO order_email_log
                (order_id, event, status, attempts, sent_at, created_at, updated_at)
            SELECT id, 'order_placed', 'sent', 1,
                   confirmation_email_sent_at, confirmation_email_sent_at, confirmation_email_sent_at
            FROM orders
            WHERE confirmation_email_sent_at IS NOT NULL
        ");
    }

    public function down()
    {
        Schema::dropIfExists('order_email_log');
    }
};
