<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which country the supplier's phone number belongs to, as an ISO 3166-1
 * alpha-2 code.
 *
 * The form stored only a dialling code, which is not enough to say what a
 * valid number looks like: +1 is the US, Canada and twenty Caribbean
 * countries, +7 is Russia and Kazakhstan, +44 is the UK plus Jersey, Guernsey
 * and the Isle of Man. Without the country there is no way to check the
 * number against the country the picker is showing a flag for, and no way to
 * show the right flag back when the record is reopened.
 *
 * country_code stays and stays authoritative for dialling -- everything that
 * sends a message builds `country_code . phone` -- so nothing downstream
 * changes. This is the extra fact, not a replacement.
 */
class AddCountryToSuppliers extends Migration
{
    public function up()
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('email');
        });

        /*
         * Backfill where the dialling code names exactly one country. Rows on
         * a shared code (+1, +44, +7 ...) are left null rather than guessed:
         * the form treats a null country as "not chosen yet" and asks, which
         * is honest, where guessing US for a Canadian supplier would not be.
         */
        $phones = json_decode(
            file_get_contents(base_path('storage/resources/phones.json')),
            true
        ) ?: [];

        $byCode = [];

        foreach ($phones as $iso => $dial) {
            $byCode[$dial][] = $iso;
        }

        foreach ($byCode as $dial => $isos) {
            if (count($isos) !== 1) {
                continue;
            }

            DB::table('suppliers')
                ->where('country_code', $dial)
                ->whereNull('country')
                ->update(['country' => $isos[0]]);
        }
    }

    public function down()
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
}
