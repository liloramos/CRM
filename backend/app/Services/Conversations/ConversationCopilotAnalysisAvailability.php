<?php

namespace App\Services\Conversations;

use App\Models\AutomationEvent;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

final class ConversationCopilotAnalysisAvailability
{
    /** @return list<string> */
    public function eventTypes(): array
    {
        return [
            AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION,
            AutomationEvent::TYPE_AI_SUGGESTION_CREATED,
        ];
    }

    /** @param Builder<Conversation> $query @return Builder<Conversation> */
    public function withAvailability(Builder $query): Builder
    {
        return $query->withExists([
            'automationEvents as has_copilot_analysis' => fn ($events) => $events->whereIn('event_type', $this->eventTypes()),
        ]);
    }

    public function available(Conversation $conversation): bool
    {
        if ($conversation->last_ai_suggestion_at !== null) {
            return true;
        }
        if (array_key_exists('has_copilot_analysis', $conversation->getAttributes())) {
            return (bool) $conversation->getAttribute('has_copilot_analysis');
        }

        return $conversation->automationEvents()
            ->whereIn('event_type', $this->eventTypes())
            ->exists();
    }
}
