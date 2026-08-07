<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppWebhookEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppInboundTrace
{
    private const SAFE_CONTEXT_KEYS = [
        'account_id',
        'company_id',
        'conversation_id',
        'customer_id',
        'error_code',
        'event_id',
        'field',
        'message_count',
        'message_id_suffix',
        'message_type',
        'phone_number_id_suffix',
        'status',
        'status_count',
    ];

    public function newCorrelationId(): string
    {
        return (string) Str::uuid();
    }

    public function correlationId(WhatsAppWebhookEvent $event): string
    {
        $correlationId = data_get($event->sanitized_payload, 'inbound_trace.correlation_id');

        return is_string($correlationId) && $correlationId !== ''
            ? $correlationId
            : $this->newCorrelationId();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $correlationId, string $stage, array $context = []): void
    {
        $safeContext = [
            'correlation_id' => $correlationId,
            'stage' => $stage,
            ...$this->sanitizeContext($context),
        ];

        if (in_array($stage, ['event_failed', 'signature_rejected'], true)) {
            Log::warning('whatsapp_inbound', $safeContext);

            return;
        }

        Log::info('whatsapp_inbound', $safeContext);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(WhatsAppWebhookEvent $event, string $stage, array $context = []): void
    {
        $correlationId = $this->correlationId($event);
        $safeContext = $this->sanitizeContext($context);
        $payload = $event->sanitized_payload ?? [];
        $previousTrace = data_get($payload, 'inbound_trace', []);

        if (! is_array($previousTrace)) {
            $previousTrace = [];
        }

        $payload['inbound_trace'] = [
            ...$previousTrace,
            'correlation_id' => $correlationId,
            'last_stage' => $stage,
            'last_stage_at' => now()->toIso8601String(),
            ...$safeContext,
        ];

        $event->forceFill(['sanitized_payload' => $payload])->save();
        $this->log($correlationId, $stage, ['event_id' => $event->id, ...$safeContext]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, bool|int|string|null>
     */
    private function sanitizeContext(array $context): array
    {
        $safe = [];

        foreach (self::SAFE_CONTEXT_KEYS as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];

            if (is_bool($value) || is_int($value) || $value === null) {
                $safe[$key] = $value;

                continue;
            }

            if (is_scalar($value)) {
                $safe[$key] = Str::limit((string) $value, 80, '');
            }
        }

        return $safe;
    }
}
