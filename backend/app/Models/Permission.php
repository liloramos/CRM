<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * @var array<string, string>
     */
    public const DEFAULT_PERMISSIONS = [
        'dashboard.view' => 'Visualizar dashboard',
        'users.view' => 'Visualizar usuarios',
        'users.manage' => 'Gerenciar usuarios',
        'roles.view' => 'Visualizar papeis e permissoes',
        'roles.manage' => 'Gerenciar papeis e permissoes',
        'settings.view' => 'Visualizar configuracoes',
        'settings.manage' => 'Gerenciar configuracoes',
    ];

    protected $fillable = [
        'name',
        'label',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::DEFAULT_PERMISSIONS;
    }
}
