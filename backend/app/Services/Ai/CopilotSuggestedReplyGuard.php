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
        if (in_array((string) data_get($safe, 'metadata.reply_source'), ['daily_menu', 'product_catalog', 'operating_hours', 'operating_hours_unconfigured', 'customer_facing_policy', 'customer_location_received', 'order_start', 'order_clarification', 'recovery_clarification', 'explicit_handoff', 'resolved_turn_product_discovery', 'semantic_grounded_product_information', 'semantic_grounded_comparison', 'semantic_grounded_options'], true)) {
            return [...$safe, 'clarification' => null];
        }
        $clarification = $this->clarifications->forAmbiguousMeat($safe, $context)
            ?? $this->clarifications->forSafe($safe, $context);
        if ($clarification !== null) {
            $reply = $this->clarifications->reply($clarification);

            return $this->withBackendReply($safe, $reply, ['clarification' => $clarification]);
        }

        $reply = trim((string) ($safe['suggested_reply'] ?? ''));

        if ($this->hasEquivalentChangeTarget($safe) && $this->mentionsPhysicalTarget($reply)) {
            $reply = $this->safeFallback($safe, $context);

            return $this->withBackendReply($safe, $reply);
        }

        if (data_get($safe, 'draft_order.items', []) === [] && ($this->assumesResolvedMenuChoice($reply) || $this->mentionsSafeOnlyOrderData($reply))) {
            $reply = $this->safeFallback($safe, $context);

            return $this->withBackendReply($safe, $reply);
        }

        if (in_array((string) ($safe['intent'] ?? ''), ['ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CONFIRMATION', 'ORDER_CHANGE'], true)) {
            $reply = $this->safeFallback($safe, $context);

            return $this->withBackendReply($safe, $reply);
        }

        if ($reply === '' || ! $this->contradictsResolvedFacts($reply, $safe)) {
            return [...$safe, 'clarification' => null];
        }

        $reply = $this->safeFallback($safe, $context);

        return $this->withBackendReply($safe, $reply);
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $extra @return array<string,mixed> */
    private function withBackendReply(array $safe, string $reply, array $extra = []): array
    {
        return [
            ...$safe,
            ...$extra,
            'clarification' => $extra['clarification'] ?? null,
            'suggested_reply' => $reply,
            'reply_messages' => [$reply],
            'metadata' => [...(array) ($safe['metadata'] ?? []), 'reply_composed_by' => 'backend'],
        ];
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
        $constraint = collect((array) data_get($safe, 'metadata.constraints', []))
            ->first(fn (mixed $entry): bool => is_array($entry) && ($entry['code'] ?? null) === 'MEAT_ALLOWANCE_EXCEEDED');

        if (is_array($constraint)) {
            $product = (string) ($constraint['product_name'] ?? 'essa marmita');
            $requested = collect((array) ($constraint['requested'] ?? []))->filter()->implode(', ');
            $included = max(0, (int) ($constraint['included_max'] ?? 0));
            $allowance = $included === 1 ? '1 tipo de carne' : "{$included} tipos de carne";
            if (($constraint['resolution'] ?? null) === 'customer_choice_required') {
                return "Na {$product}, estão incluídos até {$allowance}. Você pediu {$requested}. Qual você quer manter? 😊";
            }

            $additional = (int) ($constraint['additional_total_cents'] ?? 0);
            $reply = "Na {$product}, estão incluídos até {$allowance}. Como você pediu {$requested}, o adicional canônico de *R$ "
                .number_format($additional / 100, 2, ',', '.').'* já entrou no cálculo.';
            $reply .= ' Se preferir evitar o adicional, posso retirar alguma delas.';
            if (blank(data_get($safe, 'draft_order.fulfillment'))) {
                $reply .= "\n\nVai ser para retirada ou entrega? 😊";
            }

            return $reply;
        }

        if (in_array('CONFLICTING_MEAT_REQUEST', $warnings, true)) {
            return 'Você quer somente bife ou porco com bife adicional?';
        }
        if (in_array('CARNE', $missing, true)) {
            return 'Perfeito. Agora falta escolher a carne. Qual você deseja?';
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
        if (in_array('ACOMPANHAMENTO', $missing, true)) {
            $candidate = collect((array) data_get($safe, 'draft_order.items', []))
                ->flatMap(fn (mixed $item): array => is_array($item) ? (array) ($item['daily_component_candidates'] ?? []) : [])
                ->first(fn (mixed $entry): bool => is_array($entry) && (array) ($entry['names'] ?? []) !== []);
            $names = is_array($candidate) ? array_values((array) ($candidate['names'] ?? [])) : [];
            if ($names !== []) {
                return 'Anotei o restante 😊 Para o acompanhamento, você prefere '.implode(' ou ', $names).'?';
            }

            return 'Qual acompanhamento você prefere?';
        }
        if (in_array('ADDRESS', $missing, true)) {
            return 'Qual é o endereço para a entrega?';
        }
        if (in_array('PAYMENT_METHOD', $missing, true)) {
            return 'Recebi os dados da entrega. Como você prefere pagar?';
        }

        $items = collect(data_get($safe, 'draft_order.items', []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($context): string {
                $menuProduct = collect(data_get($context, 'menu', []))
                    ->firstWhere('id', (int) ($item['menu_item_id'] ?? 0));
                $name = $this->products->name($item, $context);
                $description = $this->products->description($item, $context);
                $meatSelections = data_get($item, 'selections.meats', []);
                if ($meatSelections === [] && filled(data_get($item, 'selections.meat'))) {
                    $meatSelections = [data_get($item, 'selections.meat')];
                }
                $meats = collect($meatSelections)->filter()->implode(' e ');
                $detail = match (data_get($item, 'selections.meat_mode', 'traditional')) {
                    'none' => ' sem carne',
                    'beef_only' => ' somente bife',
                    default => $description !== ''
                        ? ' com '.$description
                        : '',
                };

                if ($meats !== '') {
                    $detail .= $detail === '' ? " com {$meats}" : " e {$meats}";
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

        if (blank(data_get($safe, 'draft_order.fulfillment'))
            && in_array((string) ($safe['intent'] ?? ''), ['ORDER_CREATE', 'ORDER_CONTINUE'], true)
            && data_get($safe, 'draft_order.items', []) !== []
            && empty($safe['missing_information'])
            && empty($safe['warnings'])) {
            return $reply.'. Vai ser para retirada ou entrega? 😊';
        }

        return $reply.'. Confere?';
    }
}
