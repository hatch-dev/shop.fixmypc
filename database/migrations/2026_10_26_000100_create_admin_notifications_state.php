<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read state for the admin header's notification bell
 * (App\Models\Helper\AdminNotifications).
 *
 * The notifications themselves are not stored: each one is a row that already
 * exists (an order, a return request, a contact message ...), named by a
 * stable id such as "order:85". All this keeps is, per admin, which of those
 * ids were opened and when "Mark all as read" was last pressed -- so every
 * path that creates an order or a request is covered without having to remember
 * to write a notification from it.
 */
class CreateAdminNotificationsState extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('admin_notification_reads')) {
            Schema::create('admin_notification_reads', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('admin_id');
                $table->string('notification_id', 64);
                $table->timestamp('read_at')->nullable();
                $table->unique(['admin_id', 'notification_id'], 'admin_notification_reads_unique');
            });
        }

        if (!Schema::hasTable('admin_notification_states')) {
            Schema::create('admin_notification_states', function (Blueprint $table) {
                $table->unsignedBigInteger('admin_id')->primary();
                // Everything at or before this instant counts as read.
                $table->timestamp('cleared_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('admin_notification_reads');
        Schema::dropIfExists('admin_notification_states');
    }
}
