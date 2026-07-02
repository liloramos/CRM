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
            'name' => 'João Silva',
            'phone' => '62999999999',
            'email' => 'joao@email.com',
            'notes' => 'Cliente de tester',
        ]);
    }
}
