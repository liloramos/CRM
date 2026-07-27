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
use App\Services\WhatsApp\WhatsAppService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
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

    private function prepareWhatsApp(bool $withRoles = false): Company
    {
        $seeders = [CompanySeeder::class, WhatsAppSeeder::class];

        if ($withRoles) {
            $seeders[] = RoleAndPermissionSeeder::class;
        }

        $this->seed($seeders);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
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
