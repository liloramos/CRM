<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Services\Operational\CompanyOperatingHoursService;
use Carbon\CarbonImmutable;

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
    ) {}

    /** @return array<string, mixed> */
    public function analyze(Conversation $conversation): array
    {
        $conversation->loadMissing(['company', 'customer', 'activeOrder']);
        $context = $this->contextBuilder->forConversation($conversation);
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
        $intent = $this->latestIntent->resolve($context);
        $context['latest_intent'] = $intent;
        try {
            $safe = $intent === 'ORDER_CREATE'
                && data_get($context, 'operational_status.status') === CompanyOperatingHoursService::STATUS_CLOSED
                ? $this->deterministicAnalysis($this->businessHours->closedOrder(
                    $conversation->company,
                    (array) data_get($context, 'operational_status', []),
                ))
                : ($this->latestIntent->isBareOrderStartRequest($context)
                ? $this->deterministicAnalysis($this->customerReplies->orderStart())
                : ($this->hasResolvedPendingMeatClarification($context)
                ? $this->pipeline->resolvePendingMeatClarification($conversation->company, $context)
                : match ($intent) {
                    'MENU_REQUEST' => $this->deterministicAnalysis($this->menuReplies->build(
                        $conversation->company,
                        CarbonImmutable::parse((string) $context['evaluation_date']),
                        (string) data_get($context, 'latest_message.body', ''),
                        (array) data_get($context, 'operational_status', []),
                    )),
                    'PRODUCT_CLARIFICATION' => $this->deterministicAnalysis($this->productReplies->build($conversation->company, CarbonImmutable::parse((string) $context['evaluation_date']), (string) data_get($context, 'latest_message.body', ''))),
                    'BUSINESS_HOURS_REQUEST' => $this->deterministicAnalysis($this->businessHours->build($conversation->company)),
                    'LOCATION_REQUEST' => $this->deterministicAnalysis($this->customerReplies->restaurantLocation($conversation->company)),
                    'PAYMENT_QUESTION' => $this->deterministicAnalysis($this->customerReplies->paymentKey($conversation->company)),
                    'DELIVERY_QUESTION' => $this->deterministicAnalysis($this->customerReplies->deliveryFee()),
                    default => $this->pipeline->analyze($conversation->company, $context)['safe'],
                }));
            // The resolver supplies the latest conversational intent, but a safety finalizer
            // may deliberately downgrade an untrusted request to UNKNOWN.
            if ((string) ($safe['intent'] ?? '') !== 'UNKNOWN') {
                $safe['intent'] = $intent;
            }
            $safe = $this->deltas->restrict($conversation->company, $safe, $context);
            $safe = $this->suggestedReplies->restrict($safe, $context);
            $safe['metadata'] = [...(array) ($safe['metadata'] ?? []), ...$this->clarificationContinuity($context, $intent)];

            return [...$safe, 'proposal' => $this->proposals->present($conversation, $safe, $context)];
        } catch (\Throwable $exception) {
            $safe = $this->normalizer->normalize(
                ['intent' => 'UNKNOWN', 'warnings' => [['code' => 'PROVIDER_UNAVAILABLE', 'message' => 'Nao foi possivel analisar agora.']]],
                'unknown',
                ['error_code' => $exception->getMessage()],
            )->toArray();

            return [...$safe, 'proposal' => $this->proposals->present($conversation, $safe)];
        }
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

        if (in_array($intent, ['MENU_REQUEST', 'PRODUCT_CLARIFICATION', 'BUSINESS_HOURS_REQUEST', 'LOCATION_REQUEST'], true)) {
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
}
