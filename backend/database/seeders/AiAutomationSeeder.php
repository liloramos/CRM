<?php

namespace Database\Seeders;

use App\Models\AiAutomationSetting;
use App\Models\Company;
use App\Models\Conversation;
use Illuminate\Database\Seeder;

class AiAutomationSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'restaurante-sol'],
            ['name' => 'Restaurante Sol'],
        );

        $company->aiAutomationSettings()->updateOrCreate(
            ['provider' => AiAutomationSetting::PROVIDER_FAKE],
            [
                'default_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
                'automation_enabled' => true,
                'allow_auto_send' => false,
                'require_human_confirmation_for_ambiguous' => true,
                'require_human_confirmation_for_payments' => true,
                'n8n_webhook_path' => null,
                'status' => AiAutomationSetting::STATUS_ACTIVE,
                'settings' => [
                    'external_api_called' => false,
                    'stores_real_credentials' => false,
                    'purpose' => 'Base local segura para sugestoes e fallback manual.',
                ],
            ],
        );
    }
}
