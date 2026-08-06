<?php

namespace Tests\Feature\Champs;

use App\Champs\Contracts\LeadProviderInterface;
use App\Champs\DTOs\DiscoveredLead;
use App\Champs\DTOs\LeadDiscoveryRequest;
use App\Champs\Exceptions\LeadProviderException;
use App\Champs\Providers\GooglePlacesLeadProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GooglePlacesLeadProviderTest extends TestCase
{
    private const API_KEY = 'fake-google-places-key-for-tests';

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

    public function test_it_discovers_leads_from_a_successful_response(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response($this->placesPayload(), 200),
        ]);

        $leads = $this->provider()->discover($this->request());

        $this->assertCount(1, $leads);
        $this->assertInstanceOf(DiscoveredLead::class, $leads[0]);
        $this->assertSame('google_places', $leads[0]->provider);
        $this->assertSame('place-fictitious-1', $leads[0]->externalId);
        $this->assertSame('Clínica Aurora Fictícia', $leads[0]->name);
        $this->assertSame('Rua Exemplo, 100 - São Paulo - SP', $leads[0]->formattedAddress);
        $this->assertSame('São Paulo', $leads[0]->city);
        $this->assertSame('SP', $leads[0]->state);
        $this->assertSame('(11) 5555-0100', $leads[0]->phone);
        $this->assertSame('https://aurora-ficticia.example', $leads[0]->website);
        $this->assertSame(4.8, $leads[0]->rating);
        $this->assertSame(87, $leads[0]->userRatingCount);
        $this->assertSame('OPERATIONAL', $leads[0]->businessStatus);
    }

    public function test_it_rejects_a_disabled_provider_without_sending_a_request(): void
    {
        config(['champs.google_places.enabled' => false]);
        Http::fake();

        $exception = $this->captureProviderException(fn () => $this->provider()->discover($this->request()));

        $this->assertStringContainsString('disabled', $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_it_rejects_a_missing_api_key_without_sending_a_request(): void
    {
        config(['champs.google_places.api_key' => '']);
        Http::fake();

        $exception = $this->captureProviderException(fn () => $this->provider()->discover($this->request()));

        $this->assertStringContainsString('missing', $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_it_returns_an_empty_array_for_an_empty_response(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $this->assertSame([], $this->provider()->discover($this->request()));
    }

    public function test_it_rejects_invalid_json(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response('{invalid-json', 200),
        ]);

        $exception = $this->captureProviderException(fn () => $this->provider()->discover($this->request()));

        $this->assertStringContainsString('invalid response', $exception->getMessage());
    }

    public function test_it_handles_a_403_response(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['error' => ['message' => 'Forbidden']], 403),
        ]);

        $exception = $this->captureProviderException(fn () => $this->provider()->discover($this->request()));

        $this->assertStringContainsString('credentials', $exception->getMessage());
        $this->assertStringContainsString('403', $exception->getMessage());
    }

    public function test_it_handles_rate_limiting(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['error' => ['message' => 'Too many requests']], 429),
        ]);

        $exception = $this->captureProviderException(fn () => $this->provider()->discover($this->request()));

        $this->assertStringContainsString('rate limit', $exception->getMessage());
    }

    public function test_it_handles_a_server_error(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['error' => ['message' => 'Unavailable']], 500),
        ]);

        $exception = $this->captureProviderException(fn () => $this->provider()->discover($this->request()));

        $this->assertStringContainsString('temporarily unavailable', $exception->getMessage());
        $this->assertStringContainsString('500', $exception->getMessage());
    }

    public function test_it_handles_a_connection_timeout(): void
    {
        Http::fake([
            self::ENDPOINT => Http::failedConnection('Simulated timeout'),
        ]);

        $exception = $this->captureProviderException(fn () => $this->provider()->discover($this->request()));

        $this->assertStringContainsString('timed out', $exception->getMessage());
    }

    public function test_it_deduplicates_places_by_place_id(): void
    {
        $payload = $this->placesPayload();
        $payload['places'][] = [
            'id' => 'place-fictitious-1',
            'displayName' => ['text' => 'Clínica Aurora Duplicada'],
            'formattedAddress' => 'Outro endereço fictício',
        ];

        Http::fake([
            self::ENDPOINT => Http::response($payload, 200),
        ]);

        $leads = $this->provider()->discover($this->request());

        $this->assertCount(1, $leads);
        $this->assertSame('Clínica Aurora Fictícia', $leads[0]->name);
    }

    public function test_it_respects_the_requested_limit(): void
    {
        $payload = $this->placesPayload();

        foreach ([2, 3, 4] as $index) {
            $payload['places'][] = [
                'id' => "place-fictitious-{$index}",
                'displayName' => ['text' => "Empresa Fictícia {$index}"],
                'formattedAddress' => "Rua Exemplo, {$index}00 - São Paulo - SP",
            ];
        }

        Http::fake([
            self::ENDPOINT => Http::response($payload, 200),
        ]);

        $leads = $this->provider()->discover($this->request(limit: 2));

        $this->assertCount(2, $leads);
        Http::assertSent(fn (Request $request): bool => $request->data()['pageSize'] === 2);
    }

    public function test_it_builds_a_normalized_text_query(): void
    {
        $request = new LeadDiscoveryRequest(
            niche: "  clínica\n  de estética ",
            city: '  São   Paulo ',
            state: ' SP ',
            limit: 5,
        );

        $this->assertSame('clínica de estética em São Paulo SP', $request->textQuery());
    }

    public function test_it_sends_only_the_expected_headers_fields_and_payload(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['places' => []], 200),
        ]);

        $this->provider()->discover($this->request());

        Http::assertSent(function (Request $request): bool {
            $fieldMask = implode(',', GooglePlacesLeadProvider::FIELD_MASK);

            return $request->url() === self::ENDPOINT
                && ! str_contains($request->url(), self::API_KEY)
                && $request->hasHeader('X-Goog-Api-Key', self::API_KEY)
                && $request->hasHeader('X-Goog-FieldMask', $fieldMask)
                && ! str_contains($fieldMask, '*')
                && $request->data() === [
                    'textQuery' => 'clínica de estética em São Paulo SP',
                    'pageSize' => 5,
                    'languageCode' => 'pt-BR',
                    'regionCode' => 'BR',
                ];
        });
    }

    public function test_it_preserves_query_parameters_when_requesting_the_next_page(): void
    {
        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push([
                    'places' => $this->placesForIds(['place-page-1']),
                    'nextPageToken' => 'page-token-2',
                ])
                ->push([
                    'places' => $this->placesForIds([
                        'place-page-2',
                        'place-page-3',
                        'place-page-4',
                        'place-page-5',
                    ]),
                ]),
        ]);

        $leads = $this->provider()->discover($this->request());

        $this->assertCount(5, $leads);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            return $request->data() === [
                'textQuery' => 'clínica de estética em São Paulo SP',
                'pageSize' => 5,
                'languageCode' => 'pt-BR',
                'regionCode' => 'BR',
                'pageToken' => 'page-token-2',
            ];
        });
    }

    public function test_pagination_stops_when_the_requested_quantity_is_reached(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'places' => $this->placesForIds([
                    'place-stop-1',
                    'place-stop-2',
                    'place-stop-3',
                    'place-stop-4',
                    'place-stop-5',
                ]),
                'nextPageToken' => 'unused-page-token',
            ]),
        ]);

        $this->assertCount(5, $this->provider()->discover($this->request()));
        Http::assertSentCount(1);
    }

    public function test_pagination_stops_without_a_next_page_token(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'places' => $this->placesForIds(['place-only-page']),
            ]),
        ]);

        $this->assertCount(1, $this->provider()->discover($this->request()));
        Http::assertSentCount(1);
    }

    public function test_pagination_respects_the_configured_maximum_pages(): void
    {
        config(['champs.google_places.max_pages' => 3]);
        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push([
                    'places' => $this->placesForIds(['place-max-1']),
                    'nextPageToken' => 'page-2',
                ])
                ->push([
                    'places' => $this->placesForIds(['place-max-2']),
                    'nextPageToken' => 'page-3',
                ])
                ->push([
                    'places' => $this->placesForIds(['place-max-3']),
                    'nextPageToken' => 'page-4',
                ]),
        ]);

        $leads = $this->provider()->discover($this->request());

        $this->assertCount(3, $leads);
        Http::assertSentCount(3);
    }

    public function test_api_key_is_absent_from_exceptions_and_logs(): void
    {
        $secret = 'secret-value-that-must-never-leak';
        config(['champs.google_places.api_key' => $secret]);
        Log::spy();

        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => ['message' => "Rejected credential {$secret}"],
            ], 403),
        ]);

        $exception = $this->captureProviderException(fn () => $this->provider()->discover($this->request()));

        $this->assertStringNotContainsString($secret, $exception->getMessage());
        Log::shouldNotHaveReceived('emergency');
        Log::shouldNotHaveReceived('alert');
        Log::shouldNotHaveReceived('critical');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('notice');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    private function provider(): LeadProviderInterface
    {
        return app(LeadProviderInterface::class);
    }

    private function request(int $limit = 5): LeadDiscoveryRequest
    {
        return new LeadDiscoveryRequest(
            niche: 'clínica de estética',
            city: 'São Paulo',
            state: 'SP',
            limit: $limit,
        );
    }

    /**
     * @return array{places: list<array<string, mixed>>}
     */
    private function placesPayload(): array
    {
        return [
            'places' => [
                [
                    'id' => 'place-fictitious-1',
                    'displayName' => ['text' => 'Clínica Aurora Fictícia'],
                    'formattedAddress' => 'Rua Exemplo, 100 - São Paulo - SP',
                    'businessStatus' => 'OPERATIONAL',
                    'websiteUri' => 'https://aurora-ficticia.example',
                    'nationalPhoneNumber' => '(11) 5555-0100',
                    'rating' => 4.8,
                    'userRatingCount' => 87,
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function placesForIds(array $ids): array
    {
        return array_map(
            fn (string $id): array => [
                'id' => $id,
                'displayName' => ['text' => "Empresa {$id}"],
                'formattedAddress' => 'Rua Fictícia, 100 - São Paulo - SP',
            ],
            $ids,
        );
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function captureProviderException(callable $callback): LeadProviderException
    {
        try {
            $callback();
        } catch (LeadProviderException $exception) {
            return $exception;
        }

        $this->fail('Expected a LeadProviderException to be thrown.');
    }
}
