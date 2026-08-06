<?php

namespace App\Http\Requests\Champs;

use Illuminate\Foundation\Http\FormRequest;

class ChampsLeadArchiveRequest extends FormRequest
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
            'company_id' => ['prohibited'],
        ];
    }
}
