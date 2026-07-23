<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    public const TYPE_MARMITAS = 'marmitas';

    public const TYPE_BEBIDAS = 'bebidas';

    public const TYPE_SUCOS = 'sucos';

    public const TYPE_COMBOS = 'combos';

    public const TYPE_FEIJOADAS = 'feijoadas';

    public const TYPE_ADICIONAIS = 'adicionais';

    public const TYPE_ACAI = 'acai';

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'category_type',
        'description',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
