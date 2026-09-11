<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use App\Services\Menu\StructuredProductConfigurationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class CopilotProductClarificationReplyBuilder
{
    public function __construct(
        private readonly CopilotMenuAliasResolver $aliases,
        private readonly StructuredProductConfigurationService $products,
        private readonly CopilotProductEligibility $eligibility,
        private readonly CopilotOrderClarificationReplyBuilder $orderClarifications,
    ) {}

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function build(Company $company, CarbonInterface $date, string $message, array $context = []): array
    {
        $product = $this->aliases->resolveFromTextIncludingInactive($company, $message);
        if (! $product instanceof Product) {
            $familyOptions = $this->matchingProductFamilyOptions($context, $message);
            if ($familyOptions !== []) {
                $labels = collect($familyOptions)->map(function (array $option): string {
                    $price = $this->money((int) data_get($option, 'resolved_configuration.base_price_cents', $option['base_price_cents'] ?? 0));

                    return '- '.$option['name'].' — '.$price;
                })->implode("\n");

                return $this->analysis(
                    "Temos estas opções hoje:\n{$labels}",
                    [
                        'catalog_scope' => 'product_family',
                        'offered_product_ids' => collect($familyOptions)->pluck('id')->all(),
                    ],
                );
            }

            $options = $this->orderClarifications->marmitaOptions($context);
            if ($options !== [] && ($this->isMarmitaOverviewRequest($message)
                || data_get($context, 'pending_clarification.type') === 'product_selection'
                || data_get($context, 'pending_order_state.offered_product_ids', []) !== [])) {
                return $this->analysis(
                    "Os valores das marmitas disponíveis são:\n".collect($options)->pluck('label')->implode("\n"),
                    ['catalog_scope' => 'marmitas', 'offered_product_ids' => collect($options)->pluck('id')->all()],
                );
            }

            return $this->unresolved($company);
        }

        $configuration = $this->products->configuration($product, $company, $date);
        $detail = $this->matchingComponent($configuration, $message);
        $name = $detail['name'] ?? (string) $product->name;
        $available = array_key_exists('available', $detail)
            ? (bool) $detail['available']
            : (bool) data_get($configuration, 'availability.available', false);

        if (! $available) {
            $alternative = $this->relevantAlternative($company, $date, $product);
            if ($alternative instanceof Product) {
                $alternativeConfiguration = $this->products->configuration($alternative, $company, $date);
                $alternativePrice = $this->money((int) data_get($alternativeConfiguration, 'base_price_cents', $alternative->base_price_cents));

                return $this->analysis(
                    "Hoje {$name} nao esta disponivel, mas temos {$alternative->name} por {$alternativePrice}. Quer que eu adicione?",
                    ['product_id' => $product->id, 'product_slug' => $product->slug, 'availability' => 'unavailable', 'alternative_product_id' => $alternative->id],
                );
            }

            $category = $product->category()->value('name');

            return $this->analysis(
                "Hoje {$name} nao esta disponivel.".($category ? " Quer ver as opcoes de {$category}?" : ''),
                ['product_id' => $product->id, 'product_slug' => $product->slug, 'availability' => 'unavailable'],
            );
        }

        $price = $this->money((int) data_get($configuration, 'base_price_cents', $product->base_price_cents));
        $text = $this->key($message);
        if (str_contains($text, 'oquevem')) {
            $description = trim((string) data_get($configuration, 'description', ''));
            $reply = "{$name} custa {$price}.";
            if ($description !== '') {
                $reply .= ' '.$description;
            }

            return $this->analysis($reply, ['product_id' => $product->id, 'product_slug' => $product->slug, 'availability' => 'available']);
        }

        if (preg_match('/\btem\b|\bvende\b|\bpossui\b/', Str::ascii($message)) === 1) {
            return $this->analysis(
                "Sim, temos {$name} disponivel hoje por {$price}.",
                ['product_id' => $product->id, 'product_slug' => $product->slug, 'availability' => 'available'],
            );
        }

        return $this->analysis(
            "O valor de {$name} e {$price}.",
            ['product_id' => $product->id, 'product_slug' => $product->slug, 'availability' => 'available'],
        );
    }

    /** @param array<string,mixed> $configuration @return array{name:string,available:bool}|array{} */
    private function matchingComponent(array $configuration, string $message): array
    {
        $text = $this->key($message);

        return collect(data_get($configuration, 'groups', []))
            ->flatMap(fn (array $group): array => (array) ($group['component_options'] ?? []))
            ->filter(function (array $option) use ($text): bool {
                return collect([
                    $option['display_name'] ?? null,
                    $option['name'] ?? null,
                    ...((array) ($option['search_aliases'] ?? [])),
                ])->map(fn (mixed $value): string => $this->key((string) $value))
                    ->contains(fn (string $name): bool => strlen($name) > 2 && str_contains($text, $name));
            })
            ->map(fn (array $option): array => [
                'name' => (string) ($option['display_name'] ?? $option['name'] ?? 'Produto'),
                'available' => (bool) ($option['available'] ?? false),
            ])
            ->first() ?? [];
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function analysis(string $reply, array $metadata): array
    {
        return [
            'intent' => 'PRODUCT_CLARIFICATION',
            'confidence' => 1,
            'summary' => 'Consulta de produto respondida pelo catalogo operacional.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'metadata' => ['reply_source' => 'product_catalog', ...$metadata],
        ];
    }

    /** @return array<string,mixed> */
    private function unresolved(Company $company): array
    {
        $reply = 'Essa opção não consta no cardápio disponível de hoje.';
        $reply .= ' Quer ver as opções da categoria que você procura?';

        return [
            'intent' => 'PRODUCT_CLARIFICATION',
            'confidence' => 1,
            'summary' => 'Consulta de produto sem correspondencia segura no catalogo.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [['code' => 'MENU_ITEM', 'label' => 'Produto']],
            'warnings' => [['code' => 'ITEM_NOT_FOUND', 'message' => 'O item informado não consta no cardápio atual.']],
            'suggested_reply' => $reply,
            'metadata' => ['reply_source' => 'product_catalog'],
        ];
    }

    private function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function isMarmitaOverviewRequest(string $message): bool
    {
        $text = Str::of($message)->ascii()->lower()->squish()->toString();

        return preg_match('/\b(marmita|marmitex|marmitas|marmitexs)\b/', $text) === 1
            || preg_match('/\b(quais?\s+(?:os?\s+)?(?:valores?|precos?))\b/', $text) === 1;
    }

    /** @param array<string,mixed> $context @return list<array<string,mixed>> */
    private function matchingProductFamilyOptions(array $context, string $message): array
    {
        $tokens = collect(preg_split('/[^a-z0-9]+/', Str::of($message)->ascii()->lower()->toString()) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 3)
            ->reject(fn (string $token): bool => in_array($token, ['tem', 'vende', 'possui', 'hoje', 'voces', 'vcs', 'essa', 'esse', 'opcao'], true))
            ->unique()
            ->values();
        if ($tokens->isEmpty()) {
            return [];
        }

        return collect((array) data_get($context, 'menu', []))
            ->filter(function (mixed $product) use ($tokens): bool {
                if (! is_array($product)
                    || data_get($product, 'resolved_configuration.availability.available', true) !== true) {
                    return false;
                }

                $identity = Str::of(($product['name'] ?? '').' '.($product['slug'] ?? ''))
                    ->ascii()
                    ->lower()
                    ->toString();

                return $tokens->contains(fn (string $token): bool => str_contains($identity, $token));
            })
            ->take(8)
            ->values()
            ->all();
    }

    private function relevantAlternative(Company $company, CarbonInterface $date, Product $requested): ?Product
    {
        $candidates = $this->eligibility->apply(Product::query())
            ->where('company_id', $company->id)
            ->where('category_id', $requested->category_id)
            ->where('product_type', $requested->product_type)
            ->where('is_active', true)
            ->where('is_available_by_default', true)
            ->whereKeyNot($requested->id)
            ->orderBy('display_order')
            ->get()
            ->filter(fn (Product $candidate): bool => (bool) data_get($this->products->configuration($candidate, $company, $date), 'availability.available', false));

        $family = $this->familyKey($requested->name);

        return $candidates->first(fn (Product $candidate): bool => $family !== '' && str_contains($this->key($candidate->name), $family));
    }

    private function familyKey(string $name): string
    {
        return collect(preg_split('/\s+/', Str::of($name)->ascii()->lower()->toString()))
            ->map(fn (string $part): string => $this->key($part))
            ->first(fn (string $part): bool => strlen($part) >= 3) ?? '';
    }
}
