<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileAvatarRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserProfileController extends Controller
{
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $this->profileResponse($request, $user, 'Perfil atualizado com sucesso.');
    }

    public function updateAvatar(ProfileAvatarRequest $request): JsonResponse
    {
        $user = $request->user();
        $avatar = $request->file('avatar');
        $path = $avatar?->storePublicly("avatars/{$user->id}", 'public');

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'avatar' => ['Não foi possível armazenar a imagem. Tente novamente.'],
            ]);
        }

        $previousPath = $user->avatar_path;

        try {
            $user->forceFill(['avatar_path' => $path])->save();
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);

            throw $exception;
        }

        $this->deleteOwnedAvatar($user, $previousPath);

        return $this->profileResponse($request, $user, 'Foto de perfil atualizada.');
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $previousPath = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();
        $this->deleteOwnedAvatar($user, $previousPath);

        return $this->profileResponse($request, $user, 'Foto de perfil removida.');
    }

    private function deleteOwnedAvatar(User $user, ?string $path): void
    {
        if (! $path || ! Str::startsWith($path, "avatars/{$user->id}/")) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function profileResponse(
        Request $request,
        User $user,
        string $message,
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'user' => (new AuthenticatedUserResource($user))->resolve($request),
        ]);
    }
}
