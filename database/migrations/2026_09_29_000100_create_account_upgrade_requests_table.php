<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer moving their own account up a type (App\Models\Helper\AccountUpgrade).
 *
 * Personal -> business happens on the spot and is logged here as `completed`,
 * so every change of account_type has a record of when and from what.
 * Personal/business -> trade is an application: the row waits as `pending`
 * and account_type does not move until staff approve it. A business customer
 * therefore keeps business pricing while applying, and a rejection leaves them
 * exactly where they were.
 *
 * `details` holds what the customer submitted, as submitted. It is written
 * into user_business_profiles only on approval, so a rejected application
 * cannot overwrite company details staff have already verified.
 *
 * "One pending application per customer" is enforced by AccountUpgrade
 * locking the users row, not by an index: MySQL has no partial unique index.
 */
class CreateAccountUpgradeRequestsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('account_upgrade_requests')) {
            return;
        }

        Schema::create('account_upgrade_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('from_type', 20);
            $table->string('to_type', 20);
            // pending / completed / approved / rejected / withdrawn
            $table->string('status', 20);
            $table->longText('details')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('account_upgrade_requests');
    }
}
