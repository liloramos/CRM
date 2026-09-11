<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Services\SystemAssistant\SystemAssistantFeatureCatalog;
use App\Services\SystemAssistant\SystemAssistantKnowledgeBuilder;
use App\Services\SystemAssistant\SystemAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemAssistantController extends Controller
{
    use ResolvesOperationalCompany;

    public function __invoke(Request $request, SystemAssistantService $assistant, SystemAssistantFeatureCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1200'],
            'current_route' => ['nullable', Rule::in($catalog->routeKeys())],
            'selected_context' => ['nullable', 'array'],
            'selected_context.order_id' => ['nullable', 'integer'],
            'selected_context.customer_id' => ['nullable', 'integer'],
            'recent_history' => ['nullable', 'array', 'max:'.SystemAssistantKnowledgeBuilder::MAX_HISTORY_MESSAGES],
            'recent_history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'recent_history.*.text' => ['required', 'string', 'max:'.SystemAssistantKnowledgeBuilder::MAX_HISTORY_MESSAGE_LENGTH],
        ]);

        return response()->json(['data' => $assistant->answer(
            $request->user(),
            $this->resolveCompany($request),
            $validated['message'],
            [
                'current_route' => $validated['current_route'] ?? null,
                'selected_context' => $validated['selected_context'] ?? [],
                'recent_history' => $validated['recent_history'] ?? [],
            ],
        )]);
    }
}
