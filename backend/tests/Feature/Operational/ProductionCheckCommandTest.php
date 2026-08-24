<?php

namespace Tests\Feature\Operational;

use App\Services\Operational\ProductionReadinessChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ProductionCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_healthcheck_is_available_without_the_legacy_test_endpoint(): void
    {
        $this->get('/up')->assertOk();
        $this->getJson('/api/teste')->assertNotFound();
    }

    public function test_production_debug_enabled_fails_readiness(): void
    {
        $this->configureHealthyProduction();
        Config::set('app.debug', true);

        $this->artisan('app:production-check')
            ->expectsOutputToContain('APP_DEBUG')
            ->expectsOutputToContain('FAIL')
            ->assertFailed();
    }

    public function test_http_url_fails_production_readiness(): void
    {
        $this->configureHealthyProduction();
        Config::set('app.url', 'http://crm.example.test');

        $this->artisan('app:production-check')
            ->expectsOutputToContain('APP_URL')
            ->expectsOutputToContain('FAIL')
            ->assertFailed();
    }

    public function test_sync_queue_fails_production_readiness(): void
    {
        $this->configureHealthyProduction();
        Config::set('queue.default', 'sync');

        $this->artisan('app:production-check')
            ->expectsOutputToContain('Fila')
            ->expectsOutputToContain('FAIL')
            ->assertFailed();
    }

    public function test_missing_audio_binaries_warn_without_blocking_core_operation(): void
    {
        $this->configureHealthyProduction(false);

        $this->artisan('app:production-check')
            ->expectsOutputToContain('ffmpeg indisponível')
            ->expectsOutputToContain('WARNING')
            ->assertSuccessful();
    }

    public function test_healthy_configuration_passes_without_printing_secret_values(): void
    {
        $this->configureHealthyProduction();

        $this->artisan('app:production-check')
            ->expectsOutputToContain('Readiness concluída sem itens FAIL')
            ->doesntExpectOutputToContain('test-token-never-print')
            ->doesntExpectOutputToContain('test-openai-key-never-print')
            ->assertSuccessful();
    }

    private function configureHealthyProduction(bool $binariesAvailable = true): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        Config::set('app.debug', false);
        Config::set('app.url', 'https://crm.example.test');
        Config::set('app.timezone', 'America/Sao_Paulo');
        Config::set('queue.default', 'database');
        Config::set('session.driver', 'database');
        Config::set('session.secure', true);
        Config::set('filesystems.disks.public.root', storage_path('app'));
        Config::set('chatbotcrm.whatsapp.provider', 'meta');
        Config::set('chatbotcrm.whatsapp.meta.token', 'test-token-never-print');
        Config::set('chatbotcrm.whatsapp.meta.phone_number_id', 'test-phone-id-never-print');
        Config::set('chatbotcrm.whatsapp.meta.business_account_id', 'test-waba-id-never-print');
        Config::set('chatbotcrm.whatsapp.meta.verify_token', 'test-verify-token-never-print');
        Config::set('chatbotcrm.whatsapp.meta.app_secret', 'test-app-secret-never-print');
        Config::set('chatbotcrm.whatsapp.demo_data_enabled', false);
        Config::set('chatbotcrm.ai.copilot.provider', 'openai');
        Config::set('chatbotcrm.ai.openai.api_key', 'test-openai-key-never-print');
        $this->app->instance(
            ProductionReadinessChecker::class,
            new ProductionReadinessChecker(static fn (): bool => $binariesAvailable),
        );
    }
}
