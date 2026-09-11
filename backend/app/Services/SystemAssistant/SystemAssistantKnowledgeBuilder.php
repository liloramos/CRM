<?php

namespace App\Services\SystemAssistant;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Ai\CopilotProductClarificationReplyBuilder;
use App\Services\CounterSales\CounterSaleCatalogService;
use App\Services\Menu\DailyStructuredMenuService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SystemAssistantKnowledgeBuilder
{
    public const MAX_HISTORY_MESSAGES = 6;

    public const MAX_HISTORY_MESSAGE_LENGTH = 600;

    public const MAX_HISTORY_TOTAL_LENGTH = 2400;

    public function __construct(
        private readonly SystemAssistantFeatureCatalog $catalog,
        private readonly CounterSaleCatalogService $counterSales,
        private readonly DailyStructuredMenuService $dailyMenu,
        private readonly CopilotProductClarificationReplyBuilder $productReplies,
    ) {}

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function build(User $user, Company $company, string $message, array $context = []): array
    {
        $normalized = $this->normalize($message);
        $knowledge = [];

        if ($user->hasPermissionTo('orders.manage') && $this->mentionsCounterSales($normalized, $context)) {
            $knowledge['counter_sales'] = $this->counterSales($company);
        }
        if ($user->hasPermissionTo('menu.view') && preg_match('/\b(?:cardapio|menu)\b/', $normalized) === 1) {
            $knowledge['menu_today'] = $this->menuToday($company);
        }
        if ($user->hasPermissionTo('delivery.view') && preg_match('/\bentreg/', $normalized) === 1) {
            $knowledge['deliveries_today'] = $this->deliveriesToday($company);
        }
        if ($user->hasPermissionTo('payments.view') && preg_match('/\b(?:pix|pagamento|comprovante)\b/', $normalized) === 1) {
            $knowledge['payments'] = $this->payments($company);
        }
        if ($user->hasPermissionTo('customers.view') && $this->isExplicitCustomerLookup($normalized)) {
            $knowledge['customers'] = $this->customers($company, $normalized);
        }

        return [
            'available_features' => collect($this->catalog->all())
                ->filter(fn (array $feature): bool => $feature['permission'] === null || $user->hasPermissionTo($feature['permission']))
                ->map(fn (array $feature): array => [
                    'label' => $feature['label'],
                    'route' => $feature['route'],
                    'purpose' => $feature['help'],
                ])->values()->all(),
            'relevant_knowledge' => $knowledge,
            'recent_history' => $this->history((array) ($context['recent_history'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $context @return array{answer:string,feature_key:string}|null */
    public function deterministicAnswer(User $user, Company $company, string $message, array $context = []): ?array
    {
        $normalized = $this->normalize($message);
        $history = collect($this->history((array) ($context['recent_history'] ?? [])));

        if ($user->hasPermissionTo('menu.view')
            && preg_match('/\b(?:quanto\s+custa|qual\s+(?:e\s+)?o\s+preco|preco\s+atual|valor\s+atual|tem|vende|possui)\b/', $normalized) === 1) {
            $product = $this->productReplies->build($company, $this->today($company), $message, $context);
            if ((int) data_get($product, 'metadata.product_id') > 0) {
                return [
                    'answer' => (string) $product['suggested_reply'],
                    'feature_key' => 'cardapio',
                ];
            }
        }

        $isSelfServiceQuestion = preg_match('/\bself\s*service\b/', $normalized) === 1;
        $isBeefFollowUp = preg_match('/\bbife\b/', $normalized) === 1
            && $history->contains(fn (array $entry): bool => str_contains($this->normalize($entry['text']), 'self service'));
        if (! $user->hasPermissionTo('orders.manage') || (! $isSelfServiceQuestion && ! $isBeefFollowUp)) {
            return null;
        }

        $products = collect($this->counterSales($company)['products']);
        if (preg_match('/\b(?:quanto\s+custa|qual\s+(?:e\s+)?o\s+preco|preco|valor)\b/', $normalized) === 1) {
            $product = $this->requestedCounterProduct($products, $normalized);
            if (is_array($product)) {
                return [
                    'answer' => $product['name'].' está por '.$product['price'].'. O valor vem do catálogo atual do Caixa.',
                    'feature_key' => 'caixa',
                ];
            }
        }

        if ($isBeefFollowUp || preg_match('/\bself\s*service\b.*\bbife\b|\bbife\b.*\bself\s*service\b/', $normalized) === 1) {
            $selfService = $products->firstWhere('slug', 'self-service');
            $beef = is_array($selfService) ? collect($selfService['additions'])->firstWhere('code', 'extra_beef') : null;
            $price = is_array($beef) ? ' O valor atual é '.$beef['price'].'.' : '';

            return [
                'answer' => 'Se houver bife adicional, marque essa opção no Caixa antes de concluir a venda ou ao fechar a comanda.'.$price,
                'feature_key' => 'caixa',
            ];
        }

        return [
            'answer' => "Para lançar um Self Service:\n1. Abra Caixa.\n2. No card Self Service, use Adicionar para venda direta ou Abrir comanda para finalizar depois.\n3. Vincular um cliente é opcional.\n4. Na venda direta, escolha a forma de pagamento e conclua; na comanda, informe os dados pendentes no fechamento.",
            'feature_key' => 'caixa',
        ];
    }

    /** @param list<mixed> $history @return list<array{role:string,text:string}> */
    public function history(array $history): array
    {
        $result = [];
        $total = 0;
        foreach (array_reverse(array_slice($history, -self::MAX_HISTORY_MESSAGES)) as $entry) {
            if (! is_array($entry) || ! in_array($entry['role'] ?? null, ['user', 'assistant'], true) || ! is_string($entry['text'] ?? null)) {
                continue;
            }
            $text = Str::squish(Str::limit($entry['text'], self::MAX_HISTORY_MESSAGE_LENGTH, ''));
            if ($text === '' || $total + mb_strlen($text) > self::MAX_HISTORY_TOTAL_LENGTH) {
                continue;
            }
            array_unshift($result, ['role' => $entry['role'], 'text' => $text]);
            $total += mb_strlen($text);
        }

        return $result;
    }

    /** @return array{products:list<array<string,mixed>>,flow:list<string>} */
    private function counterSales(Company $company): array
    {
        $date = $this->today($company);
        $products = collect($this->counterSales->products($company, $date))
            ->take(12)
            ->map(fn (array $product): array => [
                'name' => $product['name'],
                'slug' => $product['slug'],
                'pricing_mode' => $product['pricing_mode'],
                'price' => $this->money((int) $product['base_price_cents']).($product['pricing_mode'] === 'weight' ? '/kg' : ''),
                'additions' => collect($product['additions'] ?? [])->map(fn (array $addition): array => [
                    'code' => $addition['code'],
                    'name' => $addition['name'],
                    'price' => $this->money((int) $addition['price_cents']),
                ])->values()->all(),
            ])->values()->all();

        return [
            'products' => $products,
            'flow' => ['venda direta', 'abrir comanda', 'cliente opcional', 'forma de pagamento', 'finalizar venda', 'histórico'],
        ];
    }

    /** @return array<string,mixed> */
    private function menuToday(Company $company): array
    {
        $menu = $this->dailyMenu->day($company, $this->today($company));

        return [
            'date' => $menu['date'],
            'is_service_day' => $menu['is_service_day'],
            'sections' => collect($menu['sections'])->map(fn (array $items): array => collect($items)
                ->where('available', true)->take(12)->map(fn (array $item): string => (string) data_get($item, 'component.name'))->filter()->values()->all())->all(),
        ];
    }

    /** @return array<string,int> */
    private function deliveriesToday(Company $company): array
    {
        $query = Order::query()->where('company_id', $company->id)
            ->whereDate('order_date', $this->today($company)->toDateString())
            ->where('fulfillment_type', Order::FULFILLMENT_DELIVERY);

        return ['total' => (clone $query)->count(), 'out_for_delivery' => (clone $query)->where('delivery_status', Order::DELIVERY_STATUS_OUT_FOR_DELIVERY)->count()];
    }

    /** @return array<string,int> */
    private function payments(Company $company): array
    {
        $query = Payment::query()->where('company_id', $company->id)->where('method', Payment::METHOD_PIX);

        return [
            'pix_waiting' => (clone $query)->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_AWAITING_PROOF, Payment::STATUS_PROOF_RECEIVED])->count(),
        ];
    }

    /** @return list<array{id:string,name:string,phone:?string}> */
    private function customers(Company $company, string $message): array
    {
        $term = trim((string) preg_replace('/.*\b(?:cliente|cadastro)\b/', '', $message));
        $term = trim((string) preg_replace('/^(?:chamad[oa]|com\s+nome|nome|telefone)\s+/', '', $term));
        if (mb_strlen($term) < 2) {
            return [];
        }

        $phone = (string) preg_replace('/\D+/', '', $term);

        return Customer::query()->where('company_id', $company->id)
            ->where(function ($query) use ($term, $phone): void {
                $query->where('name', 'like', '%'.$term.'%');
                if (strlen($phone) >= 3) {
                    $query->orWhere('phone', 'like', '%'.$phone.'%');
                }
            })
            ->limit(5)->get(['id', 'name', 'phone'])->map(fn (Customer $customer): array => [
                'id' => (string) $customer->id, 'name' => $customer->name, 'phone' => $customer->phone,
            ])->all();
    }

    /** @param Collection<int,array<string,mixed>> $products */
    private function requestedCounterProduct($products, string $message): ?array
    {
        $slug = str_contains($message, 'self service') ? 'self-service'
            : (str_contains($message, 'somente carne') ? 'comida-por-kg-somente-carne'
                : (str_contains($message, 'kg') ? 'comida-por-kg-comum' : null));
        $product = $slug ? $products->firstWhere('slug', $slug) : null;

        return is_array($product) ? $product : null;
    }

    /** @param array<string,mixed> $context */
    private function mentionsCounterSales(string $message, array $context): bool
    {
        if (preg_match('/\b(?:self\s*service|caixa|balcao|comanda|comida\s+por\s+kg|venda\s+por\s+kg|venda\s+presencial|venda\s+rapida)\b/', $message) === 1) {
            return true;
        }

        return preg_match('/\bbife\b/', $message) === 1
            && collect($this->history((array) ($context['recent_history'] ?? [])))
                ->contains(fn (array $entry): bool => str_contains($this->normalize($entry['text']), 'self service'));
    }

    private function isExplicitCustomerLookup(string $message): bool
    {
        return preg_match('/\b(?:encontr|localiz|procur|busc).{0,24}\b(?:cliente|cadastro)\b|\b(?:cliente|cadastro)\b.{0,24}\b(?:chamad|telefone|nome)\b/', $message) === 1;
    }

    private function today(Company $company): CarbonImmutable
    {
        $company->loadMissing('setting');

        return CarbonImmutable::now((string) ($company->setting?->timezone ?: config('app.timezone')));
    }

    private function money(int $amountCents): string
    {
        return 'R$ '.number_format($amountCents / 100, 2, ',', '.');
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
