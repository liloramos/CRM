<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_is_tenant_scoped_and_can_create_a_usable_user(): void
    {
        [$manager, $company] = $this->account(Role::ADMIN_GERENTE);
        $other = Company::query()->create(['name' => 'Outra', 'slug' => 'outra']);
        User::factory()->create(['company_id' => $other->id]);

        $this->actingAs($manager)->getJson('/api/app/settings/users')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonMissingPath('data.users.0.password');

        $created = $this->actingAs($manager)->postJson('/api/app/settings/users', [
            'name' => 'Novo',
            'email' => 'novo@sol.test',
            'role' => Role::ATENDENTE,
            'can_be_seller' => true,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertCreated()->assertJsonPath('data.canBeSeller', true)->json('data');

        $createdUser = User::query()->findOrFail($created['id']);
        $this->assertSame($company->id, $createdUser->company_id);
        $this->assertTrue(password_verify('senha-segura', (string) $createdUser->password));
        $this->assertTrue((bool) $createdUser->can_be_seller);

        $this->actingAs($manager)->postJson('/api/app/logout')->assertOk();
        $this->postJson('/api/app/login', ['email' => 'novo@sol.test', 'password' => 'senha-segura'])
            ->assertOk()
            ->assertJsonPath('user.id', (string) $created['id']);
    }

    public function test_permissions_and_self_access_change_are_protected(): void
    {
        [$attendant] = $this->account(Role::ATENDENTE);
        $this->actingAs($attendant)->getJson('/api/app/settings/users')->assertForbidden();

        [$manager] = $this->account(Role::ADMIN_GERENTE);
        $this->actingAs($manager)->patchJson("/api/app/settings/users/{$manager->id}", [
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => Role::ATENDENTE,
        ])->assertUnprocessable();

        $permissions = $manager->permissionNames();
        $this->actingAs($manager)->patchJson("/api/app/settings/users/{$manager->id}", [
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => Role::ADMIN_GERENTE,
            'permissions' => $permissions,
            'can_be_seller' => true,
        ])->assertOk()->assertJsonPath('data.canBeSeller', true);
        $this->assertSame($permissions, $manager->fresh()->permissionNames());
    }

    public function test_validation_tenant_isolation_and_administrative_edits_are_enforced(): void
    {
        [$manager, $company] = $this->account(Role::ADMIN_GERENTE);
        $existing = User::factory()->create(['company_id' => $company->id]);
        $other = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $foreign = User::factory()->create(['company_id' => $other->id]);

        $this->actingAs($manager)->postJson('/api/app/settings/users', [
            'name' => 'Duplicado',
            'email' => $existing->email,
            'role' => Role::ATENDENTE,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertUnprocessable();
        $this->actingAs($manager)->postJson('/api/app/settings/users', [
            'name' => 'DEV indevido',
            'email' => 'papel@sol.test',
            'role' => Role::SUPER_ADMIN,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
            'company_id' => $other->id,
        ])->assertForbidden();

        $created = $this->actingAs($manager)->postJson('/api/app/settings/users', [
            'name' => 'No tenant',
            'email' => 'tenant@sol.test',
            'role' => Role::ATENDENTE,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
            'company_id' => $other->id,
        ])->assertCreated()->json('data');
        $this->assertSame($company->id, User::query()->findOrFail($created['id'])->company_id);

        $this->actingAs($manager)->patchJson("/api/app/settings/users/{$foreign->id}", [
            'name' => 'Inválido',
            'email' => $foreign->email,
            'role' => Role::ATENDENTE,
        ])->assertNotFound();

        $this->actingAs($manager)->patchJson("/api/app/settings/users/{$existing->id}", [
            'name' => 'Editado',
            'email' => 'editado@sol.test',
            'phone' => '62999990000',
            'job_title' => 'Caixa',
            'can_be_seller' => true,
            'role' => Role::ADMIN_GERENTE,
        ])->assertOk()->assertJsonPath('data.canBeSeller', true)
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.password');
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'name' => 'Editado', 'phone' => '62999990000', 'job_title' => 'Caixa', 'can_be_seller' => true]);
        $this->assertTrue($existing->fresh()->hasRole(Role::ADMIN_GERENTE));
    }

    public function test_dev_can_delegate_permissions_but_delegated_manager_stays_below_its_ceiling(): void
    {
        [$dev, $company] = $this->account(Role::SUPER_ADMIN);
        $attendantDefaults = Role::defaultPermissions()[Role::ATENDENTE];

        $managed = $this->actingAs($dev)->postJson('/api/app/settings/users', [
            'name' => 'Helton',
            'email' => 'helton@sol.test',
            'role' => Role::ATENDENTE,
            'permissions' => [...$attendantDefaults, 'users.manage'],
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertCreated()
            ->assertJsonPath('data.permissions', fn ($permissions) => in_array('users.manage', $permissions, true))
            ->json('data');

        $delegated = User::query()->findOrFail($managed['id']);
        $this->assertTrue($delegated->hasPermissionTo('users.manage'));
        $this->assertSame($company->id, $delegated->company_id);

        $this->actingAs($delegated)->patchJson("/api/app/settings/users/{$delegated->id}", [
            'name' => $delegated->name,
            'email' => $delegated->email,
            'role' => Role::ADMIN_GERENTE,
            'permissions' => Role::defaultPermissions()[Role::ADMIN_GERENTE],
        ])->assertForbidden();

        $target = User::factory()->create(['company_id' => $company->id]);
        $target->assignRole(Role::ATENDENTE);
        $this->actingAs($delegated)->patchJson("/api/app/settings/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => Role::ADMIN_GERENTE,
            'permissions' => Role::defaultPermissions()[Role::ADMIN_GERENTE],
        ])->assertForbidden();

        $this->actingAs($delegated)->patchJson("/api/app/settings/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => Role::ATENDENTE,
            'permissions' => [...$attendantDefaults, 'settings.manage'],
        ])->assertUnprocessable();

        [$manager] = $this->account(Role::ADMIN_GERENTE);
        $this->actingAs($manager)->postJson('/api/app/settings/users', [
            'name' => 'DEV indevido',
            'email' => 'dev-indevido@sol.test',
            'role' => Role::SUPER_ADMIN,
            'permissions' => array_keys(Permission::defaults()),
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'dev-indevido@sol.test']);

        $this->actingAs($manager)->patchJson("/api/app/settings/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => Role::ATENDENTE,
            'permissions' => [...$attendantDefaults, 'roles.manage'],
        ])->assertUnprocessable();

        $this->actingAs($dev)->patchJson("/api/app/settings/users/{$delegated->id}", [
            'name' => $delegated->name,
            'email' => $delegated->email,
            'role' => Role::ATENDENTE,
            'permissions' => $attendantDefaults,
        ])->assertOk();
        $this->assertFalse($delegated->fresh()->hasPermissionTo('users.manage'));
    }

    public function test_lower_authority_cannot_change_protected_dev_and_last_tenant_dev_stays_active(): void
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $globalDev = User::factory()->create(['company_id' => null]);
        $globalDev->assignRole(Role::SUPER_ADMIN);
        $tenantDev = User::factory()->create(['company_id' => $company->id]);
        $tenantDev->assignRole(Role::SUPER_ADMIN);
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(Role::ADMIN_GERENTE);
        $payload = [
            'name' => $tenantDev->name,
            'email' => $tenantDev->email,
            'role' => Role::ADMIN_GERENTE,
            'permissions' => Role::defaultPermissions()[Role::ADMIN_GERENTE],
            'is_active' => false,
        ];

        $this->actingAs($manager)->patchJson("/api/app/settings/users/{$tenantDev->id}", $payload)->assertForbidden();
        $this->actingAs($globalDev)->patchJson("/api/app/settings/users/{$tenantDev->id}", $payload)->assertUnprocessable();
        $this->assertTrue($tenantDev->fresh()->hasRole(Role::SUPER_ADMIN));
        $this->assertTrue((bool) $tenantDev->fresh()->is_active);
    }

    public function test_status_avatar_and_seller_eligibility_remain_tenant_scoped(): void
    {
        Storage::fake('local');
        [$dev, $company] = $this->account(Role::SUPER_ADMIN);
        $user = User::factory()->create(['company_id' => $company->id, 'can_be_seller' => true]);
        $user->assignRole(Role::ATENDENTE);
        $other = Company::query()->create(['name' => 'Outra', 'slug' => 'outra-avatar']);
        $foreign = User::factory()->create(['company_id' => $other->id]);
        $foreign->assignRole(Role::ATENDENTE);

        $avatarUrl = $this->actingAs($dev)->post("/api/app/settings/users/{$user->id}/avatar", [
            'avatar' => UploadedFile::fake()->create('foto.webp', 10, 'image/webp'),
        ])->assertOk()
            ->assertJsonPath('data.avatarUrl', fn ($value) => str_contains((string) $value, "/users/{$user->id}/avatar?v="))
            ->json('data.avatarUrl');
        $this->actingAs($dev)->get($avatarUrl)->assertOk();
        $this->actingAs($dev)->post("/api/app/settings/users/{$foreign->id}/avatar", [
            'avatar' => UploadedFile::fake()->create('foreign.png', 10, 'image/png'),
        ])->assertNotFound();
        $this->actingAs($dev)->deleteJson("/api/app/settings/users/{$user->id}/avatar")
            ->assertOk()
            ->assertJsonPath('data.avatarUrl', null);
        $this->assertNull($user->fresh()->avatar_path);

        $this->actingAs($dev)->patchJson("/api/app/settings/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => Role::ATENDENTE,
            'permissions' => $user->permissionNames(),
            'can_be_seller' => true,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.isActive', false);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'can_be_seller' => true, 'is_active' => false]);

        $this->actingAs($dev)->postJson('/api/app/logout')->assertOk();
        $this->postJson('/api/app/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
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
