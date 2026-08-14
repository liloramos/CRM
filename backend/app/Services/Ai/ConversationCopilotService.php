<?php

namespace App\Services\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Models\Conversation;
use App\Models\Product;
use Illuminate\Support\Str;

class ConversationCopilotService
{
    public function __construct(private readonly ConversationCopilotProviderInterface $provider, private readonly ConversationCopilotNormalizer $normalizer, private readonly CopilotOrderDraftValidator $validator) {}

    /** @return array<string, mixed> */
    public function analyze(Conversation $conversation): array
    {
        $conversation->loadMissing(['company', 'customer', 'activeOrder']);
        $window = max(3, min(30, (int) config('chatbotcrm.ai.copilot.message_window', 12)));
        $messages = $conversation->messages()->latest('id')->limit($window)->get()->reverse()->values();
        $context = [
            'latest_message' => ['body' => (string) ($messages->last()?->content ?? '')],
            'messages' => $messages->map(fn ($message) => ['direction' => $message->direction, 'type' => $message->type, 'body' => Str::limit((string) $message->content, 800, '')])->all(),
            'active_order' => $conversation->activeOrder?->only(['code', 'status', 'payment_status', 'fulfillment_type']),
            'menu' => $this->menuContext($conversation),
        ];
        try {
            $raw = $this->provider->analyze($context);
        } catch (\Throwable $exception) {
            return $this->normalizer->normalize(['intent' => 'UNKNOWN', 'warnings' => [['code' => 'PROVIDER_UNAVAILABLE', 'message' => 'Nao foi possivel analisar agora.']]], 'unknown', ['error_code' => $exception->getMessage()])->toArray();
        }
        if (! array_key_exists('intent', $raw) || ! is_array($raw['draft_order'] ?? null)) {
            return $this->normalizer->normalize([
                'intent' => 'UNKNOWN',
                'warnings' => [['code' => 'INVALID_PROVIDER_OUTPUT', 'message' => 'O resultado do copiloto nao possui a estrutura esperada.']],
            ], $this->provider->name(), ['messages_used' => $messages->count()])->toArray();
        }
        $analysis = $this->normalizer->normalize($raw, $this->provider->name(), ['messages_used' => $messages->count(), 'usage' => $raw['usage'] ?? [], 'timestamp' => now()->toIso8601String()]);

        return $this->validator->validate($conversation->company, $analysis)->toArray();
    }

    /** @return list<array<string,mixed>> */
    private function menuContext(Conversation $conversation): array
    {
        return Product::query()->where('company_id', $conversation->company_id)->where('is_active', true)->where('is_available_by_default', true)->orderBy('display_order')->limit(60)->get(['id', 'slug', 'name', 'menu_rule_code'])->map(fn (Product $product) => ['id' => $product->id, 'slug' => $product->slug, 'name' => $product->name, 'rule' => $product->menu_rule_code])->all();
    }
}
