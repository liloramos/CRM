<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantProfile extends Model
{
    protected $fillable = [
        'company_id',
        'display_name',
        'legal_name',
        'document',
        'contact_email',
        'contact_phone',
        'website',
        'description',
        'address_line',
        'address_number',
        'address_complement',
        'district',
        'city',
        'state',
        'postal_code',
        'country_code',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
