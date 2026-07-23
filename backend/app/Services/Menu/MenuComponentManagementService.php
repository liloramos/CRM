<?php

namespace App\Services\Menu;

use App\Enums\MenuComponentType;
use App\Models\Company;
use App\Models\MenuComponent;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MenuComponentManagementService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createComponent(Company $company, array $attributes): array
    {
        $slug = Str::slug($attributes['name']);

        if (MenuComponent::query()->where('company_id', $company->id)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'name' => ['Ja existe um componente com este nome para a empresa.'],
            ]);
        }

        $component = MenuComponent::query()->create([
            'company_id' => $company->id,
            'name' => $attributes['name'],
            'slug' => $slug,
            'component_type' => MenuComponentType::from($attributes['component_type']),
            'description' => $attributes['description'] ?? null,
            'is_active' => $attributes['is_active'],
            'display_order' => $attributes['display_order'],
        ]);

        return $this->componentPayload($component);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function updateComponent(Company $company, MenuComponent $component, array $attributes): array
    {
        abort_unless((int) $component->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        $component->fill([
            'name' => $attributes['name'],
            'component_type' => MenuComponentType::from($attributes['component_type']),
            'description' => $attributes['description'] ?? null,
            'is_active' => $attributes['is_active'],
            'display_order' => $attributes['display_order'],
        ]);
        $component->save();

        return $this->componentPayload($component->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function componentPayload(MenuComponent $component): array
    {
        return [
            'id' => $component->id,
            'slug' => $component->slug,
            'name' => $component->name,
            'component_type' => $component->component_type->value,
            'description' => $component->description,
            'is_active' => (bool) $component->is_active,
            'display_order' => $component->display_order,
        ];
    }
}
