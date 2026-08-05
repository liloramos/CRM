<?php

namespace App\Http\Requests\Champs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreChampsSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $maxResults = max(1, (int) config('champs.google_places.max_results', 20));

        return [
            'name' => ['nullable', 'string', 'max:120'],
            'niche' => ['required', 'string', 'min:2', 'max:120'],
            'city' => ['required', 'string', 'min:2', 'max:120'],
            'state' => ['required', 'string', 'size:2'],
            'limit' => ['required', 'integer', 'min:1', "max:{$maxResults}"],
            'minimum_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'api_key' => ['prohibited'],
            'google_places_api_key' => ['prohibited'],
            'credentials' => ['prohibited'],
            'headers' => ['prohibited'],
            'token' => ['prohibited'],
            'access_token' => ['prohibited'],
            'password' => ['prohibited'],
            'secret' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'niche', 'city'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Str::squish((string) $this->input($field));
            }
        }

        if (is_string($this->input('state'))) {
            $normalized['state'] = mb_strtoupper(trim((string) $this->input('state')));
        }

        $this->merge($normalized);
    }
}
