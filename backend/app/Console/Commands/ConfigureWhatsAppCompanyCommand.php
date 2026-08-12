<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\WhatsAppAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigureWhatsAppCompanyCommand extends Command
{
    protected $signature = 'whatsapp:configure-company
        {--company=restaurante-sol : Slug exato da empresa}
        {--dry-run : Exibe a ação sem persistir}';

    protected $description = 'Associa a configuração Meta do ambiente a uma empresa sem armazenar credenciais.';

    public function handle(): int
    {
        $companySlug = trim((string) $this->option('company'));
        $company = Company::query()->where('slug', $companySlug)->first();

        if (! $company instanceof Company) {
            $this->error("Empresa [{$companySlug}] não encontrada. Informe o tenant explicitamente.");

            return self::FAILURE;
        }

        $provider = (string) config('chatbotcrm.whatsapp.provider', 'fake');
        $phoneNumberId = trim((string) config('chatbotcrm.whatsapp.meta.phone_number_id', ''));
        $businessAccountId = trim((string) config('chatbotcrm.whatsapp.meta.business_account_id', ''));
        $tokenPresent = trim((string) config('chatbotcrm.whatsapp.meta.token', '')) !== '';

        $this->table(['Item', 'Valor seguro'], [
            ['Empresa', $company->slug],
            ['Provider ativo', $provider],
            ['Phone Number ID presente', $this->yesNo($phoneNumberId !== '')],
            ['WABA ID presente', $this->yesNo($businessAccountId !== '')],
            ['Token presente', $this->yesNo($tokenPresent)],
            ['Persistência de token no banco', 'não'],
            ['Modo', $this->option('dry-run') ? 'dry-run' : 'persistência'],
        ]);

        if (! in_array($provider, ['meta', 'meta_cloud'], true) || $phoneNumberId === '' || $businessAccountId === '') {
            $this->error('Configuração Meta incompleta. Revise apenas as variáveis do backend e o config cache.');

            return self::FAILURE;
        }

        $conflict = WhatsAppAccount::query()
            ->where('phone_number_id', $phoneNumberId)
            ->where('company_id', '!=', $company->id)
            ->exists();

        if ($conflict) {
            $this->error('O Phone Number ID configurado já pertence a outra empresa. Associação bloqueada.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($company, $phoneNumberId, $businessAccountId, $tokenPresent): void {
            $account = WhatsAppAccount::query()->firstOrNew([
                'company_id' => $company->id,
                'provider' => WhatsAppAccount::PROVIDER_META_CLOUD,
                'phone_number_id' => $phoneNumberId,
            ]);
            $settings = $account->settings ?? [];
            $settings['configured_from_environment'] = true;
            $settings['stores_access_token'] = false;
            $settings['api_version'] = (string) config('chatbotcrm.whatsapp.meta.api_version', '');

            $account->fill([
                'name' => 'WhatsApp Meta Cloud',
                'business_account_id' => $businessAccountId,
                'status' => $account->status === WhatsAppAccount::STATUS_CONNECTED
                    ? WhatsAppAccount::STATUS_CONNECTED
                    : WhatsAppAccount::STATUS_PENDING_CONFIGURATION,
                'connection_status_message' => $tokenPresent
                    ? 'Configuração carregada. Aguardando validação operacional do webhook.'
                    : 'Token ausente na configuração do backend.',
                'is_default' => true,
                'settings' => $settings,
            ])->save();

            WhatsAppAccount::query()
                ->where('company_id', $company->id)
                ->whereKeyNot($account->id)
                ->update(['is_default' => false]);
        });

        $this->info('Associação empresarial atualizada sem persistir credenciais.');

        return self::SUCCESS;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'sim' : 'não';
    }
}
