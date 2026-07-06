<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function restaurantProfile(): HasOne
    {
        return $this->hasOne(RestaurantProfile::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class);
    }
}
