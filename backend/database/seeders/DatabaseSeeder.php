<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            RestaurantBaseSeeder::class,
            MenuSeeder::class,
            RoleAndPermissionSeeder::class,
            UserAccessSeeder::class,
            CustomerSeeder::class,
            ConversationSeeder::class,
        ]);
    }
}
