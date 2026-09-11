<?php

namespace App\Services\Operational;

use App\Models\Company;
use Carbon\CarbonImmutable;

final class CompanyPeriodResolver
{
    /** @return array{from: CarbonImmutable, to: CarbonImmutable, timezone: string, from_date: string, to_date: string} */
    public function resolve(Company $company, ?string $from, ?string $to, int $defaultDays = 7): array
    {
        $company->loadMissing('setting');
        $timezone = $company->setting?->timezone ?: config('app.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $fromDate = $from ? CarbonImmutable::parse($from, $timezone)->startOfDay() : $today->subDays($defaultDays - 1);
        $toDate = $to ? CarbonImmutable::parse($to, $timezone)->endOfDay() : $today->endOfDay();

        if ($fromDate->greaterThan($toDate)) {
            [$fromDate, $toDate] = [$toDate->startOfDay(), $fromDate->endOfDay()];
        }

        if ($fromDate->diffInDays($toDate) > 366) {
            $fromDate = $toDate->subDays(366)->startOfDay();
        }

        return [
            'from' => $fromDate->utc(),
            'to' => $toDate->utc(),
            'timezone' => $timezone,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
        ];
    }
}
