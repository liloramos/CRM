<?php

namespace Tests\Feature\Champs;

use App\Champs\DTOs\ResolvedInstagramProfile;
use App\Champs\Exceptions\InstagramResolutionException;
use App\Champs\Services\WebsiteInstagramResolver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteInstagramResolverTest extends TestCase
{
    private const WEBSITE = 'https://93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_resolves_a_direct_instagram_profile_link(): void
    {
        $this->fakeHtml('<a href="https://www.instagram.com/Empresa.Ficticia/">Instagram</a>');

        $profile = $this->resolver()->resolve(self::WEBSITE);

        $this->assertInstanceOf(ResolvedInstagramProfile::class, $profile);
        $this->assertSame('empresa.ficticia', $profile->username);
        $this->assertSame('https://www.instagram.com/empresa.ficticia/', $profile->profileUrl);
        $this->assertSame(ResolvedInstagramProfile::CONFIDENCE_HIGH, $profile->confidence);
        $this->assertSame(self::WEBSITE, $profile->sourceUrl);
        $this->assertFalse($profile->requiresReview);
    }

    public function test_it_normalizes_a_profile_url_with_at_sign(): void
    {
        $this->fakeHtml('<a href="https://instagram.com/@Empresa_Ficticia/">Perfil</a>');

        $profile = $this->resolver()->resolve(self::WEBSITE);

        $this->assertSame('empresa_ficticia', $profile?->username);
    }

    public function test_it_removes_parameters_from_the_canonical_profile_url(): void
    {
        $this->fakeHtml(
            '<a href="https://instagram.com/Empresa.Ficticia/?utm_source=site&amp;igsh=abc">Perfil</a>',
        );

        $profile = $this->resolver()->resolve(self::WEBSITE);

        $this->assertSame('empresa.ficticia', $profile?->username);
        $this->assertSame('https://www.instagram.com/empresa.ficticia/', $profile?->profileUrl);
    }

    public function test_it_ignores_post_reel_story_explore_account_and_share_links(): void
    {
        $this->fakeHtml(implode('', [
            '<a href="https://instagram.com/p/post-id/">Post</a>',
            '<a href="https://instagram.com/reel/reel-id/">Reel</a>',
            '<a href="https://instagram.com/stories/example/1/">Story</a>',
            '<a href="https://instagram.com/explore/">Explore</a>',
            '<a href="https://instagram.com/accounts/login/">Login</a>',
            '<a href="https://instagram.com/share/example/">Share</a>',
        ]));

        $this->assertNull($this->resolver()->resolve(self::WEBSITE));
    }

    public function test_multiple_candidates_require_review_and_have_low_confidence(): void
    {
        $this->fakeHtml(implode('', [
            '<a href="https://instagram.com/unidade.sul/">Sul</a>',
            '<a href="https://instagram.com/unidade.norte/">Norte</a>',
            '<a href="https://instagram.com/unidade.norte/">Norte novamente</a>',
        ]));

        $profile = $this->resolver()->resolve(self::WEBSITE);

        $this->assertSame('unidade.norte', $profile?->username);
        $this->assertSame(ResolvedInstagramProfile::CONFIDENCE_LOW, $profile?->confidence);
        $this->assertTrue($profile?->requiresReview);
        $this->assertSame(['unidade.norte', 'unidade.sul'], $profile?->candidates);
    }

    public function test_it_returns_null_when_the_website_has_no_instagram_link(): void
    {
        $this->fakeHtml('<p>Empresa ficticia sem redes sociais.</p>');

        $this->assertNull($this->resolver()->resolve(self::WEBSITE));
    }

    public function test_it_can_check_one_clear_local_contact_page(): void
    {
        Http::fake([
            self::WEBSITE => $this->htmlResponse('<a href="/contato">Contato</a>'),
            self::WEBSITE.'/contato' => $this->htmlResponse(
                '<a href="https://instagram.com/contato.ficticio/">Instagram</a>',
            ),
        ]);

        $profile = $this->resolver()->resolve(self::WEBSITE);

        $this->assertSame('contato.ficticio', $profile?->username);
        $this->assertSame(ResolvedInstagramProfile::CONFIDENCE_MEDIUM, $profile?->confidence);
        $this->assertSame(self::WEBSITE.'/contato', $profile?->sourceUrl);
        Http::assertSentCount(2);
    }

    public function test_it_handles_a_connection_timeout(): void
    {
        Http::fake([
            self::WEBSITE => Http::failedConnection('Simulated timeout'),
        ]);

        $exception = $this->captureException(
            fn () => $this->resolver()->resolve(self::WEBSITE),
        );

        $this->assertStringContainsString('consultar o website', $exception->getMessage());
    }

    public function test_it_validates_every_redirect_and_follows_at_most_the_safe_chain(): void
    {
        Http::fake([
            self::WEBSITE => Http::response('', 302, ['Location' => '/inicio']),
            self::WEBSITE.'/inicio' => $this->htmlResponse(
                '<a href="https://instagram.com/redirecionada.ficticia/">Instagram</a>',
            ),
        ]);

        $profile = $this->resolver()->resolve(self::WEBSITE);

        $this->assertSame('redirecionada.ficticia', $profile?->username);
        $this->assertSame(self::WEBSITE.'/inicio', $profile?->sourceUrl);
        Http::assertSentCount(2);
    }

    public function test_it_rejects_invalid_html(): void
    {
        Http::fake([
            self::WEBSITE => Http::response('<div>fragmento incompleto</div>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $exception = $this->captureException(
            fn () => $this->resolver()->resolve(self::WEBSITE),
        );

        $this->assertStringContainsString('HTML valido', $exception->getMessage());
    }

    public function test_it_blocks_localhost_and_private_network_destinations(): void
    {
        Http::fake();

        foreach (['http://localhost', 'http://127.0.0.1', 'http://10.0.0.8'] as $url) {
            $exception = $this->captureException(fn () => $this->resolver()->resolve($url));

            $this->assertStringContainsString('nao permitido', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_rejects_responses_over_the_size_limit(): void
    {
        Http::fake([
            self::WEBSITE => Http::response('<html><body>grande</body></html>', 200, [
                'Content-Type' => 'text/html',
                'Content-Length' => '500',
            ]),
        ]);

        $exception = $this->captureException(
            fn () => $this->resolver(maxResponseBytes: 100)->resolve(self::WEBSITE),
        );

        $this->assertStringContainsString('tamanho', $exception->getMessage());
    }

    public function test_it_never_requests_the_instagram_domain(): void
    {
        $this->fakeHtml('<a href="https://instagram.com/empresa.segura/">Instagram</a>');

        $this->resolver()->resolve(self::WEBSITE);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === '93.184.216.34');
    }

    public function test_it_blocks_a_redirect_to_the_instagram_domain_before_connecting(): void
    {
        Http::fake([
            self::WEBSITE => Http::response('', 302, [
                'Location' => 'https://www.instagram.com/empresa.ficticia/',
            ]),
        ]);

        $exception = $this->captureException(
            fn () => $this->resolver()->resolve(self::WEBSITE),
        );

        $this->assertStringContainsString('nao permitido', $exception->getMessage());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => parse_url(
            $request->url(),
            PHP_URL_HOST,
        ) !== 'www.instagram.com');
    }

    private function fakeHtml(string $body): void
    {
        Http::fake([
            self::WEBSITE => $this->htmlResponse($body),
        ]);
    }

    private function htmlResponse(string $body): PromiseInterface
    {
        return Http::response('<html><body>'.$body.'</body></html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function resolver(int $maxResponseBytes = 1_048_576): WebsiteInstagramResolver
    {
        return new WebsiteInstagramResolver(maxResponseBytes: $maxResponseBytes);
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function captureException(callable $callback): InstagramResolutionException
    {
        try {
            $callback();
        } catch (InstagramResolutionException $exception) {
            return $exception;
        }

        $this->fail('Expected an InstagramResolutionException to be thrown.');
    }
}
