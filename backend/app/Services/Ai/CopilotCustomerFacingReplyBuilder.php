<?php

namespace App\Services\Ai;

use App\Models\Company;

final class CopilotCustomerFacingReplyBuilder
{
    /** @return array<string,mixed> */
    public function orderStart(): array
    {
        return $this->analysis(
            'Claro! O que você gostaria de pedir?',
            ['reply_source' => 'order_start'],
        );
    }

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
    public function restaurantLocation(Company $company): array
    {
        $company->loadMissing('deliverySetting');
        $address = trim((string) data_get($company->deliverySetting?->provider_options, 'origin.address', ''));

        if ($address === '') {
            return $this->analysis(
                'Vou confirmar o endereço do restaurante para você.',
                ['location_configured' => false],
                [['code' => 'LOCATION_UNAVAILABLE', 'message' => 'O endereço do restaurante não está configurado.']],
            );
        }

        return $this->analysis("Ficamos na {$address}. ☀️", ['location_configured' => true]);
    }

    /** @return array<string,mixed> */
    private function analysis(string $reply, array $metadata = [], array $warnings = []): array
    {
        return [
            'intent' => 'GENERAL_MESSAGE',
            'confidence' => 1,
            'summary' => 'Resposta operacional segura.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => $warnings,
            'suggested_reply' => $reply,
            'metadata' => ['reply_source' => 'customer_facing_policy', ...$metadata],
        ];
    }
}
