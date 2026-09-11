<?php

namespace App\Services\SystemAssistant;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;

final class SystemAssistantActionRegistry
{
    public function __construct(private readonly SystemAssistantFeatureCatalog $catalog) {}

    /** @param array<string, mixed> $candidate @return array<string, mixed>|null */
    public function validate(User $user, Company $company, array $candidate): ?array
    {
        $type = (string) ($candidate['type'] ?? '');
        $target = (string) ($candidate['target'] ?? '');
        $parameters = is_array($candidate['parameters'] ?? null) ? $candidate['parameters'] : [];

        if ($type === 'open_order') {
            return $this->order($user, $company, $parameters);
        }
        if ($type === 'open_customer') {
            return $this->customer($user, $company, $parameters);
        }

        $feature = collect($this->catalog->all())->first(fn (array $feature): bool => $feature['route'] === $target);
        $expectedTargets = [
            'show_pending_payments' => 'pagamentos', 'show_deliveries' => 'entregas', 'open_menu' => 'cardapio',
            'open_company_settings' => 'empresa', 'open_finance' => 'financeiro', 'open_reports' => 'relatorios',
        ];
        if (! is_array($feature) || (! in_array($type, ['explain_feature', 'navigate_to_page'], true) && ($expectedTargets[$type] ?? null) !== $target)) {
            return null;
        }

        if ($feature['permission'] !== null && ! $user->hasPermissionTo($feature['permission'])) {
            return null;
        }

        return ['type' => $type, 'target' => $feature['route'], 'label' => $feature['label'], 'parameters' => []];
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed>|null */
    private function order(User $user, Company $company, array $parameters): ?array
    {
        if (! $user->hasPermissionTo('orders.view')) {
            return null;
        }
        $query = Order::query()->where('company_id', $company->id);
        $order = isset($parameters['order_id'])
            ? $query->whereKey((int) $parameters['order_id'])->first()
            : $query->where('code', (string) ($parameters['order_code'] ?? ''))->first();

        return $order ? ['type' => 'open_order', 'target' => 'pedidos', 'label' => 'Abrir pedido', 'parameters' => ['order_id' => (string) $order->id]] : null;
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed>|null */
    private function customer(User $user, Company $company, array $parameters): ?array
    {
        if (! $user->hasPermissionTo('customers.view') || ! isset($parameters['customer_id'])) {
            return null;
        }
        $customer = Customer::query()->where('company_id', $company->id)->whereKey((int) $parameters['customer_id'])->first();

        return $customer ? ['type' => 'open_customer', 'target' => 'clientes', 'label' => 'Abrir cliente', 'parameters' => ['customer_id' => (string) $customer->id]] : null;
    }
}
