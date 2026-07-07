<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderInterface;
use App\Data\Ai\AiSuggestionContext;
use App\Data\Ai\AiSuggestionResult;
use App\Models\AiAutomationSetting;
use App\Models\AiResponseSuggestion;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiAutomationService
{
    public function __construct(private readonly AiProviderInterface $provider) {}

    /**
     * @return array<string, mixed>
     */
    public function connectionStatus(?Company $company = null): array
    {
        $status = $this->provider->connectionStatus()->toArray();

        if ($company !== null) {
            $status['settings'] = $company->aiAutomationSettings()
                ->orderByDesc('status')
                ->orderBy('id')
                ->get()
                ->map(fn (AiAutomationSetting $setting): array => [
                    'id' => $setting->id,
                    'provider' => $setting->provider,
                    'default_mode' => $setting->default_mode,
                    'automation_enabled' => $setting->automation_enabled,
                    'allow_auto_send' => $setting->allow_auto_send,
                    'require_human_confirmation_for_ambiguous' => $setting->require_human_confirmation_for_ambiguous,
                    'require_human_confirmation_for_payments' => $setting->require_human_confirmation_for_payments,
                    'n8n_webhook_path_present' => $setting->n8n_webhook_path !== null && $setting->n8n_webhook_path !== '',
                    'status' => $setting->status,
                ])
                ->all();
        }

        return $status;
    }

    public function switchMode(Conversation $conversation, string $mode, ?User $user = null, ?string $reason = null): Conversation
    {
        if (! in_array($mode, Conversation::AUTOMATION_MODES, true)) {
            throw new DomainException('Invalid automation mode.');
        }

        return DB::transaction(function () use ($conversation, $mode, $user, $reason): Conversation {
            $conversation = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $company = $conversation->company()->firstOrFail();
            $previousMode = $conversation->automation_mode;

            $attributes = [
                'automation_mode' => $mode,
                'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
                'human_review_required' => false,
                'manual_takeover_reason' => null,
                'manual_takeover_at' => null,
                'manual_takeover_by_user_id' => null,
            ];

            if ($mode === Conversation::AUTOMATION_MODE_MANUAL) {
                $attributes = array_merge($attributes, [
                    'automation_status' => Conversation::AUTOMATION_STATUS_MANUAL_TAKEOVER,
                    'human_review_required' => true,
                    'manual_takeover_reason' => $reason,
                    'manual_takeover_at' => now(),
                    'manual_takeover_by_user_id' => $user?->id,
                ]);
            }

            $conversation->forceFill($attributes)->save();

            $this->recordEvent(
                company: $company,
                eventType: AutomationEvent::TYPE_AUTOMATION_MODE_CHANGED,
                conversation: $conversation,
                user: $user,
                payload: [
                    'previous_mode' => $previousMode,
                    'new_mode' => $mode,
                    'reason_present' => $reason !== null && $reason !== '',
                ],
                requiresHumanConfirmation: $mode === Conversation::AUTOMATION_MODE_MANUAL,
            );

            return $conversation->refresh();
        });
    }

    public function fallbackToHuman(
        Conversation $conversation,
        string $reason,
        ?User $user = null,
        array $metadata = [],
    ): Conversation {
        return DB::transaction(function () use ($conversation, $reason, $user, $metadata): Conversation {
            $conversation = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $company = $conversation->company()->firstOrFail();

            $conversation->forceFill([
                'automation_mode' => Conversation::AUTOMATION_MODE_MANUAL,
                'automation_status' => Conversation::AUTOMATION_STATUS_FALLBACK_REQUIRED,
                'human_review_required' => true,
                'manual_takeover_reason' => $reason,
                'manual_takeover_at' => now(),
                'manual_takeover_by_user_id' => $user?->id,
            ])->save();

            $this->recordEvent(
                company: $company,
                eventType: AutomationEvent::TYPE_MANUAL_TAKEOVER,
                conversation: $conversation,
                user: $user,
                payload: [
                    'reason_present' => $reason !== '',
                    'metadata_keys' => array_keys($metadata),
                ],
                requiresHumanConfirmation: true,
            );

            return $conversation->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function suggestReply(
        Conversation $conversation,
        ?Message $message = null,
        ?User $user = null,
        array $attributes = [],
    ): AiResponseSuggestion {
        return DB::transaction(function () use ($conversation, $message, $user, $attributes): AiResponseSuggestion {
            $conversation = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $company = $conversation->company()->firstOrFail();
            $setting = $this->settingFor($company);
            $message = $message !== null
                ? Message::query()->where('conversation_id', $conversation->id)->findOrFail($message->id)
                : $conversation->messages()->latest('id')->first();

            $result = $setting->automation_enabled
                ? $this->provider->suggestReply(new AiSuggestionContext(
                    company: $company,
                    conversation: $conversation,
                    message: $message,
                    requestedBy: $user,
                    automationMode: $conversation->automation_mode ?? $setting->default_mode,
                    settings: [
                        'provider' => $setting->provider,
                        'allow_auto_send' => $setting->allow_auto_send,
                        'require_human_confirmation_for_ambiguous' => $setting->require_human_confirmation_for_ambiguous,
                        'require_human_confirmation_for_payments' => $setting->require_human_confirmation_for_payments,
                    ],
                    metadata: ['requested_from' => $attributes['requested_from'] ?? 'operational'],
                ))
                : $this->disabledAutomationResult();

            $safety = $this->evaluateSafety($message, $result, $setting);
            $requiresHumanConfirmation = (! $setting->allow_auto_send)
                || $result->requiresHumanConfirmation
                || $safety['requires_human_confirmation'];

            $suggestionType = $safety['suggestion_type'] ?? $result->suggestionType;
            $suggestedText = $safety['suggested_text'] ?? $result->suggestedText;

            $suggestion = AiResponseSuggestion::query()->create([
                'company_id' => $company->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message?->id,
                'requested_by_user_id' => $user?->id,
                'provider' => $this->provider->name(),
                'suggestion_type' => $suggestionType,
                'status' => $requiresHumanConfirmation
                    ? AiResponseSuggestion::STATUS_REQUIRES_HUMAN_CONFIRMATION
                    : AiResponseSuggestion::STATUS_SUGGESTED,
                'prompt_summary' => $attributes['prompt_summary'] ?? $this->promptSummary($message),
                'suggested_text' => $suggestedText,
                'confidence_score' => $result->confidenceScore,
                'requires_human_confirmation' => $requiresHumanConfirmation,
                'ambiguity_reason' => $safety['ambiguity_reason'] ?? $result->ambiguityReason,
                'safety_notes' => $safety['safety_notes'] ?? $result->safetyNotes,
                'metadata' => [
                    'provider_metadata' => $result->metadata,
                    'automation_mode' => $conversation->automation_mode,
                    'auto_send_blocked' => ! $setting->allow_auto_send,
                    'message_present' => $message !== null,
                ],
                'requested_at' => now(),
            ]);

            $conversation->forceFill([
                'human_review_required' => $conversation->human_review_required || $requiresHumanConfirmation,
                'last_ai_suggestion_at' => now(),
            ])->save();

            $this->recordEvent(
                company: $company,
                eventType: AutomationEvent::TYPE_AI_SUGGESTION_CREATED,
                conversation: $conversation,
                message: $message,
                suggestion: $suggestion,
                user: $user,
                payload: [
                    'suggestion_type' => $suggestion->suggestion_type,
                    'suggestion_status' => $suggestion->status,
                    'requires_human_confirmation' => $suggestion->requires_human_confirmation,
                    'ambiguity_reason' => $suggestion->ambiguity_reason,
                    'message_length' => $message !== null ? strlen((string) $message->content) : 0,
                ],
                requiresHumanConfirmation: $requiresHumanConfirmation,
            );

            return $suggestion->refresh();
        });
    }

    public function approveSuggestion(AiResponseSuggestion $suggestion, ?User $user = null): AiResponseSuggestion
    {
        return DB::transaction(function () use ($suggestion, $user): AiResponseSuggestion {
            $suggestion = AiResponseSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            $suggestion->forceFill([
                'status' => AiResponseSuggestion::STATUS_APPROVED,
                'reviewed_by_user_id' => $user?->id,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'rejected_at' => null,
            ])->save();

            $conversation = $suggestion->conversation()->first();

            if ($conversation !== null && ! $conversation->aiResponseSuggestions()
                ->where('status', AiResponseSuggestion::STATUS_REQUIRES_HUMAN_CONFIRMATION)
                ->where('id', '!=', $suggestion->id)
                ->exists()) {
                $conversation->forceFill(['human_review_required' => false])->save();
            }

            $this->recordEvent(
                company: $suggestion->company()->firstOrFail(),
                eventType: AutomationEvent::TYPE_AI_SUGGESTION_APPROVED,
                conversation: $conversation,
                message: $suggestion->message()->first(),
                suggestion: $suggestion,
                user: $user,
                payload: [
                    'suggestion_type' => $suggestion->suggestion_type,
                    'reviewed_by_user_present' => $user !== null,
                ],
                requiresHumanConfirmation: false,
            );

            return $suggestion->refresh();
        });
    }

    public function rejectSuggestion(AiResponseSuggestion $suggestion, ?User $user = null, ?string $reason = null): AiResponseSuggestion
    {
        return DB::transaction(function () use ($suggestion, $user, $reason): AiResponseSuggestion {
            $suggestion = AiResponseSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            $suggestion->forceFill([
                'status' => AiResponseSuggestion::STATUS_REJECTED,
                'reviewed_by_user_id' => $user?->id,
                'reviewed_at' => now(),
                'approved_at' => null,
                'rejected_at' => now(),
            ])->save();

            $this->recordEvent(
                company: $suggestion->company()->firstOrFail(),
                eventType: AutomationEvent::TYPE_AI_SUGGESTION_REJECTED,
                conversation: $suggestion->conversation()->first(),
                message: $suggestion->message()->first(),
                suggestion: $suggestion,
                user: $user,
                payload: [
                    'suggestion_type' => $suggestion->suggestion_type,
                    'reason_present' => $reason !== null && $reason !== '',
                ],
                requiresHumanConfirmation: false,
            );

            return $suggestion->refresh();
        });
    }

    private function settingFor(Company $company): AiAutomationSetting
    {
        return AiAutomationSetting::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'provider' => $this->provider->name(),
            ],
            [
                'default_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
                'automation_enabled' => (bool) config('chatbotcrm.ai.automation_enabled', true),
                'allow_auto_send' => (bool) config('chatbotcrm.ai.allow_auto_send', false),
                'require_human_confirmation_for_ambiguous' => true,
                'require_human_confirmation_for_payments' => true,
                'n8n_webhook_path' => config('chatbotcrm.ai.n8n.webhook_path'),
                'status' => AiAutomationSetting::STATUS_ACTIVE,
                'settings' => [
                    'created_by' => 'ai_automation_service',
                    'external_api_called_on_create' => false,
                ],
            ],
        );
    }

    private function disabledAutomationResult(): AiSuggestionResult
    {
        return new AiSuggestionResult(
            provider: $this->provider->name(),
            suggestedText: 'Automacao de IA desativada. Continue pelo atendimento manual.',
            suggestionType: AiResponseSuggestion::TYPE_REPLY,
            confidenceScore: 0.1,
            requiresHumanConfirmation: true,
            ambiguityReason: 'automation_disabled',
            safetyNotes: 'Sugestao criada sem chamada ao provider.',
            metadata: ['external_api_called' => false],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateSafety(
        ?Message $message,
        AiSuggestionResult $result,
        AiAutomationSetting $setting,
    ): array {
        $normalized = $this->normalize((string) ($message?->content ?? ''));
        $reason = $result->ambiguityReason;

        if ($normalized === '') {
            $reason = $reason ?? 'empty_or_missing_customer_message';
        } elseif ($this->containsAny($normalized, ['igual ontem', 'mesmo de ontem', 'o mesmo', 'de sempre', 'repetir'])) {
            $reason = 'recurring_order_requires_history_confirmation';
        } elseif ($setting->require_human_confirmation_for_payments
            && $this->containsAny($normalized, ['pix', 'comprovante', 'paguei', 'pagamento', 'credito', 'saldo', 'troco', 'valor'])) {
            $reason = 'payment_requires_human_confirmation';
        } elseif ($this->containsAny($normalized, ['entrega', 'endereco', 'taxa', 'retirada', 'retirar', 'buscar', 'terceiro', 'recebe'])) {
            $reason = 'fulfillment_requires_confirmation';
        } elseif ($this->looksLikeShortAmbiguousReply($normalized)) {
            $reason = 'short_ambiguous_reply';
        }

        if ($reason === null && ! $setting->require_human_confirmation_for_ambiguous) {
            return [
                'requires_human_confirmation' => false,
                'safety_notes' => $result->safetyNotes,
            ];
        }

        if ($reason === null) {
            return [
                'requires_human_confirmation' => true,
                'safety_notes' => $result->safetyNotes ?? 'Envio automatico bloqueado por padrao operacional.',
            ];
        }

        return [
            'requires_human_confirmation' => true,
            'suggestion_type' => $this->confirmationTypeFor($reason),
            'suggested_text' => $this->confirmationQuestionFor($reason),
            'ambiguity_reason' => $reason,
            'safety_notes' => 'A IA nao confirmou pedido, pagamento, credito, entrega ou recorrencia automaticamente.',
        ];
    }

    private function confirmationTypeFor(string $reason): string
    {
        return $reason === 'payment_requires_human_confirmation'
            ? AiResponseSuggestion::TYPE_REPLY
            : AiResponseSuggestion::TYPE_CONFIRMATION_QUESTION;
    }

    private function confirmationQuestionFor(string $reason): string
    {
        return match ($reason) {
            'recurring_order_requires_history_confirmation' => 'Para eu confirmar certinho: voce quer repetir o ultimo pedido ou deseja alterar algum item?',
            'payment_requires_human_confirmation' => 'Recebi sua mensagem sobre pagamento. Vou deixar para conferencia humana antes de confirmar.',
            'fulfillment_requires_confirmation' => 'Para evitar erro: voce confirma se sera entrega ou retirada e quem recebe ou retira?',
            'short_ambiguous_reply' => 'Pode confirmar mais um detalhe do pedido para eu registrar corretamente?',
            'n8n_missing_configuration', 'n8n_request_failed', 'n8n_unavailable_or_empty_response' => 'Automacao indisponivel agora. Posso encaminhar para atendimento manual?',
            default => 'Pode confirmar os itens, quantidades e forma de entrega ou retirada antes de finalizar?',
        };
    }

    private function promptSummary(?Message $message): string
    {
        if ($message === null) {
            return 'Solicitacao de sugestao sem mensagem vinculada.';
        }

        return 'Mensagem do cliente com '.strlen((string) $message->content).' caracteres; conteudo bruto nao registrado no resumo.';
    }

    private function recordEvent(
        Company $company,
        string $eventType,
        ?Conversation $conversation = null,
        ?Message $message = null,
        ?AiResponseSuggestion $suggestion = null,
        ?User $user = null,
        array $payload = [],
        bool $requiresHumanConfirmation = false,
    ): AutomationEvent {
        $event = AutomationEvent::query()->create([
            'company_id' => $company->id,
            'conversation_id' => $conversation?->id,
            'message_id' => $message?->id,
            'ai_response_suggestion_id' => $suggestion?->id,
            'created_by_user_id' => $user?->id,
            'provider' => $this->provider->name(),
            'event_type' => $eventType,
            'status' => AutomationEvent::STATUS_RECORDED,
            'requires_human_confirmation' => $requiresHumanConfirmation,
            'payload' => $this->safePayload($payload),
        ]);

        $dispatch = $this->provider->dispatchAutomationEvent($eventType, $event->payload ?? []);

        if (! $dispatch->skipped()) {
            $event->forceFill([
                'status' => $dispatch->successful()
                    ? AutomationEvent::STATUS_DISPATCHED
                    : AutomationEvent::STATUS_FAILED,
                'response_payload' => $dispatch->safePayload,
                'error_message' => $dispatch->errorMessage,
                'dispatched_at' => $dispatch->successful() ? now() : null,
                'processed_at' => now(),
            ])->save();
        } else {
            $event->forceFill([
                'response_payload' => $dispatch->safePayload,
                'processed_at' => now(),
            ])->save();
        }

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safePayload(array $payload): array
    {
        return collect($payload)
            ->map(fn (mixed $value): mixed => is_string($value) ? Str::limit($value, 120, '') : $value)
            ->all();
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

    private function looksLikeShortAmbiguousReply(string $normalized): bool
    {
        return in_array($normalized, ['sim', 'nao', 'ok', 'pode', 'quero', 'isso'], true);
    }
}
