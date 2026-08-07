<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AppSessionController extends Controller
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json([
            'csrf_token' => csrf_token(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->json([
                'authenticated' => false,
                'user' => null,
            ], 401);
        }

        return response()->json([
            'authenticated' => true,
            'user' => $this->userPayload($request, $request->user()),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);

        if (! Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais invalidas para este ambiente.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            'authenticated' => true,
            'user' => $this->userPayload($request, $request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'authenticated' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(Request $request, ?User $user): array
    {
        abort_unless($user, 401);

        return (new AuthenticatedUserResource($user))->resolve($request);
    }
}
