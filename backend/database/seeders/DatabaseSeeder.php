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
            PrintingSeeder::class,
            WhatsAppSeeder::class,
            AiAutomationSeeder::class,
            MenuSeeder::class,
            RoleAndPermissionSeeder::class,
            UserAccessSeeder::class,
            CustomerSeeder::class,
            ConversationSeeder::class,
        ]);
    }
}
