<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryQuoteResource;
use App\Http\Resources\DeliverySettingResource;
use App\Models\DeliveryQuote;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Services\Delivery\DeliveryRoutingService;
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
            ->with(['deliveryAddress', 'deliveryQuotes' => fn ($query) => $query->latest('id')->limit(1)])
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
            'pricing_mode' => ['required', Rule::in(['per_km', 'distance_bands'])],
            'rate_per_km_cents' => ['nullable', 'integer', 'min:1'],
            'minimum_fee_cents' => ['nullable', 'integer', 'min:0'],
            'maximum_distance_km' => ['nullable', 'numeric', 'min:0.001'],
            'origin' => ['required', 'array'],
            'origin.address' => ['nullable', 'string', 'max:255'],
            'origin.latitude' => ['required', 'numeric', 'between:-90,90'],
            'origin.longitude' => ['required', 'numeric', 'between:-180,180'],
            'distance_bands' => ['nullable', 'array', 'max:20'],
            'distance_bands.*.up_to_meters' => ['required_with:distance_bands', 'integer', 'min:1'],
            'distance_bands.*.fee_cents' => ['required_with:distance_bands', 'integer', 'min:0'],
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
        $quote = $order->deliveryQuotes->first();
        $metadata = (array) ($quote?->maps_metadata ?? []);

        return [
            'id' => (string) $order->id,
            'order_code' => $order->code,
            'status' => $order->delivery_status ?? Order::DELIVERY_STATUS_ADDRESS_PENDING,
            'recipient' => $order->delivery_recipient_name ?? $order->customer_name_snapshot,
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

    /** @return array{latitude: float, longitude: float}|null */
    private function addressCoordinates(Order $order): ?array
    {
        if ($order->deliveryAddress?->latitude === null || $order->deliveryAddress?->longitude === null) {
            return null;
        }

        return ['latitude' => (float) $order->deliveryAddress->latitude, 'longitude' => (float) $order->deliveryAddress->longitude];
    }
}
