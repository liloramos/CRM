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
    ) {}

    /** @return array<string,mixed> */
    public function build(Company $company, CarbonInterface $date, string $message): array
    {
        $product = $this->aliases->resolveFromText($company, $message);
        if (! $product instanceof Product) {
            return $this->unresolved();
        }

        $configuration = $this->products->configuration($product, $company, $date);
        $detail = $this->matchingComponent($configuration, $message);
        $name = $detail['name'] ?? (string) $product->name;
        $available = array_key_exists('available', $detail)
            ? (bool) $detail['available']
            : (bool) data_get($configuration, 'availability.available', false);

        if (! $available) {
            return $this->analysis(
                "Hoje {$name} nao esta disponivel.",
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
    private function unresolved(): array
    {
        return [
            'intent' => 'PRODUCT_CLARIFICATION',
            'confidence' => 1,
            'summary' => 'Consulta de produto sem correspondencia segura no catalogo.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [['code' => 'MENU_ITEM', 'label' => 'Produto']],
            'warnings' => [['code' => 'UNRESOLVED_PRODUCT_QUERY', 'message' => 'Nao foi possivel identificar o produto consultado.']],
            'suggested_reply' => '',
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
}
