<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationQuickReply extends Model
{
    public const CATEGORY_GREETING = 'greeting';

    public const CATEGORY_MENU = 'menu';

    public const CATEGORY_ORDER = 'order';

    public const CATEGORY_ADDRESS = 'address';

    public const CATEGORY_PAYMENT = 'payment';

    public const CATEGORY_PAYMENT_PROOF = 'payment_proof';

    public const CATEGORY_UNAVAILABLE_PRODUCT = 'unavailable_product';

    public const CATEGORY_HUMAN_SUPPORT = 'human_support';

    public const CATEGORY_CLOSING = 'closing';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_GREETING,
        self::CATEGORY_MENU,
        self::CATEGORY_ORDER,
        self::CATEGORY_ADDRESS,
        self::CATEGORY_PAYMENT,
        self::CATEGORY_PAYMENT_PROOF,
        self::CATEGORY_UNAVAILABLE_PRODUCT,
        self::CATEGORY_HUMAN_SUPPORT,
        self::CATEGORY_CLOSING,
    ];

    protected $fillable = [
        'company_id',
        'title',
        'shortcut',
        'body',
        'category',
        'is_active',
        'display_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
