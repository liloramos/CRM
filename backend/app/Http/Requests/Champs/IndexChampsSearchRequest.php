<?php

namespace App\Http\Requests\Champs;

use App\Models\ChampsSearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexChampsSearchRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(ChampsSearch::STATUSES)],
            'state' => ['sometimes', 'string', 'size:2'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('state'))) {
            $this->merge(['state' => mb_strtoupper(trim((string) $this->input('state')))]);
        }
    }
}
