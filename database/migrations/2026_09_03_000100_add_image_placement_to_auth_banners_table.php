<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the artwork be placed rather than just dropped in as a cover crop:
 * image_x / image_y are the focal point the admin sets by dragging the image
 * in the preview, image_w / image_h stretch the frame the artwork fills.
 */
class AddImagePlacementToAuthBannersTable extends Migration
{
    public function up()
    {
        Schema::table('auth_banners', function (Blueprint $table) {
            $table->unsignedSmallInteger('image_x')->default(50)->after('image');
            $table->unsignedSmallInteger('image_y')->default(50)->after('image_x');
            $table->unsignedSmallInteger('image_w')->default(100)->after('image_y');
            $table->unsignedSmallInteger('image_h')->default(100)->after('image_w');
        });
    }

    public function down()
    {
        Schema::table('auth_banners', function (Blueprint $table) {
            $table->dropColumn(['image_x', 'image_y', 'image_w', 'image_h']);
        });
    }
}
