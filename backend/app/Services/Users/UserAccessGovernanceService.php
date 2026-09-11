<?php

namespace App\Services\Users;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class UserAccessGovernanceService
{
    /**
     * @return list<Role>
     */
    public function assignableRoles(User $actor): array
    {
        return Role::query()
            ->whereIn('name', array_keys(Role::defaults()))
            ->with('permissions:id,name')
            ->get()
            ->filter(fn (Role $role): bool => Role::authorityLevel($role->name) <= $actor->authorityLevel())
            ->sortByDesc(fn (Role $role): int => Role::authorityLevel($role->name))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function delegablePermissions(User $actor): array
    {
        return array_values(array_intersect($actor->permissionNames(), Permission::delegable()));
    }

    public function assertTargetManageable(User $actor, User $target): void
    {
        abort_if($target->authorityLevel() > $actor->authorityLevel(), Response::HTTP_FORBIDDEN);
    }

    /**
     * @param  list<string>|null  $requestedPermissions
     * @return list<string>
     */
    public function authorizeAccessChange(
        User $actor,
        ?User $target,
        string $roleName,
        ?array $requestedPermissions,
        bool $isActive,
    ): array {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        abort_if(Role::authorityLevel($role->name) > $actor->authorityLevel(), Response::HTTP_FORBIDDEN);
        abort_if(Role::isProtected($role->name) && ! $actor->hasRole(Role::SUPER_ADMIN), Response::HTTP_FORBIDDEN);

        if ($target instanceof User) {
            $this->assertTargetManageable($actor, $target);
        }

        $sameRole = $target?->primaryRoleName() === $role->name;
        $desired = $requestedPermissions
            ?? ($sameRole ? $target->permissionNames() : $role->permissions()->pluck('name')->all());
        $desired = array_values(array_unique($desired));
        $unknown = array_diff($desired, array_keys(Permission::defaults()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Uma ou mais permissões informadas são inválidas.'],
            ]);
        }

        if (! Role::isProtected($role->name) && array_intersect($desired, Permission::PROTECTED_PERMISSIONS) !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Permissões protegidas pertencem exclusivamente ao perfil DEV.'],
            ]);
        }

        $current = $target?->permissionNames() ?? [];
        $changesOwnAccess = $target?->is($actor)
            && ($target->primaryRoleName() !== $role->name
                || $isActive !== (bool) $target->is_active
                || array_diff($current, $desired) !== []
                || array_diff($desired, $current) !== []);

        if ($changesOwnAccess) {
            throw ValidationException::withMessages([
                'role' => ['Seu próprio acesso e status não podem ser alterados nesta tela.'],
            ]);
        }

        $changed = array_values(array_unique(array_merge(
            array_diff($current, $desired),
            array_diff($desired, $current),
        )));
        $actorCeiling = $actor->hasRole(Role::SUPER_ADMIN)
            ? array_keys(Permission::defaults())
            : $this->delegablePermissions($actor);

        if (array_diff($changed, $actorCeiling) !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Você só pode conceder ou remover permissões que possui e pode delegar.'],
            ]);
        }

        $this->assertLastTenantDevPreserved($target, $role->name, $isActive);

        return Role::isProtected($role->name)
            ? array_keys(Permission::defaults())
            : $desired;
    }

    /**
     * @param  list<string>  $effectivePermissions
     */
    public function syncAccess(User $user, string $roleName, array $effectivePermissions): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->sync([$role->id]);

        if (Role::isProtected($roleName)) {
            $user->permissionOverrides()->detach();

            return;
        }

        $baseline = $role->permissions()->pluck('name')->all();
        $overrideNames = array_values(array_unique(array_merge(
            array_diff($baseline, $effectivePermissions),
            array_diff($effectivePermissions, $baseline),
        )));
        $permissions = Permission::query()->whereIn('name', $overrideNames)->get();
        $overrides = [];

        foreach ($permissions as $permission) {
            $overrides[$permission->id] = [
                'granted' => in_array($permission->name, $effectivePermissions, true),
            ];
        }

        $user->permissionOverrides()->sync($overrides);
    }

    private function assertLastTenantDevPreserved(?User $target, string $newRole, bool $isActive): void
    {
        if (! $target instanceof User
            || $target->company_id === null
            || ! $target->hasRole(Role::SUPER_ADMIN)
            || ($newRole === Role::SUPER_ADMIN && $isActive)) {
            return;
        }

        $anotherActiveDevExists = User::query()
            ->where('company_id', $target->company_id)
            ->where('is_active', true)
            ->whereKeyNot($target->id)
            ->whereHas('roles', fn ($query) => $query->where('name', Role::SUPER_ADMIN))
            ->exists();

        if (! $anotherActiveDevExists) {
            throw ValidationException::withMessages([
                'role' => ['A empresa deve manter ao menos um usuário DEV ativo.'],
            ]);
        }
    }
}
