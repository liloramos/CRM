<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class UserAvatarController extends Controller
{
    public function __invoke(Request $request, User $user): mixed
    {
        $actor = $request->user();
        $sameTenant = $actor->company_id !== null
            && (int) $actor->company_id === (int) $user->company_id;

        abort_unless($actor->is($user) || $sameTenant || $actor->hasRole(Role::SUPER_ADMIN), Response::HTTP_NOT_FOUND);
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), Response::HTTP_NOT_FOUND);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
