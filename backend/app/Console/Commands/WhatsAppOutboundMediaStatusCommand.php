<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\WhatsAppMessageDelivery;
use Illuminate\Console\Command;

class WhatsAppOutboundMediaStatusCommand extends Command
{
    protected $signature = 'whatsapp:outbound-media-status {--limit=10 : Number of latest persisted media attempts}';

    protected $description = 'Shows sanitized persisted WhatsApp media delivery states without exposing recipients or provider IDs.';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 50);
        $messages = Message::query()
            ->with('mediaFiles')
            ->where('direction', WhatsAppMessageDelivery::DIRECTION_OUTBOUND)
            ->whereIn('type', ['image', 'document', 'video', 'audio'])
            ->latest('id')
            ->limit($limit)
            ->get();
        $deliveries = WhatsAppMessageDelivery::query()
            ->whereIn('message_id', $messages->pluck('id'))
            ->latest('id')
            ->get()
            ->keyBy('message_id');

        $this->table(
            ['Mensagem', 'Conversa', 'Tipo', 'MIME', 'Origem', 'Normalização', 'Provider chamado', 'Status'],
            $messages->map(function (Message $message) use ($deliveries): array {
                $media = $message->mediaFiles->first();
                $delivery = $deliveries->get($message->id);

                return [
                    $message->id,
                    $message->conversation_id,
                    $message->type,
                    $media?->mime_type ?? 'sem mídia',
                    data_get($media?->metadata, 'recording_source') ?? 'anexo',
                    data_get($media?->metadata, 'audio_normalization.action') ?? 'não se aplica',
                    $media?->provider_media_id ? 'sim' : 'não',
                    $delivery?->status ?? $message->delivery_status ?? 'sem entrega',
                ];
            })->all(),
        );

        $this->line('Ausência de registro significa que a tentativa foi bloqueada antes da persistência/provider.');

        return self::SUCCESS;
    }
}
