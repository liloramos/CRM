<?php

namespace App\Services\Menu;

use App\Models\Company;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MenuCategoryManagementService
{
    /** @param array<string, mixed> $attributes */
    public function create(Company $company, array $attributes): ProductCategory
    {
        $name = trim((string) $attributes['name']);
        $slug = Str::slug($name);

        $this->ensureUsableSlug($company, $slug);

        $nextOrder = ((int) ProductCategory::query()
            ->where('company_id', $company->id)
            ->max('display_order')) + 10;

        return ProductCategory::query()->create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => $slug,
            'category_type' => 'general',
            'description' => $attributes['description'] ?? null,
            'display_order' => $attributes['display_order'] ?? $nextOrder,
            'is_active' => $attributes['is_active'],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Company $company, ProductCategory $category, array $attributes): ProductCategory
    {
        $this->ensureSameCompany($company, $category);

        $name = trim((string) $attributes['name']);
        $normalizedSlug = Str::slug($name);
        $this->ensureUsableSlug($company, $normalizedSlug, $category);

        $category->fill([
            'name' => $name,
            'description' => $attributes['description'] ?? null,
            'display_order' => $attributes['display_order'],
            'is_active' => $attributes['is_active'],
        ])->save();

        return $category->refresh();
    }

    public function delete(Company $company, ProductCategory $category): void
    {
        $this->ensureSameCompany($company, $category);

        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Esta categoria possui produtos. Mova os produtos antes de excluir.'],
            ]);
        }

        DB::transaction(fn () => $category->delete());
    }

    private function ensureSameCompany(Company $company, ProductCategory $category): void
    {
        abort_unless((int) $category->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);
    }

    private function ensureUsableSlug(Company $company, string $slug, ?ProductCategory $except = null): void
    {
        if ($slug === '') {
            throw ValidationException::withMessages(['name' => ['Informe um nome valido para a categoria.']]);
        }

        $exists = ProductCategory::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => ['Ja existe uma categoria com este nome para a empresa.']]);
        }
    }
}
