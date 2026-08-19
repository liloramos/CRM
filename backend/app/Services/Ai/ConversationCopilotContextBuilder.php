<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Product;
use App\Services\Menu\DailyStructuredMenuService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class ConversationCopilotContextBuilder
{
    public function __construct(private readonly DailyStructuredMenuService $dailyMenu) {}

    /** @return array<string,mixed> */
    public function forConversation(Conversation $conversation): array
    {
        $conversation->loadMissing(['company', 'activeOrder']);
        $window = max(3, min(30, (int) config('chatbotcrm.ai.copilot.message_window', 12)));
        $messages = $conversation->messages()->latest('id')->limit($window)->get()->reverse()->values();

        return $this->build($conversation->company, $messages->map(fn ($message): array => ['direction' => $message->direction, 'type' => $message->type, 'body' => (string) $message->content])->all(), $conversation->activeOrder?->only(['code', 'status', 'payment_status', 'fulfillment_type']), CarbonImmutable::today());
    }

    /** @param list<array<string,mixed>> $messages @param array<string,mixed>|null $activeOrder @return array<string,mixed> */
    public function forMessages(Company $company, array $messages, ?array $activeOrder = null, CarbonInterface|string|null $date = null): array
    {
        return $this->build($company, $messages, $activeOrder, $this->date($date));
    }

    /** @param list<array<string,mixed>> $messages @param array<string,mixed>|null $activeOrder @return array<string,mixed> */
    private function build(Company $company, array $messages, ?array $activeOrder, CarbonInterface $date): array
    {
        $messages = array_map(fn (array $message): array => ['direction' => $message['direction'] ?? 'inbound', 'type' => $message['type'] ?? 'text', 'body' => Str::limit((string) ($message['body'] ?? ''), 800, '')], $messages);

        return [
            'latest_message' => ['body' => (string) data_get($messages, (count($messages) - 1).'.body', '')],
            'messages' => $messages,
            'active_order' => $activeOrder,
            'previous_order_context' => [
                'available' => false,
                'instruction' => 'Nenhum pedido historico foi carregado. Solicite uma referencia explicita antes de repetir um pedido anterior.',
            ],
            'evaluation_date' => $date->toDateString(),
            'menu' => $this->menuContext($company),
            'daily_meats' => $this->dailyMeats($company, $date),
        ];
    }

    private function date(CarbonInterface|string|null $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        return $date !== null ? CarbonImmutable::parse($date) : CarbonImmutable::today();
    }

    /** @return list<array{id:int,slug:string,name:string}> */
    private function dailyMeats(Company $company, CarbonInterface $date): array
    {
        return collect(data_get($this->dailyMenu->day($company, $date), 'sections.meat', []))
            ->filter(fn (array $item): bool => (bool) ($item['available'] ?? false))
            ->map(fn (array $item): array => [
                'id' => (int) data_get($item, 'component.id'),
                'slug' => (string) data_get($item, 'component.slug'),
                'name' => (string) (data_get($item, 'component.display_name') ?: data_get($item, 'component.name')),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function menuContext(Company $company): array
    {
        return Product::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('is_available_by_default', true)
            ->with(['optionGroups.componentOptions.component', 'optionGroups.productOptions.selectableProduct'])
            ->orderBy('display_order')
            ->limit(60)
            ->get()
            ->map(function (Product $product): array {
                $removableGroupCodes = collect(data_get($product->composition_rules, 'removable_group_codes', []))
                    ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
                    ->all();

                return [
                    'id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'rule' => $product->menu_rule_code,
                    'groups' => $product->optionGroups->map(fn ($group): array => [
                        'code' => $group->code,
                        'label' => $group->label,
                        'selection_mode' => $group->selection_mode->value,
                        'selection_actor' => $group->selection_actor->value,
                        'required_from_customer' => $group->is_required && $group->selection_actor->value === 'customer',
                        'removable' => in_array($group->code, $removableGroupCodes, true),
                        'min_choices' => $group->min_choices,
                        'max_choices' => $group->max_choices,
                        'options' => $group->componentOptions->filter->is_active->map(fn ($option): array => [
                            'id' => $option->component?->id,
                            'slug' => $option->component?->slug,
                            'name' => $option->component?->display_name ?: $option->component?->name,
                        ])->values()->all(),
                    ])->values()->all(),
                ];
            })->all();
    }
}
