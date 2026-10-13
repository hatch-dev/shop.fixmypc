<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSourceToSubscriptionEmails extends Migration
{
    /**
     * Run the migrations.
     *
     * Where a subscriber signed up.
     *
     * The clearance page's "Get notified" block promises "we will only email
     * you about clearance deals", and then posted to the same endpoint as the
     * footer's monthly newsletter, into a table that held nothing but an email
     * address. There was no way to tell the two apart afterwards, so the
     * promise could not be kept even in principle, and nobody could see which
     * surface was actually bringing sign-ups in.
     *
     * Nullable with no default: the rows already in the table came from
     * somewhere this column cannot honestly name, and guessing would be worse
     * than leaving them blank.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('subscription_emails', 'source')) {
            return;
        }

        Schema::table('subscription_emails', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('email')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('subscription_emails', 'source')) {
            return;
        }

        Schema::table('subscription_emails', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
}
