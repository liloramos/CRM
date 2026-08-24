<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigureOperationalDayCommand extends Command
{
    protected $signature = 'company:configure-operational-day
        {--company= : Slug exato da empresa}
        {--start=00:00 : Horário de início no formato HH:MM}
        {--force : Substitui um horário já configurado}';

    protected $description = 'Configura de forma idempotente o início do dia operacional de uma empresa.';

    public function handle(): int
    {
        $companySlug = trim((string) $this->option('company'));
        $start = trim((string) $this->option('start'));
        if ($companySlug === '') {
            $this->error('Informe a empresa explicitamente com --company=<slug>.');

            return self::FAILURE;
        }
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1) {
            $this->error('O horário deve usar o formato HH:MM entre 00:00 e 23:59.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($companySlug, $start): array {
            $company = Company::query()->where('slug', $companySlug)->lockForUpdate()->first();
            if (! $company instanceof Company) {
                return ['status' => 'company_not_found'];
            }

            $setting = CompanySetting::query()->where('company_id', $company->id)->lockForUpdate()->first();
            if (! $setting instanceof CompanySetting) {
                return ['status' => 'setting_not_found'];
            }

            $settings = is_array($setting->settings) ? $setting->settings : [];
            $existing = $settings['operational_day_start_time'] ?? null;
            if (is_string($existing) && $existing !== '' && ! $this->option('force')) {
                return ['status' => 'preserved', 'value' => $existing];
            }

            $settings['operational_day_start_time'] = $start;
            $setting->forceFill(['settings' => $settings])->save();

            return ['status' => 'updated', 'value' => $start];
        });

        if ($result['status'] === 'company_not_found') {
            $this->error("Empresa [{$companySlug}] não encontrada.");

            return self::FAILURE;
        }
        if ($result['status'] === 'setting_not_found') {
            $this->error("A empresa [{$companySlug}] não possui configuração operacional.");

            return self::FAILURE;
        }
        if ($result['status'] === 'preserved') {
            $this->info("Início do dia operacional preservado em {$result['value']}. Use --force para alterar.");

            return self::SUCCESS;
        }

        $this->info("Início do dia operacional configurado para {$result['value']}.");

        return self::SUCCESS;
    }
}
