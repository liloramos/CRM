<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use DomainException;
use Illuminate\Support\Facades\DB;

class CustomerAddressBookService
{
    /** @param array<string, mixed> $attributes */
    public function create(Customer $customer, array $attributes): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $attributes): CustomerAddress {
            $lockedCustomer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $makeDefault = (bool) ($attributes['is_default'] ?? false)
                || ! $lockedCustomer->addresses()->exists();

            if ($makeDefault) {
                $lockedCustomer->addresses()->update(['is_default' => false]);
            }

            return $lockedCustomer->addresses()->create($this->attributes(
                $lockedCustomer,
                $attributes,
                $makeDefault,
            ));
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Customer $customer, CustomerAddress $address, array $attributes): CustomerAddress
    {
        $this->assertOwnership($customer, $address);

        return DB::transaction(function () use ($customer, $address, $attributes): CustomerAddress {
            $lockedCustomer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $lockedAddress = CustomerAddress::query()->whereKey($address->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnership($lockedCustomer, $lockedAddress);

            $makeDefault = (bool) ($attributes['is_default'] ?? $lockedAddress->is_default);
            if ($makeDefault) {
                $lockedCustomer->addresses()->whereKeyNot($lockedAddress->id)->update(['is_default' => false]);
            }

            $lockedAddress->forceFill($this->attributes(
                $lockedCustomer,
                [...$lockedAddress->only($lockedAddress->getFillable()), ...$attributes],
                $makeDefault,
            ))->save();

            if (! $makeDefault && ! $lockedCustomer->addresses()->where('is_default', true)->exists()) {
                $lockedAddress->forceFill(['is_default' => true])->save();
            }

            return $lockedAddress->refresh();
        });
    }

    public function makeDefault(Customer $customer, CustomerAddress $address): CustomerAddress
    {
        return $this->update($customer, $address, ['is_default' => true]);
    }

    public function delete(Customer $customer, CustomerAddress $address): void
    {
        $this->assertOwnership($customer, $address);

        DB::transaction(function () use ($customer, $address): void {
            $lockedCustomer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $lockedAddress = CustomerAddress::query()->whereKey($address->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnership($lockedCustomer, $lockedAddress);

            $hasUnprotectedOrder = $lockedAddress->orders()
                ->whereNull('delivery_address_snapshot')
                ->exists();
            $hasUnprotectedQuote = $lockedAddress->deliveryQuotes()
                ->whereNull('delivery_address_snapshot')
                ->exists();

            if ($hasUnprotectedOrder || $hasUnprotectedQuote) {
                throw new DomainException('Este endereço ainda é necessário para preservar um pedido sem snapshot histórico.');
            }

            $wasDefault = (bool) $lockedAddress->is_default;
            $lockedAddress->delete();

            if ($wasDefault) {
                $next = $lockedCustomer->addresses()->orderBy('id')->lockForUpdate()->first();
                $next?->forceFill(['is_default' => true])->save();
            }
        });
    }

    private function assertOwnership(Customer $customer, CustomerAddress $address): void
    {
        abort_unless(
            (int) $address->customer_id === (int) $customer->id
            && (int) $address->company_id === (int) $customer->company_id,
            404,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function attributes(Customer $customer, array $attributes, bool $isDefault): array
    {
        return [
            'company_id' => $customer->company_id,
            'label' => trim((string) ($attributes['label'] ?? '')) ?: 'Principal',
            'recipient_name' => trim((string) ($attributes['recipient_name'] ?? '')) ?: $customer->name,
            'recipient_phone' => trim((string) ($attributes['recipient_phone'] ?? '')) ?: $customer->phone,
            'postal_code' => $this->nullableString($attributes['postal_code'] ?? null),
            'street' => $this->nullableString($attributes['street'] ?? null),
            'number' => $this->nullableString($attributes['number'] ?? null),
            'complement' => $this->nullableString($attributes['complement'] ?? null),
            'neighborhood' => $this->nullableString($attributes['neighborhood'] ?? null),
            'city' => $this->nullableString($attributes['city'] ?? null),
            'state' => ($state = $this->nullableString($attributes['state'] ?? null)) ? strtoupper($state) : null,
            'country_code' => strtoupper($this->nullableString($attributes['country_code'] ?? null) ?? 'BR'),
            'reference' => $this->nullableString($attributes['reference'] ?? null),
            'latitude' => $attributes['latitude'] ?? null,
            'longitude' => $attributes['longitude'] ?? null,
            'is_default' => $isDefault,
            'metadata' => $attributes['metadata'] ?? null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
