<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Data\Ai\AiProviderStatus;
use App\Data\Ai\AiSuggestionContext;
use App\Data\Ai\AiSuggestionResult;
use App\Data\Ai\AutomationDispatchResult;
use App\Models\AiResponseSuggestion;
use Illuminate\Support\Facades\Http;
use Throwable;

class N8nAiProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'n8n';
    }

    public function isConfigured(): bool
    {
        return $this->webhookBaseUrl() !== '' && $this->webhookPath() !== '';
    }

    public function connectionStatus(): AiProviderStatus
    {
        return new AiProviderStatus(
            provider: $this->name(),
            configured: $this->isConfigured(),
            status: $this->isConfigured() ? 'configured' : 'missing_configuration',
            details: [
                'webhook_base_url_present' => $this->webhookBaseUrl() !== '',
                'webhook_path_present' => $this->webhookPath() !== '',
                'external_api_called' => false,
            ],
        );
    }

    public function suggestReply(AiSuggestionContext $context): AiSuggestionResult
    {
        if (! $this->isConfigured()) {
            return new AiSuggestionResult(
                provider: $this->name(),
                suggestedText: 'IA/n8n ainda nao configurada. Encaminhe para atendimento manual e confirme os dados antes de finalizar.',
                suggestionType: AiResponseSuggestion::TYPE_REPLY,
                confidenceScore: 0.1,
                requiresHumanConfirmation: true,
                ambiguityReason: 'n8n_missing_configuration',
                safetyNotes: 'Nenhuma chamada externa foi realizada.',
                metadata: ['external_api_called' => false],
            );
        }

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->asJson()
                ->post($this->webhookUrl(), [
                    'event_type' => 'ai_reply_suggestion',
                    'company_id' => $context->company->id,
                    'conversation_id' => $context->conversation->id,
                    'message_id' => $context->message?->id,
                    'automation_mode' => $context->automationMode,
                    'message_present' => $context->latestContent() !== '',
                    'message_length' => strlen($context->latestContent()),
                    'metadata' => [
                        'source' => 'chatbotcrm',
                        'contains_raw_customer_message' => false,
                    ],
                ]);

            $json = $response->json();
            $payload = is_array($json) ? $json : [];
            $suggestedText = (string) ($payload['suggested_text'] ?? $payload['reply'] ?? '');

            if (! $response->successful() || $suggestedText === '') {
                return new AiSuggestionResult(
                    provider: $this->name(),
                    suggestedText: 'Nao consegui gerar uma sugestao automatica agora. Encaminhe para atendimento manual.',
                    suggestionType: AiResponseSuggestion::TYPE_REPLY,
                    confidenceScore: 0.2,
                    requiresHumanConfirmation: true,
                    ambiguityReason: 'n8n_unavailable_or_empty_response',
                    safetyNotes: 'Falha ou resposta vazia do provider n8n.',
                    metadata: [
                        'external_api_called' => true,
                        'http_status' => $response->status(),
                    ],
                );
            }

            return new AiSuggestionResult(
                provider: $this->name(),
                suggestedText: $suggestedText,
                suggestionType: (string) ($payload['suggestion_type'] ?? AiResponseSuggestion::TYPE_REPLY),
                confidenceScore: isset($payload['confidence_score']) ? (float) $payload['confidence_score'] : null,
                requiresHumanConfirmation: (bool) ($payload['requires_human_confirmation'] ?? true),
                ambiguityReason: isset($payload['ambiguity_reason']) ? (string) $payload['ambiguity_reason'] : null,
                safetyNotes: isset($payload['safety_notes']) ? (string) $payload['safety_notes'] : null,
                metadata: [
                    'external_api_called' => true,
                    'http_status' => $response->status(),
                    'provider_response_keys' => array_keys($payload),
                ],
            );
        } catch (Throwable $exception) {
            return new AiSuggestionResult(
                provider: $this->name(),
                suggestedText: 'A automacao nao respondeu agora. Encaminhe para atendimento manual e confirme os dados.',
                suggestionType: AiResponseSuggestion::TYPE_REPLY,
                confidenceScore: 0.1,
                requiresHumanConfirmation: true,
                ambiguityReason: 'n8n_request_failed',
                safetyNotes: 'Falha ao consultar provider n8n.',
                metadata: [
                    'external_api_called' => true,
                    'error_class' => $exception::class,
                ],
            );
        }
    }

    public function dispatchAutomationEvent(string $eventType, array $payload): AutomationDispatchResult
    {
        if (! $this->isConfigured()) {
            return new AutomationDispatchResult(
                provider: $this->name(),
                status: 'skipped',
                safePayload: [
                    'event_type' => $eventType,
                    'configured' => false,
                    'external_api_called' => false,
                ],
            );
        }

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->asJson()
                ->post($this->webhookUrl(), [
                    'event_type' => $eventType,
                    'payload' => $payload,
                ]);

            return new AutomationDispatchResult(
                provider: $this->name(),
                status: $response->successful() ? 'dispatched' : 'failed',
                errorMessage: $response->successful() ? null : 'n8n webhook returned HTTP '.$response->status().'.',
                safePayload: [
                    'http_status' => $response->status(),
                    'payload_keys' => array_keys($payload),
                    'external_api_called' => true,
                ],
            );
        } catch (Throwable $exception) {
            return new AutomationDispatchResult(
                provider: $this->name(),
                status: 'failed',
                errorMessage: 'n8n webhook request failed.',
                safePayload: [
                    'error_class' => $exception::class,
                    'external_api_called' => true,
                ],
            );
        }
    }

    private function webhookBaseUrl(): string
    {
        return trim((string) config('chatbotcrm.integrations.n8n.webhook_base_url', ''));
    }

    private function webhookPath(): string
    {
        return trim((string) config('chatbotcrm.ai.n8n.webhook_path', ''));
    }

    private function webhookUrl(): string
    {
        return rtrim($this->webhookBaseUrl(), '/').'/'.ltrim($this->webhookPath(), '/');
    }
}
