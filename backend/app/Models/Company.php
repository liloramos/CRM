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

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function weeklyMenus(): HasMany
    {
        return $this->hasMany(WeeklyMenu::class);
    }

    public function dailyMenuOverrides(): HasMany
    {
        return $this->hasMany(DailyMenuOverride::class);
    }

    public function dailyMenuOptionOverrides(): HasMany
    {
        return $this->hasMany(DailyMenuOptionOverride::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customerAddresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function deliverySetting(): HasOne
    {
        return $this->hasOne(DeliverySetting::class);
    }

    public function deliveryQuotes(): HasMany
    {
        return $this->hasMany(DeliveryQuote::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditMovements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class);
    }

    public function printerSettings(): HasMany
    {
        return $this->hasMany(PrinterSetting::class);
    }

    public function receiptTemplates(): HasMany
    {
        return $this->hasMany(ReceiptTemplate::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function printJobEvents(): HasMany
    {
        return $this->hasMany(PrintJobEvent::class);
    }

    public function whatsappAccounts(): HasMany
    {
        return $this->hasMany(WhatsAppAccount::class);
    }

    public function whatsappWebhookEvents(): HasMany
    {
        return $this->hasMany(WhatsAppWebhookEvent::class);
    }

    public function whatsappMessageDeliveries(): HasMany
    {
        return $this->hasMany(WhatsAppMessageDelivery::class);
    }

    public function whatsappMediaFiles(): HasMany
    {
        return $this->hasMany(WhatsAppMediaFile::class);
    }

    public function aiAutomationSettings(): HasMany
    {
        return $this->hasMany(AiAutomationSetting::class);
    }

    public function aiResponseSuggestions(): HasMany
    {
        return $this->hasMany(AiResponseSuggestion::class);
    }

    public function automationEvents(): HasMany
    {
        return $this->hasMany(AutomationEvent::class);
    }
}
