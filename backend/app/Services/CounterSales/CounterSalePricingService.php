<?php

namespace App\Services\CounterSales;

use App\Enums\ProductSelectionMode;
use App\Models\Company;
use App\Models\Product;
use DomainException;

final class CounterSalePricingService
{
    public function __construct(private readonly CounterSaleBeefAdditionalResolver $beefAdditional) {}

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function itemAttributes(Company $company, Product $product, array $line): array
    {
        $isWeight = data_get($product->metadata, 'pricing_mode') === 'weight';
        $quantity = (int) ($line['quantity'] ?? 1);
        $attributes = [
            'quantity' => $quantity,
            'unit_price_cents' => (int) $product->base_price_cents,
            'selected_components' => $line['selected_components'] ?? null,
            'item_notes' => $line['item_notes'] ?? null,
        ];

        if ($isWeight) {
            $attributes = [
                ...$attributes,
                ...$this->weightAttributes($product, $line, $quantity),
            ];
        } elseif (array_key_exists('weight_grams', $line) && $line['weight_grams'] !== null) {
            throw new DomainException('Peso só pode ser informado para produtos vendidos por kg.');
        }

        $attributes['options'] = $this->additionOptions($company, $product, $line, $isWeight, $quantity);

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function draftItemAttributes(Company $company, Product $product, array $line): array
    {
        $isWeight = data_get($product->metadata, 'pricing_mode') === 'weight';

        if (! $isWeight || ($line['weight_grams'] ?? null) !== null) {
            return $this->itemAttributes($company, $product, $line);
        }

        $quantity = (int) ($line['quantity'] ?? 1);
        $pricePerKg = $this->assertWeightProductConfiguration($product, $quantity);

        return [
            'quantity' => 1,
            'weight_grams' => null,
            'price_per_kg_cents' => $pricePerKg,
            'unit_price_cents' => 0,
            'selected_components' => $line['selected_components'] ?? null,
            'item_notes' => $line['item_notes'] ?? null,
            'options' => $this->additionOptions($company, $product, $line, true, 1),
        ];
    }

    /** @param array<string, mixed> $line @return array<string, int> */
    private function weightAttributes(Product $product, array $line, int $quantity): array
    {
        $pricePerKg = $this->assertWeightProductConfiguration($product, $quantity);

        $weight = $line['weight_grams'] ?? null;
        if (! is_int($weight)) {
            throw new DomainException('Informe o peso em gramas como número inteiro.');
        }

        $maximumWeight = (int) config('chatbotcrm.counter_sales.max_weight_grams', 10000);
        if ($maximumWeight <= 0) {
            throw new DomainException('O limite operacional de peso do Caixa não está configurado corretamente.');
        }

        if ($weight <= 0 || $weight > $maximumWeight) {
            throw new DomainException("O peso deve estar entre 1 e {$maximumWeight} gramas.");
        }

        $subtotal = intdiv(($weight * $pricePerKg) + 500, 1000);
        if ($subtotal <= 0) {
            throw new DomainException('O peso informado não gera um subtotal válido.');
        }

        return [
            'quantity' => 1,
            'weight_grams' => $weight,
            'price_per_kg_cents' => $pricePerKg,
            'unit_price_cents' => $subtotal,
        ];
    }

    private function assertWeightProductConfiguration(Product $product, int $quantity): int
    {
        if (data_get($product->metadata, 'weight_unit') !== 'grams') {
            throw new DomainException('A unidade de peso deste produto não está configurada corretamente.');
        }

        if ($quantity !== 1) {
            throw new DomainException('Cada item vendido por peso deve ser lançado individualmente.');
        }

        $pricePerKg = (int) $product->base_price_cents;
        if ($pricePerKg <= 0) {
            throw new DomainException('A tarifa por kg deste produto não está configurada corretamente.');
        }

        return $pricePerKg;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<array<string, mixed>>
     */
    private function additionOptions(Company $company, Product $product, array $line, bool $isWeight, int $lineQuantity): array
    {
        $additions = $line['additions'] ?? [];
        if (! is_array($additions) || $additions === []) {
            return [];
        }

        if (! $isWeight && $product->menu_rule_code !== 'self_service_counter') {
            throw new DomainException('Este produto não aceita bife adicional no Caixa.');
        }

        if ($lineQuantity !== 1) {
            throw new DomainException('Itens com adicional devem ser lançados individualmente no Caixa.');
        }

        $extraBeefQuantity = 0;
        foreach ($additions as $addition) {
            if (! is_array($addition) || ($addition['code'] ?? null) !== 'extra_beef') {
                throw new DomainException('O adicional informado não é válido para esta venda.');
            }

            $quantity = $addition['quantity'] ?? null;
            if (! is_int($quantity) || $quantity <= 0) {
                throw new DomainException('Informe uma quantidade válida para o bife adicional.');
            }

            $extraBeefQuantity += $quantity;
        }

        $rule = $this->beefAdditional->resolve($company);
        if ($extraBeefQuantity > $rule['max_quantity']) {
            throw new DomainException('No máximo um bife adicional pode ser escolhido por item.');
        }

        return [[
            'product_option_id' => null,
            'name' => $rule['name'],
            'option_type' => ProductSelectionMode::Addon->value,
            'group_code' => 'bife_adicional',
            'quantity' => $extraBeefQuantity,
            'price_delta_cents' => $rule['price_cents'],
            'total_price_cents' => $rule['price_cents'] * $extraBeefQuantity,
            'metadata' => [
                'source' => 'canonical_bife_adicional',
                'menu_component_id' => $rule['menu_component_id'],
                'product_group_component_ids' => $rule['source_link_ids'],
                'addition_code' => 'extra_beef',
                'included_in_unit_price' => false,
            ],
        ]];
    }
}
