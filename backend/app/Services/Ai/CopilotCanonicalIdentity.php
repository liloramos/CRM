<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotCanonicalIdentity
{
    /** @param list<array<string,mixed>|string> $missing @return list<array{code:string,label:string}> */
    public function missing(array $missing, bool $n8VariantResolved = false): array
    {
        return collect($missing)
            ->map(function (array|string $value) use ($n8VariantResolved): ?array {
                $rawCode = is_array($value) ? (string) ($value['code'] ?? '') : $value;
                $code = $this->missingCode($rawCode);
                if ($code === '' || ($code === 'N8_VARIANT' && $n8VariantResolved)) {
                    return null;
                }

                $label = is_array($value) ? trim((string) ($value['label'] ?? '')) : '';

                return ['code' => $code, 'label' => $this->missingLabel($code, $label)];
            })
            ->filter()
            ->unique('code')
            ->values()
            ->all();
    }

    /** @param list<string> $codes @return list<string> */
    public function missingCodes(array $codes, bool $n8VariantResolved = false): array
    {
        return array_column($this->missing($codes, $n8VariantResolved), 'code');
    }

    public function removal(string $value): string
    {
        $identity = $this->key($value);
        $identity = str_starts_with($identity, 'sem') ? substr($identity, 3) : $identity;

        return match ($identity) {
            'salada', 'saladacasa' => 'sem_salada',
            'feijao' => 'sem_feijao',
            'mandioca' => 'sem_mandioca',
            default => 'sem_'.$identity,
        };
    }

    private function missingCode(string $value): string
    {
        return match ($this->key($value)) {
            'meat', 'n5casacarne' => 'CARNE',
            'saladarequired' => 'SALADA',
            'deliveryaddress' => 'ADDRESS',
            'itemtorepeat' => 'PREVIOUS_ORDER_REFERENCE',
            'menuproduct' => 'MENU_ITEM',
            'quantity', 'quantityconfirmation', 'validquantity' => 'VALID_QUANTITY',
            default => Str::upper(trim($value)),
        };
    }

    private function missingLabel(string $code, string $fallback): string
    {
        return match ($code) {
            'CARNE' => 'Carne',
            'SALADA' => 'Salada',
            'ADDRESS' => 'Endereco',
            'PAYMENT_METHOD' => 'Forma de pagamento',
            'MENU_ITEM' => 'Produto',
            default => $fallback !== '' ? $fallback : $code,
        };
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
