<?php

namespace App\Services\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CopilotProductClarificationBuilder
{
    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed>|null */
    public function forSafe(array $safe, array $context): ?array
    {
        if (! $this->needsProductClarification($safe, $context)) {
            return null;
        }

        $reference = $this->reference((string) data_get($context, 'latest_message.body', ''));
        $candidates = $this->candidates(data_get($context, 'menu', []), $reference);
        if ($reference !== null && $candidates->count() >= 2 && $candidates->count() <= 5) {
            return [
                'type' => 'PRODUCT',
                'prompt' => "Qual produto {$reference} você deseja?",
                'options' => $candidates->all(),
                'source' => 'MENU',
                'grounded' => true,
            ];
        }

        return [
            'type' => 'PRODUCT',
            'prompt' => 'Qual marmita você deseja? Posso te mostrar as opções do cardápio de hoje.',
            'options' => [],
            'source' => 'MENU',
            'grounded' => true,
        ];
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed>|null */
    public function forAmbiguousMeat(array $safe, array $context): ?array
    {
        $pending = $this->forPendingAmbiguousMeat($safe, $context);
        if ($pending !== null) {
            return $pending;
        }

        if ((string) ($safe['intent'] ?? '') !== 'ORDER_CREATE'
            || data_get($context, 'active_order') !== null
            || ! in_array('AMBIGUOUS_MEAT', array_column((array) ($safe['warnings'] ?? []), 'code'), true)) {
            return null;
        }

        $item = data_get($safe, 'draft_order.items.0');
        if (! is_array($item) || count((array) data_get($safe, 'draft_order.items', [])) !== 1) {
            return null;
        }
        $menuItem = collect((array) data_get($context, 'menu', []))->firstWhere('id', (int) ($item['menu_item_id'] ?? 0));
        if (! is_array($menuItem) || ! in_array((string) ($menuItem['rule'] ?? ''), ['n8_tradicional', 'n9_tradicional'], true)) {
            return null;
        }

        $message = Str::of((string) data_get($context, 'latest_message.body', ''))->ascii()->lower()->toString();
        $options = collect((array) data_get($context, 'daily_meats', []))
            ->filter(fn (array $meat): bool => (int) ($meat['id'] ?? 0) > 0 && $this->matchesMeatReference($message, $meat))
            ->map(fn (array $meat): array => ['component_id' => (int) $meat['id'], 'display_name' => (string) $meat['name']])
            ->unique('component_id')->sortBy('display_name')->values();
        if ($options->count() < 2 || $options->count() > 5) {
            return null;
        }

        return [
            'type' => 'MEAT',
            'prompt' => 'Hoje temos '.$options->pluck('display_name')->implode(' e ').'. Qual você prefere?',
            'options' => $options->all(),
            'source' => 'DAILY_MENU',
            'grounded' => true,
            'scope' => ['product_id' => (int) $item['menu_item_id'], 'selection_group' => 'meat'],
        ];
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed>|null */
    private function forPendingAmbiguousMeat(array $safe, array $context): ?array
    {
        if ((string) ($safe['intent'] ?? '') !== 'ORDER_CONTINUE'
            || data_get($context, 'pending_clarification.status') !== 'eligible'
            || ! in_array(data_get($context, 'pending_clarification.resolution.status'), ['ambiguous', 'invalid'], true)) {
            return null;
        }

        $options = collect((array) data_get($context, 'pending_clarification.options', []))
            ->filter(fn (array $option): bool => (int) ($option['component_id'] ?? 0) > 0 && trim((string) ($option['display_name'] ?? '')) !== '')
            ->values();
        if ($options->count() < 2 || $options->count() > 5) {
            return null;
        }

        return [
            'type' => 'MEAT',
            'prompt' => 'Para essa marmita, qual dessas carnes você prefere?',
            'options' => $options->all(),
            'source' => 'DAILY_MENU',
            'grounded' => true,
            'scope' => (array) data_get($context, 'pending_clarification.scope', []),
        ];
    }

    /** @param array<string,mixed> $clarification */
    public function reply(array $clarification): string
    {
        $options = array_values(data_get($clarification, 'options', []));
        if ($options === []) {
            return (string) ($clarification['prompt'] ?? '');
        }

        return (string) ($clarification['prompt'] ?? '')."\n".collect($options)
            ->values()
            ->map(fn (array $option, int $index): string => ($index + 1).'. '.(string) ($option['display_name'] ?? ''))
            ->implode("\n");
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context */
    private function needsProductClarification(array $safe, array $context): bool
    {
        $latestMessage = Str::of((string) data_get($context, 'latest_message.body', ''))->ascii()->lower()->toString();
        if (data_get($safe, 'draft_order.items', []) !== []
            || preg_match('/\b(quero|manda|mandar|me\s+da|me\s+ve)\b/', $latestMessage) !== 1
            || preg_match('/\b(ignore|ignora)\b.*\b(regra|regras|instrucao|instrucoes)\b/', $latestMessage) === 1) {
            return false;
        }

        $missing = array_map(fn (mixed $item): string => strtoupper((string) data_get($item, 'code', '')), data_get($safe, 'missing_information', []));
        $warnings = array_map(fn (mixed $item): string => strtoupper((string) data_get($item, 'code', '')), data_get($safe, 'warnings', []));

        return array_intersect($missing, ['PRODUCT', 'MENU_ITEM']) !== []
            && ! in_array('UNTRUSTED_INSTRUCTION', $warnings, true);
    }

    private function reference(string $message): ?string
    {
        $ignored = ['quero', 'queria', 'gostaria', 'preciso', 'pedido', 'marmita', 'produto', 'favor', 'manda', 'me', 'uma', 'um', 'pra', 'para', 'com', 'sem'];
        $tokens = collect(preg_split('/[^a-z0-9]+/', Str::of($message)->ascii()->lower()->toString()) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, $ignored, true))
            ->values();

        return $tokens->count() === 1 ? $tokens->first() : null;
    }

    private function candidates(mixed $menu, string $reference): Collection
    {
        return collect(is_array($menu) ? $menu : [])
            ->filter(fn (mixed $product): bool => is_array($product) && (int) ($product['id'] ?? 0) > 0)
            ->filter(function (array $product) use ($reference): bool {
                $haystack = Str::of(((string) ($product['name'] ?? '')).' '.((string) ($product['slug'] ?? '')))->ascii()->lower()->toString();

                return str_contains($haystack, $reference);
            })
            ->map(fn (array $product): array => [
                'menu_item_id' => (int) $product['id'],
                'menu_item_slug' => (string) $product['slug'],
                'display_name' => (string) $product['name'],
            ])
            ->unique('menu_item_id')
            ->sortBy('display_name')
            ->values();
    }

    /** @param array<string,mixed> $meat */
    private function matchesMeatReference(string $message, array $meat): bool
    {
        return collect(preg_split('/[^a-z0-9]+/', Str::of((string) ($meat['name'] ?? ''))->ascii()->lower()->toString()) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 4)
            ->contains(fn (string $token): bool => str_contains($message, $token));
    }
}
