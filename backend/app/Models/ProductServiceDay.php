<?php

namespace App\Models;

use App\Enums\ProductServiceDay as ProductServiceDayEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductServiceDay extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'service_day',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'service_day' => ProductServiceDayEnum::class,
            'is_active' => 'boolean',
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
}
