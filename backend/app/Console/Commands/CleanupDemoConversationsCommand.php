<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppMediaFile;
use App\Models\WhatsAppMessageDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDemoConversationsCommand extends Command
{
    protected $signature = 'whatsapp:cleanup-demo
        {--confirm= : Type EXCLUIR to confirm local demo cleanup}';

    protected $description = 'Remove only explicitly marked local WhatsApp conversation demo data.';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('A limpeza de dados demonstrativos é bloqueada em produção.');

            return self::FAILURE;
        }

        if ($this->option('confirm') !== 'EXCLUIR') {
            $this->error('Informe --confirm=EXCLUIR para remover apenas os dados demonstrativos locais.');

            return self::FAILURE;
        }

        $summary = DB::transaction(function (): array {
            $customerIds = Customer::query()
                ->where(function ($query): void {
                    $query->where('source_channel', Customer::SOURCE_CHANNEL_DEMO)
                        ->orWhere('email', Customer::DEMO_EMAIL)
                        ->orWhere('email', 'like', Customer::DASHBOARD_DEMO_EMAIL_PREFIX.'%@example.test');
                })
                ->pluck('id');

            if ($customerIds->isEmpty()) {
                return [
                    'customers' => 0,
                    'conversations' => 0,
                    'messages' => 0,
                    'deliveries' => 0,
                    'media' => 0,
                    'alerts' => 0,
                ];
            }

            $conversationIds = Conversation::query()
                ->whereIn('customer_id', $customerIds)
                ->pluck('id');
            $messageIds = Message::query()
                ->whereIn('conversation_id', $conversationIds)
                ->pluck('id');

            $media = WhatsAppMediaFile::query()
                ->whereIn('message_id', $messageIds)
                ->delete();
            $deliveries = WhatsAppMessageDelivery::query()
                ->whereIn('conversation_id', $conversationIds)
                ->orWhereIn('message_id', $messageIds)
                ->delete();
            $alerts = ConversationAlert::query()
                ->whereIn('conversation_id', $conversationIds)
                ->delete();
            $messages = Message::query()
                ->whereIn('id', $messageIds)
                ->delete();
            $conversations = Conversation::query()
                ->whereIn('id', $conversationIds)
                ->delete();
            $customers = Customer::query()
                ->whereIn('id', $customerIds)
                ->delete();

            return [
                'customers' => $customers,
                'conversations' => $conversations,
                'messages' => $messages,
                'deliveries' => $deliveries,
                'media' => $media,
                'alerts' => $alerts,
            ];
        });

        $this->table(['Registro', 'Removidos'], collect($summary)->map(fn (int $count, string $key): array => [$key, $count])->values()->all());

        return self::SUCCESS;
    }
}
