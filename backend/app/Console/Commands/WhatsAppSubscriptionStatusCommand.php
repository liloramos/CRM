<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppErrorClassifier;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class WhatsAppSubscriptionStatusCommand extends Command
{
    protected $signature = 'whatsapp:subscription-status';

    protected $description = 'Check WABA subscribed apps without printing WhatsApp secrets.';

    public function __construct(protected readonly WhatsAppErrorClassifier $errors)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $businessAccountId = $this->businessAccountId();
        $token = $this->token();
        $appId = $this->appId();

        $this->table(['Item', 'Valor seguro'], [
            ['WABA ID presente', $this->yesNo($businessAccountId !== '')],
            ['Token presente', $this->yesNo($token !== '')],
            ['App ID configurado', $this->yesNo($appId !== '')],
            ['Versao da API', $this->apiVersion()],
        ]);

        if ($businessAccountId === '' || $token === '') {
            $this->error('Configuracao Meta incompleta para consultar subscribed_apps.');

            return self::FAILURE;
        }

        try {
            $response = $this->sendStatusRequest($businessAccountId, $token);
        } catch (Throwable $exception) {
            $this->renderNetworkError($exception);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->renderProviderError($response);

            return self::FAILURE;
        }

        $apps = $this->appsFromResponse($response);
        $matchingApp = $appId !== ''
            ? collect($apps)->first(fn (array $app): bool => (string) ($app['id'] ?? '') === $appId)
            : null;

        $this->table(['Item', 'Valor seguro'], [
            ['HTTP Meta', (string) $response->status()],
            ['Aplicativos inscritos', (string) count($apps)],
            ['Aplicativo atual aparece', $appId === '' ? 'indeterminado (WHATSAPP_APP_ID ausente)' : $this->yesNo(is_array($matchingApp))],
            ['App IDs mascarados', implode(', ', array_map(fn (array $app): string => $this->mask((string) ($app['id'] ?? '')), $apps)) ?: 'nenhum'],
        ]);

        return self::SUCCESS;
    }

    protected function sendStatusRequest(string $businessAccountId, string $token): Response
    {
        return $this->request($token)
            ->timeout(12)
            ->get($this->subscribedAppsUrl($businessAccountId), ['fields' => 'id,name']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function appsFromResponse(Response $response): array
    {
        $data = $response->json('data');

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    protected function renderProviderError(Response $response): void
    {
        $json = $response->json();
        $errorPayload = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $error = $this->errors->providerRejection($response->status(), $errorPayload);

        $this->table(['Item', 'Valor seguro'], [
            ['HTTP Meta', (string) $response->status()],
            ['Codigo sanitizado', $error['code']],
            ['Codigo Meta', (string) ($error['safe_details']['meta_error_code'] ?? 'nenhum')],
            ['Subcodigo Meta', (string) ($error['safe_details']['meta_error_subcode'] ?? 'nenhum')],
            ['Mensagem', $error['message']],
        ]);
    }

    protected function renderNetworkError(Throwable $exception): void
    {
        $error = $this->errors->networkFailure($exception);

        $this->table(['Item', 'Valor seguro'], [
            ['HTTP Meta', 'sem resposta'],
            ['Codigo sanitizado', $error['code']],
            ['Motivo de rede', (string) ($error['safe_details']['network_error_reason'] ?? 'desconhecido')],
            ['Codigo de transporte', (string) ($error['safe_details']['network_error_code'] ?? 'nenhum')],
            ['Excecao de transporte', (string) ($error['safe_details']['network_exception'] ?? 'nenhuma')],
            ['Mensagem', $error['message']],
        ]);
    }

    protected function subscribedAppsUrl(string $businessAccountId): string
    {
        return rtrim($this->graphUrl(), '/').'/'.$this->apiVersion().'/'.$businessAccountId.'/subscribed_apps';
    }

    protected function request(string $token)
    {
        $request = Http::withToken($token)->acceptJson();
        $bundle = trim((string) config('chatbotcrm.whatsapp.meta.ca_bundle', ''));

        if ($bundle !== '' && is_file($bundle) && is_readable($bundle)) {
            $request = $request->withOptions(['verify' => $bundle]);
        }

        return $request;
    }

    protected function token(): string
    {
        return trim((string) config('chatbotcrm.whatsapp.meta.token', ''));
    }

    protected function businessAccountId(): string
    {
        return trim((string) config('chatbotcrm.whatsapp.meta.business_account_id', ''));
    }

    protected function appId(): string
    {
        return trim((string) config('chatbotcrm.whatsapp.meta.app_id', ''));
    }

    protected function apiVersion(): string
    {
        return (string) (config('chatbotcrm.whatsapp.meta.api_version') ?: 'v20.0');
    }

    protected function graphUrl(): string
    {
        return (string) (config('chatbotcrm.whatsapp.meta.graph_url') ?: 'https://graph.facebook.com');
    }

    protected function yesNo(bool $value): string
    {
        return $value ? 'sim' : 'nao';
    }

    protected function mask(string $value): string
    {
        $value = trim($value);

        return $value === '' ? 'ausente' : '...'.substr($value, -6);
    }
}
