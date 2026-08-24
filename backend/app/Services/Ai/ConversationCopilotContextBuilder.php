<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Orders\CustomerActiveOrderResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class ConversationCopilotContextBuilder
{
    public function __construct(
        private readonly DailyStructuredMenuService $dailyMenu,
        private readonly CopilotResolvedProductConfigurationService $resolvedProducts,
        private readonly CustomerActiveOrderResolver $activeOrders,
    ) {}

    /** @return array<string,mixed> */
    public function forConversation(Conversation $conversation): array
    {
        $conversation->loadMissing(['company.setting', 'activeOrder']);
        $window = max(3, min(30, (int) config('chatbotcrm.ai.copilot.message_window', 12)));
        $activeOrder = $this->activeOrders->forConversation($conversation);
        $activeOrder?->loadMissing(['items.product', 'items.options']);
        $boundary = $this->operationalBoundary($conversation, $activeOrder);
        $messages = $conversation->messages()
            ->when($boundary !== null && isset($boundary['at']), fn ($query) => $query->where('created_at', $boundary['inclusive'] ?? false ? '>=' : '>', $boundary['at']))
            ->latest('id')
            ->limit($window)
            ->get()
            ->reverse()
            ->values();

        return $this->build(
            $conversation->company,
            $messages->map(fn ($message): array => ['direction' => $message->direction, 'type' => $message->type, 'body' => (string) $message->content])->all(),
            $this->activeOrderSnapshot($activeOrder),
            CarbonImmutable::now($this->timezone($conversation->company)),
            $boundary,
        );
    }

    /** @param list<array<string,mixed>> $messages @param array<string,mixed>|null $activeOrder @return array<string,mixed> */
    public function forMessages(Company $company, array $messages, ?array $activeOrder = null, CarbonInterface|string|null $date = null): array
    {
        return $this->build($company, $messages, $activeOrder, $this->date($date));
    }

    /** @param list<array<string,mixed>> $messages @param array<string,mixed>|null $activeOrder @return array<string,mixed> */
    private function build(Company $company, array $messages, ?array $activeOrder, CarbonInterface $date, ?array $boundary = null): array
    {
        $messages = array_map(fn (array $message): array => ['direction' => $message['direction'] ?? 'inbound', 'type' => $message['type'] ?? 'text', 'body' => Str::limit((string) ($message['body'] ?? ''), 800, '')], $messages);
        $hasCurrentInboundMessage = collect($messages)->contains(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound');

        return [
            'latest_message' => ['body' => (string) data_get($messages, (count($messages) - 1).'.body', '')],
            'messages' => $messages,
            'has_current_inbound_message' => $hasCurrentInboundMessage,
            'active_order' => $activeOrder,
            'cycle_boundary' => [
                'applied' => $boundary !== null,
                'source' => $boundary['source'] ?? null,
                'at' => isset($boundary['at']) ? $boundary['at']->toIso8601String() : null,
                'company_timezone' => $this->timezone($company),
                'operational_day_start_time' => $this->operationalDayStartTime($company),
            ],
            'previous_order_context' => [
                'available' => false,
                'instruction' => 'Nenhum pedido historico foi carregado. Solicite uma referencia explicita antes de repetir um pedido anterior.',
            ],
            'evaluation_date' => $date->toDateString(),
            'menu' => $this->menuContext($company, $date),
            'daily_meats' => $this->dailyMeats($company, $date),
        ];
    }

    /** @return array{at:CarbonInterface,source:string,inclusive:bool}|array{source:string}|null */
    private function operationalBoundary(Conversation $conversation, ?Order $activeOrder): ?array
    {
        if ($activeOrder instanceof Order) {
            // Keep an order open across midnight, but do not revive messages from before its cycle.
            return [
                'at' => $activeOrder->created_at,
                'source' => 'active_order_cycle',
                'inclusive' => true,
            ];
        }

        $closed = $this->latestClosedOrderBoundary($conversation);
        $operationalDay = $this->operationalDayBoundary($conversation->company);

        if ($operationalDay === null) {
            return $closed;
        }

        if ($closed === null || $operationalDay['at']->greaterThan($closed['at'])) {
            return $operationalDay;
        }

        return $closed;
    }

    /** @return array{at:CarbonImmutable,source:string,inclusive:bool}|null */
    private function operationalDayBoundary(Company $company): ?array
    {
        $company->loadMissing('setting');
        if ($company->setting === null) {
            return null;
        }

        $timezone = $this->timezone($company);
        $now = CarbonImmutable::now($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $this->operationalDayStartTime($company)));
        $startedAt = $now->startOfDay()->setTime($hour, $minute);

        if ($now->lessThan($startedAt)) {
            $startedAt = $startedAt->subDay();
        }

        return ['at' => $startedAt, 'source' => 'operational_day', 'inclusive' => true];
    }

    /** @return array{at:CarbonInterface,source:string,inclusive:bool}|null */
    private function latestClosedOrderBoundary(Conversation $conversation): ?array
    {
        $order = Order::query()
            ->where('company_id', $conversation->company_id)
            ->whereIn('status', [Order::STATUS_FINISHED, Order::STATUS_CANCELLED])
            ->where(function ($query) use ($conversation): void {
                $query->where('conversation_id', $conversation->id);

                if ($conversation->customer_id) {
                    $query->orWhere(function ($manualOrders) use ($conversation): void {
                        $manualOrders
                            ->whereNull('conversation_id')
                            ->where('payer_customer_id', $conversation->customer_id);
                    });
                }
            })
            ->orderByRaw('COALESCE(finished_at, cancelled_at, updated_at, created_at) DESC')
            ->first();

        if (! $order instanceof Order) {
            return null;
        }

        return [
            'at' => $order->finished_at ?? $order->cancelled_at ?? $order->updated_at ?? $order->created_at,
            'source' => (int) $order->conversation_id === (int) $conversation->id
                ? 'closed_conversation_order'
                : 'closed_customer_order',
            'inclusive' => false,
        ];
    }

    private function timezone(Company $company): string
    {
        $company->loadMissing('setting');

        return $company->setting?->timezone ?: config('app.timezone');
    }

    private function operationalDayStartTime(Company $company): string
    {
        $configured = data_get($company->setting?->settings, 'operational_day_start_time', '00:00');

        return is_string($configured) && preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $configured)
            ? $configured
            : '00:00';
    }

    private function date(CarbonInterface|string|null $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        return $date !== null ? CarbonImmutable::parse($date) : CarbonImmutable::today();
    }

    /** @return array<string,mixed>|null */
    private function activeOrderSnapshot(?Order $order): ?array
    {
        if (! $order instanceof Order) {
            return null;
        }

        return [
            ...$order->only(['id', 'code', 'status', 'payment_status', 'fulfillment_type']),
            'items' => $order->items
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn ($item): array => [
                    'id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'product_slug' => (string) ($item->product?->slug ?? ''),
                    'product_name' => (string) $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'meat_mode' => (string) data_get($item->options->firstWhere('group_code', 'variacao_bife')?->metadata, 'meat_mode', data_get($item->options->firstWhere('group_code', 'carne')?->metadata, 'meat_mode', 'traditional')),
                    'selected_components' => array_values(is_array($item->selected_components) ? $item->selected_components : []),
                    'removed_ingredients' => array_values(is_array($item->removed_ingredients) ? $item->removed_ingredients : []),
                    'item_notes' => (string) ($item->item_notes ?? ''),
                    'beneficiary_name' => (string) ($item->beneficiary_name ?? ''),
                    'options' => $item->options->map(fn ($option): array => [
                        'name' => (string) $option->name,
                        'group_code' => (string) ($option->group_code ?? ''),
                        'quantity' => (int) $option->quantity,
                        'metadata' => is_array($option->metadata) ? $option->metadata : [],
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
        ];
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
    private function menuContext(Company $company, CarbonInterface $date): array
    {
        return Product::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('is_available_by_default', true)
            ->with(['optionGroups.componentOptions.component', 'optionGroups.productOptions.selectableProduct'])
            ->orderBy('display_order')
            ->limit(60)
            ->get()
            ->map(function (Product $product) use ($company, $date): array {
                $removableGroupCodes = collect(data_get($product->composition_rules, 'removable_group_codes', []))
                    ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
                    ->all();

                return [
                    'id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'base_price_cents' => (int) $product->base_price_cents,
                    'rule' => $product->menu_rule_code,
                    'allow_no_meat' => (bool) data_get($product->composition_rules, 'traditional_meat_selection.allow_none', false)
                        || in_array('carne', data_get($product->composition_rules, 'allow_no_meat_group_codes', []), true),
                    'resolved_configuration' => $this->resolvedProducts->resolve($company, $product, $date),
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
