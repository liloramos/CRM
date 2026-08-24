<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Str;

class CopilotMenuAliasResolver
{
    public function __construct(private readonly CopilotProductEligibility $eligibility) {}

    public function resolve(Company $company, ?int $id, string $identifier): ?Product
    {
        if ($id) {
            return $this->eligibility->apply(Product::query())
                ->where('company_id', $company->id)
                ->whereKey($id)
                ->first();
        }

        $needle = $this->key($identifier);
        $slug = $this->aliases()[$needle] ?? $identifier;

        return $this->eligibility->apply(Product::query())
            ->where('company_id', $company->id)
            ->where(function ($query) use ($slug, $needle): void {
                $query->where('slug', $slug)->orWhereRaw('LOWER(REPLACE(name, \' \', \'\')) = ?', [$needle]);
            })->first();
    }

    /** @param list<array<string,mixed>> $messages */
    public function isExplicitlyReferenced(Product $product, array $messages): bool
    {
        $identifiers = $this->identifiersFor($product);

        foreach ($messages as $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $text = $this->key((string) ($message['body'] ?? ''));
            foreach ($identifiers as $identifier) {
                if ($identifier !== '' && str_contains($text, $identifier)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function identifiersFor(Product $product): array
    {
        $identifiers = [$this->key($product->slug), $this->key($product->name)];

        foreach ($this->aliases() as $alias => $slug) {
            if ($slug === $product->slug) {
                $identifiers[] = $alias;
            }
        }

        return array_values(array_unique(array_filter($identifiers)));
    }

    /** @return array<string,string> */
    private function aliases(): array
    {
        return [
            'n5' => 'n5-casa',
            'n5casa' => 'n5-casa',
            'n5casa500' => 'n5-casa',
            'n8casa' => 'n8-casa',
            'n8' => 'n8-tradicional',
            'n8livre' => 'n8-tradicional',
            'n8tradicional' => 'n8-tradicional',
            'n9' => 'n9-tradicional',
            'n9livre' => 'n9-tradicional',
            'n9tradicional' => 'n9-tradicional',
            'aguasemgas' => 'agua-mineral',
            'aguamineral' => 'agua-mineral',
            'coca600' => 'coca-cola-600ml',
            'cocacola600' => 'coca-cola-600ml',
            'cocade600' => 'coca-cola-600ml',
            'guaranalata' => 'guarana-lata',
            'cocacolazerolata' => 'coca-cola-zero-lata',
            'cocazerolata' => 'coca-cola-zero-lata',
            'spritezero' => 'sprite-zero',
            'mineiro600' => 'mineiro-600ml',
            'mineiro600ml' => 'mineiro-600ml',
            'cocacola2l' => 'coca-cola-2l',
        ];
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
