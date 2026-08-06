<?php

namespace Tests\Feature\Champs;

use App\Champs\Contracts\InstagramEnricherInterface;
use App\Champs\Contracts\InstagramResolverInterface;
use App\Champs\Contracts\LeadProviderInterface;
use App\Champs\DTOs\DiscoveredLead;
use App\Champs\DTOs\InstagramProfileData;
use App\Champs\DTOs\ResolvedInstagramProfile;
use App\Champs\Exceptions\InstagramEnrichmentException;
use App\Champs\Exceptions\InstagramResolutionException;
use App\Champs\Services\ChampsLeadEnrichmentService;
use App\Champs\Services\ChampsLeadScoringService;
use App\Models\ChampsLead;
use App\Models\ChampsSearch;
use App\Models\ChampsSearchResult;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ChampsLeadEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_the_resolved_username_and_preserves_google_source_data(): void
    {
        $company = $this->company('Empresa Alfa Ficticia');
        $lead = $this->lead($company, ['source_data' => ['google_place_id' => 'place-alpha']]);
        $resolver = $this->resolverReturning($this->resolvedProfile());
        $enricher = $this->unavailableEnricher();

        $enriched = $this->service($resolver, $enricher)->enrich($lead);

        $this->assertSame('empresa.ficticia', $enriched->instagram_username);
        $this->assertSame(
            'https://www.instagram.com/empresa.ficticia/',
            $enriched->instagram_profile_url,
        );
        $this->assertSame('place-alpha', $enriched->source_data['google_place_id']);
        $this->assertSame('high', $enriched->source_data['instagram_resolution']['confidence']);
        $this->assertFalse($enriched->source_data['instagram_resolution']['requires_review']);
    }

    public function test_it_updates_metrics_score_qualification_and_search_totals(): void
    {
        $company = $this->company('Empresa Beta Ficticia');
        $lead = $this->lead($company);
        $searchAt70 = $this->searchWithResult($company, $lead, minimumScore: 70);
        $searchAt85 = $this->searchWithResult($company, $lead, minimumScore: 85);
        $resolver = $this->resolverReturning($this->resolvedProfile());
        $enricher = $this->enricherReturning(new InstagramProfileData(
            enrichable: true,
            username: 'empresa.ficticia',
            id: '17890000000000000',
            followersCount: 6000,
            mediaCount: 12,
            isProfessional: true,
        ));

        $enriched = $this->service($resolver, $enricher)->enrich($lead);

        $this->assertSame(6000, $enriched->instagram_followers_count);
        $this->assertSame(12, $enriched->instagram_media_count);
        $this->assertTrue($enriched->instagram_is_professional);

        $resultAt70 = $searchAt70->results()->firstOrFail();
        $resultAt85 = $searchAt85->results()->firstOrFail();

        $this->assertSame(80, $resultAt70->score);
        $this->assertSame(ChampsLeadScoringService::CLASSIFICATION_GOOD, $resultAt70->classification);
        $this->assertTrue($resultAt70->criteria['professional_instagram']['met']);
        $this->assertTrue($resultAt70->criteria['recent_instagram_posts']['met']);
        $this->assertTrue($resultAt70->criteria['instagram_followers']['met']);
        $this->assertTrue($resultAt70->qualified);
        $this->assertFalse($resultAt85->qualified);
        $this->assertSame(1, $searchAt70->refresh()->total_qualified);
        $this->assertSame(0, $searchAt85->refresh()->total_qualified);
    }

    public function test_a_partial_meta_response_never_erases_existing_metrics(): void
    {
        $company = $this->company('Empresa Gama Ficticia');
        $lead = $this->lead($company, [
            'instagram_followers_count' => 9100,
            'instagram_media_count' => 27,
            'instagram_is_professional' => true,
        ]);
        $resolver = $this->resolverReturning($this->resolvedProfile());
        $enricher = $this->enricherReturning(new InstagramProfileData(
            enrichable: true,
            username: 'empresa.ficticia',
            id: '17890000000000000',
            followersCount: null,
            mediaCount: null,
            isProfessional: true,
        ));

        $enriched = $this->service($resolver, $enricher)->enrich($lead);

        $this->assertSame(9100, $enriched->instagram_followers_count);
        $this->assertSame(27, $enriched->instagram_media_count);
        $this->assertTrue($enriched->instagram_is_professional);
    }

    public function test_meta_failure_preserves_the_google_lead_and_resolved_username(): void
    {
        $company = $this->company('Empresa Delta Ficticia');
        $lead = $this->lead($company, [
            'rating' => 4.75,
            'user_rating_count' => 88,
            'business_status' => 'OPERATIONAL',
        ]);
        $resolver = $this->resolverReturning($this->resolvedProfile());
        $enricher = Mockery::mock(InstagramEnricherInterface::class);
        $enricher->shouldReceive('isAvailable')->once()->andReturnTrue();
        $enricher->shouldReceive('enrich')->once()
            ->andThrow(InstagramEnrichmentException::rateLimited());

        $enriched = $this->service($resolver, $enricher)->enrich($lead);

        $this->assertSame('empresa.ficticia', $enriched->instagram_username);
        $this->assertSame('Empresa Descoberta Ficticia', $enriched->name);
        $this->assertSame('4.75', $enriched->rating);
        $this->assertSame(88, $enriched->user_rating_count);
        $this->assertSame('OPERATIONAL', $enriched->business_status);
        $this->assertFalse($enriched->instagram_is_professional);
    }

    public function test_resolver_failure_is_isolated_and_does_not_change_the_lead(): void
    {
        $company = $this->company('Empresa Epsilon Ficticia');
        $lead = $this->lead($company);
        $resolver = Mockery::mock(InstagramResolverInterface::class);
        $resolver->shouldReceive('resolve')->once()
            ->andThrow(InstagramResolutionException::unsafeDestination());
        $enricher = Mockery::mock(InstagramEnricherInterface::class);
        $enricher->shouldNotReceive('isAvailable');
        $enricher->shouldNotReceive('enrich');

        $enriched = $this->service($resolver, $enricher)->enrich($lead);

        $this->assertNull($enriched->instagram_username);
        $this->assertSame('https://empresa-ficticia.example', $enriched->website);
    }

    public function test_enrichment_never_updates_results_or_totals_from_another_tenant(): void
    {
        $companyA = $this->company('Tenant Alfa Ficticio');
        $companyB = $this->company('Tenant Beta Ficticio');
        $leadA = $this->lead($companyA);
        $searchA = $this->searchWithResult($companyA, $leadA, minimumScore: 70);
        $searchB = $this->search($companyB, minimumScore: 70, totalQualified: 0);
        $foreignResult = ChampsSearchResult::query()->create([
            'company_id' => $companyB->id,
            'search_id' => $searchB->id,
            'lead_id' => $leadA->id,
            'score' => 5,
            'classification' => ChampsLeadScoringService::CLASSIFICATION_LOW,
            'reasons' => [],
            'criteria' => [],
            'qualified' => false,
            'position' => 1,
        ]);
        $resolver = $this->resolverReturning($this->resolvedProfile());
        $enricher = $this->enricherReturning(new InstagramProfileData(
            enrichable: true,
            username: 'empresa.ficticia',
            followersCount: 6000,
            mediaCount: 12,
            isProfessional: true,
        ));

        $this->service($resolver, $enricher)->enrich($leadA);

        $this->assertSame(80, $searchA->results()->firstOrFail()->score);
        $this->assertSame(5, $foreignResult->refresh()->score);
        $this->assertFalse($foreignResult->qualified);
        $this->assertSame(0, $searchB->refresh()->total_qualified);
    }

    public function test_new_searches_are_automatically_enriched_without_external_http_in_tests(): void
    {
        config(['champs.google_places.max_results' => 20]);
        $company = $this->company('Empresa Automatica Ficticia');
        $user = User::factory()->create(['company_id' => $company->id]);
        $provider = Mockery::mock(LeadProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('fake_places');
        $provider->shouldReceive('discover')->once()->andReturn([
            new DiscoveredLead(
                provider: 'fake_places',
                externalId: 'place-auto-enrichment',
                name: 'Empresa Automatica Ficticia',
                formattedAddress: 'Rua Exemplo, 100 - Sao Paulo - SP',
                city: 'Sao Paulo',
                state: 'SP',
                website: 'https://empresa-automatica.example',
                userRatingCount: 10,
            ),
        ]);
        $this->app->instance(LeadProviderInterface::class, $provider);
        $this->app->instance(
            InstagramResolverInterface::class,
            $this->resolverReturning($this->resolvedProfile()),
        );
        $this->app->instance(
            InstagramEnricherInterface::class,
            $this->enricherReturning(new InstagramProfileData(
                enrichable: true,
                username: 'empresa.ficticia',
                followersCount: 6000,
                mediaCount: 12,
                isProfessional: true,
            )),
        );

        $this->actingAs($user)
            ->postJson('/api/champs/searches', [
                'niche' => 'clinica ficticia',
                'city' => 'Sao Paulo',
                'state' => 'SP',
                'limit' => 1,
                'minimum_score' => 70,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_qualified', 1)
            ->assertJsonPath('data.results.0.score', 80)
            ->assertJsonPath('data.results.0.qualified', true)
            ->assertJsonPath('data.results.0.lead.instagram_username', 'empresa.ficticia')
            ->assertJsonPath('data.results.0.lead.instagram_followers_count', 6000);
    }

    private function service(
        InstagramResolverInterface $resolver,
        InstagramEnricherInterface $enricher,
    ): ChampsLeadEnrichmentService {
        return new ChampsLeadEnrichmentService(
            resolver: $resolver,
            enricher: $enricher,
            scoring: new ChampsLeadScoringService,
        );
    }

    private function resolverReturning(
        ?ResolvedInstagramProfile $profile,
    ): InstagramResolverInterface {
        $resolver = Mockery::mock(InstagramResolverInterface::class);
        $resolver->shouldReceive('resolve')->once()->andReturn($profile);

        return $resolver;
    }

    private function unavailableEnricher(): InstagramEnricherInterface
    {
        $enricher = Mockery::mock(InstagramEnricherInterface::class);
        $enricher->shouldReceive('isAvailable')->once()->andReturnFalse();
        $enricher->shouldNotReceive('enrich');

        return $enricher;
    }

    private function enricherReturning(
        InstagramProfileData $profile,
    ): InstagramEnricherInterface {
        $enricher = Mockery::mock(InstagramEnricherInterface::class);
        $enricher->shouldReceive('isAvailable')->once()->andReturnTrue();
        $enricher->shouldReceive('enrich')->once()->andReturn($profile);

        return $enricher;
    }

    private function resolvedProfile(): ResolvedInstagramProfile
    {
        return new ResolvedInstagramProfile(
            username: 'empresa.ficticia',
            profileUrl: 'https://www.instagram.com/empresa.ficticia/',
            confidence: ResolvedInstagramProfile::CONFIDENCE_HIGH,
            sourceUrl: 'https://empresa-ficticia.example',
            candidates: ['empresa.ficticia'],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function lead(Company $company, array $overrides = []): ChampsLead
    {
        return ChampsLead::query()->create(array_replace([
            'company_id' => $company->id,
            'provider' => 'google_places',
            'external_id' => 'place-'.Str::lower(Str::random(12)),
            'name' => 'Empresa Descoberta Ficticia',
            'formatted_address' => 'Rua Exemplo, 100 - Sao Paulo - SP',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'website' => 'https://empresa-ficticia.example',
            'user_rating_count' => 10,
        ], $overrides));
    }

    private function searchWithResult(
        Company $company,
        ChampsLead $lead,
        int $minimumScore,
    ): ChampsSearch {
        $search = $this->search($company, $minimumScore);
        $initialScore = (new ChampsLeadScoringService)->calculate($lead);

        ChampsSearchResult::query()->create([
            'company_id' => $company->id,
            'search_id' => $search->id,
            'lead_id' => $lead->id,
            'score' => $initialScore['score'],
            'classification' => $initialScore['classification'],
            'reasons' => $initialScore['reasons'],
            'criteria' => $initialScore['criteria'],
            'qualified' => $initialScore['score'] >= $minimumScore,
            'position' => 1,
        ]);

        return $search;
    }

    private function search(
        Company $company,
        int $minimumScore,
        int $totalQualified = 0,
    ): ChampsSearch {
        return ChampsSearch::query()->create([
            'company_id' => $company->id,
            'name' => 'Busca Ficticia',
            'niche' => 'clinica ficticia',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'requested_limit' => 5,
            'provider' => 'google_places',
            'minimum_score' => $minimumScore,
            'status' => ChampsSearch::STATUS_COMPLETED,
            'total_discovered' => 1,
            'total_saved' => 1,
            'total_qualified' => $totalQualified,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function company(string $name): Company
    {
        return Company::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
        ]);
    }
}
