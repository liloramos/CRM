<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SolRestaurantOfficialMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SolRestaurantMenuCategorySeeder::class,
            SolRestaurantProductCatalogSeeder::class,
            SolRestaurantMenuComponentSeeder::class,
        ]);
    }
}
