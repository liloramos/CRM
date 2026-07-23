<?php

namespace App\Http\Requests\Menu;

use App\Enums\WeeklyMenuSection;
use App\Http\Requests\Menu\Concerns\ParsesMenuDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClearDailyMenuComponentAdjustmentRequest extends FormRequest
{
    use ParsesMenuDate;

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
            'date' => ['required', 'string', $this->dateValidator()],
            'section' => ['required', Rule::in(array_column(WeeklyMenuSection::cases(), 'value'))],
        ];
    }

    public function section(): WeeklyMenuSection
    {
        return WeeklyMenuSection::from($this->validated('section'));
    }

    private function dateValidator(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! $this->validMenuDate($value)) {
                $fail('A data deve estar no formato YYYY-MM-DD.');
            }
        };
    }
}
