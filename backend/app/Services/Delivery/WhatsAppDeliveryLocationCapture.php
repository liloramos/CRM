<?php

namespace App\Services\Delivery;

use App\Data\WhatsApp\IncomingWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Order;
use DomainException;

class WhatsAppDeliveryLocationCapture
{
    public function __construct(private readonly DeliveryRoutingService $routing) {}

    public function capture(Conversation $conversation, IncomingWhatsAppMessage $message): void
    {
        if ($message->messageType !== 'location') {
            return;
        }

        $order = $conversation->activeOrder;
        $location = data_get($message->rawPayload, 'location');
        if (! $order instanceof Order || $order->fulfillment_type !== Order::FULFILLMENT_DELIVERY || ! is_array($location)) {
            return;
        }

        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return;
        }

        try {
            // Coordinates are persisted before any route calculation; a provider failure does not lose the location.
            $this->routing->setCoordinates($order, (float) $latitude, (float) $longitude);
        } catch (DomainException) {
            // The operational team can still review and recalculate the location later.
        }
    }
}
