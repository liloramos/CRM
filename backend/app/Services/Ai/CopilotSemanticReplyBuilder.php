<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/** Formats only facts that have already been selected from the canonical context. */
final class CopilotSemanticReplyBuilder
{
    public function __construct(private readonly CopilotProductDecisionFacts $decisionFacts) {}

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed> */
    public function ground(array $safe, array $context): array
    {
        $interpretation = data_get($safe, 'metadata.semantic_interpretation');
        if (! is_array($interpretation)) {
            return $safe;
        }

        $intents = (array) ($interpretation['intents'] ?? []);
        if (in_array('generic_order', $intents, true) && ($interpretation['mutates_order'] ?? false) !== true) {
            return $this->productDiscovery($safe, $context);
        }
        if (in_array('ask_product_information', $intents, true) && ($interpretation['mutates_order'] ?? false) !== true) {
            return $this->productInformation($safe, $context, $interpretation);
        }
        if (in_array('compare_products', $intents, true) && ($interpretation['mutates_order'] ?? false) !== true) {
            return $this->comparison($safe, $context, $interpretation);
        }
        if (in_array('ask_pending_slot_options', $intents, true) && ($interpretation['mutates_order'] ?? false) !== true) {
            return $this->pendingOptions($safe, $context, $interpretation);
        }
        if (data_get($safe, 'metadata.semantic_pending_slot_rejected') === true) {
            return $this->pendingOptions($safe, $context, $interpretation, true);
        }

        return $safe;
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @return array<string,mixed> */
    private function productDiscovery(array $safe, array $context): array
    {
        $products = collect((array) data_get($context, 'menu', []))
            ->filter(fn (mixed $product): bool => is_array($product)
                && (bool) data_get($product, 'resolved_configuration.product.availability.available', true)
                && in_array((string) ($product['rule'] ?? ''), ['n5_casa', 'n8_casa', 'n8_tradicional', 'n9_tradicional'], true))
            ->unique('id')
            ->take(6)
            ->values();
        if ($products->isEmpty()) {
            return $safe;
        }

        $reply = "Claro 😊 Estas são as marmitas de hoje:\n\n"
            .$products->map(fn (array $product): string => $this->productFactLine($product))->implode("\n\n")
            ."\n\nQual combina mais com o que você quer montar?";
        $references = $products->map(fn (array $product): array => [
            'product_id' => (int) $product['id'],
            'slug' => (string) $product['slug'],
            'name' => (string) $product['name'],
        ])->all();

        return [
            ...$safe,
            'intent' => 'ORDER_CREATE',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [['code' => 'MENU_ITEM', 'label' => 'Produto']],
            'warnings' => [],
            'suggested_reply' => $reply,
            'reply_messages' => [$reply],
            'metadata' => [
                ...(array) ($safe['metadata'] ?? []),
                'reply_source' => 'resolved_turn_product_discovery',
                'semantic_read_only' => true,
                'offered_product_ids' => $products->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'conversation_references' => $references,
            ],
        ];
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function productInformation(array $safe, array $context, array $interpretation): array
    {
        $menu = collect((array) data_get($context, 'menu', []));
        $requested = collect((array) ($interpretation['target_products'] ?? []))
            ->map(fn (mixed $target): string => $this->key((string) $target))
            ->filter();
        $latest = $this->key((string) data_get($context, 'latest_message.body', ''));
        $product = $requested->isNotEmpty()
            ? $menu->first(fn (mixed $candidate): bool => is_array($candidate) && $this->matchesAnyIdentity($candidate, $requested))
            : $menu->first(fn (mixed $candidate): bool => is_array($candidate) && $latest !== '' && $this->identityKeys($candidate)
                ->contains(fn (string $identity): bool => $identity !== '' && str_contains($latest, $identity)));
        if (! is_array($product)) {
            $referenceIds = collect((array) data_get($context, 'conversation_frame.active_references', []))
                ->pluck('product_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique();
            if ($referenceIds->count() === 1) {
                $product = $menu->firstWhere('id', $referenceIds->first());
            }
        }
        if (! is_array($product)) {
            return $safe;
        }

        $reply = $this->productFactLine($product)."\n\nQuer essa ou prefere comparar com outra opção? 😊";
        $currentReference = [[
            'product_id' => (int) $product['id'],
            'slug' => (string) $product['slug'],
            'name' => (string) $product['name'],
        ]];
        $previousReferences = collect((array) data_get($context, 'conversation_frame.active_references', []));
        $references = collect($currentReference)
            ->merge($previousReferences->count() <= 2 ? $previousReferences : [])
            ->filter(fn (mixed $reference): bool => is_array($reference) && (int) ($reference['product_id'] ?? 0) > 0)
            ->unique('product_id')
            ->take(2)
            ->values()
            ->all();

        return [
            ...$safe,
            'intent' => 'PRODUCT_CLARIFICATION',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'reply_messages' => [$reply],
            'metadata' => [
                ...(array) ($safe['metadata'] ?? []),
                'reply_source' => 'semantic_grounded_product_information',
                'semantic_recovery' => true,
                'conversation_references' => $references,
            ],
        ];
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function comparison(array $safe, array $context, array $interpretation): array
    {
        $menu = collect((array) data_get($context, 'menu', []));
        $requested = collect((array) ($interpretation['target_products'] ?? []))
            ->map(fn (mixed $target): string => $this->key((string) $target))
            ->filter();
        $referenceIds = collect((array) data_get($context, 'conversation_frame.active_references', []))
            ->pluck('product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter();
        $products = $menu->filter(function (mixed $product) use ($requested, $referenceIds): bool {
            if (! is_array($product)) {
                return false;
            }
            if ($referenceIds->contains((int) ($product['id'] ?? 0))) {
                return true;
            }

            $identities = collect([(string) ($product['id'] ?? ''), (string) ($product['slug'] ?? ''), (string) ($product['name'] ?? '')])
                ->map(fn (string $value): string => $this->key($value));

            return $requested->contains(fn (string $target): bool => $identities->contains($target));
        })->unique('id')->take(4)->values();
        if ($products->count() < 2) {
            return $safe;
        }

        $lines = $products->map(fn (array $product): string => $this->productFactLine($product))->all();
        $reply = implode("\n\n", $lines);
        $references = $products->map(fn (array $product): array => [
            'product_id' => (int) $product['id'],
            'slug' => (string) $product['slug'],
            'name' => (string) $product['name'],
        ])->all();

        return [
            ...$safe,
            'intent' => 'PRODUCT_CLARIFICATION',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'reply_messages' => [$reply],
            'metadata' => [
                ...(array) ($safe['metadata'] ?? []),
                'reply_source' => 'semantic_grounded_comparison',
                'semantic_recovery' => true,
                'conversation_references' => $references,
            ],
        ];
    }

    /** @param array<string,mixed> $product */
    private function productFactLine(array $product): string
    {
        $name = (string) ($product['name'] ?? 'Produto');
        $price = $this->money((int) ($product['base_price_cents'] ?? 0));
        $summary = $this->decisionFacts->summary($this->decisionFacts->fromProduct($product));

        return "*{$name} — {$price}*".($summary === '' ? '' : "\n{$summary}");
    }

    /** @param array<string,mixed> $product @param \Illuminate\Support\Collection<int,string> $requested */
    private function matchesAnyIdentity(array $product, $requested): bool
    {
        $identities = $this->identityKeys($product);

        return $requested->contains(fn (string $target): bool => $identities->contains($target));
    }

    /** @param array<string,mixed> $product @return \Illuminate\Support\Collection<int,string> */
    private function identityKeys(array $product)
    {
        return collect([(string) ($product['id'] ?? ''), (string) ($product['slug'] ?? ''), (string) ($product['name'] ?? '')])
            ->map(fn (string $value): string => $this->key($value))
            ->filter()
            ->values();
    }

    /** @param array<string,mixed> $safe @param array<string,mixed> $context @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function pendingOptions(array $safe, array $context, array $interpretation, bool $preserveDraft = false): array
    {
        $goal = data_get($context, 'conversation_frame.last_assistant_goal');
        if (! is_array($goal) || ($goal['type'] ?? null) !== 'choose_option') {
            return $safe;
        }
        $subject = $this->slot((string) ($interpretation['subject'] ?? ''));
        $goalSlot = $this->slot((string) ($goal['slot'] ?? ''));
        if ($subject !== '' && $subject !== $goalSlot) {
            return $safe;
        }

        $options = collect((array) ($goal['allowed_values'] ?? []))
            ->filter(fn (mixed $option): bool => is_array($option) && filled($option['label'] ?? null))
            ->unique('id')
            ->take(12)
            ->values();
        if ($options->isEmpty()) {
            return $safe;
        }

        $label = match ($goalSlot) {
            'carne' => 'carnes',
            'salada' => 'saladas',
            'payment_method' => 'formas de pagamento',
            'product_variant' => 'opções',
            default => 'opções',
        };
        $reply = ($preserveDraft ? "Essa opção não está disponível para esta escolha.\n\n" : '')
            ."Temos estas {$label}:\n"
            .$options->values()->map(fn (array $option, int $index): string => ($index + 1).'. '.(string) $option['label'])->implode("\n")
            ."\n\nQual você prefere? 😊";

        return [
            ...$safe,
            'intent' => $preserveDraft ? 'ORDER_CONTINUE' : 'GENERAL_QUESTION',
            'draft_order' => $preserveDraft ? $safe['draft_order'] : ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => $preserveDraft ? $safe['missing_information'] : [],
            'warnings' => $preserveDraft ? $safe['warnings'] : [],
            'suggested_reply' => $reply,
            'reply_messages' => [$reply],
            'metadata' => [
                ...(array) ($safe['metadata'] ?? []),
                'reply_source' => 'semantic_grounded_options',
                'semantic_recovery' => true,
                'assistant_goal' => $goal,
            ],
        ];
    }

    private function slot(string $value): string
    {
        return match (Str::of($value)->ascii()->lower()->snake()->toString()) {
            'salad', 'salads', 'salada_casa' => 'salada',
            'meat', 'meats' => 'carne',
            'payment', 'pagamento' => 'payment_method',
            default => Str::of($value)->ascii()->lower()->snake()->toString(),
        };
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
