<?php

namespace App\Http\Requests\Champs;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChampsLeadFollowUpRequest extends FormRequest
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
            'next_follow_up_at' => ['present', 'nullable', 'date'],
            'company_id' => ['prohibited'],
        ];
    }
}
