<?php

namespace App\Services\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\Company;
use Carbon\CarbonInterface;

final class ConversationCopilotPipeline
{
    public function __construct(
        private readonly ConversationCopilotProviderInterface $provider,
        private readonly ConversationCopilotNormalizer $normalizer,
        private readonly CopilotSemanticInterpretationAdapter $semanticInterpretation,
        private readonly CopilotOrderDraftValidator $validator,
    ) {}

    /** @param array<string,mixed> $context @return array{raw:array<string,mixed>,normalized:array<string,mixed>,safe:array<string,mixed>} */
    public function analyze(Company $company, array $context, ?CarbonInterface $date = null): array
    {
        $raw = $this->provider->analyze($context);
        if (! array_key_exists('intent', $raw) || ! is_array($raw['draft_order'] ?? null)) {
            $safe = $this->normalizer->normalize(['intent' => 'UNKNOWN', 'warnings' => [['code' => 'INVALID_PROVIDER_OUTPUT', 'message' => 'O resultado do copiloto nao possui a estrutura esperada.']]], $this->provider->name())->toArray();

            return ['raw' => $raw, 'normalized' => $safe, 'safe' => $safe];
        }
        $normalized = $this->normalizer->normalize($raw, $this->provider->name(), [
            'usage' => $raw['usage'] ?? [],
            'timestamp' => now()->toIso8601String(),
            'provider_interpretation_called' => true,
        ]);
        $adapted = $this->semanticInterpretation->adapt($company, $normalized, $context);
        $validationContext = $context;
        if (data_get($adapted->metadata, 'semantic_delta_validated') === true
            || data_get($adapted->metadata, 'semantic_pending_slot_rejected') === true) {
            $validationContext['latest_intent'] = $adapted->intent;
        }

        return ['raw' => $raw, 'normalized' => $adapted->toArray(), 'safe' => $this->validator->validate($company, $adapted, $date, $validationContext)->toArray()];
    }

    /** @param array<string,mixed> $analysis @param array<string,mixed> $context @return array<string,mixed> */
    public function validateDeterministic(Company $company, array $analysis, array $context, ?CarbonInterface $date = null): array
    {
        $normalized = $this->normalizer->normalize(
            $analysis,
            'deterministic',
            is_array($analysis['metadata'] ?? null) ? $analysis['metadata'] : [],
        );

        return $this->validator->validate($company, $normalized, $date, $context)->toArray();
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function resolvePendingMeatClarification(Company $company, array $context, ?CarbonInterface $date = null): array
    {
        $normalized = $this->normalizer->normalize([
            'intent' => 'ORDER_CONTINUE',
            'summary' => 'Escolha de carne revalidada a partir da resposta do cliente.',
            'draft_order' => ['items' => []],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => '',
        ], 'deterministic', ['reply_source' => 'resolved_pending_meat_clarification']);

        return $this->validator->validate($company, $normalized, $date, $context)->toArray();
    }
}
