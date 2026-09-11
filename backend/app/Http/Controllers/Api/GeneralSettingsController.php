<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\OperatingHour;
use App\Models\OperatingHourException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeneralSettingsController extends Controller
{
    use ResolvesOperationalCompany;

    public function show(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);

        return response()->json(['data' => $this->data($company)]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $data = $request->validate(['timezone' => ['required', 'timezone'], 'operating_hours' => ['required', 'array', 'size:7'], 'operating_hours.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'], 'operating_hours.*.is_open' => ['required', 'boolean'], 'operating_hours.*.opens_at' => ['nullable', 'date_format:H:i'], 'operating_hours.*.closes_at' => ['nullable', 'date_format:H:i'], 'operating_hours.*.notes' => ['nullable', 'string', 'max:255'], 'operating_exceptions' => ['sometimes', 'array', 'max:90'], 'operating_exceptions.*.date' => ['required', 'date_format:Y-m-d', 'distinct'], 'operating_exceptions.*.is_open' => ['required', 'boolean'], 'operating_exceptions.*.opens_at' => ['nullable', 'date_format:H:i'], 'operating_exceptions.*.closes_at' => ['nullable', 'date_format:H:i'], 'operating_exceptions.*.notes' => ['nullable', 'string', 'max:255']]);
        foreach ($data['operating_hours'] as $hour) {
            if ($hour['is_open'] && (empty($hour['opens_at']) || empty($hour['closes_at']) || $hour['opens_at'] >= $hour['closes_at'])) {
                abort(422, 'Horário de abertura deve ser anterior ao fechamento.');
            }
            OperatingHour::query()->updateOrCreate(['company_id' => $company->id, 'weekday' => $hour['weekday']], ['is_open' => $hour['is_open'], 'opens_at' => $hour['is_open'] ? $hour['opens_at'] : null, 'closes_at' => $hour['is_open'] ? $hour['closes_at'] : null, 'notes' => $hour['notes'] ?? null]);
        }
        if (array_key_exists('operating_exceptions', $data)) {
            $dates = [];
            foreach ($data['operating_exceptions'] as $exception) {
                if ($exception['is_open'] && (empty($exception['opens_at']) || empty($exception['closes_at']) || $exception['opens_at'] >= $exception['closes_at'])) {
                    abort(422, 'Horario especial de abertura deve ser anterior ao fechamento.');
                }
                $dates[] = $exception['date'];
                DB::table('operating_hour_exceptions')->updateOrInsert(['company_id' => $company->id, 'date' => $exception['date']], ['is_open' => $exception['is_open'], 'opens_at' => $exception['is_open'] ? $exception['opens_at'] : null, 'closes_at' => $exception['is_open'] ? $exception['closes_at'] : null, 'notes' => $exception['notes'] ?? null, 'updated_at' => now(), 'created_at' => now()]);
            }
            if ($dates === []) {
                OperatingHourException::query()->where('company_id', $company->id)->delete();
            } else {
                OperatingHourException::query()->where('company_id', $company->id)->whereNotIn('date', $dates)->delete();
            }
        }
        $company->setting()->updateOrCreate([], ['timezone' => $data['timezone']]);

        return response()->json(['data' => $this->data($company->fresh()->load('setting'))]);
    }

    private function data($company): array
    {
        $company->loadMissing('setting');
        $hours = $company->operatingHours()->get()->keyBy('weekday');

        return ['timezone' => $company->setting?->timezone ?? config('app.timezone'), 'operating_hours' => collect(range(0, 6))->map(fn ($weekday) => ['weekday' => $weekday, 'is_open' => (bool) ($hours[$weekday]->is_open ?? false), 'opens_at' => $hours[$weekday]->opens_at ?? null, 'closes_at' => $hours[$weekday]->closes_at ?? null, 'notes' => $hours[$weekday]->notes ?? null])->all(), 'operating_exceptions' => OperatingHourException::query()->where('company_id', $company->id)->orderBy('date')->get()->map(fn (OperatingHourException $exception) => ['date' => $exception->date->toDateString(), 'is_open' => $exception->is_open, 'opens_at' => $exception->opens_at, 'closes_at' => $exception->closes_at, 'notes' => $exception->notes])->all()];
    }
}
