<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotRemovalGroundingGuard
{
    /** @param list<array<string,mixed>> $messages */
    public function isGrounded(string $component, array $messages): bool
    {
        $text = collect($messages)
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->pluck('body')
            ->map(fn (mixed $body): string => Str::of((string) $body)->ascii()->lower()->toString())
            ->implode(' ');

        foreach ($this->aliases($component) as $alias) {
            if (preg_match('/\b(?:sem|tirar|retira(?:r)?|remove(?:r)?|nao\s+quero)\s+(?:a\s+|o\s+)?'.$alias.'\b/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function aliases(string $component): array
    {
        $key = Str::of($component)->ascii()->lower()->replace('_', ' ')->squish()->toString();
        $aliases = [preg_quote($key, '/')];
        if (str_ends_with($key, ' casa')) {
            $aliases[] = preg_quote(substr($key, 0, -5), '/');
        }

        return array_values(array_unique($aliases));
    }
}
