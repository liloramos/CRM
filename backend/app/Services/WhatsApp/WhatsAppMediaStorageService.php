<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Data\WhatsApp\IncomingWhatsAppMessage;
use App\Models\Company;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMediaFile;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppMediaStorageService
{
    public function __construct(private readonly WhatsAppProviderInterface $provider) {}

    public function storeIncomingMedia(
        Company $company,
        ?WhatsAppAccount $account,
        Message $message,
        WhatsAppWebhookEvent $event,
        IncomingWhatsAppMessage $incoming,
    ): ?WhatsAppMediaFile {
        if (! in_array($incoming->messageType, ['image', 'document', 'audio', 'video', 'sticker'], true)) {
            return null;
        }

        $mediaId = (string) ($incoming->safeMetadata['media_id'] ?? '');

        if ($mediaId === '') {
            return WhatsAppMediaFile::query()->create([
                'company_id' => $company->id,
                'whatsapp_account_id' => $account?->id,
                'message_id' => $message->id,
                'whatsapp_webhook_event_id' => $event->id,
                'provider' => $incoming->provider,
                'media_type' => $incoming->messageType,
                'mime_type' => $incoming->safeMetadata['mime_type'] ?? null,
                'sha256' => $incoming->safeMetadata['sha256'] ?? null,
                'original_filename' => $incoming->safeMetadata['filename'] ?? null,
                'status' => WhatsAppMediaFile::STATUS_RECEIVED,
                'metadata' => [
                    'media_id_missing' => true,
                    'source' => 'whatsapp_webhook',
                ],
            ]);
        }

        $download = $this->provider->downloadMedia($mediaId);
        $filePath = null;
        $checksum = null;
        $sizeBytes = null;
        $status = WhatsAppMediaFile::STATUS_RECEIVED;
        $metadata = [
            'source' => 'whatsapp_webhook',
            'download_attempted' => true,
        ];

        if ($download !== null) {
            $contents = $download->contents;
            $extension = WhatsAppMediaFilename::extensionFor($download->mimeType ?? (string) ($incoming->safeMetadata['mime_type'] ?? ''));
            $filePath = 'whatsapp/'.$company->id.'/'.Str::uuid()->toString().$extension;
            Storage::disk('local')->put($filePath, $contents);
            $checksum = $download->sha256 ?? hash('sha256', $contents);
            $sizeBytes = $download->sizeBytes ?? strlen($contents);
            $status = WhatsAppMediaFile::STATUS_STORED;
            $metadata = [
                ...$metadata,
                ...$download->safePayload,
            ];
        }

        return WhatsAppMediaFile::query()->create([
            'company_id' => $company->id,
            'whatsapp_account_id' => $account?->id,
            'message_id' => $message->id,
            'whatsapp_webhook_event_id' => $event->id,
            'provider' => $incoming->provider,
            'provider_media_id' => $mediaId,
            'media_type' => $incoming->messageType,
            'mime_type' => $download?->mimeType ?? $incoming->safeMetadata['mime_type'] ?? null,
            'sha256' => $incoming->safeMetadata['sha256'] ?? null,
            'original_filename' => WhatsAppMediaFilename::forMedia(
                $download?->filename ?? $incoming->safeMetadata['filename'] ?? null,
                $download?->mimeType ?? $incoming->safeMetadata['mime_type'] ?? null,
                $incoming->messageType,
                now()->format('Ymd-His'),
                $message->id,
            ),
            'size_bytes' => $sizeBytes,
            'checksum' => $checksum,
            'storage_disk' => $filePath !== null ? 'local' : null,
            'file_path' => $filePath,
            'status' => $status,
            'metadata' => $metadata,
        ]);
    }
}
