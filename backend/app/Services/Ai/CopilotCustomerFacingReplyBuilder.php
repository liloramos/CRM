<?php

namespace App\Services\Ai;

use App\Models\Company;

final class CopilotCustomerFacingReplyBuilder
{
    /** @return array<string,mixed> */
    public function paymentKey(Company $company): array
    {
        $company->loadMissing('setting');
        $key = trim((string) data_get($company->setting?->settings, 'payments.pix.key', ''));
        $holder = trim((string) data_get($company->setting?->settings, 'payments.pix.holder_name', ''));

        if ($key === '') {
            return $this->analysis('Vou confirmar a chave Pix para você.', ['pix_configured' => false]);
        }

        $reply = "A chave Pix é {$key}.";
        if ($holder !== '') {
            $reply .= " Favorecido: {$holder}.";
        }

        return $this->analysis($reply, ['pix_configured' => true]);
    }

    /** @return array<string,mixed> */
    public function deliveryFee(): array
    {
        return $this->analysis('Ainda preciso confirmar a taxa de entrega para esse endereço.');
    }

    /** @return array<string,mixed> */
    private function analysis(string $reply, array $metadata = []): array
    {
        return [
            'intent' => 'GENERAL_MESSAGE',
            'confidence' => 1,
            'summary' => 'Resposta operacional segura.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'metadata' => ['reply_source' => 'customer_facing_policy', ...$metadata],
        ];
    }
}
