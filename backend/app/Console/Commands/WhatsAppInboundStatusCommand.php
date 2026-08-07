<?php

namespace App\Console\Commands;

use App\Models\WhatsAppWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WhatsAppInboundStatusCommand extends Command
{
    protected $signature = 'whatsapp:inbound-status {--since=30 : Janela em minutos}';

    protected $description = 'Show sanitized inbound WhatsApp pipeline status.';

    public function handle(): int
    {
        $minutes = max(1, min(10080, (int) $this->option('since')));
        $since = now()->subMinutes($minutes);
        $events = WhatsAppWebhookEvent::query()
            ->where('received_at', '>=', $since)
            ->latest('received_at')
            ->latest('id')
            ->get();
        $lastEvent = $events->first();
        $trace = is_array(data_get($lastEvent?->sanitized_payload, 'inbound_trace'))
            ? data_get($lastEvent?->sanitized_payload, 'inbound_trace')
            : [];
        $correlationId = is_string($trace['correlation_id'] ?? null)
            ? '...'.substr($trace['correlation_id'], -12)
            : 'nenhum';

        $this->table(['Item', 'Valor seguro'], [
            ['Janela', $minutes.' minuto(s)'],
            ['Ultimo POST', $lastEvent?->received_at?->toIso8601String() ?? 'nenhum'],
            ['Correlation ID', $correlationId],
            ['Ultima etapa', (string) ($trace['last_stage'] ?? 'nenhuma')],
            ['Status do evento', $lastEvent?->status ?? 'nenhum'],
            ['Codigo interno', (string) ($trace['error_code'] ?? 'nenhum')],
            ['Eventos', (string) $events->count()],
            ['Processados', (string) $events->where('status', WhatsAppWebhookEvent::STATUS_PROCESSED)->count()],
            ['Ignorados', (string) $events->where('status', WhatsAppWebhookEvent::STATUS_IGNORED)->count()],
            ['Falhos', (string) $events->where('status', WhatsAppWebhookEvent::STATUS_FAILED)->count()],
            ['Jobs pendentes', (string) DB::table('jobs')->count()],
            ['Jobs falhos', (string) DB::table('failed_jobs')->count()],
        ]);

        return self::SUCCESS;
    }
}
