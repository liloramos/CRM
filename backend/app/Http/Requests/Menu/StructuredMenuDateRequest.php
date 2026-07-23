<?php

namespace App\Http\Requests\Menu;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StructuredMenuDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string|\Closure>>
     */
    public function rules(): array
    {
        return [
            'date' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! $this->isValidDate($value)) {
                        $fail('A data deve estar no formato YYYY-MM-DD.');
                    }
                },
            ],
        ];
    }

    public function operationalDate(string $timezone): CarbonImmutable
    {
        $date = $this->validated('date');

        if (! is_string($date) || $date === '') {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    }

    private function isValidDate(string $date): bool
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
