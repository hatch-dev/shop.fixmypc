<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRatingReviewsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    /*public function up()
    {
        Schema::create('rating_reviews', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id')->unsigned();
            $table->integer('order_id');
            $table->integer('rating');
            $table->text('review')->nullable();

            $table->foreign('product_id')
                ->references('id')
                ->on('products');

            $table->foreign('user_id')
                ->references('id')
                ->on('users');
        });
    } */
	
	public function up()
    {
        Schema::create('rating_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id')->nullable();
			$table->integer('order_id');
            $table->string('user_token')->nullable(); // For guest users
            
            // Review content
            $table->tinyInteger('rating')->unsigned(); // 1-5 stars
            $table->text('review')->nullable();
            $table->string('source')->default('shop'); // google, facebook, shop
            $table->string('name')->nullable(); // Customer name
            $table->boolean('is_verified')->default(false);
            
            // Admin tracking
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->boolean('approved')->default(false);
            $table->text('admin_notes')->nullable();
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
		});
    } 

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('rating_reviews');
    }
}
