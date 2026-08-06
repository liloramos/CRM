<?php

namespace Tests\Feature\Champs;

use App\Champs\Contracts\InstagramEnricherInterface;
use App\Champs\DTOs\InstagramProfileData;
use App\Champs\Exceptions\InstagramEnrichmentException;
use App\Champs\Providers\MetaInstagramEnricher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MetaInstagramEnricherTest extends TestCase
{
    private const ACCESS_TOKEN = 'fake-meta-access-token-for-tests';

    private const ENDPOINT = 'https://graph.facebook.com/v23.0/17841400000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'champs.meta.enabled' => true,
            'champs.meta.graph_base_url' => 'https://graph.facebook.com',
            'champs.meta.api_version' => 'v23.0',
            'champs.meta.access_token' => self::ACCESS_TOKEN,
            'champs.meta.instagram_account_id' => '17841400000000000',
            'champs.meta.timeout' => 15,
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_enriches_a_professional_account_with_business_discovery(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::response($this->successPayload(), 200),
        ]);

        $profile = $this->enricher()->enrich('Empresa.Ficticia');

        $this->assertInstanceOf(InstagramProfileData::class, $profile);
        $this->assertTrue($profile->enrichable);
        $this->assertTrue($profile->isProfessional);
        $this->assertSame('empresa.ficticia', $profile->username);
        $this->assertSame('17890000000000000', $profile->id);
        $this->assertSame('Empresa Ficticia', $profile->name);
        $this->assertSame('Biografia publica ficticia.', $profile->biography);
        $this->assertSame('https://empresa-ficticia.example', $profile->website);
        $this->assertSame(7500, $profile->followersCount);
        $this->assertSame(42, $profile->mediaCount);
        $this->assertSame('https://images.example/profile.jpg', $profile->profilePictureUrl);
    }

    public function test_it_uses_the_expected_business_discovery_shape_and_secret_header(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::response($this->successPayload(), 200),
        ]);

        $this->enricher()->enrich('empresa.ficticia');

        Http::assertSent(function (Request $request): bool {
            $expectedFields = 'business_discovery.username(empresa.ficticia)'
                .'{'.implode(',', MetaInstagramEnricher::BUSINESS_DISCOVERY_FIELDS).'}';

            return str_starts_with($request->url(), self::ENDPOINT)
                && ! str_contains($request->url(), self::ACCESS_TOKEN)
                && $request->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN)
                && $request->data()['fields'] === $expectedFields;
        });
    }

    public function test_it_rejects_a_disabled_provider_without_http(): void
    {
        config(['champs.meta.enabled' => false]);
        Http::fake();

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringContainsString('desabilitado', $exception->getMessage());
        $this->assertFalse($this->enricher()->isAvailable());
        Http::assertNothingSent();
    }

    public function test_it_rejects_a_missing_token_without_http(): void
    {
        config(['champs.meta.access_token' => '']);
        Http::fake();

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringContainsString('incompleta', $exception->getMessage());
        $this->assertFalse($this->enricher()->isAvailable());
        Http::assertNothingSent();
    }

    public function test_it_rejects_a_non_official_graph_base_url_without_http(): void
    {
        config(['champs.meta.graph_base_url' => 'https://graph-proxy.example']);
        Http::fake();

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringContainsString('incompleta', $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_it_rejects_an_invalid_username_without_http(): void
    {
        Http::fake();

        $exception = $this->captureException(
            fn () => $this->enricher()->enrich('https://instagram.com/empresa'),
        );

        $this->assertStringContainsString('invalido', $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_a_personal_or_unavailable_account_is_not_a_fatal_error(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::response([
                'error' => ['code' => 100, 'message' => 'Unsupported target account'],
            ], 400),
        ]);

        $profile = $this->enricher()->enrich('conta.pessoal');

        $this->assertFalse($profile->enrichable);
        $this->assertFalse($profile->isProfessional);
        $this->assertSame('conta.pessoal', $profile->username);
        $this->assertNotNull($profile->reason);
    }

    public function test_it_handles_a_401_response(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::response(['error' => ['code' => 190]], 401),
        ]);

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringContainsString('credenciais', $exception->getMessage());
    }

    public function test_it_handles_a_403_response(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::response(['error' => ['code' => 10]], 403),
        ]);

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringContainsString('permissao', $exception->getMessage());
    }

    public function test_it_handles_rate_limiting(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::response(['error' => ['code' => 4]], 429),
        ]);

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringContainsString('limite', $exception->getMessage());
    }

    public function test_it_handles_a_meta_server_error(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::response(['error' => ['code' => 2]], 500),
        ]);

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringContainsString('indisponivel', $exception->getMessage());
    }

    public function test_it_handles_a_connection_timeout(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::failedConnection('Simulated timeout'),
        ]);

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringContainsString('expirou', $exception->getMessage());
    }

    public function test_it_preserves_nulls_in_a_partial_response(): void
    {
        Http::fake([
            self::ENDPOINT.'*' => Http::response([
                'business_discovery' => [
                    'id' => '17890000000000000',
                    'username' => 'empresa.ficticia',
                ],
            ], 200),
        ]);

        $profile = $this->enricher()->enrich('empresa.ficticia');

        $this->assertTrue($profile->enrichable);
        $this->assertNull($profile->followersCount);
        $this->assertNull($profile->mediaCount);
        $this->assertNull($profile->name);
        $this->assertNull($profile->website);
    }

    public function test_the_token_is_absent_from_exceptions_urls_and_logs(): void
    {
        $secret = 'secret-meta-token-that-must-not-leak';
        config(['champs.meta.access_token' => $secret]);
        Log::spy();
        Http::fake([
            self::ENDPOINT.'*' => Http::response([
                'error' => ['code' => 190, 'message' => "Rejected {$secret}"],
            ], 401),
        ]);

        $exception = $this->captureException(fn () => $this->enricher()->enrich('empresa.ficticia'));

        $this->assertStringNotContainsString($secret, $exception->getMessage());
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), $secret));
        Log::shouldNotHaveReceived('emergency');
        Log::shouldNotHaveReceived('alert');
        Log::shouldNotHaveReceived('critical');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('notice');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    private function enricher(): InstagramEnricherInterface
    {
        return app(InstagramEnricherInterface::class);
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function successPayload(): array
    {
        return [
            'business_discovery' => [
                'id' => '17890000000000000',
                'username' => 'empresa.ficticia',
                'name' => 'Empresa Ficticia',
                'biography' => 'Biografia publica ficticia.',
                'website' => 'https://empresa-ficticia.example',
                'followers_count' => 7500,
                'media_count' => 42,
                'profile_picture_url' => 'https://images.example/profile.jpg',
            ],
        ];
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function captureException(callable $callback): InstagramEnrichmentException
    {
        try {
            $callback();
        } catch (InstagramEnrichmentException $exception) {
            return $exception;
        }

        $this->fail('Expected an InstagramEnrichmentException to be thrown.');
    }
}
