<?php

namespace App\Services\Delivery;

use App\Models\Company;
use App\Models\CustomerAddress;
use App\Models\DeliveryQuote;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\WhatsApp\WhatsAppService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    public function __construct(
        private readonly OrderWorkflowService $orders,
    ) {}

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

            $pricePerKmCents = (int) ($setting?->price_per_km_cents ?? DeliverySetting::DEFAULT_PRICE_PER_KM_CENTS);
            $surchargePercent = (float) ($setting?->surcharge_percent ?? DeliverySetting::DEFAULT_SURCHARGE_PERCENT);
            $calculation = $attributes['pricing_result'] ?? $this->calculateFee(
                $distanceKm,
                $pricePerKmCents,
                $surchargePercent,
                $setting?->minimum_fee_cents,
            );
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

    /** @param array<string, mixed>|null $snapshot */
    public function configureDeliveryAddress(
        Order $order,
        ?CustomerAddress $address = null,
        ?array $snapshot = null,
    ): Order {
        return DB::transaction(function () use ($order, $address, $snapshot): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->orders->assertEditable($order);

            if ($address !== null) {
                if ((int) $address->company_id !== (int) $order->company_id
                    || ($order->payer_customer_id !== null && (int) $address->customer_id !== (int) $order->payer_customer_id)) {
                    throw new DomainException('O endereço selecionado não pertence ao cliente deste pedido.');
                }
            }

            $resolvedSnapshot = $this->addressSnapshot($address, $snapshot);
            if ($resolvedSnapshot === null || trim((string) ($resolvedSnapshot['street'] ?? $resolvedSnapshot['formatted_address'] ?? '')) === '') {
                throw new DomainException('Informe um endereço de entrega válido.');
            }

            $order->forceFill([
                'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_PENDING,
                'delivery_status' => Order::DELIVERY_STATUS_ADDRESS_PENDING,
                'pickup_status' => null,
                'delivery_address_id' => $address?->id,
                'delivery_distance_km' => null,
                'delivery_fee_base_cents' => 0,
                'delivery_fee_surcharge_cents' => 0,
                'delivery_fee_cents' => 0,
                'delivery_recipient_name' => $resolvedSnapshot['recipient_name'] ?? $order->customer_name_snapshot,
                'delivery_recipient_phone' => $resolvedSnapshot['recipient_phone'] ?? $order->customer_phone_snapshot,
                'delivery_reference' => $resolvedSnapshot['reference'] ?? null,
                'delivery_address_snapshot' => $resolvedSnapshot,
                'delivery_calculated_at' => null,
            ])->save();

            return $this->orders->recalculateTotals($order);
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

            if ($deliveryStatus === Order::DELIVERY_STATUS_OUT_FOR_DELIVERY) {
                if ($order->status === Order::STATUS_OUT_FOR_DELIVERY
                    && $order->delivery_status === Order::DELIVERY_STATUS_OUT_FOR_DELIVERY) {
                    return $order;
                }

                if ($order->status !== Order::STATUS_READY_FOR_PICKUP) {
                    throw new DomainException('O pedido precisa estar pronto antes de sair para entrega.');
                }
            }

            if ($deliveryStatus === Order::DELIVERY_STATUS_DELIVERED) {
                if ($order->status === Order::STATUS_FINISHED
                    && $order->delivery_status === Order::DELIVERY_STATUS_DELIVERED) {
                    return $order;
                }

                if ($order->status !== Order::STATUS_OUT_FOR_DELIVERY) {
                    throw new DomainException('O pedido precisa estar em entrega para ser concluido.');
                }
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

    public function markReady(Order $order, ?User $user = null): Order
    {
        return DB::transaction(function () use ($order, $user): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === Order::STATUS_READY_FOR_PICKUP) {
                return $order;
            }

            if ($order->status !== Order::STATUS_IN_PREPARATION) {
                throw new DomainException('Somente pedidos em preparo podem ser marcados como prontos.');
            }

            $order->forceFill([
                'pickup_status' => $order->fulfillment_type === Order::FULFILLMENT_DELIVERY ? null : Order::PICKUP_STATUS_READY,
                'fulfillment_status' => $order->fulfillment_type === Order::FULFILLMENT_DELIVERY
                    ? Order::FULFILLMENT_STATUS_DELIVERY_QUOTED
                    : Order::FULFILLMENT_STATUS_READY_FOR_PICKUP,
            ])->save();

            return $this->orders->transitionTo($order, Order::STATUS_READY_FOR_PICKUP, $user, 'preparation_ready');
        });
    }

    public function startDelivery(Order $order, ?User $user = null): Order
    {
        if ($order->fulfillment_type !== Order::FULFILLMENT_DELIVERY) {
            throw new DomainException('Somente pedidos de entrega podem sair para entrega.');
        }

        if ($order->status === Order::STATUS_OUT_FOR_DELIVERY) {
            return $order->refresh();
        }

        if ($order->status !== Order::STATUS_READY_FOR_PICKUP) {
            throw new DomainException('O pedido precisa estar pronto antes de sair para entrega.');
        }

        if (empty($order->delivery_address_snapshot) && $order->delivery_address_id === null) {
            throw new DomainException('Informe o endereco de entrega antes de despachar o pedido.');
        }

        return $this->updateDeliveryStatus($order, Order::DELIVERY_STATUS_OUT_FOR_DELIVERY, $user, 'Entrega iniciada manualmente.');
    }

    public function markDelivered(Order $order, ?User $user = null): Order
    {
        if ($order->fulfillment_type !== Order::FULFILLMENT_DELIVERY) {
            throw new DomainException('Use a confirmacao de retirada para este pedido.');
        }

        if ($order->status === Order::STATUS_FINISHED) {
            return $order->refresh();
        }

        if ($order->status !== Order::STATUS_OUT_FOR_DELIVERY) {
            throw new DomainException('O pedido precisa estar em entrega para ser concluido.');
        }

        return $this->updateDeliveryStatus($order, Order::DELIVERY_STATUS_DELIVERED, $user, 'Entrega confirmada manualmente.');
    }

    /**
     * Sends an optional operational notification after the delivery state was
     * committed. The client reference is deliberately stable: retries reuse
     * the canonical outbound record instead of creating a second message.
     *
     * @return array{sent: bool, warning: string|null}
     */
    public function notifyCustomer(Order $order, string $notification, WhatsAppService $whatsapp, ?User $user = null): array
    {
        $recipient = trim((string) ($order->delivery_recipient_phone ?? $order->customer_phone_snapshot));

        if ($recipient === '') {
            return ['sent' => false, 'warning' => 'Status registrado, mas não há telefone para avisar o cliente.'];
        }

        $message = match ($notification) {
            'out_for_delivery' => "Seu pedido saiu para entrega! 🛵☀️\nDaqui a pouquinho ele chega até você 😊",
            'delivered' => "Pedido entregue! 😊☀️\nMuito obrigado pela preferência. Esperamos que aproveite sua refeição! 💛\nSol Restaurante",
            default => throw new DomainException('Aviso operacional não suportado.'),
        };

        try {
            $delivery = $whatsapp->sendTextMessage(
                $order->company()->firstOrFail(),
                $recipient,
                $message,
                [
                    'sender_type' => 'human',
                    'sent_by_user_id' => $user?->id,
                    'message_source' => 'delivery_workflow',
                    'action_type' => "delivery_{$notification}",
                    'client_reference' => "delivery-order-{$order->id}-{$notification}",
                ],
            );
        } catch (Throwable) {
            return ['sent' => false, 'warning' => 'Status registrado, mas não foi possível enviar o aviso ao cliente.'];
        }

        return $delivery->status === 'failed'
            ? ['sent' => false, 'warning' => 'Status registrado, mas não foi possível enviar o aviso ao cliente.']
            : ['sent' => true, 'warning' => null];
    }

    public function markPickedUp(Order $order, ?User $user = null): Order
    {
        return DB::transaction(function () use ($order, $user): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->fulfillment_type === Order::FULFILLMENT_DELIVERY) {
                throw new DomainException('Pedidos de entrega devem ser concluidos como entregues.');
            }

            if ($order->status === Order::STATUS_FINISHED) {
                return $order;
            }

            if ($order->status !== Order::STATUS_READY_FOR_PICKUP) {
                throw new DomainException('O pedido precisa estar pronto para retirada antes da conclusao.');
            }

            $order->forceFill([
                'pickup_status' => Order::PICKUP_STATUS_PICKED_UP,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_PICKED_UP,
            ])->save();

            return $this->orders->transitionTo($order, Order::STATUS_FINISHED, $user, 'pickup_completed');
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

            if ((int) $address->company_id !== (int) $order->company_id
                || ($order->payer_customer_id !== null && (int) $address->customer_id !== (int) $order->payer_customer_id)) {
                throw new DomainException('Delivery address must belong to the same company as the order.');
            }

            return $address;
        }

        $addressId = $attributes['customer_address_id'] ?? null;

        if ($addressId === null) {
            return null;
        }

        $address = CustomerAddress::query()->findOrFail($addressId);

        if ((int) $address->company_id !== (int) $order->company_id
            || ($order->payer_customer_id !== null && (int) $address->customer_id !== (int) $order->payer_customer_id)) {
            throw new DomainException('Delivery address must belong to the same company as the order.');
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>|null  $fallback
     * @return array<string, mixed>|null
     */
    public function addressSnapshot(?CustomerAddress $address, ?array $fallback = null): ?array
    {
        if ($address === null) {
            return $fallback;
        }

        return [
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'postal_code' => $address->postal_code,
            'street' => $address->street,
            'number' => $address->number,
            'complement' => $address->complement,
            'neighborhood' => $address->neighborhood,
            'city' => $address->city,
            'state' => $address->state,
            'country_code' => $address->country_code,
            'reference' => $address->reference,
            'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
            'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            ...($fallback ?? []),
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
