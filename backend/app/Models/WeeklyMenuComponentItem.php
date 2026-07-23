<?php

namespace App\Models;

use App\Enums\WeeklyMenuSection;
use App\Enums\WeeklyMenuServiceDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyMenuComponentItem extends Model
{
    protected $fillable = [
        'company_id',
        'weekly_menu_id',
        'service_day',
        'section',
        'menu_component_id',
        'is_active',
        'display_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'service_day' => WeeklyMenuServiceDay::class,
            'section' => WeeklyMenuSection::class,
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function weeklyMenu(): BelongsTo
    {
        return $this->belongsTo(WeeklyMenu::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(MenuComponent::class, 'menu_component_id');
    }
}
