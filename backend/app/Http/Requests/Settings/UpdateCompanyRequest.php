<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->company_id !== null
            && $user->hasPermissionTo('settings.manage');
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'trade_name', 'responsible_name', 'email', 'phone', 'timezone'] as $field) {
            if (! $this->exists($field) || ! is_string($this->input($field))) {
                continue;
            }

            $value = trim($this->string($field)->toString());
            $normalized[$field] = $field !== 'name' && $value === '' ? null : $value;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'responsible_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone'],
            'id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'slug' => ['prohibited'],
            'role' => ['prohibited'],
            'roles' => ['prohibited'],
            'permissions' => ['prohibited'],
            'logo_path' => ['prohibited'],
            'logo_url' => ['prohibited'],
        ];
    }
}
