<?php

namespace App\Services\Menu;

use App\Models\MenuComponent;

class MenuComponentPresentation
{
    /**
     * @return array<string, mixed>
     */
    public function summary(MenuComponent $component): array
    {
        return [
            'id' => $component->id,
            'slug' => $component->slug,
            'name' => $component->name,
            'display_name' => $this->displayName($component),
            'supporting_name' => $this->supportingName($component),
            'search_aliases' => $this->searchAliases($component),
            'component_type' => $component->component_type->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function searchAliases(MenuComponent $component): array
    {
        return match ($component->slug) {
            'file-de-peixe' => [
                'Peixe frito',
                'Peixe',
                'File de peixe',
                'File de peixe empanado',
            ],
            'bisteca-de-porco-na-chapa' => [
                'Bisteca',
                'Bisteca de porco na chapa',
                'Bisteca suina',
                'Porco na chapa',
            ],
            default => [$component->name],
        };
    }

    private function displayName(MenuComponent $component): string
    {
        return match ($component->slug) {
            'file-de-peixe' => 'Peixe frito',
            default => $component->name,
        };
    }

    private function supportingName(MenuComponent $component): ?string
    {
        return match ($component->slug) {
            'file-de-peixe' => $component->name,
            default => $component->description,
        };
    }
}
