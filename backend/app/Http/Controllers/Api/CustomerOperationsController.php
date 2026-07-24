<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->where('company_id', $company->id)
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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $phoneDigits = $this->phoneDigits((string) ($validated['phone'] ?? ''));

        if ($phoneDigits !== '') {
            $duplicate = Customer::query()
                ->where('company_id', $company->id)
                ->whereNotNull('phone')
                ->get()
                ->first(fn (Customer $customer): bool => $this->phoneDigits((string) $customer->phone) === $phoneDigits);

            if ($duplicate instanceof Customer) {
                throw ValidationException::withMessages([
                    'phone' => ['Ja existe um cliente com este telefone.'],
                ]);
            }
        }

        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => Str::squish($validated['name']),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'data' => $this->summary($customer),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Customer $customer): array
    {
        return [
            'id' => (string) $customer->id,
            'name' => $customer->name,
            'phoneLabel' => $customer->phone ?: 'Sem telefone cadastrado',
            'tags' => ['Operacao'],
            'creditBalance' => round(((int) $customer->credit_balance_cents) / 100, 2),
            'notes' => $customer->notes ? [$customer->notes] : [],
            'preferences' => [],
        ];
    }

    private function phoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
