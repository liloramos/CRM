<?php

namespace App\Services\Operational;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\Menu\MenuAvailabilityService;
use Illuminate\Support\Collection;

class OperationalCrmPresenter
{
    public function __construct(private readonly MenuAvailabilityService $menuAvailability) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Company $company): array
    {
        $orders = Order::query()
            ->with([
                'payerCustomer',
                'items.options',
                'statusHistories' => fn ($query) => $query->latest()->limit(8),
                'latestPrintJob',
                'payments',
            ])
            ->where('company_id', $company->id)
            ->latest()
            ->limit(30)
            ->get();

        $conversations = Conversation::query()
            ->with([
                'customer',
                'messages' => fn ($query) => $query->latest()->limit(8),
                'orders' => fn ($query) => $query->latest()->limit(1),
            ])
            ->where('company_id', $company->id)
            ->latest('started_at')
            ->limit(30)
            ->get();

        $customers = Customer::query()
            ->where('company_id', $company->id)
            ->orderBy('name')
            ->limit(50)
            ->get();

        $products = $this->menuAvailability
            ->availableProducts($company)
            ->get();

        return [
            'company' => [
                'id' => (string) $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ],
            'orders' => $orders->map(fn (Order $order): array => $this->order($order))->values(),
            'conversations' => $conversations->map(fn (Conversation $conversation): array => $this->conversation($conversation))->values(),
            'customers' => $customers->map(fn (Customer $customer): array => $this->customer($customer))->values(),
            'products' => $products->map(fn (Product $product): array => $this->product($product))->values(),
            'deliveries' => $orders
                ->filter(fn (Order $order): bool => $order->fulfillment_type !== null)
                ->map(fn (Order $order): array => $this->deliveryTask($order))
                ->values(),
            'financeEntries' => $orders->map(fn (Order $order): array => $this->financeEntry($order))->values(),
            'financialSummary' => $this->financialSummary($orders),
            'expenses' => [],
            'paymentMethods' => $this->paymentMethods($orders),
            'integrations' => $this->integrations(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(Order $order): array
    {
        $customer = $order->payerCustomer;

        return [
            'id' => (string) $order->id,
            'code' => $order->code,
            'customer' => $this->customer($customer),
            'status' => $this->mapOrderStatus((string) $order->status),
            'paymentStatus' => $this->mapPaymentStatus((string) $order->payment_status),
            'fulfillmentType' => $this->mapFulfillmentType((string) ($order->fulfillment_type ?: Order::FULFILLMENT_PICKUP)),
            'printStatus' => $this->mapPrintStatus((string) $order->print_status),
            'channel' => $this->mapChannel((string) $order->origin_channel),
            'createdLabel' => $order->created_at?->format('d/m H:i') ?? 'Agora',
            'pickupPerson' => $order->pickup_person_name,
            'deliveryLabel' => $order->delivery_reference ?: $order->delivery_recipient_name,
            'generalNotes' => $order->general_notes ?: 'Sem observacoes gerais.',
            'kitchenNotes' => $order->kitchen_notes ?: 'Sem observacoes de cozinha.',
            'total' => $this->cents((int) $order->total_cents),
            'paid' => $this->cents((int) $order->amount_paid_cents),
            'creditUsed' => $this->cents((int) $order->credit_used_cents),
            'deliveryFee' => $this->cents((int) ($order->delivery_fee_cents ?? 0)),
            'amountDue' => $this->cents((int) $order->amount_due_cents),
            'items' => $order->items
                ->sortBy('sort_order')
                ->map(fn ($item): array => [
                    'id' => (string) $item->id,
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => $this->cents((int) $item->unit_price_cents),
                    'notes' => $item->item_notes ?: 'Sem observacao por item.',
                    'beneficiary' => $item->beneficiary_name ?: $customer?->name ?: 'A confirmar',
                    'additions' => $item->options->pluck('name')->values()->all(),
                    'unavailable' => false,
                ])
                ->values(),
            'history' => $order->statusHistories
                ->map(fn ($history): array => [
                    'id' => (string) $history->id,
                    'title' => $this->statusLabel((string) $history->to_status),
                    'description' => $history->notes ?: $history->reason ?: 'Atualizacao operacional registrada.',
                    'timeLabel' => $history->created_at?->format('d/m H:i') ?? '',
                ])
                ->values(),
            'ticketPreviewUrl' => $order->latestPrintJob?->preview_url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function product(Product $product): array
    {
        return [
            'id' => (string) $product->id,
            'category' => $product->category?->name ?? 'Cardapio',
            'name' => $product->name,
            'description' => $product->description ?: $product->notes_hint ?: 'Produto cadastrado para operacao do cardapio.',
            'price' => $this->cents((int) ($product->base_price_cents ?? 0)),
            'available' => (bool) ($product->is_active && $product->is_available_by_default),
            'tags' => collect([
                $product->product_type,
                $product->menu_rule_code,
                $product->allows_item_notes ? 'aceita observacao' : null,
            ])->filter()->values()->all(),
            'options' => $product->options
                ->map(fn ($option): array => [
                    'id' => (string) $option->id,
                    'name' => $option->name,
                    'type' => $option->option_type,
                    'groupCode' => $option->group_code ?: 'componentes',
                    'groupLabel' => $this->optionGroupLabel($option->group_code),
                    'priceDelta' => $this->cents((int) $option->price_delta_cents),
                    'required' => (bool) $option->is_required,
                    'availableToday' => $this->optionAvailableToday($option),
                    'dailyReason' => $this->optionDailyReason($option),
                ])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(Conversation $conversation): array
    {
        $messages = $conversation->messages
            ->sortBy('created_at')
            ->map(fn ($message): array => [
                'id' => (string) $message->id,
                'sender' => $this->mapSender((string) $message->sender),
                'body' => $message->content,
                'timeLabel' => $message->created_at?->format('H:i') ?? '',
            ])
            ->values();

        return [
            'id' => (string) $conversation->id,
            'customer' => $this->customer($conversation->customer),
            'mode' => $this->mapAutomationMode((string) $conversation->automation_mode, (bool) $conversation->human_review_required),
            'unread' => 0,
            'statusLabel' => $conversation->automation_status ?: $conversation->status,
            'lastMessage' => $messages->last()['body'] ?? 'Sem mensagens recentes.',
            'messages' => $messages,
            'linkedOrderId' => $conversation->orders->first()?->id ? (string) $conversation->orders->first()->id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(?Customer $customer): array
    {
        return [
            'id' => $customer ? (string) $customer->id : 'pending-customer',
            'name' => $customer?->name ?: 'Cliente a confirmar',
            'phoneLabel' => $customer?->phone ?: 'Sem telefone cadastrado',
            'tags' => ['Operacao'],
            'creditBalance' => $this->cents((int) ($customer?->credit_balance_cents ?? 0)),
            'notes' => $customer?->notes ? [$customer->notes] : [],
            'preferences' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryTask(Order $order): array
    {
        return [
            'id' => (string) $order->id,
            'orderCode' => $order->code,
            'type' => $this->mapFulfillmentType((string) ($order->fulfillment_type ?: Order::FULFILLMENT_PICKUP)),
            'status' => $order->fulfillment_status ?: 'pending',
            'recipient' => $order->delivery_recipient_name ?: $order->pickup_person_name ?: $order->payerCustomer?->name ?: 'A confirmar',
            'routeLabel' => $order->delivery_reference ?: $order->pickup_notes ?: 'Sem rota externa integrada.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeEntry(Order $order): array
    {
        return [
            'id' => (string) $order->id,
            'label' => 'Pedido '.$order->code,
            'orderCode' => $order->code,
            'status' => $this->mapPaymentStatus((string) $order->payment_status),
            'amount' => $this->cents((int) $order->total_cents),
            'receivedAmount' => $this->cents((int) $order->amount_paid_cents),
            'pendingAmount' => $this->cents((int) $order->amount_due_cents),
            'creditApplied' => $this->cents((int) $order->credit_used_cents),
            'method' => $this->paymentMethodLabel((string) ($order->payment_method ?: 'a_confirmar')),
            'paymentMethod' => $this->mapPaymentMethod((string) ($order->payment_method ?: 'a_confirmar')),
            'createdLabel' => $order->created_at?->format('d/m H:i') ?? '',
            'description' => $order->payment_confirmed_at ? 'Pagamento confirmado por atendente.' : 'Aguardando conferencia humana.',
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<string, mixed>
     */
    private function financialSummary(Collection $orders): array
    {
        $gross = (int) $orders->sum('total_cents');
        $confirmed = (int) $orders->where('payment_status', Payment::ORDER_STATUS_PAID)->sum('amount_paid_cents');
        $pending = (int) $orders->sum('amount_due_cents');
        $pix = (int) $orders->where('payment_method', Payment::METHOD_PIX)->sum('amount_paid_cents');
        $credit = (int) $orders->sum('credit_used_cents');

        return [
            'dateLabel' => now()->format('d/m/Y'),
            'ordersCount' => $orders->count(),
            'paidOrders' => $orders->where('payment_status', Payment::ORDER_STATUS_PAID)->count(),
            'pendingOrders' => $orders->where('amount_due_cents', '>', 0)->count(),
            'grossRevenue' => $this->cents($gross),
            'confirmedRevenue' => $this->cents($confirmed),
            'pendingAmount' => $this->cents($pending),
            'expensesAmount' => 0,
            'netProfit' => $this->cents($confirmed),
            'pixAmount' => $this->cents($pix),
            'creditUsed' => $this->cents($credit),
            'customerCreditBalance' => 0,
            'averageTicket' => $orders->count() > 0 ? $this->cents((int) round($gross / $orders->count())) : 0,
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return list<array<string, mixed>>
     */
    private function paymentMethods(Collection $orders): array
    {
        $total = max((int) $orders->sum('amount_paid_cents'), 1);

        return $orders
            ->groupBy(fn (Order $order): string => (string) ($order->payment_method ?: 'a_confirmar'))
            ->map(function (Collection $group, string $method) use ($total): array {
                $amount = (int) $group->sum('amount_paid_cents');

                return [
                    'method' => $this->mapPaymentMethod($method),
                    'label' => $this->paymentMethodLabel($method),
                    'amount' => $this->cents($amount),
                    'count' => $group->count(),
                    'percentage' => (int) round(($amount / $total) * 100),
                    'tone' => $method === Payment::METHOD_PIX ? 'brand' : 'neutral',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, string>>
     */
    private function integrations(): array
    {
        return [
            [
                'id' => 'whatsapp',
                'title' => 'WhatsApp Meta Cloud API',
                'status' => config('chatbotcrm.whatsapp.provider') === 'meta' ? 'warning' : 'offline',
                'description' => 'Provider plugavel preparado; sem credenciais reais ativas neste MVP local.',
            ],
            [
                'id' => 'ai',
                'title' => 'IA / n8n',
                'status' => config('chatbotcrm.ai.provider') === 'n8n' ? 'warning' : 'offline',
                'description' => 'Automacao em modo seguro, com confirmacao humana para ambiguidades.',
            ],
            [
                'id' => 'printing',
                'title' => 'Impressao HTML',
                'status' => 'warning',
                'description' => 'Previa HTML operacional; impressao fisica real continua pendente de configuracao.',
            ],
            [
                'id' => 'google',
                'title' => 'Google Workspace',
                'status' => 'offline',
                'description' => 'Estrutura documental preparada, sem OAuth ativo ou tokens reais.',
            ],
        ];
    }

    private function cents(int $value): float
    {
        return round($value / 100, 2);
    }

    private function mapOrderStatus(string $status): string
    {
        return match ($status) {
            Order::STATUS_AWAITING_CUSTOMER_CONFIRMATION => 'em_conferencia',
            Order::STATUS_CONFIRMED, Order::STATUS_AWAITING_PAYMENT, Order::STATUS_AWAITING_PAYMENT_PROOF => 'aguardando_pagamento',
            Order::STATUS_PAYMENT_PROOF_RECEIVED => 'comprovante_recebido',
            Order::STATUS_PAYMENT_CONFIRMED => 'pagamento_confirmado',
            Order::STATUS_READY_TO_PRINT => 'pronto_para_imprimir',
            Order::STATUS_PRINTED => 'impresso',
            Order::STATUS_IN_PREPARATION => 'em_preparo',
            Order::STATUS_READY_FOR_PICKUP => 'pronto',
            Order::STATUS_OUT_FOR_DELIVERY => 'saiu_para_entrega',
            Order::STATUS_FINISHED => 'finalizado',
            Order::STATUS_CANCELLED => 'cancelado',
            default => 'novo',
        };
    }

    private function mapPaymentStatus(string $status): string
    {
        return match ($status) {
            Payment::ORDER_STATUS_PAID, Payment::STATUS_CONFIRMED => 'pago',
            Payment::ORDER_STATUS_PARTIAL => 'parcial',
            Payment::ORDER_STATUS_OVERPAID => 'credito',
            Payment::STATUS_PROOF_RECEIVED, Payment::ORDER_STATUS_REJECTED, Payment::STATUS_REJECTED => 'revisao_humana',
            default => 'pendente',
        };
    }

    private function mapPrintStatus(string $status): string
    {
        return match ($status) {
            Order::PRINT_STATUS_PRINTING, Order::PRINT_STATUS_QUEUED => 'imprimindo',
            Order::PRINT_STATUS_PRINTED, Order::PRINT_STATUS_MANUAL_CONFIRMED, Order::PRINT_STATUS_WAIVED => 'impresso',
            Order::PRINT_STATUS_REPRINT_REQUESTED => 'reimpressao',
            Order::PRINT_STATUS_FAILED, Order::PRINT_STATUS_PRINTER_UNAVAILABLE => 'erro',
            default => 'aguardando',
        };
    }

    private function mapFulfillmentType(string $type): string
    {
        return match ($type) {
            Order::FULFILLMENT_DELIVERY => 'entrega',
            Order::FULFILLMENT_COUNTER, Order::FULFILLMENT_DINE_IN => 'balcao',
            default => 'retirada',
        };
    }

    private function mapChannel(string $channel): string
    {
        return match ($channel) {
            Order::CHANNEL_WHATSAPP => 'WhatsApp',
            Order::CHANNEL_COUNTER => 'Balcao',
            default => 'Manual',
        };
    }

    private function mapSender(string $sender): string
    {
        return match ($sender) {
            'attendant', 'user' => 'attendant',
            'ai', 'assistant' => 'ai',
            default => 'customer',
        };
    }

    private function mapAutomationMode(string $mode, bool $humanReviewRequired): string
    {
        if ($humanReviewRequired) {
            return 'atencao';
        }

        return $mode === Conversation::AUTOMATION_MODE_MANUAL ? 'manual' : 'ia';
    }

    private function mapPaymentMethod(string $method): string
    {
        return match ($method) {
            Payment::METHOD_PIX => 'pix',
            Payment::METHOD_CASH => 'dinheiro',
            Payment::METHOD_DEBIT_CARD, Payment::METHOD_CREDIT_CARD => 'cartao',
            Payment::METHOD_CUSTOMER_CREDIT => 'credito_cliente',
            Payment::METHOD_MIXED => 'misto',
            default => 'a_confirmar',
        };
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            Payment::METHOD_PIX => 'Pix',
            Payment::METHOD_CASH => 'Dinheiro',
            Payment::METHOD_DEBIT_CARD => 'Cartao debito',
            Payment::METHOD_CREDIT_CARD => 'Cartao credito',
            Payment::METHOD_CUSTOMER_CREDIT => 'Credito do cliente',
            Payment::METHOD_MIXED => 'Misto',
            default => 'A confirmar',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Order::STATUS_READY_TO_PRINT => 'Pronto para imprimir',
            Order::STATUS_PRINTED => 'Comanda impressa',
            Order::STATUS_IN_PREPARATION => 'Em preparo',
            Order::STATUS_FINISHED => 'Finalizado',
            Order::STATUS_CANCELLED => 'Cancelado',
            default => 'Status atualizado',
        };
    }

    private function optionAvailableToday(ProductOption $option): bool
    {
        $override = $option->relationLoaded('dailyMenuOptionOverrides')
            ? $option->dailyMenuOptionOverrides->first()
            : null;

        return (bool) $option->is_active && $override?->status !== 'unavailable';
    }

    private function optionDailyReason(ProductOption $option): ?string
    {
        if (! $option->relationLoaded('dailyMenuOptionOverrides')) {
            return null;
        }

        return $option->dailyMenuOptionOverrides->first()?->reason;
    }

    private function optionGroupLabel(?string $groupCode): string
    {
        return match ($groupCode) {
            'base', 'bases', 'guarnicoes' => 'Bases/guarnicoes',
            'salada' => 'Saladas',
            'carne', 'bife' => 'Carnes',
            'bebidas' => 'Bebidas',
            'adicionais' => 'Adicionais',
            default => 'Componentes',
        };
    }
}
