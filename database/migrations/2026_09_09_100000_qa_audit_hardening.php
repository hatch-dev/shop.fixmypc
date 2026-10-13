<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns required by the QA audit fixes (reference/qa/qa-audit-2026-09-09.html).
 *
 *  - admins/users verification codes gain an expiry and an attempt counter so
 *    SEC-01 and ACC-01 can throttle and expire them instead of accepting a
 *    permanent 4-digit code.
 *  - cancellations record what was actually refunded (RFN-01), rather than a
 *    bare boolean that moves no money.
 *  - order_status_histories gives the status column the audit trail it never
 *    had, and lets updateStatus() record who changed what.
 */
class QaAuditHardening extends Migration
{
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->timestamp('code_expires_at')->nullable()->after('code');
            $table->unsignedSmallInteger('code_attempts')->default(0)->after('code_expires_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('code_expires_at')->nullable()->after('code');
            $table->unsignedSmallInteger('code_attempts')->default(0)->after('code_expires_at');
        });

        Schema::table('cancellations', function (Blueprint $table) {
            $table->decimal('refunded_amount', 12, 2)->nullable()->after('refunded');
            $table->timestamp('refunded_at')->nullable()->after('refunded_amount');
            $table->string('refund_reference')->nullable()->after('refunded_at');
            $table->text('refund_error')->nullable()->after('refund_reference');
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedInteger('from_status')->nullable();
            $table->unsignedInteger('to_status');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('changed_by_type', 32)->default('admin');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_status_histories');

        Schema::table('cancellations', function (Blueprint $table) {
            $table->dropColumn(['refunded_amount', 'refunded_at', 'refund_reference', 'refund_error']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['code_expires_at', 'code_attempts']);
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['code_expires_at', 'code_attempts']);
        });
    }
}
