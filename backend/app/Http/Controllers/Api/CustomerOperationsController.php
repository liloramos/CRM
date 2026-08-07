<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
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

    public function store(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);

        $validated = $this->validatedCustomerPayload($request);

        $this->assertUniquePhone($company->id, (string) ($validated['phone'] ?? ''));

        $customer = DB::transaction(function () use ($company, $validated): Customer {
            $customer = Customer::query()->create([
                'company_id' => $company->id,
                'name' => Str::squish($validated['name']),
                'phone' => $this->normalizedPhoneOrNull($validated['phone'] ?? null),
                'email' => $validated['email'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'source_channel' => 'manual',
            ]);

            $this->upsertDefaultAddress($customer, $validated['address'] ?? []);

            return $customer->refresh()->load('addresses');
        });

        return response()->json([
            'data' => $this->summary($customer),
        ], 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $company = $this->resolveCompany($request);
        abort_unless((int) $customer->company_id === (int) $company->id, 404);

        $validated = $this->validatedCustomerPayload($request);

        $this->assertUniquePhone($company->id, (string) ($validated['phone'] ?? ''), $customer->id);

        $customer = DB::transaction(function () use ($customer, $validated): Customer {
            $customer->forceFill([
                'name' => Str::squish($validated['name']),
                'phone' => $this->normalizedPhoneOrNull($validated['phone'] ?? null),
                'email' => $validated['email'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ])->save();

            $this->upsertDefaultAddress($customer, $validated['address'] ?? []);

            return $customer->refresh()->load('addresses');
        });

        return response()->json([
            'data' => $this->summary($customer),
        ]);
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
    private function upsertDefaultAddress(Customer $customer, array $address): void
    {
        $hasAddress = collect($address)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->isNotEmpty();

        if (! $hasAddress) {
            return;
        }

        CustomerAddress::query()->updateOrCreate(
            [
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'is_default' => true,
            ],
            [
                'label' => 'Principal',
                'recipient_name' => $customer->name,
                'recipient_phone' => $customer->phone,
                'street' => $address['street'] ?? null,
                'number' => $address['number'] ?? null,
                'complement' => $address['complement'] ?? null,
                'neighborhood' => $address['neighborhood'] ?? null,
                'city' => $address['city'] ?? null,
                'reference' => $address['reference'] ?? null,
                'country_code' => 'BR',
            ],
        );
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
                'street' => $defaultAddress->street,
                'number' => $defaultAddress->number,
                'complement' => $defaultAddress->complement,
                'neighborhood' => $defaultAddress->neighborhood,
                'city' => $defaultAddress->city,
                'reference' => $defaultAddress->reference,
            ] : null,
        ];
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
