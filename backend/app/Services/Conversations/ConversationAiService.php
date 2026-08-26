<?php

namespace App\Services\Conversations;

use App\Jobs\ProcessCopilotAutomation;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;

class ConversationAiService
{
    public function considerIncomingMessage(
        Company $company,
        Conversation $conversation,
        Message $message,
        int $expectedVersion,
    ): void {
        // The webhook worker persists the inbound message first. This secondary
        // job keeps Copilot analysis and any allowed effect out of that transaction.
        ProcessCopilotAutomation::dispatch($message->id, $expectedVersion)->afterCommit();
    }
}
