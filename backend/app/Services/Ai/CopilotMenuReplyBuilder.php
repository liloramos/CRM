<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Services\Menu\DailyStructuredMenuService;
use Carbon\CarbonInterface;

final class CopilotMenuReplyBuilder
{
    public function __construct(private readonly DailyStructuredMenuService $dailyMenu) {}

    /** @return array<string,mixed> */
    public function build(Company $company, CarbonInterface $date): array
    {
        $menu = $this->dailyMenu->day($company, $date);
        $products = collect(data_get($menu, 'catalog.categories', []))
            ->flatMap(fn (mixed $category): array => is_array($category) ? (array) ($category['products'] ?? []) : [])
            ->filter(fn (mixed $product): bool => is_array($product) && (bool) data_get($product, 'availability.available', false))
            ->map(fn (array $product): string => sprintf('%s - R$ %s', (string) ($product['name'] ?? 'Produto'), $this->money((int) ($product['base_price_cents'] ?? 0))))
            ->values();
        $sections = collect(data_get($menu, 'sections', []))
            ->map(function (mixed $items, string $section): ?string {
                $names = collect(is_array($items) ? $items : [])
                    ->filter(fn (mixed $item): bool => is_array($item) && (bool) ($item['available'] ?? false))
                    ->map(fn (array $item): string => (string) data_get($item, 'component.display_name', data_get($item, 'component.name', '')))
                    ->filter()
                    ->values();

                return $names->isEmpty() ? null : ucfirst($section).': '.$names->implode(', ');
            })
            ->filter()
            ->values();

        $reply = $products->isEmpty()
            ? 'Vou confirmar o cardápio disponível de hoje para você.'
            : "Cardápio de hoje:\n".$products->implode("\n");
        if ($sections->isNotEmpty()) {
            $reply .= "\n\n".$sections->implode("\n");
        }

        return [
            'intent' => 'MENU_REQUEST',
            'confidence' => 1,
            'summary' => 'Solicitação de cardápio do dia.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'metadata' => ['reply_source' => 'daily_menu', 'menu_date' => $date->toDateString()],
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
