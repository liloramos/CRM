<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class DestroyCompanyLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->company_id !== null
            && $user->hasPermissionTo('settings.manage');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'logo_path' => ['prohibited'],
            'logo_url' => ['prohibited'],
        ];
    }
}
