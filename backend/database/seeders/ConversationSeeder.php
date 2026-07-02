<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Seeder;

class ConversationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $conversation = Conversation::create([
            'company_id' => 1,  
            'customer_id' => 1,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'content' => 'Olá, gostaria de fazer um pedido.',
            'type' => 'text',
        ]); 
    }
}
