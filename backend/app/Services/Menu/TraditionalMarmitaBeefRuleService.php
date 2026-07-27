<?php

namespace App\Services\Menu;

use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use Illuminate\Validation\ValidationException;

class TraditionalMarmitaBeefRuleService
{
    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function quote(Product $product, array $selection): array
    {
        $product->loadMissing('optionGroups.componentOptions.component');

        if (! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            throw ValidationException::withMessages([
                'product' => ['Este produto nao possui regras estruturadas de bife.'],
            ]);
        }

        $mode = $selection['meat_mode'] ?? 'traditional';
        $traditionalMeatIds = $this->integerList($selection['traditional_meat_component_ids'] ?? []);
        $extraBeefQuantity = (int) ($selection['extra_beef_quantity'] ?? 0);

        return match ($mode) {
            'beef_only' => $this->quoteBeefOnly($product, $traditionalMeatIds, $extraBeefQuantity),
            'traditional' => $this->quoteTraditional($product, $traditionalMeatIds, $extraBeefQuantity),
            default => throw ValidationException::withMessages([
                'meat_mode' => ['Escolha tradicional ou somente bife.'],
            ]),
        };
    }

    /**
     * @param  array<int, int>  $traditionalMeatIds
     * @return array<string, mixed>
     */
    private function quoteBeefOnly(Product $product, array $traditionalMeatIds, int $extraBeefQuantity): array
    {
        if ($traditionalMeatIds !== []) {
            throw ValidationException::withMessages([
                'traditional_meat_component_ids' => ['Somente bife substitui as carnes tradicionais.'],
            ]);
        }

        if ($extraBeefQuantity > 0) {
            throw ValidationException::withMessages([
                'extra_beef_quantity' => ['Bife adicional nao pode ser combinado com somente bife.'],
            ]);
        }

        $beefOnly = $this->componentLink($product, 'variacao_bife');

        if (! $this->linkIsConfigured($beefOnly) || $beefOnly->final_price_cents === null) {
            throw ValidationException::withMessages([
                'beef_only' => ['O modo somente bife nao esta ativo para este produto.'],
            ]);
        }

        return [
            'meat_mode' => 'beef_only',
            'base_price_cents' => $product->base_price_cents,
            'total_cents' => $beefOnly->final_price_cents,
            'beef_only_final_price_cents' => $beefOnly->final_price_cents,
            'extra_beef_quantity' => 0,
            'extra_beef_total_cents' => 0,
        ];
    }

    /**
     * @param  array<int, int>  $traditionalMeatIds
     * @return array<string, mixed>
     */
    private function quoteTraditional(Product $product, array $traditionalMeatIds, int $extraBeefQuantity): array
    {
        $this->validateTraditionalMeats($product, $traditionalMeatIds);

        $extraBeef = $this->componentLink($product, 'bife_adicional');
        $maxQuantity = (int) ($extraBeef?->group?->max_quantity ?? 0);

        if ($extraBeefQuantity > 0 && ! $this->linkIsConfigured($extraBeef)) {
            throw ValidationException::withMessages([
                'extra_beef_quantity' => ['Bife adicional nao esta ativo para este produto.'],
            ]);
        }

        if ($extraBeefQuantity > $maxQuantity) {
            throw ValidationException::withMessages([
                'extra_beef_quantity' => ['No maximo um bife adicional pode ser escolhido.'],
            ]);
        }

        $extraBeefTotal = $extraBeefQuantity * (int) ($extraBeef?->price_delta_cents ?? 0);
        $basePriceCents = (int) ($product->base_price_cents ?? 0);

        return [
            'meat_mode' => 'traditional',
            'base_price_cents' => $basePriceCents,
            'total_cents' => $basePriceCents + $extraBeefTotal,
            'traditional_meat_component_ids' => $traditionalMeatIds,
            'extra_beef_quantity' => $extraBeefQuantity,
            'extra_beef_unit_price_cents' => (int) ($extraBeef?->price_delta_cents ?? 0),
            'extra_beef_total_cents' => $extraBeefTotal,
        ];
    }

    /**
     * @param  array<int, int>  $traditionalMeatIds
     */
    private function validateTraditionalMeats(Product $product, array $traditionalMeatIds): void
    {
        if (count($traditionalMeatIds) !== 2) {
            throw ValidationException::withMessages([
                'traditional_meat_component_ids' => ['Escolha exatamente duas carnes tradicionais.'],
            ]);
        }

        $uniqueMeatIds = array_values(array_unique($traditionalMeatIds));

        $meats = MenuComponent::query()
            ->where('company_id', $product->company_id)
            ->whereIn('id', $uniqueMeatIds)
            ->where('component_type', 'meat')
            ->where('slug', '<>', 'bife')
            ->get();

        if ($meats->count() !== count($uniqueMeatIds)) {
            throw ValidationException::withMessages([
                'traditional_meat_component_ids' => ['Escolha apenas carnes tradicionais validas do cardapio.'],
            ]);
        }
    }

    private function componentLink(Product $product, string $groupCode): ?ProductGroupComponent
    {
        return $product->optionGroups
            ->firstWhere('code', $groupCode)
            ?->componentOptions
            ->first(fn (ProductGroupComponent $link): bool => $link->component?->slug === 'bife');
    }

    private function linkIsConfigured(?ProductGroupComponent $link): bool
    {
        return $link instanceof ProductGroupComponent
            && (bool) $link->is_active
            && ! (bool) $link->requires_confirmation;
    }

    /**
     * @return array<int, int>
     */
    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_numeric($item))
            ->map(fn (mixed $item): int => (int) $item)
            ->values()
            ->all();
    }
}
