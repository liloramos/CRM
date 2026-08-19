<?php

namespace App\Services\Ai;

use App\Models\Conversation;

class ConversationCopilotService
{
    public function __construct(private readonly ConversationCopilotContextBuilder $contextBuilder, private readonly ConversationCopilotPipeline $pipeline, private readonly ConversationCopilotNormalizer $normalizer) {}

    /** @return array<string, mixed> */
    public function analyze(Conversation $conversation): array
    {
        $conversation->loadMissing(['company', 'customer', 'activeOrder']);
        $context = $this->contextBuilder->forConversation($conversation);
        try {
            return $this->pipeline->analyze($conversation->company, $context)['safe'];
        } catch (\Throwable $exception) {
            return $this->normalizer->normalize(['intent' => 'UNKNOWN', 'warnings' => [['code' => 'PROVIDER_UNAVAILABLE', 'message' => 'Nao foi possivel analisar agora.']]], 'unknown', ['error_code' => $exception->getMessage()])->toArray();
        }
    }
}
