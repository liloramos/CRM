<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PrinterSetting;
use App\Models\ReceiptTemplate;
use Illuminate\Database\Seeder;

class PrintingSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'restaurante-sol'],
            ['name' => 'Restaurante Sol'],
        );

        $company->printerSettings()->updateOrCreate(
            ['name' => 'Impressora termica operacional'],
            [
                'printer_model' => 'Epson TM-T20X 031 / M352A',
                'print_mode' => PrinterSetting::PRINT_MODE_BROWSER_HTML,
                'connection_type' => PrinterSetting::CONNECTION_BROWSER_DRIVER,
                'status' => PrinterSetting::STATUS_ACTIVE,
                'paper_width_mm' => 80,
                'is_default' => true,
                'settings' => [
                    'driver_mode' => 'browser_first',
                    'serial_number_stored' => false,
                    'requires_real_configuration' => true,
                ],
            ],
        );

        $company->receiptTemplates()->updateOrCreate(
            ['code' => 'order-ticket-kitchen'],
            [
                'name' => 'Comanda operacional cozinha',
                'template_type' => ReceiptTemplate::TYPE_ORDER_TICKET,
                'target_audience' => ReceiptTemplate::TARGET_KITCHEN,
                'view_name' => 'printing.order-ticket',
                'width_chars' => 32,
                'includes_financials' => true,
                'is_default' => true,
                'settings' => [
                    'layout' => 'thermal_html',
                    'paper_width_mm' => 80,
                ],
            ],
        );
    }
}
