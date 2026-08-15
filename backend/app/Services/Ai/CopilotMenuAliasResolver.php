<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Str;

class CopilotMenuAliasResolver
{
    public function resolve(Company $company, ?int $id, string $identifier): ?Product
    {
        if ($id) {
            return Product::query()->where('company_id', $company->id)->whereKey($id)->first();
        }

        $needle = $this->key($identifier);
        $aliases = [
            'n5' => 'n5-casa',
            'n5casa' => 'n5-casa',
            'n5casa500' => 'n5-casa',
            'n8casa' => 'n8-casa',
            'n8livre' => 'n8-tradicional',
            'n8tradicional' => 'n8-tradicional',
            'n9livre' => 'n9-tradicional',
            'n9tradicional' => 'n9-tradicional',
            'coca600' => 'coca-cola-600ml',
            'cocacola600' => 'coca-cola-600ml',
            'cocade600' => 'coca-cola-600ml',
            'guaranalata' => 'guarana-lata',
            'cocacolazerolata' => 'coca-cola-zero-lata',
            'spritezero' => 'sprite-zero',
            'mineiro600' => 'mineiro-600ml',
            'mineiro600ml' => 'mineiro-600ml',
            'cocacola2l' => 'coca-cola-2l',
        ];
        $slug = $aliases[$needle] ?? $identifier;

        return Product::query()->where('company_id', $company->id)->where(function ($query) use ($slug, $needle): void {
            $query->where('slug', $slug)->orWhereRaw('LOWER(REPLACE(name, \' \', \'\')) = ?', [$needle]);
        })->first();
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
