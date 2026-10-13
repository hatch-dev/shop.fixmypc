<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserBusinessProfilesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_business_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();

            // Shared by business and trade.
            $table->string('company_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('cro_number')->nullable();
            $table->text('billing_address')->nullable();

            // Business only.
            $table->string('sector')->nullable();
            $table->boolean('requires_po')->default(false);
            $table->boolean('credit_application')->default(false);

            // Trade only.
            $table->string('trade')->nullable();
            $table->string('expected_spend')->nullable();
            $table->string('website')->nullable();
            $table->boolean('resale_confirmed')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_business_profiles');
    }
}
