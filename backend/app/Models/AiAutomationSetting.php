<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAutomationSetting extends Model
{
    public const PROVIDER_FAKE = 'fake';

    public const PROVIDER_N8N = 'n8n';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'company_id',
        'provider',
        'default_mode',
        'automation_enabled',
        'allow_auto_send',
        'require_human_confirmation_for_ambiguous',
        'require_human_confirmation_for_payments',
        'n8n_webhook_path',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'automation_enabled' => 'boolean',
            'allow_auto_send' => 'boolean',
            'require_human_confirmation_for_ambiguous' => 'boolean',
            'require_human_confirmation_for_payments' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
