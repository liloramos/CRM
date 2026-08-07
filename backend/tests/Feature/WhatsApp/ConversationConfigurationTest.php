<?php

namespace Tests\Feature\WhatsApp;

use App\Contracts\Ai\AiProviderInterface;
use App\Data\Ai\AiSuggestionContext;
use App\Data\Ai\AiSuggestionResult;
use App\Models\AiAutomationSetting;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationQuickReply;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\AiAutomationService;
use App\Services\Ai\Providers\FakeAiProvider;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_replies_are_managed_per_company_without_demo_content(): void
    {
        [$company, $user] = $this->manager();
        $otherCompany = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante']);
        $otherReply = ConversationQuickReply::query()->create([
            'company_id' => $otherCompany->id,
            'title' => 'Resposta externa',
            'shortcut' => 'saudacao',
            'body' => 'Conteúdo de outra empresa.',
            'category' => ConversationQuickReply::CATEGORY_GREETING,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $response = $this->actingAs($user)->postJson('/api/app/conversation-quick-replies', [
            'title' => 'Saudação da equipe',
            'shortcut' => '/Saudação',
            'body' => 'Olá! Como podemos ajudar?',
            'category' => ConversationQuickReply::CATEGORY_GREETING,
            'is_active' => true,
            'display_order' => 5,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.shortcut', 'saudacao')
            ->assertJsonPath('data.isActive', true);

        $replyId = (int) $response->json('data.id');
        $this->assertDatabaseHas('conversation_quick_replies', [
            'id' => $replyId,
            'company_id' => $company->id,
            'created_by' => $user->id,
            'shortcut' => 'saudacao',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/app/conversation-quick-replies/{$replyId}", [
                'title' => 'Saudação atualizada',
                'shortcut' => 'saudacao',
                'body' => 'Olá! Em que podemos ajudar?',
                'category' => ConversationQuickReply::CATEGORY_GREETING,
                'is_active' => false,
                'display_order' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        $this->actingAs($user)
            ->getJson('/api/app/conversation-quick-replies')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)
            ->getJson('/api/app/conversation-quick-replies?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['title' => 'Resposta externa']);

        $this->actingAs($user)
            ->patchJson("/api/app/conversation-quick-replies/{$otherReply->id}", [
                'title' => 'Tentativa externa',
                'shortcut' => 'externa',
                'body' => 'Não deve atualizar.',
                'category' => ConversationQuickReply::CATEGORY_GREETING,
                'is_active' => true,
                'display_order' => 0,
            ])
            ->assertNotFound();
    }

    public function test_ai_style_and_approved_replies_are_passed_to_provider_with_payment_confirmation_enabled(): void
    {
        [$company, $user] = $this->manager();

        $this->actingAs($user)
            ->patchJson('/api/app/conversation-ai-style', [
                'establishment_name' => 'Restaurante Sol',
                'preferred_greeting' => 'Olá! Seja bem-vindo.',
                'tone' => 'warm',
                'formality' => 'balanced',
                'emoji_usage' => 'light',
                'preferred_words' => ['por favor', 'obrigado'],
                'forbidden_words' => ['pagamento confirmado'],
                'human_transfer_message' => 'Vou chamar uma atendente.',
                'payment_proof_received_message' => 'Recebemos o comprovante para revisão.',
                'closing_message' => 'Obrigada pelo pedido.',
            ])
            ->assertOk()
            ->assertJsonPath('data.establishment_name', 'Restaurante Sol');

        ConversationQuickReply::query()->create([
            'company_id' => $company->id,
            'title' => 'Atendimento humano',
            'shortcut' => 'humano',
            'body' => 'Vou chamar uma atendente.',
            'category' => ConversationQuickReply::CATEGORY_HUMAN_SUPPORT,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente de contexto',
            'phone' => '5562999990001',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'whatsapp_identifier' => '5562999990001',
            'started_at' => now(),
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'content' => 'Qual é o cardápio?',
            'type' => 'text',
            'delivery_status' => 'received',
        ]);

        $provider = new class extends FakeAiProvider
        {
            public ?AiSuggestionContext $lastContext = null;

            public function suggestReply(AiSuggestionContext $context): AiSuggestionResult
            {
                $this->lastContext = $context;

                return parent::suggestReply($context);
            }
        };
        $this->app->instance(AiProviderInterface::class, $provider);

        app(AiAutomationService::class)->suggestReply($conversation, $message, $user);

        $this->assertNotNull($provider->lastContext);
        $this->assertSame('Restaurante Sol', data_get($provider->lastContext?->settings, 'conversation_style.establishment_name'));
        $this->assertSame('humano', data_get($provider->lastContext?->settings, 'approved_quick_replies.0.shortcut'));

        $setting = AiAutomationSetting::query()
            ->where('company_id', $company->id)
            ->where('provider', 'fake')
            ->firstOrFail();
        $this->assertTrue($setting->require_human_confirmation_for_payments);
    }

    /** @return array{Company, User} */
    private function manager(): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return [$company, $user];
    }
}
