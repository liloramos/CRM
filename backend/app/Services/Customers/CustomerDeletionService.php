<?php

namespace App\Services\Customers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CustomerDeletionService
{
    /** @return array{eligible: bool, reasons: list<string>} */
    public function eligibility(Company $company, Customer $customer): array
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            return ['eligible' => false, 'reasons' => ['customer_not_found']];
        }

        $reasons = [];

        if ($customer->conversations()->exists()) {
            $reasons[] = 'conversation_history';
        }
        if ($customer->payerOrders()->exists()) {
            $reasons[] = 'order_history';
        }
        if ($customer->payments()->exists()) {
            $reasons[] = 'payment_history';
        }
        if ($customer->creditMovements()->exists() || (int) $customer->credit_balance_cents !== 0) {
            $reasons[] = 'credit_history';
        }

        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }

    /** @return array{deleted: bool, eligibility: array{eligible: bool, reasons: list<string>}} */
    public function delete(Company $company, Customer $customer, User $actor): array
    {
        if (! $actor->hasPermissionTo('customers.manage')
            || ! $actor->hasAnyRole([Role::SUPER_ADMIN, Role::ADMIN_GERENTE])) {
            throw new AuthorizationException('Voce nao tem permissao para excluir clientes.');
        }

        return DB::transaction(function () use ($company, $customer): array {
            $lockedCustomer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $eligibility = $this->eligibility($company, $lockedCustomer);

            if (! $eligibility['eligible']) {
                return ['deleted' => false, 'eligibility' => $eligibility];
            }

            $lockedCustomer->addresses()->delete();
            $lockedCustomer->delete();

            return ['deleted' => true, 'eligibility' => $eligibility];
        });
    }
}
