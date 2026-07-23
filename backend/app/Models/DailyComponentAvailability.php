<?php

namespace App\Models;

use App\Enums\MenuAvailabilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class DailyComponentAvailability extends Model
{
    protected $table = 'daily_component_availability';

    protected $fillable = [
        'company_id',
        'menu_component_id',
        'availability_date',
        'status',
        'reason',
        'replacement_component_id',
        'marked_by_user_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (DailyComponentAvailability $availability): void {
            if ($availability->replacement_component_id === null) {
                return;
            }

            if ((int) $availability->replacement_component_id === (int) $availability->menu_component_id) {
                throw new InvalidArgumentException('A component availability replacement cannot point to itself.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'availability_date' => 'date',
            'status' => MenuAvailabilityStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(MenuComponent::class, 'menu_component_id');
    }

    public function replacementComponent(): BelongsTo
    {
        return $this->belongsTo(MenuComponent::class, 'replacement_component_id');
    }

    public function markedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
