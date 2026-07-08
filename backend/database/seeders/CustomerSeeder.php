<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'restaurante-sol'],
            ['name' => 'Restaurante Sol'],
        );

        Customer::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'email' => 'cliente.exemplo@example.test',
            ],
            [
                'name' => 'Cliente Exemplo',
                'phone' => null,
                'notes' => 'Cliente ficticio para seed local.',
            ],
        );
    }
}
