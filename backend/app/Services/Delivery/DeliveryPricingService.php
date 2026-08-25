<?php

namespace App\Services\Delivery;

use App\Models\DeliverySetting;
use DomainException;

class DeliveryPricingService
{
    public const MODE_PER_KM = 'per_km';

    public const MODE_DISTANCE_BANDS = 'distance_bands';

    /**
     * @return array{base_fee_cents: int, surcharge_cents: int, delivery_fee_cents: int, snapshot: array<string, mixed>}
     */
    public function calculate(DeliverySetting $setting, int $distanceMeters): array
    {
        if ($distanceMeters < 1) {
            throw new DomainException('A distância da rota deve ser maior que zero.');
        }

        $mode = (string) $setting->calculation_mode;
        $options = (array) $setting->provider_options;

        if ($mode === self::MODE_PER_KM) {
            $rate = (int) $setting->price_per_km_cents;
            if ($rate < 1) {
                throw new DomainException('Configure um valor por quilômetro válido.');
            }

            // Centavos por km multiplicados por metros, arredondados apenas no resultado monetário.
            $fee = intdiv(($distanceMeters * $rate) + 500, 1000);
            $fee = max($fee, (int) ($setting->minimum_fee_cents ?? 0));

            return [
                'base_fee_cents' => $fee,
                'surcharge_cents' => 0,
                'delivery_fee_cents' => $fee,
                'snapshot' => [
                    'mode' => self::MODE_PER_KM,
                    'rate_per_km_cents' => $rate,
                    'rounding' => 'nearest_cent',
                    'distance_meters' => $distanceMeters,
                    'minimum_fee_cents' => $setting->minimum_fee_cents,
                ],
            ];
        }

        if ($mode === self::MODE_DISTANCE_BANDS) {
            $band = $this->bandForDistance($options['distance_bands'] ?? [], $distanceMeters);

            return [
                'base_fee_cents' => $band['fee_cents'],
                'surcharge_cents' => 0,
                'delivery_fee_cents' => $band['fee_cents'],
                'snapshot' => [
                    'mode' => self::MODE_DISTANCE_BANDS,
                    'distance_meters' => $distanceMeters,
                    'band_up_to_meters' => $band['up_to_meters'],
                    'band_fee_cents' => $band['fee_cents'],
                ],
            ];
        }

        throw new DomainException('A regra de preço de entrega não está configurada.');
    }

    /**
     * @param  list<array<string, mixed>>  $bands
     * @return array{up_to_meters: int, fee_cents: int}
     */
    public function bandForDistance(array $bands, int $distanceMeters): array
    {
        $normalized = $this->normalizeBands($bands);

        foreach ($normalized as $band) {
            if ($distanceMeters <= $band['up_to_meters']) {
                return $band;
            }
        }

        throw new DomainException('A distância está fora das faixas de entrega configuradas.');
    }

    /** @param list<array<string, mixed>> $bands @return list<array{up_to_meters: int, fee_cents: int}> */
    public function normalizeBands(array $bands): array
    {
        $normalized = collect($bands)
            ->map(function (array $band): array {
                return [
                    'up_to_meters' => (int) ($band['up_to_meters'] ?? 0),
                    'fee_cents' => (int) ($band['fee_cents'] ?? 0),
                ];
            })
            ->sortBy('up_to_meters')
            ->values()
            ->all();

        $previousLimit = 0;
        foreach ($normalized as $band) {
            if ($band['up_to_meters'] <= $previousLimit || $band['fee_cents'] < 0) {
                throw new DomainException('As faixas de distância precisam ter limites crescentes e valores válidos.');
            }
            $previousLimit = $band['up_to_meters'];
        }

        if ($normalized === []) {
            throw new DomainException('Configure ao menos uma faixa de distância.');
        }

        return $normalized;
    }
}
