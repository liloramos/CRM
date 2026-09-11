<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Services\Operational\CompanyOperatingHoursService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ConversationCopilotService
{
    public function __construct(
        private readonly ConversationCopilotContextBuilder $contextBuilder,
        private readonly ConversationCopilotPipeline $pipeline,
        private readonly ConversationCopilotNormalizer $normalizer,
        private readonly CopilotOrderProposalPresenter $proposals,
        private readonly CopilotProposalDeltaGuard $deltas,
        private readonly CopilotSuggestedReplyGuard $suggestedReplies,
        private readonly CopilotLatestMessageIntentResolver $latestIntent,
        private readonly CopilotMenuReplyBuilder $menuReplies,
        private readonly CopilotProductClarificationReplyBuilder $productReplies,
        private readonly CopilotBusinessHoursReplyBuilder $businessHours,
        private readonly CopilotCustomerFacingReplyBuilder $customerReplies,
        private readonly CopilotOrderClarificationReplyBuilder $orderClarifications,
        private readonly CopilotSemanticReplyBuilder $semanticReplies,
        private readonly CopilotTurnStateReducer $turnState,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(Conversation $conversation, ?Message $triggerInbound = null): array
    {
        $conversation->loadMissing(['company', 'customer', 'activeOrder']);
        $context = $this->contextBuilder->forConversation($conversation, $triggerInbound);
        if (! data_get($context, 'has_current_inbound_message', false)) {
            $safe = $this->normalizer->normalize(
                [
                    'intent' => 'UNKNOWN',
                    'summary' => 'Nenhuma nova mensagem para analisar.',
                    'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
                    'missing_information' => [],
                    'warnings' => [],
                    'suggested_reply' => '',
                ],
                'internal',
                ['reply_source' => 'no_new_inbound_message', 'no_new_inbound_message' => true],
            )->toArray();

            $proposal = $this->proposals->present($conversation, $safe, $context);
            $proposal['target'] = [
                'state' => 'UNAVAILABLE',
                'requires_human_selection' => false,
                'choices' => [],
                'default_choice' => null,
                'active_order' => null,
            ];
            $proposal['blocking_reasons'] = ['Nenhuma nova mensagem para analisar.'];

            return [...$safe, 'proposal' => $proposal];
        }
        $safe = $this->decorateResolvedTurn($this->analyzeContext($conversation->company, $context), $context, (int) $conversation->id);

        return [...$safe, 'proposal' => $this->proposals->present($conversation, $safe, $context)];
    }

    /** @return array<string,mixed> */
    public function analyzeMessages(Company $company, array $messages): array
    {
        $context = $this->contextBuilder->forMessages($company, $messages);
        $safe = $this->decorateResolvedTurn($this->analyzeContext($company, $context), $context, null);

        return [...$safe, 'proposal' => $this->proposals->presentForSandbox($company, $safe, $context)];
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function analyzeContext(Company $company, array $context): array
    {
        $intent = $this->latestIntent->resolve($context);
        $evaluationDate = CarbonImmutable::parse((string) $context['evaluation_date']);
        $context['latest_intent'] = $intent;
        $semantic = null;
        $semanticFailure = false;
        $semanticError = null;
        $semanticAttempted = false;
        $semanticException = null;
        $semanticDecision = function () use ($company, $context, $evaluationDate, &$semantic, &$semanticAttempted, &$semanticException): array {
            if (! $semanticAttempted) {
                $semanticAttempted = true;
                try {
                    $semantic = $this->pipeline->analyze($company, $context, $evaluationDate)['safe'];
                } catch (\Throwable $exception) {
                    $semanticException = $exception;
                }
            }
            if ($semanticException instanceof \Throwable) {
                throw $semanticException;
            }

            return is_array($semantic) ? $semantic : [];
        };
        $stateRequiresInterpretation = $this->hasPendingOrderState($context)
            && ! in_array($intent, [
                'GREETING', 'MENU_REQUEST', 'PRODUCT_CLARIFICATION', 'BUSINESS_HOURS_REQUEST',
                'LOCATION_REQUEST', 'PAYMENT_QUESTION', 'PAYMENT_CONFIRMATION', 'DELIVERY_QUESTION', 'ORDER_STATUS',
            ], true);
        if ($stateRequiresInterpretation || $this->latestIntent->shouldInterpretBeforeDeterministic($intent, $context)) {
            try {
                // Interpretation happens before generic keyword-driven branches.
                // Canonical builders below may still own the final facts/reply.
                $semantic = $semanticDecision();
            } catch (\Throwable $exception) {
                $semanticFailure = true;
                $semanticError = $exception->getMessage();
            }
        }
        $handoffReason = $this->latestIntent->handoffReason($context);
        $candidateClarification = $intent === 'ORDER_CREATE'
            ? $this->orderClarifications->buildCandidateClarification($context)
            : null;
        $productCompatibility = $intent === 'ORDER_CREATE'
            ? $this->orderClarifications->buildProductCompatibility($context)
            : null;
        $paymentSelection = ($intent === 'ORDER_CONTINUE'
            || data_get($context, 'pending_order_state.next_objective') === 'ASK_PAYMENT_METHOD')
            ? $this->customerReplies->activeOrderPaymentSelection($company, $context)
            : null;
        $orderClarification = $intent === 'ORDER_CREATE' && ! $this->latestIntent->isBareOrderStartRequest($context)
            ? $this->orderClarifications->build($context)
            : null;
        try {
            if ($intent === 'ORDER_CREATE'
                && data_get($context, 'operational_status.status') === CompanyOperatingHoursService::STATUS_CLOSED) {
                $safe = $this->deterministicAnalysis($this->businessHours->closedOrder(
                    $company,
                    (array) data_get($context, 'operational_status', []),
                ));
            } elseif ($handoffReason !== null) {
                $safe = $this->deterministicAnalysis($this->customerReplies->handoff($handoffReason));
            } elseif ($candidateClarification !== null) {
                $candidateSafe = data_get($candidateClarification, 'metadata.clarification_kind') === 'candidate_narrowed'
                    ? $this->pipeline->validateDeterministic($company, $candidateClarification, $context, $evaluationDate)
                    : $this->deterministicAnalysis($candidateClarification);
                $safe = $this->withSemanticInterpretation($candidateSafe, $semantic, $semanticFailure);
            } elseif ($productCompatibility !== null) {
                $safe = $this->withSemanticInterpretation($this->deterministicAnalysis($productCompatibility), $semantic, $semanticFailure);
            } elseif ($paymentSelection !== null) {
                $safe = $this->deterministicAnalysis($paymentSelection);
            } elseif ($orderClarification !== null) {
                $safe = $this->withSemanticInterpretation($this->deterministicAnalysis($orderClarification), $semantic, $semanticFailure);
            } elseif ($this->latestIntent->isBareOrderStartRequest($context)) {
                $safe = $this->deterministicAnalysis($this->customerReplies->orderStart());
            } elseif ($this->hasResolvedPendingMeatClarification($context)) {
                $safe = $this->pipeline->resolvePendingMeatClarification($company, $context, $evaluationDate);
            } elseif (is_array($semantic)) {
                $safe = $semantic;
            } else {
                $safe = match ($intent) {
                    'GREETING' => $this->deterministicAnalysis($this->customerReplies->greeting()),
                    'MENU_REQUEST' => $this->deterministicAnalysis($this->menuReplies->build(
                        $company,
                        $evaluationDate,
                        (string) data_get($context, 'latest_message.body', ''),
                        (array) data_get($context, 'operational_status', []),
                    )),
                    'PRODUCT_CLARIFICATION' => $this->deterministicAnalysis($this->productReplies->build($company, $evaluationDate, (string) data_get($context, 'latest_message.body', ''), $context)),
                    'BUSINESS_HOURS_REQUEST' => $this->deterministicAnalysis($this->businessHours->build($company)),
                    'LOCATION_REQUEST' => $this->deterministicAnalysis($this->customerReplies->restaurantLocation($company)),
                    'PAYMENT_QUESTION' => $this->deterministicAnalysis($this->customerReplies->paymentKey($company)),
                    'PAYMENT_CONFIRMATION' => $this->deterministicAnalysis($this->customerReplies->paymentConfirmationRequired()),
                    'DELIVERY_QUESTION' => $this->deterministicAnalysis($this->customerReplies->deliveryFee()),
                    'ORDER_STATUS' => $this->deterministicAnalysis($this->customerReplies->pendingOrderTotal($context)),
                    'ORDER_CONTINUE' => data_get($context, 'latest_message.type') === 'location'
                        ? $this->deterministicAnalysis($this->customerReplies->customerLocationReceived($context))
                        : $semanticDecision(),
                    default => $semanticDecision(),
                };
            }
            $safe = $this->semanticReplies->ground($safe, $context);
            // The resolver supplies the latest conversational intent, but a safety finalizer
            // may deliberately downgrade an untrusted request to UNKNOWN.
            if ((string) ($safe['intent'] ?? '') !== 'UNKNOWN'
                && $intent !== 'GENERAL_MESSAGE'
                && data_get($safe, 'metadata.semantic_delta_validated') !== true
                && data_get($safe, 'metadata.semantic_pending_slot_rejected') !== true
                && data_get($safe, 'metadata.semantic_read_only') !== true) {
                $safe['intent'] = $intent;
            }
            if ((string) ($safe['intent'] ?? '') === 'HUMAN_REQUEST'
                && data_get($safe, 'metadata.reply_source') !== 'explicit_handoff') {
                $safe = $this->deterministicAnalysis($this->customerReplies->handoff($handoffReason ?? 'out_of_domain'));
            }
            if ((string) ($safe['intent'] ?? '') === 'UNKNOWN'
                && ! $this->latestIntent->hasUntrustedInstruction($context)
                && $this->isRecoverableUnknown($safe)) {
                $validationWarnings = (array) ($safe['warnings'] ?? []);
                $semanticMetadata = array_filter([
                    'semantic_interpretation' => data_get($safe, 'metadata.semantic_interpretation'),
                    'provider_interpretation_called' => data_get($safe, 'metadata.provider_interpretation_called'),
                ], fn (mixed $value): bool => $value !== null);
                $safe = $this->deterministicAnalysis($this->hasPendingOrderState($context)
                    ? $this->customerReplies->contextualRecoveryClarification($context)
                    : ($this->hasRecoveryClarification($context)
                        ? $this->customerReplies->handoff('irreparable_message')
                        : $this->customerReplies->recoveryClarification()));
                $safe['warnings'] = collect([
                    ...$validationWarnings,
                    ...(array) ($safe['warnings'] ?? []),
                ])->filter(fn (mixed $warning): bool => is_array($warning))
                    ->unique(fn (array $warning): string => strtoupper((string) ($warning['code'] ?? '')).'|'.(string) ($warning['message'] ?? ''))
                    ->values()
                    ->all();
                $safe['metadata'] = [...(array) ($safe['metadata'] ?? []), ...$semanticMetadata];
            }
            $safe = $this->deltas->restrict($company, $safe, $context);
            $safe = $this->suggestedReplies->restrict($safe, $context);
            $safe = $this->appendSupplementalMenuReply($company, $evaluationDate, $context, $intent, $safe);
            $safe = $this->canonicalPaymentReply($company, $safe);
            $safe = $this->progressiveReplyMessages($safe);
            $safe['metadata'] = [
                ...(array) ($safe['metadata'] ?? []),
                ...$this->clarificationContinuity($context, $intent),
                'pending_customer_location' => data_get($context, 'pending_order_state.customer_location'),
                ...($semanticError === null ? [] : ['semantic_interpretation_error' => $semanticError]),
            ];
            $assistantGoal = $this->assistantGoal($safe, $context);
            if ($assistantGoal !== null) {
                $safe['metadata']['assistant_goal'] = $assistantGoal;
            }

            return $safe;
        } catch (\Throwable $exception) {
            $fallback = $this->customerReplies->recoveryClarification();
            $fallback['warnings'] = [[
                'code' => 'PROVIDER_UNAVAILABLE',
                'message' => 'Nao foi possivel concluir a interpretacao semantica neste turno.',
            ]];

            return $this->normalizer->normalize(
                $fallback,
                'unknown',
                [
                    ...(array) ($fallback['metadata'] ?? []),
                    'provider_failure' => true,
                    'error_code' => $exception->getMessage(),
                ],
            )->toArray();
        }
    }

    /** @param array<string,mixed> $context */
    private function hasRecoveryClarification(array $context): bool
    {
        return collect(data_get($context, 'messages', []))
            ->slice(0, -1)
            ->contains(fn (mixed $message): bool => is_array($message)
                && ($message['direction'] ?? null) === 'outbound'
                && str_contains(
                    mb_strtolower((string) ($message['body'] ?? '')),
                    'não consegui entender direitinho',
                ));
    }

    /** @param array<string,mixed> $analysis */
    private function isRecoverableUnknown(array $analysis): bool
    {
        $unsafeCodes = ['INVALID_PROVIDER_OUTPUT', 'PROVIDER_UNAVAILABLE', 'UNTRUSTED_INSTRUCTION'];
        $warningCodes = collect((array) ($analysis['warnings'] ?? []))
            ->map(fn (mixed $warning): string => strtoupper((string) data_get($warning, 'code', '')))
            ->filter();

        return $warningCodes->intersect($unsafeCodes)->isEmpty();
    }

    /** @param array<string,mixed> $context */
    private function hasPendingOrderState(array $context): bool
    {
        return (array) data_get($context, 'pending_order_state.draft_order.items', []) !== []
            || is_array(data_get($context, 'active_order'));
    }

    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    private function deterministicAnalysis(array $analysis): array
    {
        return $this->normalizer->normalize(
            $analysis,
            'deterministic',
            is_array($analysis['metadata'] ?? null) ? $analysis['metadata'] : [],
        )->toArray();
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed> */
    private function decorateResolvedTurn(array $safe, array $context, ?int $conversationId): array
    {
        $readOnly = data_get($safe, 'metadata.semantic_read_only') === true
            || collect((array) data_get($safe, 'metadata.semantic_interpretation.intents', []))
                ->intersect(['ask_product_information', 'compare_products', 'ask_pending_slot_options'])
                ->isNotEmpty()
            || (in_array((string) ($safe['intent'] ?? ''), [
                'MENU_REQUEST', 'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST', 'PRODUCT_CLARIFICATION',
                'PAYMENT_QUESTION', 'DELIVERY_QUESTION', 'ORDER_STATUS', 'GENERAL_QUESTION',
            ], true) && in_array((string) data_get($safe, 'metadata.reply_source', ''), [
                'daily_menu', 'product_catalog', 'operating_hours', 'operating_hours_unconfigured',
                'customer_facing_policy', 'semantic_grounded_product_information',
                'semantic_grounded_comparison', 'semantic_grounded_options',
            ], true));
        $orderContext = $this->turnState->reduce($safe, $context);
        if ($readOnly) {
            // Read-only turns keep the canonical order exclusively in the turn
            // envelope. The outward analysis must not look like an order mutation.
            $safe['draft_order'] = ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''];
        } elseif (is_array(data_get($orderContext, 'draft_order'))) {
            $safe['draft_order'] = data_get($orderContext, 'draft_order');
        }
        $safe['draft_order'] = is_array($safe['draft_order'] ?? null)
            ? ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => '', ...$safe['draft_order']]
            : ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''];
        $requiredMissing = array_values((array) data_get($orderContext, 'required_missing_slots', []));
        $existingMissing = collect((array) ($safe['missing_information'] ?? []))->keyBy(
            fn (mixed $entry): string => strtoupper((string) data_get($entry, 'code', '')),
        );
        if (! $readOnly) {
            $safe['missing_information'] = collect($requiredMissing)
                ->map(function (mixed $code) use ($existingMissing): array {
                    $canonicalCode = strtoupper(trim((string) $code));
                    $existing = $existingMissing->get($canonicalCode);

                    return is_array($existing)
                        ? $existing
                        : ['code' => $canonicalCode, 'label' => Str::of($canonicalCode)->lower()->replace('_', ' ')->ucfirst()->toString()];
                })
                ->values()
                ->all();
        }
        $previousObjective = (string) data_get($context, 'pending_order_state.next_objective', '');
        $confirmation = (string) data_get($safe, 'metadata.semantic_interpretation.confirmation', 'unknown');
        if ($previousObjective === 'ASK_PAYMENT_METHOD'
            && $orderContext['next_objective'] === 'WAIT_PAYMENT_PROOF'
            && is_array(data_get($context, 'active_order'))
            && mb_strtolower(trim((string) data_get($safe, 'draft_order.payment_method', ''))) === 'pix'
            && data_get($safe, 'metadata.pix_configured') === true) {
            $safe['metadata'] = [
                ...(array) ($safe['metadata'] ?? []),
                'reply_source' => 'payment_selection',
                'active_order_id' => (int) data_get($context, 'active_order.id'),
                'payment_method' => 'pix',
            ];
        }
        if ((string) ($safe['intent'] ?? '') === 'UNKNOWN'
            && in_array($previousObjective, ['CONFIRM_ITEM', 'ASK_MORE_ITEMS'], true)
            && in_array($confirmation, ['yes', 'no'], true)
            && $orderContext['next_objective'] !== $previousObjective) {
            $safe['intent'] = 'ORDER_CONTINUE';
            $safe['warnings'] = array_values(array_filter(
                (array) ($safe['warnings'] ?? []),
                fn (mixed $warning): bool => strtoupper((string) data_get($warning, 'code', '')) !== 'MESSAGE_NOT_UNDERSTOOD',
            ));
            $safe['metadata'] = [...(array) ($safe['metadata'] ?? []), 'reply_source' => 'turn_loop_objective'];
        }
        $safe = $this->orderClarifications->continueFromObjective($safe, $orderContext, $context);
        $safe['metadata'] = [
            ...(array) ($safe['metadata'] ?? []),
            'assistant_goal' => $orderContext['assistant_goal'],
            'order_context' => $orderContext,
            'next_objective' => $orderContext['next_objective'],
            'phase' => $orderContext['phase'],
        ];
        $stateBefore = data_get($context, 'pending_order_state.draft_order');
        $candidateAfter = (array) ($safe['draft_order'] ?? []);
        $stateAfter = data_get($orderContext, 'draft_order');
        $stateDelta = $readOnly || ! is_array($stateAfter)
            ? []
            : $this->stateDelta(is_array($stateBefore) ? $stateBefore : [], $stateAfter);
        $constraints = array_values((array) data_get($safe, 'metadata.constraints', []));
        $assistantGoal = data_get($orderContext, 'assistant_goal');
        $nextObjective = (string) data_get($orderContext, 'next_objective', '');
        $nextAction = $nextObjective === 'RESOLVE_CONSTRAINT'
            ? 'explain_constraint'
            : ($nextObjective === '' ? 'answer_information' : strtolower($nextObjective));
        $latestId = (int) data_get($context, 'trigger_message.id', data_get($context, 'latest_message.id', 0));
        $externalMessageId = trim((string) data_get($context, 'trigger_message.external_message_id', data_get($context, 'latest_message.external_message_id', '')));
        $triggerMessageIds = $externalMessageId !== '' ? [$externalMessageId] : ($latestId > 0 ? [(string) $latestId] : []);
        $providerCalled = data_get($safe, 'metadata.provider_interpretation_called') === true;
        $backendReplySources = [
            'daily_menu', 'product_catalog', 'order_clarification', 'resolved_turn_product_discovery',
            'semantic_grounded_product_information', 'semantic_grounded_comparison', 'semantic_grounded_options',
            'deterministic_greeting', 'customer_facing_policy', 'operating_hours', 'operating_hours_unconfigured',
        ];
        $providerNaturalizer = $providerCalled
            && data_get($safe, 'metadata.reply_composed_by') !== 'backend'
            && ! in_array((string) data_get($safe, 'metadata.reply_source', ''), $backendReplySources, true);
        $resolvedTurn = [
            'customer_goal' => (string) data_get($safe, 'metadata.semantic_interpretation.reply_goal', $safe['intent'] ?? 'UNKNOWN'),
            'state_summary' => $stateAfter,
            'information_answers' => array_values(array_intersect(
                (array) data_get($safe, 'metadata.semantic_interpretation.intents', []),
                ['ask_product_information', 'compare_products', 'ask_pending_slot_options'],
            )),
            'canonical_facts' => [
                'product_ids' => array_values(array_filter(array_map('intval', (array) data_get($safe, 'metadata.conversation_references.*.product_id', [])))),
                'daily_meats' => array_values((array) data_get($context, 'daily_meats', [])),
            ],
            'applied_changes' => $stateDelta,
            'constraints' => $constraints,
            'next_question' => $assistantGoal,
            'phase' => $orderContext['phase'],
            'next_objective' => $orderContext['next_objective'],
            'prohibited_claims' => array_values((array) data_get($context, 'authority.prohibited', [])),
        ];
        $trace = [
            'turn_id' => ($conversationId === null ? 'sandbox' : 'conversation:'.$conversationId).':inbound:'.($latestId ?: 'unknown'),
            'trigger_message_ids' => $triggerMessageIds,
            'trigger_message_record_ids' => $latestId > 0 ? [$latestId] : [],
            'state_before' => $stateBefore,
            'pending_slot_before' => data_get($context, 'conversation_frame.last_assistant_goal.slot'),
            'semantic_result' => data_get($safe, 'metadata.semantic_interpretation'),
            'semantic_error' => data_get($safe, 'metadata.semantic_interpretation_error'),
            'canonical_resolution' => [
                'intent' => $safe['intent'] ?? 'UNKNOWN',
                'validated' => data_get($safe, 'metadata.semantic_delta_validated') === true,
                'reply_source' => data_get($safe, 'metadata.reply_source'),
            ],
            'state_delta' => $stateDelta,
            'constraints' => $constraints,
            'state_after' => $stateAfter,
            'next_action' => $nextAction,
            'provider_interpretation_called' => $providerCalled,
            'provider_naturalizer_called' => $providerNaturalizer,
            'reply' => array_values((array) ($safe['reply_messages'] ?? [])),
            'review_reason' => null,
            'stale_discarded' => false,
        ];
        $turnEnvelope = [
            'conversation_id' => $conversationId,
            'trigger_inbound_message_id' => $latestId > 0 ? $latestId : null,
            'trigger_external_message_id' => $externalMessageId !== '' ? $externalMessageId : null,
            'trigger_occurred_at' => data_get($context, 'trigger_message.occurred_at', data_get($context, 'latest_message.occurred_at')),
            'previous_order_context' => data_get($context, 'pending_order_state'),
            'semantic_result' => data_get($safe, 'metadata.semantic_interpretation'),
            'validated_delta' => $stateDelta,
            'canonical_state' => $stateAfter,
            'phase' => $orderContext['phase'],
            'next_objective' => $orderContext['next_objective'],
            'information_response' => data_get($safe, 'metadata.information_response'),
            'reply_composer' => data_get($safe, 'metadata.final_reply_composer'),
            'final_reply_messages' => array_values((array) ($safe['reply_messages'] ?? [])),
            'authority_decision' => null,
        ];

        return [
            ...$safe,
            'metadata' => [
                ...(array) ($safe['metadata'] ?? []),
                'resolved_turn' => $resolvedTurn,
                'turn_trace' => $trace,
                'turn_envelope' => $turnEnvelope,
                'provider_naturalizer_called' => $providerNaturalizer,
                'order_context' => $orderContext,
            ],
        ];
    }

    /** @param array<string|int,mixed> $before @param array<string|int,mixed> $after @return array<string|int,mixed> */
    private function stateDelta(array $before, array $after): array
    {
        if (array_is_list($before) || array_is_list($after)) {
            return $before === $after ? [] : $after;
        }

        $delta = [];
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before)) {
                $delta[$key] = $value;

                continue;
            }
            $previous = $before[$key];
            if (is_array($previous) && is_array($value)) {
                $nested = $this->stateDelta($previous, $value);
                if ($nested !== []) {
                    $delta[$key] = $nested;
                }
            } elseif ($previous !== $value) {
                $delta[$key] = $value;
            }
        }

        return $delta;
    }

    /** @param array<string,mixed> $safe @param array<string,mixed>|null $semantic @return array<string,mixed> */
    private function withSemanticInterpretation(array $safe, ?array $semantic, bool $providerFailure): array
    {
        $interpretation = is_array($semantic) ? data_get($semantic, 'metadata.semantic_interpretation') : null;

        return [
            ...$safe,
            'metadata' => [
                ...(array) ($safe['metadata'] ?? []),
                ...(is_array($interpretation) ? ['semantic_interpretation' => $interpretation, 'provider_interpretation_called' => true] : []),
                ...($providerFailure ? ['provider_interpretation_called' => true, 'provider_failure' => true] : []),
            ],
        ];
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $safe @return array<string,mixed> */
    private function appendSupplementalMenuReply(Company $company, CarbonImmutable $date, array $context, string $intent, array $safe): array
    {
        $pendingItems = (array) data_get($context, 'pending_order_state.draft_order.items', []);
        if (! in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE'], true)
            || ! $this->latestIntent->hasMenuRequest($context)
            || (data_get($safe, 'draft_order.items', []) === []
                && $pendingItems === []
                && data_get($safe, 'metadata.product_candidate') === null)) {
            return $safe;
        }

        $supplement = $this->menuReplies->buildSupplemental(
            $company,
            $date,
            (string) data_get($context, 'latest_message.body', ''),
        );
        $information = trim((string) ($supplement['suggested_reply'] ?? ''));
        if ($information === '') {
            return $safe;
        }

        $stateItems = (array) data_get($safe, 'draft_order.items', []);
        if ($stateItems === []) {
            $stateItems = $pendingItems;
        }
        $productIds = collect($stateItems)
            ->pluck('menu_item_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $names = Product::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $productIds)
            ->pluck('name', 'id');
        $selected = collect($stateItems)
            ->map(function (mixed $item) use ($names): ?string {
                if (! is_array($item)) {
                    return null;
                }

                $name = trim((string) $names->get((int) ($item['menu_item_id'] ?? 0), ''));

                return $name === '' ? null : max(1, (int) ($item['quantity'] ?? 1)).'x '.$name;
            })
            ->filter()
            ->implode(', ');
        $opening = ($selected === '' ? '' : "Seu pedido continua com *{$selected}*. 😊\n\n").$information;
        $continuations = collect();
        if (data_get($safe, 'metadata.semantic_read_only') !== true) {
            $continuations = collect((array) ($safe['reply_messages'] ?? []))
                ->filter(fn (mixed $message): bool => is_string($message) && trim($message) !== '')
                ->map(fn (string $message): string => trim($message));
            if ($continuations->isEmpty() && trim((string) ($safe['suggested_reply'] ?? '')) !== '') {
                $continuations->push(trim((string) $safe['suggested_reply']));
            }
        }
        $messages = collect([$opening])
            ->merge($continuations)
            ->unique()
            ->take(3)
            ->values()
            ->all();

        return [
            ...$safe,
            'suggested_reply' => implode("\n\n", $messages),
            'reply_messages' => $messages,
            'metadata' => [
                ...(array) ($safe['metadata'] ?? []),
                'reply_source' => 'daily_menu',
                'supplemental_intents' => ['MENU_REQUEST'],
                'supplemental_reply_source' => data_get($supplement, 'metadata.reply_source'),
            ],
        ];
    }

    /** @param array<string,mixed> $context */
    private function pendingOrderContinuation(array $context): string
    {
        $slot = (string) data_get($context, 'conversation_frame.last_assistant_goal.slot', '');

        return match ($slot) {
            'carne' => 'Para continuar, qual carne você deseja?',
            'salada' => 'Para continuar, qual salada você deseja?',
            'fulfillment' => 'Para continuar, vai ser para retirada ou entrega?',
            'address' => 'Para continuar, qual é o endereço da entrega?',
            'payment_method' => 'Para continuar, como você prefere pagar?',
            default => '',
        };
    }

    /** @param array<string,mixed> $safe @return array<string,mixed> */
    private function canonicalPaymentReply(Company $company, array $safe): array
    {
        if (mb_strtolower(trim((string) data_get($safe, 'draft_order.payment_method', ''))) !== 'pix') {
            return $safe;
        }

        $payment = $this->deterministicAnalysis($this->customerReplies->paymentKey($company));
        $summary = trim((string) ($safe['suggested_reply'] ?? ''));
        $messages = collect([$summary, ...(array) ($payment['reply_messages'] ?: [$payment['suggested_reply']])])
            ->filter(fn (mixed $message): bool => is_string($message) && trim($message) !== '')
            ->unique()
            ->take(3)
            ->values()
            ->all();

        return [
            ...$safe,
            'suggested_reply' => implode(' ', $messages),
            'reply_messages' => $messages,
            'metadata' => [...(array) ($safe['metadata'] ?? []), 'pix_configured' => data_get($payment, 'metadata.pix_configured')],
        ];
    }

    /** @param array<string,mixed> $safe @return array<string,mixed> */
    private function progressiveReplyMessages(array $safe): array
    {
        if (count((array) ($safe['reply_messages'] ?? [])) > 1
            || data_get($safe, 'draft_order.items', []) === []
            || filled(data_get($safe, 'draft_order.fulfillment'))
            || ! empty($safe['missing_information'])
            || ! empty($safe['warnings'])) {
            return $safe;
        }

        $reply = trim((string) ($safe['suggested_reply'] ?? ''));
        if (preg_match('/^(.*?)\.\s+(Vai ser para retirada ou entrega\?.*)$/iu', $reply, $parts) !== 1) {
            return $safe;
        }

        return [...$safe, 'reply_messages' => [trim($parts[1]).'.', trim($parts[2])]];
    }

    /** @param array<string,mixed> $context */
    private function hasResolvedPendingMeatClarification(array $context): bool
    {
        return data_get($context, 'pending_clarification.status') === 'eligible'
            && data_get($context, 'pending_clarification.type') === 'ambiguous_meat'
            && data_get($context, 'pending_clarification.resolution.status') === 'resolved'
            && (int) data_get($context, 'pending_clarification.resolution.component_id') > 0;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function clarificationContinuity(array $context, string $intent): array
    {
        $pending = data_get($context, 'pending_clarification');
        if (! is_array($pending) || ! isset($pending['source_event_id'])) {
            return [];
        }

        if (in_array($intent, ['MENU_REQUEST', 'PRODUCT_CLARIFICATION', 'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST', 'PAYMENT_QUESTION', 'PAYMENT_CONFIRMATION', 'DELIVERY_QUESTION'], true)) {
            return [];
        }

        $resolution = (string) data_get($pending, 'resolution.status', 'stale');
        if ($intent === 'ORDER_CREATE') {
            $resolution = 'superseded';
        }

        return ['clarification_continuity' => [
            'source_event_id' => (int) $pending['source_event_id'],
            'resolution' => $resolution,
            'matched_option_id' => $resolution === 'resolved' ? (int) data_get($pending, 'resolution.component_id') : null,
        ]];
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed>|null */
    private function assistantGoal(array $safe, array $context): ?array
    {
        $existing = data_get($safe, 'metadata.assistant_goal');
        if (is_array($existing) && filled($existing['slot'] ?? null)) {
            return [
                'type' => (string) ($existing['type'] ?? 'choose_option'),
                'slot' => (string) $existing['slot'],
                'product_id' => (int) ($existing['product_id'] ?? 0) ?: null,
            ];
        }

        $code = strtoupper((string) data_get($safe, 'missing_information.0.code', ''));
        $slot = match ($code) {
            'CARNE' => 'carne',
            'SALADA' => 'salada',
            'PAYMENT_METHOD' => 'payment_method',
            'ADDRESS' => 'address',
            'N8_VARIANT' => 'product_variant',
            'PRODUCT', 'MENU_ITEM' => 'product',
            'SABOR' => 'sabor',
            'ACOMPANHAMENTO' => 'acompanhamento',
            default => null,
        };
        if ($slot === null
            && data_get($safe, 'draft_order.items', []) !== []
            && blank(data_get($safe, 'draft_order.fulfillment'))) {
            $slot = 'fulfillment';
        }
        if ($slot === null) {
            return null;
        }

        return [
            'type' => $slot === 'address' ? 'provide_value' : 'choose_option',
            'slot' => $slot,
            'product_id' => (int) data_get($safe, 'draft_order.items.0.menu_item_id', data_get($context, 'pending_order_state.draft_order.items.0.menu_item_id')) ?: null,
        ];
    }
}
