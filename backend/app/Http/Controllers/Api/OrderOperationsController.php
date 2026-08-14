<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DailyMenuOptionOverride;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\Delivery\DeliveryWorkflowService;
use App\Services\Operational\OperationalCrmPresenter;
use App\Services\Orders\OrderCleanupService;
use App\Services\Orders\OrderItemSelectionValidator;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderOperationsController extends Controller
{
    use ResolvesOperationalCompany;

    public function index(Request $request, OperationalCrmPresenter $presenter): JsonResponse
    {
        $company = $this->resolveCompany($request);

        $orders = Order::query()
            ->with($this->orderRelations())
            ->where('company_id', $company->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Order $order): array => $presenter->order($order))
            ->values();

        return response()->json(['data' => $orders]);
    }

    public function show(Request $request, Order $order, OperationalCrmPresenter $presenter): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        return response()->json([
            'data' => $presenter->order($order->load($this->orderRelations())),
        ]);
    }

    public function storeDraft(
        Request $request,
        OrderWorkflowService $orders,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        $validated = $request->validate([
            'payer_customer_id' => ['nullable', 'integer'],
            'customer_name_snapshot' => ['nullable', 'string', 'max:120'],
            'customer_phone_snapshot' => ['nullable', 'string', 'max:40'],
            'origin_channel' => ['nullable', Rule::in([Order::CHANNEL_MANUAL, Order::CHANNEL_COUNTER, Order::CHANNEL_PHONE, Order::CHANNEL_OTHER])],
            'fulfillment_type' => ['nullable', Rule::in([Order::FULFILLMENT_PICKUP, Order::FULFILLMENT_DELIVERY, Order::FULFILLMENT_COUNTER])],
            'general_notes' => ['nullable', 'string', 'max:1000'],
            'kitchen_notes' => ['nullable', 'string', 'max:1000'],
            'pickup_person_name' => ['nullable', 'string', 'max:120'],
        ]);

        $customer = null;
        if (! empty($validated['payer_customer_id'])) {
            $customer = Customer::query()
                ->where('company_id', $company->id)
                ->whereKey($validated['payer_customer_id'])
                ->first();

            if (! $customer) {
                throw ValidationException::withMessages([
                    'payer_customer_id' => ['Cliente nao pertence ao restaurante atual.'],
                ]);
            }
        }

        $validated['customer_name_snapshot'] = Str::squish((string) ($validated['customer_name_snapshot'] ?? ''));
        $validated['customer_phone_snapshot'] = Str::squish((string) ($validated['customer_phone_snapshot'] ?? ''));

        if ($customer) {
            $validated['customer_name_snapshot'] = $validated['customer_name_snapshot'] !== ''
                ? $validated['customer_name_snapshot']
                : $customer->name;
            $validated['customer_phone_snapshot'] = $validated['customer_phone_snapshot'] !== ''
                ? $validated['customer_phone_snapshot']
                : (string) ($customer->phone ?? '');
        }

        if (! $customer && $validated['customer_name_snapshot'] === '') {
            throw ValidationException::withMessages([
                'customer_name_snapshot' => ['Informe o nome do cliente ou selecione um cliente cadastrado.'],
            ]);
        }

        $validated['customer_name_snapshot'] = $validated['customer_name_snapshot'] !== '' ? $validated['customer_name_snapshot'] : null;
        $validated['customer_phone_snapshot'] = $validated['customer_phone_snapshot'] !== '' ? $validated['customer_phone_snapshot'] : null;

        $order = $orders->createDraft($company, [
            ...$validated,
            'created_by_user_id' => $request->user()?->id,
            'origin_channel' => $validated['origin_channel'] ?? Order::CHANNEL_MANUAL,
            'entry_mode' => Order::CHANNEL_MANUAL,
            'fulfillment_type' => $validated['fulfillment_type'] ?? Order::FULFILLMENT_PICKUP,
            'is_manual' => true,
            'human_review_required' => true,
            'customer_confirmation_required' => true,
            'status_notes' => 'Rascunho criado pela interface operacional.',
        ]);

        return response()->json([
            'data' => $presenter->order($order->load($this->orderRelations())),
        ], 201);
    }

    public function addItem(
        Request $request,
        Order $order,
        OrderWorkflowService $orders,
        OrderItemSelectionValidator $selectionValidator,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'item_notes' => ['nullable', 'string', 'max:1000'],
            'beneficiary_name' => ['nullable', 'string', 'max:120'],
            'options' => ['sometimes', 'array'],
            'options.*.product_option_id' => ['required_with:options', 'integer'],
            'options.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'structured_options' => ['sometimes', 'array'],
            'structured_options.*.component_link_id' => ['nullable', 'integer'],
            'structured_options.*.product_link_id' => ['nullable', 'integer'],
            'structured_options.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'included_component_ids' => ['sometimes', 'array'],
            'included_component_ids.*' => ['integer'],
            'removed_component_ids' => ['sometimes', 'array'],
            'removed_component_ids.*' => ['integer'],
            'meat_mode' => ['nullable', 'string', 'in:traditional,beef_only'],
            'traditional_meat_component_ids' => ['sometimes', 'array'],
            'traditional_meat_component_ids.*' => ['integer'],
            'additions' => ['sometimes', 'array'],
            'additions.*.code' => ['required_with:additions', 'string', 'max:80'],
            'additions.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $product = Product::query()
            ->with('optionGroups')
            ->where('company_id', $company->id)
            ->whereKey($validated['product_id'])
            ->firstOrFail();

        try {
            if ($product->optionGroups->isNotEmpty()) {
                $selection = $selectionValidator->validateStructuredSelections(
                    $company->loadMissing('setting'),
                    $product,
                    $this->orderDateForSelection($order, $company),
                    $validated['structured_options'] ?? [],
                    [
                        'meat_mode' => $validated['meat_mode'] ?? 'traditional',
                        'traditional_meat_component_ids' => $validated['traditional_meat_component_ids'] ?? [],
                        'extra_beef_quantity' => $this->additionQuantity($validated['additions'] ?? [], 'extra_beef'),
                    ],
                    (int) $validated['quantity'],
                    [
                        'included_component_ids' => $validated['included_component_ids'] ?? [],
                        'removed_component_ids' => $validated['removed_component_ids'] ?? [],
                    ],
                );

                $validated['options'] = $selection['options'];

                foreach (['unit_price_cents', 'selected_components', 'removed_ingredients'] as $key) {
                    if (array_key_exists($key, $selection)) {
                        $validated[$key] = $selection[$key];
                    }
                }
            } else {
                $validated['options'] = $this->validatedOptionRows(
                    companyId: (int) $company->id,
                    product: $product,
                    optionRows: $validated['options'] ?? [],
                );
            }
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'Revise as escolhas do item.',
                'errors' => $exception->errors(),
            ], 422);
        }

        try {
            $orders->addItem($order, $product, $validated);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->refresh()->load($this->orderRelations())),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $additions
     */
    private function additionQuantity(array $additions, string $code): int
    {
        return collect($additions)
            ->filter(fn (array $addition): bool => ($addition['code'] ?? null) === $code)
            ->sum(fn (array $addition): int => max(1, (int) ($addition['quantity'] ?? 1)));
    }

    public function cancel(
        Request $request,
        Order $order,
        OrderWorkflowService $orders,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($order->payments()->where('status', Payment::STATUS_CONFIRMED)->exists()) {
            return response()->json([
                'message' => 'Anule a confirmacao do pagamento antes de cancelar este pedido.',
            ], 422);
        }

        try {
            $orders->transitionTo(
                $order,
                Order::STATUS_CANCELLED,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? 'Pedido cancelado pela interface operacional.',
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->refresh()->load($this->orderRelations())),
        ]);
    }

    public function voidPayment(
        Request $request,
        Order $order,
        PaymentWorkflowService $payments,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $payments->voidLatestConfirmedPayment($order, $request->user(), $validated['reason']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->refresh()->load($this->orderRelations())),
        ]);
    }

    public function confirmPayment(
        Request $request,
        Order $order,
        PaymentWorkflowService $payments,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        $validated = $request->validate([
            'method' => ['required', Rule::in(Payment::METHODS)],
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'overpayment_action' => ['nullable', Rule::in([
                Payment::OVERPAYMENT_PENDING_REVIEW,
                Payment::OVERPAYMENT_KEEP_AS_CREDIT,
                Payment::OVERPAYMENT_REFUND,
            ])],
        ]);

        try {
            $payments->confirmOrderPayment($order, $request->user(), [
                ...$validated,
                'customer_id' => $order->payer_customer_id,
            ]);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->refresh()->load($this->orderRelations())),
        ]);
    }

    public function destroyDraft(
        Request $request,
        Order $order,
        OrderWorkflowService $orders,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        try {
            $orders->deleteEmptyDraft($order);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => [
                'deleted' => true,
                'id' => (string) $order->id,
            ],
        ]);
    }

    public function destroyPermanently(
        Request $request,
        Order $order,
        OrderCleanupService $cleanup,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'in:EXCLUIR'],
        ]);

        try {
            $result = $cleanup->deleteOnePermanently($company, $order);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($result['blocked'] !== []) {
            return $this->permanentDeletionBlockedResponse($result, singleOrder: true);
        }

        return response()->json([
            'data' => [
                ...$result,
                'confirmation' => $validated['confirmation'],
            ],
        ]);
    }

    public function destroyManyPermanently(
        Request $request,
        OrderCleanupService $cleanup,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'integer', 'min:1'],
            'confirmation' => ['required', 'string', 'in:EXCLUIR'],
        ]);

        try {
            $result = $cleanup->deleteManyPermanently(
                $company,
                array_map('intval', $validated['order_ids']),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($result['blocked'] !== []) {
            return $this->permanentDeletionBlockedResponse($result, singleOrder: false);
        }

        return response()->json([
            'data' => [
                ...$result,
                'confirmation' => $validated['confirmation'],
            ],
        ]);
    }

    public function destroyManyForTesting(
        Request $request,
        OrderCleanupService $cleanup,
    ): JsonResponse {
        $company = $this->resolveCompany($request);

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'integer', 'min:1'],
            'confirmation' => ['required', 'string', 'in:EXCLUIR'],
        ]);

        try {
            $result = $cleanup->deleteManyForTesting(
                $company,
                array_map('intval', $validated['order_ids']),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => [
                ...$result,
                'confirmation' => $validated['confirmation'],
            ],
        ]);
    }

    public function updateStatus(
        Request $request,
        Order $order,
        OrderWorkflowService $orders,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'reason' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $orders->transitionTo(
                $order,
                $validated['status'],
                $request->user(),
                $validated['reason'] ?? 'manual_status_change',
                $validated['notes'] ?? 'Status alterado pela interface operacional.',
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->refresh()->load($this->orderRelations())),
        ]);
    }

    public function advanceFulfillment(Request $request, Order $order, string $action, DeliveryWorkflowService $delivery, OperationalCrmPresenter $presenter): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        try {
            $updated = match ($action) {
                'ready' => $delivery->markReady($order, $request->user()),
                'start-delivery' => $delivery->startDelivery($order, $request->user()),
                'delivered' => $delivery->markDelivered($order, $request->user()),
                'picked-up' => $delivery->markPickedUp($order, $request->user()),
                default => throw new DomainException('Acao operacional nao suportada.'),
            };
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $presenter->order($updated->refresh()->load($this->orderRelations()))]);
    }

    public function previewTicket(
        Request $request,
        Order $order,
        PrintWorkflowService $printing,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        try {
            $printJob = $printing->generateTicket($order, $request->user());
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => [
                'order' => $presenter->order($order->refresh()->load($this->orderRelations())),
                'preview' => [
                    'id' => (string) $printJob->id,
                    'status' => $printJob->status,
                    'html' => $printJob->html_content,
                    'previewUrl' => $printJob->preview_url,
                    'generatedAt' => $printJob->previewed_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $optionRows
     * @return list<array<string, mixed>>
     */
    private function validatedOptionRows(int $companyId, Product $product, array $optionRows): array
    {
        $optionIds = collect($optionRows)
            ->pluck('product_option_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($optionIds->isEmpty()) {
            return [];
        }

        $options = ProductOption::query()
            ->where('company_id', $companyId)
            ->active()
            ->whereIn('id', $optionIds)
            ->where(function ($query) use ($product): void {
                $query->whereNull('product_id')
                    ->orWhere('product_id', $product->id);
            })
            ->get()
            ->keyBy('id');

        if ($options->count() !== $optionIds->count()) {
            throw ValidationException::withMessages([
                'options' => ['Uma ou mais opcoes nao pertencem ao produto ou restaurante atual.'],
            ]);
        }

        $unavailableOptionIds = DailyMenuOptionOverride::query()
            ->where('company_id', $companyId)
            ->whereDate('availability_date', now()->toDateString())
            ->where('status', DailyMenuOptionOverride::STATUS_UNAVAILABLE)
            ->whereIn('product_option_id', $optionIds)
            ->pluck('product_option_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($unavailableOptionIds !== []) {
            throw ValidationException::withMessages([
                'options' => ['Uma ou mais opcoes selecionadas estao indisponiveis hoje.'],
            ]);
        }

        return collect($optionRows)
            ->map(function (array $row) use ($options): array {
                $option = $options[(int) $row['product_option_id']];

                return [
                    'product_option_id' => $option->id,
                    'name' => $option->name,
                    'option_type' => $option->option_type,
                    'group_code' => $option->group_code,
                    'quantity' => (int) ($row['quantity'] ?? 1),
                    'price_delta_cents' => (int) $option->price_delta_cents,
                ];
            })
            ->values()
            ->all();
    }

    private function orderDateForSelection(Order $order, $company): CarbonImmutable
    {
        $timezone = $company->setting?->timezone ?: config('app.timezone');

        return CarbonImmutable::parse($order->order_date?->toDateString() ?? now($timezone)->toDateString(), $timezone);
    }

    /**
     * @return list<string>
     */
    private function orderRelations(): array
    {
        return [
            'payerCustomer',
            'items.options',
            'statusHistories' => fn ($query) => $query->latest()->limit(8),
            'latestPrintJob',
            'payments',
        ];
    }

    /**
     * @param  array{eligible: list<array<string, mixed>>, blocked: list<array<string, mixed>>}  $result
     */
    private function permanentDeletionBlockedResponse(array $result, bool $singleOrder): JsonResponse
    {
        return response()->json([
            'code' => $singleOrder ? OrderCleanupService::BLOCKED_CODE : OrderCleanupService::BULK_BLOCKED_CODE,
            'message' => $singleOrder
                ? 'Este pedido possui registros operacionais e nao pode ser excluido.'
                : 'Um ou mais pedidos possuem registros operacionais e nao podem ser excluidos.',
            'reasons' => $result['blocked'][0]['reasons'] ?? [],
            'eligible' => $result['eligible'],
            'blocked' => $result['blocked'],
        ], 422);
    }
}
