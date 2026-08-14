<?php

namespace App\Services\Ai;

use App\Data\Ai\CopilotAnalysis;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class CopilotOrderDraftValidator
{
    public function __construct(private readonly CopilotMenuAliasResolver $aliases, private readonly CopilotOrderItemSelectionAdapter $selections) {}

    public function validate(Company $company, CopilotAnalysis $analysis): CopilotAnalysis
    {
        $warnings = $analysis->warnings;
        $items = collect($analysis->draftOrder['items'] ?? [])->map(function (array $item, int $index) use ($company, &$warnings): array {
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 50) {
                $warnings[] = ['code' => 'INVALID_QUANTITY', 'message' => 'A quantidade sugerida nao e valida.', 'item_index' => $index];

                return [...$item, 'valid' => false];
            }
            $product = $this->aliases->resolve($company, $item['menu_item_id'] ?? null, (string) ($item['menu_item_slug'] ?? ''));
            if (! $product || ! $product->is_active || ! $product->is_available_by_default) {
                $warnings[] = ['code' => 'UNRESOLVED_MENU_ITEM', 'message' => 'Nao foi possivel associar o item a um produto disponivel.', 'item_index' => $index];

                return [...$item, 'valid' => false];
            }
            $result = $this->selections->validate($company, $product, CarbonImmutable::today(), [
                ...$item,
                'menu_item_id' => $product->id,
                'menu_item_slug' => $product->slug,
                'quantity' => $quantity,
                'item_notes' => Str::limit((string) ($item['item_notes'] ?? ''), 500, ''),
            ]);
            foreach ($result['warnings'] as $warning) {
                $warnings[] = [...$warning, 'item_index' => $index];
            }

            return $result['item'];
        })->all();
        $missing = $analysis->missingInformation;
        if (($analysis->draftOrder['fulfillment'] ?? null) === 'delivery' && blank($analysis->draftOrder['address'] ?? null)) {
            $missing[] = ['code' => 'ADDRESS', 'label' => 'Endereco'];
        }

        return new CopilotAnalysis($analysis->intent, $analysis->confidence, $analysis->summary, [...$analysis->draftOrder, 'items' => $items], collect($missing)->unique('code')->values()->all(), $warnings, $analysis->suggestedReply, true, [...$analysis->metadata, 'validation_warning_count' => count($warnings)]);
    }
}
