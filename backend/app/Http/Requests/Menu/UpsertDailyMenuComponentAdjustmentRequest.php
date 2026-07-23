<?php

namespace App\Http\Requests\Menu;

use App\Enums\DailyMenuAdjustmentAction;
use App\Enums\WeeklyMenuSection;
use App\Http\Requests\Menu\Concerns\ParsesMenuDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertDailyMenuComponentAdjustmentRequest extends FormRequest
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
            'action' => ['required', Rule::in(array_column(DailyMenuAdjustmentAction::cases(), 'value'))],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function section(): WeeklyMenuSection
    {
        return WeeklyMenuSection::from($this->validated('section'));
    }

    public function action(): DailyMenuAdjustmentAction
    {
        return DailyMenuAdjustmentAction::from($this->validated('action'));
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
