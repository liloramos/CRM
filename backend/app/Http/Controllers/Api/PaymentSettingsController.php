<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentSettingsController extends Controller
{
    use ResolvesOperationalCompany;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->data($this->resolveCompany($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        abort_if(array_diff(array_keys($request->all()), ['methods', 'pix']) !== [], 422, 'Campos de configuração não permitidos.');
        $attributes = $request->validate([
            'methods' => ['required', 'array:pix,cash,debit_card,credit_card,customer_credit,other'],
            'methods.pix' => ['required', 'boolean'],
            'methods.cash' => ['required', 'boolean'],
            'methods.debit_card' => ['required', 'boolean'],
            'methods.credit_card' => ['required', 'boolean'],
            'methods.customer_credit' => ['required', 'boolean'],
            'methods.other' => ['required', 'boolean'],
            'pix' => ['nullable', 'array:public_key'],
            'pix.public_key' => ['nullable', 'string', 'max:160'],
        ]);
        $setting = $company->setting()->firstOrCreate([]);
        $settings = is_array($setting->settings) ? $setting->settings : [];
        $payments = is_array($settings['payments'] ?? null) ? $settings['payments'] : [];
        $pix = is_array($payments['pix'] ?? null) ? $payments['pix'] : [];

        $payments['methods'] = collect($this->methods())->mapWithKeys(
            fn (array $method): array => [$method['code'] => (bool) $attributes['methods'][$method['code']]],
        )->all();
        $pix['public_key'] = $attributes['pix']['public_key'] ?? null;
        $payments['pix'] = $pix;
        $settings['payments'] = $payments;
        $setting->update(['settings' => $settings]);

        return response()->json(['data' => $this->data($company)]);
    }

    /** @return array<string, mixed> */
    private function data($company): array
    {
        $settings = (array) ($company->setting?->settings ?? []);
        $payments = is_array($settings['payments'] ?? null) ? $settings['payments'] : [];
        $methods = is_array($payments['methods'] ?? null) ? $payments['methods'] : [];
        $pix = is_array($payments['pix'] ?? null) ? $payments['pix'] : [];

        return [
            'methods' => collect($this->methods())->map(fn (array $method): array => [
                ...$method,
                'enabled' => array_key_exists($method['code'], $methods) ? (bool) $methods[$method['code']] : true,
            ])->values(),
            'pix' => [
                'public_key' => is_string($pix['public_key'] ?? null) ? $pix['public_key'] : null,
            ],
        ];
    }

    /** @return list<array{code: string, label: string, description: string}> */
    private function methods(): array
    {
        return [
            ['code' => Payment::METHOD_PIX, 'label' => 'Pix', 'description' => 'Pagamento com comprovante sujeito à conferência.'],
            ['code' => Payment::METHOD_CASH, 'label' => 'Dinheiro', 'description' => 'Recebimento em dinheiro.'],
            ['code' => Payment::METHOD_DEBIT_CARD, 'label' => 'Cartão de débito', 'description' => 'Registro de pagamento por débito.'],
            ['code' => Payment::METHOD_CREDIT_CARD, 'label' => 'Cartão de crédito', 'description' => 'Registro de pagamento por crédito.'],
            ['code' => Payment::METHOD_CUSTOMER_CREDIT, 'label' => 'Crédito do cliente', 'description' => 'Uso de crédito já disponível ao cliente.'],
            ['code' => Payment::METHOD_OTHER, 'label' => 'Outro meio registrado', 'description' => 'Registro manual de outra forma aceita.'],
        ];
    }
}
