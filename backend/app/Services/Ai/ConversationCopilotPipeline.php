<?php

namespace App\Services\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\Company;
use Carbon\CarbonInterface;

final class ConversationCopilotPipeline
{
    public function __construct(private readonly ConversationCopilotProviderInterface $provider, private readonly ConversationCopilotNormalizer $normalizer, private readonly CopilotOrderDraftValidator $validator) {}

    /** @param array<string,mixed> $context @return array{raw:array<string,mixed>,normalized:array<string,mixed>,safe:array<string,mixed>} */
    public function analyze(Company $company, array $context, ?CarbonInterface $date = null): array
    {
        $raw = $this->provider->analyze($context);
        if (! array_key_exists('intent', $raw) || ! is_array($raw['draft_order'] ?? null)) {
            $safe = $this->normalizer->normalize(['intent' => 'UNKNOWN', 'warnings' => [['code' => 'INVALID_PROVIDER_OUTPUT', 'message' => 'O resultado do copiloto nao possui a estrutura esperada.']]], $this->provider->name())->toArray();

            return ['raw' => $raw, 'normalized' => $safe, 'safe' => $safe];
        }
        $normalized = $this->normalizer->normalize($raw, $this->provider->name(), ['usage' => $raw['usage'] ?? [], 'timestamp' => now()->toIso8601String()]);

        return ['raw' => $raw, 'normalized' => $normalized->toArray(), 'safe' => $this->validator->validate($company, $normalized, $date, $context)->toArray()];
    }
}
