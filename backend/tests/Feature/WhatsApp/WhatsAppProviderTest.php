<?php

namespace Tests\Feature\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Jobs\ProcessWhatsAppWebhookEvent;
use App\Models\AiResponseSuggestion;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppMessageDelivery;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppErrorClassifier;
use App\Services\WhatsApp\WhatsAppService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        Config::set('chatbotcrm.whatsapp.meta.app_secret', '');

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=safe-test-token&hub.challenge=safe-challenge')
            ->assertOk()
            ->assertSee('safe-challenge');

        $this->get('/api/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=safe-test-token&hub.challenge=safe-alias-challenge')
            ->assertOk()
            ->assertSee('safe-alias-challenge');

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
            ->assertJson(['status' => 'received']);

        $event = WhatsAppWebhookEvent::query()->firstOrFail();
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

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

    public function test_webhook_signature_validation_blocks_invalid_signed_payload(): void
    {
        $this->seed([CompanySeeder::class, WhatsAppSeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.whatsapp.meta.app_secret', 'safe-app-secret');

        $this->postJson('/api/webhooks/whatsapp', $this->textPayload('wamid.invalid-signature'), [
            'X-Hub-Signature-256' => 'sha256=invalid',
        ])->assertForbidden();

        $this->assertSame(0, WhatsAppWebhookEvent::query()->count());
        $this->assertSame(0, Message::query()->count());
    }

    public function test_signed_raw_meta_webhook_completes_inbound_pipeline_and_is_returned_by_api(): void
    {
        $company = $this->prepareWhatsApp(withRoles: true);
        Config::set('chatbotcrm.whatsapp.meta.app_secret', 'safe-app-secret');
        Queue::fake();
        $rawPayload = file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound-text.json'));
        $this->assertIsString($rawPayload);
        $signature = 'sha256='.hash_hmac('sha256', $rawPayload, 'safe-app-secret');

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $rawPayload,
        )
            ->assertOk()
            ->assertJsonPath('status', WhatsAppWebhookEvent::STATUS_RECEIVED)
            ->assertJsonStructure(['event_id', 'correlation_id']);

        $event = WhatsAppWebhookEvent::query()->firstOrFail();
        Queue::assertPushed(
            ProcessWhatsAppWebhookEvent::class,
            fn (ProcessWhatsAppWebhookEvent $job): bool => $job->eventId === $event->id,
        );

        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

        $event->refresh();
        $conversation = Conversation::query()->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        $this->assertTrue($event->signature_present);
        $this->assertSame(WhatsAppWebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertSame('event_completed', data_get($event->sanitized_payload, 'inbound_trace.last_stage'));
        $this->assertSame($company->id, $event->company_id);
        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id,
            'whatsapp_id' => '15550000001',
            'source_channel' => 'whatsapp',
        ]);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'company_id' => $company->id,
            'channel' => 'whatsapp',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'external_message_id' => 'wamid.fixture-inbound-message',
            'content' => 'Mensagem inbound sanitizada.',
        ]);

        $this->actingAs($user)
            ->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.id', (string) $conversation->id)
            ->assertJsonPath('data.conversations.0.messages.0.body', 'Mensagem inbound sanitizada.');

        $this->actingAs($user)
            ->getJson("/api/app/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', (string) $conversation->id)
            ->assertJsonPath('data.messages.0.body', 'Mensagem inbound sanitizada.');

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $rawPayload,
        )
            ->assertOk()
            ->assertJsonPath('status', WhatsAppWebhookEvent::STATUS_PROCESSED);

        $this->assertSame(1, WhatsAppWebhookEvent::query()->count());
        $this->assertSame(1, Message::query()->where('external_message_id', 'wamid.fixture-inbound-message')->count());

        Artisan::call('whatsapp:inbound-status', ['--since' => 30]);
        $statusOutput = Artisan::output();
        $this->assertStringContainsString('event_completed', $statusOutput);
        $this->assertStringNotContainsString('Mensagem inbound sanitizada.', $statusOutput);
        $this->assertStringNotContainsString('15550000001', $statusOutput);
        $this->assertStringNotContainsString('wamid.fixture-inbound-message', $statusOutput);
    }

    public function test_unsigned_webhook_is_accepted_locally_when_app_secret_is_not_configured(): void
    {
        $this->prepareWhatsApp();
        Config::set('chatbotcrm.whatsapp.meta.app_secret', '');
        Queue::fake();

        $this->postJson('/api/webhooks/whatsapp', $this->textPayload('wamid.unsigned-local'))
            ->assertOk()
            ->assertJsonPath('status', WhatsAppWebhookEvent::STATUS_RECEIVED);

        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'signature_present' => false,
            'status' => WhatsAppWebhookEvent::STATUS_RECEIVED,
        ]);
    }

    public function test_message_without_contacts_is_processed_with_operational_fallback_name(): void
    {
        $this->prepareWhatsApp();
        Config::set('chatbotcrm.whatsapp.meta.app_secret', '');
        Queue::fake();
        $payload = $this->textPayload('wamid.no-contacts');
        unset($payload['entry'][0]['changes'][0]['value']['contacts']);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
        $event = WhatsAppWebhookEvent::query()->firstOrFail();
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

        $this->assertDatabaseHas('customers', [
            'name' => 'Cliente WhatsApp',
            'whatsapp_id' => '15550100001',
        ]);
        $this->assertDatabaseHas('messages', [
            'external_message_id' => 'wamid.no-contacts',
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
        ]);
    }

    public function test_unresolved_whatsapp_account_marks_event_failed_instead_of_silently_processing(): void
    {
        $this->seed(CompanySeeder::class);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.whatsapp.meta.app_secret', '');
        Queue::fake();

        $this->postJson('/api/webhooks/whatsapp', $this->textPayload('wamid.account-missing'))->assertOk();
        $event = WhatsAppWebhookEvent::query()->firstOrFail();
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

        $event->refresh();
        $this->assertSame(WhatsAppWebhookEvent::STATUS_FAILED, $event->status);
        $this->assertSame('event_failed', data_get($event->sanitized_payload, 'inbound_trace.last_stage'));
        $this->assertSame('whatsapp_account_not_resolved', data_get($event->sanitized_payload, 'inbound_trace.error_code'));
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Conversation::query()->count());
        $this->assertSame(0, Message::query()->count());
    }

    public function test_duplicate_webhook_event_does_not_duplicate_message(): void
    {
        $this->prepareWhatsApp();
        $payload = $this->textPayload('wamid.duplicate-message');

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertSame(1, WhatsAppWebhookEvent::query()->count());

        $event = WhatsAppWebhookEvent::query()->firstOrFail();
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

        $this->assertSame(1, Message::query()->where('external_message_id', 'wamid.duplicate-message')->count());
        $this->assertSame(1, Customer::query()->where('whatsapp_id', '15550100001')->count());
        $this->assertSame(1, Conversation::query()->count());
    }

    public function test_status_webhook_updates_delivery_and_message_state(): void
    {
        $company = $this->prepareWhatsApp();
        $delivery = app(WhatsAppService::class)->sendTextMessage($company, '15550100001', 'Mensagem de status.');

        $this->postJson('/api/webhooks/whatsapp', $this->statusPayload((string) $delivery->provider_message_id, 'delivered'))
            ->assertOk();

        $event = WhatsAppWebhookEvent::query()->latest('id')->firstOrFail();
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

        $this->assertDatabaseHas('whatsapp_message_deliveries', [
            'id' => $delivery->id,
            'status' => WhatsAppMessageDelivery::STATUS_DELIVERED,
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $delivery->message_id,
            'delivery_status' => WhatsAppMessageDelivery::STATUS_DELIVERED,
        ]);
    }

    public function test_incoming_message_does_not_generate_ai_suggestion_after_manual_takeover(): void
    {
        $company = $this->prepareWhatsApp();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Manual',
            'phone' => '15550100001',
            'whatsapp_id' => '15550100001',
            'source_channel' => 'whatsapp',
        ]);
        Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_MANUAL,
            'automation_status' => Conversation::AUTOMATION_STATUS_MANUAL_TAKEOVER,
            'human_review_required' => true,
            'whatsapp_identifier' => '15550100001',
            'started_at' => now(),
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->textPayload('wamid.manual-no-ai', 'Tem alguem ai?'))->assertOk();
        $event = WhatsAppWebhookEvent::query()->firstOrFail();
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

        $this->assertSame(0, AiResponseSuggestion::query()->count());
        $this->assertDatabaseHas('messages', [
            'external_message_id' => 'wamid.manual-no-ai',
            'sender_type' => 'customer',
        ]);
    }

    public function test_conversations_api_lists_messages_and_manual_reply_switches_to_manual_mode(): void
    {
        $company = $this->prepareWhatsApp(withRoles: true);
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Conversa',
            'phone' => '15550100001',
            'whatsapp_id' => '15550100001',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'whatsapp_identifier' => '15550100001',
            'started_at' => now(),
        ]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'sender_type' => 'customer',
            'content' => 'Oi, quero fazer um pedido.',
            'type' => 'text',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.customer.name', 'Cliente Conversa');

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/messages", [
                'body' => 'Pode me confirmar os itens?',
            ])
            ->assertOk()
            ->assertJsonPath('data.automationMode', Conversation::AUTOMATION_MODE_MANUAL)
            ->assertJsonPath('data.mode', 'atencao');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'human',
            'content' => 'Pode me confirmar os itens?',
        ]);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'automation_mode' => Conversation::AUTOMATION_MODE_MANUAL,
        ]);
    }

    public function test_opening_conversation_marks_only_inbound_messages_read_idempotently(): void
    {
        $company = $this->prepareWhatsApp(withRoles: true);
        Http::fake();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Leitura',
            'phone' => '15550100002',
            'whatsapp_id' => '15550100002',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'unread_count' => 2,
            'whatsapp_identifier' => '15550100002',
            'started_at' => now(),
        ]);
        $firstInbound = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'sender_type' => 'customer',
            'content' => 'Primeira mensagem.',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.read-first',
        ]);
        $secondInbound = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'sender_type' => 'customer',
            'content' => 'Segunda mensagem.',
            'type' => 'text',
            'provider' => 'fake',
            'external_message_id' => 'wamid.read-second',
        ]);
        $outbound = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'agent',
            'direction' => WhatsAppMessageDelivery::DIRECTION_OUTBOUND,
            'sender_type' => 'human',
            'content' => 'Resposta.',
            'type' => 'text',
            'provider' => 'fake',
        ]);
        $otherConversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'unread_count' => 1,
            'whatsapp_identifier' => '15550100003',
            'started_at' => now(),
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $this->assertNotNull($firstInbound->fresh()->read_at);
        $this->assertNotNull($secondInbound->fresh()->read_at);
        $this->assertNull($outbound->fresh()->read_at);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'unread_count' => 0]);
        $this->assertDatabaseHas('conversations', ['id' => $otherConversation->id, 'unread_count' => 1]);

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $this->assertSame(0, Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', WhatsAppMessageDelivery::DIRECTION_INBOUND)
            ->whereNull('read_at')
            ->count());
        Http::assertNothingSent();
    }

    public function test_mark_read_rejects_a_conversation_from_another_company(): void
    {
        $company = $this->prepareWhatsApp(withRoles: true);
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Isolado',
            'phone' => '15550100005',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'unread_count' => 1,
            'started_at' => now(),
        ]);
        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $user = User::factory()->create(['company_id' => $otherCompany->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/read")
            ->assertNotFound();

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'unread_count' => 1,
        ]);
    }

    public function test_meta_read_receipt_failure_does_not_undo_local_read(): void
    {
        $company = $this->prepareWhatsApp(withRoles: true);
        Config::set('chatbotcrm.whatsapp.provider', 'meta_cloud');
        Config::set('chatbotcrm.whatsapp.meta.token', 'safe-test-token');
        Config::set('chatbotcrm.whatsapp.meta.phone_number_id', 'safe-phone-number-id');
        Config::set('chatbotcrm.whatsapp.meta.verify_token', 'safe-verify-token');
        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response([
                'error' => ['message' => 'safe provider rejection', 'code' => 131000],
            ], 400),
        ]);

        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Recibo',
            'phone' => '15550100004',
            'whatsapp_id' => '15550100004',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'unread_count' => 1,
            'whatsapp_identifier' => '15550100004',
            'started_at' => now(),
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'direction' => WhatsAppMessageDelivery::DIRECTION_INBOUND,
            'sender_type' => 'customer',
            'content' => 'Recibo.',
            'type' => 'text',
            'provider' => 'meta_cloud',
            'external_message_id' => 'wamid.receipt-fails',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $this->assertNotNull($message->fresh()->read_at);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'unread_count' => 0]);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/messages'));
    }

    public function test_meta_provider_is_used_when_configured_and_calls_phone_number_id_endpoint(): void
    {
        $this->seed([CompanySeeder::class]);
        Config::set('chatbotcrm.whatsapp.provider', 'meta_cloud');
        Config::set('chatbotcrm.whatsapp.meta.token', 'safe-test-token-not-real');
        Config::set('chatbotcrm.whatsapp.meta.phone_number_id', 'safe-phone-number-id');
        Config::set('chatbotcrm.whatsapp.meta.business_account_id', 'safe-business-account-id');
        Config::set('chatbotcrm.whatsapp.meta.verify_token', 'safe-verify-token-not-real');
        Config::set('chatbotcrm.whatsapp.meta.api_version', 'v20.0');

        Http::fake([
            'https://graph.facebook.com/v20.0/safe-phone-number-id/messages' => Http::response([
                'messages' => [['id' => 'wamid.sent-by-meta']],
            ], 200),
        ]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $delivery = app(WhatsAppService::class)->sendTextMessage($company, '(62) 99999-0001', 'Mensagem real sanitizada.');

        $this->assertSame('meta_cloud', $delivery->provider);
        $this->assertSame(WhatsAppMessageDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('wamid.sent-by-meta', $delivery->provider_message_id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.facebook.com/v20.0/safe-phone-number-id/messages'
            && $request['to'] === '5562999990001');
    }

    public function test_meta_send_failure_returns_structured_422_and_retry_reuses_message(): void
    {
        $company = $this->prepareWhatsApp(withRoles: true);
        Config::set('chatbotcrm.whatsapp.provider', 'meta_cloud');
        Config::set('chatbotcrm.whatsapp.meta.token', 'safe-test-token-not-real');
        Config::set('chatbotcrm.whatsapp.meta.phone_number_id', 'safe-phone-number-id');
        Config::set('chatbotcrm.whatsapp.meta.business_account_id', 'safe-business-account-id');
        Config::set('chatbotcrm.whatsapp.meta.verify_token', 'safe-verify-token-not-real');
        Config::set('chatbotcrm.whatsapp.meta.api_version', 'v20.0');

        Http::fakeSequence()
            ->push(['error' => ['code' => 131047, 'type' => 'OAuthException']], 400)
            ->push(['messages' => [['id' => 'wamid.retry-ok']]], 200);

        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Meta',
            'phone' => '62999990000',
            'whatsapp_id' => '5562999990000',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'whatsapp_identifier' => '5562999990000',
            'started_at' => now(),
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/messages", [
                'body' => 'Mensagem que falha na Meta.',
                'client_reference' => 'safe-client-reference',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'whatsapp_customer_window_closed')
            ->assertJsonPath('data.messages.0.status', WhatsAppMessageDelivery::STATUS_FAILED);

        $message = Message::query()->where('conversation_id', $conversation->id)->where('direction', WhatsAppMessageDelivery::DIRECTION_OUTBOUND)->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/messages", [
                'body' => 'Mensagem que falha na Meta.',
                'client_reference' => 'safe-client-reference',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'whatsapp_customer_window_closed');

        $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->where('direction', WhatsAppMessageDelivery::DIRECTION_OUTBOUND)->count());
        $this->assertSame(1, WhatsAppMessageDelivery::query()->where('message_id', $message->id)->count());
        $this->assertSame(1, ConversationAlert::query()
            ->where('conversation_id', $conversation->id)
            ->where('deduplication_key', 'message-send-failed:'.$message->id)
            ->count());

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/messages/{$message->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.messages.0.status', WhatsAppMessageDelivery::STATUS_SENT);

        $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->where('direction', WhatsAppMessageDelivery::DIRECTION_OUTBOUND)->count());
        $this->assertSame(2, WhatsAppMessageDelivery::query()->where('message_id', $message->id)->count());
        $this->assertDatabaseHas('conversation_alerts', [
            'conversation_id' => $conversation->id,
            'deduplication_key' => 'message-send-failed:'.$message->id,
            'status' => ConversationAlert::STATUS_RESOLVED,
        ]);
        Http::assertSentCount(2);
    }

    public function test_operational_conversations_hide_demo_data_when_disabled(): void
    {
        $this->prepareWhatsApp(withRoles: true);
        Config::set('chatbotcrm.whatsapp.demo_data_enabled', false);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $demoCustomer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Exemplo',
            'email' => Customer::DEMO_EMAIL,
            'source_channel' => Customer::SOURCE_CHANNEL_DEMO,
        ]);
        Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $demoCustomer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);
        $realCustomer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Real',
            'phone' => '62999990001',
            'source_channel' => 'whatsapp',
        ]);
        Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $realCustomer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        $payload = $this->actingAs($user)->getJson('/api/app/conversations')->assertOk()->json('data.conversations');

        $this->assertCount(1, $payload);
        $this->assertSame('Cliente Real', $payload[0]['customer']['name']);
    }

    public function test_local_demo_cleanup_requires_confirmation_and_preserves_real_customers(): void
    {
        $company = $this->prepareWhatsApp();
        $demoCustomer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Registro marcado como demo',
            'email' => Customer::DEMO_EMAIL,
            'source_channel' => Customer::SOURCE_CHANNEL_DEMO,
        ]);
        $demoConversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $demoCustomer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);
        Message::query()->create([
            'conversation_id' => $demoConversation->id,
            'sender' => 'customer',
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'content' => 'Mensagem demonstrativa marcada.',
            'type' => 'text',
            'delivery_status' => 'received',
        ]);
        $realCustomer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente operacional preservado',
            'phone' => '5562999990009',
            'source_channel' => 'whatsapp',
        ]);
        $localAdmin = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'admin.gerente@example.test',
        ]);

        $this->assertSame(1, Artisan::call('whatsapp:cleanup-demo'));
        $this->assertDatabaseHas('customers', ['id' => $demoCustomer->id]);

        $this->assertSame(0, Artisan::call('whatsapp:cleanup-demo', ['--confirm' => 'EXCLUIR']));
        $this->assertDatabaseMissing('customers', ['id' => $demoCustomer->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $demoConversation->id]);
        $this->assertDatabaseHas('customers', ['id' => $realCustomer->id]);
        $this->assertDatabaseHas('users', ['id' => $localAdmin->id]);
    }

    public function test_inbound_whatsapp_reuses_existing_customer_without_overwriting_manual_name(): void
    {
        $company = $this->prepareWhatsApp();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Nome Corrigido',
            'phone' => '15550100001',
            'whatsapp_id' => '15550100001',
            'source_channel' => 'manual',
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->textPayload('wamid.reuse-customer'))->assertOk();
        $event = WhatsAppWebhookEvent::query()->firstOrFail();
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

        $customer->refresh();

        $this->assertSame('Nome Corrigido', $customer->name);
        $this->assertSame('Cliente WhatsApp Sanitizado', $customer->whatsapp_profile_name);
        $this->assertNotNull($customer->last_whatsapp_at);
        $this->assertSame(1, Customer::query()->where('company_id', $company->id)->where('whatsapp_id', '15550100001')->count());
    }

    public function test_image_payment_proof_creates_review_alert_without_confirming_payment(): void
    {
        $company = $this->prepareWhatsApp();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Pix',
            'phone' => '15550100001',
            'whatsapp_id' => '15550100001',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'whatsapp_identifier' => '15550100001',
            'started_at' => now(),
        ]);
        $order = $this->createActiveOrder($company, $customer, $conversation);

        $this->postJson('/api/webhooks/whatsapp', $this->imagePayload('wamid.payment-proof'))->assertOk();
        $event = WhatsAppWebhookEvent::query()->firstOrFail();
        (new ProcessWhatsAppWebhookEvent($event->id))->handle(app(WhatsAppService::class));

        $this->assertDatabaseHas('payment_proofs', [
            'order_id' => $order->id,
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'status' => PaymentProof::STATUS_RECEIVED,
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => Payment::STATUS_PROOF_RECEIVED,
        ]);
        $this->assertDatabaseMissing('payments', [
            'order_id' => $order->id,
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('conversation_alerts', [
            'conversation_id' => $conversation->id,
            'type' => ConversationAlert::TYPE_PAYMENT_PROOF_RECEIVED,
            'severity' => ConversationAlert::SEVERITY_CRITICAL,
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'ai',
            'content' => 'Recebemos seu comprovante. Vamos conferir o pagamento e avisaremos assim que ele for confirmado.',
        ]);
    }

    public function test_human_can_approve_payment_proof_once_from_conversation(): void
    {
        $company = $this->prepareWhatsApp(withRoles: true);
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Revisao',
            'phone' => '15550100002',
            'whatsapp_id' => '15550100002',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_MANUAL,
            'automation_status' => Conversation::AUTOMATION_STATUS_MANUAL_TAKEOVER,
            'whatsapp_identifier' => '15550100002',
            'started_at' => now(),
        ]);
        $order = $this->createActiveOrder($company, $customer, $conversation, totalCents: 3200);
        $payment = Payment::query()->create([
            'company_id' => $company->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'method' => Payment::METHOD_PIX,
            'provider' => Payment::PROVIDER_MANUAL,
            'status' => Payment::STATUS_PROOF_RECEIVED,
            'amount_cents' => 3200,
            'confirmed_amount_cents' => 0,
            'amount_due_after_payment_cents' => 3200,
            'currency' => 'BRL',
        ]);
        $proof = PaymentProof::query()->create([
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'status' => PaymentProof::STATUS_RECEIVED,
            'received_at' => now(),
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->postJson("/api/app/conversations/{$conversation->id}/payment-proofs/{$proof->id}/approve", [
                'confirmed_amount_cents' => 3200,
                'notes' => 'Comprovante aprovado no teste.',
            ])
            ->assertOk()
            ->assertJsonPath('data.paymentReview', null);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_CONFIRMED,
            'confirmed_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('payment_proofs', [
            'id' => $proof->id,
            'status' => PaymentProof::STATUS_ACCEPTED,
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

    public function test_meta_errors_are_classified_without_exposing_provider_message(): void
    {
        $classifier = app(WhatsAppErrorClassifier::class);
        $expired = $classifier->providerRejection(401, [
            'code' => 190,
            'error_subcode' => 463,
            'type' => 'OAuthException',
            'message' => 'sensitive provider detail',
        ]);
        $recipient = $classifier->providerRejection(400, [
            'code' => 131030,
            'type' => 'OAuthException',
            'message' => 'recipient detail',
        ]);
        $network = $classifier->networkFailure(new \RuntimeException('cURL error 60: SSL certificate problem'));

        $this->assertSame(WhatsAppErrorClassifier::TOKEN_EXPIRED, $expired['code']);
        $this->assertSame('463', $expired['safe_details']['meta_error_subcode']);
        $this->assertSame(WhatsAppErrorClassifier::RECIPIENT_NOT_ALLOWED, $recipient['code']);
        $this->assertSame(WhatsAppErrorClassifier::NETWORK_FAILURE, $network['code']);
        $this->assertSame('tls', $network['safe_details']['network_error_reason']);
        $this->assertSame('60', $network['safe_details']['network_error_code']);
        $this->assertStringNotContainsString('sensitive provider detail', json_encode($expired, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('recipient detail', json_encode($recipient, JSON_THROW_ON_ERROR));
    }

    public function test_whatsapp_diagnose_does_not_print_secrets(): void
    {
        Config::set('chatbotcrm.whatsapp.provider', 'meta_cloud');
        Config::set('chatbotcrm.whatsapp.meta.token', 'safe-test-token-never-print');
        Config::set('chatbotcrm.whatsapp.meta.phone_number_id', 'safe-phone-number-id-never-print');
        Config::set('chatbotcrm.whatsapp.meta.business_account_id', 'safe-waba-never-print');
        Config::set('chatbotcrm.whatsapp.meta.verify_token', 'safe-verify-token-never-print');

        Artisan::call('whatsapp:diagnose');
        $output = Artisan::output();

        $this->assertStringContainsString('Provider ativo', $output);
        $this->assertStringNotContainsString('safe-test-token-never-print', $output);
        $this->assertStringNotContainsString('safe-phone-number-id-never-print', $output);
        $this->assertStringNotContainsString('safe-waba-never-print', $output);
        $this->assertStringNotContainsString('safe-verify-token-never-print', $output);
    }

    private function prepareWhatsApp(bool $withRoles = false): Company
    {
        $seeders = [CompanySeeder::class, WhatsAppSeeder::class];

        if ($withRoles) {
            $seeders[] = RoleAndPermissionSeeder::class;
        }

        $this->seed($seeders);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.whatsapp.meta.app_secret', '');
        Config::set('chatbotcrm.ai.provider', 'fake');

        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(string $messageId, string $body = 'Pedido sanitizado de teste.'): array
    {
        return $this->basePayload($messageId, [
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function imagePayload(string $messageId): array
    {
        return $this->basePayload($messageId, [
            'type' => 'image',
            'image' => [
                'id' => 'fake-media-proof-id',
                'mime_type' => 'image/png',
                'sha256' => 'fake-media-checksum',
                'caption' => 'Comprovante pix teste',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(string $messageId, string $status): array
    {
        return [
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
                                'statuses' => [
                                    [
                                        'id' => $messageId,
                                        'status' => $status,
                                        'timestamp' => '1780000001',
                                        'recipient_id' => '15550100001',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $messageAttributes
     * @return array<string, mixed>
     */
    private function basePayload(string $messageId, array $messageAttributes): array
    {
        return [
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
                                    array_merge([
                                        'id' => $messageId,
                                        'from' => '15550100001',
                                        'timestamp' => '1780000000',
                                    ], $messageAttributes),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createActiveOrder(
        Company $company,
        Customer $customer,
        Conversation $conversation,
        int $totalCents = 2500,
    ): Order {
        $order = Order::query()->create([
            'company_id' => $company->id,
            'payer_customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'customer_name_snapshot' => $customer->name,
            'customer_phone_snapshot' => $customer->phone,
            'order_date' => now()->toDateString(),
            'daily_sequence' => 1,
            'code' => 'SOL-TEST-'.$conversation->id,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'origin_channel' => Order::CHANNEL_WHATSAPP,
            'entry_mode' => 'whatsapp',
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'subtotal_cents' => $totalCents,
            'total_cents' => $totalCents,
            'amount_due_cents' => $totalCents,
            'payment_status' => Payment::ORDER_STATUS_PENDING,
            'payment_method' => Payment::METHOD_PIX,
            'currency' => 'BRL',
        ]);

        $conversation->forceFill(['active_order_id' => $order->id])->save();

        return $order;
    }
}
