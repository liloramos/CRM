<?php

namespace App\Http\Requests\Menu;

use App\Enums\MenuAvailabilityStatus;
use App\Http\Requests\Menu\Concerns\ParsesMenuDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComponentAvailabilityRequest extends FormRequest
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
            'date' => $this->dateRules(),
            'status' => ['required', Rule::in(array_column(MenuAvailabilityStatus::cases(), 'value'))],
            'reason' => ['nullable', 'string', 'max:500'],
            'replacement_component_id' => ['nullable', 'integer'],
        ];
    }

    public function status(): MenuAvailabilityStatus
    {
        return MenuAvailabilityStatus::from($this->validated('status'));
    }

    /**
     * @return array<int, ValidationRule|string|\Closure>
     */
    private function dateRules(): array
    {
        return [
            'required',
            'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! $this->validMenuDate($value)) {
                    $fail('A data deve estar no formato YYYY-MM-DD.');
                }
            },
        ];
    }
}
