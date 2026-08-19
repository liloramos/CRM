<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

final class CopilotSensitiveItemNoteSanitizer
{
    /** @return array{note:string,rejected:bool} */
    public function sanitize(string $note): array
    {
        $note = trim($note);
        $identity = Str::of($note)->ascii()->lower()->toString();
        $financialOrOperationalInstruction = preg_match('/(?:r\$|\b\d+(?:[,.]\d+)?\s*reais?\b|\bpreco\b|\bvalor\b|\bdesconto\b|\bpagamento\b|\bpix\b|\bmarca(?:r)?\s+como\s+(?:pago|entregue|pronto|cancelado|concluido)\b|\bstatus\b)/', $identity) === 1;
        $administrativeAuthority = preg_match('/\b(?:gerente|dono|admin(?:istrador)?)\b.*\b(?:autoriz|aprov)|\b(?:autoriz|aprov).*\b(?:gerente|dono|admin)/', $identity) === 1;

        return $financialOrOperationalInstruction || $administrativeAuthority
            ? ['note' => '', 'rejected' => true]
            : ['note' => $note, 'rejected' => false];
    }
}
