<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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
        Storage::fake(config('filesystems.default'));

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
            ->assertJsonPath('user.avatar_url', Storage::disk(config('filesystems.default'))->url($avatarPath));
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

    #[DataProvider('validAvatarFormats')]
    public function test_valid_avatar_format_is_accepted(
        string $fileName,
        string $fixture,
        string $expectedMimeType,
    ): void {
        Storage::fake(config('filesystems.default'));
        $user = User::factory()->create();
        [$avatar, $temporaryPath] = $this->temporaryUpload(
            $fileName,
            $this->imageContent($fixture),
            $expectedMimeType,
        );

        try {
            $this->assertSame($expectedMimeType, $avatar->getMimeType());

            $this->actingAs($user)
                ->post('/api/app/profile/avatar', [
                    'avatar' => $avatar,
                ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('message', 'Foto de perfil atualizada.');
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        $path = $user->refresh()->avatar_path;

        $this->assertNotNull($path);
        Storage::disk(config('filesystems.default'))->assertExists($path);
    }

    public function test_valid_avatar_replaces_the_previous_avatar(): void
    {
        Storage::fake(config('filesystems.default'));

        $user = User::factory()->create();
        $oldPath = "avatars/{$user->id}/old.png";
        $user->forceFill(['avatar_path' => $oldPath])->save();
        Storage::disk(config('filesystems.default'))->put($oldPath, 'old-avatar');

        $this->actingAs($user)
            ->post('/api/app/profile/avatar', [
                'avatar' => $this->fakeImage('replacement.webp', 'webp'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Foto de perfil atualizada.');

        $newPath = $user->refresh()->avatar_path;

        $this->assertNotNull($newPath);
        $this->assertStringStartsWith("avatars/{$user->id}/", $newPath);
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk(config('filesystems.default'))->assertExists($newPath);
        Storage::disk(config('filesystems.default'))->assertMissing($oldPath);
    }

    public function test_svg_avatar_is_rejected_and_preserves_the_previous_avatar(): void
    {
        $this->assertInvalidUploadPreservesAvatar(
            UploadedFile::fake()->createWithContent(
                'avatar.svg',
                '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>',
            ),
        );
    }

    public function test_text_file_renamed_as_image_is_rejected_and_preserves_the_previous_avatar(): void
    {
        [$avatar, $temporaryPath] = $this->temporaryUpload(
            'avatar.jpg',
            'isto não é uma imagem',
            'image/jpeg',
        );

        try {
            $this->assertSame('text/plain', $avatar->getMimeType());
            $this->assertInvalidUploadPreservesAvatar($avatar);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function test_avatar_larger_than_two_megabytes_is_rejected_and_preserves_the_previous_avatar(): void
    {
        $content = $this->imageContent('png').str_repeat('x', (2 * 1024 * 1024) + 1);

        $this->assertInvalidUploadPreservesAvatar(
            UploadedFile::fake()->createWithContent('oversized.png', $content),
        );
    }

    public function test_user_can_remove_their_avatar_repeatedly_without_touching_another_path(): void
    {
        Storage::fake(config('filesystems.default'));

        $user = User::factory()->create();
        $avatarPath = "avatars/{$user->id}/profile.png";
        $user->forceFill(['avatar_path' => $avatarPath])->save();
        Storage::disk(config('filesystems.default'))->put($avatarPath, 'avatar');
        Storage::disk(config('filesystems.default'))->put('avatars/999/protected.png', 'protected');

        $this->actingAs($user)
            ->deleteJson('/api/app/profile/avatar')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);

        $this->actingAs($user)
            ->deleteJson('/api/app/profile/avatar')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk(config('filesystems.default'))->assertMissing($avatarPath);
        Storage::disk(config('filesystems.default'))->assertExists('avatars/999/protected.png');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validAvatarFormats(): array
    {
        return [
            'JPG' => ['avatar.jpg', 'jpeg', 'image/jpeg'],
            'JPEG' => ['avatar.jpeg', 'jpeg', 'image/jpeg'],
            'PNG' => ['avatar.png', 'png', 'image/png'],
            'WebP' => ['avatar.webp', 'webp', 'image/webp'],
        ];
    }

    private function assertInvalidUploadPreservesAvatar(UploadedFile $avatar): void
    {
        Storage::fake(config('filesystems.default'));

        $user = User::factory()->create();
        $previousPath = "avatars/{$user->id}/previous.png";
        $user->forceFill(['avatar_path' => $previousPath])->save();
        Storage::disk(config('filesystems.default'))->put($previousPath, $this->imageContent('png'));

        $this->actingAs($user)
            ->post('/api/app/profile/avatar', [
                'avatar' => $avatar,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertSame($previousPath, $user->refresh()->avatar_path);
        Storage::disk(config('filesystems.default'))->assertExists($previousPath);
        $this->assertCount(1, Storage::disk(config('filesystems.default'))->allFiles("avatars/{$user->id}"));
    }

    private function fakeImage(string $name, string $fixture): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->imageContent($fixture));
    }

    /**
     * @return array{UploadedFile, string}
     */
    private function temporaryUpload(string $name, string $content, string $clientMimeType): array
    {
        $path = tempnam(sys_get_temp_dir(), 'champs-avatar-');
        $this->assertIsString($path);
        $this->assertNotFalse(file_put_contents($path, $content));

        return [
            new UploadedFile($path, $name, $clientMimeType, UPLOAD_ERR_OK, true),
            $path,
        ];
    }

    private function imageContent(string $fixture): string
    {
        $content = base64_decode(
            match ($fixture) {
                'jpeg' => '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==',
                'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                'webp' => 'UklGRkoAAABXRUJQVlA4WAoAAAAQAAAAAAAAAAAAQUxQSAwAAAAQkP8PBAAQAFZQOCAcAAAAMAEAnQEqAQABAAFAJiWkAANwAP7+4f4AAA==',
                default => throw new \InvalidArgumentException("Fixture de imagem desconhecida: {$fixture}"),
            },
            true,
        );

        $this->assertIsString($content);

        return $content;
    }
}
