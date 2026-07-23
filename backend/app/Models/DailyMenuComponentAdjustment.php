<?php

namespace App\Models;

use App\Enums\DailyMenuAdjustmentAction;
use App\Enums\WeeklyMenuSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyMenuComponentAdjustment extends Model
{
    protected $fillable = [
        'company_id',
        'availability_date',
        'menu_component_id',
        'section',
        'action',
        'display_order',
        'notes',
        'marked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'availability_date' => 'date',
            'section' => WeeklyMenuSection::class,
            'action' => DailyMenuAdjustmentAction::class,
            'display_order' => 'integer',
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

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
