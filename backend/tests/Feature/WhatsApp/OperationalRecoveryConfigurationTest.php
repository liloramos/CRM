<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AiAutomationSetting;
use App\Models\Company;
use App\Models\ConversationQuickReply;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Database\Seeders\SolRestaurantConversationConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationalRecoveryConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_company_command_is_explicit_idempotent_and_never_stores_the_token(): void
    {
        $company = Company::query()->create(['name' => 'Restaurante Sol', 'slug' => 'restaurante-sol']);
        config()->set('chatbotcrm.whatsapp.provider', 'meta');
        config()->set('chatbotcrm.whatsapp.meta.phone_number_id', 'safe-test-phone-id');
        config()->set('chatbotcrm.whatsapp.meta.business_account_id', 'safe-test-waba-id');
        config()->set('chatbotcrm.whatsapp.meta.token', 'safe-test-token-not-persisted');

        $this->artisan('whatsapp:configure-company', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('whatsapp_accounts', 0);

        $this->artisan('whatsapp:configure-company')->assertSuccessful();
        $this->artisan('whatsapp:configure-company')->assertSuccessful();

        $this->assertDatabaseCount('whatsapp_accounts', 1);
        $account = WhatsAppAccount::query()->firstOrFail();
        $this->assertSame($company->id, $account->company_id);
        $this->assertSame(WhatsAppAccount::PROVIDER_META_CLOUD, $account->provider);
        $this->assertTrue($account->is_default);
        $this->assertFalse((bool) data_get($account->settings, 'stores_access_token', true));
        $this->assertStringNotContainsString('safe-test-token-not-persisted', json_encode($account->settings));
    }

    public function test_whatsapp_company_command_rejects_cross_tenant_phone_number_conflict(): void
    {
        Company::query()->create(['name' => 'Restaurante Sol', 'slug' => 'restaurante-sol']);
        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        WhatsAppAccount::query()->create([
            'company_id' => $otherCompany->id,
            'provider' => WhatsAppAccount::PROVIDER_META_CLOUD,
            'name' => 'Conta externa',
            'phone_number_id' => 'shared-phone-id',
            'business_account_id' => 'external-waba-id',
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'is_default' => true,
        ]);
        config()->set('chatbotcrm.whatsapp.provider', 'meta');
        config()->set('chatbotcrm.whatsapp.meta.phone_number_id', 'shared-phone-id');
        config()->set('chatbotcrm.whatsapp.meta.business_account_id', 'configured-waba-id');

        $this->artisan('whatsapp:configure-company')->assertFailed();

        $this->assertDatabaseCount('whatsapp_accounts', 1);
        $this->assertDatabaseMissing('whatsapp_accounts', [
            'company_id' => Company::query()->where('slug', 'restaurante-sol')->value('id'),
            'phone_number_id' => 'shared-phone-id',
        ]);
    }

    public function test_quick_replies_and_ai_style_are_idempotent_and_keep_payment_human_only(): void
    {
        $company = Company::query()->create(['name' => 'Restaurante Sol', 'slug' => 'restaurante-sol']);
        User::factory()->create(['company_id' => $company->id]);

        $this->seed(SolRestaurantConversationConfigurationSeeder::class);
        $this->seed(SolRestaurantConversationConfigurationSeeder::class);

        $this->assertSame(6, ConversationQuickReply::query()->where('company_id', $company->id)->count());
        $this->assertSame(
            ['delivery', 'marmitas', 'ola', 'orientacoes', 'pix', 'valores'],
            ConversationQuickReply::query()->where('company_id', $company->id)->orderBy('shortcut')->pluck('shortcut')->all(),
        );
        $this->assertDatabaseHas('conversation_quick_replies', [
            'company_id' => $company->id,
            'shortcut' => 'orientacoes',
            'category' => ConversationQuickReply::CATEGORY_INFORMATION,
            'is_active' => true,
        ]);
        $this->assertStringContainsString(
            'conferência',
            ConversationQuickReply::query()->where('company_id', $company->id)->where('shortcut', 'pix')->value('body'),
        );

        $setting = AiAutomationSetting::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertFalse($setting->automation_enabled);
        $this->assertFalse($setting->allow_auto_send);
        $this->assertTrue($setting->require_human_confirmation_for_payments);
        $this->assertSame('Sol Restaurante', data_get($setting->settings, 'conversation_style.establishment_name'));
        $this->assertSame('moderate', data_get($setting->settings, 'conversation_style.emoji_usage'));
    }

    public function test_whatsapp_subscription_status_uses_config_and_never_prints_secrets(): void
    {
        config()->set('chatbotcrm.whatsapp.meta.token', 'safe-token-never-print');
        config()->set('chatbotcrm.whatsapp.meta.business_account_id', 'safe-waba-never-print');
        config()->set('chatbotcrm.whatsapp.meta.app_id', 'safe-app-id-123456');
        config()->set('chatbotcrm.whatsapp.meta.api_version', 'v20.0');
        config()->set('chatbotcrm.whatsapp.meta.graph_url', 'https://graph.example.test');

        Http::fake([
            'https://graph.example.test/v20.0/safe-waba-never-print/subscribed_apps*' => Http::response([
                'data' => [
                    ['id' => 'safe-app-id-123456', 'name' => 'Aplicativo sanitizado'],
                ],
            ], 200),
        ]);

        Artisan::call('whatsapp:subscription-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Aplicativo atual aparece', $output);
        $this->assertStringContainsString('...123456', $output);
        $this->assertStringNotContainsString('safe-token-never-print', $output);
        $this->assertStringNotContainsString('safe-waba-never-print', $output);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://graph.example.test/v20.0/safe-waba-never-print/subscribed_apps?fields=id%2Cname');
    }

    public function test_whatsapp_subscribe_waba_is_dry_run_until_apply_is_used(): void
    {
        config()->set('chatbotcrm.whatsapp.meta.token', 'safe-token-never-print');
        config()->set('chatbotcrm.whatsapp.meta.business_account_id', 'safe-waba-never-print');
        config()->set('chatbotcrm.whatsapp.meta.api_version', 'v20.0');
        config()->set('chatbotcrm.whatsapp.meta.graph_url', 'https://graph.example.test');

        Http::fake();

        Artisan::call('whatsapp:subscribe-waba');
        $output = Artisan::output();

        $this->assertStringContainsString('Dry-run', $output);
        $this->assertStringNotContainsString('safe-token-never-print', $output);
        $this->assertStringNotContainsString('safe-waba-never-print', $output);
        Http::assertNothingSent();

        Http::fake([
            'https://graph.example.test/v20.0/safe-waba-never-print/subscribed_apps' => Http::response(['success' => true], 200),
        ]);

        Artisan::call('whatsapp:subscribe-waba', ['--apply' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Inscricao solicitada', $output);
        $this->assertStringNotContainsString('safe-token-never-print', $output);
        $this->assertStringNotContainsString('safe-waba-never-print', $output);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://graph.example.test/v20.0/safe-waba-never-print/subscribed_apps');
    }

    public function test_whatsapp_subscription_status_reports_sanitized_provider_error(): void
    {
        config()->set('chatbotcrm.whatsapp.meta.token', 'safe-token-never-print');
        config()->set('chatbotcrm.whatsapp.meta.business_account_id', 'safe-waba-never-print');
        config()->set('chatbotcrm.whatsapp.meta.api_version', 'v20.0');
        config()->set('chatbotcrm.whatsapp.meta.graph_url', 'https://graph.example.test');

        Http::fake([
            'https://graph.example.test/v20.0/safe-waba-never-print/subscribed_apps*' => Http::response([
                'error' => [
                    'code' => 190,
                    'error_subcode' => 463,
                    'type' => 'OAuthException',
                    'message' => 'provider message must not be printed',
                ],
            ], 401),
        ]);

        $exitCode = Artisan::call('whatsapp:subscription-status');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('whatsapp_token_expired', $output);
        $this->assertStringContainsString('463', $output);
        $this->assertStringNotContainsString('provider message must not be printed', $output);
        $this->assertStringNotContainsString('safe-token-never-print', $output);
        $this->assertStringNotContainsString('safe-waba-never-print', $output);
    }

    public function test_whatsapp_subscription_status_reports_sanitized_network_failure(): void
    {
        config()->set('chatbotcrm.whatsapp.meta.token', 'safe-token-never-print');
        config()->set('chatbotcrm.whatsapp.meta.business_account_id', 'safe-waba-never-print');
        config()->set('chatbotcrm.whatsapp.meta.api_version', 'v20.0');
        config()->set('chatbotcrm.whatsapp.meta.graph_url', 'https://graph.example.test');

        Http::fake(function (): void {
            throw new \RuntimeException('cURL error 7: Failed to connect to 127.0.0.1 port 9 for https://graph.example.test/v20.0/safe-waba-never-print/subscribed_apps');
        });

        $exitCode = Artisan::call('whatsapp:subscription-status');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('whatsapp_network_failure', $output);
        $this->assertStringNotContainsString('https://graph.example.test', $output);
        $this->assertStringNotContainsString('safe-token-never-print', $output);
        $this->assertStringNotContainsString('safe-waba-never-print', $output);
    }
}
