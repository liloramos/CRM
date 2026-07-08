<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\AiProviderInterface;
use App\Models\AiResponseSuggestion;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAutomationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_recurring_order_generates_confirmation_question_and_requires_human_review(): void
    {
        Config::set('chatbotcrm.ai.provider', 'fake');

        [$company, $conversation, $message] = $this->createConversationWithMessage('Quero igual ontem.');
        $automation = app(AiAutomationService::class);

        $suggestion = $automation->suggestReply($conversation, $message);

        $this->assertSame('fake', $suggestion->provider);
        $this->assertSame(AiResponseSuggestion::TYPE_CONFIRMATION_QUESTION, $suggestion->suggestion_type);
        $this->assertSame(AiResponseSuggestion::STATUS_REQUIRES_HUMAN_CONFIRMATION, $suggestion->status);
        $this->assertTrue($suggestion->requires_human_confirmation);
        $this->assertSame('recurring_order_requires_history_confirmation', $suggestion->ambiguity_reason);
        $this->assertStringContainsString('repetir o ultimo pedido', $suggestion->suggested_text);
        $this->assertTrue($conversation->refresh()->human_review_required);
        $this->assertDatabaseHas('automation_events', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'ai_response_suggestion_id' => $suggestion->id,
            'event_type' => AutomationEvent::TYPE_AI_SUGGESTION_CREATED,
            'status' => AutomationEvent::STATUS_RECORDED,
            'requires_human_confirmation' => true,
        ]);
    }

    public function test_manual_fallback_switches_conversation_mode_and_records_event(): void
    {
        Config::set('chatbotcrm.ai.provider', 'fake');

        [$company, $conversation] = $this->createConversationWithMessage('Mensagem incompleta para atendimento manual.');
        $user = User::factory()->create(['company_id' => $company->id]);
        $automation = app(AiAutomationService::class);

        $conversation = $automation->fallbackToHuman(
            conversation: $conversation,
            reason: 'Cliente enviou informacoes incompletas.',
            user: $user,
            metadata: ['source' => 'feature_test'],
        );

        $this->assertSame(Conversation::AUTOMATION_MODE_MANUAL, $conversation->automation_mode);
        $this->assertSame(Conversation::AUTOMATION_STATUS_FALLBACK_REQUIRED, $conversation->automation_status);
        $this->assertTrue($conversation->human_review_required);
        $this->assertSame($user->id, $conversation->manual_takeover_by_user_id);
        $this->assertDatabaseHas('automation_events', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'created_by_user_id' => $user->id,
            'event_type' => AutomationEvent::TYPE_MANUAL_TAKEOVER,
            'status' => AutomationEvent::STATUS_RECORDED,
            'requires_human_confirmation' => true,
        ]);
    }

    public function test_n8n_provider_without_configuration_does_not_call_external_webhook(): void
    {
        Config::set('chatbotcrm.ai.provider', 'n8n');
        Config::set('chatbotcrm.integrations.n8n.webhook_base_url', null);
        Config::set('chatbotcrm.ai.n8n.webhook_path', null);
        Http::fake();

        [, $conversation, $message] = $this->createConversationWithMessage('Pedido de teste sanitizado.');
        $provider = app(AiProviderInterface::class);
        $automation = app(AiAutomationService::class);

        $status = $provider->connectionStatus()->toArray();
        $suggestion = $automation->suggestReply($conversation, $message);

        $this->assertSame('n8n', $provider->name());
        $this->assertFalse($provider->isConfigured());
        $this->assertSame('missing_configuration', $status['status']);
        $this->assertSame(AiResponseSuggestion::STATUS_REQUIRES_HUMAN_CONFIRMATION, $suggestion->status);
        $this->assertTrue($suggestion->requires_human_confirmation);
        $this->assertSame('n8n_missing_configuration', $suggestion->ambiguity_reason);
        Http::assertNothingSent();
    }

    /**
     * @return array{0: Company, 1: Conversation, 2: Message}
     */
    private function createConversationWithMessage(string $content): array
    {
        $company = Company::query()->create([
            'name' => 'Restaurante Seguro Teste',
            'slug' => 'restaurante-seguro-teste-'.uniqid(),
        ]);

        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Sanitizado',
        ]);

        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'started_at' => now(),
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'content' => $content,
            'type' => 'text',
            'received_at' => now(),
        ]);

        return [$company, $conversation, $message];
    }
}
