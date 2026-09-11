<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_read_and_update_only_their_profile(): void
    {
        [$user] = $this->account(Role::ATENDENTE);
        $this->actingAs($user)->getJson('/api/app/account/profile')->assertOk()->assertJsonPath('data.email', $user->email);
        $permissions = $user->permissionNames();

        $this->actingAs($user)->patchJson('/api/app/account/profile', [
            'name' => 'Nome atualizado',
            'email' => $user->email,
            'phone' => '62999990000',
            'job_title' => 'Desenvolvedor',
        ])->assertOk()->assertJsonPath('data.jobTitle', 'Desenvolvedor');

        $this->actingAs($user)->getJson('/api/app/account/profile')
            ->assertOk()
            ->assertJsonPath('data.jobTitle', 'Desenvolvedor');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nome atualizado', 'phone' => '62999990000', 'job_title' => 'Desenvolvedor']);
        $this->assertSame($permissions, $user->fresh()->permissionNames());

        $this->actingAs($user)->patchJson('/api/app/account/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [Role::SUPER_ADMIN],
            'permissions' => ['roles.manage'],
            'can_be_seller' => true,
            'company_id' => 999,
        ])->assertUnprocessable();
        $this->assertTrue($user->fresh()->hasRole(Role::ATENDENTE));
        $this->assertFalse((bool) $user->fresh()->can_be_seller);
    }

    public function test_avatar_validation_and_company_management_are_protected(): void
    {
        Storage::fake('local');
        [$attendant] = $this->account(Role::ATENDENTE);
        [$manager, $company] = $this->account(Role::ADMIN_GERENTE);
        $this->actingAs($attendant)->post('/api/app/account/profile/avatar', ['avatar' => UploadedFile::fake()->create('arquivo.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $avatar = $this->actingAs($attendant)->post('/api/app/account/profile/avatar', ['avatar' => UploadedFile::fake()->create('avatar.png', 10, 'image/png')])
            ->assertOk()
            ->assertJsonPath('data.avatarUrl', fn ($value) => str_contains((string) $value, "/users/{$attendant->id}/avatar?v="))
            ->json('data.avatarUrl');
        $this->actingAs($attendant)->get($avatar)->assertOk();
        $this->actingAs($attendant)->getJson('/api/app/session')->assertJsonPath('user.avatarUrl', $avatar);
        $this->actingAs($attendant)->deleteJson('/api/app/account/profile/avatar')->assertOk()->assertJsonPath('data.avatarUrl', null);
        $this->assertNull($attendant->fresh()->avatar_path);
        $this->actingAs($attendant)->patchJson('/api/app/account/company', ['name' => 'Bloqueado'])->assertForbidden();
        $this->actingAs($manager)->patchJson('/api/app/account/company', ['name' => 'Sol Atualizado', 'display_name' => 'Sol Restaurante', 'timezone' => 'America/Sao_Paulo'])->assertOk()->assertJsonPath('data.name', 'Sol Atualizado');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Sol Atualizado']);
    }

    public function test_email_and_password_updates_keep_the_existing_security_flow(): void
    {
        [$user] = $this->account(Role::ATENDENTE);

        $this->actingAs($user)->patchJson('/api/app/account/profile', [
            'name' => $user->name,
            'email' => 'novo-email@sol.test',
            'phone' => null,
            'job_title' => null,
        ])->assertOk()->assertJsonPath('data.email', 'novo-email@sol.test');
        $this->assertNull($user->fresh()->email_verified_at);

        $this->actingAs($user)->patchJson('/api/app/account/profile', [
            'name' => $user->name,
            'email' => 'novo-email@sol.test',
            'current_password' => 'senha-incorreta',
            'password' => 'senha-nova-segura',
            'password_confirmation' => 'senha-nova-segura',
        ])->assertUnprocessable();

        $this->actingAs($user)->patchJson('/api/app/account/profile', [
            'name' => $user->name,
            'email' => 'novo-email@sol.test',
            'current_password' => 'password',
            'password' => 'senha-nova-segura',
            'password_confirmation' => 'senha-nova-segura',
        ])->assertOk();
        $this->assertTrue(password_verify('senha-nova-segura', (string) $user->fresh()->password));
    }

    private function account(string $role): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole($role);

        return [$user, $company];
    }
}
