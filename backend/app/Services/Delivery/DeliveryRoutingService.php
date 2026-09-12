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
use App\Services\Customers\CustomerAddressBookService;
use App\Services\Orders\OrderWorkflowService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliveryRoutingService
{
    public function __construct(
        private readonly DeliveryGeocodingProviderInterface $geocoding,
        private readonly DeliveryRouteProviderInterface $routes,
        private readonly DeliveryPricingService $pricing,
        private readonly DeliveryWorkflowService $delivery,
        private readonly OrderWorkflowService $orders,
        private readonly CustomerAddressBookService $addressBook,
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
            'origin' => ['address' => trim((string) ($attributes['origin']['address'] ?? '')), ...$origin->toArray()],
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
        $snapshot = [
            ...(array) $order->delivery_address_snapshot,
            'formatted_address' => data_get($order->delivery_address_snapshot, 'formatted_address', 'Localização compartilhada via WhatsApp'),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_source' => 'whatsapp_location',
            'location_updated_at' => now()->toIso8601String(),
        ];
        $address = $order->deliveryAddress;
        $order = $this->delivery->configureDeliveryAddress($order, $address, $snapshot);

        return $this->calculate($order, $snapshot, $address, $actor);
    }

    public function geocodeAndSetAddress(Order $order, string $addressText, ?User $actor = null): DeliveryQuote
    {
        $geocoded = $this->geocoding->geocode(trim($addressText));
        $snapshot = [
            'label' => 'Entrega',
            'recipient_name' => $order->delivery_recipient_name ?: $order->customer_name_snapshot,
            'recipient_phone' => $order->delivery_recipient_phone ?: $order->customer_phone_snapshot,
            'formatted_address' => $geocoded->formattedAddress,
            'street' => $geocoded->formattedAddress,
            'latitude' => $geocoded->coordinates->latitude,
            'longitude' => $geocoded->coordinates->longitude,
            'location_source' => 'geocoded_address',
            'location_updated_at' => now()->toIso8601String(),
            'original_address' => $addressText,
            'geocoding_provider' => $geocoded->provider,
            ...$geocoded->metadata,
        ];
        $order = $this->delivery->configureDeliveryAddress($order, null, $snapshot);

        return $this->calculate($order, $snapshot, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{quote: DeliveryQuote|null, warning: string|null}
     */
    public function updateManualAddress(Order $order, array $attributes, ?User $actor = null): array
    {
        $order->loadMissing(['payerCustomer', 'deliveryAddress']);
        $snapshot = [
            'label' => $attributes['label'] ?? 'Entrega',
            'recipient_name' => $order->delivery_recipient_name ?: $order->customer_name_snapshot,
            'recipient_phone' => $order->delivery_recipient_phone ?: $order->customer_phone_snapshot,
            'postal_code' => $attributes['postal_code'] ?? null,
            'street' => $attributes['street'],
            'number' => $attributes['number'],
            'complement' => $attributes['complement'] ?? null,
            'neighborhood' => $attributes['neighborhood'],
            'city' => $attributes['city'],
            'state' => strtoupper((string) $attributes['state']),
            'country_code' => 'BR',
            'reference' => $attributes['reference'] ?? null,
            'latitude' => null,
            'longitude' => null,
            'location_source' => 'manual_address',
            'location_updated_at' => now()->toIso8601String(),
        ];

        $address = null;
        if (($attributes['save_to_customer'] ?? false) && $order->payerCustomer !== null) {
            $address = $this->addressBook->create($order->payerCustomer, [
                ...$snapshot,
                'is_default' => (bool) ($attributes['is_default'] ?? false),
            ]);
        }

        $order = $this->delivery->configureDeliveryAddress($order, $address, $snapshot);

        try {
            $geocoded = $this->geocoding->geocode($this->manualAddressText($snapshot));
            $snapshot = [
                ...$snapshot,
                'latitude' => $geocoded->coordinates->latitude,
                'longitude' => $geocoded->coordinates->longitude,
                'geocoded_address' => $geocoded->formattedAddress,
                'geocoding_provider' => $geocoded->provider,
                ...$geocoded->metadata,
            ];

            if ($address !== null && $order->payerCustomer !== null) {
                $address = $this->addressBook->update($order->payerCustomer, $address, $snapshot);
            }

            $order = $this->delivery->configureDeliveryAddress($order->refresh(), $address, $snapshot);

            return ['quote' => $this->calculate($order, $snapshot, $address, $actor), 'warning' => null];
        } catch (Throwable $exception) {
            return [
                'quote' => null,
                'warning' => 'Endereço do pedido salvo. Não foi possível geocodificar e recalcular a rota agora; revise a localização antes de despachar. '.$exception->getMessage(),
            ];
        }
    }

    public function recalculate(Order $order, ?User $actor = null): DeliveryQuote
    {
        $snapshot = (array) $order->delivery_address_snapshot;
        if ($snapshot === [] && $order->deliveryAddress !== null) {
            $snapshot = $this->delivery->addressSnapshot($order->deliveryAddress) ?? [];
            $order = $this->delivery->configureDeliveryAddress($order, $order->deliveryAddress, $snapshot);
        }
        if ($snapshot === []) {
            throw new DomainException('Informe uma localização de entrega antes de recalcular a rota.');
        }

        if (! is_numeric($snapshot['latitude'] ?? null) || ! is_numeric($snapshot['longitude'] ?? null)) {
            $geocoded = $this->geocoding->geocode($this->manualAddressText($snapshot));
            $snapshot = [
                ...$snapshot,
                'latitude' => $geocoded->coordinates->latitude,
                'longitude' => $geocoded->coordinates->longitude,
                'geocoded_address' => $geocoded->formattedAddress,
                'geocoding_provider' => $geocoded->provider,
                ...$geocoded->metadata,
            ];
            $order = $this->delivery->configureDeliveryAddress($order, $order->deliveryAddress, $snapshot);
        }

        return $this->calculate($order, $snapshot, $order->deliveryAddress, $actor);
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
            $this->orders->recalculateTotals($order);

            return $quote->refresh();
        });
    }

    /** @param array<string, mixed> $snapshot */
    private function calculate(Order $order, array $snapshot, ?CustomerAddress $address, ?User $actor): DeliveryQuote
    {
        $setting = DeliverySetting::query()->where('company_id', $order->company_id)->first();
        if (! $setting?->is_active) {
            throw new DomainException('O cálculo de entrega não está ativo para este restaurante.');
        }

        $origin = $this->coordinatesFrom((array) data_get($setting->provider_options, 'origin', []), 'Configure a origem do restaurante antes de calcular a rota.');
        if (! is_numeric($snapshot['latitude'] ?? null) || ! is_numeric($snapshot['longitude'] ?? null)) {
            throw new DomainException('A localização de destino está incompleta.');
        }

        $destination = new DeliveryCoordinates((float) $snapshot['latitude'], (float) $snapshot['longitude']);
        $route = $this->routes->calculateRoute($origin, $destination);
        $price = $this->pricing->calculate($setting, $route->distanceMeters);

        return $this->delivery->quoteDelivery($order, [
            'customer_address_id' => $address?->id,
            'delivery_address' => $snapshot,
            'recipient_name' => $snapshot['recipient_name'] ?? null,
            'recipient_phone' => $snapshot['recipient_phone'] ?? null,
            'address_reference' => $snapshot['reference'] ?? null,
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

    /** @param array<string, mixed> $address */
    private function manualAddressText(array $address): string
    {
        return collect([
            trim(implode(', ', array_filter([$address['street'] ?? null, $address['number'] ?? null]))),
            $address['neighborhood'] ?? null,
            trim(implode(' - ', array_filter([$address['city'] ?? null, $address['state'] ?? null]))),
            ! empty($address['postal_code']) ? 'CEP '.$address['postal_code'] : null,
            'Brasil',
        ])->filter()->implode(', ');
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
