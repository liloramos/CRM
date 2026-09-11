<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotLatestMessageIntentResolver
{
    public function __construct(
        private readonly CopilotOrderClarificationReplyBuilder $orderClarifications,
    ) {}

    /** @param array<string,mixed> $context */
    public function resolve(array $context): string
    {
        if (data_get($context, 'latest_message.type') === 'location'
            && is_numeric(data_get($context, 'latest_message.location.latitude'))
            && is_numeric(data_get($context, 'latest_message.location.longitude'))) {
            return 'ORDER_CONTINUE';
        }

        $text = $this->latestInbound($context);

        if ($text === '') {
            return 'GENERAL_MESSAGE';
        }

        if ($this->handoffReason($context) !== null) {
            return 'HUMAN_REQUEST';
        }

        if ($this->isGreeting($text)) {
            return 'GREETING';
        }

        if ($this->isMarmitaCategoryDiscovery($text)) {
            return $this->hasCurrentPendingOrder($context) ? 'MENU_REQUEST' : 'ORDER_CREATE';
        }

        if ($this->hasMenuRequest($context) && $this->hasExplicitOrderRequest($text)) {
            return $this->hasCurrentPendingOrder($context) && ! $this->hasSpecificProductReference($text)
                ? 'ORDER_CONTINUE'
                : 'ORDER_CREATE';
        }

        if ($this->hasMenuRequest($context)) {
            return 'MENU_REQUEST';
        }

        if ($this->hasUnambiguousBusinessHoursIntent($text)) {
            return 'BUSINESS_HOURS_REQUEST';
        }

        if (preg_match('/\b(onde\s+fica|qual(?:\s+e)?\s+(?:o\s+)?endereco|voces?\s+ficam\s+onde|endereco\s+do\s+restaurante)\b/', $text) === 1) {
            return 'LOCATION_REQUEST';
        }

        if (preg_match('/\b(chave\s+pix|pix\s+(?:para|de|do)|como\s+pagar\s+por\s+pix|qual(?:\s+e)?\s+(?:o|a)?\s*(?:chave\s+)?pix|me\s+passa\s+(?:a\s+)?(?:chave\s+)?pix)\b/', $text) === 1) {
            return 'PAYMENT_QUESTION';
        }

        if (preg_match('/\bconfirm\w*\b.{0,24}\b(?:pix|pagamento)\b|\b(?:pix|pagamento)\b.{0,24}\bconfirm\w*\b/', $text) === 1) {
            return 'PAYMENT_CONFIRMATION';
        }

        if (mb_strtolower((string) data_get($context, 'pending_order_state.draft_order.payment_method', '')) === 'pix'
            && preg_match('/\bqual(?:\s+e)?\s+(?:a\s+)?chave\b/', $text) === 1) {
            return 'PAYMENT_QUESTION';
        }

        if (preg_match('/\b(aceita(?:m)?\s+pix|formas?\s+de\s+pagamento|como\s+posso\s+pagar)\b/', $text) === 1) {
            return 'PAYMENT_QUESTION';
        }

        if (preg_match('/\b(taxa|valor)\b.{0,24}\b(entrega|frete)\b|\b(entrega|frete)\b.{0,24}\b(taxa|valor)\b/', $text) === 1) {
            return 'DELIVERY_QUESTION';
        }

        if ($this->hasCurrentPendingOrder($context) && $this->isPendingOrderTotalQuestion($text)) {
            return 'ORDER_STATUS';
        }

        if (data_get($context, 'conversation_frame.last_assistant_goal.type') === 'choose_option'
            && $this->isPendingGoalOptionsQuestion($text)) {
            return 'GENERAL_MESSAGE';
        }

        if ($this->isProductInformationRequest($text)
            && preg_match('/\b(?:quanto\s+(?:custa|e)|valor|preco|precos|valores|o\s+que\s+vem)\b/', $text) === 1) {
            return 'PRODUCT_CLARIFICATION';
        }

        if ($this->hasProductConversationContext($context)
            && preg_match('/\bquantas?\s+carnes?\b/', $text) === 1) {
            return 'PRODUCT_CLARIFICATION';
        }

        if ($this->requiresContextualSemanticInterpretation($text, $context)) {
            return 'GENERAL_MESSAGE';
        }

        if ($this->isProductInformationRequest($text)) {
            return 'PRODUCT_CLARIFICATION';
        }

        if ($this->hasExplicitOrderRequest($text)) {
            return 'ORDER_CREATE';
        }

        if (data_get($context, 'pending_clarification.type') === 'ambiguous_meat'
            && $this->hasEligiblePendingClarification($context)) {
            return 'ORDER_CONTINUE';
        }

        if (data_get($context, 'conversation_frame.last_assistant_goal.type') === 'choose_option'
            && $this->selectsPendingGoalOption($text, $context)) {
            return 'ORDER_CONTINUE';
        }

        if ($this->hasCurrentPendingOrder($context) && $this->mentionsCanonicalOrderComponent($text, $context)) {
            return 'ORDER_CONTINUE';
        }

        if (data_get($context, 'conversation_frame.last_assistant_goal.type') === 'choose_option'
            && ! in_array(data_get($context, 'conversation_frame.last_assistant_goal.slot'), ['product', 'product_variant'], true)) {
            // The model receives the structured goal and canonical options. It can
            // distinguish an option answer, an options question, or a topic switch.
            return 'GENERAL_MESSAGE';
        }

        if ($this->isProductInformationRequest($text)) {
            return 'PRODUCT_CLARIFICATION';
        }

        if ($this->isBareOrderStartRequest($context)) {
            return 'ORDER_CREATE';
        }

        if (data_get($context, 'pending_clarification.type') === 'product_selection'
            && $this->selectsCanonicalProduct($text, $context)) {
            return 'ORDER_CREATE';
        }

        if (! $this->hasCurrentPendingOrder($context) && $this->orderClarifications->isInformalOrder($context)) {
            return 'ORDER_CREATE';
        }

        if (preg_match('/\b(troca|troque|alter[ae]|muda|mude|substitui[ar]?|tir[ae]|remove[ar]?)\b/', $text) === 1) {
            return 'ORDER_CHANGE';
        }

        if (count(data_get($context, 'messages', [])) > 1
            && preg_match('/\b(?:so|apenas|somente)\s+(?:uma|um)\b/', $text) === 1) {
            return 'ORDER_CHANGE';
        }

        if ($this->hasEligiblePendingClarification($context)) {
            return 'ORDER_CONTINUE';
        }

        if ($this->hasCurrentPendingOrder($context)
            && preg_match('/^(?:sim|isso(?:\s+mesmo)?|pode\s+ser|confere|confirmo|correto|ok(?:ay)?)\b/', $text) === 1) {
            return 'ORDER_CONFIRMATION';
        }

        if ($this->hasCurrentPendingOrder($context)
            && preg_match('/\b(pix|cart[aã]o|dinheiro|rua|avenida|av\.?|travessa|entrega|retirada|buscar|busca|endereco)\b/', $text) === 1) {
            return 'ORDER_CONTINUE';
        }

        if ($this->hasCurrentPendingOrder($context)
            && preg_match('/^(?:com\s+)?(?:frango|porco|almondega|bife)\b/', $text) === 1) {
            return 'ORDER_CONTINUE';
        }

        if ($this->hasCurrentPendingOrder($context)
            && preg_match('/^sem\s+\S+/', $text) === 1) {
            return 'ORDER_CONTINUE';
        }

        if (preg_match('/\b(e\s+tambem|tambem|mais\s+uma|com\s+isso)\b/', $text) === 1) {
            return 'ORDER_CONTINUE';
        }

        return 'GENERAL_MESSAGE';
    }

    /** @param array<string,mixed> $context */
    public function handoffReason(array $context): ?string
    {
        $text = $this->latestInbound($context);

        if (preg_match('/\b(fiado|fiar|desconto|negociar|negociacao|preco\s+especial|credito\s+especial)\b/', $text) === 1) {
            return 'financial_exception';
        }

        if (preg_match('/\b(homem\s*aranha|super[- ]?heroi|resultado\s+do\s+jogo|quem\s+ganhou\s+o\s+jogo|presidente\s+do\s+brasil)\b/', $text) === 1) {
            return 'out_of_domain';
        }

        if (preg_match('/\b(atendente|pessoa\s+de\s+verdade|falar\s+com\s+(?:uma\s+)?pessoa|atendimento\s+humano)\b/', $text) === 1) {
            return 'customer_requested_human';
        }

        return null;
    }

    /** @param array<string,mixed> $context */
    public function isBareOrderStartRequest(array $context): bool
    {
        $text = $this->latestInbound($context);

        return preg_match('/^(?:(?:oi|ola)\s*,?\s*)?(?:quero|queria|gostaria(?:\s+de)?|preciso)\s+(?:fazer|montar|realizar)\s+(?:um\s+)?pedido[.!?]*$/', $text) === 1;
    }

    /**
     * Free-form order language must be interpreted before a generic local
     * resolver can finish the turn. Exact greetings, protected requests and
     * machine-shaped slot values remain eligible for deterministic fast paths.
     *
     * @param  array<string,mixed>  $context
     */
    public function shouldInterpretBeforeDeterministic(string $intent, array $context): bool
    {
        if (data_get($context, 'latest_message.type') !== 'text') {
            return false;
        }

        $text = $this->latestInbound($context);
        if ($text === '' || $this->isGreeting($text) || $this->handoffReason($context) !== null || $this->hasUntrustedInstruction($context)) {
            return false;
        }

        if (in_array(data_get($context, 'pending_order_state.next_objective'), ['CONFIRM_ITEM', 'ASK_MORE_ITEMS', 'ASK_LOCATION'], true)
            || in_array(data_get($context, 'conversation_frame.last_assistant_goal.type'), ['confirm', 'provide_value'], true)) {
            return true;
        }

        if (data_get($context, 'conversation_frame.last_assistant_goal.type') === 'choose_option'
            && $this->selectsPendingGoalOption($text, $context)) {
            return false;
        }

        if ($this->hasEligiblePendingClarification($context)
            && data_get($context, 'pending_clarification.resolution.status') === 'resolved') {
            return false;
        }

        return in_array($intent, ['ORDER_CREATE', 'ORDER_CONTINUE', 'ORDER_CHANGE', 'ORDER_CONFIRMATION', 'GENERAL_MESSAGE'], true);
    }

    private function isProductInformationRequest(string $text): bool
    {
        return preg_match('/\b(?:quanto\s+(?:custa|e)|qual(?:\s+o)?\s+(?:valor|preco)|quais(?:\s+os)?\s+(?:valores|precos)|valores?\s+d(?:a|o|as|os)|precos?\s+d(?:a|o|as|os)|(?:tem|vende|possui)\s+.+|o\s+que\s+vem)\b/', $text) === 1;
    }

    private function isMarmitaCategoryDiscovery(string $text): bool
    {
        $category = 'marmit(?:a|ex)(?:s|es)?';

        return preg_match('/(?:\b(?:qual|quais|que)\b.{0,24}\b'.$category.'\b.{0,24}\b(?:tem|sao)\b|\b'.$category.'\b.{0,32}\b(?:qual|quais|que)\b.{0,24}\btem\b|\btem\b.{0,16}\b(?:qual|quais)\b.{0,16}\b'.$category.'\b)/', $text) === 1;
    }

    private function isPendingOrderTotalQuestion(string $text): bool
    {
        return preg_match('/\b(?:quanto\s+(?:fica|deu|vai\s+dar)|qual(?:\s+e)?\s+(?:o\s+)?total|total\s+d[oa]\s+pedido|valor\s+d[oa]\s+pedido)\b/', $text) === 1;
    }

    private function isPendingGoalOptionsQuestion(string $text): bool
    {
        return preg_match('/^(?:quais?(?:\s+que)?\s+(?:tem|sao)(?:\s+ai)?|quais?\s+(?:as\s+)?(?:opcoes|carnes|saladas)|me\s+(?:fala|mostra)\s+(?:as\s+)?opcoes)[?!.]*$/', $text) === 1;
    }

    private function hasUnambiguousBusinessHoursIntent(string $text): bool
    {
        if (preg_match('/\b(?:horario|horarios|abert[oa]s?|fechad[oa]s?)\b|\bque\s+horas\s+(?:abre|fecha)(?:m)?\b/', $text) === 1) {
            return true;
        }

        return preg_match('/\b(?:funcionando|atendendo)\b/', $text) === 1
            && preg_match('/\b(?:agora|hoje|amanha|domingo|segunda|terca|quarta|quinta|sexta|sabado|ainda|ja|esta|estao)\b/', $text) === 1;
    }

    /** @param array<string,mixed> $context */
    public function hasUntrustedInstruction(array $context): bool
    {
        $text = $this->latestInbound($context);

        return preg_match('/\b(?:ignore|ignora|ignorar)\s+(?:as\s+)?regras\b|\b(?:prompt|instruc(?:ao|oes))\s+(?:do\s+)?sistema\b/', $text) === 1;
    }

    /** @param array<string,mixed> $context */
    public function hasMenuRequest(array $context): bool
    {
        $text = $this->latestInbound($context);

        return preg_match('/\b(cardapio|menu|buffet|acompanhamentos?|guarnicoes?|o\s+que\s+tem\s+hoje|tem\s+almoco\s+hoje)\b/', $text) === 1;
    }

    private function isGreeting(string $text): bool
    {
        $text = Str::of($text)->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();

        return preg_match('/^(?:(?:oi|ola|opa|e\s+ai)(?:\s+(?:bom\s+dia|boa\s+tarde|boa\s+noite))?|bom\s+dia|boa\s+tarde|boa\s+noite)$/', $text) === 1;
    }

    private function hasExplicitOrderRequest(string $text): bool
    {
        $hasProduct = preg_match('/\b(?:n\s*[- ]?\s*(?:5|8|9)|marmit(?:a|ex)|separadinha)\b/', $text) === 1;

        return ($hasProduct
            && (preg_match('/\b(?:quero|qro|queria|gostaria|manda|mandar|me\s+da|me\s+ve|adiciona|inclui|pedir|pode\s+fazer|vou\s+querer)\b/', $text) === 1
                || preg_match('/\bmarmit(?:a|ex)\s+n\s*[- ]?\s*(?:5|8|9)\b/', $text) === 1
                || preg_match('/\b(?:com|sem)\b\s+\S+/', $text) === 1))
            || preg_match('/\b(?:adiciona|inclui|acrescenta)\b\s+\S+/', $text) === 1
            || preg_match('/\b(?:quero|qro|queria|gostaria)\s+(?:de\s+)?(?:uma|um)\s+\S+/', $text) === 1;
    }

    /** @param array<string,mixed> $context */
    private function selectsPendingGoalOption(string $text, array $context): bool
    {
        $answer = preg_replace('/^(?:pode\s+ser|quero|vai\s+ser|escolho)\s+/', '', $text) ?? $text;
        $answer = Str::of($answer)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
        if ($answer === '') {
            return false;
        }

        return collect((array) data_get($context, 'conversation_frame.last_assistant_goal.allowed_values', []))
            ->contains(function (mixed $option) use ($answer): bool {
                if (! is_array($option)) {
                    return false;
                }

                return collect([$option['id'] ?? null, $option['label'] ?? null])
                    ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                    ->map(fn (string $value): string => Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString())
                    ->contains($answer);
            });
    }

    /** @param array<string,mixed> $context */
    private function requiresContextualSemanticInterpretation(string $text, array $context): bool
    {
        if (! $this->hasProductConversationContext($context)) {
            return false;
        }

        return preg_match('/\b(?:como\s+(?:e|funciona|monta)|diferenca|comparar?)\b/', $text) === 1
            || preg_match('/^(?:e\s+)?(?:essa|esse|a\s+n\s*[- ]?\s*(?:5|8|9)(?:\s+(?:casa|livre))?)\??$/', $text) === 1
            || preg_match('/^(?:pode\s+ser|quero|vou\s+querer)\s+(?:a\s+)?(?:casa|livre|essa|esse)\b/', $text) === 1;
    }

    /** @param array<string,mixed> $context */
    private function hasProductConversationContext(array $context): bool
    {
        return data_get($context, 'pending_clarification.type') === 'product_selection'
            || data_get($context, 'pending_order_state.offered_product_ids', []) !== []
            || data_get($context, 'conversation_frame.active_references', []) !== [];
    }

    /** @param array<string,mixed> $context */
    private function latestInbound(array $context): string
    {
        foreach (array_reverse(data_get($context, 'messages', [])) as $message) {
            if (($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text') {
                return Str::of((string) ($message['body'] ?? ''))->ascii()->lower()->squish()->toString();
            }
        }

        return '';
    }

    /** @param array<string,mixed> $context */
    private function hasCurrentPendingOrder(array $context): bool
    {
        if (is_array(data_get($context, 'active_order'))
            && (int) data_get($context, 'active_order.id') > 0) {
            return true;
        }

        if ((array) data_get($context, 'pending_order_state.draft_order.items', []) !== []
            || (array) data_get($context, 'pending_order_state.selected_components', []) !== []) {
            return true;
        }

        $messages = data_get($context, 'messages', []);
        if (! is_array($messages)) {
            return false;
        }

        foreach (array_reverse(array_slice($messages, 0, -1)) as $message) {
            if (($message['direction'] ?? null) !== 'inbound' || ($message['type'] ?? 'text') !== 'text') {
                continue;
            }

            $body = Str::of((string) ($message['body'] ?? ''))->ascii()->lower()->squish()->toString();
            if (preg_match('/\bn\s*[- ]?\s*(?:5|8|9)\b/', $body) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasSpecificProductReference(string $text): bool
    {
        return preg_match('/\bn\s*[- ]?\s*(?:5|8|9)\b|\bseparadinha\b/', $text) === 1;
    }

    /** @param array<string,mixed> $context */
    private function mentionsCanonicalOrderComponent(string $text, array $context): bool
    {
        $tokens = collect(preg_split('/[^a-z0-9]+/', $text) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 3)
            ->unique();
        if ($tokens->isEmpty()) {
            return false;
        }

        return collect((array) data_get($context, 'operational_catalog.components', []))
            ->filter(fn (mixed $component): bool => is_array($component) && ($component['available_today'] ?? false) === true)
            ->contains(function (array $component) use ($text, $tokens): bool {
                $identities = collect([
                    (string) ($component['slug'] ?? ''),
                    (string) ($component['name'] ?? ''),
                    (string) ($component['display_name'] ?? ''),
                ])->map(fn (string $value): string => Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString())
                    ->filter();

                return $identities->contains(fn (string $identity): bool => str_contains(' '.$text.' ', ' '.$identity.' '))
                    || $identities->contains(function (string $identity) use ($tokens): bool {
                        $leading = explode(' ', $identity)[0] ?? '';

                        return strlen($leading) >= 3 && $tokens->contains($leading);
                    });
            });
    }

    /** @param array<string,mixed> $context */
    private function selectsCanonicalProduct(string $text, array $context): bool
    {
        $key = fn (string $value): string => Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/\b(?:da|do|de)\b/', ' ')
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
        $needle = $key($text);
        if ($needle === '') {
            return false;
        }

        return collect((array) data_get($context, 'menu', []))->contains(function (mixed $product) use ($key, $needle): bool {
            if (! is_array($product)) {
                return false;
            }

            return collect([(string) ($product['name'] ?? ''), (string) ($product['slug'] ?? '')])
                ->map($key)
                ->filter()
                ->contains($needle);
        });
    }

    /** @param array<string,mixed> $context */
    private function hasEligiblePendingClarification(array $context): bool
    {
        return data_get($context, 'pending_clarification.status') === 'eligible'
            && in_array(data_get($context, 'pending_clarification.resolution.status'), ['resolved', 'ambiguous', 'invalid'], true);
    }
}
