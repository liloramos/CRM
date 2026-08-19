<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotPreparationNoteRecovery
{
    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<array<string,mixed>>  $messages
     * @return list<array<string,mixed>>
     */
    public function recover(array $items, array $messages): array
    {
        $notes = $this->notes($messages);
        if ($notes === [] || count($items) !== 1) {
            return $items;
        }

        return array_map(function (array $item) use ($notes): array {
            $current = trim((string) ($item['item_notes'] ?? ''));
            if ($current !== '') {
                return $item;
            }

            return [...$item, 'item_notes' => $notes[0]];
        }, $items);
    }

    /** @param list<array<string,mixed>> $messages @return list<string> */
    private function notes(array $messages): array
    {
        $text = collect($messages)
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->pluck('body')
            ->map(fn (mixed $body): string => Str::of((string) $body)->ascii()->lower()->toString())
            ->implode(' ');
        preg_match_all('/\b([a-z0-9]+)\s+separad[oa]\b/', $text, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $component): string => Str::ucfirst($component).' separado')
            ->map(fn (string $note): string => Str::replaceLast(' separado', str_ends_with($note, 'a separado') ? ' separada' : ' separado', $note))
            ->unique(fn (string $note): string => Str::of($note)->ascii()->lower()->toString())
            ->values()
            ->all();
    }
}
