<?php

namespace App\Jobs;

use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppInboundTrace;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWhatsAppWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $eventId) {}

    public function handle(WhatsAppService $whatsapp, ?WhatsAppInboundTrace $trace = null): void
    {
        $event = WhatsAppWebhookEvent::query()->find($this->eventId);

        if ($event === null || $event->status === WhatsAppWebhookEvent::STATUS_PROCESSED) {
            return;
        }

        $trace ??= app(WhatsAppInboundTrace::class);
        $trace->record($event, 'job_started');
        $whatsapp->processWebhookEvent($event);
    }

    public function failed(?Throwable $exception): void
    {
        $event = WhatsAppWebhookEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        app(WhatsAppInboundTrace::class)->record($event, 'event_failed', [
            'error_code' => 'whatsapp_webhook_job_failed',
            'status' => WhatsAppWebhookEvent::STATUS_FAILED,
        ]);
    }
}
