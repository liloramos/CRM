<?php

namespace Tests\Feature\Champs;

use App\Models\ChampsSearch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChampsGooglePlacesSearchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'fake-integration-key-for-tests';

    private const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'champs.google_places.enabled' => true,
            'champs.google_places.api_key' => self::API_KEY,
            'champs.google_places.base_url' => 'https://places.googleapis.com/v1',
            'champs.google_places.language' => 'pt-BR',
            'champs.google_places.region' => 'BR',
            'champs.google_places.timeout' => 15,
            'champs.google_places.max_results' => 20,
        ]);

        Http::preventStrayRequests();
    }

    public function test_authenticated_post_persists_a_google_places_response_end_to_end(): void
    {
        $company = Company::query()->create([
            'name' => 'Empresa Integração Fictícia',
            'slug' => 'empresa-integracao-'.Str::lower(Str::random(8)),
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);

        Http::fake([
            self::ENDPOINT => Http::response([
                'places' => [[
                    'id' => 'place-integration-fictitious',
                    'displayName' => ['text' => 'Clínica Integração Fictícia'],
                    'formattedAddress' => 'Rua Exemplo, 100 - São Paulo - SP',
                    'businessStatus' => 'OPERATIONAL',
                    'websiteUri' => 'https://integracao-ficticia.example',
                    'nationalPhoneNumber' => '(11) 5555-0100',
                    'rating' => 4.8,
                    'userRatingCount' => 120,
                ]],
            ], 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/champs/searches', [
                'name' => 'Integração Google Places',
                'niche' => 'clínica de estética',
                'city' => 'São Paulo',
                'state' => 'SP',
                'limit' => 1,
                'minimum_score' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', ChampsSearch::STATUS_COMPLETED)
            ->assertJsonPath('data.provider', 'google_places')
            ->assertJsonPath('data.total_discovered', 1)
            ->assertJsonPath('data.total_saved', 1)
            ->assertJsonPath('data.total_qualified', 1)
            ->assertJsonPath('data.results.0.lead.external_id', 'place-integration-fictitious')
            ->assertJsonPath('data.results.0.lead.name', 'Clínica Integração Fictícia');

        $this->assertDatabaseHas('champs_searches', [
            'company_id' => $company->id,
            'status' => ChampsSearch::STATUS_COMPLETED,
            'total_discovered' => 1,
            'total_saved' => 1,
            'total_qualified' => 1,
        ]);
        $this->assertDatabaseHas('champs_leads', [
            'company_id' => $company->id,
            'provider' => 'google_places',
            'external_id' => 'place-integration-fictitious',
        ]);
        $this->assertDatabaseHas('champs_search_results', [
            'company_id' => $company->id,
            'score' => 60,
            'qualified' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT
            && ! str_contains($request->url(), self::API_KEY));
    }
}
