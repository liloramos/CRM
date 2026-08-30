<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class SolRestaurantOperatingHoursSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();

        foreach (range(0, 6) as $weekday) {
            $open = $weekday !== 0;
            $company->operatingHours()->updateOrCreate(
                ['weekday' => $weekday],
                [
                    'is_open' => $open,
                    'opens_at' => $open ? '10:30' : null,
                    'closes_at' => $open ? '14:00' : null,
                    'notes' => $open
                        ? 'Horário oficial informado pelo responsável do Restaurante Sol.'
                        : 'Domingo fechado conforme horário oficial do Restaurante Sol.',
                ],
            );
        }
    }
}
