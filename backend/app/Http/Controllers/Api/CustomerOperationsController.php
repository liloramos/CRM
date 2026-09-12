<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\Customers\CustomerAddressBookService;
use App\Services\Customers\CustomerDeletionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerOperationsController extends Controller
{
    use ResolvesOperationalCompany;

    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 12);

        $customers = Customer::query()
            ->with('addresses')
            ->where('company_id', $company->id)
            ->when(! config('chatbotcrm.whatsapp.demo_data_enabled'), function ($query): void {
                $query->where(function ($nested): void {
                    $nested->whereNull('source_channel')
                        ->orWhere('source_channel', '!=', Customer::SOURCE_CHANNEL_DEMO);
                })
                    ->where(function ($nested): void {
                        $nested->whereNull('email')
                            ->orWhere('email', '!=', Customer::DEMO_EMAIL);
                    });
            })
            ->when(! config('chatbotcrm.whatsapp.demo_data_enabled'), function ($query): void {
                $query->where(function ($nested): void {
                    $nested->whereNull('email')
                        ->orWhere('email', 'not like', Customer::DASHBOARD_DEMO_EMAIL_PREFIX.'%@example.test');
                });
            })
            ->when($search !== '', function ($query) use ($search): void {
                $needle = '%'.Str::lower($search).'%';
                $digits = $this->phoneDigits($search);

                $query->where(function ($nested) use ($needle, $digits): void {
                    $nested->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$needle]);

                    if ($digits !== '') {
                        $nested->orWhereRaw(
                            "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?",
                            ['%'.$digits.'%'],
                        );
                    }
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $customers->map(fn (Customer $customer): array => $this->summary($customer))->values(),
        ]);
    }

    public function store(Request $request, CustomerAddressBookService $addresses): JsonResponse
    {
        $company = $this->resolveCompany($request);

        $validated = $this->validatedCustomerPayload($request);

        $this->assertUniquePhone($company->id, (string) ($validated['phone'] ?? ''));

        $customer = DB::transaction(function () use ($company, $validated, $addresses): Customer {
            $customer = Customer::query()->create([
                'company_id' => $company->id,
                'name' => Str::squish($validated['name']),
                'phone' => $this->normalizedPhoneOrNull($validated['phone'] ?? null),
                'email' => $validated['email'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'source_channel' => 'manual',
            ]);

            $this->storeInitialAddresses($customer, $validated, $addresses);

            return $customer->refresh()->load('addresses');
        });

        return response()->json([
            'data' => $this->summary($customer),
        ], 201);
    }

    public function update(Request $request, Customer $customer, CustomerAddressBookService $addresses): JsonResponse
    {
        $company = $this->resolveCompany($request);
        abort_unless((int) $customer->company_id === (int) $company->id, 404);

        $validated = $this->validatedCustomerPayload($request);

        $this->assertUniquePhone($company->id, (string) ($validated['phone'] ?? ''), $customer->id);

        $customer = DB::transaction(function () use ($customer, $validated, $addresses): Customer {
            $customer->forceFill([
                'name' => Str::squish($validated['name']),
                'phone' => $this->normalizedPhoneOrNull($validated['phone'] ?? null),
                'email' => $validated['email'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ])->save();

            if (array_key_exists('addresses', $validated)) {
                $this->syncAddresses($customer, $validated['addresses'] ?? [], $addresses);
            } elseif (array_key_exists('address', $validated)) {
                $this->upsertLegacyDefaultAddress($customer, $validated['address'] ?? [], $addresses);
            }

            return $customer->refresh()->load('addresses');
        });

        return response()->json([
            'data' => $this->summary($customer),
        ]);
    }

    public function storeAddress(
        Request $request,
        Customer $customer,
        CustomerAddressBookService $addresses,
    ): JsonResponse {
        $this->assertCustomerBelongsToCompany($request, $customer);
        $address = $addresses->create($customer, $this->validatedAddressPayload($request));

        return response()->json(['data' => $this->addressSummary($address)], 201);
    }

    public function updateAddress(
        Request $request,
        Customer $customer,
        CustomerAddress $address,
        CustomerAddressBookService $addresses,
    ): JsonResponse {
        $this->assertCustomerBelongsToCompany($request, $customer);
        $address = $addresses->update($customer, $address, $this->validatedAddressPayload($request));

        return response()->json(['data' => $this->addressSummary($address)]);
    }

    public function setDefaultAddress(
        Request $request,
        Customer $customer,
        CustomerAddress $address,
        CustomerAddressBookService $addresses,
    ): JsonResponse {
        $this->assertCustomerBelongsToCompany($request, $customer);

        return response()->json(['data' => $this->addressSummary($addresses->makeDefault($customer, $address))]);
    }

    public function destroyAddress(
        Request $request,
        Customer $customer,
        CustomerAddress $address,
        CustomerAddressBookService $addresses,
    ): JsonResponse {
        $this->assertCustomerBelongsToCompany($request, $customer);

        try {
            $addresses->delete($customer, $address);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['deleted' => true, 'address_id' => (string) $address->id]]);
    }

    public function destroy(
        Request $request,
        Customer $customer,
        CustomerDeletionService $deletion,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        abort_unless((int) $customer->company_id === (int) $company->id, 404);

        $result = $deletion->delete($company, $customer, $request->user());

        if (! $result['deleted']) {
            return response()->json([
                'message' => 'Este cliente possui historico operacional ou financeiro e nao pode ser excluido permanentemente.',
                'code' => 'customer_has_protected_history',
                'data' => $result['eligibility'],
            ], 422);
        }

        return response()->json(['data' => ['deleted' => true, 'customer_id' => (string) $customer->id]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCustomerPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'array'],
            'address.street' => ['nullable', 'string', 'max:180'],
            'address.number' => ['nullable', 'string', 'max:40'],
            'address.complement' => ['nullable', 'string', 'max:120'],
            'address.neighborhood' => ['nullable', 'string', 'max:120'],
            'address.city' => ['nullable', 'string', 'max:120'],
            'address.reference' => ['nullable', 'string', 'max:180'],
            'address.postal_code' => ['nullable', 'string', 'max:16'],
            'address.state' => ['nullable', 'string', 'size:2'],
            'addresses' => ['sometimes', 'array', 'max:20'],
            'addresses.*.id' => ['nullable', 'integer'],
            'addresses.*.label' => ['required', 'string', 'max:80'],
            'addresses.*.recipient_name' => ['nullable', 'string', 'max:120'],
            'addresses.*.recipient_phone' => ['nullable', 'string', 'max:40'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:16'],
            'addresses.*.street' => ['required', 'string', 'max:180'],
            'addresses.*.number' => ['required', 'string', 'max:40'],
            'addresses.*.complement' => ['nullable', 'string', 'max:120'],
            'addresses.*.neighborhood' => ['required', 'string', 'max:120'],
            'addresses.*.city' => ['required', 'string', 'max:120'],
            'addresses.*.state' => ['nullable', 'string', 'size:2'],
            'addresses.*.country_code' => ['nullable', 'string', 'size:2'],
            'addresses.*.reference' => ['nullable', 'string', 'max:180'],
            'addresses.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'addresses.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'addresses.*.is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedAddressPayload(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'street' => ['required', 'string', 'max:180'],
            'number' => ['required', 'string', 'max:40'],
            'complement' => ['nullable', 'string', 'max:120'],
            'neighborhood' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'reference' => ['nullable', 'string', 'max:180'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertUniquePhone(int $companyId, string $phone, ?int $ignoreCustomerId = null): void
    {
        $phoneDigits = $this->phoneDigits($phone);

        if ($phoneDigits === '') {
            return;
        }

        $duplicate = Customer::query()
            ->where('company_id', $companyId)
            ->when($ignoreCustomerId !== null, fn ($query) => $query->whereKeyNot($ignoreCustomerId))
            ->whereNotNull('phone')
            ->get()
            ->first(fn (Customer $customer): bool => $this->phoneDigits((string) $customer->phone) === $phoneDigits);

        if ($duplicate instanceof Customer) {
            throw ValidationException::withMessages([
                'phone' => ['Já existe um cliente com este telefone.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function upsertLegacyDefaultAddress(
        Customer $customer,
        array $address,
        CustomerAddressBookService $addresses,
    ): void {
        $hasAddress = collect($address)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->isNotEmpty();

        if (! $hasAddress) {
            return;
        }

        $default = $customer->addresses()->where('is_default', true)->first();
        $attributes = [
            'label' => 'Principal',
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'postal_code' => $address['postal_code'] ?? null,
            'street' => $address['street'] ?? null,
            'number' => $address['number'] ?? null,
            'complement' => $address['complement'] ?? null,
            'neighborhood' => $address['neighborhood'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'reference' => $address['reference'] ?? null,
            'country_code' => 'BR',
            'is_default' => true,
        ];

        $default
            ? $addresses->update($customer, $default, $attributes)
            : $addresses->create($customer, $attributes);
    }

    /** @param array<string, mixed> $validated */
    private function storeInitialAddresses(Customer $customer, array $validated, CustomerAddressBookService $addresses): void
    {
        if (! empty($validated['addresses'])) {
            foreach ($validated['addresses'] as $address) {
                $addresses->create($customer, $address);
            }

            return;
        }

        $this->upsertLegacyDefaultAddress($customer, $validated['address'] ?? [], $addresses);
    }

    /** @param list<array<string, mixed>> $payload */
    private function syncAddresses(Customer $customer, array $payload, CustomerAddressBookService $addresses): void
    {
        $kept = [];
        foreach ($payload as $row) {
            if (! empty($row['id'])) {
                $address = $customer->addresses()->whereKey($row['id'])->firstOrFail();
                $kept[] = $addresses->update($customer, $address, $row)->id;
            } else {
                $kept[] = $addresses->create($customer, $row)->id;
            }
        }

        $customer->addresses()->whereNotIn('id', $kept)->get()
            ->each(fn (CustomerAddress $address) => $addresses->delete($customer, $address));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Customer $customer): array
    {
        $customer->loadMissing('addresses');
        $defaultAddress = $customer->addresses->firstWhere('is_default', true) ?? $customer->addresses->first();

        return [
            'id' => (string) $customer->id,
            'name' => $customer->name,
            'phoneLabel' => $customer->phone ?: 'Sem telefone cadastrado',
            'phone' => $customer->phone,
            'email' => $customer->email,
            'whatsappId' => $customer->whatsapp_id,
            'whatsappProfileName' => $customer->whatsapp_profile_name,
            'sourceChannel' => $customer->source_channel,
            'tags' => array_values(array_filter([
                $customer->source_channel === 'whatsapp' ? 'WhatsApp' : 'Operação',
                $customer->whatsapp_profile_name ? 'Perfil WhatsApp' : null,
            ])),
            'creditBalance' => round(((int) $customer->credit_balance_cents) / 100, 2),
            'notes' => $customer->notes ? [$customer->notes] : [],
            'preferences' => [],
            'address' => $defaultAddress ? [
                'id' => (string) $defaultAddress->id,
                'label' => $defaultAddress->label,
                'postal_code' => $defaultAddress->postal_code,
                'street' => $defaultAddress->street,
                'number' => $defaultAddress->number,
                'complement' => $defaultAddress->complement,
                'neighborhood' => $defaultAddress->neighborhood,
                'city' => $defaultAddress->city,
                'state' => $defaultAddress->state,
                'reference' => $defaultAddress->reference,
            ] : null,
            'addresses' => $customer->addresses
                ->sortBy([['is_default', 'desc'], ['id', 'asc']])
                ->map(fn (CustomerAddress $address): array => $this->addressSummary($address))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function addressSummary(CustomerAddress $address): array
    {
        return [
            'id' => (string) $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'postal_code' => $address->postal_code,
            'street' => $address->street,
            'number' => $address->number,
            'complement' => $address->complement,
            'neighborhood' => $address->neighborhood,
            'city' => $address->city,
            'state' => $address->state,
            'country_code' => $address->country_code,
            'reference' => $address->reference,
            'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
            'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            'is_default' => (bool) $address->is_default,
        ];
    }

    private function assertCustomerBelongsToCompany(Request $request, Customer $customer): void
    {
        $company = $this->resolveCompany($request);
        abort_unless((int) $customer->company_id === (int) $company->id, 404);
    }

    private function phoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function normalizedPhoneOrNull(?string $phone): ?string
    {
        $digits = $this->phoneDigits((string) $phone);

        return $digits !== '' ? $digits : null;
    }
}
