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
                providerMessageId: $message['id'] ?? null,
                from: $message['from'] ?? null,
                to: $message['to'] ?? ($payload['phone_number_id'] ?? null),
                senderName: $message['sender_name'] ?? null,
                messageType: $message['type'] ?? 'text',
                text: $message['text']['body'] ?? $message['text'] ?? null,
                sentAt: isset($message['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $message['timestamp']) : null,
                rawPayload: $message,
                safeMetadata: [
                    'source' => 'simple_payload',
                    'phone_number_id' => $payload['phone_number_id'] ?? null,
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

        return new IncomingWhatsAppMessage(
            provider: $provider,
            providerAccountId: $metadata['phone_number_id'] ?? null,
            providerMessageId: $message['id'] ?? null,
            from: $from,
            to: $metadata['display_phone_number'] ?? ($metadata['phone_number_id'] ?? null),
            senderName: $from !== null ? ($contacts[$from] ?? null) : null,
            messageType: $type,
            text: $type === 'text' ? ($message['text']['body'] ?? null) : null,
            sentAt: isset($message['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $message['timestamp']) : null,
            rawPayload: $message,
            safeMetadata: [
                'source' => 'meta_cloud_webhook',
                'phone_number_id' => $metadata['phone_number_id'] ?? null,
                'display_phone_number_present' => isset($metadata['display_phone_number']),
            ],
        );
    }
}
