<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queue Laravel has always expected and this install never had.
 *
 * QUEUE_CONNECTION was 'sync', which is not a queue: every Mail::send ran
 * inside the customer's request, so a slow SMTP handshake was added to the
 * checkout response, and a refused connection was the end of that email --
 * no retry, no record, nothing to replay.
 *
 * 'database' rather than redis/sqs deliberately. This deploys to cPanel over
 * SSH with no daemon supervisor and no Redis; a table plus a cron running
 * `queue:work --stop-when-empty` is the driver that actually survives that
 * environment.
 *
 * failed_jobs is the other half. A job that exhausts its retries lands here
 * with its payload and exception intact, which is what makes
 * `queue:retry` a real recovery path rather than a documented aspiration.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('failed_jobs');
    }
};
