<?php

namespace Database\Seeders;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class FlashDiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('flash_discounts')->insert([
            [
                'type' => 'percentage',
                'value' => 15,
                'min_cart_value' => 100,
                'max_discount' => 200,
                'start_time' => Carbon::now()->subMinutes(10),
                'end_time' => Carbon::now()->addHours(3),
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }
}
