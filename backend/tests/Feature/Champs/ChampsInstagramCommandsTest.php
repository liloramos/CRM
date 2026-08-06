<?php

namespace Tests\Feature\Champs;

use App\Champs\Contracts\InstagramEnricherInterface;
use App\Champs\Contracts\InstagramResolverInterface;
use App\Champs\DTOs\InstagramProfileData;
use App\Champs\DTOs\ResolvedInstagramProfile;
use App\Champs\Exceptions\InstagramEnrichmentException;
use App\Champs\Exceptions\InstagramResolutionException;
use Illuminate\Console\Command;
use Mockery;
use Tests\TestCase;

class ChampsInstagramCommandsTest extends TestCase
{
    public function test_resolver_command_displays_a_public_candidate(): void
    {
        $resolver = Mockery::mock(InstagramResolverInterface::class);
        $resolver->shouldReceive('resolve')->once()->andReturn(new ResolvedInstagramProfile(
            username: 'empresa.ficticia',
            profileUrl: 'https://www.instagram.com/empresa.ficticia/',
            confidence: ResolvedInstagramProfile::CONFIDENCE_HIGH,
            sourceUrl: 'https://empresa-ficticia.example',
            candidates: ['empresa.ficticia'],
        ));
        $this->app->instance(InstagramResolverInterface::class, $resolver);

        $this->artisan('champs:resolve-instagram', [
            'website' => 'https://empresa-ficticia.example',
        ])
            ->expectsOutputToContain('@empresa.ficticia')
            ->expectsOutputToContain('high')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_resolver_command_returns_failure_on_a_technical_error(): void
    {
        $resolver = Mockery::mock(InstagramResolverInterface::class);
        $resolver->shouldReceive('resolve')->once()
            ->andThrow(InstagramResolutionException::requestFailed());
        $this->app->instance(InstagramResolverInterface::class, $resolver);

        $this->artisan('champs:resolve-instagram', [
            'website' => 'https://empresa-ficticia.example',
        ])
            ->expectsOutputToContain('consultar o website')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_enricher_command_explains_a_non_professional_account(): void
    {
        $enricher = Mockery::mock(InstagramEnricherInterface::class);
        $enricher->shouldReceive('enrich')->once()
            ->andReturn(InstagramProfileData::notEnrichable('conta.pessoal'));
        $this->app->instance(InstagramEnricherInterface::class, $enricher);

        $this->artisan('champs:enrich-instagram', ['username' => 'conta.pessoal'])
            ->expectsOutputToContain('nao e profissional')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_enricher_command_returns_sanitized_failure_without_token(): void
    {
        $secret = 'secret-token-that-must-not-appear';
        $enricher = Mockery::mock(InstagramEnricherInterface::class);
        $enricher->shouldReceive('enrich')->once()
            ->andThrow(InstagramEnrichmentException::credentialsRejected());
        $this->app->instance(InstagramEnricherInterface::class, $enricher);

        $this->artisan('champs:enrich-instagram', ['username' => 'empresa.ficticia'])
            ->expectsOutputToContain('credenciais')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(Command::FAILURE);
    }
}
