<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;

class AccountSecurityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roleNames(),
            ],
            'two_factor' => [
                'available' => Features::canManageTwoFactorAuthentication(),
                'enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'confirmation_required' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
            ],
            'session' => ['active' => true],
        ]]);
    }
}
