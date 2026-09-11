<?php

namespace App\Services\Ai;

/** Central classification shared by every authority/review branch. */
final class CopilotReviewClassification
{
    /** @return list<string> */
    public function ordinaryMissing(): array
    {
        return [
            'PRODUCT', 'MENU_ITEM', 'PRODUCT_VARIANT', 'N8_VARIANT', 'CARNE', 'MEAT', 'SALADA',
            'ACOMPANHAMENTO', 'COMPONENT', 'ADDRESS', 'LOCATION', 'FULFILLMENT', 'PAYMENT_METHOD',
            'VALID_QUANTITY', 'QUANTITY', 'SABOR', 'ITEM_CONFIRMATION', 'MORE_ITEMS', 'PAYMENT_PROOF',
        ];
    }

    /** @return list<string> */
    public function recoverableWarnings(): array
    {
        return [
            'MESSAGE_NOT_UNDERSTOOD', 'ITEM_UNAVAILABLE', 'UNAVAILABLE_DAILY_COMPONENT',
            'UNRESOLVED_MENU_ITEM', 'UNRESOLVED_PRODUCT_QUERY', 'UNGROUNDED_PRODUCT', 'POSSIBLE_TYPO',
            'INVALID_QUANTITY', 'DOMAIN_SELECTION_REJECTED', 'REMOVAL_NOT_SUPPORTED',
            'PRODUCT_INCOMPATIBLE_WITH_EXPLICIT_SELECTIONS', 'MEAT_ALLOWANCE_EXCEEDED',
            'UNRESOLVED_MEAT', 'AMBIGUOUS_MEAT', 'SEMANTIC_DELTA_REJECTED', 'PROVIDER_UNAVAILABLE',
        ];
    }

    /** @param list<string> $codes */
    public function onlyOrdinaryMissing(array $codes): bool
    {
        return array_diff($codes, $this->ordinaryMissing()) === [];
    }

    /** @param list<string> $codes */
    public function onlyRecoverableWarnings(array $codes): bool
    {
        return array_diff($codes, $this->recoverableWarnings()) === [];
    }
}
