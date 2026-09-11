<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends Model
{
    public const CATEGORIES = ['order', 'payment', 'printing', 'whatsapp', 'ai', 'delivery', 'menu', 'access', 'customer', 'reports', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    public const STATUSES = ['open', 'in_review', 'resolved'];

    protected $fillable = ['company_id', 'user_id', 'related_order_id', 'related_customer_id', 'code', 'category', 'subject', 'description', 'priority', 'status', 'current_route', 'technical_context', 'email_delivery_status', 'email_delivery_failed_at'];

    protected function casts(): array
    {
        return ['technical_context' => 'array', 'email_delivery_failed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'related_order_id');
    }

    public function relatedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'related_customer_id');
    }
}
