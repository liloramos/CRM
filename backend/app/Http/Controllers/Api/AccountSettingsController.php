<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\UserIdentityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AccountSettingsController extends Controller
{
    public function __construct(private readonly UserIdentityPresenter $identityPresenter) {}

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profileData($request->user()->loadMissing('company.restaurantProfile', 'roles.permissions'))]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'phone' => ['nullable', 'string', 'max:30'], 'job_title' => ['nullable', 'string', 'max:100'], 'current_password' => ['nullable', 'required_with:password', 'current_password:web'], 'password' => ['nullable', 'string', 'min:8', 'confirmed'], 'role' => ['prohibited'], 'roles' => ['prohibited'], 'permissions' => ['prohibited'], 'company_id' => ['prohibited'], 'can_be_seller' => ['prohibited'], 'is_active' => ['prohibited']]);
        $user->fill(collect($data)->only(['name', 'email', 'phone', 'job_title'])->all());
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return response()->json(['data' => $this->profileData($user->fresh()->loadMissing('company.restaurantProfile', 'roles.permissions'))]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $user = $request->user();
        $old = $user->avatar_path;
        $user->avatar_path = $request->file('avatar')->store("company-identity/{$user->company_id}/avatars", 'local');
        $user->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['data' => $this->profileData($user->fresh()->loadMissing('company.restaurantProfile', 'roles.permissions'))]);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $old = $user->avatar_path;
        $user->avatar_path = null;
        $user->save();

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['data' => $this->profileData($user->fresh()->loadMissing('company.restaurantProfile', 'roles.permissions'))]);
    }

    public function company(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->companyData($request->user())]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        $this->authorizeCompany($request->user());
        $company = $request->user()->company;
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'display_name' => ['required', 'string', 'max:120'], 'legal_name' => ['nullable', 'string', 'max:160'], 'responsible_name' => ['nullable', 'string', 'max:120'], 'contact_email' => ['nullable', 'email', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:30'], 'timezone' => ['required', 'timezone'], 'address_line' => ['nullable', 'string', 'max:160'], 'address_number' => ['nullable', 'string', 'max:30'], 'address_complement' => ['nullable', 'string', 'max:100'], 'district' => ['nullable', 'string', 'max:100'], 'city' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'string', 'max:2'], 'postal_code' => ['nullable', 'string', 'max:16']]);
        $company->update(['name' => $data['name']]);
        $company->setting()->updateOrCreate([], ['timezone' => $data['timezone']]);
        $company->restaurantProfile()->updateOrCreate([], collect($data)->except(['name', 'timezone'])->all());

        return response()->json(['data' => $this->companyData($request->user())]);
    }

    public function updateCompanyLogo(Request $request): JsonResponse
    {
        $this->authorizeCompany($request->user());
        $request->validate(['logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $company = $request->user()->company;
        $profile = $company->restaurantProfile()->firstOrCreate(['company_id' => $company->id], ['display_name' => $company->name]);
        $old = $profile->logo_path;
        $profile->logo_path = $request->file('logo')->store("company-identity/{$company->id}/logo", 'local');
        $profile->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['data' => $this->companyData($request->user())]);
    }

    public function companyLogo(Request $request): mixed
    {
        $path = $request->user()->company?->restaurantProfile?->logo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    private function authorizeCompany(User $user): void
    {
        abort_unless($user->hasAnyRole([Role::SUPER_ADMIN, Role::ADMIN_GERENTE]), 403);
    }

    private function profileData(User $user): array
    {
        return [...$this->identityPresenter->present($user), 'companyName' => $user->company?->name, 'roles' => $user->roleNames(), 'permissions' => $user->permissionNames()];
    }

    private function companyData(User $user): array
    {
        $company = $user->company->loadMissing('restaurantProfile', 'setting');
        $p = $company->restaurantProfile;

        return ['name' => $company->name, 'displayName' => $p?->display_name, 'legalName' => $p?->legal_name, 'responsibleName' => $p?->responsible_name, 'contactEmail' => $p?->contact_email, 'contactPhone' => $p?->contact_phone, 'timezone' => $company->setting?->timezone, 'addressLine' => $p?->address_line, 'addressNumber' => $p?->address_number, 'addressComplement' => $p?->address_complement, 'district' => $p?->district, 'city' => $p?->city, 'state' => $p?->state, 'postalCode' => $p?->postal_code, 'logoUrl' => $p?->logo_path ? '/api/app/account/company/logo' : null];
    }
}
