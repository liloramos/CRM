<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Projects the canonical catalog/configuration into the small set of facts a
 * customer needs to choose a product. It does not infer business rules.
 */
final class CopilotProductDecisionFacts
{
    /** @param array<string,mixed> $product @return array<string,mixed> */
    public function fromProduct(array $product): array
    {
        $configuration = (array) data_get($product, 'resolved_configuration.static_configuration', []);
        $rule = (string) ($product['rule'] ?? data_get($configuration, 'menu_rule_code', ''));
        $groups = collect((array) data_get($configuration, 'groups', $product['groups'] ?? []));
        $meatGroup = $groups->firstWhere('code', 'carne');
        $meatSelection = (array) data_get($product, 'resolved_configuration.meat_selection', []);
        $minMeats = (int) ($meatSelection['min'] ?? data_get($meatGroup, 'min_choices', 0));
        $maxMeats = (int) ($meatSelection['max'] ?? data_get($meatGroup, 'max_choices', 0));
        $maxMeats = max($minMeats, $maxMeats);
        $fixedComponents = $groups
            ->filter(fn (mixed $group): bool => is_array($group) && ($group['selection_mode'] ?? null) === 'fixed')
            ->flatMap(fn (array $group): array => (array) ($group['component_options'] ?? $group['options'] ?? []))
            ->filter(fn (mixed $option): bool => is_array($option) && (bool) ($option['available'] ?? true))
            ->map(fn (array $option): string => trim((string) ($option['display_name'] ?? $option['name'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $additionalMeatPrice = (int) data_get(
            $configuration,
            'meat_configuration.traditional.additional_meat_price_cents',
            data_get($product, 'composition_rules.standard_meat_additional_price_cents', 0),
        );
        $additions = collect((array) data_get($configuration, 'additions', []))
            ->filter(fn (mixed $addition): bool => is_array($addition) && ($addition['enabled'] ?? false) === true)
            ->map(fn (array $addition): array => [
                'code' => (string) ($addition['code'] ?? ''),
                'name' => (string) ($addition['name'] ?? ''),
                'price_cents' => (int) ($addition['price_cents'] ?? $addition['price_delta_cents'] ?? 0),
                'max_quantity' => (int) ($addition['max_quantity'] ?? 0),
            ])
            ->values()
            ->all();

        return [
            'product_id' => (int) ($product['id'] ?? 0),
            'slug' => (string) ($product['slug'] ?? ''),
            'name' => (string) ($product['name'] ?? 'Produto'),
            'price_cents' => (int) data_get($product, 'resolved_configuration.product.base_price_cents', $product['base_price_cents'] ?? 0),
            'available' => (bool) data_get($product, 'resolved_configuration.product.availability.available', true),
            'assembly_mode' => str_ends_with($rule, '_casa') ? 'house' : (str_ends_with($rule, '_tradicional') ? 'free' : 'standard'),
            'description' => Str::of((string) ($product['description'] ?? data_get($configuration, 'description', '')))->squish()->toString(),
            'fixed_components' => $fixedComponents,
            'meat_allowance' => [
                'min' => $minMeats,
                'included_max' => $maxMeats,
                'additional_unit_price_cents' => $additionalMeatPrice,
            ],
            'additions' => $additions,
        ];
    }

    /** @param array<string,mixed> $facts */
    public function summary(array $facts): string
    {
        $parts = collect();
        $description = trim((string) ($facts['description'] ?? ''));
        if ($description !== '') {
            $parts->push($description);
        }

        if (($facts['assembly_mode'] ?? null) === 'house') {
            $components = collect((array) ($facts['fixed_components'] ?? []))->take(5)->implode(', ');
            $parts->push('Casa: composição definida pelo restaurante'.($components === '' ? '.' : ' — '.$components.'.'));
        } elseif (($facts['assembly_mode'] ?? null) === 'free') {
            $parts->push('Livre: você monta com o buffet disponível no dia.');
        }

        $included = (int) data_get($facts, 'meat_allowance.included_max', 0);
        if ($included > 0) {
            $parts->push($included === 1 ? 'Inclui 1 tipo de carne.' : "Inclui até {$included} tipos de carne.");
        }
        $additional = (int) data_get($facts, 'meat_allowance.additional_unit_price_cents', 0);
        if ($additional > 0) {
            $parts->push('Carne padrão adicional: '.$this->money($additional).'.');
        }
        foreach ((array) ($facts['additions'] ?? []) as $addition) {
            if (! is_array($addition) || (int) ($addition['price_cents'] ?? 0) < 1) {
                continue;
            }
            $parts->push(trim((string) ($addition['name'] ?? 'Adicional')).': '.$this->money((int) $addition['price_cents']).'.');
        }

        return $parts->filter()->unique()->implode(' ');
    }

    private function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
