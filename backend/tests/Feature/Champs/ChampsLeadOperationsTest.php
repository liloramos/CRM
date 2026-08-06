<?php

namespace Tests\Feature\Champs;

use App\Champs\Enums\ChampsLeadActivityType;
use App\Champs\Enums\ChampsLeadPriority;
use App\Champs\Enums\ChampsLeadStage;
use App\Models\ChampsLead;
use App\Models\ChampsSearch;
use App\Models\ChampsSearchResult;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChampsLeadOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_saved_leads_or_mutate_a_lead(): void
    {
        [$company] = $this->companyAndUser();
        $lead = $this->lead($company);

        $this->getJson('/api/champs/saved-leads')->assertUnauthorized();
        $this->patchJson("/api/champs/leads/{$lead->id}/favorite", ['is_favorite' => true])
            ->assertUnauthorized();
    }

    public function test_a_lead_can_be_favorited_and_unfavorited_with_activity_history(): void
    {
        [$company, $user] = $this->companyAndUser();
        $lead = $this->lead($company);

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/favorite", ['is_favorite' => true])
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true);
        $this->assertDatabaseHas('champs_leads', ['id' => $lead->id, 'is_favorite' => true]);
        $this->assertDatabaseHas('champs_lead_activities', [
            'lead_id' => $lead->id,
            'type' => ChampsLeadActivityType::FavoriteAdded->value,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/favorite", ['is_favorite' => false])
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);
        $this->assertDatabaseHas('champs_lead_activities', [
            'lead_id' => $lead->id,
            'type' => ChampsLeadActivityType::FavoriteRemoved->value,
        ]);
    }

    public function test_pipeline_stage_and_priority_are_updated_and_validated(): void
    {
        [$company, $user] = $this->companyAndUser();
        $lead = $this->lead($company);

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/pipeline", [
                'pipeline_stage' => ChampsLeadStage::Interested->value,
                'priority' => ChampsLeadPriority::High->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.pipeline_stage', ChampsLeadStage::Interested->value)
            ->assertJsonPath('data.priority', ChampsLeadPriority::High->value);
        $this->assertDatabaseHas('champs_lead_activities', [
            'lead_id' => $lead->id,
            'type' => ChampsLeadActivityType::StageChanged->value,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/pipeline", ['pipeline_stage' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pipeline_stage');
        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/pipeline", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pipeline_stage');
    }

    public function test_assignment_is_limited_to_users_from_the_same_company(): void
    {
        [$company, $user] = $this->companyAndUser();
        $lead = $this->lead($company);
        $assignee = User::factory()->create(['company_id' => $company->id]);
        [, $otherTenantUser] = $this->companyAndUser('Empresa Externa Fictícia');

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/assignment", ['assigned_user_id' => $assignee->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_user.id', $assignee->id)
            ->assertJsonPath('data.assigned_user.name', $assignee->name);
        $this->assertDatabaseHas('champs_lead_activities', [
            'lead_id' => $lead->id,
            'type' => ChampsLeadActivityType::Assigned->value,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/assignment", ['assigned_user_id' => $otherTenantUser->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_user_id');
    }

    public function test_notes_contact_and_follow_up_create_operational_history(): void
    {
        [$company, $user] = $this->companyAndUser();
        $lead = $this->lead($company);
        $contactedAt = now()->subHour()->toIso8601String();
        $followUpAt = now()->addDay()->toIso8601String();

        $this->actingAs($user)
            ->postJson("/api/champs/leads/{$lead->id}/activities", [
                'type' => ChampsLeadActivityType::Note->value,
                'description' => 'Retornar na próxima semana para validar disponibilidade.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', ChampsLeadActivityType::Note->value);
        $this->assertDatabaseHas('champs_leads', [
            'id' => $lead->id,
            'commercial_notes' => 'Retornar na próxima semana para validar disponibilidade.',
        ]);

        $this->actingAs($user)
            ->postJson("/api/champs/leads/{$lead->id}/activities", [
                'type' => ChampsLeadActivityType::Contacted->value,
                'description' => 'Mensagem inicial enviada.',
                'occurred_at' => $contactedAt,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', ChampsLeadActivityType::Contacted->value);
        $this->assertNotNull($lead->refresh()->last_contacted_at);

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/follow-up", ['next_follow_up_at' => $followUpAt])
            ->assertOk()
            ->assertJsonPath('data.next_follow_up_at', $followUpAt);
        $this->assertDatabaseHas('champs_lead_activities', [
            'lead_id' => $lead->id,
            'type' => ChampsLeadActivityType::FollowUpScheduled->value,
        ]);

        $this->actingAs($user)
            ->getJson("/api/champs/leads/{$lead->id}/activities")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_saved_leads_filters_summary_and_scores_are_scoped_to_operational_leads(): void
    {
        [$company, $user] = $this->companyAndUser();
        $interested = $this->lead($company, [
            'is_favorite' => true,
            'pipeline_stage' => ChampsLeadStage::Interested->value,
            'priority' => ChampsLeadPriority::High->value,
            'website' => 'https://interessada.example',
            'instagram_username' => 'interessada_ficticia',
        ]);
        $contacted = $this->lead($company, [
            'pipeline_stage' => ChampsLeadStage::Contacted->value,
            'next_follow_up_at' => now()->subHour(),
        ]);
        $this->lead($company, ['name' => 'Lead Novo Fora da Central']);
        $this->addResult($interested, 82);
        $this->addResult($contacted, 48);

        $this->actingAs($user)
            ->getJson('/api/champs/saved-leads')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $response = $this->actingAs($user)->getJson(
            '/api/champs/saved-leads?pipeline_stage=interested&priority=high&minimum_score=70&has_instagram=true&has_website=true',
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $interested->id)
            ->assertJsonPath('data.0.score', 82)
            ->assertJsonPath('summary.saved', 2)
            ->assertJsonPath('summary.interested', 1)
            ->assertJsonPath('summary.contacted', 1)
            ->assertJsonPath('summary.overdue', 1);

        $this->actingAs($user)
            ->getJson('/api/champs/saved-leads?follow_up=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $contacted->id);
    }

    public function test_lead_archive_restore_and_archived_search_preserve_saved_leads(): void
    {
        [$company, $user] = $this->companyAndUser();
        $lead = $this->lead($company, ['is_favorite' => true]);
        $search = $this->search($company, $user);
        ChampsSearchResult::query()->create([
            'company_id' => $company->id,
            'search_id' => $search->id,
            'lead_id' => $lead->id,
            'score' => 70,
            'classification' => 'Bom potencial',
            'reasons' => [],
            'criteria' => [],
            'qualified' => true,
            'position' => 1,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/champs/searches/{$search->id}/archive")
            ->assertOk();
        $this->actingAs($user)
            ->getJson('/api/champs/saved-leads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lead->id);

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/archive")
            ->assertOk();
        $this->assertNotNull($lead->refresh()->archived_at);
        $this->actingAs($user)->getJson('/api/champs/saved-leads')->assertJsonCount(0, 'data');
        $this->actingAs($user)
            ->getJson('/api/champs/saved-leads?archived=only')
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);
    }

    public function test_another_tenant_cannot_read_or_change_saved_leads_or_activities(): void
    {
        [$companyA, $userA] = $this->companyAndUser('Empresa A Fictícia');
        [$companyB, $userB] = $this->companyAndUser('Empresa B Fictícia');
        $leadA = $this->lead($companyA, ['is_favorite' => true]);
        $leadB = $this->lead($companyB, ['is_favorite' => true]);

        $this->actingAs($userA)
            ->getJson('/api/champs/saved-leads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $leadA->id);
        $this->actingAs($userA)
            ->patchJson("/api/champs/leads/{$leadB->id}/favorite", ['is_favorite' => false])
            ->assertNotFound();
        $this->actingAs($userA)
            ->postJson("/api/champs/leads/{$leadB->id}/activities", [
                'type' => ChampsLeadActivityType::Note->value,
                'description' => 'Tentativa indevida.',
            ])
            ->assertNotFound();
        $this->actingAs($userA)
            ->getJson("/api/champs/leads/{$leadB->id}/activities")
            ->assertNotFound();
    }

    public function test_company_id_is_not_accepted_in_lead_mutations(): void
    {
        [$company, $user] = $this->companyAndUser();
        $lead = $this->lead($company);

        $this->actingAs($user)
            ->patchJson("/api/champs/leads/{$lead->id}/favorite", [
                'is_favorite' => true,
                'company_id' => 99999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    /**
     * @return array{Company, User}
     */
    private function companyAndUser(string $name = 'Empresa Operacional Fictícia'): array
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug(),
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);

        return [$company, $user];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(Company $company, array $attributes = []): ChampsLead
    {
        return ChampsLead::query()->create([
            'company_id' => $company->id,
            'provider' => 'google_places',
            'external_id' => 'place-'.str()->uuid(),
            'name' => 'Empresa Comercial Fictícia',
            'city' => 'São Paulo',
            'state' => 'SP',
            ...$attributes,
        ]);
    }

    private function addResult(ChampsLead $lead, int $score): ChampsSearchResult
    {
        $search = $this->search($lead->company, User::query()->where('company_id', $lead->company_id)->firstOrFail());

        return ChampsSearchResult::query()->create([
            'company_id' => $lead->company_id,
            'search_id' => $search->id,
            'lead_id' => $lead->id,
            'score' => $score,
            'classification' => $score >= 70 ? 'Bom potencial' : 'Potencial médio',
            'reasons' => [],
            'criteria' => [],
            'qualified' => true,
            'position' => 1,
        ]);
    }

    private function search(Company $company, User $user): ChampsSearch
    {
        return ChampsSearch::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'name' => 'Busca operacional fictícia',
            'niche' => 'clínica',
            'city' => 'São Paulo',
            'state' => 'SP',
            'requested_limit' => 20,
            'provider' => 'google_places',
            'minimum_score' => 0,
            'status' => ChampsSearch::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
