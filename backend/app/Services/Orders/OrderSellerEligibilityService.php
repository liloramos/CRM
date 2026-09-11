<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class OrderSellerEligibilityService
{
    /** @return Collection<int, User> */
    public function candidates(Company $company): Collection
    {
        return $this->eligibleQuery($company)
            ->orderBy('name')
            ->get();
    }

    public function resolve(Company $company, mixed $sellerUserId): ?User
    {
        if ($sellerUserId === null || $sellerUserId === '') {
            return null;
        }

        $seller = $this->eligibleQuery($company)
            ->whereKey((int) $sellerUserId)
            ->first();

        if (! $seller instanceof User) {
            throw new DomainException('O responsável selecionado não está habilitado para vendas neste restaurante.');
        }

        return $seller;
    }

    /** @return Builder<User> */
    private function eligibleQuery(Company $company): Builder
    {
        return User::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('can_be_seller', true);
    }
}
