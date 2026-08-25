<?php

namespace App\Services\Delivery;

use App\Contracts\Delivery\DeliveryGeocodingProviderInterface;
use App\Contracts\Delivery\DeliveryRouteProviderInterface;
use App\Data\Delivery\DeliveryCoordinates;
use App\Models\Company;
use App\Models\CustomerAddress;
use App\Models\DeliveryQuote;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeliveryRoutingService
{
    public function __construct(
        private readonly DeliveryGeocodingProviderInterface $geocoding,
        private readonly DeliveryRouteProviderInterface $routes,
        private readonly DeliveryPricingService $pricing,
        private readonly DeliveryWorkflowService $delivery,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function updateSettings(Company $company, array $attributes): DeliverySetting
    {
        $origin = $this->coordinatesFrom($attributes['origin'] ?? null, 'Informe as coordenadas de origem do restaurante.');
        $mode = (string) ($attributes['pricing_mode'] ?? DeliveryPricingService::MODE_PER_KM);
        if (! in_array($mode, [DeliveryPricingService::MODE_PER_KM, DeliveryPricingService::MODE_DISTANCE_BANDS], true)) {
            throw new DomainException('O modo de cobrança de entrega é inválido.');
        }

        $options = [
            'origin' => [
                'address' => trim((string) ($attributes['origin']['address'] ?? '')),
                ...$origin->toArray(),
            ],
            'distance_bands' => $mode === DeliveryPricingService::MODE_DISTANCE_BANDS
                ? $this->pricing->normalizeBands((array) ($attributes['distance_bands'] ?? []))
                : [],
        ];

        if ($mode === DeliveryPricingService::MODE_PER_KM && (int) ($attributes['rate_per_km_cents'] ?? 0) < 1) {
            throw new DomainException('Informe um valor por quilômetro válido.');
        }

        return DeliverySetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'calculation_mode' => $mode,
                'price_per_km_cents' => $mode === DeliveryPricingService::MODE_PER_KM ? (int) $attributes['rate_per_km_cents'] : 0,
                'surcharge_percent' => 0,
                'minimum_fee_cents' => $attributes['minimum_fee_cents'] ?? null,
                'maximum_distance_km' => $attributes['maximum_distance_km'] ?? null,
                'rounding_mode' => DeliverySetting::ROUNDING_NEAREST_CENT,
                'maps_provider' => $attributes['maps_provider'] ?? $this->routes->name(),
                'provider_options' => $options,
            ],
        );
    }

    public function setCoordinates(Order $order, float $latitude, float $longitude, ?User $actor = null): DeliveryQuote
    {
        $address = $this->upsertLocationAddress($order, new DeliveryCoordinates($latitude, $longitude), null, 'whatsapp_location');

        return $this->calculate($order, $address, $actor);
    }

    public function geocodeAndSetAddress(Order $order, string $addressText, ?User $actor = null): DeliveryQuote
    {
        $geocoded = $this->geocoding->geocode(trim($addressText));
        $address = $this->upsertLocationAddress($order, $geocoded->coordinates, $geocoded->formattedAddress, 'geocoded_address', [
            'original_address' => $addressText,
            'geocoding_provider' => $geocoded->provider,
            ...$geocoded->metadata,
        ]);

        return $this->calculate($order, $address, $actor);
    }

    public function recalculate(Order $order, ?User $actor = null): DeliveryQuote
    {
        $address = $order->deliveryAddress;
        if (! $address instanceof CustomerAddress) {
            throw new DomainException('Informe uma localização de entrega antes de recalcular a rota.');
        }

        return $this->calculate($order, $address, $actor);
    }

    public function overrideFee(Order $order, int $finalFeeCents, ?User $actor, ?string $reason = null): DeliveryQuote
    {
        if ($finalFeeCents < 0) {
            throw new DomainException('A taxa final não pode ser negativa.');
        }
        if (! $order->canBeEdited()) {
            throw new DomainException('Este pedido não permite ajuste da taxa de entrega.');
        }

        return DB::transaction(function () use ($order, $finalFeeCents, $actor, $reason): DeliveryQuote {
            $quote = $order->deliveryQuotes()->latest('id')->lockForUpdate()->first();
            if (! $quote instanceof DeliveryQuote) {
                throw new DomainException('Calcule a rota antes de ajustar a taxa de entrega.');
            }

            $metadata = (array) $quote->maps_metadata;
            $metadata['manual_override'] = [
                'calculated_fee_cents' => $quote->delivery_fee_cents,
                'final_fee_cents' => $finalFeeCents,
                'actor_id' => $actor?->id,
                'reason' => $reason,
                'overridden_at' => now()->toIso8601String(),
            ];
            $quote->forceFill(['delivery_fee_cents' => $finalFeeCents, 'maps_metadata' => $metadata])->save();

            $order->forceFill(['delivery_fee_cents' => $finalFeeCents])->save();
            app(OrderWorkflowService::class)->recalculateTotals($order);

            return $quote->refresh();
        });
    }

    private function calculate(Order $order, CustomerAddress $address, ?User $actor): DeliveryQuote
    {
        $setting = DeliverySetting::query()->where('company_id', $order->company_id)->first();
        if (! $setting?->is_active) {
            throw new DomainException('O cálculo de entrega não está ativo para este restaurante.');
        }

        $origin = $this->coordinatesFrom((array) data_get($setting->provider_options, 'origin', []), 'Configure a origem do restaurante antes de calcular a rota.');
        if ($address->latitude === null || $address->longitude === null) {
            throw new DomainException('A localização de destino está incompleta.');
        }

        $destination = new DeliveryCoordinates((float) $address->latitude, (float) $address->longitude);
        $route = $this->routes->calculateRoute($origin, $destination);
        $price = $this->pricing->calculate($setting, $route->distanceMeters);

        return $this->delivery->quoteDelivery($order, [
            'customer_address_id' => $address->id,
            'distance_km' => $route->distanceMeters / 1000,
            'quoted_by_user_id' => $actor?->id,
            'calculation_mode' => $setting->calculation_mode,
            'maps_provider' => $route->provider,
            'external_route_id' => $route->externalRouteId,
            'pricing_result' => $price,
            'maps_metadata' => [
                'origin' => $origin->toArray(),
                'destination' => $destination->toArray(),
                'distance_meters' => $route->distanceMeters,
                'duration_seconds' => $route->durationSeconds,
                'encoded_polyline' => $route->encodedPolyline,
                'pricing' => $price['snapshot'],
                'route' => $route->metadata,
                'calculated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function upsertLocationAddress(Order $order, DeliveryCoordinates $coordinates, ?string $formattedAddress, string $source, array $metadata = []): CustomerAddress
    {
        return DB::transaction(function () use ($order, $coordinates, $formattedAddress, $source, $metadata): CustomerAddress {
            $existing = $order->deliveryAddress;
            $address = $existing instanceof CustomerAddress ? $existing : new CustomerAddress([
                'company_id' => $order->company_id,
                'customer_id' => $order->payer_customer_id,
                'label' => 'Entrega',
            ]);
            $currentMetadata = (array) $address->metadata;
            $address->fill([
                'company_id' => $order->company_id,
                'customer_id' => $order->payer_customer_id,
                'street' => $formattedAddress ?: $address->street,
                'latitude' => $coordinates->latitude,
                'longitude' => $coordinates->longitude,
                'metadata' => [...$currentMetadata, ...$metadata, 'location_source' => $source, 'location_updated_at' => now()->toIso8601String()],
            ])->save();

            $order->forceFill([
                'delivery_address_id' => $address->id,
                'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
                'delivery_status' => $order->delivery_status ?? Order::DELIVERY_STATUS_ADDRESS_PENDING,
            ])->save();

            return $address->refresh();
        });
    }

    /** @param array<string, mixed>|mixed $value */
    private function coordinatesFrom(mixed $value, string $message): DeliveryCoordinates
    {
        if (! is_array($value) || ! array_key_exists('latitude', $value) || ! array_key_exists('longitude', $value)) {
            throw new DomainException($message);
        }

        return new DeliveryCoordinates((float) $value['latitude'], (float) $value['longitude']);
    }
}
