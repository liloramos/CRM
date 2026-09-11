<?php

namespace App\Services\CounterSales;

use App\Models\Company;
use App\Models\ProductGroupComponent;
use DomainException;

final class CounterSaleBeefAdditionalResolver
{
    /**
     * @return array{name: string, price_cents: int, max_quantity: int, menu_component_id: int, source_link_ids: list<int>}
     */
    public function resolve(Company $company): array
    {
        $links = ProductGroupComponent::query()
            ->with(['component', 'group.product'])
            ->where('is_active', true)
            ->where('requires_confirmation', false)
            ->whereHas('component', fn ($query) => $query
                ->where('company_id', $company->id)
                ->where('slug', 'bife'))
            ->whereHas('group', fn ($query) => $query
                ->where('company_id', $company->id)
                ->where('code', 'bife_adicional')
                ->whereHas('product', fn ($products) => $products
                    ->whereIn('menu_rule_code', ['n8_tradicional', 'n9_tradicional'])))
            ->get();

        if ($links->isEmpty()) {
            throw new DomainException('A regra canônica de bife adicional não está configurada.');
        }

        $prices = $links->pluck('price_delta_cents')->map(fn ($price): int => (int) $price)->unique()->values();
        $maximums = $links->map(fn (ProductGroupComponent $link): int => (int) ($link->group?->max_quantity ?? 0))->unique()->values();

        if ($prices->count() !== 1 || (int) $prices->first() <= 0 || $maximums->count() !== 1 || (int) $maximums->first() !== 1) {
            throw new DomainException('A configuração canônica de bife adicional está inconsistente entre os produtos.');
        }

        /** @var ProductGroupComponent $source */
        $source = $links->sortBy('id')->first();

        return [
            'name' => (string) ($source->group?->label ?: 'Bife adicional'),
            'price_cents' => (int) $prices->first(),
            'max_quantity' => (int) $maximums->first(),
            'menu_component_id' => (int) $source->menu_component_id,
            'source_link_ids' => $links->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
        ];
    }
}
