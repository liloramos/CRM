<?php

namespace App\Services\Menu;

use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use App\Enums\ProductServiceDay as ProductServiceDayEnum;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroupComponent;
use App\Models\ProductOptionGroup;
use App\Models\ProductServiceDay;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MenuProductManagementService
{
    public function __construct(private readonly StructuredProductConfigurationService $configuration) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $serviceDays
     * @return array<string, mixed>
     */
    public function updateProduct(
        Company $company,
        Product $product,
        array $attributes,
        array $serviceDays,
        CarbonInterface $date,
    ): array {
        abort_unless((int) $product->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        DB::transaction(function () use ($company, $product, $attributes, $serviceDays): void {
            if (isset($attributes['category_slug'])) {
                if (! $this->isCounterProduct($product)) {
                    throw ValidationException::withMessages([
                        'category_slug' => ['A categoria so pode ser alterada para produtos de balcao.'],
                    ]);
                }

                $product->category_id = $this->counterCategory($company, $attributes['category_slug'])->id;
            }

            $product->fill([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'base_price_cents' => $attributes['price_cents'],
                'is_active' => $attributes['is_active'],
                'is_available_by_default' => $attributes['is_available_by_default'],
                'display_order' => $attributes['display_order'],
                'currency' => 'BRL',
            ]);
            $product->save();

            $this->syncServiceDays($product, $serviceDays);

            if (isset($attributes['beef_rules']) && is_array($attributes['beef_rules'])) {
                $this->syncTraditionalMarmitaBeefRules($product, $attributes['beef_rules']);
            }
        });

        return $this->configuration->configuration($product->refresh(), $company, $date);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $serviceDays
     * @return array<string, mixed>
     */
    public function createCounterProduct(
        Company $company,
        array $attributes,
        array $serviceDays,
        CarbonInterface $date,
    ): array {
        $name = trim((string) $attributes['name']);
        $slug = Str::slug($name);

        if ($slug === '') {
            throw ValidationException::withMessages(['name' => ['Informe um nome valido para o produto.']]);
        }

        if (Product::query()->where('company_id', $company->id)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => ['Ja existe um produto com este nome para a empresa.']]);
        }

        $product = DB::transaction(function () use ($attributes, $company, $name, $serviceDays, $slug): Product {
            $category = $this->counterCategory($company, (string) $attributes['category_slug']);
            $nextOrder = ((int) Product::query()
                ->where('company_id', $company->id)
                ->where('category_id', $category->id)
                ->max('display_order')) + 10;

            $product = Product::query()->create([
                'company_id' => $company->id,
                'category_id' => $category->id,
                'name' => $name,
                'slug' => $slug,
                'product_type' => Product::TYPE_COUNTER,
                'description' => $attributes['description'] ?? null,
                'base_price_cents' => $attributes['price_cents'],
                'currency' => 'BRL',
                'is_active' => $attributes['is_active'],
                'is_available_by_default' => $attributes['is_available_by_default'],
                'allows_item_notes' => true,
                'metadata' => ['counter_sale' => true],
                'display_order' => $attributes['display_order'] ?? $nextOrder,
            ]);

            $this->syncServiceDays($product, $serviceDays);

            return $product;
        });

        return $this->configuration->configuration($product->refresh(), $company, $date);
    }

    /**
     * @return array<string, mixed>
     */
    public function replaceProductImage(Company $company, Product $product, UploadedFile $image, CarbonInterface $date): array
    {
        abort_unless((int) $product->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        $extension = strtolower($image->guessExtension() ?: $image->extension() ?: 'jpg');
        $directory = "menu-products/{$company->id}/{$product->id}";
        $path = $image->storePubliclyAs($directory, Str::uuid().'.'.$extension, 'public');

        DB::transaction(function () use ($path, $product): void {
            $metadata = (array) $product->metadata;
            $this->deleteStoredImage($product, $metadata['catalog_image_path'] ?? null);
            $metadata['catalog_image_path'] = $path;
            $product->forceFill(['metadata' => $metadata])->save();
        });

        return $this->configuration->configuration($product->refresh(), $company, $date);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeProductImage(Company $company, Product $product, CarbonInterface $date): array
    {
        abort_unless((int) $product->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        DB::transaction(function () use ($product): void {
            $metadata = (array) $product->metadata;
            $this->deleteStoredImage($product, $metadata['catalog_image_path'] ?? null);
            unset($metadata['catalog_image_path']);
            $product->forceFill(['metadata' => $metadata])->save();
        });

        return $this->configuration->configuration($product->refresh(), $company, $date);
    }

    /**
     * @param  array<int, string>  $activeDays
     */
    public function syncServiceDays(Product $product, array $activeDays): void
    {
        $activeDays = collect($activeDays)->unique()->values();

        foreach (ProductServiceDayEnum::cases() as $serviceDay) {
            ProductServiceDay::query()->updateOrCreate(
                [
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'service_day' => $serviceDay->value,
                ],
                [
                    'is_active' => $activeDays->contains($serviceDay->value),
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function updateComponentOption(
        Company $company,
        ProductGroupComponent $option,
        array $attributes,
        CarbonInterface $date,
    ): array {
        $option->loadMissing(['group.product', 'component']);
        $group = $option->group;
        $product = $group?->product;

        abort_unless($group !== null && $product instanceof Product, Response::HTTP_NOT_FOUND);
        abort_unless((int) $group->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);
        abort_unless((int) $product->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        if ($group->selection_mode !== ProductSelectionMode::Variation) {
            throw ValidationException::withMessages([
                'option' => ['Somente variacoes estruturadas podem ser resolvidas por esta acao.'],
            ]);
        }

        DB::transaction(function () use ($attributes, $option, $product): void {
            if ($attributes['resolution'] === 'not_offered') {
                $option->fill([
                    'is_active' => false,
                    'requires_confirmation' => false,
                    'price_delta_cents' => 0,
                    'final_price_cents' => null,
                ]);
                $option->save();

                return;
            }

            $finalPriceCents = (int) $attributes['final_price_cents'];
            $basePriceCents = (int) ($product->base_price_cents ?? 0);

            if ($finalPriceCents < $basePriceCents) {
                throw ValidationException::withMessages([
                    'final_price_cents' => ['O preco final da variacao nao pode ser menor que o preco base do produto.'],
                ]);
            }

            $option->fill([
                'is_active' => true,
                'requires_confirmation' => false,
                'price_delta_cents' => $finalPriceCents - $basePriceCents,
                'final_price_cents' => $finalPriceCents,
            ]);
            $option->save();
        });

        return $this->configuration->configuration($product->refresh(), $company, $date);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function syncTraditionalMarmitaBeefRules(Product $product, array $rules): void
    {
        if (! in_array($product->menu_rule_code, ['n8_tradicional', 'n9_tradicional'], true)) {
            throw ValidationException::withMessages([
                'beef_rules' => ['Regras de bife so podem ser configuradas para N8 e N9 Tradicional.'],
            ]);
        }

        $basePriceCents = (int) ($product->base_price_cents ?? 0);
        $beefOnly = $rules['beef_only'] ?? [];
        $extraBeef = $rules['extra_beef'] ?? [];

        $beefOnlyEnabled = (bool) ($beefOnly['enabled'] ?? false);
        $extraBeefEnabled = (bool) ($extraBeef['enabled'] ?? false);
        $beefOnlyFinalPriceCents = $beefOnly['final_price_cents'] ?? null;
        $extraBeefPriceCents = $extraBeef['price_cents'] ?? null;
        $extraBeefMaxQuantity = $extraBeef['max_quantity'] ?? null;

        if ($beefOnlyEnabled && ! is_int($beefOnlyFinalPriceCents)) {
            throw ValidationException::withMessages([
                'beef_rules.beef_only.final_price_cents' => ['Informe o preco final do modo somente bife.'],
            ]);
        }

        if ($beefOnlyEnabled && (int) $beefOnlyFinalPriceCents < $basePriceCents) {
            throw ValidationException::withMessages([
                'beef_rules.beef_only.final_price_cents' => ['O preco final do modo somente bife nao pode ser menor que o preco base.'],
            ]);
        }

        if ($extraBeefEnabled && ! is_int($extraBeefPriceCents)) {
            throw ValidationException::withMessages([
                'beef_rules.extra_beef.price_cents' => ['Informe o preco do bife adicional.'],
            ]);
        }

        if ($extraBeefEnabled && (! is_int($extraBeefMaxQuantity) || $extraBeefMaxQuantity < 1)) {
            throw ValidationException::withMessages([
                'beef_rules.extra_beef.max_quantity' => ['Informe a quantidade maxima do bife adicional.'],
            ]);
        }

        $beef = MenuComponent::query()
            ->where('company_id', $product->company_id)
            ->where('slug', 'bife')
            ->firstOrFail();

        $beefOnlyGroup = $this->upsertProductGroup($product, [
            'code' => 'variacao_bife',
            'label' => 'Somente bife',
            'selection_mode' => ProductSelectionMode::Variation,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => false,
            'min_choices' => 0,
            'max_choices' => 1,
            'min_quantity' => null,
            'max_quantity' => null,
            'same_component_only' => false,
            'included_in_base_price' => false,
            'display_order' => 10,
        ]);

        ProductGroupComponent::query()->updateOrCreate(
            [
                'product_option_group_id' => $beefOnlyGroup->id,
                'menu_component_id' => $beef->id,
            ],
            [
                'price_delta_cents' => $beefOnlyEnabled ? ((int) $beefOnlyFinalPriceCents - $basePriceCents) : 0,
                'final_price_cents' => $beefOnlyEnabled ? (int) $beefOnlyFinalPriceCents : null,
                'included_quantity' => null,
                'is_default' => false,
                'is_active' => $beefOnlyEnabled,
                'requires_confirmation' => false,
                'display_order' => 10,
            ],
        );

        $extraBeefGroup = $this->upsertProductGroup($product, [
            'code' => 'bife_adicional',
            'label' => 'Bife adicional',
            'selection_mode' => ProductSelectionMode::Addon,
            'selection_actor' => ProductSelectionActor::Customer,
            'is_required' => false,
            'min_choices' => 0,
            'max_choices' => 1,
            'min_quantity' => 0,
            'max_quantity' => $extraBeefEnabled ? (int) $extraBeefMaxQuantity : 1,
            'same_component_only' => true,
            'included_in_base_price' => false,
            'display_order' => 20,
        ]);

        ProductGroupComponent::query()->updateOrCreate(
            [
                'product_option_group_id' => $extraBeefGroup->id,
                'menu_component_id' => $beef->id,
            ],
            [
                'price_delta_cents' => $extraBeefEnabled ? (int) $extraBeefPriceCents : 0,
                'final_price_cents' => null,
                'included_quantity' => null,
                'is_default' => false,
                'is_active' => $extraBeefEnabled,
                'requires_confirmation' => false,
                'display_order' => 10,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertProductGroup(Product $product, array $attributes): ProductOptionGroup
    {
        return ProductOptionGroup::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'code' => $attributes['code'],
            ],
            [
                'company_id' => $product->company_id,
                'label' => $attributes['label'],
                'selection_mode' => $attributes['selection_mode'],
                'selection_actor' => $attributes['selection_actor'],
                'is_required' => $attributes['is_required'],
                'min_choices' => $attributes['min_choices'],
                'max_choices' => $attributes['max_choices'],
                'min_quantity' => $attributes['min_quantity'],
                'max_quantity' => $attributes['max_quantity'],
                'same_component_only' => $attributes['same_component_only'],
                'included_in_base_price' => $attributes['included_in_base_price'],
                'display_order' => $attributes['display_order'],
            ],
        );
    }

    private function isCounterProduct(Product $product): bool
    {
        return $product->product_type === Product::TYPE_COUNTER
            || (bool) data_get($product->metadata, 'counter_sale', false);
    }

    private function counterCategory(Company $company, string $slug): ProductCategory
    {
        $definition = ProductCategory::counterCategoryDefinitions()[$slug] ?? null;

        if ($definition === null) {
            throw ValidationException::withMessages(['category_slug' => ['Selecione uma categoria valida para o produto de balcao.']]);
        }

        return ProductCategory::query()->firstOrCreate(
            ['company_id' => $company->id, 'slug' => $slug],
            $definition,
        );
    }

    private function deleteStoredImage(Product $product, mixed $path): void
    {
        if (! is_string($path) || ! str_starts_with($path, "menu-products/{$product->company_id}/{$product->id}/")) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
