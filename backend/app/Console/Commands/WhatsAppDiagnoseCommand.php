<?php

namespace App\Console\Commands;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppMessageDelivery;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

class WhatsAppDiagnoseCommand extends Command
{
    protected $signature = 'whatsapp:diagnose {--probe : Test Meta connectivity without sending a message}';

    protected $description = 'Show safe WhatsApp Cloud API diagnostics without printing secrets.';

    public function handle(WhatsAppProviderInterface $provider): int
    {
        $lastOutbound = WhatsAppMessageDelivery::query()
            ->where('direction', WhatsAppMessageDelivery::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();
        $lastInbound = Message::query()
            ->where('direction', WhatsAppMessageDelivery::DIRECTION_INBOUND)
            ->latest('id')
            ->first();
        $lastSuccessfulOutbound = WhatsAppMessageDelivery::query()
            ->where('direction', WhatsAppMessageDelivery::DIRECTION_OUTBOUND)
            ->whereIn('status', [
                WhatsAppMessageDelivery::STATUS_SENT,
                WhatsAppMessageDelivery::STATUS_DELIVERED,
                WhatsAppMessageDelivery::STATUS_READ,
            ])
            ->latest('id')
            ->first();
        $lastWebhook = WhatsAppWebhookEvent::query()->latest('received_at')->latest('id')->first();
        $demoCustomers = Customer::query()
            ->where(function ($query): void {
                $query->where('source_channel', Customer::SOURCE_CHANNEL_DEMO)
                    ->orWhere('email', Customer::DEMO_EMAIL)
                    ->orWhere('email', 'like', Customer::DASHBOARD_DEMO_EMAIL_PREFIX.'%@example.test');
            })
            ->count();
        $demoConversations = Conversation::query()
            ->whereHas('customer', function ($query): void {
                $query->where('source_channel', Customer::SOURCE_CHANNEL_DEMO)
                    ->orWhere('email', Customer::DEMO_EMAIL)
                    ->orWhere('email', 'like', Customer::DASHBOARD_DEMO_EMAIL_PREFIX.'%@example.test');
            })
            ->count();

        $rows = [
            ['Provider ativo', (string) config('chatbotcrm.whatsapp.provider', 'fake')],
            ['Provider configurado', $this->yesNo((bool) config('chatbotcrm.whatsapp.meta.token') && (bool) config('chatbotcrm.whatsapp.meta.phone_number_id') && (bool) config('chatbotcrm.whatsapp.meta.verify_token'))],
            ['Token presente', $this->yesNo((bool) config('chatbotcrm.whatsapp.meta.token'))],
            ['Phone number ID presente', $this->yesNo((bool) config('chatbotcrm.whatsapp.meta.phone_number_id'))],
            ['Business account ID presente', $this->yesNo((bool) config('chatbotcrm.whatsapp.meta.business_account_id'))],
            ['Verify token presente', $this->yesNo((bool) config('chatbotcrm.whatsapp.meta.verify_token'))],
            ['App secret presente', $this->yesNo((bool) config('chatbotcrm.whatsapp.meta.app_secret'))],
            ['CA bundle configurado', $this->yesNo((bool) config('chatbotcrm.whatsapp.meta.ca_bundle'))],
            ['CA bundle legível', $this->yesNo($this->caBundleIsReadable())],
            ['Versão da API', (string) config('chatbotcrm.whatsapp.meta.api_version', 'v20.0')],
            ['Queue connection', (string) config('queue.default')],
            ['Config cache', $this->yesNo(App::configurationIsCached())],
            ['Webhook canônico', '/api/webhooks/whatsapp'],
            ['Último webhook recebido', $lastWebhook?->received_at?->toIso8601String() ?? 'nenhum'],
            ['Último outbound', $lastOutbound ? $lastOutbound->status.' #'.$lastOutbound->id : 'nenhum'],
            ['Último envio bem-sucedido', $lastSuccessfulOutbound?->sent_at?->toIso8601String() ?? 'nenhum'],
            ['Último erro sanitizado', $lastOutbound?->error_message ?: 'nenhum'],
            ['Código sanitizado', (string) (data_get($lastOutbound?->safe_payload, 'error_code') ?: 'nenhum')],
            ['HTTP Meta', (string) (data_get($lastOutbound?->safe_payload, 'http_status') ?? 'sem resposta')],
            ['Código Meta', (string) (data_get($lastOutbound?->safe_payload, 'meta_error_code') ?: 'nenhum')],
            ['Subcódigo Meta', (string) (data_get($lastOutbound?->safe_payload, 'meta_error_subcode') ?: 'nenhum')],
            ['Último inbound recebido', $lastInbound ? '#'.$lastInbound->id.' '.$lastInbound->created_at?->toIso8601String() : 'nenhum'],
            ['Demo data habilitada', $this->yesNo((bool) Config::get('chatbotcrm.whatsapp.demo_data_enabled'))],
            ['Clientes demo detectados', (string) $demoCustomers],
            ['Conversas demo detectadas', (string) $demoConversations],
        ];

        $this->table(['Item', 'Valor seguro'], $rows);

        if ($this->option('probe')) {
            $probe = $provider->diagnoseConnectivity();
            $this->newLine();
            $this->table(['Teste de conectividade', 'Resultado seguro'], [
                ['Status', (string) ($probe['status'] ?? 'unknown')],
                ['HTTP', (string) ($probe['http_status'] ?? 'sem resposta')],
                ['Código', (string) ($probe['error_code'] ?? 'nenhum')],
                ['Código Meta', (string) ($probe['meta_error_code'] ?? 'nenhum')],
                ['Subcódigo Meta', (string) ($probe['meta_error_subcode'] ?? 'nenhum')],
                ['Motivo de rede', (string) ($probe['network_error_reason'] ?? 'não se aplica')],
                ['Código de transporte', (string) ($probe['network_error_code'] ?? 'nenhum')],
                ['Exceção de transporte', (string) ($probe['network_exception'] ?? 'nenhuma')],
                ['Mensagem', (string) ($probe['message'] ?? 'Conexão disponível.')],
            ]);
        }

        return self::SUCCESS;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'sim' : 'não';
    }

    private function caBundleIsReadable(): bool
    {
        $bundle = trim((string) config('chatbotcrm.whatsapp.meta.ca_bundle', ''));

        return $bundle !== '' && is_file($bundle) && is_readable($bundle);
    }
}
