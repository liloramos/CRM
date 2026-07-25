<?php

namespace App\Services\Menu;

use App\Enums\ProductSelectionMode;
use App\Enums\ProductServiceDay as ProductServiceDayEnum;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductServiceDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($product, $attributes, $serviceDays): void {
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
}
