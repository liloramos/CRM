<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Data\WhatsApp\IncomingWhatsAppMessage;
use App\Models\Company;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMediaFile;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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
                    'voice_note' => (bool) data_get($incoming->safeMetadata, 'voice_note', false),
                ],
            ]);
        }

        $media = WhatsAppMediaFile::query()->create([
            'company_id' => $company->id,
            'whatsapp_account_id' => $account?->id,
            'message_id' => $message->id,
            'whatsapp_webhook_event_id' => $event->id,
            'provider' => $incoming->provider,
            'provider_media_id' => $mediaId,
            'media_type' => $incoming->messageType,
            'mime_type' => $incoming->safeMetadata['mime_type'] ?? null,
            'sha256' => $incoming->safeMetadata['sha256'] ?? null,
            'original_filename' => WhatsAppMediaFilename::forMedia(
                $incoming->safeMetadata['filename'] ?? null,
                $incoming->safeMetadata['mime_type'] ?? null,
                $incoming->messageType,
                now()->format('Ymd-His'),
                $message->id,
            ),
            'status' => WhatsAppMediaFile::STATUS_RECEIVED,
            'metadata' => [
                'source' => 'whatsapp_webhook',
                'download_attempted' => false,
                'voice_note' => (bool) data_get($incoming->safeMetadata, 'voice_note', false),
            ],
        ]);

        return $this->restoreIncomingMedia($media);
    }

    public function restoreIncomingMedia(WhatsAppMediaFile $media): WhatsAppMediaFile
    {
        if ($this->hasStoredFile($media)) {
            return $media;
        }

        $mediaId = trim((string) $media->provider_media_id);
        if ($mediaId === '') {
            return $this->markDownloadUnavailable($media, 'media_id_missing');
        }

        try {
            $download = $this->provider->downloadMedia($mediaId);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp inbound media download failed.', [
                'company_id' => $media->company_id,
                'media_file_id' => $media->id,
                'message_id' => $media->message_id,
                'media_type' => $media->media_type,
                'provider' => $media->provider,
                'error_class' => $exception::class,
            ]);

            return $this->markDownloadUnavailable($media, 'provider_exception');
        }

        if ($download === null) {
            return $this->markDownloadUnavailable($media, 'provider_unavailable');
        }

        $extension = WhatsAppMediaFilename::extensionFor($download->mimeType ?? (string) $media->mime_type);
        $filePath = 'whatsapp/'.$media->company_id.'/'.Str::uuid()->toString().$extension;

        try {
            Storage::disk('local')->put($filePath, $download->contents);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp inbound media storage failed.', [
                'company_id' => $media->company_id,
                'media_file_id' => $media->id,
                'message_id' => $media->message_id,
                'media_type' => $media->media_type,
                'error_class' => $exception::class,
            ]);

            return $this->markDownloadUnavailable($media, 'storage_unavailable');
        }

        $media->forceFill([
            'mime_type' => $download->mimeType ?? $media->mime_type,
            'original_filename' => WhatsAppMediaFilename::forMedia($download->filename ?? $media->original_filename, $download->mimeType ?? $media->mime_type, $media->media_type, $media->created_at?->format('Ymd-His'), $media->message_id),
            'size_bytes' => $download->sizeBytes ?? strlen($download->contents),
            'checksum' => $download->sha256 ?? hash('sha256', $download->contents),
            'storage_disk' => 'local',
            'file_path' => $filePath,
            'status' => WhatsAppMediaFile::STATUS_STORED,
            'metadata' => [
                ...(array) $media->metadata,
                'download_attempted' => true,
                'download_result' => 'stored',
                ...$download->safePayload,
            ],
        ])->save();

        return $media->refresh();
    }

    private function hasStoredFile(WhatsAppMediaFile $media): bool
    {
        return $media->storage_disk !== null
            && $media->file_path !== null
            && Storage::disk($media->storage_disk)->exists($media->file_path);
    }

    private function markDownloadUnavailable(WhatsAppMediaFile $media, string $reason): WhatsAppMediaFile
    {
        $media->forceFill([
            'status' => WhatsAppMediaFile::STATUS_FAILED,
            'metadata' => [
                ...(array) $media->metadata,
                'download_attempted' => true,
                'download_result' => $reason,
            ],
        ])->save();

        return $media->refresh();
    }
}
