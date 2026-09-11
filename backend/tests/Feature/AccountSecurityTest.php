<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_reads_only_its_safe_security_state(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('sensitive-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['secret-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->getJson('/api/app/account/security')
            ->assertOk()
            ->assertJsonPath('data.account.email', $user->email)
            ->assertJsonPath('data.two_factor.enabled', true)
            ->assertJsonPath('data.session.active', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.two_factor_secret')
            ->assertJsonMissingPath('data.two_factor_recovery_codes')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_unauthenticated_user_cannot_read_security_state(): void
    {
        $this->getJson('/api/app/account/security')->assertUnauthorized();
    }
}
