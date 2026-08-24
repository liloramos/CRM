<?php

namespace App\Services\Operational;

use Closure;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class ProductionReadinessChecker
{
    public const PASS = 'PASS';

    public const WARNING = 'WARNING';

    public const FAIL = 'FAIL';

    /** @param (Closure(string): bool)|null $binaryProbe */
    public function __construct(private readonly ?Closure $binaryProbe = null) {}

    /** @return list<array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string}> */
    public function evaluate(): array
    {
        $production = app()->environment('production');
        $queue = (string) config('queue.default', 'sync');
        $publicRoot = (string) config('filesystems.disks.public.root', '');
        $publicLink = public_path('storage');
        $whatsAppProvider = (string) config('chatbotcrm.whatsapp.provider', 'fake');
        $openAiProvider = (string) config('chatbotcrm.ai.copilot.provider', 'fake');

        return [
            $this->environmentCheck($production),
            $this->debugCheck($production),
            $this->httpsCheck($production),
            $this->databaseCheck(),
            $this->queueCheck($queue, $production),
            $this->queueTablesCheck($queue),
            $this->sessionCheck($production),
            $this->storageCheck($publicRoot, $publicLink),
            $this->binaryCheck('ffmpeg', (string) config('chatbotcrm.whatsapp.media.ffmpeg_binary', 'ffmpeg')),
            $this->binaryCheck('ffprobe', (string) config('chatbotcrm.whatsapp.media.ffprobe_binary', 'ffprobe')),
            $this->timezoneCheck(),
            $this->whatsAppCheck($whatsAppProvider, $production),
            $this->openAiCheck($openAiProvider),
            $this->demoDataCheck($production),
            $this->mockFallbackCheck(),
        ];
    }

    /** @param list<array{status: string}> $checks */
    public function hasFailures(array $checks): bool
    {
        return collect($checks)->contains(fn (array $check): bool => $check['status'] === self::FAIL);
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function environmentCheck(bool $production): array
    {
        return $production
            ? $this->result(self::PASS, 'APP_ENV', 'Ambiente de produção configurado.')
            : $this->result(self::WARNING, 'APP_ENV', 'Execute novamente com a configuração de produção antes do go-live.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function debugCheck(bool $production): array
    {
        if (! (bool) config('app.debug')) {
            return $this->result(self::PASS, 'APP_DEBUG', 'Debug desativado.');
        }

        return $this->result($production ? self::FAIL : self::WARNING, 'APP_DEBUG', 'Debug deve estar desativado em produção.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function httpsCheck(bool $production): array
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

        if ($scheme === 'https') {
            return $this->result(self::PASS, 'APP_URL', 'URL pública usa HTTPS.');
        }

        return $this->result($production ? self::FAIL : self::WARNING, 'APP_URL', 'Configure uma URL pública HTTPS antes do go-live.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function databaseCheck(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return $this->result(self::PASS, 'Banco de dados', 'Conexão disponível.');
        } catch (Throwable) {
            return $this->result(self::FAIL, 'Banco de dados', 'Não foi possível conectar ao banco configurado.');
        }
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function queueCheck(string $queue, bool $production): array
    {
        if ($queue === 'database') {
            return $this->result(self::PASS, 'Fila', 'Fila database configurada; mantenha um worker supervisionado em execução.');
        }

        if ($queue === 'sync') {
            return $this->result($production ? self::FAIL : self::WARNING, 'Fila', 'A fila sync não atende ao processamento assíncrono do WhatsApp em produção.');
        }

        return $this->result(self::WARNING, 'Fila', 'Confirme worker supervisionado e a disponibilidade da conexão configurada.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function queueTablesCheck(string $queue): array
    {
        if ($queue !== 'database') {
            return $this->result(self::WARNING, 'Tabelas da fila', 'Não verificadas para a conexão de fila atual.');
        }

        try {
            return Schema::hasTable('jobs') && Schema::hasTable('failed_jobs')
                ? $this->result(self::PASS, 'Tabelas da fila', 'Tabelas jobs e failed_jobs disponíveis.')
                : $this->result(self::FAIL, 'Tabelas da fila', 'Execute as migrations antes de iniciar o worker.');
        } catch (Throwable) {
            return $this->result(self::FAIL, 'Tabelas da fila', 'Não foi possível verificar as tabelas da fila.');
        }
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function sessionCheck(bool $production): array
    {
        $driver = (string) config('session.driver', 'file');
        $secure = (bool) config('session.secure', false);

        if ($driver !== 'array' && (! $production || $secure)) {
            return $this->result(self::PASS, 'Sessão', 'Driver persistente e cookies compatíveis com o ambiente atual.');
        }

        if ($driver === 'array') {
            return $this->result($production ? self::FAIL : self::WARNING, 'Sessão', 'O driver array não é persistente.');
        }

        return $this->result(self::FAIL, 'Sessão', 'Ative SESSION_SECURE_COOKIE em produção HTTPS.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function storageCheck(string $publicRoot, string $publicLink): array
    {
        if ($publicRoot === '' || ! is_dir($publicRoot) || ! is_writable($publicRoot)) {
            return $this->result(self::FAIL, 'Storage público', 'O diretório público de uploads não está acessível para escrita.');
        }

        if (! is_link($publicLink) && ! is_dir($publicLink)) {
            return $this->result(self::WARNING, 'Storage público', 'Execute storage:link antes de publicar fotos e mídias públicas.');
        }

        return $this->result(self::PASS, 'Storage público', 'Diretório de uploads acessível e link público presente.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function binaryCheck(string $name, string $binary): array
    {
        if ($binary === '' || ! $this->binaryAvailable($binary)) {
            return $this->result(self::WARNING, $name, "{$name} indisponível; mensagens de áudio podem não ser normalizadas.");
        }

        return $this->result(self::PASS, $name, "{$name} acessível para processamento de áudio.");
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function timezoneCheck(): array
    {
        $timezone = (string) config('app.timezone', '');

        try {
            new DateTimeZone($timezone);

            return $this->result(self::PASS, 'Timezone', 'Timezone da aplicação válida.');
        } catch (Throwable) {
            return $this->result(self::FAIL, 'Timezone', 'Configure uma timezone válida para o dia operacional.');
        }
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function whatsAppCheck(string $provider, bool $production): array
    {
        if ($provider !== 'meta') {
            return $this->result($production ? self::FAIL : self::WARNING, 'WhatsApp', 'Configure o provider Meta antes do go-live.');
        }

        $required = ['token', 'phone_number_id', 'business_account_id', 'verify_token', 'app_secret'];
        $configured = collect($required)->every(fn (string $key): bool => filled(config("chatbotcrm.whatsapp.meta.{$key}")));

        return $configured
            ? $this->result(self::PASS, 'WhatsApp', 'Credenciais obrigatórias presentes; valide o webhook HTTPS no painel Meta.')
            : $this->result(self::FAIL, 'WhatsApp', 'Faltam configurações obrigatórias da integração Meta.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function openAiCheck(string $provider): array
    {
        if ($provider !== 'openai') {
            return $this->result(self::WARNING, 'Copiloto IA', 'Provider online não configurado; o CRM continua operando sem Copiloto online.');
        }

        return filled(config('chatbotcrm.ai.openai.api_key'))
            ? $this->result(self::PASS, 'Copiloto IA', 'Chave do provider online presente.')
            : $this->result(self::WARNING, 'Copiloto IA', 'Provider online selecionado sem chave configurada.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function demoDataCheck(bool $production): array
    {
        if (! (bool) config('chatbotcrm.whatsapp.demo_data_enabled', false)) {
            return $this->result(self::PASS, 'Dados demonstrativos', 'Dados demonstrativos desativados.');
        }

        return $this->result($production ? self::FAIL : self::WARNING, 'Dados demonstrativos', 'Desative DEMO_DATA_ENABLED antes do go-live.');
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function mockFallbackCheck(): array
    {
        return $this->result(self::WARNING, 'Fallback mock do frontend', 'Confirme VITE_ENABLE_MOCK_FALLBACK ausente ou false no build de produção.');
    }

    private function binaryAvailable(string $binary): bool
    {
        if ($this->binaryProbe !== null) {
            return (bool) ($this->binaryProbe)($binary);
        }

        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{status: self::PASS|self::WARNING|self::FAIL, check: string, message: string} */
    private function result(string $status, string $check, string $message): array
    {
        return compact('status', 'check', 'message');
    }
}
