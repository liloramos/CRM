<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiResponseSuggestionResource;
use App\Models\AiResponseSuggestion;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationAutomationController extends Controller
{
    public function suggest(
        Request $request,
        Conversation $conversation,
        AiAutomationService $automation,
    ): AiResponseSuggestionResource {
        $attributes = $request->validate([
            'message_id' => ['nullable', 'integer'],
            'prompt_summary' => ['nullable', 'string', 'max:500'],
            'requested_from' => ['nullable', 'string', 'max:80'],
        ]);

        $message = isset($attributes['message_id'])
            ? Message::query()
                ->where('conversation_id', $conversation->id)
                ->findOrFail($attributes['message_id'])
            : null;

        $suggestion = $automation->suggestReply(
            conversation: $conversation,
            message: $message,
            user: $request->user(),
            attributes: $attributes,
        );

        return new AiResponseSuggestionResource($suggestion->load('automationEvents'));
    }

    public function setMode(
        Request $request,
        Conversation $conversation,
        AiAutomationService $automation,
    ): JsonResponse {
        $attributes = $request->validate([
            'mode' => ['required', 'string', Rule::in(Conversation::AUTOMATION_MODES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $conversation = $automation->switchMode(
            conversation: $conversation,
            mode: $attributes['mode'],
            user: $request->user(),
            reason: $attributes['reason'] ?? null,
        );

        return response()->json($this->automationState($conversation));
    }

    public function fallback(
        Request $request,
        Conversation $conversation,
        AiAutomationService $automation,
    ): JsonResponse {
        $attributes = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $conversation = $automation->fallbackToHuman(
            conversation: $conversation,
            reason: $attributes['reason'],
            user: $request->user(),
            metadata: $attributes['metadata'] ?? [],
        );

        return response()->json($this->automationState($conversation));
    }

    public function approveSuggestion(
        Request $request,
        AiResponseSuggestion $aiResponseSuggestion,
        AiAutomationService $automation,
    ): AiResponseSuggestionResource {
        $suggestion = $automation->approveSuggestion($aiResponseSuggestion, $request->user());

        return new AiResponseSuggestionResource($suggestion->load('automationEvents'));
    }

    public function rejectSuggestion(
        Request $request,
        AiResponseSuggestion $aiResponseSuggestion,
        AiAutomationService $automation,
    ): AiResponseSuggestionResource {
        $attributes = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $suggestion = $automation->rejectSuggestion(
            suggestion: $aiResponseSuggestion,
            user: $request->user(),
            reason: $attributes['reason'] ?? null,
        );

        return new AiResponseSuggestionResource($suggestion->load('automationEvents'));
    }

    /**
     * @return array<string, mixed>
     */
    private function automationState(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'automation_mode' => $conversation->automation_mode,
            'automation_status' => $conversation->automation_status,
            'human_review_required' => $conversation->human_review_required,
            'manual_takeover_reason' => $conversation->manual_takeover_reason,
            'manual_takeover_at' => $conversation->manual_takeover_at,
            'manual_takeover_by_user_id' => $conversation->manual_takeover_by_user_id,
            'automation_paused_until' => $conversation->automation_paused_until,
            'last_ai_suggestion_at' => $conversation->last_ai_suggestion_at,
        ];
    }
}
