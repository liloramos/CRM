<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const AUTHORITY_ATTENDANT = 100;

    public const AUTHORITY_MANAGER = 200;

    public const AUTHORITY_DEV = 300;

    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN_GERENTE = 'admin_gerente';

    public const ATENDENTE = 'atendente';

    /**
     * @var array<string, string>
     */
    public const DEFAULT_ROLES = [
        self::SUPER_ADMIN => 'DEV',
        self::ADMIN_GERENTE => 'Gerência',
        self::ATENDENTE => 'Atendente',
    ];

    /**
     * @var array<string, int>
     */
    public const AUTHORITY_LEVELS = [
        self::SUPER_ADMIN => self::AUTHORITY_DEV,
        self::ADMIN_GERENTE => self::AUTHORITY_MANAGER,
        self::ATENDENTE => self::AUTHORITY_ATTENDANT,
    ];

    /**
     * @var array<string, list<string>>
     */
    public const DEFAULT_ROLE_PERMISSIONS = [
        self::SUPER_ADMIN => [
            'dashboard.view',
            'users.view',
            'users.manage',
            'roles.view',
            'roles.manage',
            'settings.view',
            'settings.manage',
            'menu.view',
            'menu.manage',
            'orders.view',
            'orders.manage',
            'customers.view',
            'customers.manage',
            'payments.view',
            'payments.manage',
            'finance.view',
            'finance.manage',
            'reports.view',
            'delivery.view',
            'delivery.manage',
            'printing.view',
            'printing.manage',
            'whatsapp.view',
            'whatsapp.manage',
            'ai.view',
            'ai.manage',
        ],
        self::ADMIN_GERENTE => [
            'dashboard.view',
            'users.view',
            'users.manage',
            'roles.view',
            'settings.view',
            'settings.manage',
            'menu.view',
            'menu.manage',
            'orders.view',
            'orders.manage',
            'customers.view',
            'customers.manage',
            'payments.view',
            'payments.manage',
            'finance.view',
            'finance.manage',
            'reports.view',
            'delivery.view',
            'delivery.manage',
            'printing.view',
            'printing.manage',
            'whatsapp.view',
            'whatsapp.manage',
            'ai.view',
            'ai.manage',
        ],
        self::ATENDENTE => [
            'dashboard.view',
            'menu.view',
            'menu.manage',
            'orders.view',
            'orders.manage',
            'customers.view',
            'payments.view',
            'payments.manage',
            'delivery.view',
            'delivery.manage',
            'printing.view',
            'printing.manage',
            'whatsapp.view',
            'ai.view',
            'ai.manage',
        ],
    ];

    protected $fillable = [
        'name',
        'label',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::DEFAULT_ROLES;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function defaultPermissions(): array
    {
        return self::DEFAULT_ROLE_PERMISSIONS;
    }

    public static function authorityLevel(string $role): int
    {
        return self::AUTHORITY_LEVELS[$role] ?? 0;
    }

    public static function isProtected(string $role): bool
    {
        return $role === self::SUPER_ADMIN;
    }
}
