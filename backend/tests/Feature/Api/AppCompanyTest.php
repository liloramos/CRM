<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_endpoints_require_authentication(): void
    {
        $this->getJson('/api/app/company')->assertUnauthorized();
        $this->patchJson('/api/app/company', [])->assertUnauthorized();
        $this->postJson('/api/app/company/logo', [])->assertUnauthorized();
        $this->deleteJson('/api/app/company/logo')->assertUnauthorized();
    }

    public function test_company_endpoint_returns_only_the_authenticated_users_company(): void
    {
        $company = $this->company('Empresa Aurora', 'empresa-aurora');
        $otherCompany = $this->company('Empresa Horizonte', 'empresa-horizonte');
        $user = $this->userFor($company, Role::ATENDENTE);

        $this->actingAs($user)
            ->getJson('/api/app/company')
            ->assertOk()
            ->assertJsonPath('company.id', (string) $company->id)
            ->assertJsonPath('company.name', 'Empresa Aurora')
            ->assertJsonPath('company.can_manage', false)
            ->assertJsonMissing(['name' => $otherCompany->name]);

        $this->actingAs($user)
            ->getJson("/api/app/company?company_id={$otherCompany->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_admin_updates_public_company_fields_without_changing_slug_or_role(): void
    {
        $company = $this->company('Empresa Inicial', 'empresa-estavel');
        $user = $this->userFor($company, Role::ADMIN_GERENTE);
        $roleNames = $user->roleNames();

        $this->actingAs($user)
            ->patchJson('/api/app/company', [
                'name' => '  Estratégia Norte  ',
                'trade_name' => '  Norte Performance  ',
                'responsible_name' => '  Paula Fictícia  ',
                'email' => '  contato@norte.example.test  ',
                'phone' => '  +55 11 90000-1000  ',
                'timezone' => 'America/Manaus',
            ])
            ->assertOk()
            ->assertJsonPath('company.name', 'Estratégia Norte')
            ->assertJsonPath('company.trade_name', 'Norte Performance')
            ->assertJsonPath('company.responsible_name', 'Paula Fictícia')
            ->assertJsonPath('company.email', 'contato@norte.example.test')
            ->assertJsonPath('company.phone', '+55 11 90000-1000')
            ->assertJsonPath('company.timezone', 'America/Manaus')
            ->assertJsonPath('company.slug', 'empresa-estavel')
            ->assertJsonPath('company.can_manage', true);

        $company->refresh()->load('setting');

        $this->assertSame('Estratégia Norte', $company->name);
        $this->assertSame('America/Manaus', $company->setting?->timezone);
        $this->assertSame('empresa-estavel', $company->slug);
        $this->assertSame($roleNames, $user->fresh()->roleNames());
    }

    public function test_company_update_rejects_tenant_slug_role_and_internal_logo_fields(): void
    {
        $company = $this->company();
        $otherCompany = $this->company('Outro Tenant', 'outro-tenant');
        $user = $this->userFor($company, Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->patchJson('/api/app/company', [
                'name' => 'Nome permitido',
                'company_id' => $otherCompany->id,
                'slug' => 'slug-injetado',
                'role' => 'super_admin',
                'roles' => ['super_admin'],
                'logo_path' => '../../arquivo.png',
                'logo_url' => 'https://externo.example/logo.png',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_id',
                'slug',
                'role',
                'roles',
                'logo_path',
                'logo_url',
            ]);

        $this->assertSame('Empresa Fictícia', $company->refresh()->name);
        $this->assertSame('Outro Tenant', $otherCompany->refresh()->name);
    }

    public function test_company_name_and_timezone_are_validated(): void
    {
        $user = $this->userFor($this->company(), Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->patchJson('/api/app/company', [
                'name' => '   ',
                'timezone' => 'Fuso/Inexistente',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'timezone']);
    }

    #[DataProvider('administrativeRoles')]
    public function test_real_administrative_roles_can_manage_the_company(string $role): void
    {
        $company = $this->company();
        $user = $this->userFor($company, $role);

        $this->actingAs($user)
            ->patchJson('/api/app/company', ['name' => "Empresa {$role}"])
            ->assertOk();

        $this->assertSame("Empresa {$role}", $company->refresh()->name);
    }

    #[DataProvider('readOnlyRoles')]
    public function test_users_without_settings_permission_can_view_but_cannot_change_the_company(string $role): void
    {
        $company = $this->company();
        $user = $this->userFor($company, $role);

        $this->actingAs($user)->getJson('/api/app/company')->assertOk();
        $this->actingAs($user)
            ->patchJson('/api/app/company', ['name' => 'Alteração proibida'])
            ->assertForbidden();
        $this->actingAs($user)
            ->postJson('/api/app/company/logo', [])
            ->assertForbidden();
        $this->actingAs($user)
            ->deleteJson('/api/app/company/logo')
            ->assertForbidden();

        $this->assertSame('Empresa Fictícia', $company->refresh()->name);
    }

    #[DataProvider('validLogoFormats')]
    public function test_valid_company_logo_format_is_accepted(
        string $fileName,
        string $fixture,
        string $expectedMimeType,
    ): void {
        Storage::fake(config('filesystems.default'));
        $company = $this->company();
        $user = $this->userFor($company, Role::ADMIN_GERENTE);
        [$logo, $temporaryPath] = $this->temporaryUpload(
            $fileName,
            $this->imageContent($fixture),
            $expectedMimeType,
        );

        try {
            $this->assertSame($expectedMimeType, $logo->getMimeType());

            $response = $this->actingAs($user)
                ->post('/api/app/company/logo', [
                    'logo' => $logo,
                ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('message', 'Logo da empresa atualizada.')
                ->assertJsonMissingPath('company.logo_path');

            $this->assertStringNotContainsString('C:\\', $response->getContent());
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        $path = $company->refresh()->logo_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith("companies/{$company->id}/logo/", $path);
        Storage::disk(config('filesystems.default'))->assertExists($path);
    }

    public function test_svg_logo_is_rejected_and_preserves_the_previous_logo(): void
    {
        $this->assertInvalidUploadPreservesLogo(
            UploadedFile::fake()->createWithContent(
                'logo.svg',
                '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>',
            ),
        );
    }

    public function test_text_file_renamed_as_logo_is_rejected_and_preserves_the_previous_logo(): void
    {
        [$logo, $temporaryPath] = $this->temporaryUpload(
            'logo.jpg',
            'isto não é uma imagem',
            'image/jpeg',
        );

        try {
            $this->assertSame('text/plain', $logo->getMimeType());
            $this->assertInvalidUploadPreservesLogo($logo);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function test_logo_larger_than_two_megabytes_is_rejected_and_preserves_the_previous_logo(): void
    {
        $content = $this->imageContent('png').str_repeat('x', (2 * 1024 * 1024) + 1);

        $this->assertInvalidUploadPreservesLogo(
            UploadedFile::fake()->createWithContent('oversized.png', $content),
        );
    }

    public function test_valid_logo_replaces_the_previous_logo(): void
    {
        Storage::fake(config('filesystems.default'));
        $company = $this->company();
        $user = $this->userFor($company, Role::ADMIN_GERENTE);
        $oldPath = "companies/{$company->id}/logo/old.png";
        $company->forceFill(['logo_path' => $oldPath])->save();
        Storage::disk(config('filesystems.default'))->put($oldPath, 'old-logo');

        $this->actingAs($user)
            ->post('/api/app/company/logo', [
                'logo' => $this->fakeImage('replacement.webp', 'webp'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $newPath = $company->refresh()->logo_path;

        $this->assertNotNull($newPath);
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk(config('filesystems.default'))->assertExists($newPath);
        Storage::disk(config('filesystems.default'))->assertMissing($oldPath);
    }

    public function test_logo_removal_is_idempotent_and_never_deletes_another_tenants_file(): void
    {
        Storage::fake(config('filesystems.default'));
        $company = $this->company();
        $otherCompany = $this->company('Tenant Protegido', 'tenant-protegido');
        $user = $this->userFor($company, Role::ADMIN_GERENTE);
        $ownPath = "companies/{$company->id}/logo/current.png";
        $otherPath = "companies/{$otherCompany->id}/logo/protected.png";
        $company->forceFill(['logo_path' => $ownPath])->save();
        Storage::disk(config('filesystems.default'))->put($ownPath, 'current');
        Storage::disk(config('filesystems.default'))->put($otherPath, 'protected');

        $this->actingAs($user)
            ->deleteJson('/api/app/company/logo')
            ->assertOk()
            ->assertJsonPath('company.logo_url', null);
        $this->actingAs($user)
            ->deleteJson('/api/app/company/logo')
            ->assertOk()
            ->assertJsonPath('company.logo_url', null);

        Storage::disk(config('filesystems.default'))->assertMissing($ownPath);
        Storage::disk(config('filesystems.default'))->assertExists($otherPath);

        $company->forceFill(['logo_path' => $otherPath])->save();
        $this->actingAs($user)->deleteJson('/api/app/company/logo')->assertOk();

        $this->assertNull($company->refresh()->logo_path);
        Storage::disk(config('filesystems.default'))->assertExists($otherPath);
    }

    public function test_session_returns_the_real_company_and_does_not_expose_internal_paths(): void
    {
        Storage::fake(config('filesystems.default'));
        $company = $this->company('Workspace Champs Fictício', 'workspace-champs-ficticio');
        $company->setting()->create(['timezone' => 'America/Recife']);
        $logoPath = "companies/{$company->id}/logo/identity.png";
        $company->forceFill(['logo_path' => $logoPath])->save();
        $user = $this->userFor($company, Role::ADMIN_GERENTE);

        $response = $this->actingAs($user)
            ->getJson('/api/app/session')
            ->assertOk()
            ->assertJsonPath('user.company.id', (string) $company->id)
            ->assertJsonPath('user.company.name', 'Workspace Champs Fictício')
            ->assertJsonPath('user.company.timezone', 'America/Recife')
            ->assertJsonPath('user.company.logo_url', Storage::disk(config('filesystems.default'))->url($logoPath))
            ->assertJsonPath('user.company.can_manage', true)
            ->assertJsonMissingPath('user.company.logo_path');

        $this->assertStringNotContainsString('restaurant_profiles', $response->getContent());
    }

    public function test_updating_champs_company_preserves_restaurante_sol(): void
    {
        $sol = $this->company('Restaurante Sol', 'restaurante-sol');
        $champs = $this->company('Workspace Champs', 'workspace-champs');
        $user = $this->userFor($champs, Role::ADMIN_GERENTE);

        $this->actingAs($user)
            ->patchJson('/api/app/company', [
                'name' => 'Identidade Champs Atualizada',
                'timezone' => 'America/Sao_Paulo',
            ])
            ->assertOk();

        $this->assertSame('Restaurante Sol', $sol->refresh()->name);
        $this->assertSame('restaurante-sol', $sol->slug);
        $this->assertSame('Identidade Champs Atualizada', $champs->refresh()->name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function administrativeRoles(): array
    {
        return [
            'super admin' => [Role::SUPER_ADMIN],
            'admin gerente' => [Role::ADMIN_GERENTE],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readOnlyRoles(): array
    {
        return [
            'atendente operacional' => [Role::ATENDENTE],
            'viewer sem permissão' => ['viewer'],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validLogoFormats(): array
    {
        return [
            'JPG' => ['logo.jpg', 'jpeg', 'image/jpeg'],
            'JPEG' => ['logo.jpeg', 'jpeg', 'image/jpeg'],
            'PNG' => ['logo.png', 'png', 'image/png'],
            'WebP' => ['logo.webp', 'webp', 'image/webp'],
        ];
    }

    private function company(
        string $name = 'Empresa Fictícia',
        ?string $slug = null,
    ): Company {
        return Company::query()->create([
            'name' => $name,
            'slug' => $slug ?? 'empresa-ficticia-'.str()->random(8),
        ]);
    }

    private function userFor(Company $company, string $role): User
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $roleModel = Role::query()->firstOrCreate(
            ['name' => $role],
            ['label' => str($role)->headline()->toString()],
        );
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole($roleModel);

        return $user;
    }

    private function assertInvalidUploadPreservesLogo(UploadedFile $logo): void
    {
        Storage::fake(config('filesystems.default'));
        $company = $this->company();
        $user = $this->userFor($company, Role::ADMIN_GERENTE);
        $previousPath = "companies/{$company->id}/logo/previous.png";
        $company->forceFill(['logo_path' => $previousPath])->save();
        Storage::disk(config('filesystems.default'))->put($previousPath, $this->imageContent('png'));

        $this->actingAs($user)
            ->post('/api/app/company/logo', [
                'logo' => $logo,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');

        $this->assertSame($previousPath, $company->refresh()->logo_path);
        Storage::disk(config('filesystems.default'))->assertExists($previousPath);
        $this->assertCount(1, Storage::disk(config('filesystems.default'))->allFiles("companies/{$company->id}/logo"));
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
        $path = tempnam(sys_get_temp_dir(), 'champs-company-logo-');
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
