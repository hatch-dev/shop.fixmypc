<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An Irish or SEPA transfer is addressed by IBAN and BIC. The bank page asked
 * for both and the table had neither, so the customer saw two empty boxes and
 * the IBAN only reached them because it had been typed into account_number.
 */
class AddIbanBicToPayments extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'iban')) {
                $table->string('iban', 64)->nullable()->after('account_number');
            }
            if (!Schema::hasColumn('payments', 'bic_swift')) {
                $table->string('bic_swift', 32)->nullable()->after('iban');
            }
        });

        // Carry over an IBAN already sitting in account_number: two letters,
        // two check digits, then the account. Anything else is left alone.
        foreach (DB::table('payments')->whereNull('iban')->get(['id', 'account_number']) as $row) {
            $compact = strtoupper(preg_replace('/\s+/', '', (string)$row->account_number));

            if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $compact)) {
                DB::table('payments')->where('id', $row->id)->update([
                    'iban' => trim(chunk_split($compact, 4, ' ')),
                ]);
            }
        }
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['iban', 'bic_swift']);
        });
    }
}
