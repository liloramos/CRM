<?php

namespace App\Champs\Support;

final class InstagramUsername
{
    public static function normalize(string $value): ?string
    {
        $username = mb_strtolower(ltrim(trim(rawurldecode($value)), '@'));

        if ($username === '' || mb_strlen($username) > 30) {
            return null;
        }

        if (! preg_match('/^[a-z0-9._]+$/', $username)) {
            return null;
        }

        if (str_contains($username, '..') || str_starts_with($username, '.') || str_ends_with($username, '.')) {
            return null;
        }

        return $username;
    }
}
