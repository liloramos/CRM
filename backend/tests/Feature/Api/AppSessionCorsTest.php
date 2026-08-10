<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AppSessionCorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cors.allowed_origins', ['http://localhost:5173']);
    }

    public function test_configured_frontend_can_read_the_csrf_endpoint_with_credentials(): void
    {
        $response = $this->getJson('/api/app/csrf-token', [
            'Origin' => 'http://localhost:5173',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['csrf_token'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_configured_frontend_preflight_is_allowed_without_wildcard_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/app/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'X-CSRF-TOKEN, Content-Type',
        ]);

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_unconfigured_origin_is_not_reflected_by_cors(): void
    {
        $response = $this->getJson('/api/app/csrf-token', [
            'Origin' => 'https://origem-nao-configurada.example',
        ]);

        $response->assertOk();

        self::assertNotSame(
            'https://origem-nao-configurada.example',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
        self::assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
