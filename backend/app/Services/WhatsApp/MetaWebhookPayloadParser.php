<?php

namespace App\Services\WhatsApp;

use App\Data\WhatsApp\IncomingWhatsAppMessage;
use Carbon\CarbonImmutable;

class MetaWebhookPayloadParser
{
    /**
     * @return list<IncomingWhatsAppMessage>
     */
    public function parse(array $payload, string $provider): array
    {
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            return $this->parseSimplePayload($payload, $provider);
        }

        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $metadata = $value['metadata'] ?? [];
                $contacts = $this->contactsByWaId($value['contacts'] ?? []);

                foreach ($value['messages'] ?? [] as $message) {
                    $messages[] = $this->messageFromMetaRow($message, $metadata, $contacts, $provider);
                }
            }
        }

        return $messages;
    }

    /**
     * @return list<IncomingWhatsAppMessage>
     */
    private function parseSimplePayload(array $payload, string $provider): array
    {
        $messages = [];

        foreach ($payload['messages'] as $message) {
            $messages[] = new IncomingWhatsAppMessage(
                provider: $provider,
                providerAccountId: $payload['phone_number_id'] ?? null,
                providerMessageId: null,
                from: $message['from'] ?? null,
                to: $message['to'] ?? ($payload['phone_number_id'] ?? null),
                senderName: $message['sender_name'] ?? null,
                messageType: $message['type'] ?? 'text',
                text: $message['text']['body'] ?? $message['text'] ?? null,
                sentAt: isset($message['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $message['timestamp']) : null,
                replyToProviderMessageId: $message['context']['id'] ?? null,
                rawPayload: $message,
                safeMetadata: [
                    'source' => 'simple_payload',
                    'phone_number_id' => $payload['phone_number_id'] ?? null,
                    'reply_context_present' => isset($message['context']['id']),
                    ...$this->messageMetadata($message, $message['type'] ?? 'text'),
                ],
            );
        }

        return $messages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @return array<string, string|null>
     */
    private function contactsByWaId(array $contacts): array
    {
        $indexed = [];

        foreach ($contacts as $contact) {
            $waId = $contact['wa_id'] ?? null;

            if ($waId === null) {
                continue;
            }

            $indexed[(string) $waId] = $contact['profile']['name'] ?? null;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string|null>  $contacts
     */
    private function messageFromMetaRow(array $message, array $metadata, array $contacts, string $provider): IncomingWhatsAppMessage
    {
        $from = $message['from'] ?? null;
        $type = (string) ($message['type'] ?? 'unknown');

        $revokedMessageId = $this->revokedMessageId($message);
        if ($revokedMessageId !== null) {
            return new IncomingWhatsAppMessage(
                provider: $provider,
                providerAccountId: $metadata['phone_number_id'] ?? null,
                providerMessageId: $message['id'] ?? null,
                from: $from,
                to: $metadata['display_phone_number'] ?? ($metadata['phone_number_id'] ?? null),
                senderName: $from !== null ? ($contacts[$from] ?? null) : null,
                messageType: 'message_revoked',
                text: null,
                sentAt: isset($message['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $message['timestamp']) : null,
                rawPayload: $message,
                safeMetadata: [
                    'source' => 'meta_cloud_webhook',
                    'phone_number_id' => $metadata['phone_number_id'] ?? null,
                    'revoked_message_id_suffix' => substr($revokedMessageId, -8),
                ],
            );
        }

        return new IncomingWhatsAppMessage(
            provider: $provider,
            providerAccountId: $metadata['phone_number_id'] ?? null,
            providerMessageId: $message['id'] ?? null,
            from: $from,
            to: $metadata['display_phone_number'] ?? ($metadata['phone_number_id'] ?? null),
            senderName: $from !== null ? ($contacts[$from] ?? null) : null,
            messageType: $type,
            text: $this->messageText($message, $type),
            sentAt: isset($message['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $message['timestamp']) : null,
            replyToProviderMessageId: $message['context']['id'] ?? null,
            rawPayload: $message,
            safeMetadata: [
                'source' => 'meta_cloud_webhook',
                'phone_number_id' => $metadata['phone_number_id'] ?? null,
                'display_phone_number_present' => isset($metadata['display_phone_number']),
                'reply_context_present' => isset($message['context']['id']),
                ...$this->messageMetadata($message, $type),
            ],
        );
    }

    private function revokedMessageId(array $message): ?string
    {
        $candidate = $message['revoked']['message_id']
            ?? $message['message_deleted']['id']
            ?? $message['deleted']['message_id']
            ?? ($message['type'] === 'revoked' ? ($message['id'] ?? null) : null);

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }

    private function messageText(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            'image' => $message['image']['caption'] ?? null,
            'document' => $message['document']['caption'] ?? null,
            'location' => isset($message['location'])
                ? 'Localizacao compartilhada'
                : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function messageMetadata(array $message, string $type): array
    {
        $media = $message[$type] ?? [];

        if (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true) && is_array($media)) {
            return [
                'media_id' => $media['id'] ?? null,
                'mime_type' => $media['mime_type'] ?? null,
                'sha256' => $media['sha256'] ?? null,
                'filename' => $media['filename'] ?? null,
                'caption_present' => isset($media['caption']),
                'voice_note' => $type === 'audio' ? (bool) ($media['voice'] ?? false) : false,
            ];
        }

        if ($type === 'reaction' && isset($message['reaction']) && is_array($message['reaction'])) {
            return [
                'reaction_target_present' => isset($message['reaction']['message_id']),
                'reaction_emoji' => $message['reaction']['emoji'] ?? null,
                'reaction_emoji_present' => isset($message['reaction']['emoji']) && $message['reaction']['emoji'] !== '',
            ];
        }

        if ($type === 'location' && isset($message['location']) && is_array($message['location'])) {
            return [
                'location_present' => true,
                'latitude_present' => isset($message['location']['latitude']),
                'longitude_present' => isset($message['location']['longitude']),
            ];
        }

        if ($type === 'interactive' && isset($message['interactive']) && is_array($message['interactive'])) {
            return [
                'interactive_type' => $message['interactive']['type'] ?? null,
                'reply_id' => $message['interactive']['button_reply']['id']
                    ?? $message['interactive']['list_reply']['id']
                    ?? null,
            ];
        }

        return [];
    }
}
