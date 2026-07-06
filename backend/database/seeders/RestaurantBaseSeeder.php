<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class RestaurantBaseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'restaurante-sol'],
            ['name' => 'Restaurante Sol'],
        );

        $company->restaurantProfile()->updateOrCreate(
            [],
            [
                'display_name' => $company->name,
                'country_code' => 'BR',
            ],
        );

        $company->setting()->updateOrCreate(
            [],
            [
                'status' => 'active',
                'timezone' => 'America/Sao_Paulo',
                'locale' => 'pt_BR',
                'currency' => 'BRL',
                'default_attendance_mode' => 'manual',
                'settings' => [
                    'tenant_type' => 'restaurant',
                    'data_source' => 'safe_seed',
                ],
            ],
        );

        foreach (range(0, 6) as $weekday) {
            $company->operatingHours()->updateOrCreate(
                ['weekday' => $weekday],
                [
                    'is_open' => false,
                    'opens_at' => null,
                    'closes_at' => null,
                    'notes' => 'Configure os horarios reais no painel operacional.',
                ],
            );
        }
    }
}
