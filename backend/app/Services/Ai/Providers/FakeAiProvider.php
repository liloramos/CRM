<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Data\Ai\AiProviderStatus;
use App\Data\Ai\AiSuggestionContext;
use App\Data\Ai\AiSuggestionResult;
use App\Data\Ai\AutomationDispatchResult;
use App\Models\AiResponseSuggestion;
use Illuminate\Support\Str;

class FakeAiProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function connectionStatus(): AiProviderStatus
    {
        return new AiProviderStatus(
            provider: $this->name(),
            configured: true,
            status: 'connected',
            details: [
                'transport' => 'local_fake',
                'external_api_called' => false,
                'auto_send_supported' => false,
            ],
        );
    }

    public function suggestReply(AiSuggestionContext $context): AiSuggestionResult
    {
        $normalized = $this->normalize($context->latestContent());

        if ($normalized === '') {
            return new AiSuggestionResult(
                provider: $this->name(),
                suggestedText: 'Posso te ajudar com o pedido de hoje. Voce quer entrega ou retirada?',
                suggestionType: AiResponseSuggestion::TYPE_CONFIRMATION_QUESTION,
                confidenceScore: 0.45,
                requiresHumanConfirmation: true,
                ambiguityReason: 'empty_or_missing_customer_message',
                safetyNotes: 'Sugestao local sem chamada externa.',
                metadata: ['external_api_called' => false],
            );
        }

        if ($this->containsAny($normalized, ['igual ontem', 'mesmo de ontem', 'o mesmo', 'de sempre', 'repetir'])) {
            return new AiSuggestionResult(
                provider: $this->name(),
                suggestedText: 'Para eu confirmar certinho: voce quer repetir o ultimo pedido ou deseja alterar algum item?',
                suggestionType: AiResponseSuggestion::TYPE_CONFIRMATION_QUESTION,
                confidenceScore: 0.62,
                requiresHumanConfirmation: true,
                ambiguityReason: 'recurring_order_requires_history_confirmation',
                safetyNotes: 'Pedido recorrente precisa ser conferido pelo atendimento antes de confirmar.',
                metadata: ['external_api_called' => false],
            );
        }

        if ($this->containsAny($normalized, ['pix', 'comprovante', 'paguei', 'pagamento', 'credito', 'saldo', 'valor'])) {
            return new AiSuggestionResult(
                provider: $this->name(),
                suggestedText: 'Recebi sua mensagem sobre pagamento. Vou deixar para conferencia do atendimento antes de confirmar.',
                suggestionType: AiResponseSuggestion::TYPE_REPLY,
                confidenceScore: 0.58,
                requiresHumanConfirmation: true,
                ambiguityReason: 'payment_requires_human_confirmation',
                safetyNotes: 'Pagamento, comprovante e credito exigem conferencia humana.',
                metadata: ['external_api_called' => false],
            );
        }

        return new AiSuggestionResult(
            provider: $this->name(),
            suggestedText: 'Sugestao para o atendente: confirme itens, quantidade, entrega ou retirada e pagamento antes de finalizar.',
            suggestionType: AiResponseSuggestion::TYPE_REPLY,
            confidenceScore: 0.55,
            requiresHumanConfirmation: true,
            safetyNotes: 'Resposta sugerida para apoio operacional, sem envio automatico.',
            metadata: ['external_api_called' => false],
        );
    }

    public function dispatchAutomationEvent(string $eventType, array $payload): AutomationDispatchResult
    {
        return new AutomationDispatchResult(
            provider: $this->name(),
            status: 'skipped',
            safePayload: [
                'transport' => 'local_fake',
                'event_type' => $eventType,
                'external_api_called' => false,
                'payload_keys' => array_keys($payload),
            ],
        );
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->toString();
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
