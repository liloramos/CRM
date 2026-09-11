<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * Permissions that express protected DEV authority and cannot be delegated
     * as an individual override to a lower access profile.
     *
     * @var list<string>
     */
    public const PROTECTED_PERMISSIONS = [
        'roles.manage',
    ];

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
        'customers.view' => 'Visualizar clientes',
        'customers.manage' => 'Gerenciar clientes',
        'payments.view' => 'Visualizar pagamentos e comprovantes',
        'payments.manage' => 'Gerenciar pagamentos, comprovantes e creditos',
        'finance.view' => 'Visualizar financeiro gerencial',
        'finance.manage' => 'Executar correcoes financeiras administrativas',
        'reports.view' => 'Visualizar relatorios gerenciais',
        'delivery.view' => 'Visualizar entregas, retiradas e taxas',
        'delivery.manage' => 'Gerenciar entregas, retiradas e calculo de taxas',
        'printing.view' => 'Visualizar comandas e previas de impressao',
        'printing.manage' => 'Gerenciar impressao, reimpressao e excecoes de comanda',
        'whatsapp.view' => 'Visualizar status e configuracao tecnica do WhatsApp',
        'whatsapp.manage' => 'Gerenciar provider, webhooks e integracao WhatsApp',
        'ai.view' => 'Visualizar status e configuracao tecnica de IA e automacao',
        'ai.manage' => 'Gerenciar modo de automacao, sugestoes e fallback manual',
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

    /**
     * @return list<string>
     */
    public static function delegable(): array
    {
        return array_values(array_diff(array_keys(self::DEFAULT_PERMISSIONS), self::PROTECTED_PERMISSIONS));
    }
}
