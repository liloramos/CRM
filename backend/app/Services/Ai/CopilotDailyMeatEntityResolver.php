<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Menu\MenuComponentPresentation;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class CopilotDailyMeatEntityResolver
{
    public function __construct(
        private readonly DailyStructuredMenuService $dailyMenu,
        private readonly CopilotCanonicalEntityResolver $entities,
        private readonly MenuComponentPresentation $presentation,
    ) {}

    /** @return array{components:list<MenuComponent>,resolved:list<array<string,mixed>>,ambiguous:list<array<string,mixed>>,consumed_spans:list<array<string,int>>} */
    public function resolve(Company $company, Product $product, CarbonInterface $date, string $text): array
    {
        $components = collect($this->candidates($company, $product, $date));
        $resolution = $this->entities->resolve($text, $this->entityRows($components));

        return ['components' => $components->all(), ...$resolution];
    }

    /** @return list<string> */
    public function names(Company $company, Product $product, CarbonInterface $date, string $text): array
    {
        $resolution = $this->resolve($company, $product, $date, $text);
        $components = collect($resolution['components'])->keyBy(fn (MenuComponent $component): int => (int) $component->id);

        return collect($resolution['resolved'])
            ->map(function (array $match) use ($components): ?string {
                $component = $components->get((int) ($match['canonical_id'] ?? 0));

                return $component instanceof MenuComponent
                    ? (string) ($component->display_name ?: $component->name)
                    : null;
            })
            ->filter()
            ->unique(fn (string $name): string => $this->key($name))
            ->values()
            ->all();
    }

    /** @return list<MenuComponent> */
    public function candidates(Company $company, Product $product, CarbonInterface $date): array
    {
        $availableIds = collect(data_get($this->dailyMenu->day($company, $date), 'sections.meat', []))
            ->filter(fn (array $item): bool => (bool) ($item['available'] ?? false))
            ->map(fn (array $item): int => (int) data_get($item, 'component.id'))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->all();
        $available = MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('component_type', 'meat')
            ->whereIn('id', $availableIds)
            ->get()
            ->values();

        if (in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            return $available->all();
        }

        $product->loadMissing('optionGroups.componentOptions');
        $productMeatIds = $product->optionGroups
            ->filter(fn ($group): bool => $this->key((string) $group->code) === 'carne')
            ->flatMap(fn ($group) => $group->componentOptions)
            ->filter(fn (ProductGroupComponent $link): bool => $link->is_active)
            ->pluck('menu_component_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $available
            ->filter(fn (MenuComponent $component): bool => in_array((int) $component->id, $productMeatIds, true))
            ->values()
            ->all();
    }

    /** @param iterable<MenuComponent> $components @return list<array<string,mixed>> */
    private function entityRows(iterable $components): array
    {
        $components = collect($components)->values();
        $tokens = $components->mapWithKeys(fn (MenuComponent $component): array => [
            (int) $component->id => $this->identityTokens((string) ($component->display_name ?: $component->name)),
        ]);
        $counts = $tokens->flatten()->countBy();

        return $components->map(function (MenuComponent $component) use ($tokens, $counts): array {
            $identityTokens = collect($tokens->get((int) $component->id, []));
            $leading = (string) $identityTokens->first();
            $aliases = $identityTokens
                ->filter(fn (string $token): bool => $token === $leading || (int) $counts->get($token, 0) === 1)
                ->map(fn (string $token): array => ['value' => $token, 'source' => 'inferred_alias'])
                ->values()
                ->all();
            $aliases = [...$aliases, ...$this->presentation->searchAliases($component)];

            return [
                'id' => (int) $component->id,
                'slug' => (string) $component->slug,
                'name' => (string) ($component->display_name ?: $component->name),
                'display_name' => (string) $component->display_name,
                'type' => 'meat',
                'available_today' => true,
                'aliases' => $aliases,
            ];
        })->all();
    }

    /** @return list<string> */
    private function identityTokens(string $value): array
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->explode(' ')
            ->filter(fn (string $token): bool => strlen($token) >= 4 && ! in_array($token, ['para'], true))
            ->values()
            ->all();
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
