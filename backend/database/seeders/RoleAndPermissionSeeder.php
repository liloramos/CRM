<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect(Permission::defaults())
            ->mapWithKeys(fn (string $label, string $name): array => [
                $name => Permission::query()->updateOrCreate(
                    ['name' => $name],
                    ['label' => $label],
                ),
            ]);

        foreach (Role::defaults() as $name => $label) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                ['label' => $label],
            );

            $rolePermissionIds = collect(Role::defaultPermissions()[$name] ?? [])
                ->map(fn (string $permissionName): int => $permissions[$permissionName]->id)
                ->all();

            $role->permissions()->sync($rolePermissionIds);
        }
    }
}
