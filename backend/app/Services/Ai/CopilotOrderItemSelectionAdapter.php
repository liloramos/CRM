<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Services\Orders\OrderItemSelectionValidator;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CopilotOrderItemSelectionAdapter
{
    public function __construct(private readonly OrderItemSelectionValidator $validator) {}

    /**
     * @param  array<string, mixed>  $item
     * @return array{item: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function validate(Company $company, Product $product, CarbonInterface $date, array $item): array
    {
        $product->loadMissing('optionGroups.componentOptions.component');
        $warnings = [];
        $rows = [];
        $selections = is_array($item['selections'] ?? null) ? $item['selections'] : [];

        foreach ($this->selectionValues($product, $selections) as [$groupHint, $value]) {
            $link = $this->resolveLink($product, $groupHint, $value);
            if (! $link) {
                $warnings[] = [
                    'code' => 'UNRESOLVED_SELECTION',
                    'message' => 'Uma escolha sugerida nao corresponde de forma inequivoca ao produto.',
                ];

                continue;
            }

            $rows[] = ['component_link_id' => $link->id, 'quantity' => 1];
        }

        $removedComponentIds = [];
        foreach ($item['removed_components'] ?? [] as $removed) {
            $link = $this->resolveLink($product, 'fixed', (string) $removed);
            if (! $link) {
                $warnings[] = [
                    'code' => 'INVALID_REMOVAL',
                    'message' => 'Uma remocao sugerida nao pertence a composicao do produto.',
                ];

                continue;
            }
            $removedComponentIds[] = (int) $link->menu_component_id;
        }

        $meatMode = $this->meatMode($selections);
        $meatIds = $this->meatIds($company, $product, $selections, $warnings);
        $extraBeef = $this->extraBeefQuantity($selections, $warnings);
        $quantity = (int) ($item['quantity'] ?? 1);

        try {
            $validated = $this->validator->validateStructuredSelections(
                $company->loadMissing('setting'),
                $product,
                $date,
                array_values(array_unique($rows, SORT_REGULAR)),
                [
                    'meat_mode' => $meatMode,
                    'traditional_meat_component_ids' => $meatIds,
                    'extra_beef_quantity' => $extraBeef,
                ],
                $quantity,
                ['removed_component_ids' => array_values(array_unique($removedComponentIds))],
            );
        } catch (DomainException|ValidationException $exception) {
            $warnings[] = [
                'code' => 'DOMAIN_SELECTION_REJECTED',
                'message' => $this->validationMessage($exception),
            ];

            return ['item' => [...$item, 'valid' => false], 'warnings' => $warnings];
        }

        return [
            'item' => [
                ...$item,
                'valid' => $warnings === [],
                'removed_components' => $validated['removed_ingredients'] ?? [],
                'resolved_selections' => $validated['selected_components'] ?? [],
                'unit_price_cents' => $validated['unit_price_cents'] ?? $product->base_price_cents,
            ],
            'warnings' => $warnings,
        ];
    }

    /** @return list<array{0:string,1:string}> */
    private function selectionValues(Product $product, array $selections): array
    {
        $values = [];
        foreach ($selections as $key => $selection) {
            $isTraditionalMarmita = in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true);
            if (in_array((string) $key, ['meat_mode', 'beef_variant', 'extra_beef'], true)
                || ($isTraditionalMarmita && in_array((string) $key, ['meat', 'meats'], true))) {
                continue;
            }
            foreach (is_array($selection) ? $selection : [$selection] as $value) {
                if (is_string($value) && filled($value)) {
                    $values[] = [(string) $key, $value];
                }
            }
        }

        return $values;
    }

    private function resolveLink(Product $product, string $groupHint, string $value): ?ProductGroupComponent
    {
        $needle = $this->key($value);
        if ($needle === '') {
            return null;
        }
        $groups = $product->optionGroups;
        if ($groupHint === 'fixed') {
            $groups = $groups->filter(fn ($group): bool => $group->selection_mode->value === 'fixed');
        } elseif ($groupHint !== '') {
            $hint = $this->key($groupHint);
            $matched = $groups->filter(fn ($group): bool => str_contains($this->key((string) $group->code), $hint) || str_contains($this->key((string) $group->label), $hint));
            if ($matched->isNotEmpty()) {
                $groups = $matched;
            }
        }

        $matches = $groups->flatMap(fn ($group) => $group->componentOptions)
            ->filter(fn (ProductGroupComponent $link): bool => $link->is_active && $link->component && in_array($needle, [
                $this->key((string) $link->component->slug),
                $this->key((string) $link->component->name),
                $this->key((string) $link->component->display_name),
            ], true))
            ->unique('id')
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param list<array<string,mixed>> $warnings @return list<int> */
    private function meatIds(Company $company, Product $product, array $selections, array &$warnings): array
    {
        $meats = $selections['meats'] ?? $selections['meat'] ?? [];
        $meats = is_array($meats) ? $meats : [$meats];
        $ids = [];
        foreach ($meats as $meat) {
            if (! is_string($meat)) {
                continue;
            }
            $component = $this->resolveDailyMeat($company, $product, $meat);
            if (! $component) {
                $warnings[] = ['code' => 'UNRESOLVED_MEAT', 'message' => 'Uma carne sugerida nao pertence de forma inequivoca ao produto.'];

                continue;
            }
            $ids[] = (int) $component->id;
        }

        return $ids;
    }

    private function resolveDailyMeat(Company $company, Product $product, string $value): ?MenuComponent
    {
        $link = $this->resolveLink($product, 'carne', $value);
        if ($link?->component) {
            return $link->component;
        }

        $needle = $this->key($value);
        $matches = MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('component_type', 'meat')
            ->get()
            ->filter(fn (MenuComponent $component): bool => in_array($needle, [
                $this->key((string) $component->slug),
                $this->key((string) $component->name),
                $this->key((string) $component->display_name),
            ], true))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param list<array<string,mixed>> $warnings */
    private function extraBeefQuantity(array $selections, array &$warnings): int
    {
        $value = $selections['extra_beef'] ?? 0;
        if (! is_numeric($value) || (int) $value < 0) {
            $warnings[] = ['code' => 'INVALID_EXTRA_BEEF', 'message' => 'A quantidade de bife adicional sugerida nao e valida.'];

            return 0;
        }

        return (int) $value;
    }

    private function meatMode(array $selections): string
    {
        $value = $this->key((string) ($selections['meat_mode'] ?? $selections['beef_variant'] ?? 'traditional'));

        return in_array($value, ['beefonly', 'somente bife', 'somente bife', 'sobife'], true) ? 'beef_only' : 'traditional';
    }

    private function validationMessage(DomainException|ValidationException $exception): string
    {
        if ($exception instanceof ValidationException) {
            return (string) collect($exception->errors())->flatten()->first();
        }

        return $exception->getMessage();
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
