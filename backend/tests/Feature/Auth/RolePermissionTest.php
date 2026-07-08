<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_roles_and_permissions_can_be_seeded(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => Role::SUPER_ADMIN]);
        $this->assertDatabaseHas('roles', ['name' => Role::ADMIN_GERENTE]);
        $this->assertDatabaseHas('roles', ['name' => Role::ATENDENTE]);
        $this->assertDatabaseHas('permissions', ['name' => 'users.view']);

        $adminRole = Role::query()->where('name', Role::ADMIN_GERENTE)->firstOrFail();

        $this->assertTrue(
            $adminRole->permissions()->where('name', 'users.manage')->exists(),
        );
    }

    public function test_user_can_have_company_role_and_permissions(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $company = Company::query()->create([
            'name' => 'Empresa Teste',
            'slug' => 'empresa-teste',
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);

        $user->assignRole(Role::ADMIN_GERENTE);

        $this->assertTrue($user->company->is($company));
        $this->assertTrue($user->hasRole(Role::ADMIN_GERENTE));
        $this->assertTrue($user->hasPermissionTo('users.view'));
        $this->assertFalse($user->hasPermissionTo('roles.manage'));
    }

    public function test_super_admin_has_all_default_permissions(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();

        $user->assignRole(Role::SUPER_ADMIN);

        foreach (array_keys(Permission::defaults()) as $permission) {
            $this->assertTrue($user->hasPermissionTo($permission));
        }
    }
}
