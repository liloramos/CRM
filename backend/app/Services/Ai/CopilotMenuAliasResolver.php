<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Str;

class CopilotMenuAliasResolver
{
    public function __construct(
        private readonly CopilotProductEligibility $eligibility,
        private readonly CopilotCanonicalEntityResolver $entities,
    ) {}

    public function resolve(Company $company, ?int $id, string $identifier): ?Product
    {
        if ($id) {
            return $this->eligibility->apply(Product::query())
                ->where('company_id', $company->id)
                ->whereKey($id)
                ->first();
        }

        $needle = $this->key($identifier);
        $normalizedAliases = collect($this->aliases())
            ->mapWithKeys(fn (string $slug, string $alias): array => [$this->key($alias) => $slug]);
        $slug = $normalizedAliases->get($needle, $identifier);

        return $this->eligibility->apply(Product::query())
            ->where('company_id', $company->id)
            ->where(function ($query) use ($slug, $needle): void {
                $query->where('slug', $slug)->orWhereRaw('LOWER(REPLACE(name, \' \', \'\')) = ?', [$needle]);
            })->first();
    }

    public function resolveFromText(Company $company, string $text): ?Product
    {
        $product = $this->resolveFromTextIncludingInactive($company, $text);

        return $product?->is_active ? $product : null;
    }

    public function resolveFromTextIncludingInactive(Company $company, string $text): ?Product
    {
        if (trim($text) === '') {
            return null;
        }

        $products = $this->eligibility->apply(Product::query())
            ->where('company_id', $company->id)
            ->get();
        $resolution = $this->entities->resolve($text, $this->productEntities($products));
        if ($resolution['ambiguous'] !== [] || $resolution['resolved'] === []) {
            return null;
        }

        return $products->firstWhere('id', (int) $resolution['resolved'][0]['canonical_id']);
    }

    /** @param list<array<string,mixed>> $messages @return list<int> */
    public function explicitlyReferencedProductIds(Company $company, array $messages): array
    {
        $products = $this->eligibility->apply(Product::query())
            ->where('company_id', $company->id)
            ->get();
        $entities = $this->productEntities($products);

        return collect($messages)
            ->filter(fn (mixed $message): bool => is_array($message)
                && ($message['direction'] ?? null) === 'inbound'
                && ($message['type'] ?? 'text') === 'text')
            ->flatMap(fn (array $message): array => collect($this->entities->resolve(
                (string) ($message['body'] ?? ''),
                $entities,
            )['resolved'])->pluck('canonical_id')->all())
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<array<string,mixed>> $messages */
    public function isExplicitlyReferenced(Product $product, array $messages): bool
    {
        $products = $this->eligibility->apply(Product::query())
            ->where('company_id', $product->company_id)
            ->get();
        $entities = $this->productEntities($products);

        foreach ($messages as $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $resolution = $this->entities->resolve((string) ($message['body'] ?? ''), $entities);
            if (collect($resolution['resolved'])->contains(
                fn (array $match): bool => (int) ($match['canonical_id'] ?? 0) === (int) $product->id,
            )) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string,mixed>> */
    private function productEntities($products): array
    {
        return collect($products)->map(function (Product $product): array {
            $aliases = collect($this->aliases())
                ->filter(fn (string $slug): bool => $slug === $product->slug)
                ->keys()
                ->flatMap(function (string $alias): array {
                    $compact = $this->key($alias);
                    $familySpaced = trim(preg_replace('/^(n)([589])/', '$1 $2 ', $compact) ?: $compact);
                    $numberAttachedUnit = trim(preg_replace('/(?<=\D)(?=\d)/', ' ', $compact) ?: $compact);
                    $numberSpaced = trim(preg_replace('/(?<=\D)(?=\d)|(?<=\d)(?=\D)/', ' ', $compact) ?: $compact);
                    $connectorSpaced = trim(preg_replace('/(?<=[a-z])(de|da|do)(?=\d)/', ' $1 ', $compact) ?: $compact);

                    return [$alias, $compact, $familySpaced, $numberAttachedUnit, $numberSpaced, $connectorSpaced];
                })
                ->map(fn (string $alias): string => trim($alias))
                ->unique()
                ->values()
                ->all();
            if (preg_match('/\bn\s*([0-9]+)\b/i', (string) $product->name, $match) === 1) {
                $family = 'n'.$match[1];
                $price = intdiv((int) $product->base_price_cents, 100);
                if ($price > 0) {
                    $aliases = [...$aliases, "{$family} {$price}", "{$family} de {$price}", "{$family} por {$price}"];
                }
            }

            return [
                'id' => (int) $product->id,
                'slug' => (string) $product->slug,
                'name' => (string) $product->name,
                'type' => 'product',
                'available_today' => (bool) ($product->is_active && $product->is_available_by_default),
                'aliases' => $aliases,
            ];
        })->all();
    }

    /** @return array<string,string> */
    private function aliases(): array
    {
        return [
            'n5' => 'n5-casa',
            'n5 casa' => 'n5-casa',
            'n5 casa 500' => 'n5-casa',
            'n8 casa' => 'n8-casa',
            'n8 livre' => 'n8-tradicional',
            'n8 livres' => 'n8-tradicional',
            'n8 tradicional' => 'n8-tradicional',
            'n8 tradicionais' => 'n8-tradicional',
            'n9' => 'n9-tradicional',
            'n9 livre' => 'n9-tradicional',
            'n9 livres' => 'n9-tradicional',
            'n9 tradicional' => 'n9-tradicional',
            'n9 tradicionais' => 'n9-tradicional',
            'agua sem gas' => 'agua-mineral',
            'agua mineral' => 'agua-mineral',
            'coca 600' => 'coca-cola-600ml',
            'coca cola 600' => 'coca-cola-600ml',
            'coca de 600' => 'coca-cola-600ml',
            'guarana lata' => 'guarana-lata',
            'coca cola zero lata' => 'coca-cola-zero-lata',
            'coca zero lata' => 'coca-cola-zero-lata',
            'coca zero' => 'coca-cola-zero-lata',
            'coca cola zero' => 'coca-cola-zero-lata',
            'sprite zero' => 'sprite-zero',
            'mineiro 600' => 'mineiro-600ml',
            'mineiro 600 ml' => 'mineiro-600ml',
            'coca 2l' => 'coca-cola-2l',
            'coca cola 2l' => 'coca-cola-2l',
        ];
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
