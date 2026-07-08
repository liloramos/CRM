<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Database\Seeder;

class ConversationSeeder extends Seeder
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

        $customer = Customer::query()->updateOrCreate(
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

        $conversation = Conversation::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'channel' => 'whatsapp',
            ],
            [
                'status' => 'open',
                'started_at' => now(),
            ],
        );

        Message::query()->firstOrCreate(
            [
                'conversation_id' => $conversation->id,
                'sender' => 'customer',
                'type' => 'text',
            ],
            [
                'content' => 'Ola, gostaria de fazer um pedido.',
            ],
        );
    }
}
