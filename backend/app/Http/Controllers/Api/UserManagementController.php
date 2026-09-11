<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\UserAccessGovernanceService;
use App\Support\UserIdentityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    use ResolvesOperationalCompany;

    public function __construct(
        private readonly UserAccessGovernanceService $governance,
        private readonly UserIdentityPresenter $identityPresenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $actor = $request->user();
        $roles = $this->governance->assignableRoles($actor);
        $delegable = $this->governance->delegablePermissions($actor);

        $users = User::query()
            ->where('company_id', $company->id)
            ->with(['company', 'roles.permissions', 'permissionOverrides'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => [
            'users' => $users->map(fn (User $user): array => $this->present($user, $actor)),
            'available_roles' => collect($roles)->map(fn (Role $role): array => [
                'name' => $role->name,
                'label' => Role::defaults()[$role->name] ?? $role->label,
                'permissions' => $role->permissions->pluck('name')->values(),
                'protected' => Role::isProtected($role->name),
            ]),
            'available_permissions' => collect(Permission::defaults())
                ->only($delegable)
                ->map(fn (string $label, string $name): array => ['name' => $name, 'label' => $label])
                ->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $actor = $request->user();
        $data = $this->validateData($request);
        $isActive = (bool) ($data['is_active'] ?? true);
        $permissions = $this->governance->authorizeAccessChange(
            $actor,
            null,
            $data['role'],
            $data['permissions'] ?? null,
            $isActive,
        );

        $user = DB::transaction(function () use ($company, $data, $isActive, $permissions): User {
            $user = User::query()->create([
                'company_id' => $company->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'can_be_seller' => $data['can_be_seller'] ?? false,
                'is_active' => $isActive,
                'password' => Hash::make($data['password']),
            ]);
            $this->governance->syncAccess($user, $data['role'], $permissions);

            return $user;
        });

        return response()->json([
            'data' => $this->present($user->load('company', 'roles.permissions', 'permissionOverrides'), $actor),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $actor = $request->user();
        $this->assertTenant($user, $company->id);
        $this->governance->assertTargetManageable($actor, $user);

        $data = $this->validateData($request, $user->id, false);
        $roleName = $data['role'] ?? $user->primaryRoleName() ?? Role::ATENDENTE;
        $isActive = (bool) ($data['is_active'] ?? $user->is_active);
        $permissions = $this->governance->authorizeAccessChange(
            $actor,
            $user,
            $roleName,
            array_key_exists('permissions', $data) ? $data['permissions'] : null,
            $isActive,
        );

        DB::transaction(function () use ($user, $data, $roleName, $isActive, $permissions): void {
            $user->fill(collect($data)->only([
                'name',
                'email',
                'phone',
                'job_title',
                'can_be_seller',
            ])->all());
            $user->is_active = $isActive;
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();
            $this->governance->syncAccess($user, $roleName, $permissions);
        });

        return response()->json([
            'data' => $this->present($user->fresh()->load('company', 'roles.permissions', 'permissionOverrides'), $actor),
        ]);
    }

    public function updateAvatar(Request $request, User $user): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $actor = $request->user();
        $this->assertTenant($user, $company->id);
        $this->governance->assertTargetManageable($actor, $user);
        $request->validate(['avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        $old = $user->avatar_path;
        $user->avatar_path = $request->file('avatar')->store("company-identity/{$company->id}/avatars", 'local');
        $user->save();

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json([
            'data' => $this->present($user->fresh()->load('company', 'roles.permissions', 'permissionOverrides'), $actor),
        ]);
    }

    public function removeAvatar(Request $request, User $user): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $actor = $request->user();
        $this->assertTenant($user, $company->id);
        $this->governance->assertTargetManageable($actor, $user);

        $old = $user->avatar_path;
        $user->avatar_path = null;
        $user->save();

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json([
            'data' => $this->present($user->fresh()->load('company', 'roles.permissions', 'permissionOverrides'), $actor),
        ]);
    }

    private function validateData(Request $request, ?int $id = null, bool $password = true): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'can_be_seller' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['required', Rule::in(array_keys(Role::defaults()))],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(Permission::defaults()))],
            'password' => $password ? ['required', 'string', 'min:8', 'confirmed'] : ['prohibited'],
        ]);
    }

    private function assertTenant(User $user, int $companyId): void
    {
        abort_unless((int) $user->company_id === $companyId, 404);
    }

    private function present(User $user, User $actor): array
    {
        return [
            'id' => (string) $user->id,
            ...$this->identityPresenter->present($user),
            'canBeSeller' => (bool) $user->can_be_seller,
            'isActive' => (bool) $user->is_active,
            'roles' => $user->roleNames(),
            'permissions' => $user->permissionNames(),
            'companyName' => $user->company?->name,
            'canEdit' => $user->authorityLevel() <= $actor->authorityLevel(),
            'canEditAccess' => ! $user->is($actor) && $user->authorityLevel() <= $actor->authorityLevel(),
        ];
    }
}
