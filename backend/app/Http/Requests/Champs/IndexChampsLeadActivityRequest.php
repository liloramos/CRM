<?php

namespace App\Http\Requests\Champs;

use Illuminate\Foundation\Http\FormRequest;

class IndexChampsLeadActivityRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'company_id' => ['prohibited'],
        ];
    }
}
