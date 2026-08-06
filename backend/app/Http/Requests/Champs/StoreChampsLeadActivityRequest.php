<?php

namespace App\Http\Requests\Champs;

use App\Champs\Enums\ChampsLeadActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChampsLeadActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ChampsLeadActivityType::userCreatableValues())],
            'description' => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'metadata' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('type') === ChampsLeadActivityType::Note->value && trim((string) $this->input('description')) === '') {
                $validator->errors()->add('description', 'A nota não pode ficar vazia.');
            }
        });
    }
}
