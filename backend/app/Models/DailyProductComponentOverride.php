<?php

namespace App\Models;

use App\Enums\MenuAvailabilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyProductComponentOverride extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'menu_component_id',
        'availability_date',
        'status',
        'reason',
        'marked_by_user_id',
    ];

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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(MenuComponent::class, 'menu_component_id');
    }

    public function markedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
