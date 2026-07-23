<?php

namespace App\Http\Requests\Menu\Concerns;

use Carbon\CarbonImmutable;

trait ParsesMenuDate
{
    public function availabilityDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('date'));
    }

    private function validMenuDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        $errors = CarbonImmutable::getLastErrors();

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
