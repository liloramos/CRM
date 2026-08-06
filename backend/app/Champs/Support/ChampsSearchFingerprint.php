<?php

namespace App\Champs\Support;

use Illuminate\Support\Str;

final class ChampsSearchFingerprint
{
    public static function make(
        string $niche,
        string $city,
        string $state,
        string $provider,
    ): string {
        $parts = array_map(
            self::normalize(...),
            [$niche, $city, $state, $provider],
        );

        return hash('sha256', implode("\x1F", $parts));
    }

    private static function normalize(string $value): string
    {
        return Str::lower(Str::squish(Str::ascii($value)));
    }
}
