<?php

namespace App\Services\Delivery;

use App\Models\Company;
use App\Models\CustomerAddress;
use App\Models\DeliveryQuote;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeliveryWorkflowService
{
    /**
     * @var list<string>
     */
    private const DELIVERY_STATUSES = [
        Order::DELIVERY_STATUS_ADDRESS_PENDING,
        Order::DELIVERY_STATUS_QUOTED,
        Order::DELIVERY_STATUS_OUT_FOR_DELIVERY,
        Order::DELIVERY_STATUS_DELIVERED,
    ];

    /**
     * @var list<string>
     */
    private const PICKUP_STATUSES = [
        Order::PICKUP_STATUS_PENDING,
        Order::PICKUP_STATUS_READY,
        Order::PICKUP_STATUS_PICKED_UP,
    ];

    public function __construct(private readonly OrderWorkflowService $orders) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function quoteDelivery(Order $order, array $attributes): DeliveryQuote
    {
        return DB::transaction(function () use ($order, $attributes): DeliveryQuote {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->orders->assertEditable($order);

            $setting = $this->deliverySettingFor($order->company()->firstOrFail());
            $distanceKm = $this->resolveDistanceKm($attributes['distance_km'] ?? null);
            $this->assertDistanceAllowed($distanceKm, $setting);

            $pricePerKmCents = (int) ($attributes['price_per_km_cents'] ?? ($setting?->price_per_km_cents ?? DeliverySetting::DEFAULT_PRICE_PER_KM_CENTS));
            $surchargePercent = (float) ($attributes['surcharge_percent'] ?? ($setting?->surcharge_percent ?? DeliverySetting::DEFAULT_SURCHARGE_PERCENT));
            $calculation = $this->calculateFee($distanceKm, $pricePerKmCents, $surchargePercent, $setting?->minimum_fee_cents);
            $address = $this->resolveAddress($order, $attributes);
            $addressSnapshot = $this->addressSnapshot($address, $attributes['delivery_address'] ?? null);

            $quote = DeliveryQuote::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'customer_address_id' => $address?->id,
                'delivery_setting_id' => $setting?->id,
                'quoted_by_user_id' => $attributes['quoted_by_user_id'] ?? null,
                'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
                'status' => $attributes['status'] ?? DeliveryQuote::STATUS_QUOTED,
                'distance_km' => $distanceKm,
                'price_per_km_cents' => $pricePerKmCents,
                'base_fee_cents' => $calculation['base_fee_cents'],
                'surcharge_percent' => $surchargePercent,
                'surcharge_cents' => $calculation['surcharge_cents'],
                'delivery_fee_cents' => $calculation['delivery_fee_cents'],
                'currency' => $attributes['currency'] ?? $order->currency,
                'calculation_mode' => $attributes['calculation_mode'] ?? ($setting?->calculation_mode ?? DeliverySetting::CALCULATION_MANUAL_DISTANCE),
                'maps_provider' => $attributes['maps_provider'] ?? ($setting?->maps_provider ?? DeliverySetting::MAPS_PROVIDER_NONE),
                'external_route_id' => $attributes['external_route_id'] ?? null,
                'delivery_address_snapshot' => $addressSnapshot,
                'recipient_name' => $attributes['recipient_name'] ?? $address?->recipient_name,
                'recipient_phone' => $attributes['recipient_phone'] ?? $address?->recipient_phone,
                'address_reference' => $attributes['address_reference'] ?? $address?->reference,
                'delivery_notes' => $attributes['delivery_notes'] ?? null,
                'maps_metadata' => $attributes['maps_metadata'] ?? null,
                'quoted_at' => $attributes['quoted_at'] ?? now(),
                'accepted_at' => ($attributes['status'] ?? null) === DeliveryQuote::STATUS_ACCEPTED ? now() : null,
            ]);

            $this->applyQuoteToOrder($quote, $order);

            return $quote->refresh();
        });
    }

    public function acceptQuote(DeliveryQuote $quote, ?User $user = null): Order
    {
        return DB::transaction(function () use ($quote): Order {
            $quote = DeliveryQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $quote->forceFill([
                'status' => DeliveryQuote::STATUS_ACCEPTED,
                'accepted_at' => now(),
            ])->save();

            return $this->applyQuoteToOrder($quote, $quote->order()->firstOrFail());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function configurePickup(Order $order, array $attributes = []): Order
    {
        return DB::transaction(function () use ($order, $attributes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->orders->assertEditable($order);

            $pickupStatus = $attributes['pickup_status'] ?? Order::PICKUP_STATUS_PENDING;

            if (! in_array($pickupStatus, self::PICKUP_STATUSES, true)) {
                throw new DomainException("Unsupported pickup status [{$pickupStatus}].");
            }

            $order->forceFill([
                'fulfillment_type' => $attributes['fulfillment_type'] ?? Order::FULFILLMENT_PICKUP,
                'fulfillment_status' => $this->fulfillmentStatusForPickup($pickupStatus),
                'pickup_status' => $pickupStatus,
                'delivery_status' => null,
                'delivery_address_id' => null,
                'delivery_distance_km' => null,
                'delivery_fee_base_cents' => 0,
                'delivery_fee_surcharge_percent' => 0,
                'delivery_fee_surcharge_cents' => 0,
                'delivery_fee_cents' => 0,
                'delivery_recipient_name' => null,
                'delivery_recipient_phone' => null,
                'delivery_reference' => null,
                'delivery_notes' => null,
                'delivery_address_snapshot' => null,
                'delivery_calculated_at' => null,
                'pickup_person_name' => $attributes['pickup_person_name'] ?? $order->pickup_person_name,
                'pickup_person_phone' => $attributes['pickup_person_phone'] ?? $order->pickup_person_phone,
                'pickup_authorized_by' => $attributes['pickup_authorized_by'] ?? $order->pickup_authorized_by,
                'pickup_notes' => $attributes['pickup_notes'] ?? $order->pickup_notes,
            ])->save();

            return $this->orders->recalculateTotals($order);
        });
    }

    public function updateDeliveryStatus(Order $order, string $deliveryStatus, ?User $user = null, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($order, $deliveryStatus, $user, $notes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($deliveryStatus, self::DELIVERY_STATUSES, true)) {
                throw new DomainException("Unsupported delivery status [{$deliveryStatus}].");
            }

            $order->forceFill([
                'delivery_status' => $deliveryStatus,
                'fulfillment_status' => $this->fulfillmentStatusForDelivery($deliveryStatus),
            ])->save();

            if ($deliveryStatus === Order::DELIVERY_STATUS_OUT_FOR_DELIVERY) {
                return $this->orders->transitionTo($order, Order::STATUS_OUT_FOR_DELIVERY, $user, 'delivery_out', $notes);
            }

            if ($deliveryStatus === Order::DELIVERY_STATUS_DELIVERED) {
                return $this->orders->transitionTo($order, Order::STATUS_FINISHED, $user, 'delivery_finished', $notes);
            }

            return $order->refresh();
        });
    }

    /**
     * @return array{base_fee_cents: int, surcharge_cents: int, delivery_fee_cents: int}
     */
    public function calculateFee(float $distanceKm, int $pricePerKmCents, float $surchargePercent, ?int $minimumFeeCents = null): array
    {
        if ($distanceKm <= 0) {
            throw new DomainException('Delivery distance must be greater than zero.');
        }

        if ($pricePerKmCents <= 0) {
            throw new DomainException('Delivery price per kilometer must be greater than zero.');
        }

        $baseFeeCents = (int) round($distanceKm * $pricePerKmCents);
        $surchargeCents = (int) round($baseFeeCents * ($surchargePercent / 100));
        $deliveryFeeCents = $baseFeeCents + $surchargeCents;

        if ($minimumFeeCents !== null) {
            $deliveryFeeCents = max($deliveryFeeCents, $minimumFeeCents);
        }

        return [
            'base_fee_cents' => $baseFeeCents,
            'surcharge_cents' => $surchargeCents,
            'delivery_fee_cents' => $deliveryFeeCents,
        ];
    }

    private function applyQuoteToOrder(DeliveryQuote $quote, Order $order): Order
    {
        $order->forceFill([
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
            'fulfillment_status' => Order::FULFILLMENT_STATUS_DELIVERY_QUOTED,
            'delivery_status' => Order::DELIVERY_STATUS_QUOTED,
            'pickup_status' => null,
            'delivery_address_id' => $quote->customer_address_id,
            'delivery_distance_km' => $quote->distance_km,
            'delivery_fee_base_cents' => $quote->base_fee_cents,
            'delivery_fee_surcharge_percent' => $quote->surcharge_percent,
            'delivery_fee_surcharge_cents' => $quote->surcharge_cents,
            'delivery_fee_cents' => $quote->delivery_fee_cents,
            'delivery_recipient_name' => $quote->recipient_name,
            'delivery_recipient_phone' => $quote->recipient_phone,
            'delivery_reference' => $quote->address_reference,
            'delivery_notes' => $quote->delivery_notes,
            'delivery_address_snapshot' => $quote->delivery_address_snapshot,
            'delivery_calculated_at' => $quote->quoted_at ?? now(),
        ])->save();

        return $this->orders->recalculateTotals($order);
    }

    private function deliverySettingFor(Company $company): ?DeliverySetting
    {
        return $company->deliverySetting()->first();
    }

    private function resolveDistanceKm(mixed $distanceKm): float
    {
        if ($distanceKm === null || $distanceKm === '') {
            throw new DomainException('Manual delivery distance is required in V1.');
        }

        return round((float) $distanceKm, 3);
    }

    private function assertDistanceAllowed(float $distanceKm, ?DeliverySetting $setting): void
    {
        if ($distanceKm <= 0) {
            throw new DomainException('Delivery distance must be greater than zero.');
        }

        if ($setting?->maximum_distance_km !== null && $distanceKm > (float) $setting->maximum_distance_km) {
            throw new DomainException('Delivery distance exceeds the configured maximum distance.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveAddress(Order $order, array $attributes): ?CustomerAddress
    {
        if (($attributes['customer_address'] ?? null) instanceof CustomerAddress) {
            $address = $attributes['customer_address'];

            if ((int) $address->company_id !== (int) $order->company_id) {
                throw new DomainException('Delivery address must belong to the same company as the order.');
            }

            return $address;
        }

        $addressId = $attributes['customer_address_id'] ?? null;

        if ($addressId === null) {
            return null;
        }

        $address = CustomerAddress::query()->findOrFail($addressId);

        if ((int) $address->company_id !== (int) $order->company_id) {
            throw new DomainException('Delivery address must belong to the same company as the order.');
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>|null  $fallback
     * @return array<string, mixed>|null
     */
    private function addressSnapshot(?CustomerAddress $address, ?array $fallback): ?array
    {
        if ($address === null) {
            return $fallback;
        }

        return [
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'postal_code' => $address->postal_code,
            'street' => $address->street,
            'number' => $address->number,
            'complement' => $address->complement,
            'neighborhood' => $address->neighborhood,
            'city' => $address->city,
            'state' => $address->state,
            'country_code' => $address->country_code,
            'reference' => $address->reference,
        ];
    }

    private function fulfillmentStatusForDelivery(string $deliveryStatus): string
    {
        return match ($deliveryStatus) {
            Order::DELIVERY_STATUS_OUT_FOR_DELIVERY => Order::FULFILLMENT_STATUS_DELIVERY_OUT,
            Order::DELIVERY_STATUS_DELIVERED => Order::FULFILLMENT_STATUS_DELIVERED,
            default => Order::FULFILLMENT_STATUS_DELIVERY_QUOTED,
        };
    }

    private function fulfillmentStatusForPickup(string $pickupStatus): string
    {
        return match ($pickupStatus) {
            Order::PICKUP_STATUS_READY => Order::FULFILLMENT_STATUS_READY_FOR_PICKUP,
            Order::PICKUP_STATUS_PICKED_UP => Order::FULFILLMENT_STATUS_PICKED_UP,
            default => Order::FULFILLMENT_STATUS_PICKUP_PENDING,
        };
    }
}
