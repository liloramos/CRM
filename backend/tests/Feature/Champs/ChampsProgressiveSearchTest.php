<?php

namespace Tests\Feature\Champs;

use App\Champs\Contracts\LeadProviderInterface;
use App\Champs\Contracts\PaginatedLeadProviderInterface;
use App\Champs\DTOs\DiscoveredLead;
use App\Champs\DTOs\LeadDiscoveryPage;
use App\Champs\DTOs\LeadDiscoveryRequest;
use App\Champs\Support\ChampsSearchFingerprint;
use App\Models\ChampsLead;
use App\Models\ChampsSearch;
use App\Models\ChampsSearchResult;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChampsProgressiveSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'champs.google_places.max_results' => 20,
            'champs.google_places.max_pages' => 3,
        ]);
    }

    public function test_repeated_searches_return_progressive_unseen_companies(): void
    {
        [, $user] = $this->companyAndUser();
        $provider = $this->bindProgressiveProvider();

        $first = $this->actingAs($user)->postJson('/api/champs/searches', $this->payload());
        $second = $this->actingAs($user)->postJson('/api/champs/searches', $this->payload());
        $third = $this->actingAs($user)->postJson('/api/champs/searches', $this->payload());

        $first->assertCreated()
            ->assertJsonPath('data.total_scanned', 5)
            ->assertJsonPath('data.total_skipped_seen', 0);
        $second->assertCreated()
            ->assertJsonPath('data.total_scanned', 10)
            ->assertJsonPath('data.total_skipped_seen', 5);
        $third->assertCreated()
            ->assertJsonPath('data.total_scanned', 15)
            ->assertJsonPath('data.total_skipped_seen', 10);

        $firstIds = $this->resultExternalIds($first->json('data.results'));
        $secondIds = $this->resultExternalIds($second->json('data.results'));
        $thirdIds = $this->resultExternalIds($third->json('data.results'));

        $this->assertSame($this->ids('a', 'e'), $firstIds);
        $this->assertSame($this->ids('f', 'j'), $secondIds);
        $this->assertSame($this->ids('k', 'o'), $thirdIds);
        $this->assertCount(15, array_unique([...$firstIds, ...$secondIds, ...$thirdIds]));
        $this->assertSame(6, $provider->calls);
        $this->assertSame(15, ChampsLead::query()->count());
        $this->assertSame(15, ChampsSearchResult::query()->count());
        $this->assertSame(1, ChampsSearch::query()->distinct()->count('search_fingerprint'));
    }

    public function test_no_new_company_completes_with_zero_results_after_maximum_pages(): void
    {
        [, $user] = $this->companyAndUser();
        $provider = $this->bindProgressiveProvider();

        for ($iteration = 0; $iteration < 3; $iteration++) {
            $this->actingAs($user)->postJson('/api/champs/searches', $this->payload())->assertCreated();
        }

        $response = $this->actingAs($user)->postJson('/api/champs/searches', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.status', ChampsSearch::STATUS_COMPLETED)
            ->assertJsonPath('data.total_discovered', 0)
            ->assertJsonPath('data.total_saved', 0)
            ->assertJsonPath('data.total_scanned', 15)
            ->assertJsonPath('data.total_skipped_seen', 15)
            ->assertJsonPath('data.message', ChampsSearch::NO_NEW_RESULTS_MESSAGE)
            ->assertJsonCount(0, 'data.results');
        $this->assertSame(9, $provider->calls);
    }

    public function test_exclude_seen_false_allows_previous_companies(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProgressiveProvider();

        $first = $this->actingAs($user)->postJson('/api/champs/searches', $this->payload());
        $second = $this->actingAs($user)->postJson('/api/champs/searches', $this->payload([
            'exclude_seen' => false,
        ]));

        $first->assertCreated();
        $second->assertCreated()
            ->assertJsonPath('data.exclude_seen', false)
            ->assertJsonPath('data.total_scanned', 5)
            ->assertJsonPath('data.total_skipped_seen', 0);
        $this->assertSame(
            $this->resultExternalIds($first->json('data.results')),
            $this->resultExternalIds($second->json('data.results')),
        );
        $this->assertSame(5, ChampsLead::query()->count());
        $this->assertSame(10, ChampsSearchResult::query()->count());
    }

    public function test_seen_companies_from_another_tenant_do_not_interfere(): void
    {
        [$companyA, $userA] = $this->companyAndUser('Empresa Alfa Fictícia');
        [$companyB, $userB] = $this->companyAndUser('Empresa Beta Fictícia');
        $this->bindProgressiveProvider();

        $this->actingAs($userA)->postJson('/api/champs/searches', $this->payload())->assertCreated();
        $responseB = $this->actingAs($userB)->postJson('/api/champs/searches', $this->payload());

        $responseB->assertCreated()
            ->assertJsonPath('data.total_skipped_seen', 0);
        $this->assertSame($this->ids('a', 'e'), $this->resultExternalIds($responseB->json('data.results')));
        $this->assertSame(5, ChampsLead::query()->forCompany($companyA->id)->count());
        $this->assertSame(5, ChampsLead::query()->forCompany($companyB->id)->count());
    }

    public function test_fingerprint_normalizes_query_and_ignores_display_options(): void
    {
        $first = ChampsSearchFingerprint::make(
            '  Clínica   de Estética ',
            ' São Paulo ',
            'sp',
            'GOOGLE_PLACES',
        );
        $second = ChampsSearchFingerprint::make(
            'clinica de estetica',
            'sao paulo',
            'SP',
            'google_places',
        );

        $this->assertSame($first, $second);
        $this->assertNotSame(
            $first,
            ChampsSearchFingerprint::make('clinica de estetica', 'Rio de Janeiro', 'RJ', 'google_places'),
        );
    }

    public function test_search_can_be_archived_restored_and_remains_tenant_scoped(): void
    {
        [$companyA, $userA] = $this->companyAndUser('Empresa Arquivo Alfa');
        [, $userB] = $this->companyAndUser('Empresa Arquivo Beta');
        $this->bindProgressiveProvider();

        $searchId = $this->actingAs($userA)
            ->postJson('/api/champs/searches', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($userB)
            ->patchJson("/api/champs/searches/{$searchId}/archive")
            ->assertNotFound();
        $this->actingAs($userA)
            ->patchJson("/api/champs/searches/{$searchId}/archive")
            ->assertOk()
            ->assertJsonPath('data.id', $searchId);

        $this->assertNotNull(ChampsSearch::query()->findOrFail($searchId)->archived_at);
        $this->actingAs($userA)->getJson('/api/champs/searches')->assertJsonCount(0, 'data');
        $this->actingAs($userA)
            ->getJson('/api/champs/searches?archived=only')
            ->assertJsonCount(1, 'data');

        $this->actingAs($userA)
            ->patchJson("/api/champs/searches/{$searchId}/restore")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);
        $this->assertDatabaseHas('champs_searches', [
            'id' => $searchId,
            'company_id' => $companyA->id,
            'archived_at' => null,
        ]);
    }

    public function test_archive_all_preserves_leads_results_and_seen_memory(): void
    {
        [$companyA, $userA] = $this->companyAndUser('Empresa Memória Alfa');
        [, $userB] = $this->companyAndUser('Empresa Memória Beta');
        $this->bindProgressiveProvider([
            'first' => new LeadDiscoveryPage($this->leads($this->ids('a', 'e'))),
        ]);

        $this->actingAs($userA)->postJson('/api/champs/searches', $this->payload())->assertCreated();
        $this->actingAs($userB)->postJson('/api/champs/searches', $this->payload())->assertCreated();

        $this->actingAs($userA)
            ->postJson('/api/champs/searches/archive-all')
            ->assertOk()
            ->assertJsonPath('archived_count', 1);

        $this->assertSame(5, ChampsLead::query()->forCompany($companyA->id)->count());
        $this->assertSame(5, ChampsSearchResult::query()->forCompany($companyA->id)->count());
        $this->assertSame(1, ChampsSearch::query()->forCompany($companyA->id)->archived()->count());
        $this->assertSame(1, ChampsSearch::query()->forCompany($userB->company_id)->active()->count());

        $second = $this->actingAs($userA)->postJson('/api/champs/searches', $this->payload());
        $second->assertCreated()
            ->assertJsonPath('data.status', ChampsSearch::STATUS_COMPLETED)
            ->assertJsonPath('data.total_saved', 0)
            ->assertJsonPath('data.total_skipped_seen', 5);
    }

    public function test_legacy_search_without_fingerprint_is_backfilled_and_preserves_memory(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProgressiveProvider([
            'first' => new LeadDiscoveryPage($this->leads($this->ids('a', 'e'))),
        ]);

        $legacySearchId = $this->actingAs($user)
            ->postJson('/api/champs/searches', $this->payload())
            ->assertCreated()
            ->json('data.id');
        ChampsSearch::query()->whereKey($legacySearchId)->update(['search_fingerprint' => null]);

        $response = $this->actingAs($user)->postJson('/api/champs/searches', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.total_saved', 0)
            ->assertJsonPath('data.total_skipped_seen', 5);
        $this->assertNotNull(ChampsSearch::query()->findOrFail($legacySearchId)->search_fingerprint);
    }

    public function test_client_cannot_send_seen_external_ids(): void
    {
        [, $user] = $this->companyAndUser();
        $this->bindProgressiveProvider();

        foreach (['seen_ids', 'exclude_ids', 'external_ids'] as $field) {
            $this->actingAs($user)
                ->postJson('/api/champs/searches', $this->payload([$field => ['place-a']]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    /**
     * @param  array<string, LeadDiscoveryPage>|null  $pages
     */
    private function bindProgressiveProvider(?array $pages = null): ProgressiveFakeLeadProvider
    {
        $provider = new ProgressiveFakeLeadProvider($pages ?? [
            'first' => new LeadDiscoveryPage($this->leads($this->ids('a', 'e')), 'page-2'),
            'page-2' => new LeadDiscoveryPage($this->leads($this->ids('f', 'j')), 'page-3'),
            'page-3' => new LeadDiscoveryPage($this->leads($this->ids('k', 'o')), 'page-4'),
        ]);

        $this->app->instance(LeadProviderInterface::class, $provider);

        return $provider;
    }

    /**
     * @return array{Company, User}
     */
    private function companyAndUser(string $name = 'Empresa Progressiva Fictícia'): array
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
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Garimpagem Progressiva',
            'niche' => 'clínica de estética',
            'city' => 'São Paulo',
            'state' => 'SP',
            'limit' => 5,
            'minimum_score' => 0,
            'exclude_seen' => true,
        ], $overrides);
    }

    /**
     * @param  list<string>  $externalIds
     * @return list<DiscoveredLead>
     */
    private function leads(array $externalIds): array
    {
        return array_map(
            fn (string $externalId): DiscoveredLead => new DiscoveredLead(
                provider: 'fake_places',
                externalId: $externalId,
                name: "Empresa {$externalId}",
                formattedAddress: 'Rua Fictícia, 100 - São Paulo - SP',
                city: 'São Paulo',
                state: 'SP',
                phone: null,
                website: null,
                rating: 4.5,
                userRatingCount: 10,
                businessStatus: 'OPERATIONAL',
            ),
            $externalIds,
        );
    }

    /**
     * @return list<string>
     */
    private function ids(string $first, string $last): array
    {
        return array_map(fn (string $letter): string => "place-{$letter}", range($first, $last));
    }

    /**
     * @return list<string>
     */
    private function resultExternalIds(mixed $results): array
    {
        $this->assertIsArray($results);

        return array_map(
            fn (array $result): string => $result['lead']['external_id'],
            $results,
        );
    }
}

final class ProgressiveFakeLeadProvider implements LeadProviderInterface, PaginatedLeadProviderInterface
{
    public int $calls = 0;

    /**
     * @param  array<string, LeadDiscoveryPage>  $pages
     */
    public function __construct(private readonly array $pages) {}

    public function name(): string
    {
        return 'fake_places';
    }

    public function discover(LeadDiscoveryRequest $request): array
    {
        return $this->discoverPage($request)->leads;
    }

    public function discoverPage(
        LeadDiscoveryRequest $request,
        ?string $pageToken = null,
    ): LeadDiscoveryPage {
        $this->calls++;

        return $this->pages[$pageToken ?? 'first'] ?? new LeadDiscoveryPage([]);
    }
}
