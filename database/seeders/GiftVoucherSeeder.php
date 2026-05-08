<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GiftVoucherSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $titles = [
            'Monetary Voucher',
            'Special Gift Card',
            'Holiday Voucher',
            'Festival Voucher',
            'Premium Gift Voucher',
            'Exclusive Voucher'
        ];

        $descriptions = [
            'Buy and gift to your loved ones.',
            'Perfect for any occasion.',
            'Use this voucher for shopping.',
            'Best gift option available.',
            'Enjoy shopping with this voucher.',
            'Limited time gift voucher.'
        ];

        for ($i = 0; $i < 6; $i++) {
            DB::table('gift_voucher')->insert([
                'title' => $titles[array_rand($titles)],
                'image' => 'vouchers/banner' . rand(1, 3) . '.jpg',
                'description' => $descriptions[array_rand($descriptions)],
                'amounts' => json_encode([
                    rand(10, 30),
                    rand(40, 70),
                    rand(80, 150)
                ]),
                'min_quantity' => 1,
                'max_quantity' => rand(3, 10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
