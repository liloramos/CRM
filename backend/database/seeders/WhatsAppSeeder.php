<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Seeder;

class WhatsAppSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'restaurante-sol'],
            ['name' => 'Restaurante Sol'],
        );

        $company->whatsappAccounts()->updateOrCreate(
            [
                'provider' => WhatsAppAccount::PROVIDER_FAKE,
                'name' => 'WhatsApp fake desenvolvimento',
            ],
            [
                'phone_number_id' => 'fake-phone-number-id',
                'business_account_id' => 'fake-business-account-id',
                'display_phone_number' => null,
                'status' => WhatsAppAccount::STATUS_CONNECTED,
                'connection_status_message' => 'Provider fake local para desenvolvimento seguro.',
                'is_default' => true,
                'connected_at' => now(),
                'settings' => [
                    'external_api_called' => false,
                    'stores_real_credentials' => false,
                ],
            ],
        );
    }
}
