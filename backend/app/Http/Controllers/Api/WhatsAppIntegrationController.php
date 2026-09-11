<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessageDelivery;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppIntegrationController extends Controller
{
    use ResolvesOperationalCompany;

    public function show(Request $request, WhatsAppService $whatsapp): JsonResponse
    {
        return response()->json(['data' => $this->data($this->resolveCompany($request), $whatsapp)]);
    }

    public function check(Request $request, WhatsAppService $whatsapp): JsonResponse
    {
        $diagnosis = $whatsapp->diagnoseConnectivity();
        $available = ($diagnosis['status'] ?? null) === 'available';

        return response()->json(['data' => [
            'status' => $available ? 'validated' : 'failed',
            'message' => $available
                ? 'Conexão validada com segurança.'
                : (($diagnosis['error_code'] ?? null) === 'configuration_missing'
                    ? 'A configuração necessária da integração está incompleta.'
                    : 'Não foi possível validar a conexão com a Meta.'),
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function data($company, WhatsAppService $whatsapp): array
    {
        $connection = $whatsapp->connectionStatus($company);
        $provider = (string) ($connection['provider'] ?? '');
        $details = (array) ($connection['details'] ?? []);
        $account = $company->whatsappAccounts()->orderByDesc('is_default')->orderBy('id')->first();
        $lastWebhookEvent = $company->whatsappWebhookEvents()->latest('received_at')->first();
        $lastInbound = $this->activity($company->whatsappMessageDeliveries()->where('direction', WhatsAppMessageDelivery::DIRECTION_INBOUND)->latest('created_at')->latest('id')->first(), 'created_at');
        $lastOutbound = $this->activity($company->whatsappMessageDeliveries()->where('direction', WhatsAppMessageDelivery::DIRECTION_OUTBOUND)->orderByRaw('COALESCE(sent_at, created_at) DESC')->latest('id')->first(), 'sent_at');
        $isLocal = $provider === WhatsAppAccount::PROVIDER_FAKE;
        $configured = (bool) ($connection['configured'] ?? false) && ! $isLocal;
        $status = $isLocal ? 'local' : (! $configured ? 'not_configured' : ($account?->status === WhatsAppAccount::STATUS_ERROR ? 'error' : ($account?->status === WhatsAppAccount::STATUS_CONNECTED ? 'connected' : 'configured')));
        $webhookUrl = $this->publicWebhookUrl();

        return [
            'provider' => $isLocal ? 'Ambiente local' : 'Meta Cloud API',
            'configured' => $configured,
            'connection_status' => $status,
            'phone_number_masked' => $this->maskPhone($account?->display_phone_number),
            'phone_number_id_masked' => $this->maskIdentifier($account?->phone_number_id ?: config('chatbotcrm.whatsapp.meta.phone_number_id')),
            'waba_id_masked' => $this->maskIdentifier($account?->business_account_id ?: config('chatbotcrm.whatsapp.meta.business_account_id')),
            'api_version' => $isLocal ? null : (data_get($account?->settings, 'api_version') ?? $details['api_version'] ?? null),
            'token_configured' => (bool) ($details['token_present'] ?? false),
            'webhook' => [
                'url' => $webhookUrl,
                'status' => $isLocal ? 'local' : ($account?->webhook_verified_at ? 'verified' : 'pending'),
                'last_received_at' => $account?->last_webhook_at?->toIso8601String() ?? $lastWebhookEvent?->received_at?->toIso8601String(),
            ],
            'last_inbound' => $lastInbound,
            'last_outbound' => $lastOutbound,
            'recent_errors' => $this->errors($company),
            'processing' => [
                'pending_webhook_events' => $company->whatsappWebhookEvents()->where('status', WhatsAppWebhookEvent::STATUS_RECEIVED)->count(),
                'failed_webhook_events' => $company->whatsappWebhookEvents()->where('status', WhatsAppWebhookEvent::STATUS_FAILED)->count(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function activity(?WhatsAppMessageDelivery $delivery, string $timestamp): ?array
    {
        if ($delivery === null) {
            return null;
        }

        return [
            'conversation_id' => $delivery->conversation_id,
            'status' => $delivery->status,
            'occurred_at' => ($delivery->{$timestamp} ?? $delivery->created_at)?->toIso8601String(),
        ];
    }

    /** @return list<array{kind: string, occurred_at: string|null}> */
    private function errors($company): array
    {
        $deliveryErrors = $company->whatsappMessageDeliveries()
            ->where('status', WhatsAppMessageDelivery::STATUS_FAILED)
            ->latest('failed_at')
            ->limit(4)
            ->get()
            ->map(fn (WhatsAppMessageDelivery $delivery): array => ['kind' => 'Falha de envio', 'occurred_at' => $delivery->failed_at?->toIso8601String()]);
        $webhookErrors = $company->whatsappWebhookEvents()
            ->where('status', WhatsAppWebhookEvent::STATUS_FAILED)
            ->latest('received_at')
            ->limit(4)
            ->get()
            ->map(fn (WhatsAppWebhookEvent $event): array => ['kind' => 'Webhook não processado', 'occurred_at' => $event->received_at?->toIso8601String()]);

        return $deliveryErrors->merge($webhookErrors)->sortByDesc('occurred_at')->take(4)->values()->all();
    }

    private function maskIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : str_repeat('•', max(4, strlen($value) - 4)).substr($value, -4);
    }

    private function maskPhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';

        return $digits === '' ? null : substr($digits, 0, 2).' *****-'.substr($digits, -4);
    }

    private function publicWebhookUrl(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (app()->environment('local') || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return url('/api/webhooks/whatsapp/meta');
    }
}
