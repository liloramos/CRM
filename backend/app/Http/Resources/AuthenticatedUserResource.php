<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('company', 'roles.permissions');

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'avatar_url' => $this->resource->avatarUrl(),
            'company' => $this->company ? [
                'id' => (string) $this->company->id,
                'name' => $this->company->name,
                'slug' => $this->company->slug,
            ] : null,
            'roles' => $this->resource->roleNames(),
            'permissions' => $this->resource->permissionNames(),
        ];
    }
}
