<?php

namespace App\Console\Commands;

use App\Models\AiAutomationSetting;
use App\Models\Company;
use App\Models\Conversation;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigureCopilotRolloutCommand extends Command
{
    protected $signature = 'ai:copilot-rollout
        {rollout : disabled, shadow ou act_safe}
        {--company= : Slug exato da empresa}
        {--confirm : Aplica a alteracao; sem esta opcao o comando e somente dry-run}';

    protected $description = 'Consulta ou altera de forma explicita o rollout do Copiloto para uma empresa.';

    public function handle(CopilotAutomationAuthorityPolicy $policy): int
    {
        $companySlug = trim((string) $this->option('company'));
        $requested = strtolower(trim((string) $this->argument('rollout')));
        $target = $policy->normalizeRollout($requested);

        if ($companySlug === '') {
            $this->error('Informe a empresa explicitamente com --company=<slug>.');

            return self::FAILURE;
        }
        if ($target !== $requested) {
            $this->error('Rollout invalido. Use disabled, shadow ou act_safe.');

            return self::FAILURE;
        }

        $company = Company::query()->where('slug', $companySlug)->first();
        if (! $company instanceof Company) {
            $this->error("Empresa [{$companySlug}] nao encontrada.");

            return self::FAILURE;
        }
        if ($target === CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE
            && ! (bool) config('chatbotcrm.ai.copilot.act_safe_enabled', false)) {
            $this->error('ACT_SAFE permanece bloqueado pelo flag global AI_COPILOT_ACT_SAFE_ENABLED.');

            return self::FAILURE;
        }

        $current = app(CopilotAutomationSettings::class)->rolloutFor($company);
        $this->table(['Empresa', 'Atual', 'Solicitado'], [[$company->slug, $current, $target]]);

        if (! $this->option('confirm')) {
            $this->warn('Dry-run: nenhuma configuracao foi alterada. Use --confirm para aplicar.');

            return self::SUCCESS;
        }
        if ($current === $target) {
            $this->info("Rollout ja esta configurado como {$target}; nenhuma alteracao necessaria.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($company, $target): void {
            $setting = AiAutomationSetting::query()
                ->where('company_id', $company->id)
                ->where('provider', CopilotAutomationSettings::PROVIDER)
                ->lockForUpdate()
                ->first();

            if (! $setting instanceof AiAutomationSetting) {
                $setting = new AiAutomationSetting([
                    'company_id' => $company->id,
                    'provider' => CopilotAutomationSettings::PROVIDER,
                    'default_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
                    'automation_enabled' => true,
                    'allow_auto_send' => false,
                    'require_human_confirmation_for_ambiguous' => true,
                    'require_human_confirmation_for_payments' => true,
                    'status' => AiAutomationSetting::STATUS_ACTIVE,
                ]);
            }

            $settings = is_array($setting->settings) ? $setting->settings : [];
            $settings['rollout'] = $target;
            $setting->settings = $settings;
            $setting->save();
        });

        $this->info("Rollout do Copiloto alterado de {$current} para {$target}.");

        return self::SUCCESS;
    }
}
