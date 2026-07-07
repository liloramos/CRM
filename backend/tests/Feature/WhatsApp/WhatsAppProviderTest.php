<?php

namespace Tests\Feature\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Models\Company;
use App\Models\Message;
use App\Models\WhatsAppMessageDelivery;
use App\Services\WhatsApp\WhatsAppService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WhatsAppProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_provider_send_records_delivery_without_external_api(): void
    {
        $this->seed([CompanySeeder::class, WhatsAppSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $whatsapp = app(WhatsAppService::class);

        $delivery = $whatsapp->sendTextMessage(
            $company,
            '15550100001',
            'Mensagem sanitizada de desenvolvimento.',
            ['customer_name' => 'Cliente WhatsApp Sanitizado'],
        );

        $this->assertSame('fake', $delivery->provider);
        $this->assertSame(WhatsAppMessageDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(WhatsAppMessageDelivery::DIRECTION_OUTBOUND, $delivery->direction);
        $this->assertStringStartsWith('fake_', (string) $delivery->provider_message_id);
        $this->assertSame(1, Message::query()->where('sender', 'agent')->count());
        $this->assertFalse($delivery->safe_payload['external_api_called'] ?? false);
    }

    public function test_webhook_verification_and_receive_persists_safe_event_and_message(): void
    {
        $this->seed([CompanySeeder::class, WhatsAppSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.whatsapp.fake.verify_token', 'safe-test-token');

        $this->get('/api/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=safe-test-token&hub.challenge=safe-challenge')
            ->assertOk()
            ->assertSee('safe-challenge');

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'fake-business-account-id',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => 'fake-phone-number-id',
                                    'display_phone_number' => '15550109999',
                                ],
                                'contacts' => [
                                    [
                                        'wa_id' => '15550100001',
                                        'profile' => ['name' => 'Cliente WhatsApp Sanitizado'],
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.safe-message-id',
                                        'from' => '15550100001',
                                        'timestamp' => '1780000000',
                                        'type' => 'text',
                                        'text' => ['body' => 'Pedido sanitizado de teste.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/whatsapp/meta', $payload)
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'provider' => 'fake',
            'event_type' => 'message',
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('messages', [
            'sender' => 'customer',
            'content' => 'Pedido sanitizado de teste.',
            'external_message_id' => 'wamid.safe-message-id',
        ]);
        $this->assertDatabaseHas('whatsapp_message_deliveries', [
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'status' => WhatsAppMessageDelivery::STATUS_RECEIVED,
            'provider_message_id' => 'wamid.safe-message-id',
        ]);
    }

    public function test_meta_provider_status_does_not_expose_token_value(): void
    {
        Config::set('chatbotcrm.whatsapp.provider', 'meta_cloud');
        Config::set('chatbotcrm.whatsapp.meta.token', 'safe-test-token-not-real');
        Config::set('chatbotcrm.whatsapp.meta.phone_number_id', 'safe-phone-number-id');
        Config::set('chatbotcrm.whatsapp.meta.verify_token', 'safe-verify-token-not-real');

        $provider = app(WhatsAppProviderInterface::class);
        $status = $provider->connectionStatus()->toArray();
        $encodedStatus = json_encode($status);

        $this->assertSame('meta_cloud', $provider->name());
        $this->assertTrue($provider->isConfigured());
        $this->assertIsString($encodedStatus);
        $this->assertStringNotContainsString('safe-test-token-not-real', $encodedStatus);
        $this->assertTrue($status['details']['token_present']);
    }
}
