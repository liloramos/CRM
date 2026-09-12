<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryQuoteResource;
use App\Http\Resources\DeliverySettingResource;
use App\Models\CustomerAddress;
use App\Models\DeliveryQuote;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Services\Delivery\DeliveryPricingService;
use App\Services\Delivery\DeliveryRoutingService;
use App\Services\Delivery\DeliveryWorkflowService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryOperationsController extends Controller
{
    use ResolvesOperationalCompany;

    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $orders = Order::query()
            ->with(['deliveryAddress', 'payerCustomer.addresses', 'deliveryQuotes' => fn ($query) => $query->latest('id')->limit(1)])
            ->where('company_id', $company->id)
            ->where('fulfillment_type', Order::FULFILLMENT_DELIVERY)
            ->whereNotIn('status', [Order::STATUS_FINISHED, Order::STATUS_CANCELLED])
            ->latest('updated_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order): array => $this->deliveryPayload($order))->values(),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $setting = DeliverySetting::query()->firstOrCreate([
            'company_id' => $company->id,
        ]);

        return response()->json(['data' => new DeliverySettingResource($setting)]);
    }

    public function updateSettings(Request $request, DeliveryRoutingService $routing): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'maps_provider' => ['nullable', Rule::in(['google', 'fake', 'none'])],
            'pricing_mode' => ['required', Rule::in([
                DeliveryPricingService::MODE_PER_KM,
                DeliveryPricingService::MODE_DISTANCE_BANDS,
            ])],
            'rate_per_km_cents' => [
                'exclude_unless:pricing_mode,'.DeliveryPricingService::MODE_PER_KM,
                'required',
                'integer',
                'min:1',
            ],
            'minimum_fee_cents' => ['nullable', 'integer', 'min:0'],
            'maximum_distance_km' => ['nullable', 'numeric', 'min:0.001'],
            'origin' => ['required', 'array'],
            'origin.address' => ['nullable', 'string', 'max:255'],
            'origin.latitude' => ['required', 'numeric', 'between:-90,90'],
            'origin.longitude' => ['required', 'numeric', 'between:-180,180'],
            'distance_bands' => [
                'exclude_unless:pricing_mode,'.DeliveryPricingService::MODE_DISTANCE_BANDS,
                'required',
                'array',
                'min:1',
                'max:20',
            ],
            'distance_bands.*.up_to_meters' => [
                'exclude_unless:pricing_mode,'.DeliveryPricingService::MODE_DISTANCE_BANDS,
                'required',
                'integer',
                'min:1',
            ],
            'distance_bands.*.fee_cents' => [
                'exclude_unless:pricing_mode,'.DeliveryPricingService::MODE_DISTANCE_BANDS,
                'required',
                'integer',
                'min:0',
            ],
        ]);

        try {
            $setting = $routing->updateSettings($company, $validated);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => new DeliverySettingResource($setting)]);
    }

    public function setCoordinates(Request $request, Order $order, DeliveryRoutingService $routing): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->routeResponse(fn () => $routing->setCoordinates(
            $order,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $request->user(),
        ));
    }

    public function geocodeAddress(Request $request, Order $order, DeliveryRoutingService $routing): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);
        $validated = $request->validate(['address' => ['required', 'string', 'max:500']]);

        return $this->routeResponse(fn () => $routing->geocodeAndSetAddress($order, $validated['address'], $request->user()));
    }

    public function updateAddress(Request $request, Order $order, DeliveryRoutingService $routing): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);
        $validated = $request->validate([
            'postal_code' => ['nullable', 'string', 'max:16'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:40'],
            'complement' => ['nullable', 'string', 'max:120'],
            'neighborhood' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'size:2'],
            'reference' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:80'],
            'save_to_customer' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $routing->updateManualAddress($order, $validated, $request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $order->refresh()->load(['deliveryAddress', 'deliveryQuotes' => fn ($query) => $query->latest('id')->limit(1)]);

        return response()->json([
            'data' => $this->deliveryPayload($order),
            'warning' => $result['warning'],
        ]);
    }

    public function selectAddress(
        Request $request,
        Order $order,
        CustomerAddress $address,
        DeliveryWorkflowService $delivery,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);
        abort_unless(
            (int) $address->company_id === (int) $company->id
            && $order->payer_customer_id !== null
            && (int) $address->customer_id === (int) $order->payer_customer_id,
            404,
        );

        try {
            $delivery->configureDeliveryAddress($order, $address);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $order->refresh()->load(['deliveryAddress', 'deliveryQuotes' => fn ($query) => $query->latest('id')->limit(1)]);

        return response()->json(['data' => $this->deliveryPayload($order)]);
    }

    public function recalculate(Request $request, Order $order, DeliveryRoutingService $routing): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        return $this->routeResponse(fn () => $routing->recalculate($order, $request->user()));
    }

    public function overrideFee(Request $request, Order $order, DeliveryRoutingService $routing): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);
        $validated = $request->validate([
            'final_fee_cents' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->routeResponse(fn () => $routing->overrideFee(
            $order,
            (int) $validated['final_fee_cents'],
            $request->user(),
            $validated['reason'] ?? null,
        ));
    }

    /** @param callable(): DeliveryQuote $operation */
    private function routeResponse(callable $operation): JsonResponse
    {
        try {
            $quote = $operation();
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => new DeliveryQuoteResource($quote)]);
    }

    /** @return array<string, mixed> */
    private function deliveryPayload(Order $order): array
    {
        $order->loadMissing('payerCustomer.addresses');
        $quote = $order->delivery_calculated_at ? $order->deliveryQuotes->first() : null;
        $metadata = (array) ($quote?->maps_metadata ?? []);

        return [
            'id' => (string) $order->id,
            'order_code' => $order->code,
            'status' => $this->operationalDeliveryStatus($order),
            'order_status' => $order->status,
            'recipient' => $order->delivery_recipient_name ?? $order->customer_name_snapshot,
            'customer_id' => $order->payer_customer_id !== null ? (string) $order->payer_customer_id : null,
            'saved_addresses' => $order->payerCustomer?->addresses
                ?->sortBy([['is_default', 'desc'], ['id', 'asc']])
                ->map(fn (CustomerAddress $address): array => [
                    ...$address->only(['label', 'recipient_name', 'recipient_phone', 'postal_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'country_code', 'reference']),
                    'id' => (string) $address->id,
                    'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
                    'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
                    'is_default' => (bool) $address->is_default,
                ])->values()->all() ?? [],
            'address' => $order->delivery_address_snapshot,
            'destination' => $metadata['destination'] ?? $this->addressCoordinates($order),
            'origin' => $metadata['origin'] ?? null,
            'distance_meters' => $metadata['distance_meters'] ?? null,
            'duration_seconds' => $metadata['duration_seconds'] ?? null,
            'encoded_polyline' => $metadata['encoded_polyline'] ?? null,
            'calculated_fee_cents' => data_get($metadata, 'manual_override.calculated_fee_cents', $quote?->delivery_fee_cents),
            'final_fee_cents' => $order->delivery_fee_cents,
            'quote_id' => $quote?->id,
        ];
    }

    private function operationalDeliveryStatus(Order $order): string
    {
        return match ($order->status) {
            Order::STATUS_READY_FOR_PICKUP => 'ready',
            Order::STATUS_OUT_FOR_DELIVERY => Order::DELIVERY_STATUS_OUT_FOR_DELIVERY,
            Order::STATUS_FINISHED => Order::DELIVERY_STATUS_DELIVERED,
            default => $order->delivery_status ?? Order::DELIVERY_STATUS_ADDRESS_PENDING,
        };
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function addressCoordinates(Order $order): ?array
    {
        $snapshot = (array) $order->delivery_address_snapshot;
        if (! is_numeric($snapshot['latitude'] ?? null) || ! is_numeric($snapshot['longitude'] ?? null)) {
            return null;
        }

        return ['latitude' => (float) $snapshot['latitude'], 'longitude' => (float) $snapshot['longitude']];
    }
}
