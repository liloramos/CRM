<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use App\Services\Menu\DailyStructuredMenuService;
use App\Services\Operational\CompanyOperatingHoursService;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class CopilotMenuReplyBuilder
{
    public function __construct(
        private readonly DailyStructuredMenuService $dailyMenu,
        private readonly CopilotProductEligibility $eligibility,
        private readonly CompanyOperatingHoursService $operatingHours,
    ) {}

    /** @return array<string,mixed> */
    public function build(Company $company, CarbonInterface $date, string $request = '', ?array $operationalStatus = null): array
    {
        $operationalStatus ??= $this->operatingHours->status($company);
        if (($operationalStatus['status'] ?? null) === CompanyOperatingHoursService::STATUS_CLOSED) {
            return $this->analysis(
                $this->operatingHours->closedReply($operationalStatus),
                $date,
                $operationalStatus,
                false,
                'operating_hours',
            );
        }

        $menu = $this->dailyMenu->day($company, $date);
        $descriptions = Product::query()
            ->where('company_id', $company->id)
            ->whereIn('id', collect(data_get($menu, 'catalog.categories', []))->flatMap(fn (mixed $category): array => is_array($category) ? array_column((array) ($category['products'] ?? []), 'id') : [])->filter())
            ->pluck('description', 'id');
        $categories = collect(data_get($menu, 'catalog.categories', []))
            ->filter(fn (mixed $category): bool => is_array($category))
            ->map(function (array $category) use ($descriptions): array {
                $products = collect($category['products'] ?? [])
                    ->filter(fn (mixed $product): bool => is_array($product)
                        && (bool) data_get($product, 'availability.available', false)
                        && $this->eligibility->isEligibleProductType(isset($product['product_type']) ? (string) $product['product_type'] : null))
                    ->map(fn (array $product): array => [
                        ...$product,
                        'description' => trim((string) $descriptions->get((int) ($product['id'] ?? 0), '')),
                    ])
                    ->values()
                    ->all();

                return [...$category, 'products' => $products];
            })
            ->filter(fn (array $category): bool => $category['products'] !== [])
            ->values();
        $reply = match (true) {
            $categories->isEmpty() => 'Ainda não tenho o cardápio de hoje disponível aqui 😊',
            $this->wantsCompleteMenu($request) => $this->completeReply($categories),
            default => $this->summaryReply($categories, $menu),
        };

        return $this->analysis($reply, $date, $operationalStatus, $categories->isNotEmpty());
    }

    /** @param array<string,mixed> $operationalStatus @return array<string,mixed> */
    private function analysis(string $reply, CarbonInterface $date, array $operationalStatus, bool $menuAvailable, string $source = 'daily_menu'): array
    {
        return [
            'intent' => 'MENU_REQUEST',
            'confidence' => 1,
            'summary' => 'Solicitação de cardápio do dia.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'metadata' => [
                'reply_source' => $source,
                'menu_date' => $date->toDateString(),
                'menu_available' => $menuAvailable,
                ...$this->operatingHours->metadata($operationalStatus),
            ],
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    private function productBlock(array $product): string
    {
        $name = trim((string) ($product['name'] ?? 'Produto'));
        $price = (int) ($product['base_price_cents'] ?? 0);
        $heading = $price > 0 ? "🍽️ *{$name} – R$ {$this->money($price)}*" : "🍽️ *{$name}*";
        $description = Str::of((string) ($product['description'] ?? ''))->squish()->limit(105, '')->toString();

        return $description === '' ? $heading : "{$heading}\n{$description}";
    }

    private function completeReply($categories): string
    {
        $blocks = $categories->map(fn (array $category): string => '*'.mb_strtoupper(trim((string) ($category['name'] ?? 'Opções')))."*\n\n"
            .collect($category['products'])->map(fn (array $product): string => $this->productBlock($product))->implode("\n\n"));

        return "Claro! ☀️ Aqui está o cardápio completo de hoje:\n\n"
            .$blocks->implode("\n\n")
            ."\n\nQuer ajuda para escolher ou montar seu pedido?";
    }

    private function summaryReply($categories, array $menu): string
    {
        $marmitas = $categories->firstWhere('slug', 'marmitas');
        $products = collect(is_array($marmitas) ? ($marmitas['products'] ?? []) : [])
            ->take(5)
            ->map(fn (array $product): string => $this->productBlock($product));

        if ($products->isEmpty()) {
            $names = $categories->pluck('name')->filter()->take(6)->implode(', ');

            return "Hoje temos opções de {$names}. ☀️\n\nQual categoria você quer ver?";
        }

        $reply = "🥡 *MARMITEX – SOL RESTAURANTE*\n\n".$products->implode("\n\n");
        $meats = collect(data_get($menu, 'sections.meat', []))
            ->filter(fn (mixed $item): bool => is_array($item) && (bool) ($item['available'] ?? false))
            ->map(fn (array $item): string => (string) data_get($item, 'component.display_name', data_get($item, 'component.name', '')))
            ->filter()
            ->take(4)
            ->values();
        if ($meats->isNotEmpty()) {
            $reply .= "\n\n🥩 *Carnes de hoje:* ".$meats->implode(', ').'.';
        }

        $otherCategories = $categories
            ->reject(fn (array $category): bool => ($category['slug'] ?? null) === 'marmitas')
            ->pluck('name')
            ->filter()
            ->take(6)
            ->implode(', ');
        if ($otherCategories !== '') {
            $reply .= "\n\nTambém temos {$otherCategories}.";
        }

        return $reply."\n\n☀️ Quer montar uma para você ou ver outra categoria?";
    }

    private function wantsCompleteMenu(string $request): bool
    {
        $request = Str::of($request)->ascii()->lower()->squish()->toString();

        return Str::contains($request, ['cardapio completo', 'menu completo', 'cardapio inteiro', 'todas as opcoes', 'tudo que voces tem']);
    }
}
