<?php

namespace App\Services\Operational;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CompanyOperatingHoursService
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_UNKNOWN = 'UNKNOWN';

    /** @return array{status:string,timezone:string,local_datetime:string,today_has_service:bool,schedule:string} */
    public function status(Company $company, ?CarbonInterface $at = null): array
    {
        $company->loadMissing('setting');
        $timezone = $company->setting?->timezone ?: config('app.timezone');
        $now = $at instanceof CarbonInterface
            ? CarbonImmutable::instance($at)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);
        $hours = $company->operatingHours()->orderBy('weekday')->get();
        $configured = $hours->filter(fn ($hour): bool => (bool) $hour->is_open
            && filled($hour->opens_at)
            && filled($hour->closes_at));

        if ($configured->isEmpty()) {
            return $this->result(self::STATUS_UNKNOWN, $timezone, $now, false, '');
        }

        $exception = $company->operatingHourExceptions()->whereDate('date', $now->toDateString())->first();
        if ($exception !== null) {
            if (! $exception->is_open || blank($exception->opens_at) || blank($exception->closes_at)) {
                return $this->result(self::STATUS_CLOSED, $timezone, $now, false, $this->schedule($configured));
            }

            $opens = CarbonImmutable::parse($now->toDateString().' '.$exception->opens_at, $timezone);
            $closes = CarbonImmutable::parse($now->toDateString().' '.$exception->closes_at, $timezone);
            if ($closes->lessThanOrEqualTo($opens)) {
                $closes = $closes->addDay();
            }

            return $this->result($now->betweenIncluded($opens, $closes) ? self::STATUS_OPEN : self::STATUS_CLOSED, $timezone, $now, true, $this->schedule($configured));
        }

        $today = $configured->firstWhere('weekday', $now->dayOfWeek);
        if ($today === null) {
            return $this->result(self::STATUS_CLOSED, $timezone, $now, false, $this->schedule($configured));
        }

        $opens = CarbonImmutable::parse($now->toDateString().' '.$today->opens_at, $timezone);
        $closes = CarbonImmutable::parse($now->toDateString().' '.$today->closes_at, $timezone);
        if ($closes->lessThanOrEqualTo($opens)) {
            $closes = $closes->addDay();
        }

        return $this->result(
            $now->betweenIncluded($opens, $closes) ? self::STATUS_OPEN : self::STATUS_CLOSED,
            $timezone,
            $now,
            true,
            $this->schedule($configured),
        );
    }

    /** @param array{status:string,timezone:string,local_datetime:string,today_has_service:bool,schedule:string} $status */
    public function closedReply(array $status): string
    {
        $lead = $status['today_has_service']
            ? 'No momento estamos fechados 😊☀️'
            : 'Hoje estamos fechados 😊☀️';
        $schedule = trim($status['schedule']);

        return $schedule === ''
            ? $lead
            : "{$lead}\nNosso atendimento funciona {$schedule}.\nQuando estivermos abertos, é só chamar por aqui.";
    }

    /** @param array{status:string,timezone:string,local_datetime:string,today_has_service:bool,schedule:string} $status @return array<string,mixed> */
    public function metadata(array $status): array
    {
        return [
            'operational_status' => $status['status'],
            'company_timezone' => $status['timezone'],
            'local_datetime' => $status['local_datetime'],
            'today_has_service' => $status['today_has_service'],
            'operating_hours_summary' => $status['schedule'],
        ];
    }

    /** @param Collection<int,mixed> $hours */
    private function schedule(Collection $hours): string
    {
        return $hours
            ->groupBy(fn ($hour): string => substr((string) $hour->opens_at, 0, 5).'-'.substr((string) $hour->closes_at, 0, 5))
            ->map(function (Collection $slotHours): string {
                $first = $slotHours->first();
                $weekdays = $slotHours->pluck('weekday')->map(fn (mixed $day): int => (int) $day)->sortBy(fn (int $day): int => $day === 0 ? 7 : $day)->values()->all();

                return $this->days($weekdays)
                    .', das '.substr((string) $first->opens_at, 0, 5)
                    .' às '.substr((string) $first->closes_at, 0, 5);
            })
            ->implode('; ');
    }

    /** @param list<int> $weekdays */
    private function days(array $weekdays): string
    {
        $names = [
            0 => 'domingo',
            1 => 'segunda-feira',
            2 => 'terça-feira',
            3 => 'quarta-feira',
            4 => 'quinta-feira',
            5 => 'sexta-feira',
            6 => 'sábado',
        ];
        $ordered = array_map(fn (int $day): int => $day === 0 ? 7 : $day, $weekdays);
        $consecutive = count($ordered) > 1
            && $ordered === range($ordered[0], $ordered[count($ordered) - 1]);

        if ($consecutive) {
            return 'de '.$names[$weekdays[0]].' a '.$names[$weekdays[count($weekdays) - 1]];
        }

        $labels = array_map(fn (int $day): string => $names[$day], $weekdays);
        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' e '.$last;
    }

    /** @return array{status:string,timezone:string,local_datetime:string,today_has_service:bool,schedule:string} */
    private function result(string $status, string $timezone, CarbonImmutable $now, bool $todayHasService, string $schedule): array
    {
        return [
            'status' => $status,
            'timezone' => $timezone,
            'local_datetime' => $now->toIso8601String(),
            'today_has_service' => $todayHasService,
            'schedule' => $schedule,
        ];
    }
}
