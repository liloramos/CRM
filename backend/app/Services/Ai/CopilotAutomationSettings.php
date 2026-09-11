<?php

namespace App\Services\Ai;

use App\Models\AiAutomationSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CopilotAutomationSettings
{
    public const PROVIDER = 'copilot';

    public const GUIDANCE_VERSION = 1;

    public const MAX_GUIDANCE_ITEMS = 20;

    public const MAX_GUIDANCE_LENGTH = 300;

    public const MAX_GUIDANCE_TOTAL_LENGTH = 4000;

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

    /** @return array{version:int,instructions:list<string>,updated_at:?string,updated_by_user_id:?int} */
    public function guidanceFor(Company $company): array
    {
        $setting = $this->settingFor($company);
        $guidance = data_get($setting?->settings, 'copilot_guidance', []);

        return [
            'version' => self::GUIDANCE_VERSION,
            'instructions' => $this->sanitizeInstructions(is_array($guidance) ? ($guidance['instructions'] ?? []) : []),
            'updated_at' => is_array($guidance) && is_string($guidance['updated_at'] ?? null) ? $guidance['updated_at'] : null,
            'updated_by_user_id' => is_array($guidance) && is_numeric($guidance['updated_by_user_id'] ?? null) ? (int) $guidance['updated_by_user_id'] : null,
        ];
    }

    public function updateRollout(Company $company, string $rollout): AiAutomationSetting
    {
        return $this->persist($company, [
            ...$this->currentSettings($company),
            'rollout' => app(CopilotAutomationAuthorityPolicy::class)->normalizeRollout($rollout),
        ]);
    }

    /** @param list<mixed> $instructions */
    public function updateGuidance(Company $company, User $user, array $instructions): AiAutomationSetting
    {
        $instructions = $this->sanitizeInstructions($instructions, true);

        return $this->persist($company, [
            ...$this->currentSettings($company),
            'copilot_guidance' => [
                'version' => self::GUIDANCE_VERSION,
                'instructions' => $instructions,
                'updated_at' => now()->toIso8601String(),
                'updated_by_user_id' => (int) $user->id,
            ],
        ]);
    }

    private function settingFor(Company $company): ?AiAutomationSetting
    {
        return AiAutomationSetting::query()
            ->where('company_id', $company->id)
            ->where('provider', self::PROVIDER)
            ->first();
    }

    /** @return array<string,mixed> */
    private function currentSettings(Company $company): array
    {
        $settings = $this->settingFor($company)?->settings;

        return is_array($settings) ? $settings : [];
    }

    /** @param array<string,mixed> $settings */
    private function persist(Company $company, array $settings): AiAutomationSetting
    {
        return AiAutomationSetting::query()->updateOrCreate(
            ['company_id' => $company->id, 'provider' => self::PROVIDER],
            [
                'default_mode' => 'automatic',
                'automation_enabled' => (bool) config('chatbotcrm.ai.automation_enabled', true),
                'allow_auto_send' => (bool) config('chatbotcrm.ai.allow_auto_send', false),
                'require_human_confirmation_for_ambiguous' => true,
                'require_human_confirmation_for_payments' => true,
                'status' => AiAutomationSetting::STATUS_ACTIVE,
                'settings' => $settings,
            ],
        );
    }

    /** @param mixed $instructions @return list<string> */
    private function sanitizeInstructions(mixed $instructions, bool $validateSafety = false): array
    {
        if (! is_array($instructions)) {
            return [];
        }

        $clean = collect($instructions)
            ->filter(fn (mixed $instruction): bool => is_string($instruction))
            ->map(fn (string $instruction): string => Str::squish(Str::limit($instruction, self::MAX_GUIDANCE_LENGTH, '')))
            ->filter()
            ->unique(fn (string $instruction): string => Str::lower($instruction))
            ->take(self::MAX_GUIDANCE_ITEMS)
            ->values()
            ->all();

        if ($validateSafety && array_sum(array_map('mb_strlen', $clean)) > self::MAX_GUIDANCE_TOTAL_LENGTH) {
            throw ValidationException::withMessages([
                'instructions' => 'O conjunto de orientações ultrapassa o limite de conteúdo permitido.',
            ]);
        }

        if ($validateSafety) {
            foreach ($clean as $index => $instruction) {
                if ($this->weakensProtectedAuthority($instruction)) {
                    throw ValidationException::withMessages([
                        "instructions.{$index}" => 'Essa orientação entra em conflito com uma proteção obrigatória do sistema.',
                    ]);
                }
            }
        }

        return $clean;
    }

    private function weakensProtectedAuthority(string $instruction): bool
    {
        $text = Str::of($instruction)->ascii()->lower()->squish()->toString();
        $protectedAction = preg_match('/\b(confirm|aprov|valid|rejeit|anul|estorn|reembols|refund|exclu|apag|conced|liber|alter|dar|aplic)\w*\b/', $text) === 1;
        $protectedSubject = preg_match('/\b(pix|pagamento|comprovante|credito|refund|reembolso|estorno|taxa|pedido|registro|desconto)\w*\b/', $text) === 1;
        $permissionLanguage = preg_match('/\b(pode|permit|automatic|sem\s+(?:revisao|confirmacao|humano)|nao\s+precisa)\w*\b/', $text) === 1;

        return $protectedAction && $protectedSubject && $permissionLanguage;
    }
}
