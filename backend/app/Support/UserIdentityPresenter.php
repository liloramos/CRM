<?php

namespace App\Support;

use App\Models\User;

class UserIdentityPresenter
{
    /**
     * @return array{name: string, email: string, phone: ?string, jobTitle: ?string, avatarUrl: ?string}
     */
    public function present(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'jobTitle' => $user->job_title,
            'avatarUrl' => $this->avatarUrl($user),
        ];
    }

    public function avatarUrl(User $user): ?string
    {
        if (! $user->avatar_path) {
            return null;
        }

        $version = substr(hash('sha256', $user->avatar_path), 0, 12);

        return "/api/app/users/{$user->getKey()}/avatar?v={$version}";
    }
}
