<?php

namespace App\Http\Requests\Champs;

use App\Champs\Enums\ChampsLeadPriority;
use App\Champs\Enums\ChampsLeadStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSavedChampsLeadRequest extends FormRequest
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
            'query' => ['sometimes', 'string', 'max:120'],
            'pipeline_stage' => ['sometimes', 'string', Rule::enum(ChampsLeadStage::class)],
            'priority' => ['sometimes', 'string', Rule::enum(ChampsLeadPriority::class)],
            'assigned_user_id' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === 'unassigned') {
                    return;
                }

                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
                    $fail('O responsável informado é inválido.');
                }
            }],
            'state' => ['sometimes', 'string', 'size:2'],
            'minimum_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'follow_up' => ['sometimes', 'string', Rule::in(['overdue', 'today', 'upcoming', 'none'])],
            'has_instagram' => ['sometimes', 'boolean'],
            'has_website' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'string', Rule::in(['active', 'only', 'all'])],
            'order_by' => ['sometimes', 'string', Rule::in([
                'updated_at',
                'name',
                'score',
                'next_follow_up_at',
                'priority',
            ])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if (is_string($this->input('query'))) {
            $values['query'] = trim((string) $this->input('query'));
        }

        if (is_string($this->input('state'))) {
            $values['state'] = mb_strtoupper(trim((string) $this->input('state')));
        }

        foreach (['has_instagram', 'has_website'] as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }

            $parsed = filter_var(
                $this->input($field),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );

            if ($parsed !== null) {
                $values[$field] = $parsed;
            }
        }

        if ($values !== []) {
            $this->merge($values);
        }
    }
}
