<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class CompanyLogoRequest extends FormRequest
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
            'logo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
            'company_id' => ['prohibited'],
            'logo_path' => ['prohibited'],
            'logo_url' => ['prohibited'],
        ];
    }
}
