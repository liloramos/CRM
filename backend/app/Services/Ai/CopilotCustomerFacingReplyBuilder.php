<?php

namespace App\Services\Ai;

use App\Models\Company;

final class CopilotCustomerFacingReplyBuilder
{
    /** @return array<string,mixed> */
    public function greeting(): array
    {
        return $this->analysis(
            'Oi! 😊 Como posso te ajudar hoje?',
            ['reply_source' => 'deterministic_greeting'],
            [],
            'GREETING',
        );
    }

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
        $key = trim((string) (data_get($company->setting?->settings, 'payments.pix.public_key')
            ?? data_get($company->setting?->settings, 'payments.pix.key', '')));
        $holder = trim((string) data_get($company->setting?->settings, 'payments.pix.holder_name', ''));

        if ($key === '') {
            return $this->analysis('Ainda não tenho a chave Pix disponível aqui.', ['pix_configured' => false]);
        }

        $reply = "A chave Pix é {$key}.";
        if ($holder !== '') {
            $reply .= " Favorecido: {$holder}.";
        }

        $proofInstruction = 'Depois do pagamento, envie o comprovante aqui para nossa equipe conferir.';

        return $this->analysis(
            $reply.' '.$proofInstruction,
            ['pix_configured' => true],
            [],
            'GENERAL_MESSAGE',
            [$reply, $proofInstruction],
        );
    }

    /** @return array<string,mixed> */
    public function paymentConfirmationRequired(): array
    {
        return $this->analysis(
            'Eu não confirmo pagamentos por aqui. Se você já pagou, envie o comprovante e nossa equipe fará a conferência.',
            ['payment_confirmation_requires_human' => true],
            [],
            'PAYMENT_CONFIRMATION',
        );
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    public function activeOrderPaymentSelection(Company $company, array $context): ?array
    {
        $activeOrder = data_get($context, 'active_order');
        if (! is_array($activeOrder) || (int) ($activeOrder['id'] ?? 0) < 1) {
            return null;
        }

        $text = mb_strtolower(trim((string) data_get($context, 'latest_message.body', '')));
        if (preg_match('/\bpix\b/u', $text) !== 1
            || ! in_array('pix', (array) data_get($context, 'payment.available_methods', []), true)) {
            return null;
        }

        $total = (int) ($activeOrder['total_cents'] ?? 0);
        $summary = $total > 0
            ? 'Perfeito! O total do pedido é *R$ '.number_format($total / 100, 2, ',', '.').'*.'
            : 'Perfeito! Vamos seguir com Pix.';

        return [
            'intent' => 'ORDER_CONTINUE',
            'confidence' => 1,
            'summary' => 'Cliente escolheu Pix para o pedido em andamento.',
            'draft_order' => [
                'items' => [],
                'fulfillment' => $activeOrder['fulfillment_type'] ?? null,
                'address' => $activeOrder['delivery_address'] ?? '',
                'payment_method' => 'pix',
            ],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $summary,
            'reply_messages' => [$summary],
            'metadata' => [
                'reply_source' => 'payment_selection',
                'active_order_id' => (int) $activeOrder['id'],
                'payment_method' => 'pix',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function deliveryFee(): array
    {
        return $this->analysis('Ainda preciso confirmar a taxa de entrega para esse endereço.');
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function pendingOrderTotal(array $context): array
    {
        $activeOrder = data_get($context, 'active_order');
        if (is_array($activeOrder) && (int) ($activeOrder['id'] ?? 0) > 0) {
            $total = (int) ($activeOrder['total_cents'] ?? 0);

            return $this->analysis(
                $total > 0
                    ? 'O total do seu pedido é *R$ '.number_format($total / 100, 2, ',', '.').'*.'
                    : 'Ainda estou fechando o total do seu pedido.',
                ['order_total_cents' => $total, 'total_source' => 'active_order'],
                [],
                'ORDER_STATUS',
            );
        }

        $draft = (array) data_get($context, 'pending_order_state.draft_order', []);
        $menu = collect((array) data_get($context, 'menu', []))->keyBy(fn (array $product): int => (int) ($product['id'] ?? 0));
        $total = collect((array) ($draft['items'] ?? []))->sum(function (array $item) use ($menu): int {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = isset($item['unit_price_cents'])
                ? (int) $item['unit_price_cents']
                : (int) data_get(
                    $menu->get((int) ($item['menu_item_id'] ?? 0)),
                    'resolved_configuration.base_price_cents',
                    data_get($menu->get((int) ($item['menu_item_id'] ?? 0)), 'base_price_cents', 0),
                );

            return $quantity * $unitPrice;
        });

        if ($total < 1) {
            return $this->analysis(
                'Ainda preciso dos itens do pedido para calcular o total.',
                ['order_total_cents' => 0, 'total_source' => 'pending_order'],
                [],
                'ORDER_STATUS',
            );
        }

        $reply = 'Até aqui, seu pedido fica em *R$ '.number_format($total / 100, 2, ',', '.').'*.';
        if (($draft['fulfillment'] ?? null) === 'delivery') {
            $reply .= ' A taxa de entrega ainda precisa ser calculada para o endereço.';
        }

        return $this->analysis(
            $reply,
            ['order_total_cents' => $total, 'total_source' => 'pending_order'],
            [],
            'ORDER_STATUS',
        );
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function customerLocationReceived(array $context): array
    {
        $latitude = data_get($context, 'latest_message.location.latitude');
        $longitude = data_get($context, 'latest_message.location.longitude');
        $address = trim((string) data_get($context, 'latest_message.location.address', ''));
        $draft = (array) data_get($context, 'pending_order_state.draft_order', []);
        $items = (array) ($draft['items'] ?? []);
        $missing = $items === []
            ? [['code' => 'MENU_ITEM', 'label' => 'Produto']]
            : [['code' => 'PAYMENT_METHOD', 'label' => 'Forma de pagamento']];
        $reply = $items === []
            ? 'Recebi sua localização. Qual marmitex você deseja?'
            : 'Recebi sua localização. Como você prefere pagar?';

        return [
            'intent' => 'ORDER_CONTINUE',
            'confidence' => 1,
            'summary' => 'Localização de entrega recebida e preservada.',
            'draft_order' => [
                ...$draft,
                'items' => $items,
                'fulfillment' => 'delivery',
                'address' => $address !== '' ? $address : 'Localização compartilhada via WhatsApp',
                'payment_method' => (string) ($draft['payment_method'] ?? ''),
            ],
            'missing_information' => $missing,
            'warnings' => [],
            'suggested_reply' => $reply,
            'reply_messages' => [$reply],
            'metadata' => [
                'reply_source' => 'customer_location_received',
                'customer_location' => [
                    'latitude' => is_numeric($latitude) ? (float) $latitude : null,
                    'longitude' => is_numeric($longitude) ? (float) $longitude : null,
                    'name' => data_get($context, 'latest_message.location.name'),
                    'address' => $address !== '' ? $address : null,
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function recoveryClarification(): array
    {
        return $this->analysis(
            'Desculpe, não consegui entender direitinho. Você quer ver o cardápio, fazer um pedido ou saber algo do restaurante?',
            ['reply_source' => 'recovery_clarification', 'recoverable_unknown' => true],
            [['code' => 'MESSAGE_NOT_UNDERSTOOD', 'message' => 'A mensagem precisa de uma clarificação do cliente.']],
            'UNKNOWN',
        );
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function contextualRecoveryClarification(array $context): array
    {
        $productId = (int) data_get($context, 'pending_order_state.draft_order.items.0.menu_item_id');
        $product = collect((array) data_get($context, 'menu', []))->firstWhere('id', $productId);
        $productName = trim((string) data_get($product, 'name', 'seu pedido'));
        $slot = (string) data_get($context, 'conversation_frame.last_assistant_goal.slot', '');
        $subject = match ($slot) {
            'carne' => 'a carne',
            'salada' => 'a salada',
            'fulfillment' => 'se vai retirar ou receber por entrega',
            'address' => 'o endereço da entrega',
            'payment_method' => 'a forma de pagamento',
            default => '',
        };
        $reply = $subject === ''
            ? "Não entendi essa última parte. Você quer alterar algo na sua {$productName}?"
            : "Não entendi essa última parte. Na sua {$productName}, você quer informar {$subject} ou alterar outra escolha?";

        return $this->analysis(
            $reply,
            [
                'reply_source' => 'recovery_clarification',
                'recoverable_unknown' => true,
                'contextual_recovery' => true,
            ],
            [['code' => 'MESSAGE_NOT_UNDERSTOOD', 'message' => 'A última parte da mensagem precisa de uma clarificação contextual.']],
            'UNKNOWN',
        );
    }

    /** @return array<string,mixed> */
    public function handoff(string $reason): array
    {
        $reply = match ($reason) {
            'financial_exception' => 'Essa condição precisa ser confirmada pela nossa equipe 😊 Vou chamar uma de nossas atendentes para te ajudar.',
            'out_of_domain' => 'Desculpe 😊 Sou a atendente virtual do Sol Restaurante e consigo ajudar com cardápio, pedidos, pagamentos e informações do restaurante. Vou chamar uma de nossas atendentes para te ajudar por aqui.',
            'customer_requested_human' => 'Claro 😊 Vou chamar uma de nossas atendentes para te ajudar.',
            default => 'Desculpe, não consegui entender direitinho 😕 Vou chamar uma de nossas atendentes para te ajudar.',
        };

        return $this->analysis(
            $reply,
            ['reply_source' => 'explicit_handoff', 'handoff_reason' => $reason],
            [],
            'HUMAN_REQUEST',
        );
    }

    /** @return array<string,mixed> */
    public function restaurantLocation(Company $company): array
    {
        $company->loadMissing('deliverySetting');
        $address = trim((string) data_get($company->deliverySetting?->provider_options, 'origin.address', ''));

        if ($address === '') {
            return $this->analysis(
                'Ainda não tenho o endereço do restaurante disponível aqui.',
                ['location_configured' => false],
                [['code' => 'LOCATION_UNAVAILABLE', 'message' => 'O endereço do restaurante não está configurado.']],
            );
        }

        return $this->analysis("Ficamos na {$address}. ☀️", ['location_configured' => true]);
    }

    /** @return array<string,mixed> */
    private function analysis(string $reply, array $metadata = [], array $warnings = [], string $intent = 'GENERAL_MESSAGE', array $replyMessages = []): array
    {
        return [
            'intent' => $intent,
            'confidence' => 1,
            'summary' => 'Resposta operacional segura.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => $warnings,
            'suggested_reply' => $reply,
            'reply_messages' => $replyMessages,
            'metadata' => ['reply_source' => 'customer_facing_policy', ...$metadata],
        ];
    }
}
