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
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
            if (isset($attributes['category_id'])) {
                $category = $this->category($company, (int) $attributes['category_id']);
                $product->category_id = $category->id;

                if ((bool) data_get($product->metadata, 'catalog_admin_product', false)) {
                    $product->product_type = $this->productTypeFor($category);
                }
            }

            $metadata = (array) $product->metadata;
            if ((bool) $attributes['is_active']) {
                unset($metadata['catalog_archived_at'], $metadata['catalog_archived_reason'], $metadata['catalog_archived_by_user_id']);
            }

            $product->fill([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'base_price_cents' => $attributes['price_cents'],
                'is_active' => $attributes['is_active'],
                'is_available_by_default' => $attributes['is_available_by_default'],
                'display_order' => $attributes['display_order'],
                'currency' => 'BRL',
                'metadata' => $metadata,
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
    public function createProduct(
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
            $category = $this->category($company, (int) $attributes['category_id']);
            $isCounterProduct = (bool) $attributes['is_counter_product'];
            $nextOrder = ((int) Product::query()
                ->where('company_id', $company->id)
                ->where('category_id', $category->id)
                ->max('display_order')) + 10;

            $product = Product::query()->create([
                'company_id' => $company->id,
                'category_id' => $category->id,
                'name' => $name,
                'slug' => $slug,
                'product_type' => $isCounterProduct ? Product::TYPE_COUNTER : $this->productTypeFor($category),
                'description' => $attributes['description'] ?? null,
                'base_price_cents' => $attributes['price_cents'],
                'currency' => 'BRL',
                'is_active' => $attributes['is_active'],
                'is_available_by_default' => $attributes['is_available_by_default'],
                'allows_item_notes' => true,
                'metadata' => $isCounterProduct
                    ? ['counter_sale' => true]
                    : ['catalog_admin_product' => true],
                'display_order' => $attributes['display_order'] ?? $nextOrder,
            ]);

            $this->syncServiceDays($product, $serviceDays);

            return $product;
        });

        return $this->configuration->configuration($product->refresh(), $company, $date);
    }

    /**
     * @return array{outcome: string, message: string, product: array<string, mixed>|null}
     */
    public function deleteProduct(
        Company $company,
        Product $product,
        CarbonInterface $date,
        ?int $actorUserId = null,
    ): array {
        abort_unless((int) $product->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        if ($this->requiresHistoricalPreservation($product)) {
            DB::transaction(function () use ($actorUserId, $product): void {
                $metadata = (array) $product->metadata;
                $metadata['catalog_archived_at'] = now()->toIso8601String();
                $metadata['catalog_archived_reason'] = 'referenced_product';
                $metadata['catalog_archived_by_user_id'] = $actorUserId;

                $product->forceFill([
                    'is_active' => false,
                    'is_available_by_default' => false,
                    'metadata' => $metadata,
                ])->save();
            });

            return [
                'outcome' => 'archived',
                'message' => 'O produto possui historico ou vinculos e foi arquivado com seguranca.',
                'product' => $this->configuration->configuration($product->refresh(), $company, $date),
            ];
        }

        $imagePath = data_get($product->metadata, 'catalog_image_path');

        DB::transaction(fn () => $product->delete());
        $this->deleteStoredImage($product, $imagePath);

        return [
            'outcome' => 'deleted',
            'message' => 'Produto excluido.',
            'product' => null,
        ];
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

        if (! is_string($path)) {
            throw new RuntimeException('Nao foi possivel armazenar a imagem do produto.');
        }

        $previousPath = data_get($product->metadata, 'catalog_image_path');

        try {
            DB::transaction(function () use ($path, $product): void {
                $metadata = (array) $product->metadata;
                $metadata['catalog_image_path'] = $path;
                $product->forceFill(['metadata' => $metadata])->save();
            });
        } catch (Throwable $exception) {
            $this->deleteStoredImage($product, $path);

            throw $exception;
        }

        $this->deleteStoredImage($product, $previousPath);

        return $this->configuration->configuration($product->refresh(), $company, $date);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeProductImage(Company $company, Product $product, CarbonInterface $date): array
    {
        abort_unless((int) $product->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);

        $previousPath = data_get($product->metadata, 'catalog_image_path');

        DB::transaction(function () use ($product): void {
            $metadata = (array) $product->metadata;
            unset($metadata['catalog_image_path']);
            $product->forceFill(['metadata' => $metadata])->save();
        });

        $this->deleteStoredImage($product, $previousPath);

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
        $hasStandardMeatRule = array_key_exists('standard_meat', $rules);
        $standardMeat = $rules['standard_meat'] ?? [];

        $beefOnlyEnabled = (bool) ($beefOnly['enabled'] ?? false);
        $extraBeefEnabled = (bool) ($extraBeef['enabled'] ?? false);
        $beefOnlyFinalPriceCents = $beefOnly['final_price_cents'] ?? null;
        $extraBeefPriceCents = $extraBeef['price_cents'] ?? null;
        $extraBeefMaxQuantity = $extraBeef['max_quantity'] ?? null;
        $standardMeatEnabled = (bool) ($standardMeat['enabled'] ?? false);
        $standardMeatPriceCents = $standardMeat['price_cents'] ?? null;

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

        if ($standardMeatEnabled && ! is_int($standardMeatPriceCents)) {
            throw ValidationException::withMessages([
                'beef_rules.standard_meat.price_cents' => ['Informe o preco da carne padrao adicional.'],
            ]);
        }

        if ($hasStandardMeatRule) {
            $product->forceFill([
                'composition_rules' => [
                    ...($product->composition_rules ?? []),
                    'standard_meat_additional_price_cents' => $standardMeatEnabled ? (int) $standardMeatPriceCents : null,
                ],
            ])->save();
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

    private function category(Company $company, int $categoryId): ProductCategory
    {
        $category = ProductCategory::query()
            ->where('company_id', $company->id)
            ->whereKey($categoryId)
            ->first();

        if (! $category instanceof ProductCategory) {
            throw ValidationException::withMessages([
                'category_id' => ['Selecione uma categoria valida para esta empresa.'],
            ]);
        }

        return $category;
    }

    private function productTypeFor(ProductCategory $category): string
    {
        return match ($category->category_type) {
            ProductCategory::TYPE_MARMITAS => Product::TYPE_MARMITA,
            ProductCategory::TYPE_BEBIDAS => Product::TYPE_BEVERAGE,
            ProductCategory::TYPE_SUCOS => Product::TYPE_JUICE,
            ProductCategory::TYPE_COMBOS => Product::TYPE_COMBO,
            ProductCategory::TYPE_FEIJOADAS => Product::TYPE_FEIJOADA,
            ProductCategory::TYPE_ADICIONAIS => Product::TYPE_ADDON,
            ProductCategory::TYPE_ACAI => Product::TYPE_ACAI,
            default => Product::TYPE_PRODUCT,
        };
    }

    private function requiresHistoricalPreservation(Product $product): bool
    {
        return $product->orderItems()->exists()
            || $product->includedInComboItems()->exists()
            || $product->selectableProductLinks()->exists();
    }

    private function deleteStoredImage(Product $product, mixed $path): void
    {
        if (! is_string($path) || ! str_starts_with($path, "menu-products/{$product->company_id}/{$product->id}/")) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
