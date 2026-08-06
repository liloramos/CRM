<?php

namespace Tests\Feature\Champs;

use App\Champs\Contracts\LeadProviderInterface;
use App\Champs\DTOs\DiscoveredLead;
use App\Champs\Exceptions\LeadProviderException;
use App\Champs\Services\ChampsLeadScoringService;
use App\Models\ChampsLead;
use App\Models\ChampsSearch;
use App\Models\ChampsSearchResult;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ChampsPersistentSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'champs.google_places.max_results' => 20,
            'champs.google_places.api_key' => 'fake-key-used-only-by-tests',
        ]);

        Http::preventStrayRequests();
    }

    public function test_unauthenticated_user_receives_401(): void
    {
        $this->postJson('/api/champs/searches', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_search_uses_the_injected_provider_without_external_http(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([$this->discoveredLead('place-provider-fake')]);

        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.provider', 'fake_places')
            ->assertJsonPath('data.status', ChampsSearch::STATUS_COMPLETED);
    }

    public function test_search_creates_persistent_history_for_the_authenticated_company(): void
    {
        [$company, $user] = $this->companyAndUser();
        $this->bindProviderResponse([$this->discoveredLead('place-history')]);

        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload(['name' => 'Busca Fictícia']))
            ->assertCreated();

        $this->assertDatabaseHas('champs_searches', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'name' => 'Busca Fictícia',
            'provider' => 'fake_places',
            'status' => ChampsSearch::STATUS_COMPLETED,
            'total_discovered' => 1,
            'total_saved' => 1,
            'total_qualified' => 1,
        ]);

        $this->actingAs($user)
            ->getJson('/api/champs/searches')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_discovered_leads_are_saved_with_source_data(): void
    {
        [$company, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-saved', ['rawData' => ['safe_source' => 'fake']]),
        ]);

        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertCreated();

        $lead = ChampsLead::query()->firstOrFail();

        $this->assertSame($company->id, $lead->company_id);
        $this->assertSame('place-saved', $lead->external_id);
        $this->assertSame(['safe_source' => 'fake'], $lead->source_data);
    }

    public function test_backend_score_is_calculated_and_persisted(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-scored', [
                'website' => 'https://empresa-ficticia.example',
                'phone' => '(11) 5555-0101',
            ]),
        ]);

        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertCreated();

        $result = ChampsSearchResult::query()->firstOrFail();

        $this->assertSame(60, $result->score);
        $this->assertSame(ChampsLeadScoringService::CLASSIFICATION_MEDIUM, $result->classification);
        $this->assertFalse($result->criteria['email']['met']);
        $this->assertNotContains('E-mail informado', $result->reasons);
    }

    public function test_repeated_place_id_is_upserted_without_duplicate_leads(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderSequence([
            [$this->discoveredLead('place-upsert', ['name' => 'Empresa Inicial Fictícia'])],
            [$this->discoveredLead('place-upsert', [
                'name' => 'Empresa Atualizada Fictícia',
                'phone' => '(11) 5555-0102',
            ])],
        ]);

        $this->actingAs($user)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();
        $this->actingAs($user)->postJson('/api/champs/searches', $this->validPayload([
            'exclude_seen' => false,
        ]))->assertCreated();

        $this->assertSame(1, ChampsLead::query()->count());
        $this->assertSame(2, ChampsSearchResult::query()->count());
        $this->assertDatabaseHas('champs_leads', [
            'external_id' => 'place-upsert',
            'name' => 'Empresa Atualizada Fictícia',
            'phone' => '(11) 5555-0102',
        ]);
    }

    public function test_same_provider_external_id_does_not_collide_between_tenants(): void
    {
        [$companyA, $userA] = $this->companyAndUser('Empresa Alfa Fictícia');
        [$companyB, $userB] = $this->companyAndUser('Empresa Beta Fictícia');
        $this->bindProviderSequence([
            [$this->discoveredLead('shared-place-id')],
            [$this->discoveredLead('shared-place-id')],
        ]);

        $this->actingAs($userA)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();
        $this->actingAs($userB)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();

        $this->assertSame(2, ChampsLead::query()->where('external_id', 'shared-place-id')->count());
        $this->assertDatabaseHas('champs_leads', [
            'company_id' => $companyA->id,
            'external_id' => 'shared-place-id',
        ]);
        $this->assertDatabaseHas('champs_leads', [
            'company_id' => $companyB->id,
            'external_id' => 'shared-place-id',
        ]);
    }

    public function test_tenant_cannot_list_another_tenants_leads(): void
    {
        [, $userA] = $this->companyAndUser('Empresa Alfa Fictícia');
        [, $userB] = $this->companyAndUser('Empresa Beta Fictícia');
        $this->bindProviderResponse([
            $this->discoveredLead('tenant-b-place', ['name' => 'Lead Exclusivo Beta']),
        ]);

        $this->actingAs($userB)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();

        $this->actingAs($userA)
            ->getJson('/api/champs/leads?qualified=true')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['external_id' => 'tenant-b-place']);
    }

    public function test_tenant_cannot_open_another_tenants_search(): void
    {
        [, $userA] = $this->companyAndUser('Empresa Alfa Fictícia');
        [, $userB] = $this->companyAndUser('Empresa Beta Fictícia');
        $this->bindProviderResponse([$this->discoveredLead('tenant-search-place')]);

        $searchId = $this->actingAs($userB)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($userA)
            ->getJson("/api/champs/searches/{$searchId}")
            ->assertNotFound();
    }

    public function test_leads_can_be_filtered_by_state(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-sp', ['name' => 'Empresa Paulista Fictícia', 'state' => 'SP']),
            $this->discoveredLead('place-rj', ['name' => 'Empresa Carioca Fictícia', 'state' => 'RJ']),
        ]);

        $this->actingAs($user)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/champs/leads?state=RJ')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'place-rj');
    }

    public function test_leads_can_be_filtered_by_search_id(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderSequence([
            [$this->discoveredLead('place-first-search')],
            [$this->discoveredLead('place-second-search')],
        ]);

        $firstSearchId = $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertCreated();

        $this->actingAs($user)
            ->getJson("/api/champs/leads?search_id={$firstSearchId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'place-first-search');
    }

    public function test_search_preserves_all_results_and_marks_only_leads_above_the_cutoff_as_qualified(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-below-cutoff-low', ['state' => 'MG']),
            $this->discoveredLead('place-below-cutoff-near', ['state' => 'SP']),
            $this->discoveredLead('place-above-cutoff', [
                'state' => 'SP',
                'website' => 'https://cutoff-ficticia.example',
                'phone' => '(11) 5555-0199',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload(['minimum_score' => 50]))
            ->assertCreated()
            ->assertJsonPath('data.total_discovered', 3)
            ->assertJsonPath('data.total_saved', 3)
            ->assertJsonPath('data.total_qualified', 1)
            ->assertJsonCount(3, 'data.results')
            ->assertJsonPath('data.results.0.qualified', false)
            ->assertJsonPath('data.results.1.qualified', false)
            ->assertJsonPath('data.results.2.qualified', true);

        $searchId = $response->json('data.id');

        $this->assertSame(3, ChampsLead::query()->count());
        $this->assertSame(3, ChampsSearchResult::query()->count());
        $this->assertSame(1, ChampsSearchResult::query()->where('qualified', true)->count());
        $this->assertSame(2, ChampsSearchResult::query()->where('qualified', false)->count());

        $this->actingAs($user)
            ->getJson("/api/champs/leads?search_id={$searchId}&qualified=true")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'place-above-cutoff')
            ->assertJsonPath('data.0.qualified', true);

        $this->actingAs($user)
            ->getJson("/api/champs/leads?search_id={$searchId}&qualified=false")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.qualified', false)
            ->assertJsonPath('data.1.qualified', false);
    }

    public function test_leads_can_be_filtered_by_minimum_score(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-low', ['state' => 'MG']),
            $this->discoveredLead('place-medium', [
                'state' => 'SP',
                'website' => 'https://medium-ficticia.example',
            ]),
        ]);

        $this->actingAs($user)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/champs/leads?minimum_score=40')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'place-medium');
    }

    public function test_leads_can_be_filtered_by_classification(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-low-class', ['state' => 'MG']),
            $this->discoveredLead('place-medium-class', [
                'state' => 'SP',
                'website' => 'https://classificacao-ficticia.example',
            ]),
        ]);

        $this->actingAs($user)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();

        $query = http_build_query([
            'classification' => ChampsLeadScoringService::CLASSIFICATION_MEDIUM,
        ]);

        $this->actingAs($user)
            ->getJson('/api/champs/leads?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'place-medium-class');
    }

    public function test_leads_support_text_search(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-horizonte', ['name' => 'Clínica Horizonte Fictícia']),
            $this->discoveredLead('place-aurora', ['name' => 'Clínica Aurora Fictícia']),
        ]);

        $this->actingAs($user)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/champs/leads?query=Horizonte')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'place-horizonte');
    }

    public function test_provider_email_is_persisted_exposed_searchable_and_scored(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-with-email', [
                'state' => 'MG',
                'email' => 'contato@empresa-ficticia.example',
            ]),
            $this->discoveredLead('place-without-email', ['state' => 'MG']),
        ]);

        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertCreated();

        $withEmail = ChampsLead::query()->where('external_id', 'place-with-email')->firstOrFail();
        $withoutEmail = ChampsLead::query()->where('external_id', 'place-without-email')->firstOrFail();

        $this->assertSame('contato@empresa-ficticia.example', $withEmail->email);
        $this->assertSame(20, $withEmail->searchResults()->firstOrFail()->score);
        $this->assertSame(10, $withoutEmail->searchResults()->firstOrFail()->score);

        $this->actingAs($user)
            ->getJson('/api/champs/leads?query=contato%40empresa-ficticia.example')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'contato@empresa-ficticia.example');
    }

    public function test_leads_can_be_ordered_by_score(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-score-low', ['state' => 'MG']),
            $this->discoveredLead('place-score-high', [
                'state' => 'SP',
                'website' => 'https://score-ficticia.example',
                'phone' => '(11) 5555-0103',
            ]),
        ]);

        $this->actingAs($user)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/champs/leads?order_by=score&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.external_id', 'place-score-high')
            ->assertJsonPath('data.1.external_id', 'place-score-low');
    }

    public function test_leads_are_paginated(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-page-1'),
            $this->discoveredLead('place-page-2'),
            $this->discoveredLead('place-page-3'),
        ]);

        $this->actingAs($user)->postJson('/api/champs/searches', $this->validPayload())->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/champs/leads?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_provider_failure_marks_search_as_failed(): void
    {
        [$company, $user] = $this->companyAndUser();
        $this->bindProviderException(LeadProviderException::rateLimited());

        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'Google Places rate limit was reached. Try again later.');

        $this->assertDatabaseHas('champs_searches', [
            'company_id' => $company->id,
            'status' => ChampsSearch::STATUS_FAILED,
            'error_message' => 'Google Places rate limit was reached. Try again later.',
        ]);
    }

    public function test_failure_message_never_contains_the_configured_key(): void
    {
        $secret = 'secret-key-that-must-not-leak';
        config(['champs.google_places.api_key' => $secret]);
        [, $user] = $this->companyAndUser();
        $this->bindProviderException(new LeadProviderException("Provider rejected {$secret}"));

        $response = $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertStatus(502);

        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString(
            $secret,
            (string) ChampsSearch::query()->firstOrFail()->error_message,
        );
    }

    public function test_company_id_from_payload_is_rejected(): void
    {
        [, $user] = $this->companyAndUser();
        [$otherCompany] = $this->companyAndUser('Outra Empresa Fictícia');
        $this->bindProviderResponse([]);

        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload([
                'company_id' => $otherCompany->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        $this->assertSame(0, ChampsSearch::query()->count());
    }

    public function test_provider_and_credentials_from_payload_are_rejected(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([]);

        foreach (['provider', 'api_key', 'access_token'] as $field) {
            $this->actingAs($user)
                ->postJson('/api/champs/searches', $this->validPayload([
                    $field => 'untrusted-value',
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, ChampsSearch::query()->count());
    }

    public function test_search_input_validation_rejects_invalid_niche_city_state_and_limit(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([]);

        $invalidCases = [
            'niche' => ['niche' => 'x'],
            'city' => ['city' => 'x'],
            'state' => ['state' => 'SPO'],
            'limit_min' => ['limit' => 0],
            'limit_max' => ['limit' => 21],
        ];

        foreach ($invalidCases as $case => $override) {
            $field = str_starts_with($case, 'limit') ? 'limit' : $case;

            $this->actingAs($user)
                ->postJson('/api/champs/searches', $this->validPayload($override))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_search_detail_returns_results_with_leads_but_not_raw_source_data(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderResponse([
            $this->discoveredLead('place-detail-1', ['rawData' => ['private_raw' => 'hidden']]),
            $this->discoveredLead('place-detail-2'),
        ]);

        $searchId = $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $response = $this->actingAs($user)
            ->getJson("/api/champs/searches/{$searchId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.results')
            ->assertJsonPath('data.results.0.lead.external_id', 'place-detail-1');

        $this->assertArrayNotHasKey(
            'source_data',
            $response->json('data.results.0.lead'),
        );
    }

    public function test_search_history_filters_are_tenant_scoped(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProviderSequence([
            [$this->discoveredLead('place-history-sp', ['state' => 'SP'])],
            [$this->discoveredLead('place-history-rj', ['state' => 'RJ'])],
        ]);

        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload(['state' => 'SP']))
            ->assertCreated();
        $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->validPayload(['state' => 'RJ']))
            ->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/champs/searches?status=completed&state=RJ')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.state', 'RJ');
    }

    /**
     * @return array{Company, User}
     */
    private function companyAndUser(string $name = 'Empresa Champs Fictícia'): array
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);

        return [$company, $user];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Garimpagem Fictícia',
            'niche' => 'clínica de estética',
            'city' => 'São Paulo',
            'state' => 'SP',
            'limit' => 5,
            'minimum_score' => 0,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function discoveredLead(string $externalId, array $overrides = []): DiscoveredLead
    {
        $attributes = array_replace([
            'provider' => 'fake_places',
            'externalId' => $externalId,
            'name' => 'Empresa Descoberta Fictícia',
            'formattedAddress' => 'Rua Exemplo, 100 - São Paulo - SP',
            'city' => 'São Paulo',
            'state' => 'SP',
            'phone' => null,
            'website' => null,
            'rating' => 4.5,
            'userRatingCount' => 10,
            'businessStatus' => 'OPERATIONAL',
            'rawData' => ['source' => 'fake_test_provider'],
        ], $overrides);

        return new DiscoveredLead(...$attributes);
    }

    /**
     * @param  list<DiscoveredLead>  $leads
     */
    private function bindProviderResponse(array $leads): void
    {
        $this->bindProviderSequence([$leads]);
    }

    /**
     * @param  list<list<DiscoveredLead>>  $responses
     */
    private function bindProviderSequence(array $responses): void
    {
        $provider = Mockery::mock(LeadProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('fake_places');
        $provider->shouldReceive('discover')->andReturn(...$responses);

        $this->app->instance(LeadProviderInterface::class, $provider);
    }

    private function bindProviderException(LeadProviderException $exception): void
    {
        $provider = Mockery::mock(LeadProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('fake_places');
        $provider->shouldReceive('discover')->andThrow($exception);

        $this->app->instance(LeadProviderInterface::class, $provider);
    }
}
