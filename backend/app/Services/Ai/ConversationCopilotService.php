<?php

namespace App\Services\Ai;

use App\Models\Conversation;

class ConversationCopilotService
{
    public function __construct(
        private readonly ConversationCopilotContextBuilder $contextBuilder,
        private readonly ConversationCopilotPipeline $pipeline,
        private readonly ConversationCopilotNormalizer $normalizer,
        private readonly CopilotOrderProposalPresenter $proposals,
        private readonly CopilotSuggestedReplyGuard $suggestedReplies,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(Conversation $conversation): array
    {
        $conversation->loadMissing(['company', 'customer', 'activeOrder']);
        $context = $this->contextBuilder->forConversation($conversation);
        try {
            $safe = $this->suggestedReplies->restrict($this->pipeline->analyze($conversation->company, $context)['safe'], $context);

            return [...$safe, 'proposal' => $this->proposals->present($conversation, $safe)];
        } catch (\Throwable $exception) {
            $safe = $this->normalizer->normalize(
                ['intent' => 'UNKNOWN', 'warnings' => [['code' => 'PROVIDER_UNAVAILABLE', 'message' => 'Nao foi possivel analisar agora.']]],
                'unknown',
                ['error_code' => $exception->getMessage()],
            )->toArray();

            return [...$safe, 'proposal' => $this->proposals->present($conversation, $safe)];
        }
    }
}
