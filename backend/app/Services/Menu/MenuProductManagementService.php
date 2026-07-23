<?php

namespace App\Services\Menu;

use App\Enums\ProductServiceDay as ProductServiceDayEnum;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductServiceDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
}
