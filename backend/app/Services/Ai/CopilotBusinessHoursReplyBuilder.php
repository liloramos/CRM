<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Services\Operational\CompanyOperatingHoursService;

final class CopilotBusinessHoursReplyBuilder
{
    public function __construct(private readonly CompanyOperatingHoursService $operatingHours) {}

    /** @return array<string,mixed> */
    public function build(Company $company): array
    {
        $status = $this->operatingHours->status($company);
        $reply = match ($status['status']) {
            CompanyOperatingHoursService::STATUS_OPEN => 'Sim, estamos abertos agora 😊 Nosso atendimento funciona '.$status['schedule'].'.',
            CompanyOperatingHoursService::STATUS_CLOSED => $this->operatingHours->closedReply($status),
            default => 'O horário de funcionamento ainda não está configurado aqui.',
        };

        return $this->analysis($reply, $status);
    }

    /** @param array<string,mixed>|null $status @return array<string,mixed> */
    public function closedOrder(Company $company, ?array $status = null): array
    {
        $status ??= $this->operatingHours->status($company);

        return $this->analysis($this->operatingHours->closedReply($status), $status, 'ORDER_CREATE');
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private function analysis(string $reply, array $status, string $intent = 'BUSINESS_HOURS_REQUEST'): array
    {
        return [
            'intent' => $intent,
            'confidence' => 1,
            'summary' => 'Consulta de horário de funcionamento.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => $reply,
            'metadata' => [
                'reply_source' => $status['status'] === CompanyOperatingHoursService::STATUS_UNKNOWN
                    ? 'operating_hours_unconfigured'
                    : 'operating_hours',
                ...$this->operatingHours->metadata($status),
            ],
        ];
    }
}
