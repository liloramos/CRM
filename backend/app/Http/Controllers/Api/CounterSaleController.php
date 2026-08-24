<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CounterSales\CounterSaleCatalogService;
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

    public function products(Request $request, CounterSaleCatalogService $catalog): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $date = CarbonImmutable::now($company->setting?->timezone ?: config('app.timezone'));

        return response()->json([
            'data' => $catalog->products($company, $date),
            'meta' => ['date' => $date->toDateString()],
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
            'payment_method' => ['required', Rule::in([
                Payment::METHOD_CASH,
                Payment::METHOD_PIX,
                Payment::METHOD_DEBIT_CARD,
                Payment::METHOD_CREDIT_CARD,
            ])],
        ]);

        try {
            $order = $sales->complete(
                $company,
                $request->user(),
                $validated['items'],
                $validated['payment_method'],
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->order($order->load($this->orderRelations())),
        ], 201);
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

    /** @return list<string> */
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
