<?php

namespace App\Data\Menu;

use App\Enums\MenuAvailabilityStatus;
use App\Models\MenuComponent;

class ComponentAvailabilityResult
{
    public const SOURCE_PRODUCT_OVERRIDE = 'product_override';

    public const SOURCE_GLOBAL_AVAILABILITY = 'global_availability';

    public const SOURCE_COMPONENT_DEFAULT = 'component_default';

    public function __construct(
        public readonly MenuAvailabilityStatus $status,
        public readonly bool $available,
        public readonly string $source,
        public readonly ?string $reason,
        public readonly ?MenuComponent $replacementComponent,
        public readonly string $availabilityDate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'available' => $this->available,
            'source' => $this->source,
            'reason' => $this->reason,
            'replacement' => $this->replacementComponent
                ? $this->componentPayload($this->replacementComponent)
                : null,
            'availability_date' => $this->availabilityDate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(MenuComponent $component): array
    {
        return [
            'id' => $component->id,
            'slug' => $component->slug,
            'name' => $component->name,
            'component_type' => $component->component_type->value,
        ];
    }
}
