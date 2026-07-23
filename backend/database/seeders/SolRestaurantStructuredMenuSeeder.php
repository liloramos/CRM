<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SolRestaurantStructuredMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SolRestaurantOfficialMenuSeeder::class,
            SolRestaurantProductRuleSeeder::class,
            SolRestaurantWeeklyMenuSeeder::class,
        ]);
    }
}
