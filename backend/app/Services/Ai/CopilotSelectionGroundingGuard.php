<?php

namespace App\Services\Ai;

use App\Models\MenuComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CopilotSelectionGroundingGuard
{
    /**
     * Keeps only provider-proposed components that are grounded in customer text.
     *
     * @param  list<MenuComponent>  $proposed
     * @param  list<MenuComponent>  $candidates
     * @param  list<array<string,mixed>>  $messages
     * @return array{components:list<MenuComponent>,warnings:list<array{code:string,message:string}>}
     */
    public function groundComponents(array $proposed, array $candidates, array $messages, string $subject): array
    {
        $customerText = $this->customerText($messages);
        $customerTokens = $this->tokens($customerText);
        $grounded = [];
        $warnings = [];

        foreach ($proposed as $component) {
            if ($this->isExplicit($component, $customerText)) {
                $grounded[] = $component;

                continue;
            }

            $matchingCandidates = $this->candidateTokens($component)
                ->intersect($customerTokens)
                ->map(fn (string $token) => collect($candidates)->filter(fn (MenuComponent $candidate): bool => $this->candidateTokens($candidate)->contains($token))->values())
                ->filter(fn ($matches) => $matches->isNotEmpty());

            if ($matchingCandidates->contains(fn ($matches): bool => $matches->count() === 1 && (int) $matches->first()->id === (int) $component->id)) {
                $grounded[] = $component;

                continue;
            }

            $warnings[] = $matchingCandidates->contains(fn ($matches): bool => $matches->count() > 1)
                ? ['code' => 'AMBIGUOUS_'.strtoupper($subject), 'message' => 'Uma escolha sugerida permanece ambigua no contexto do cliente.']
                : ['code' => 'UNGROUNDED_'.strtoupper($subject), 'message' => 'Uma escolha sugerida nao possui evidencia suficiente no contexto do cliente.'];
        }

        return ['components' => $grounded, 'warnings' => $warnings];
    }

    /** @param list<array<string,mixed>> $messages */
    private function customerText(array $messages): string
    {
        return collect($messages)
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->map(fn (array $message): string => (string) ($message['body'] ?? ''))
            ->implode(' ');
    }

    private function isExplicit(MenuComponent $component, string $customerText): bool
    {
        $text = $this->key($customerText);

        return collect([$component->slug, $component->name, $component->display_name])
            ->filter(fn (mixed $value): bool => is_string($value) && $this->key($value) !== '')
            ->contains(fn (string $value): bool => str_contains($text, $this->key($value)));
    }

    /** @return Collection<int,string> */
    private function candidateTokens(MenuComponent $component): Collection
    {
        return collect([$component->slug, $component->name, $component->display_name])
            ->filter(fn (mixed $value): bool => is_string($value))
            ->flatMap(fn (string $value): array => $this->tokens($value)->all())
            ->unique()
            ->values();
    }

    /** @return Collection<int,string> */
    private function tokens(string $value): Collection
    {
        return collect(preg_split('/[^a-z0-9]+/', Str::of($value)->ascii()->lower()->toString()) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 3)
            ->unique()
            ->values();
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
