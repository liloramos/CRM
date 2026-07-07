<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Customer::create([
            'company_id' => 1,
            'name' => 'Cliente Exemplo',
            'phone' => null,
            'email' => 'cliente.exemplo@example.test',
            'notes' => 'Cliente ficticio para seed local.',
        ]);
    }
}
