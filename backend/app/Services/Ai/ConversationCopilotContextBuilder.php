<?php

namespace App\Services\Ai;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\MenuComponent;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Menu\MenuComponentPresentation;
use App\Services\Operational\CompanyOperatingHoursService;
use App\Services\Orders\CustomerActiveOrderResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ConversationCopilotContextBuilder
{
    public function __construct(
        private readonly DailyStructuredMenuService $dailyMenu,
        private readonly CopilotResolvedProductConfigurationService $resolvedProducts,
        private readonly CustomerActiveOrderResolver $activeOrders,
        private readonly CopilotProductEligibility $eligibility,
        private readonly CopilotProductDecisionFacts $decisionFacts,
        private readonly CompanyOperatingHoursService $operatingHours,
        private readonly CopilotAutomationSettings $automationSettings,
        private readonly MenuComponentPresentation $componentPresentation,
    ) {}

    /** @return array<string,mixed> */
    public function forConversation(Conversation $conversation, ?Message $triggerInbound = null): array
    {
        $conversation->loadMissing(['company.setting', 'company.deliverySetting', 'customer.addresses', 'activeOrder']);
        $window = max(3, min(30, (int) config('chatbotcrm.ai.copilot.message_window', 12)));
        $activeOrder = $this->activeOrders->forConversation($conversation);
        $activeOrder?->loadMissing(['items.product', 'items.options']);
        $boundary = $this->operationalBoundary($conversation, $activeOrder);
        $messages = $conversation->messages()
            ->when($boundary !== null && isset($boundary['at']), fn ($query) => $query->where('created_at', $boundary['inclusive'] ?? false ? '>=' : '>', $boundary['at']))
            ->when($triggerInbound instanceof Message, fn ($query) => $query->where('id', '<=', $triggerInbound->id))
            ->orderByRaw('COALESCE(received_at, sent_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit($window)
            ->get()
            ->reverse()
            ->values();
        if ($triggerInbound instanceof Message
            && (int) $triggerInbound->conversation_id === (int) $conversation->id
            && $triggerInbound->direction === 'inbound'
            && ! $messages->contains(fn (Message $message): bool => (int) $message->id === (int) $triggerInbound->id)) {
            $messages->push($triggerInbound);
        }

        $context = $this->build(
            $conversation->company,
            $messages->map(fn ($message): array => [
                'id' => (int) $message->id,
                'direction' => $message->direction,
                'type' => $message->type,
                'body' => (string) $message->content,
                'external_message_id' => $message->external_message_id,
                'occurred_at' => ($message->received_at ?? $message->sent_at ?? $message->created_at)?->toIso8601String(),
                'location' => $message->type === 'location' ? data_get($message->metadata, 'location') : null,
            ])->all(),
            $this->activeOrderSnapshot($activeOrder),
            CarbonImmutable::now($this->timezone($conversation->company)),
            $boundary,
            $conversation->customer === null ? null : [
                'id' => (int) $conversation->customer->id,
                'name' => (string) $conversation->customer->name,
                'saved_addresses' => $conversation->customer->addresses
                    ->sortBy([['is_default', 'desc'], ['id', 'asc']])
                    ->map(fn ($address): array => [
                        'id' => (int) $address->id,
                        'label' => (string) ($address->label ?: 'Endereço'),
                        'is_default' => (bool) $address->is_default,
                        'address' => collect([$address->street, $address->number, $address->neighborhood, $address->city])->filter()->implode(', '),
                    ])->values()->all(),
                'address_selection_policy' => 'Sugira o padrão quando houver; nunca confirme sem escolha explícita. Com múltiplos endereços, pergunte qual usar.',
            ],
        );
        $trigger = $triggerInbound instanceof Message
            && (int) $triggerInbound->conversation_id === (int) $conversation->id
            && $triggerInbound->direction === 'inbound'
                ? $triggerInbound
                : $messages->reverse()->first(fn (Message $message): bool => $message->direction === 'inbound');
        if ($trigger instanceof Message) {
            $triggerSnapshot = $this->messageSnapshot($trigger);
            $context['latest_message'] = $triggerSnapshot;
            $context['trigger_message'] = $triggerSnapshot;
            $context['latest_customer_message_for_turn'] = $triggerSnapshot;
        }

        $pendingClarification = $this->pendingClarification($conversation, $messages, $activeOrder, $boundary, $context, $trigger);

        $pendingOrderState = $this->pendingOrderState($conversation, $messages, $activeOrder, $boundary, $pendingClarification, $trigger);

        return [
            ...$context,
            'pending_clarification' => $pendingClarification,
            'pending_order_state' => $pendingOrderState,
            'conversation_frame' => $this->conversationFrame(
                $conversation,
                $messages,
                $context,
                $pendingOrderState,
                $boundary,
                $trigger,
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function messageSnapshot(Message $message): array
    {
        return [
            'id' => (int) $message->id,
            'direction' => $message->direction,
            'type' => $message->type,
            'body' => Str::limit((string) $message->content, 800, ''),
            'external_message_id' => filled($message->external_message_id) ? Str::limit((string) $message->external_message_id, 180, '') : null,
            'occurred_at' => ($message->received_at ?? $message->sent_at ?? $message->created_at)?->toIso8601String(),
            'location' => $message->type === 'location' && is_array(data_get($message->metadata, 'location'))
                ? data_get($message->metadata, 'location')
                : null,
        ];
    }

    /** @param list<array<string,mixed>> $messages @param array<string,mixed>|null $activeOrder @return array<string,mixed> */
    public function forMessages(Company $company, array $messages, ?array $activeOrder = null, CarbonInterface|string|null $date = null): array
    {
        $context = $this->build($company, $messages, $activeOrder, $this->date($date));
        $trigger = collect((array) data_get($context, 'messages', []))
            ->reverse()
            ->first(fn (mixed $message): bool => is_array($message) && ($message['direction'] ?? null) === 'inbound');
        if (is_array($trigger)) {
            $context['latest_message'] = $trigger;
            $context['trigger_message'] = $trigger;
            $context['latest_customer_message_for_turn'] = $trigger;
        }

        return [
            ...$context,
            'conversation_frame' => [
                'recent_messages' => array_values((array) data_get($context, 'messages', [])),
                'active_order_state' => $activeOrder,
                'pending_order_state' => null,
                'pending_slots' => [],
                'last_assistant_goal' => null,
                'recent_options' => [],
                'active_references' => [],
                'business_context' => [
                    'products' => $this->relevantProducts($context, null, null, []),
                    'payment_methods' => array_values((array) data_get($context, 'payment.available_methods', [])),
                ],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $messages @param array<string,mixed>|null $activeOrder @return array<string,mixed> */
    private function build(Company $company, array $messages, ?array $activeOrder, CarbonInterface $date, ?array $boundary = null, ?array $customer = null): array
    {
        $company->loadMissing(['setting', 'deliverySetting']);
        $messages = array_map(fn (array $message): array => [
            'id' => isset($message['id']) ? (int) $message['id'] : null,
            'direction' => $message['direction'] ?? 'inbound',
            'type' => $message['type'] ?? 'text',
            'body' => Str::limit((string) ($message['body'] ?? ''), 800, ''),
            'external_message_id' => isset($message['external_message_id']) ? Str::limit((string) $message['external_message_id'], 180, '') : null,
            'occurred_at' => isset($message['occurred_at']) ? (string) $message['occurred_at'] : null,
            'location' => is_array($message['location'] ?? null) ? $message['location'] : null,
        ], $messages);
        $hasCurrentInboundMessage = collect($messages)->contains(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound');

        return [
            'company' => [
                'name' => (string) $company->name,
                'timezone' => $this->timezone($company),
                'address' => trim((string) data_get($company->deliverySetting?->provider_options, 'origin.address', '')),
            ],
            'customer' => $customer,
            'latest_message' => (array) data_get($messages, count($messages) - 1, ['body' => '']),
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
            'operational_status' => $this->operatingHours->status($company, $date),
            'menu' => $this->menuContext($company, $date),
            'daily_meats' => $this->dailyMeats($company, $date),
            'operational_catalog' => $this->operationalCatalog($company, $date),
            'payment' => [
                'active_order_status' => $activeOrder['payment_status'] ?? null,
                'available_methods' => $this->availablePaymentMethods($company),
                'pix_key' => $this->pixKey($company),
                'pix_configured' => $this->pixKey($company) !== '',
            ],
            'delivery' => [
                'fulfillment' => $activeOrder['fulfillment_type'] ?? null,
                'address' => $activeOrder['delivery_address'] ?? null,
                'fee_cents' => $activeOrder['delivery_fee_cents'] ?? null,
            ],
            'authority' => [
                'allowed' => ['answer_grounded_information', 'ask_clarification', 'stage_validated_pickup_order'],
                'prohibited' => ['confirm_payment', 'void_payment', 'refund', 'grant_credit', 'special_discount', 'change_delivery_fee', 'delete_records'],
            ],
            'company_guidance' => $this->automationSettings->guidanceFor($company),
        ];
    }

    /** @return array{components:list<array{id:int,slug:string,name:string,type:string,available_today:bool}>} */
    private function operationalCatalog(Company $company, CarbonInterface $date): array
    {
        $availableIds = collect(data_get($this->dailyMenu->day($company, $date), 'sections', []))
            ->flatMap(fn (mixed $items): array => is_array($items) ? $items : [])
            ->filter(fn (mixed $item): bool => is_array($item) && (bool) ($item['available'] ?? false))
            ->map(fn (array $item): int => (int) data_get($item, 'component.id'))
            ->filter()
            ->unique();
        $availableIds = $availableIds->merge(
            collect($this->menuContext($company, $date))
                ->flatMap(fn (array $product): array => (array) data_get($product, 'resolved_configuration.daily_components', []))
                ->filter(fn (mixed $component): bool => is_array($component)
                    && ($component['applicability'] ?? null) === 'AVAILABLE_TODAY')
                ->map(fn (array $component): int => (int) ($component['id'] ?? $component['component_id'] ?? 0))
                ->filter(),
        )->unique();

        return [
            'components' => MenuComponent::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('display_order')
                ->limit(160)
                ->get(['id', 'slug', 'name', 'component_type'])
                ->map(fn (MenuComponent $component): array => [
                    'id' => (int) $component->id,
                    'slug' => (string) $component->slug,
                    'name' => (string) $component->name,
                    'type' => $component->component_type->value,
                    'available_today' => $availableIds->contains((int) $component->id),
                    'aliases' => $this->componentPresentation->searchAliases($component),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return list<string> */
    private function availablePaymentMethods(Company $company): array
    {
        $configured = (array) data_get($company->setting?->settings, 'payments.methods', []);

        return collect([
            Payment::METHOD_PIX,
            Payment::METHOD_CASH,
            Payment::METHOD_DEBIT_CARD,
            Payment::METHOD_CREDIT_CARD,
            Payment::METHOD_CUSTOMER_CREDIT,
            Payment::METHOD_OTHER,
        ])->filter(fn (string $method): bool => ! array_key_exists($method, $configured) || (bool) $configured[$method])
            ->values()
            ->all();
    }

    private function pixKey(Company $company): string
    {
        return trim((string) (data_get($company->setting?->settings, 'payments.pix.public_key')
            ?? data_get($company->setting?->settings, 'payments.pix.key', '')));
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
            ...$order->only(['id', 'code', 'status', 'payment_status', 'payment_method', 'fulfillment_type', 'subtotal_cents', 'delivery_fee_cents', 'total_cents', 'amount_due_cents']),
            'delivery_address' => trim((string) data_get($order->delivery_address_snapshot, 'formatted_address', data_get($order->delivery_address_snapshot, 'address', ''))),
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
        return $this->eligibility->apply(Product::query())
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
                    'description' => (string) ($product->description ?? ''),
                    'product_type' => (string) $product->product_type,
                    'base_price_cents' => (int) $product->base_price_cents,
                    'rule' => $product->menu_rule_code,
                    'composition_rules' => is_array($product->composition_rules) ? $product->composition_rules : [],
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
                        'min_quantity' => $group->min_quantity,
                        'max_quantity' => $group->max_quantity,
                        'options' => $group->componentOptions->filter->is_active->map(fn ($option): array => [
                            'id' => $option->component?->id,
                            'slug' => $option->component?->slug,
                            'name' => $option->component?->display_name ?: $option->component?->name,
                        ])->values()->all(),
                    ])->values()->all(),
                ];
            })->all();
    }

    /**
     * Reuses the automation audit payload as the bounded structured memory for an
     * unfinished customer turn. Catalog rules are still rebuilt on every analysis.
     *
     * @param  Collection<int, Message>  $messages
     * @param  array<string, mixed>|null  $pendingClarification
     * @return array<string, mixed>|null
     */
    private function pendingOrderState(Conversation $conversation, $messages, ?Order $activeOrder, ?array $boundary, ?array $pendingClarification, ?Message $triggerInbound = null): ?array
    {
        $current = $triggerInbound instanceof Message
            ? $triggerInbound
            : $messages->reverse()->first(fn ($message): bool => $message->direction === 'inbound');

        if ($activeOrder instanceof Order) {
            $stored = $current instanceof Message
                ? AutomationEvent::query()
                    ->where('company_id', $conversation->company_id)
                    ->where('conversation_id', $conversation->id)
                    ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)
                    ->where('order_id', $activeOrder->id)
                    ->where('message_id', '<', $current->id)
                    ->where('payload->order_context->has_context', true)
                    ->latest('id')
                    ->first()
                : null;
            $storedContext = $stored instanceof AutomationEvent
                ? (array) data_get($stored->payload, 'order_context', [])
                : [];

            return [
                ...$storedContext,
                'source' => 'active_order',
                'draft_order' => $this->activeOrderSnapshot($activeOrder),
                'has_context' => true,
                'item_confirmation' => (array) ($storedContext['item_confirmation'] ?? ['status' => 'confirmed']),
                'additional_items' => (array) ($storedContext['additional_items'] ?? ['status' => 'declined']),
            ];
        }

        if (! $current instanceof Message) {
            return $pendingClarification === null ? null : $this->pendingClarificationOrderState($pendingClarification);
        }

        $event = AutomationEvent::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)
            ->where('message_id', '<', $current->id)
            ->where('payload->order_context->has_context', true)
            ->when($boundary !== null && isset($boundary['at']), function ($query) use ($boundary): void {
                $query->whereHas('message', fn ($messageQuery) => $messageQuery->where(
                    'created_at',
                    $boundary['inclusive'] ?? false ? '>=' : '>',
                    $boundary['at'],
                ));
            })
            ->latest('id')
            ->first();
        if (! $event instanceof AutomationEvent) {
            return $pendingClarification === null ? null : $this->pendingClarificationOrderState($pendingClarification);
        }

        $storedOrderContext = (array) data_get($event->payload, 'order_context', []);
        if (in_array((string) ($storedOrderContext['lifecycle'] ?? ''), ['cancelled', 'superseded', 'complete'], true)) {
            return null;
        }

        $sourceMessage = $event->message()->first();
        if (! $sourceMessage instanceof Message || ($boundary !== null && isset($boundary['at'])
            && ($boundary['inclusive'] ?? false ? $sourceMessage->created_at->lessThan($boundary['at']) : $sourceMessage->created_at->lessThanOrEqualTo($boundary['at'])))) {
            return null;
        }

        return ['source' => 'automation_event', 'source_event_id' => (int) $event->id, ...$storedOrderContext];
    }

    /** @param array<string,mixed> $pendingClarification @return array<string,mixed> */
    private function pendingClarificationOrderState(array $pendingClarification): array
    {
        $components = array_values((array) ($pendingClarification['recognized_components'] ?? []));
        $candidate = [
            'family' => null,
            'variant' => null,
            'variant_candidates' => [],
            'quantity' => 1,
            'daily_component_ids' => collect($components)->where('type', '!=', 'meat')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'meat_component_ids' => collect($components)->where('type', 'meat')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'meats' => collect($components)->where('type', 'meat')->pluck('name')->filter()->values()->all(),
            'components' => $components,
            'additions' => [],
            'notes' => '',
            'provenance' => 'customer_explicit',
            'status' => 'candidate',
        ];

        return [
            'source' => 'pending_clarification',
            'candidate_items' => [$candidate],
            'selected_components' => $components,
            'missing_fields' => ['MENU_ITEM'],
        ];
    }

    /**
     * Automation events are only an audit trail for a question previously offered in
     * Shadow. Product availability and labels are always rebuilt from today's menu.
     *
     * @param  Collection<int, Message>  $messages
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function pendingClarification(Conversation $conversation, $messages, ?Order $activeOrder, ?array $boundary, array $context, ?Message $triggerInbound = null): ?array
    {
        $current = $triggerInbound instanceof Message && $triggerInbound->type === 'text'
            ? $triggerInbound
            : $messages->reverse()->first(fn ($message): bool => $message->direction === 'inbound' && $message->type === 'text');
        if ($current === null) {
            return null;
        }

        $event = AutomationEvent::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)
            ->where('message_id', '<', $current->id)
            ->when($boundary !== null && isset($boundary['at']), function ($query) use ($boundary): void {
                $query->whereHas('message', fn ($messageQuery) => $messageQuery->where(
                    'created_at',
                    $boundary['inclusive'] ?? false ? '>=' : '>',
                    $boundary['at'],
                ));
            })
            ->latest('id')
            ->limit(24)
            ->get()
            ->first(function (AutomationEvent $candidate): bool {
                return in_array(data_get($candidate->payload, 'action'), ['send_safe_clarification', 'send_order_clarification'], true)
                    && in_array(data_get($candidate->payload, 'clarification_context.type'), ['ambiguous_meat', 'product_selection'], true);
            });
        if (! $event instanceof AutomationEvent) {
            return null;
        }

        $terminalResolution = AutomationEvent::query()
            ->where('company_id', $conversation->company_id)
            ->where('conversation_id', $conversation->id)
            ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)
            ->where('message_id', '>', $event->message_id)
            ->where('message_id', '<', $current->id)
            ->latest('id')
            ->limit(24)
            ->get()
            ->first(function (AutomationEvent $candidate) use ($event): bool {
                return (int) data_get($candidate->payload, 'clarification_source_event_id') === (int) $event->id
                    && in_array(data_get($candidate->payload, 'clarification_resolution'), ['resolved', 'invalid', 'rejected', 'stale', 'superseded'], true);
            });
        if ($terminalResolution instanceof AutomationEvent) {
            return null;
        }

        $snapshot = (array) data_get($event->payload, 'clarification_context', []);
        $sourceMessage = $event->message()->first();
        if (! $sourceMessage || ($boundary !== null && isset($boundary['at'])
            && ($boundary['inclusive'] ?? false ? $sourceMessage->created_at->lessThan($boundary['at']) : $sourceMessage->created_at->lessThanOrEqualTo($boundary['at'])))) {
            return $this->staleClarification($event, 'cycle_boundary');
        }

        $expectedActiveOrderId = $snapshot['active_order_id'] ?? null;
        $actualActiveOrderId = $activeOrder?->id;
        if ((int) ($expectedActiveOrderId ?? 0) !== (int) ($actualActiveOrderId ?? 0)) {
            return $this->staleClarification($event, 'active_order_changed');
        }

        if (($snapshot['type'] ?? null) === 'product_selection') {
            $available = collect(data_get($context, 'operational_catalog.components', []))
                ->filter(fn (mixed $component): bool => is_array($component) && ($component['available_today'] ?? false) === true)
                ->keyBy('id');
            $components = collect((array) ($snapshot['recognized_components'] ?? []))
                ->map(fn (mixed $component): ?array => is_array($component) && $available->has((int) ($component['id'] ?? 0))
                    ? (array) $available->get((int) $component['id'])
                    : null)
                ->filter()
                ->values()
                ->all();

            return [
                'status' => 'eligible',
                'source_event_id' => (int) $event->id,
                'source_message_id' => (int) $event->message_id,
                'type' => 'product_selection',
                'recognized_components' => $components,
            ];
        }

        $productId = (int) data_get($snapshot, 'candidate.product_id');
        $product = collect((array) data_get($context, 'menu', []))->firstWhere('id', $productId);
        $source = (string) ($snapshot['source'] ?? 'DAILY_MENU');
        if (! is_array($product)
            || ($source === 'DAILY_MENU' && ! in_array((string) ($product['rule'] ?? ''), ['n8_tradicional', 'n9_tradicional'], true))) {
            return $this->staleClarification($event, 'product_unavailable');
        }

        $selectionGroup = collect((array) data_get($product, 'groups', []))->firstWhere('code', 'carne');
        $selectionMode = $source === 'DAILY_MENU'
            ? ((int) data_get($product, 'resolved_configuration.meat_configuration.traditional.selection_rules.max', 1) > 1 ? 'multiple' : 'single')
            : (string) data_get($selectionGroup, 'selection_mode');
        if (! in_array($selectionMode, ['single', 'multiple'], true)) {
            return $this->staleClarification($event, 'selection_group_changed');
        }

        $allowedIds = array_values(array_unique(array_filter(array_map('intval', (array) ($snapshot['option_component_ids'] ?? [])))));
        $available = $source === 'PRODUCT_CONFIGURATION'
            ? collect((array) data_get(
                collect((array) data_get($product, 'resolved_configuration.static_configuration.groups', []))->firstWhere('code', 'carne'),
                'component_options',
                [],
            ))
                ->filter(fn (array $option): bool => ($option['available'] ?? false) === true)
                ->map(fn (array $option): array => [
                    'id' => (int) ($option['component_id'] ?? 0),
                    'name' => (string) ($option['display_name'] ?? $option['name'] ?? ''),
                ])
                ->keyBy(fn (array $meat): int => (int) ($meat['id'] ?? 0))
            : collect((array) data_get($context, 'daily_meats', []))->keyBy(fn (array $meat): int => (int) ($meat['id'] ?? 0));
        $options = collect($allowedIds)
            ->map(fn (int $id): ?array => $available->has($id) ? ['component_id' => $id, 'display_name' => (string) data_get($available->get($id), 'name')] : null)
            ->filter()
            ->values();
        if (count($allowedIds) < 2 || $options->count() !== count($allowedIds) || $options->count() > 5) {
            return $this->staleClarification($event, 'menu_changed');
        }

        $resolution = $this->clarificationResolution((string) $current->content, $options->all());

        return [
            'status' => 'eligible',
            'source_event_id' => (int) $event->id,
            'source_message_id' => (int) $event->message_id,
            'type' => 'ambiguous_meat',
            'scope' => [
                'product_id' => $productId,
                'selection_group' => 'meat',
                'selection_mode' => $selectionMode,
            ],
            'candidate' => [
                'product_id' => $productId,
                'product_slug' => (string) data_get($snapshot, 'candidate.product_slug'),
                'quantity' => max(1, (int) data_get($snapshot, 'candidate.quantity', 1)),
            ],
            'options' => $options->all(),
            'resolution' => $resolution,
        ];
    }

    /** @return array<string, mixed> */
    private function staleClarification(AutomationEvent $event, string $reason): array
    {
        return [
            'status' => 'stale',
            'source_event_id' => (int) $event->id,
            'source_message_id' => (int) $event->message_id,
            'type' => 'ambiguous_meat',
            'resolution' => ['status' => 'stale', 'reason' => $reason],
        ];
    }

    /** @param list<array{component_id:int,display_name:string}> $options @return array<string, mixed> */
    private function clarificationResolution(string $body, array $options): array
    {
        $text = $this->clarificationKey($body);
        foreach ($options as $option) {
            if ($text !== '' && $text === $this->clarificationKey($option['display_name'])) {
                return ['status' => 'resolved', 'match' => 'exact', 'component_id' => $option['component_id']];
            }
        }

        $ordinal = match ($text) {
            '1', 'primeira', 'a primeira' => 0,
            '2', 'segunda', 'a segunda' => 1,
            '3', 'terceira', 'a terceira' => 2,
            '4', 'quarta', 'a quarta' => 3,
            '5', 'quinta', 'a quinta' => 4,
            default => null,
        };
        if ($ordinal !== null && isset($options[$ordinal])) {
            return ['status' => 'resolved', 'match' => 'ordinal', 'component_id' => $options[$ordinal]['component_id']];
        }

        $matchingOptions = collect($options)->filter(function (array $option) use ($text): bool {
            $tokens = preg_split('/[^a-z0-9]+/', $this->clarificationKey($option['display_name'])) ?: [];

            return collect($tokens)
                ->filter(fn (string $token): bool => strlen($token) >= 4)
                ->contains(fn (string $token): bool => str_contains($text, $token));
        });

        if ($matchingOptions->count() === 1) {
            return [
                'status' => 'resolved',
                'match' => 'contextual',
                'component_id' => (int) $matchingOptions->first()['component_id'],
            ];
        }

        return ['status' => $matchingOptions->count() > 1 ? 'ambiguous' : 'invalid'];
    }

    private function clarificationKey(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }

    /**
     * Builds bounded conversational memory from the existing message/event/state sources.
     * The state deltas attached to allowed values are canonical backend data; the model may
     * choose one of them, but it cannot author an arbitrary mutation path or value.
     *
     * @param  Collection<int, Message>  $messages
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>|null  $pendingOrderState
     * @return array<string,mixed>
     */
    private function conversationFrame(Conversation $conversation, Collection $messages, array $context, ?array $pendingOrderState, ?array $boundary = null, ?Message $triggerInbound = null): array
    {
        $current = $triggerInbound instanceof Message
            ? $triggerInbound
            : $messages->reverse()->first(fn (Message $message): bool => $message->direction === 'inbound');
        $memoryEvents = $current instanceof Message
            ? AutomationEvent::query()
                ->where('company_id', $conversation->company_id)
                ->where('conversation_id', $conversation->id)
                ->where('event_type', AutomationEvent::TYPE_COPILOT_AUTOMATION_DECISION)
                ->where('message_id', '<', $current->id)
                ->when($boundary !== null && isset($boundary['at']), function ($query) use ($boundary): void {
                    $query->whereHas('message', fn ($messageQuery) => $messageQuery->where(
                        'created_at',
                        $boundary['inclusive'] ?? false ? '>=' : '>',
                        $boundary['at'],
                    ));
                })
                ->latest('id')
                ->limit(24)
                ->get()
            : collect();
        $goalEvent = $memoryEvents->first(fn (AutomationEvent $event): bool => is_array(data_get($event->payload, 'assistant_goal')));
        $referenceEvent = $memoryEvents->first(fn (AutomationEvent $event): bool => (array) data_get($event->payload, 'conversation_references', []) !== []);
        $storedGoal = data_get($pendingOrderState, 'assistant_goal');
        if (! is_array($storedGoal)) {
            $storedGoal = $goalEvent instanceof AutomationEvent
                ? data_get($goalEvent->payload, 'assistant_goal')
                : null;
        }
        $missingFields = array_values(array_filter(array_map(
            fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) data_get($pendingOrderState, 'missing_fields', []),
        )));
        $storedReferences = data_get($pendingOrderState, 'conversation_references');
        if (! is_array($storedReferences) || $storedReferences === []) {
            $storedReferences = $referenceEvent instanceof AutomationEvent
                ? data_get($referenceEvent->payload, 'conversation_references', [])
                : [];
        }
        $references = collect(is_array($storedReferences) ? $storedReferences : [])
            ->filter(fn (mixed $reference): bool => is_array($reference) && (int) ($reference['product_id'] ?? 0) > 0)
            ->values()
            ->all();
        $goal = is_array($storedGoal) ? $storedGoal : $this->goalFromMissingField($missingFields[0] ?? null, $pendingOrderState);
        $goal = $this->groundGoal($goal, $pendingOrderState, $context, $references);

        return [
            'recent_messages' => array_values((array) data_get($context, 'messages', [])),
            'active_order_state' => data_get($context, 'active_order'),
            'pending_order_state' => $pendingOrderState,
            'pending_slots' => collect($missingFields)
                ->map(fn (string $code): array => [
                    'code' => $code,
                    'slot' => $this->slotForMissingCode($code),
                ])
                ->filter(fn (array $slot): bool => $slot['slot'] !== null)
                ->values()
                ->all(),
            'last_assistant_goal' => $goal,
            'recent_options' => is_array($goal) ? array_values((array) ($goal['allowed_values'] ?? [])) : [],
            'active_references' => $references,
            'business_context' => [
                'products' => $this->relevantProducts($context, $pendingOrderState, $goal, $references),
                'payment_methods' => array_values((array) data_get($context, 'payment.available_methods', [])),
            ],
        ];
    }

    /** @param array<string,mixed>|null $pendingOrderState @return array<string,mixed>|null */
    private function goalFromMissingField(?string $code, ?array $pendingOrderState): ?array
    {
        $slot = $this->slotForMissingCode($code);
        if ($slot === null) {
            return null;
        }

        return [
            'type' => in_array($slot, ['address'], true) ? 'provide_value' : 'choose_option',
            'slot' => $slot,
            'product_id' => (int) data_get($pendingOrderState, 'draft_order.items.0.menu_item_id') ?: null,
        ];
    }

    private function slotForMissingCode(?string $code): ?string
    {
        return match (strtoupper((string) $code)) {
            'CARNE' => 'carne',
            'SALADA' => 'salada',
            'PAYMENT_METHOD' => 'payment_method',
            'FULFILLMENT' => 'fulfillment',
            'ADDRESS' => 'address',
            'ITEM_CONFIRMATION' => 'item_confirmation',
            'MORE_ITEMS' => 'more_items',
            'PAYMENT_PROOF' => 'payment_proof',
            'CONSTRAINT' => 'constraint',
            'N8_VARIANT' => 'product_variant',
            'PRODUCT', 'MENU_ITEM' => 'product',
            'SABOR' => 'sabor',
            'ACOMPANHAMENTO' => 'acompanhamento',
            default => null,
        };
    }

    /** @param array<string,mixed>|null $goal @param array<string,mixed>|null $pendingOrderState @param array<string,mixed> $context @param list<array<string,mixed>> $references @return array<string,mixed>|null */
    private function groundGoal(?array $goal, ?array $pendingOrderState, array $context, array $references): ?array
    {
        if (! is_array($goal) || blank($goal['slot'] ?? null)) {
            return null;
        }

        $slot = $this->canonicalSlot((string) $goal['slot']);
        $draftItems = array_values((array) data_get($pendingOrderState, 'draft_order.items', []));
        $productId = (int) ($goal['product_id'] ?? 0);
        $itemIndex = collect($draftItems)->search(fn (mixed $item): bool => is_array($item)
            && ($productId < 1 || (int) ($item['menu_item_id'] ?? 0) === $productId));
        $itemIndex = $itemIndex === false ? 0 : (int) $itemIndex;
        $item = $draftItems[$itemIndex] ?? null;
        $productId = $productId > 0 ? $productId : (int) data_get($item, 'menu_item_id');
        $product = collect((array) data_get($context, 'menu', []))->firstWhere('id', $productId);
        $allowed = $this->allowedValuesForSlot(
            $slot,
            $itemIndex,
            is_array($item) ? $item : null,
            is_array($product) ? $product : null,
            $context,
            $pendingOrderState,
            $references,
        );

        return [
            'type' => (string) ($goal['type'] ?? ($allowed === [] ? 'provide_value' : 'choose_option')),
            'slot' => $slot,
            'product_id' => $productId > 0 ? $productId : null,
            'allowed_values' => $allowed,
        ];
    }

    private function canonicalSlot(string $slot): string
    {
        return match (Str::of($slot)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString()) {
            'salad', 'salads', 'salada_casa' => 'salada',
            'meat', 'meats' => 'carne',
            'payment', 'pagamento', 'forma_de_pagamento' => 'payment_method',
            'variant', 'n8_variant' => 'product_variant',
            default => Str::of($slot)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString(),
        };
    }

    /** @param array<string,mixed>|null $item @param array<string,mixed>|null $product @param array<string,mixed> $context @param array<string,mixed>|null $pendingOrderState @param list<array<string,mixed>> $references @return list<array<string,mixed>> */
    private function allowedValuesForSlot(string $slot, int $itemIndex, ?array $item, ?array $product, array $context, ?array $pendingOrderState, array $references): array
    {
        if ($slot === 'product') {
            $selectedComponents = collect((array) data_get($pendingOrderState, 'selected_components', []))
                ->filter(fn (mixed $component): bool => is_array($component) && (int) ($component['id'] ?? 0) > 0)
                ->unique('id')
                ->values();
            $selectedDailyComponentIds = $selectedComponents
                ->reject(fn (array $component): bool => ($component['type'] ?? null) === 'meat')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $selectedMeats = $selectedComponents
                ->where('type', 'meat')
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
            $referenceIds = collect($references)
                ->pluck('product_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique();
            $productIds = ($referenceIds->count() >= 1 && $referenceIds->count() <= 2
                ? $referenceIds
                : collect((array) data_get($pendingOrderState, 'offered_product_ids', [])))
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique();

            return collect((array) data_get($context, 'menu', []))
                ->filter(fn (mixed $candidate): bool => is_array($candidate)
                    && $productIds->contains((int) ($candidate['id'] ?? 0)))
                ->map(fn (array $candidate): array => [
                    'id' => (string) $candidate['id'],
                    'label' => (string) $candidate['name'],
                    'aliases' => array_values(array_filter([
                        (string) ($candidate['slug'] ?? ''),
                        Str::afterLast((string) ($candidate['name'] ?? ''), ' '),
                    ])),
                    'state_delta' => [
                        "items.{$itemIndex}.menu_item_id" => (int) $candidate['id'],
                        "items.{$itemIndex}.menu_item_slug" => (string) $candidate['slug'],
                        "items.{$itemIndex}.quantity" => 1,
                        "items.{$itemIndex}.selections" => ['meat' => null, 'meats' => $selectedMeats],
                        "items.{$itemIndex}.daily_component_ids" => $selectedDailyComponentIds,
                        "items.{$itemIndex}.removed_components" => [],
                        "items.{$itemIndex}.item_notes" => '',
                    ],
                ])
                ->values()
                ->all();
        }
        if ($slot === 'payment_method') {
            return collect((array) data_get($context, 'payment.available_methods', []))
                ->map(fn (mixed $method): array => [
                    'id' => (string) $method,
                    'label' => (string) $method,
                    'state_delta' => ['payment_method' => (string) $method],
                ])->values()->all();
        }
        if ($slot === 'fulfillment') {
            return [
                ['id' => 'pickup', 'label' => 'Retirada', 'state_delta' => ['fulfillment' => 'pickup']],
                ['id' => 'delivery', 'label' => 'Entrega', 'state_delta' => ['fulfillment' => 'delivery']],
            ];
        }
        if ($slot === 'product_variant') {
            $candidateItem = collect((array) data_get($pendingOrderState, 'candidate_items', []))
                ->first(fn (mixed $candidate): bool => is_array($candidate) && ($candidate['status'] ?? 'candidate') === 'candidate');
            $candidateIds = is_array($candidateItem)
                ? collect((array) ($candidateItem['variant_candidates'] ?? []))->map(fn (mixed $id): int => (int) $id)->filter()->unique()
                : collect();

            return collect((array) data_get($context, 'menu', []))
                ->filter(fn (mixed $candidate): bool => is_array($candidate)
                    && ($candidateIds->isNotEmpty()
                        ? $candidateIds->contains((int) ($candidate['id'] ?? 0))
                        : in_array((string) ($candidate['rule'] ?? ''), ['n8_casa', 'n8_tradicional'], true)))
                ->map(function (array $candidate) use ($candidateItem, $itemIndex): array {
                    $selectedItem = [
                        'menu_item_id' => (int) $candidate['id'],
                        'menu_item_slug' => (string) $candidate['slug'],
                        'quantity' => max(1, (int) data_get($candidateItem, 'quantity', 1)),
                        'selections' => [
                            'meat' => null,
                            'meats' => array_values((array) data_get($candidateItem, 'meats', [])),
                        ],
                        'daily_component_ids' => array_values((array) data_get($candidateItem, 'daily_component_ids', [])),
                        'removed_components' => [],
                        'item_notes' => (string) data_get($candidateItem, 'notes', ''),
                        'candidate_origin' => [
                            'family' => (string) data_get($candidateItem, 'family', ''),
                            'provenance' => (string) data_get($candidateItem, 'provenance', ''),
                        ],
                    ];

                    return [
                        'id' => (string) $candidate['id'],
                        'label' => (string) $candidate['name'],
                        'aliases' => array_values(array_filter([(string) ($candidate['slug'] ?? ''), Str::afterLast((string) ($candidate['name'] ?? ''), ' ')])),
                        'state_delta' => [
                            "items.{$itemIndex}" => $selectedItem,
                        ],
                    ];
                })->values()->all();
        }
        if ($slot === 'acompanhamento') {
            $candidateIds = collect((array) data_get($item, 'daily_component_candidates', []))
                ->flatMap(fn (mixed $candidate): array => is_array($candidate) ? (array) ($candidate['component_ids'] ?? []) : [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();
            if ($candidateIds->isNotEmpty()) {
                return collect((array) data_get($context, 'operational_catalog.components', []))
                    ->filter(fn (mixed $component): bool => is_array($component)
                        && (bool) ($component['available_today'] ?? false)
                        && $candidateIds->contains((int) ($component['id'] ?? 0)))
                    ->map(fn (array $component): array => [
                        'id' => (string) $component['id'],
                        'label' => (string) $component['name'],
                        'aliases' => array_values(array_filter([(string) ($component['slug'] ?? '')])),
                        'state_delta' => ["items.{$itemIndex}.daily_component_ids" => [(int) $component['id']]],
                    ])
                    ->unique('id')
                    ->values()
                    ->all();
            }
        }
        if (! is_array($product)) {
            return [];
        }

        $group = collect((array) ($product['groups'] ?? []))->first(fn (mixed $candidate): bool => is_array($candidate)
            && $this->canonicalSlot((string) ($candidate['code'] ?? '')) === $slot);
        $options = is_array($group) ? collect((array) ($group['options'] ?? [])) : collect();
        if ($slot === 'carne' && in_array((string) ($product['rule'] ?? ''), ['n8_tradicional', 'n9_tradicional'], true)) {
            $options = collect((array) data_get($context, 'daily_meats', []));
        }
        $selectionMode = (string) data_get($group, 'selection_mode', 'single');
        $selectionKey = $slot === 'carne'
            ? ($selectionMode === 'multiple' || in_array((string) ($product['rule'] ?? ''), ['n8_tradicional', 'n9_tradicional'], true) ? 'meats' : 'meat')
            : (string) data_get($group, 'code', $slot);

        return $options
            ->filter(fn (mixed $option): bool => is_array($option) && filled($option['name'] ?? null))
            ->map(fn (array $option): array => [
                'id' => (string) ($option['id'] ?? $option['component_id'] ?? ''),
                'label' => (string) $option['name'],
                'aliases' => array_values(array_filter([(string) ($option['slug'] ?? '')])),
                'state_delta' => [
                    "items.{$itemIndex}.selections.{$selectionKey}" => $selectionKey === 'meats'
                        ? [(string) $option['name']]
                        : (string) $option['name'],
                ],
            ])
            ->unique('id')
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $context @param array<string,mixed>|null $pendingOrderState @param array<string,mixed>|null $goal @param list<array<string,mixed>> $references @return list<array<string,mixed>> */
    private function relevantProducts(array $context, ?array $pendingOrderState, ?array $goal, array $references): array
    {
        $ids = collect((array) data_get($pendingOrderState, 'draft_order.items', []))
            ->pluck('menu_item_id')
            ->merge([(int) data_get($goal, 'product_id')])
            ->merge(collect($references)->pluck('product_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $latest = Str::of((string) data_get($context, 'latest_message.body', ''))->ascii()->lower()->toString();
        $tokens = collect(preg_split('/[^a-z0-9]+/', $latest) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 2)
            ->values();
        $menu = collect((array) data_get($context, 'menu', []));
        $matched = $menu->filter(function (mixed $product) use ($ids, $tokens): bool {
            if (! is_array($product)) {
                return false;
            }
            if ($ids->contains((int) ($product['id'] ?? 0))) {
                return true;
            }

            $identity = Str::of(implode(' ', [(string) ($product['name'] ?? ''), (string) ($product['slug'] ?? '')]))->ascii()->lower()->toString();

            return $tokens->contains(fn (string $token): bool => strlen($token) >= 2 && str_contains($identity, $token));
        });
        if ($matched->isEmpty()) {
            $matched = $menu->filter(fn (mixed $product): bool => is_array($product)
                && in_array((string) ($product['product_type'] ?? ''), [Product::TYPE_MARMITA, Product::TYPE_COMBO], true));
        }

        return $matched->take(8)
            ->map(fn (array $product): array => [
                ...$product,
                'decision_facts' => $this->decisionFacts->fromProduct($product),
            ])
            ->values()
            ->all();
    }
}
