<?php

namespace App\Http\Requests\Menu;

use App\Http\Requests\Menu\Concerns\ParsesMenuDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductGroupComponentRequest extends FormRequest
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
            'resolution' => ['required', Rule::in(['offered', 'not_offered'])],
            'final_price_cents' => ['nullable', 'integer', 'min:0', 'required_if:resolution,offered'],
        ];
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
