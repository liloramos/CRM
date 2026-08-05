<?php

namespace Tests\Feature\Champs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChampsTestGooglePlacesCommandTest extends TestCase
{
    private const API_KEY = 'fake-command-key-for-tests';

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

    public function test_command_displays_discovered_leads_without_exposing_the_key(): void
    {
        Http::fake([
            'https://places.googleapis.com/v1/places:searchText' => Http::response([
                'places' => [
                    [
                        'id' => 'place-command-fictitious',
                        'displayName' => ['text' => 'Clínica Horizonte Fictícia'],
                        'formattedAddress' => 'Avenida Exemplo, 200 - São Paulo - SP',
                        'nationalPhoneNumber' => '(11) 5555-0200',
                        'websiteUri' => 'https://horizonte-ficticia.example',
                        'rating' => 4.7,
                        'userRatingCount' => 42,
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('champs:test-google-places', [
            'niche' => 'clínica de estética',
            '--city' => 'São Paulo',
            '--state' => 'SP',
            '--limit' => '5',
        ])
            ->expectsOutputToContain('Clínica Horizonte Fictícia')
            ->expectsOutputToContain('1 estabelecimento(s) encontrado(s).')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_command_returns_failure_when_the_provider_is_disabled(): void
    {
        config(['champs.google_places.enabled' => false]);
        Http::fake();

        $this->artisan('champs:test-google-places', [
            'niche' => 'clínica de estética',
            '--city' => 'São Paulo',
            '--state' => 'SP',
            '--limit' => '5',
        ])
            ->expectsOutputToContain('disabled')
            ->assertExitCode(Command::FAILURE);

        Http::assertNothingSent();
    }
}
