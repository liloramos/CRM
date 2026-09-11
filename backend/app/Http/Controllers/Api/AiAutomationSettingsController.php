<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\ConversationAlert;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationSettings;
use App\Services\Ai\CopilotSandboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiAutomationSettingsController extends Controller
{
    use ResolvesOperationalCompany;

    public function show(Request $request, CopilotAutomationSettings $settings): JsonResponse
    {
        return response()->json(['data' => $this->data($this->resolveCompany($request), $settings)]);
    }

    public function update(Request $request, CopilotAutomationSettings $settings): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'rollout' => ['required', 'string', Rule::in([
                CopilotAutomationAuthorityPolicy::ROLLOUT_DISABLED,
                CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW,
                CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
            ])],
        ]);

        $settings->updateRollout($company, $validated['rollout']);

        return response()->json(['data' => $this->data($company, $settings)]);
    }

    public function updateGuidance(Request $request, CopilotAutomationSettings $settings): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'instructions' => ['present', 'array', 'max:'.CopilotAutomationSettings::MAX_GUIDANCE_ITEMS],
            'instructions.*' => ['required', 'string', 'max:'.CopilotAutomationSettings::MAX_GUIDANCE_LENGTH, 'distinct:ignore_case'],
        ]);
        $settings->updateGuidance($company, $request->user(), $validated['instructions']);

        return response()->json(['data' => $this->data($company, $settings)]);
    }

    public function sandbox(Request $request, CopilotSandboxService $sandbox): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $request->validate(['message' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $sandbox->simulate($company, trim($validated['message']))]);
    }

    /** @return array<string, mixed> */
    private function data(Company $company, CopilotAutomationSettings $settings): array
    {
        $lastEvent = AutomationEvent::query()->where('company_id', $company->id)
            ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)->latest('created_at')->first();
        $lastFailure = AutomationEvent::query()->where('company_id', $company->id)
            ->where('status', AutomationEvent::STATUS_FAILED)->latest('created_at')->first();
        $handoffs = ConversationAlert::query()->where('company_id', $company->id)
            ->whereIn('type', [ConversationAlert::TYPE_HUMAN_REQUESTED, ConversationAlert::TYPE_LOW_CONFIDENCE_AI])
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->latest('created_at')->limit(5)->get();
        $provider = (string) config('chatbotcrm.ai.copilot.provider', 'fake');
        $openAiConfigured = (string) config('chatbotcrm.ai.openai.api_key', '') !== '';

        return [
            'provider' => $provider === 'openai' ? 'OpenAI' : 'Ambiente local',
            'model' => $provider === 'openai' ? config('chatbotcrm.ai.openai.model') : null,
            'api_key_configured' => $provider !== 'openai' || $openAiConfigured,
            'rollout' => $settings->rolloutFor($company),
            'automation_enabled' => (bool) config('chatbotcrm.ai.automation_enabled', true),
            'allow_auto_send' => (bool) config('chatbotcrm.ai.allow_auto_send', false),
            'guidance' => $settings->guidanceFor($company),
            'last_execution_at' => $lastEvent?->created_at?->toIso8601String(),
            'last_failure' => $lastFailure ? 'A última execução não pôde ser concluída.' : null,
            'handoffs' => $handoffs->map(fn (ConversationAlert $alert): array => [
                'reason' => trim((string) ($alert->message ?: $alert->title)) ?: ($alert->type === ConversationAlert::TYPE_HUMAN_REQUESTED
                    ? 'Cliente pediu atendimento humano'
                    : 'Revisão humana necessária'),
                'occurred_at' => $alert->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
