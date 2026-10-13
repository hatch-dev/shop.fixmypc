<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the sign-in banner into a builder: placeholder colours, a freely
 * chosen text colour in place of the dark/light theme switch.
 */
class AddBuilderFieldsToAuthBannersTable extends Migration
{
    public function up()
    {
        Schema::table('auth_banners', function (Blueprint $table) {

            // Calls to action
            $table->tinyInteger('href_new_tab')->default(0)->after('href');

            // Artwork
            $table->string('bg_from')->nullable()->after('image');
            $table->string('bg_to')->nullable()->after('bg_from');

            // Any colour the admin likes, rather than the two-way theme switch.
            $table->string('text_color')->default('#FFFFFF')->after('bg_to');
            $table->dropColumn('theme');
        });
    }

    public function down()
    {
        Schema::table('auth_banners', function (Blueprint $table) {
            $table->string('theme')->default('dark')->after('image');
            $table->dropColumn([
                'href_new_tab',
                'bg_from',
                'bg_to',
                'text_color'
            ]);
        });
    }
}
