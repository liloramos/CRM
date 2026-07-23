<?php

namespace App\Http\Requests\Menu;

use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWeeklyMenuComponentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'service_day' => ['required', Rule::in(array_column(WeeklyMenuServiceDay::cases(), 'value'))],
            'section' => ['required', Rule::in(array_column(WeeklyMenuSection::cases(), 'value'))],
            'display_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function serviceDay(): WeeklyMenuServiceDay
    {
        return WeeklyMenuServiceDay::from($this->validated('service_day'));
    }

    public function section(): WeeklyMenuSection
    {
        return WeeklyMenuSection::from($this->validated('section'));
    }
}
