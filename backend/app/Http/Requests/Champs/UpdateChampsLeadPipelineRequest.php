<?php

namespace App\Http\Requests\Champs;

use App\Champs\Enums\ChampsLeadPriority;
use App\Champs\Enums\ChampsLeadStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateChampsLeadPipelineRequest extends FormRequest
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
            'pipeline_stage' => ['sometimes', 'string', Rule::enum(ChampsLeadStage::class)],
            'priority' => ['sometimes', 'string', Rule::enum(ChampsLeadPriority::class)],
            'company_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('pipeline_stage') && ! $this->has('priority')) {
                $validator->errors()->add('pipeline_stage', 'Informe uma etapa ou prioridade.');
            }
        });
    }
}
