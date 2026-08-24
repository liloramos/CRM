<?php

namespace App\Services\Menu;

use App\Enums\MenuComponentType;
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
        if ($component->component_type === MenuComponentType::JuiceFlavor) {
            return array_values(array_unique([
                $component->name,
                $this->displayName($component),
                "Suco de {$component->name}",
            ]));
        }

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
        if ($component->component_type === MenuComponentType::JuiceFlavor) {
            return "Suco de {$component->name} 500ml";
        }

        return match ($component->slug) {
            'file-de-peixe' => 'Peixe frito',
            default => $component->name,
        };
    }

    private function supportingName(MenuComponent $component): ?string
    {
        if ($component->component_type === MenuComponentType::JuiceFlavor) {
            return 'Sabor de suco';
        }

        return match ($component->slug) {
            'file-de-peixe' => $component->name,
            default => $component->description,
        };
    }
}
