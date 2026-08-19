<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotItemNoteGroundingGuard
{
    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<array<string,mixed>>  $messages
     * @return array{items:list<array<string,mixed>>,warnings:list<array{code:string,message:string}>}
     */
    public function ground(array $items, array $messages): array
    {
        $customerText = $this->key(collect($messages)
            ->filter(fn (array $message): bool => ($message['direction'] ?? null) === 'inbound' && ($message['type'] ?? 'text') === 'text')
            ->pluck('body')
            ->implode(' '));
        $warnings = [];

        $items = array_map(function (array $item) use ($customerText, &$warnings): array {
            $note = trim((string) ($item['item_notes'] ?? ''));
            if ($note === '' || str_contains($customerText, $this->key($note))) {
                return $item;
            }

            $warnings[] = [
                'code' => 'UNGROUNDED_ITEM_NOTE',
                'message' => 'Uma observacao sem evidencia no pedido do cliente foi removida.',
            ];

            return [...$item, 'item_notes' => ''];
        }, $items);

        return ['items' => $items, 'warnings' => $warnings];
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->rtrim('.!?:;')->toString();
    }
}
