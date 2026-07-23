<?php

namespace App\Http\Requests\Menu;

use App\Enums\ProductServiceDay;
use App\Http\Requests\Menu\Concerns\ParsesMenuDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuProductRequest extends FormRequest
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
            'date' => ['nullable', 'string', $this->dateValidator()],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'is_available_by_default' => ['required', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'service_days' => ['required', 'array'],
            'service_days.*' => ['required', Rule::in(array_column(ProductServiceDay::cases(), 'value'))],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function serviceDays(): array
    {
        return collect($this->validated('service_days'))
            ->unique()
            ->values()
            ->all();
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
