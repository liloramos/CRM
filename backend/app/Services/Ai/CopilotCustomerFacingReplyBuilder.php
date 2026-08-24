<?php

namespace App\Services\Ai;

final class CopilotCustomerFacingReplyBuilder
{
    /** @return array<string,mixed> */
    public function paymentKey(): array
    {
        return $this->analysis('Vou confirmar a chave Pix para você.');
    }

    /** @return array<string,mixed> */
    public function deliveryFee(): array
    {
        return $this->analysis('Ainda preciso confirmar a taxa de entrega para esse endereço.');
    }

    /** @return array<string,mixed> */
    private function analysis(string $reply): array
    {
        return [
            'intent' => 'GENERAL_MESSAGE',
            'confidence' => 1,
            'summary' => 'Resposta operacional segura.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'metadata' => ['reply_source' => 'customer_facing_policy'],
        ];
    }
}
