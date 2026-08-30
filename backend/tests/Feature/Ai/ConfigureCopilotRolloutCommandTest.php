<?php

namespace Tests\Feature\Ai;

use App\Models\AiAutomationSetting;
use App\Models\Company;
use App\Models\Conversation;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigureCopilotRolloutCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_create_or_change_a_setting(): void
    {
        $company = Company::query()->create(['name' => 'Empresa Teste', 'slug' => 'empresa-teste']);
        config()->set('chatbotcrm.ai.copilot.act_safe_enabled', true);

        $this->artisan('ai:copilot-rollout', ['rollout' => 'act_safe', '--company' => $company->slug])
            ->expectsOutputToContain('Dry-run')
            ->assertSuccessful();

        $this->assertSame(0, AiAutomationSetting::query()->count());
    }

    public function test_act_safe_requires_the_global_safety_flag(): void
    {
        $company = Company::query()->create(['name' => 'Empresa Teste', 'slug' => 'empresa-teste']);
        config()->set('chatbotcrm.ai.copilot.act_safe_enabled', false);

        $this->artisan('ai:copilot-rollout', ['rollout' => 'act_safe', '--company' => $company->slug, '--confirm' => true])
            ->expectsOutputToContain('permanece bloqueado pelo flag global')
            ->assertFailed();

        $this->assertSame(0, AiAutomationSetting::query()->count());
    }

    public function test_confirmed_rollout_is_company_scoped_reversible_and_preserves_other_settings(): void
    {
        $company = Company::query()->create(['name' => 'Empresa Teste', 'slug' => 'empresa-teste']);
        $other = Company::query()->create(['name' => 'Outra Empresa', 'slug' => 'outra-empresa']);
        config()->set('chatbotcrm.ai.copilot.act_safe_enabled', true);
        $setting = $this->setting($company, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW, ['conversation_style' => 'objetivo']);
        $otherSetting = $this->setting($other, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW);

        $this->artisan('ai:copilot-rollout', ['rollout' => 'act_safe', '--company' => $company->slug, '--confirm' => true])
            ->expectsOutputToContain('alterado de shadow para act_safe')
            ->assertSuccessful();

        $this->assertSame('act_safe', data_get($setting->fresh()->settings, 'rollout'));
        $this->assertSame('objetivo', data_get($setting->fresh()->settings, 'conversation_style'));
        $this->assertSame('shadow', data_get($otherSetting->fresh()->settings, 'rollout'));

        $this->artisan('ai:copilot-rollout', ['rollout' => 'shadow', '--company' => $company->slug, '--confirm' => true])
            ->expectsOutputToContain('alterado de act_safe para shadow')
            ->assertSuccessful();

        $this->assertSame('shadow', data_get($setting->fresh()->settings, 'rollout'));
        $this->assertSame('objetivo', data_get($setting->fresh()->settings, 'conversation_style'));
    }

    private function setting(Company $company, string $rollout, array $extraSettings = []): AiAutomationSetting
    {
        return AiAutomationSetting::query()->create([
            'company_id' => $company->id,
            'provider' => CopilotAutomationSettings::PROVIDER,
            'default_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_enabled' => true,
            'allow_auto_send' => false,
            'require_human_confirmation_for_ambiguous' => true,
            'require_human_confirmation_for_payments' => true,
            'status' => AiAutomationSetting::STATUS_ACTIVE,
            'settings' => ['rollout' => $rollout, ...$extraSettings],
        ]);
    }
}
