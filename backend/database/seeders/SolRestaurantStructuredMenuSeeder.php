<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SolRestaurantStructuredMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RestaurantBaseSeeder::class,
            SolRestaurantOfficialMenuSeeder::class,
            SolRestaurantProductRuleSeeder::class,
            SolRestaurantWeeklyMenuSeeder::class,
            SolRestaurantComboCompositionSeeder::class,
        ]);
    }
}
