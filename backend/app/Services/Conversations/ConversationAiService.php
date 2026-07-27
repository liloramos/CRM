<?php

namespace App\Services\Conversations;

use App\Models\AiResponseSuggestion;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Message;
use App\Services\Ai\AiAutomationService;

class ConversationAiService
{
    public function __construct(
        private readonly AiAutomationService $automation,
        private readonly ConversationAlertService $alerts,
    ) {}

    public function considerIncomingMessage(
        Company $company,
        Conversation $conversation,
        Message $message,
        int $expectedVersion,
    ): ?AiResponseSuggestion {
        $conversation = Conversation::query()->whereKey($conversation->id)->firstOrFail();

        if ((int) $conversation->automation_version !== $expectedVersion) {
            return null;
        }

        if ($conversation->automation_mode === Conversation::AUTOMATION_MODE_MANUAL) {
            return null;
        }

        $suggestion = $this->automation->suggestReply(
            conversation: $conversation,
            message: $message,
            attributes: ['requested_from' => 'whatsapp_webhook'],
        );

        if ($suggestion->requires_human_confirmation) {
            $this->alerts->open(
                company: $company,
                type: ConversationAlert::TYPE_LOW_CONFIDENCE_AI,
                severity: ConversationAlert::SEVERITY_WARNING,
                title: 'IA precisa de revisao',
                message: 'A automacao encontrou uma duvida e aguarda uma atendente.',
                conversation: $conversation,
                messageModel: $message,
                deduplicationKey: 'ai-review:'.$message->id,
                metadata: [
                    'suggestion_id' => $suggestion->id,
                    'ambiguity_reason' => $suggestion->ambiguity_reason,
                ],
            );
        }

        return $suggestion;
    }
}
