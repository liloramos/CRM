<?php

namespace App\Http\Requests\Champs;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChampsLeadAssignmentRequest extends FormRequest
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
        $companyId = (int) $this->user()?->company_id;

        return [
            'assigned_user_id' => [
                'present',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(User::class, 'id')->where(
                    fn (Builder $query): Builder => $query->where('company_id', $companyId),
                ),
            ],
            'company_id' => ['prohibited'],
        ];
    }
}
