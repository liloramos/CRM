<?php

namespace App\Jobs;

use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWhatsAppWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $eventId) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        $event = WhatsAppWebhookEvent::query()->find($this->eventId);

        if ($event === null || $event->status === WhatsAppWebhookEvent::STATUS_PROCESSED) {
            return;
        }

        $whatsapp->processWebhookEvent($event);
    }
}
