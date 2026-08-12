<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppErrorClassifier;
use Illuminate\Http\Client\Response;
use Throwable;

class SubscribeWhatsAppWabaCommand extends WhatsAppSubscriptionStatusCommand
{
    protected $signature = 'whatsapp:subscribe-waba {--apply : Executa a inscricao; sem esta opcao o comando e dry-run}';

    protected $description = 'Subscribe the configured WABA to the current Meta app without printing WhatsApp secrets.';

    public function __construct(WhatsAppErrorClassifier $errors)
    {
        parent::__construct($errors);
    }

    public function handle(): int
    {
        $businessAccountId = $this->businessAccountId();
        $token = $this->token();

        $this->table(['Item', 'Valor seguro'], [
            ['WABA ID presente', $this->yesNo($businessAccountId !== '')],
            ['Token presente', $this->yesNo($token !== '')],
            ['Modo', $this->option('apply') ? 'apply' : 'dry-run'],
            ['Versao da API', $this->apiVersion()],
        ]);

        if ($businessAccountId === '' || $token === '') {
            $this->error('Configuracao Meta incompleta para inscrever a WABA.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry-run: use --apply para executar POST /subscribed_apps.');

            return self::SUCCESS;
        }

        try {
            $response = $this->sendSubscribeRequest($businessAccountId, $token);
        } catch (Throwable $exception) {
            $this->renderNetworkError($exception);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->renderProviderError($response);

            return self::FAILURE;
        }

        $this->table(['Item', 'Valor seguro'], [
            ['HTTP Meta', (string) $response->status()],
            ['Inscricao solicitada', 'sim'],
        ]);

        return self::SUCCESS;
    }

    protected function sendSubscribeRequest(string $businessAccountId, string $token): Response
    {
        return $this->request($token)
            ->timeout(12)
            ->post($this->subscribedAppsUrl($businessAccountId));
    }
}
