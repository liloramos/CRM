<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use Illuminate\Support\Str;

class FakeConversationCopilotProvider implements ConversationCopilotProviderInterface
{
    /** @param array<string,mixed>|null $fixture */
    public function __construct(private readonly ?array $fixture = null) {}

    public function name(): string
    {
        return 'fake';
    }

    public function analyze(array $context): array
    {
        if ($this->fixture !== null) {
            return $this->fixture;
        }

        $text = Str::of((string) data_get($context, 'latest_message.body', ''))->ascii()->lower()->toString();
        $intent = str_contains($text, 'cardapio') ? 'MENU_REQUEST' : (str_contains($text, 'quero') ? 'ORDER_CREATE' : 'GENERAL_QUESTION');

        return [
            'intent' => $intent,
            'confidence' => $intent === 'ORDER_CREATE' ? 0.72 : 0.48,
            'summary' => 'Analise local para revisao humana.',
            'draft_order' => ['items' => [], 'fulfillment' => str_contains($text, 'entrega') ? 'delivery' : null, 'address' => null, 'payment_method' => null],
            'missing_information' => $intent === 'ORDER_CREATE' ? ['Revise os itens antes de criar o pedido.'] : [],
            'warnings' => ['Resultado gerado pelo provider fake; revise antes de usar.'],
            'suggested_reply' => $intent === 'MENU_REQUEST' ? 'Posso te passar as opcoes disponiveis de hoje.' : 'Vou conferir os detalhes com voce antes de registrar o pedido.',
            'requires_human_review' => true,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ];
    }
}
