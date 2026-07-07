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
        'menu.view' => 'Visualizar cardapio',
        'menu.manage' => 'Gerenciar cardapio e disponibilidade',
        'orders.view' => 'Visualizar pedidos',
        'orders.manage' => 'Gerenciar pedidos e status',
        'payments.view' => 'Visualizar pagamentos e comprovantes',
        'payments.manage' => 'Gerenciar pagamentos, comprovantes e creditos',
        'delivery.view' => 'Visualizar entregas, retiradas e taxas',
        'delivery.manage' => 'Gerenciar entregas, retiradas e calculo de taxas',
        'printing.view' => 'Visualizar comandas e previas de impressao',
        'printing.manage' => 'Gerenciar impressao, reimpressao e excecoes de comanda',
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
