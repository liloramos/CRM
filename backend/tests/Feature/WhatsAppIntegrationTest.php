<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessageDelivery;
use App\Models\WhatsAppWebhookEvent;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_reads_tenant_scoped_masked_status_without_credentials(): void
    {
        [$manager, $company] = $this->manager();
        $account = WhatsAppAccount::query()->create([
            'company_id' => $company->id,
            'provider' => WhatsAppAccount::PROVIDER_META_CLOUD,
            'name' => 'Canal Sol',
            'phone_number_id' => '1234567890',
            'business_account_id' => '9876543210',
            'display_phone_number' => '62999998888',
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'is_default' => true,
            'webhook_verified_at' => now(),
            'last_webhook_at' => now(),
            'settings' => ['api_version' => 'v20.0', 'token' => 'secret'],
        ]);
        WhatsAppMessageDelivery::query()->create([
            'company_id' => $company->id,
            'whatsapp_account_id' => $account->id,
            'provider' => WhatsAppAccount::PROVIDER_META_CLOUD,
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'status' => WhatsAppMessageDelivery::STATUS_RECEIVED,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        WhatsAppMessageDelivery::query()->create([
            'company_id' => $company->id,
            'whatsapp_account_id' => $account->id,
            'provider' => WhatsAppAccount::PROVIDER_META_CLOUD,
            'direction' => WhatsAppMessageDelivery::DIRECTION_OUTBOUND,
            'status' => WhatsAppMessageDelivery::STATUS_FAILED,
            'sent_at' => now(),
            'failed_at' => now(),
            'error_message' => 'raw provider secret',
        ]);
        $other = Company::query()->create(['name' => 'Outra', 'slug' => 'outra']);
        WhatsAppWebhookEvent::query()->create([
            'company_id' => $other->id,
            'provider' => WhatsAppAccount::PROVIDER_META_CLOUD,
            'status' => WhatsAppWebhookEvent::STATUS_FAILED,
            'received_at' => now(),
            'error_message' => 'foreign secret',
        ]);

        config([
            'chatbotcrm.whatsapp.provider' => 'meta_cloud',
            'chatbotcrm.whatsapp.meta.token' => 'access-token-secret',
            'chatbotcrm.whatsapp.meta.phone_number_id' => '1234567890',
            'chatbotcrm.whatsapp.meta.business_account_id' => '9876543210',
            'chatbotcrm.whatsapp.meta.verify_token' => 'verify-secret',
        ]);

        $this->actingAs($manager)
            ->getJson('/api/app/integrations/whatsapp')
            ->assertOk()
            ->assertJsonPath('data.connection_status', 'connected')
            ->assertJsonPath('data.phone_number_id_masked', '••••••7890')
            ->assertJsonPath('data.waba_id_masked', '••••••3210')
            ->assertJsonPath('data.recent_errors.0.kind', 'Falha de envio')
            ->assertJsonPath('data.last_inbound.status', WhatsAppMessageDelivery::STATUS_RECEIVED)
            ->assertJsonPath('data.last_outbound.status', WhatsAppMessageDelivery::STATUS_FAILED)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.app_secret')
            ->assertJsonMissingPath('data.verify_token')
            ->assertJsonMissingPath('data.recent_errors.0.error_message');
    }

    public function test_authorization_and_safe_connectivity_check_do_not_create_operational_records(): void
    {
        [$manager] = $this->manager();
        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized)->getJson('/api/app/integrations/whatsapp')->assertForbidden();
        $this->actingAs($unauthorized)->postJson('/api/app/integrations/whatsapp/check')->assertForbidden();

        config([
            'chatbotcrm.whatsapp.provider' => 'meta_cloud',
            'chatbotcrm.whatsapp.meta.token' => 'access-token-secret',
            'chatbotcrm.whatsapp.meta.phone_number_id' => '1234567890',
            'chatbotcrm.whatsapp.meta.verify_token' => 'verify-secret',
        ]);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['id' => '1234567890'])]);

        $this->actingAs($manager)
            ->postJson('/api/app/integrations/whatsapp/check')
            ->assertOk()
            ->assertJsonPath('data.status', 'validated');
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    /** @return array{User, Company} */
    private function manager(): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return [$user, $company];
    }
}
