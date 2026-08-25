<?php

namespace App\Services\Delivery\Providers;

use App\Contracts\Delivery\DeliveryRouteProviderInterface;
use App\Data\Delivery\DeliveryCoordinates;
use App\Data\Delivery\DeliveryRoute;
use DomainException;
use Illuminate\Support\Facades\Http;

class GoogleDeliveryRouteProvider implements DeliveryRouteProviderInterface
{
    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return (string) config('chatbotcrm.delivery.maps.server_api_key', '') !== '';
    }

    public function calculateRoute(DeliveryCoordinates $origin, DeliveryCoordinates $destination): DeliveryRoute
    {
        if (! $this->isConfigured()) {
            throw new DomainException('O cálculo de rota do Google Maps não está configurado.');
        }

        try {
            $response = Http::timeout((int) config('chatbotcrm.delivery.maps.timeout_seconds', 8))
                ->withHeaders([
                    'X-Goog-Api-Key' => (string) config('chatbotcrm.delivery.maps.server_api_key'),
                    'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.routeLabels',
                ])
                ->post((string) config('chatbotcrm.delivery.maps.routes_url'), [
                    'origin' => ['location' => ['latLng' => ['latitude' => $origin->latitude, 'longitude' => $origin->longitude]]],
                    'destination' => ['location' => ['latLng' => ['latitude' => $destination->latitude, 'longitude' => $destination->longitude]]],
                    'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_UNAWARE',
                    'computeAlternativeRoutes' => false,
                    'languageCode' => 'pt-BR',
                    'units' => 'METRIC',
                ]);
        } catch (\Throwable) {
            throw new DomainException('Não foi possível calcular a rota agora. Confira o endereço ou tente novamente.');
        }

        $route = $response->json('routes.0');
        if (! $response->successful() || ! is_array($route) || ! isset($route['distanceMeters'])) {
            throw new DomainException('Não foi possível calcular a rota. Confira o endereço ou tente novamente.');
        }

        return new DeliveryRoute(
            distanceMeters: (int) $route['distanceMeters'],
            durationSeconds: $this->durationSeconds($route['duration'] ?? null),
            provider: $this->name(),
            externalRouteId: is_string($route['routeLabels'][0] ?? null) ? $route['routeLabels'][0] : null,
            encodedPolyline: data_get($route, 'polyline.encodedPolyline'),
        );
    }

    private function durationSeconds(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d+)s$/', $value, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
