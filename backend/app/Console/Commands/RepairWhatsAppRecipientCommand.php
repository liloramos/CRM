<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

class RepairWhatsAppRecipientCommand extends Command
{
    protected $signature = 'whatsapp:repair-recipient
        {customer : Customer ID to repair}
        {phone : Canonical phone in international digits or E.164 format}
        {--confirm : Apply the repair after validation}';

    protected $description = 'Set a canonical outbound phone without changing the WhatsApp provider identity.';

    public function handle(): int
    {
        $customer = Customer::query()->find($this->argument('customer'));

        if (! $customer instanceof Customer) {
            $this->error('Cliente não encontrado.');

            return self::FAILURE;
        }

        $phone = preg_replace('/\D+/', '', (string) $this->argument('phone')) ?: '';

        if (strlen($phone) < 10 || strlen($phone) > 15) {
            $this->error('Informe um telefone internacional válido.');

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->line('Simulação: o telefone canônico será atualizado sem alterar a identidade WhatsApp.');
            $this->line('Use --confirm para aplicar.');

            return self::SUCCESS;
        }

        $customer->forceFill(['phone' => $phone])->save();
        $this->info('Telefone canônico atualizado com segurança.');

        return self::SUCCESS;
    }
}
