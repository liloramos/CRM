<?php

namespace App\Services\Delivery\Providers;

use App\Contracts\Delivery\DeliveryGeocodingProviderInterface;
use App\Data\Delivery\DeliveryCoordinates;
use App\Data\Delivery\GeocodedDeliveryAddress;
use DomainException;
use Illuminate\Support\Facades\Http;

class GoogleDeliveryGeocodingProvider implements DeliveryGeocodingProviderInterface
{
    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return (string) config('chatbotcrm.delivery.maps.server_api_key', '') !== '';
    }

    public function geocode(string $address): GeocodedDeliveryAddress
    {
        if (! $this->isConfigured()) {
            throw new DomainException('O geocoding do Google Maps não está configurado.');
        }

        try {
            $response = Http::timeout((int) config('chatbotcrm.delivery.maps.timeout_seconds', 8))
                ->get(rtrim((string) config('chatbotcrm.delivery.maps.geocoding_url'), '/'), [
                    'address' => $address,
                    'key' => config('chatbotcrm.delivery.maps.server_api_key'),
                ]);
        } catch (\Throwable) {
            throw new DomainException('Não foi possível consultar o endereço agora. Tente novamente.');
        }

        $results = $response->json('results');
        if (! $response->successful() || ! is_array($results) || $results === []) {
            throw new DomainException('Não foi possível localizar o endereço. Confira os dados e tente novamente.');
        }
        if (count($results) !== 1) {
            throw new DomainException('O endereço retornou mais de uma localização. Revise os dados antes de calcular a rota.');
        }
        $result = $results[0];

        $location = data_get($result, 'geometry.location');
        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            throw new DomainException('O endereço informado não retornou uma localização válida.');
        }

        return new GeocodedDeliveryAddress(
            formattedAddress: (string) ($result['formatted_address'] ?? $address),
            coordinates: new DeliveryCoordinates((float) $location['lat'], (float) $location['lng']),
            provider: $this->name(),
            metadata: ['place_id' => $result['place_id'] ?? null],
        );
    }
}
