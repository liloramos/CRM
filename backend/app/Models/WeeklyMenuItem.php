<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyMenuItem extends Model
{
    public const DAY_EVERYDAY = 'everyday';

    public const DAY_KEYS = [
        0 => 'sunday',
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
    ];

    protected $fillable = [
        'weekly_menu_id',
        'product_id',
        'service_day',
        'is_available_by_default',
        'notes',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_available_by_default' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function weeklyMenu(): BelongsTo
    {
        return $this->belongsTo(WeeklyMenu::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
