<?php

namespace App\Http\Requests\Champs;

use App\Champs\Services\ChampsLeadScoringService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexChampsLeadRequest extends FormRequest
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
            'search_id' => ['sometimes', 'integer', 'min:1'],
            'state' => ['sometimes', 'string', 'size:2'],
            'minimum_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'qualified' => ['sometimes', 'boolean'],
            'classification' => [
                'sometimes',
                'string',
                Rule::in(ChampsLeadScoringService::CLASSIFICATIONS),
            ],
            'query' => ['sometimes', 'string', 'max:120'],
            'order_by' => ['sometimes', 'string', Rule::in(['score', 'name', 'rating', 'created_at'])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('state'))) {
            $this->merge(['state' => mb_strtoupper(trim((string) $this->input('state')))]);
        }

        if (is_string($this->input('query'))) {
            $this->merge(['query' => trim((string) $this->input('query'))]);
        }

        if (is_string($this->input('qualified'))) {
            $qualified = filter_var(
                $this->input('qualified'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );

            if ($qualified !== null) {
                $this->merge(['qualified' => $qualified]);
            }
        }
    }
}
