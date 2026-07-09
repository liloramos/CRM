<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Operational\OperationalCrmPresenter;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'origin_channel' => ['nullable', Rule::in([Order::CHANNEL_MANUAL, Order::CHANNEL_COUNTER, Order::CHANNEL_PHONE, Order::CHANNEL_OTHER])],
            'fulfillment_type' => ['nullable', Rule::in([Order::FULFILLMENT_PICKUP, Order::FULFILLMENT_DELIVERY, Order::FULFILLMENT_COUNTER])],
            'general_notes' => ['nullable', 'string', 'max:1000'],
            'kitchen_notes' => ['nullable', 'string', 'max:1000'],
            'pickup_person_name' => ['nullable', 'string', 'max:120'],
        ]);

        if (! empty($validated['payer_customer_id'])) {
            $customerExists = Customer::query()
                ->where('company_id', $company->id)
                ->whereKey($validated['payer_customer_id'])
                ->exists();

            if (! $customerExists) {
                throw ValidationException::withMessages([
                    'payer_customer_id' => ['Cliente nao pertence ao restaurante atual.'],
                ]);
            }
        }

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
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'item_notes' => ['nullable', 'string', 'max:1000'],
            'beneficiary_name' => ['nullable', 'string', 'max:120'],
        ]);

        $product = Product::query()
            ->where('company_id', $company->id)
            ->whereKey($validated['product_id'])
            ->firstOrFail();

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
}
