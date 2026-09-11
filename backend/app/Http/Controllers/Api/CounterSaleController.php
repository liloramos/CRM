<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CounterSales\CounterSaleCatalogService;
use App\Services\CounterSales\CounterSaleHistoryService;
use App\Services\CounterSales\CounterSaleWorkflowService;
use App\Services\Operational\OperationalCrmPresenter;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CounterSaleController extends Controller
{
    use ResolvesOperationalCompany;

    public function index(Request $request, CounterSaleHistoryService $history): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'payment_method' => ['nullable', Rule::in([
                Payment::METHOD_CASH,
                Payment::METHOD_PIX,
                Payment::METHOD_DEBIT_CARD,
                Payment::METHOD_CREDIT_CARD,
            ])],
            'status' => ['nullable', Rule::in(['completed', 'cancelled'])],
        ]);

        try {
            $data = $history->history($company, $validated);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function products(Request $request, CounterSaleCatalogService $catalog): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $date = CarbonImmutable::now($company->setting?->timezone ?: config('app.timezone'));

        return response()->json([
            'data' => $catalog->products($company, $date),
            'meta' => ['date' => $date->toDateString()],
        ]);
    }

    public function drafts(Request $request, CounterSaleHistoryService $history): JsonResponse
    {
        return response()->json([
            'data' => $history->openDrafts($this->resolveCompany($request)),
        ]);
    }

    public function store(
        Request $request,
        CounterSaleWorkflowService $sales,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.weight_grams' => ['nullable', 'integer'],
            'items.*.selected_components' => ['nullable', 'array', 'max:30'],
            'items.*.selected_components.*' => ['string', 'max:100'],
            'items.*.additions' => ['nullable', 'array', 'max:10'],
            'items.*.additions.*' => ['array:code,quantity'],
            'items.*.additions.*.code' => ['required', Rule::in(['extra_beef'])],
            'items.*.additions.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::in([
                Payment::METHOD_CASH,
                Payment::METHOD_PIX,
                Payment::METHOD_DEBIT_CARD,
                Payment::METHOD_CREDIT_CARD,
            ])],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'save_customer' => ['nullable', 'boolean'],
            'seller_user_id' => ['nullable', 'integer'],
        ]);

        try {
            $order = $sales->complete(
                $company,
                $request->user(),
                $validated['items'],
                $validated['payment_method'],
                $this->customerAttributes($validated),
                $validated['seller_user_id'] ?? null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->load($this->orderRelations())),
        ], 201);
    }

    public function storeDraft(
        Request $request,
        CounterSaleWorkflowService $sales,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'weight_grams' => ['nullable', 'integer'],
            'selected_components' => ['nullable', 'array', 'max:30'],
            'selected_components.*' => ['string', 'max:100'],
            'additions' => ['nullable', 'array', 'max:10'],
            'additions.*' => ['array:code,quantity'],
            'additions.*.code' => ['required', Rule::in(['extra_beef'])],
            'additions.*.quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'save_customer' => ['nullable', 'boolean'],
            'seller_user_id' => ['nullable', 'integer'],
        ]);

        try {
            $order = $sales->openDraft(
                $company,
                $request->user(),
                [
                    'product_id' => $validated['product_id'],
                    'quantity' => 1,
                    ...array_intersect_key($validated, array_flip([
                        'weight_grams',
                        'selected_components',
                        'additions',
                    ])),
                ],
                $validated['notes'] ?? null,
                $this->customerAttributes($validated),
                $validated['seller_user_id'] ?? null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->load($this->orderRelations())),
        ], 201);
    }

    public function updateDraftCustomer(
        Request $request,
        Order $order,
        CounterSaleWorkflowService $sales,
        CounterSaleHistoryService $history,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'customer_id' => ['present', 'nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'save_customer' => ['nullable', 'boolean'],
            'seller_user_id' => ['nullable', 'integer'],
        ]);

        try {
            $order = $sales->updateDraftCustomer(
                $company,
                $request->user(),
                $order,
                $this->customerAttributes($validated),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $history->draft($company, $order),
        ]);
    }

    public function finalizeDraft(
        Request $request,
        Order $order,
        CounterSaleWorkflowService $sales,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'weight_grams' => ['nullable', 'integer'],
            'selected_components' => ['nullable', 'array', 'max:30'],
            'selected_components.*' => ['string', 'max:100'],
            'additions' => ['nullable', 'array', 'max:10'],
            'additions.*' => ['array:code,quantity'],
            'additions.*.code' => ['required', Rule::in(['extra_beef'])],
            'additions.*.quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::in([
                Payment::METHOD_CASH,
                Payment::METHOD_PIX,
                Payment::METHOD_DEBIT_CARD,
                Payment::METHOD_CREDIT_CARD,
            ])],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'save_customer' => ['nullable', 'boolean'],
        ]);

        try {
            $order = $sales->finalizeDraft(
                $company,
                $request->user(),
                $order,
                array_intersect_key($validated, array_flip([
                    'weight_grams',
                    'selected_components',
                    'additions',
                    'notes',
                    'customer_id',
                    'customer_name',
                    'customer_phone',
                    'save_customer',
                    'seller_user_id',
                ])),
                $validated['payment_method'],
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->load($this->orderRelations())),
        ]);
    }

    public function cancel(
        Request $request,
        Order $order,
        CounterSaleWorkflowService $sales,
        OperationalCrmPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $order = $sales->cancel($company, $request->user(), $order, $validated['reason'], $validated['notes'] ?? null);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->load($this->orderRelations())),
        ]);
    }

    public function show(Request $request, Order $order, CounterSaleHistoryService $history): JsonResponse
    {
        $company = $this->resolveCompany($request);

        try {
            $data = $history->detail($company, $order);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(['data' => $data]);
    }

    /** @return list<string> */
    private function orderRelations(): array
    {
        return [
            'payerCustomer',
            'seller',
            'items.options',
            'statusHistories' => fn ($query) => $query->with('user')->latest()->limit(8),
            'latestPrintJob',
            'payments',
        ];
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function customerAttributes(array $validated): array
    {
        return array_intersect_key($validated, array_flip([
            'customer_id',
            'customer_name',
            'customer_phone',
            'save_customer',
        ]));
    }
}
