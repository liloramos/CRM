<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_endpoints_require_authentication(): void
    {
        $this->patchJson('/api/app/profile', [])->assertUnauthorized();
        $this->postJson('/api/app/profile/avatar', [])->assertUnauthorized();
        $this->deleteJson('/api/app/profile/avatar')->assertUnauthorized();
    }

    public function test_session_returns_the_authenticated_users_real_profile(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'name' => 'Marcelo Fictício',
            'email' => 'marcelo.ficticio@example.test',
            'phone' => '+55 11 90000-0000',
            'job_title' => 'Gestor de tráfego',
        ]);
        $avatarPath = "avatars/{$user->id}/profile.png";
        $user->forceFill(['avatar_path' => $avatarPath])->save();

        $this->actingAs($user)
            ->getJson('/api/app/session')
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.name', 'Marcelo Fictício')
            ->assertJsonPath('user.phone', '+55 11 90000-0000')
            ->assertJsonPath('user.job_title', 'Gestor de tráfego')
            ->assertJsonPath('user.avatar_url', Storage::disk('public')->url($avatarPath));
    }

    public function test_authenticated_user_can_update_profile_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/app/profile', [
                'name' => '  Ana Estratégia  ',
                'email' => 'ana.estrategia@example.test',
                'phone' => '  +55 21 98888-0000  ',
                'job_title' => '  Gestora de mídia  ',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Ana Estratégia')
            ->assertJsonPath('user.email', 'ana.estrategia@example.test')
            ->assertJsonPath('user.phone', '+55 21 98888-0000')
            ->assertJsonPath('user.job_title', 'Gestora de mídia');

        $user->refresh();

        $this->assertSame('Ana Estratégia', $user->name);
        $this->assertSame('+55 21 98888-0000', $user->phone);
        $this->assertSame('Gestora de mídia', $user->job_title);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_is_preserved_when_email_does_not_change(): void
    {
        $user = User::factory()->create();
        $verifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->patchJson('/api/app/profile', [
                'name' => 'Nome Atualizado',
                'email' => $user->email,
                'phone' => null,
                'job_title' => null,
            ])
            ->assertOk();

        $this->assertTrue($verifiedAt->equalTo($user->refresh()->email_verified_at));
    }

    public function test_profile_update_reuses_unique_email_validation(): void
    {
        $existingUser = User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/app/profile', [
                'name' => 'Usuária Fictícia',
                'email' => $existingUser->email,
                'phone' => null,
                'job_title' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_user_can_upload_and_replace_their_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $oldPath = "avatars/{$user->id}/old.png";
        $user->forceFill(['avatar_path' => $oldPath])->save();
        Storage::disk('public')->put($oldPath, 'old-avatar');

        $response = $this->actingAs($user)
            ->post('/api/app/profile/avatar', [
                'avatar' => $this->fakePng('profile.png'),
            ], ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Foto de perfil atualizada.');

        $path = $user->refresh()->avatar_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith("avatars/{$user->id}/", $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing($oldPath);

        $replacement = $this->actingAs($user)
            ->post('/api/app/profile/avatar', [
                'avatar' => $this->fakePng('replacement.png'),
            ], ['Accept' => 'application/json']);

        $replacement->assertOk();
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($user->refresh()->avatar_path);
    }

    public function test_user_can_remove_their_avatar_without_touching_another_path(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $avatarPath = "avatars/{$user->id}/profile.png";
        $user->forceFill(['avatar_path' => $avatarPath])->save();
        Storage::disk('public')->put($avatarPath, 'avatar');
        Storage::disk('public')->put('avatars/999/protected.png', 'protected');

        $this->actingAs($user)
            ->deleteJson('/api/app/profile/avatar')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($avatarPath);
        Storage::disk('public')->assertExists('avatars/999/protected.png');
    }

    public function test_avatar_upload_rejects_non_image_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/api/app/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('payload.txt', 10, 'text/plain'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    private function fakePng(string $name): UploadedFile
    {
        $content = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        $this->assertIsString($content);

        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
