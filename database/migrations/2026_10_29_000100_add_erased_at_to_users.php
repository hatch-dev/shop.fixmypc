<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When staff deleted a customer who had orders (App\Models\Helper\AccountErasure).
 *
 * Their orders are kept for the accounts, and orders.user_id cannot point at
 * nothing, so the users row stays with the person scrubbed out of it. This
 * marks it, so the customer list can leave it out.
 */
class AddErasedAtToUsers extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'erased_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('erased_at')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('users', 'erased_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('erased_at');
            });
        }
    }
}
