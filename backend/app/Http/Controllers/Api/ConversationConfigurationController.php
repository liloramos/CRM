<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\AiAutomationSetting;
use App\Models\ConversationQuickReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConversationConfigurationController extends Controller
{
    use ResolvesOperationalCompany;

    public function quickReplies(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);
        $search = Str::of((string) ($validated['search'] ?? ''))->squish()->toString();

        $quickReplies = ConversationQuickReply::query()
            ->where('company_id', $company->id)
            ->when(! ($validated['include_inactive'] ?? false), fn ($query) => $query->where('is_active', true))
            ->when($search !== '', function ($query) use ($search): void {
                $needle = '%'.Str::lower(Str::ascii($search)).'%';
                $query->where(function ($nested) use ($needle): void {
                    $nested->whereRaw('LOWER(title) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(shortcut) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(body) LIKE ?', [$needle]);
                });
            })
            ->orderBy('display_order')
            ->orderBy('title')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $quickReplies->map(fn (ConversationQuickReply $reply): array => $this->quickReply($reply))->values(),
        ]);
    }

    public function storeQuickReply(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $this->validatedQuickReply($request, $company->id);

        $quickReply = ConversationQuickReply::query()->create([
            ...$validated,
            'company_id' => $company->id,
            'shortcut' => $this->normalizeShortcut($validated['shortcut']),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->quickReply($quickReply)], 201);
    }

    public function updateQuickReply(
        Request $request,
        ConversationQuickReply $quickReply,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        abort_unless((int) $quickReply->company_id === (int) $company->id, 404);
        $validated = $this->validatedQuickReply($request, $company->id, $quickReply->id);

        $quickReply->forceFill([
            ...$validated,
            'shortcut' => $this->normalizeShortcut($validated['shortcut']),
            'updated_by' => $request->user()?->id,
        ])->save();

        return response()->json(['data' => $this->quickReply($quickReply->refresh())]);
    }

    public function aiStyle(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $setting = $this->aiSetting($company->id);

        return response()->json(['data' => $this->styleFrom($setting)]);
    }

    public function updateAiStyle(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'establishment_name' => ['nullable', 'string', 'max:120'],
            'preferred_greeting' => ['nullable', 'string', 'max:500'],
            'tone' => ['required', Rule::in(['warm', 'direct', 'casual', 'professional'])],
            'formality' => ['required', Rule::in(['informal', 'balanced', 'formal'])],
            'emoji_usage' => ['required', Rule::in(['none', 'light', 'moderate'])],
            'preferred_words' => ['nullable', 'array', 'max:20'],
            'preferred_words.*' => ['string', 'max:80'],
            'forbidden_words' => ['nullable', 'array', 'max:20'],
            'forbidden_words.*' => ['string', 'max:80'],
            'human_transfer_message' => ['nullable', 'string', 'max:500'],
            'payment_proof_received_message' => ['nullable', 'string', 'max:500'],
            'closing_message' => ['nullable', 'string', 'max:500'],
        ]);
        $setting = $this->aiSetting($company->id);
        $settings = $setting->settings ?? [];
        $settings['conversation_style'] = [
            ...$validated,
            'preferred_words' => $this->cleanWordList($validated['preferred_words'] ?? []),
            'forbidden_words' => $this->cleanWordList($validated['forbidden_words'] ?? []),
            'updated_by_user_id' => $request->user()?->id,
            'updated_at' => now()->toIso8601String(),
        ];

        $setting->forceFill(['settings' => $settings])->save();

        return response()->json(['data' => $this->styleFrom($setting->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedQuickReply(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        $request->merge([
            'shortcut' => $this->normalizeShortcut((string) $request->input('shortcut')),
        ]);

        $shortcutRule = Rule::unique('conversation_quick_replies', 'shortcut')
            ->where(fn ($query) => $query->where('company_id', $companyId));

        if ($ignoreId !== null) {
            $shortcutRule->ignore($ignoreId);
        }

        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'shortcut' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/', $shortcutRule],
            'body' => ['required', 'string', 'max:4000'],
            'category' => ['required', Rule::in(ConversationQuickReply::CATEGORIES)],
            'is_active' => ['required', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function quickReply(ConversationQuickReply $reply): array
    {
        return [
            'id' => (string) $reply->id,
            'title' => $reply->title,
            'shortcut' => $reply->shortcut,
            'body' => $reply->body,
            'category' => $reply->category,
            'isActive' => (bool) $reply->is_active,
            'displayOrder' => (int) $reply->display_order,
            'createdAt' => $reply->created_at?->toIso8601String(),
            'updatedAt' => $reply->updated_at?->toIso8601String(),
        ];
    }

    private function normalizeShortcut(string $shortcut): string
    {
        return Str::of($shortcut)->ascii()->lower()->trim('/')->toString();
    }

    private function aiSetting(int $companyId): AiAutomationSetting
    {
        $provider = (string) config('chatbotcrm.ai.provider', 'fake');

        return AiAutomationSetting::query()->firstOrCreate(
            ['company_id' => $companyId, 'provider' => $provider],
            [
                'default_mode' => 'assisted',
                'automation_enabled' => (bool) config('chatbotcrm.ai.automation_enabled', true),
                'allow_auto_send' => (bool) config('chatbotcrm.ai.allow_auto_send', false),
                'require_human_confirmation_for_ambiguous' => true,
                'require_human_confirmation_for_payments' => true,
                'status' => AiAutomationSetting::STATUS_ACTIVE,
                'settings' => [],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function styleFrom(AiAutomationSetting $setting): array
    {
        return array_replace([
            'establishment_name' => '',
            'preferred_greeting' => '',
            'tone' => 'warm',
            'formality' => 'balanced',
            'emoji_usage' => 'light',
            'preferred_words' => [],
            'forbidden_words' => [],
            'human_transfer_message' => '',
            'payment_proof_received_message' => '',
            'closing_message' => '',
        ], data_get($setting->settings, 'conversation_style', []));
    }

    /** @param list<string> $words */
    private function cleanWordList(array $words): array
    {
        return collect($words)
            ->map(fn (string $word): string => Str::squish($word))
            ->filter()
            ->unique(fn (string $word): string => Str::lower(Str::ascii($word)))
            ->values()
            ->all();
    }
}
