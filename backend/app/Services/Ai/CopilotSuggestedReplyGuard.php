<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotSuggestedReplyGuard
{
    public function __construct(
        private readonly CopilotProductClarificationBuilder $clarifications,
        private readonly CustomerFacingProductPresenter $products,
    ) {}

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed> */
    public function restrict(array $safe, array $context): array
    {
        if (in_array((string) data_get($safe, 'metadata.reply_source'), ['daily_menu', 'operating_hours', 'operating_hours_unconfigured', 'customer_facing_policy'], true)) {
            return [...$safe, 'clarification' => null];
        }
        $clarification = $this->clarifications->forSafe($safe, $context);
        if ($clarification !== null) {
            return [...$safe, 'clarification' => $clarification, 'suggested_reply' => $this->clarifications->reply($clarification)];
        }

        $reply = trim((string) ($safe['suggested_reply'] ?? ''));

        if ($this->hasEquivalentChangeTarget($safe) && $this->mentionsPhysicalTarget($reply)) {
            return [...$safe, 'clarification' => null, 'suggested_reply' => $this->safeFallback($safe, $context)];
        }

        if (data_get($safe, 'draft_order.items', []) === [] && ($this->assumesResolvedMenuChoice($reply) || $this->mentionsSafeOnlyOrderData($reply))) {
            return [...$safe, 'clarification' => null, 'suggested_reply' => $this->safeFallback($safe, $context)];
        }

        if (in_array((string) ($safe['intent'] ?? ''), ['ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE'], true)) {
            return [...$safe, 'clarification' => null, 'suggested_reply' => $this->safeFallback($safe, $context)];
        }

        if ($reply === '' || ! $this->contradictsResolvedFacts($reply, $safe)) {
            return [...$safe, 'clarification' => null];
        }

        return [...$safe, 'clarification' => null, 'suggested_reply' => $this->safeFallback($safe, $context)];
    }

    /** @param array<string,mixed> $safe */
    private function contradictsResolvedFacts(string $reply, array $safe): bool
    {
        $text = Str::of($reply)->ascii()->lower()->toString();
        if (preg_match('/\b(registrei|criei|adicionei|inclui|alterei|atualizei|coloquei|deixei|anotei|encaminhei|vou\s+encaminhar|pedido\s+anotado|ja\s+(?:esta|ficou)|confirmei(?:\s+pagamento)?)\b|\b(rascunho|proposta|produto\s+seguro|safe\s+result|revisao\s+humana|review|target|mutation|pedido\s+ativo|resolved\s+configuration)\b/', $text)) {
            return true;
        }

        $items = array_values(data_get($safe, 'draft_order.items', []));
        if ($this->hasChangeMeatSelection($safe) && preg_match('/\bqual(?:\s+e)?\s+a?s?\s*carne/', $text) === 1) {
            return true;
        }
        if ($this->hasChangeMeatSelection($safe)
            && str_contains((string) data_get($safe, 'draft_order.change_request.product_slug'), 'n8-tradicional')
            && preg_match('/n8\s*livre.*(?:nao|sem).*escolha.*carne/', $text) === 1) {
            return true;
        }
        $productSlugs = array_filter(array_map(fn (mixed $item): string => is_array($item) ? (string) ($item['menu_item_slug'] ?? '') : '', $items));
        if (array_intersect($productSlugs, ['n8-casa', 'n8-tradicional']) !== []
            && preg_match('/n8.*(?:casa.*livre|livre.*casa)/', $text)) {
            return true;
        }

        if ($items !== []
            && collect($items)->every(fn (mixed $item): bool => is_array($item) && (int) ($item['quantity'] ?? 0) > 0)
            && preg_match('/\b(quantos|quantas|quantidade)\b/', $text)) {
            return true;
        }

        $missingCodes = array_map(
            fn (mixed $missing): string => strtoupper((string) data_get($missing, 'code', '')),
            data_get($safe, 'missing_information', []),
        );
        if (! in_array('SALADA', $missingCodes, true)
            && preg_match('/\bqual(?:\s+e)?\s+a?s?\s*salada\b/', $text) === 1) {
            return true;
        }

        return ($this->hasSelection($items, ['meat', 'meats']) && preg_match('/\bqual(?:\s+e)?\s+a?s?\s*carne/', $text))
            || ($this->hasSelection($items, ['salad', 'salads', 'salada', 'saladas']) && preg_match('/\bqual(?:\s+e)?\s+a?s?\s*salada/', $text));
    }

    private function assumesResolvedMenuChoice(string $reply): bool
    {
        $text = Str::of($reply)->ascii()->lower()->toString();

        return preg_match('/\bqual(?:\s+e)?\s+a?s?\s*(?:carne|salada)\b/', $text) === 1;
    }

    private function mentionsSafeOnlyOrderData(string $reply): bool
    {
        $text = Str::of($reply)->ascii()->lower()->toString();

        return preg_match('/\bn\s*[- ]?\s*(?:5|8|9)\b|\b(?:almond|porco|frango|bife|pix|entrega)\b/', $text) === 1;
    }

    /** @param list<mixed> $items @param list<string> $keys */
    private function hasSelection(array $items, array $keys): bool
    {
        foreach ($items as $item) {
            if (! is_array($item) || ! is_array($item['selections'] ?? null)) {
                continue;
            }

            foreach ($keys as $key) {
                $value = $item['selections'][$key] ?? null;
                if ((is_array($value) && $value !== []) || (is_string($value) && $value !== '')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $safe */
    private function hasChangeMeatSelection(array $safe): bool
    {
        $selections = data_get($safe, 'draft_order.change_request.to_selections', []);

        return is_array($selections) && collect($selections['meats'] ?? $selections['meat'] ?? [])
            ->contains(fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /** @param array<string,mixed> $safe */
    private function hasEquivalentChangeTarget(array $safe): bool
    {
        return data_get($safe, 'intent') === 'ORDER_CHANGE'
            && data_get($safe, 'draft_order.change_request.target_items_equivalent') === true;
    }

    private function mentionsPhysicalTarget(string $reply): bool
    {
        $text = Str::of($reply)->ascii()->lower()->toString();

        return preg_match('/\b(primeir[ao]|segund[ao]|qual\s+das?\s+duas|qual\s+dos?\s+2)\b/', $text) === 1;
    }

    /** @param array<string,mixed> $safe */
    private function safeFallback(array $safe, array $context = []): string
    {
        $warnings = array_column(data_get($safe, 'warnings', []), 'code');
        $missing = array_column(data_get($safe, 'missing_information', []), 'code');

        if (in_array('CONFLICTING_MEAT_REQUEST', $warnings, true)) {
            return 'Você quer somente bife ou porco com bife adicional?';
        }
        if (in_array('CARNE', $missing, true)) {
            return 'Qual carne você deseja?';
        }
        if (in_array('TARGET_ORDER_ITEM', $missing, true)) {
            $change = data_get($safe, 'draft_order.change_request', []);
            $product = (string) data_get($change, 'product_name', 'item');
            $target = (string) data_get($change, 'to_selections.meats.0', data_get($change, 'to_selections.meat', ''));
            $count = count(data_get($change, 'target_item_ids', []));
            $subject = $count > 1 ? "dos {$count} itens {$product}" : "do item {$product}";

            return $target !== ''
                ? "Qual {$subject} você quer alterar para {$target}?"
                : "Qual {$subject} você quer alterar?";
        }
        if ($this->hasEquivalentChangeTarget($safe)) {
            $change = data_get($safe, 'draft_order.change_request', []);
            $product = (string) data_get($change, 'product_name', 'item');
            $target = (string) data_get($change, 'to_selections.meats.0', data_get($change, 'to_selections.meat', ''));

            return $target !== ''
                ? "Entendi: uma {$product} continua como esta e outra sera com {$target}. Confere?"
                : 'Vou confirmar essa alteracao para voce.';
        }
        if (in_array('ORDER_CHANGE_REQUIRES_REVIEW', $warnings, true)) {
            return 'Vou confirmar essa alteracao para voce.';
        }
        if (in_array('SALADA', $missing, true)) {
            return 'Qual salada você deseja?';
        }
        if (in_array('ADDRESS', $missing, true)) {
            return 'Qual é o endereço para a entrega?';
        }

        $items = collect(data_get($safe, 'draft_order.items', []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($context): string {
                $menuProduct = collect(data_get($context, 'menu', []))
                    ->firstWhere('id', (int) ($item['menu_item_id'] ?? 0));
                $name = $this->products->name($item, $context);
                $description = $this->products->description($item, $context);
                $meats = collect(data_get($item, 'selections.meats', []))->filter()->implode(' e ');
                $detail = match (data_get($item, 'selections.meat_mode', 'traditional')) {
                    'none' => ' sem carne',
                    'beef_only' => ' somente bife',
                    default => $description !== ''
                        ? ' com '.$description
                        : '',
                };

                if ($description === '' && $meats !== '') {
                    $detail = " com {$meats}";
                }
                if (str_contains(Str::of((string) ($item['item_notes'] ?? ''))->ascii()->lower()->toString(), 'salada a escolha da casa')) {
                    $detail .= $detail === '' ? ' com salada por conta da casa' : ' e salada por conta da casa';
                }

                return max(1, (int) ($item['quantity'] ?? 1))." {$name}{$detail}";
            })
            ->values();
        if ($items->isEmpty()) {
            return '';
        }

        $reply = 'Entendi: '.$items->implode(', ');
        if (data_get($safe, 'draft_order.fulfillment') === 'delivery') {
            $address = trim((string) data_get($safe, 'draft_order.address', ''));
            $reply .= $address !== '' ? ' para entrega em '.Str::title($address) : ' para entrega';
        }
        if (filled(data_get($safe, 'draft_order.payment_method'))) {
            $method = (string) data_get($safe, 'draft_order.payment_method');
            $reply .= ' com pagamento por '.(mb_strtolower($method) === 'pix' ? 'Pix' : $method);
        }

        return $reply.'. Confere?';
    }
}
