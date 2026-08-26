<?php

namespace App\Services\Ai;

use App\Models\AiAutomationSetting;
use App\Models\Company;

class CopilotAutomationSettings
{
    public const PROVIDER = 'copilot';

    public function rolloutFor(Company $company): string
    {
        $setting = AiAutomationSetting::query()
            ->where('company_id', $company->id)
            ->where('provider', self::PROVIDER)
            ->first();

        $configured = is_array($setting?->settings) ? ($setting->settings['rollout'] ?? null) : null;

        // Jobs check this setting immediately before an effect. Do not cache it
        // for the lifetime of the worker: an operator may revoke rollout while
        // a Copilot analysis is still in progress.
        return app(CopilotAutomationAuthorityPolicy::class)->normalizeRollout(
            is_string($configured) ? $configured : (string) config('chatbotcrm.ai.copilot.default_rollout', 'disabled'),
        );
    }

    public function globallyEnabled(): bool
    {
        return (bool) config('chatbotcrm.ai.copilot.act_safe_enabled', false);
    }
}
