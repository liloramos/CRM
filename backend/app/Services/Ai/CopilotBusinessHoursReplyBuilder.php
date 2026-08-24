<?php

namespace App\Services\Ai;

use App\Models\Company;
use Carbon\CarbonImmutable;

final class CopilotBusinessHoursReplyBuilder
{
    /** @return array<string,mixed> */
    public function build(Company $company): array
    {
        $timezone = $company->setting?->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        $hours = $company->operatingHours()
            ->where('weekday', $now->dayOfWeek)
            ->where('is_open', true)
            ->whereNotNull('opens_at')
            ->whereNotNull('closes_at')
            ->get();
        $hasConfiguredSchedule = $company->operatingHours()
            ->where('is_open', true)
            ->whereNotNull('opens_at')
            ->whereNotNull('closes_at')
            ->exists();

        if (! $hasConfiguredSchedule) {
            return $this->analysis('Vou confirmar o horário de funcionamento para você.', 'operating_hours_unconfigured');
        }

        $open = $hours->contains(function ($hour) use ($now): bool {
            $opens = CarbonImmutable::parse($now->toDateString().' '.$hour->opens_at, $now->timezone);
            $closes = CarbonImmutable::parse($now->toDateString().' '.$hour->closes_at, $now->timezone);
            if ($closes->lessThanOrEqualTo($opens)) {
                $closes = $closes->addDay();
            }

            return $now->betweenIncluded($opens, $closes);
        });

        return $this->analysis(
            $open ? 'Sim, estamos funcionando neste momento.' : 'No momento estamos fechados.',
            'operating_hours',
        );
    }

    /** @return array<string,mixed> */
    private function analysis(string $reply, string $source): array
    {
        return [
            'intent' => 'BUSINESS_HOURS_REQUEST',
            'confidence' => 1,
            'summary' => 'Consulta de horário de funcionamento.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'metadata' => ['reply_source' => $source],
        ];
    }
}
