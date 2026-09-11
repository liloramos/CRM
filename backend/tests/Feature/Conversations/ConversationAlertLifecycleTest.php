<?php

namespace Tests\Feature\Conversations;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\Conversations\ConversationAlertService;
use App\Services\Conversations\ConversationPresenter;
use App\Services\Conversations\ConversationWorkflowService;
use App\Services\Operational\OperationalCrmPresenter;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConversationAlertLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_conversation_can_toggle_modes_without_creating_actionable_alerts(): void
    {
        [$company, $conversation, $user] = $this->context();
        $workflow = app(ConversationWorkflowService::class);

        $conversation = $workflow->switchMode($conversation, Conversation::AUTOMATION_MODE_MANUAL, $user);
        $conversation = $workflow->switchMode($conversation, Conversation::AUTOMATION_MODE_AUTOMATIC, $user);

        $this->assertSame(Conversation::AUTOMATION_MODE_AUTOMATIC, $conversation->automation_mode);
        $this->assertSame(0, ConversationAlert::query()->where('company_id', $company->id)->count());
        $this->assertSame(0, ConversationAlert::query()->where('company_id', $company->id)->currentActionable()->count());
    }

    public function test_manual_takeover_resolves_only_handoff_alerts_and_preserves_other_actionable_causes(): void
    {
        [$company, $conversation, $user] = $this->context();
        $alerts = app(ConversationAlertService::class);
        $humanRequested = $this->openAlert($alerts, $company, $conversation, ConversationAlert::TYPE_HUMAN_REQUESTED, 'human-request');
        $lowConfidence = $this->openAlert($alerts, $company, $conversation, ConversationAlert::TYPE_LOW_CONFIDENCE_AI, 'low-confidence');
        $paymentProof = $this->openAlert($alerts, $company, $conversation, ConversationAlert::TYPE_PAYMENT_PROOF_RECEIVED, 'payment-proof');
        $sendFailure = $this->openAlert($alerts, $company, $conversation, ConversationAlert::TYPE_MESSAGE_SEND_FAILED, 'send-failure');

        $this->assertTrue($conversation->fresh()->human_review_required);

        app(ConversationWorkflowService::class)->switchMode(
            $conversation,
            Conversation::AUTOMATION_MODE_MANUAL,
            $user,
            'Atendimento assumido pela equipe.',
        );

        $this->assertSame(ConversationAlert::STATUS_RESOLVED, $humanRequested->refresh()->status);
        $this->assertSame(ConversationAlert::STATUS_RESOLVED, $lowConfidence->refresh()->status);
        $this->assertSame($user->id, $humanRequested->resolved_by_user_id);
        $this->assertNotNull($lowConfidence->resolved_at);
        $this->assertSame(ConversationAlert::STATUS_OPEN, $paymentProof->refresh()->status);
        $this->assertSame(ConversationAlert::STATUS_OPEN, $sendFailure->refresh()->status);
        $this->assertSame(2, ConversationAlert::query()->where('conversation_id', $conversation->id)->currentActionable()->count());
        $this->assertSame(4, ConversationAlert::query()->where('conversation_id', $conversation->id)->count());
        $this->assertFalse($conversation->fresh()->human_review_required);
    }

    public function test_returning_to_automatic_does_not_reopen_handoff_but_a_new_real_event_can_start_a_new_cycle(): void
    {
        [$company, $conversation, $user] = $this->context();
        $alerts = app(ConversationAlertService::class);
        $alert = $this->openAlert($alerts, $company, $conversation, ConversationAlert::TYPE_LOW_CONFIDENCE_AI, 'copilot-review');

        $workflow = app(ConversationWorkflowService::class);
        $conversation = $workflow->switchMode($conversation, Conversation::AUTOMATION_MODE_MANUAL, $user);
        $conversation = $workflow->switchMode($conversation, Conversation::AUTOMATION_MODE_AUTOMATIC, $user);

        $this->assertSame(ConversationAlert::STATUS_RESOLVED, $alert->refresh()->status);
        $this->assertSame(0, ConversationAlert::query()->where('conversation_id', $conversation->id)->currentActionable()->count());
        $this->assertFalse($conversation->fresh()->human_review_required);

        $reopened = $this->openAlert($alerts, $company, $conversation, ConversationAlert::TYPE_LOW_CONFIDENCE_AI, 'copilot-review');

        $this->assertSame($alert->id, $reopened->id);
        $this->assertSame(ConversationAlert::STATUS_OPEN, $reopened->status);
        $this->assertSame(1, ConversationAlert::query()->where('conversation_id', $conversation->id)->count());
        $this->assertSame(1, ConversationAlert::query()->where('conversation_id', $conversation->id)->currentActionable()->count());
        $this->assertTrue($conversation->fresh()->human_review_required);
    }

    public function test_conversation_and_operational_snapshot_share_actionable_attention_semantics(): void
    {
        [$company, $conversation] = $this->context();
        $alerts = app(ConversationAlertService::class);
        $alert = $this->openAlert($alerts, $company, $conversation, ConversationAlert::TYPE_LOW_CONFIDENCE_AI, 'projection-review');
        $conversation->forceFill(['human_review_required' => false])->save();

        $conversationProjection = app(ConversationPresenter::class)->conversation($conversation->fresh());
        $snapshotProjection = $this->snapshotConversation($company, $conversation);

        $this->assertSame('ATTENTION', data_get($conversationProjection, 'operationalStatus.code'));
        $this->assertSame('ATTENTION', data_get($snapshotProjection, 'operationalStatus.code'));
        $this->assertSame(1, $conversationProjection['actionableAlertCount']);
        $this->assertSame(1, $snapshotProjection['actionableAlertCount']);
        $this->assertSame('ia', $snapshotProjection['mode']);
        $this->actingAs(User::query()->where('company_id', $company->id)->firstOrFail())
            ->getJson('/api/app/conversations?mode=attention')
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations');

        $alerts->resolve($alert);

        $conversationProjection = app(ConversationPresenter::class)->conversation($conversation->fresh());
        $snapshotProjection = $this->snapshotConversation($company, $conversation);

        $this->assertSame('IDLE', data_get($conversationProjection, 'operationalStatus.code'));
        $this->assertSame('IDLE', data_get($snapshotProjection, 'operationalStatus.code'));
        $this->assertSame(0, $conversationProjection['actionableAlertCount']);
        $this->assertSame(0, $snapshotProjection['actionableAlertCount']);
        $this->assertSame(ConversationAlert::STATUS_RESOLVED, $alert->refresh()->status);
        $this->assertSame(1, ConversationAlert::query()->where('conversation_id', $conversation->id)->count());
        $this->getJson('/api/app/conversations?mode=attention')
            ->assertOk()
            ->assertJsonCount(0, 'data.conversations');
    }

    public function test_unresolved_send_failure_remains_actionable_across_days_and_manual_takeover(): void
    {
        [$company, $conversation, $user] = $this->context();
        $alert = $this->openAlert(
            app(ConversationAlertService::class),
            $company,
            $conversation,
            ConversationAlert::TYPE_MESSAGE_SEND_FAILED,
            'provider-failure',
        );
        $alert->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();

        app(ConversationWorkflowService::class)->switchMode($conversation, Conversation::AUTOMATION_MODE_MANUAL, $user);

        $this->assertSame(ConversationAlert::STATUS_OPEN, $alert->refresh()->status);
        $this->assertTrue($alert->isCurrentActionable());
        $this->assertSame('ATTENTION', data_get(
            app(ConversationPresenter::class)->conversation($conversation->fresh()),
            'operationalStatus.code',
        ));
    }

    /** @return array{Company, Conversation, User} */
    private function context(): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        Config::set('chatbotcrm.whatsapp.demo_data_enabled', true);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        Config::set('chatbotcrm.ai.provider', 'fake');

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente lifecycle',
            'phone' => '5562999999000',
            'source_channel' => 'whatsapp',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
            'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
            'started_at' => now(),
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return [$company, $conversation, $user];
    }

    private function openAlert(
        ConversationAlertService $alerts,
        Company $company,
        Conversation $conversation,
        string $type,
        string $key,
    ): ConversationAlert {
        return $alerts->open(
            company: $company,
            type: $type,
            severity: ConversationAlert::SEVERITY_WARNING,
            title: 'Pendência operacional de teste',
            message: 'A equipe precisa verificar esta condição.',
            conversation: $conversation,
            deduplicationKey: $key.':'.$conversation->id,
        );
    }

    /** @return array<string, mixed> */
    private function snapshotConversation(Company $company, Conversation $conversation): array
    {
        return collect(app(OperationalCrmPresenter::class)->snapshot($company)['conversations'])
            ->firstWhere('id', (string) $conversation->id);
    }
}
