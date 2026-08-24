<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Str;

final class CopilotQuantityGroundingGuard
{
    public function __construct(private readonly CopilotProductEligibility $eligibility) {}

    /**
     * Grounds explicit customer quantities before trusting the provider's
     * proposed item shape. This keeps an invalid "zero N5" invalid even when
     * the provider omits the item altogether.
     *
     * @param  list<array<string,mixed>>  $items
     * @param  list<array<string,mixed>>  $messages
     * @return array{items:list<array<string,mixed>>,warnings:list<array{code:string,message:string}>,missing:list<array{code:string,label:string}>}
     */
    public function ground(Company $company, array $items, array $messages): array
    {
        $products = $this->eligibility->apply(Product::query())
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
        $itemsByProduct = collect($items)->groupBy('menu_item_id');
        $rejectedIndexes = [];
        $warnings = [];
        $missing = [];

        foreach ($products as $productId => $product) {
            $declared = $this->declaredQuantity($product, $messages);
            if ($declared === null) {
                continue;
            }

            $group = $itemsByProduct->get($productId, collect());
            $proposed = $group->sum(fn (array $item): int => max(0, (int) ($item['quantity'] ?? 0)));
            if ($declared > 0 && $group->isNotEmpty() && $declared === $proposed) {
                continue;
            }

            if ($declared > 0 && $group->isEmpty()) {
                continue;
            }

            foreach ($group->keys() as $index) {
                $rejectedIndexes[] = $index;
            }

            $warnings[] = [
                'code' => $declared < 1 ? 'INVALID_QUANTITY' : 'UNGROUNDED_QUANTITY',
                'message' => $declared < 1
                    ? 'A quantidade sugerida nao e valida.'
                    : 'A quantidade sugerida nao corresponde a quantidade informada pelo cliente.',
            ];
            $missing[] = ['code' => 'VALID_QUANTITY', 'label' => 'Quantidade valida'];
        }

        return [
            'items' => collect($items)->reject(fn (array $_item, int $index): bool => in_array($index, $rejectedIndexes, true))->values()->all(),
            'warnings' => $warnings,
            'missing' => $missing,
        ];
    }

    /** @param list<array<string,mixed>> $messages */
    private function declaredQuantity(Product $product, array $messages): ?int
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $text = Str::of((string) ($message['body'] ?? ''))->ascii()->lower()->toString();
            if (preg_match('/\b(?:outra|outro)\b/', $text) === 1) {
                continue;
            }
            foreach ($this->aliases($product) as $alias) {
                $pattern = '/\\b(?<quantity>0|[1-9]\\d*|zero|uma?|duas?|tres|quatro|cinco|seis|sete|oito|nove|dez)\\s+(?:de\\s+)?'.$alias.'\\b/u';
                if (preg_match($pattern, $text, $matches) === 1) {
                    return $this->number($matches['quantity']);
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function aliases(Product $product): array
    {
        $aliases = [preg_quote(Str::of($product->name)->ascii()->lower()->toString(), '/')];
        if (preg_match('/^n(\\d+)/', $product->slug, $match) === 1) {
            $aliases[] = 'n\\s*[- ]?\\s*'.$match[1].'(?:\\s+(?:casa|tradicional))?';
        }

        return array_values(array_unique($aliases));
    }

    private function number(string $value): int
    {
        return match ($value) {
            'zero' => 0,
            'um', 'uma' => 1,
            'dois', 'duas' => 2,
            'tres' => 3,
            'quatro' => 4,
            'cinco' => 5,
            'seis' => 6,
            'sete' => 7,
            'oito' => 8,
            'nove' => 9,
            'dez' => 10,
            default => (int) $value,
        };
    }
}
