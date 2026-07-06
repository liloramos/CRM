<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN_GERENTE = 'admin_gerente';

    public const ATENDENTE = 'atendente';

    /**
     * @var array<string, string>
     */
    public const DEFAULT_ROLES = [
        self::SUPER_ADMIN => 'Super admin',
        self::ADMIN_GERENTE => 'Admin/Gerente',
        self::ATENDENTE => 'Atendente',
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
            'payments.view',
            'payments.manage',
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
            'payments.view',
            'payments.manage',
        ],
        self::ATENDENTE => [
            'dashboard.view',
            'menu.view',
            'orders.view',
            'orders.manage',
            'payments.view',
            'payments.manage',
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
}
