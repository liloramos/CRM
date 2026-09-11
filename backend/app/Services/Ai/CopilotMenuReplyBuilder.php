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
        private readonly CopilotResolvedProductConfigurationService $resolvedProducts,
        private readonly CopilotProductDecisionFacts $decisionFacts,
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
        $productModels = Product::query()
            ->where('company_id', $company->id)
            ->whereIn('id', collect(data_get($menu, 'catalog.categories', []))->flatMap(fn (mixed $category): array => is_array($category) ? array_column((array) ($category['products'] ?? []), 'id') : [])->filter())
            ->get()
            ->keyBy('id');
        $categories = collect(data_get($menu, 'catalog.categories', []))
            ->filter(fn (mixed $category): bool => is_array($category))
            ->map(function (array $category) use ($company, $date, $productModels): array {
                $categoryProducts = collect($category['products'] ?? [])
                    ->filter(fn (mixed $product): bool => is_array($product)
                        && (bool) data_get($product, 'availability.available', false)
                        && $this->eligibility->isEligibleProductType(isset($product['product_type']) ? (string) $product['product_type'] : null))
                    ->map(function (array $product) use ($company, $date, $productModels): array {
                        $model = $productModels->get((int) ($product['id'] ?? 0));

                        return [
                            ...$product,
                            'description' => trim((string) ($model?->description ?? '')),
                            'resolved_configuration' => $model instanceof Product
                                ? $this->resolvedProducts->resolve($company, $model, $date)
                                : [],
                        ];
                    })
                    ->values()
                    ->all();

                return [...$category, 'products' => $categoryProducts];
            })
            ->filter(fn (array $category): bool => $category['products'] !== [])
            ->values();
        $messages = match (true) {
            $categories->isEmpty() => 'Ainda não tenho o cardápio de hoje disponível aqui 😊',
            $this->wantsBuffet($request) => [$this->dailyMenuMessage($menu, false)],
            $this->wantsCompleteMenu($request) => $this->completeMessages($categories, $menu),
            default => [$this->summaryReply($categories, $menu)],
        };

        if (! is_array($messages)) {
            $messages = [$messages];
        }

        return $this->analysis(implode("\n\n", $messages), $date, $operationalStatus, $categories->isNotEmpty(), 'daily_menu', $messages);
    }

    /** @return array<string,mixed> */
    public function buildSupplemental(Company $company, CarbonInterface $date, string $request): array
    {
        $menu = $this->dailyMenu->day($company, $date);
        $reply = $this->wantsBuffet($request)
            ? $this->dailyMenuMessage($menu, false)
            : $this->dailyMenuMessage($menu, true);

        return $this->analysis(
            $reply,
            $date,
            $this->operatingHours->status($company, $date),
            $reply !== '',
            'daily_menu',
            $reply === '' ? [] : [$reply],
        );
    }

    /** @param array<string,mixed> $operationalStatus @return array<string,mixed> */
    private function analysis(string $reply, CarbonInterface $date, array $operationalStatus, bool $menuAvailable, string $source = 'daily_menu', array $replyMessages = []): array
    {
        return [
            'intent' => 'MENU_REQUEST',
            'confidence' => 1,
            'summary' => 'Solicitação de cardápio do dia.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'reply_messages' => $replyMessages,
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
        $heading = $price > 0 ? "• *{$name} – R$ {$this->money($price)}*" : "• *{$name}*";
        $details = $this->decisionFacts->summary($this->decisionFacts->fromProduct($product));

        return $details === '' ? $heading : "{$heading}\n{$details}";
    }

    /** @return list<string> */
    private function completeMessages($categories, array $menu): array
    {
        $blocks = $categories->map(fn (array $category): string => '*'.mb_strtoupper(trim((string) ($category['name'] ?? 'Opções')))."*\n\n"
            .collect($category['products'])->map(fn (array $product): string => $this->productBlock($product))->implode("\n\n"));
        $messages = collect(['Claro! ☀️ Aqui está o cardápio completo de hoje:']);
        $blocks->each(function (string $block) use ($messages): void {
            $lastKey = $messages->keys()->last();
            $last = (string) $messages->last();
            if (mb_strlen($last."\n\n".$block) <= 1400) {
                $messages->put($lastKey, $last."\n\n".$block);

                return;
            }

            if ($messages->count() < 2) {
                $messages->push($block);

                return;
            }

            $messages->put($lastKey, $last."\n\n".$block);
        });
        $daily = $this->dailyMenuMessage($menu, true);
        $ending = ($daily === '' ? '' : $daily."\n\n").$this->cta($categories);
        $messages->push($ending);

        return $messages->take(3)->values()->all();
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

        $reply = "🍱 *MARMITAS*\n\n".$products->implode("\n\n");
        $daily = $this->dailyMenuMessage($menu, true);
        if ($daily !== '') {
            $reply .= "\n\n".$daily;
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

        return $reply."\n\n☀️ ".$this->cta($categories);
    }

    private function wantsCompleteMenu(string $request): bool
    {
        $request = Str::of($request)->ascii()->lower()->squish()->toString();

        return preg_match('/\b(?:cardapio|menu)\s+(?:bem\s+)?completo\b|\bcompleto\s+(?:cardapio|menu)\b/', $request) === 1
            || Str::contains($request, ['todas as opcoes', 'tudo que voces tem']);
    }

    private function wantsBuffet(string $request): bool
    {
        $request = Str::of($request)->ascii()->lower()->squish()->toString();

        return preg_match('/\b(?:buffet|acompanhamentos?|guarnicoes?)\b/', $request) === 1;
    }

    private function productRule(array $product): string
    {
        $rule = (string) data_get($product, 'resolved_configuration.static_configuration.menu_rule_code', '');
        if (str_ends_with($rule, '_tradicional')) {
            return 'Livre: você monta com os itens disponíveis no buffet do dia.';
        }
        if (! str_ends_with($rule, '_casa')) {
            return '';
        }

        $fixed = collect(data_get($product, 'resolved_configuration.static_configuration.groups', []))
            ->filter(fn (mixed $group): bool => is_array($group) && ($group['selection_mode'] ?? null) === 'fixed')
            ->flatMap(fn (array $group): array => (array) ($group['component_options'] ?? []))
            ->filter(fn (mixed $option): bool => is_array($option) && (bool) ($option['available'] ?? true))
            ->map(fn (array $option): string => trim((string) ($option['display_name'] ?? $option['name'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        return 'Casa: composição definida pelo restaurante'.($fixed->isEmpty() ? '.' : ' — '.$fixed->implode(', ').'.');
    }

    private function meatRule(array $product): string
    {
        $resolved = (array) data_get($product, 'resolved_configuration', []);
        $selection = (array) data_get($resolved, 'meat_selection', []);
        if ($selection !== []) {
            $min = max(0, (int) ($selection['min'] ?? 0));
            $max = max($min, (int) ($selection['max'] ?? 0));
            if ($max > 1) {
                return $min > 0 ? "Escolha de {$min} até {$max} carnes." : "Escolha até {$max} carnes.";
            }
            if ($max === 1) {
                return 'Inclui 1 carne.';
            }
        }

        $group = collect(data_get($resolved, 'static_configuration.groups', []))->firstWhere('code', 'carne');
        if (! is_array($group)) {
            return '';
        }
        $types = max(0, (int) ($group['max_choices'] ?? 0));
        $quantity = max($types, (int) ($group['max_quantity'] ?? 0));
        if ($quantity > 1 && $types === 1) {
            return "Inclui {$quantity} porções de 1 carne.";
        }

        return $types === 1 ? 'Inclui 1 carne.' : ($types > 1 ? "Escolha até {$types} carnes." : '');
    }

    private function dailyMenuMessage(array $menu, bool $includeMeats): string
    {
        $buffet = collect(data_get($menu, 'sections', []))
            ->except('meat')
            ->flatMap(fn (mixed $items): array => is_array($items) ? $items : [])
            ->filter(fn (mixed $item): bool => is_array($item) && (bool) ($item['available'] ?? false))
            ->map(fn (array $item): string => trim((string) data_get($item, 'component.display_name', data_get($item, 'component.name', ''))))
            ->filter()
            ->unique()
            ->values();
        $meats = collect(data_get($menu, 'sections.meat', []))
            ->filter(fn (mixed $item): bool => is_array($item) && (bool) ($item['available'] ?? false))
            ->map(fn (array $item): string => trim((string) data_get($item, 'component.display_name', data_get($item, 'component.name', ''))))
            ->filter()
            ->unique()
            ->values();
        $blocks = collect();
        if ($buffet->isNotEmpty()) {
            $blocks->push("🥗 *Buffet de hoje*\n• ".$buffet->implode("\n• "));
        }
        if ($includeMeats && $meats->isNotEmpty()) {
            $blocks->push("🥩 *Carnes de hoje*\n• ".$meats->implode("\n• "));
        }

        return $blocks->implode("\n\n");
    }

    private function cta($categories): string
    {
        $alternatives = $categories
            ->reject(fn (array $category): bool => ($category['slug'] ?? null) === 'marmitas')
            ->pluck('name')
            ->filter()
            ->take(5)
            ->values();
        $suffix = $alternatives->isEmpty()
            ? ''
            : ' ou prefere pedir '.$alternatives->implode(', ');

        return 'Quer que eu monte uma marmita para você'.$suffix.'? 😊';
    }
}
