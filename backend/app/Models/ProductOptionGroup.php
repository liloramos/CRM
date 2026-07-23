<?php

namespace App\Models;

use App\Enums\ProductSelectionActor;
use App\Enums\ProductSelectionMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOptionGroup extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'code',
        'label',
        'selection_mode',
        'selection_actor',
        'is_required',
        'min_choices',
        'max_choices',
        'min_quantity',
        'max_quantity',
        'same_component_only',
        'included_in_base_price',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'selection_mode' => ProductSelectionMode::class,
            'selection_actor' => ProductSelectionActor::class,
            'is_required' => 'boolean',
            'min_choices' => 'integer',
            'max_choices' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'same_component_only' => 'boolean',
            'included_in_base_price' => 'boolean',
            'display_order' => 'integer',
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

    public function componentOptions(): HasMany
    {
        return $this->hasMany(ProductGroupComponent::class);
    }

    public function productOptions(): HasMany
    {
        return $this->hasMany(ProductGroupProduct::class);
    }
}
