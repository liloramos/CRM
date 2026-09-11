<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\ReceiptTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\PrintingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_reads_only_its_company_printing_summary(): void
    {
        [$manager, $company] = $this->manager();
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'customer_name_snapshot' => 'Cliente Sol',
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
        ]);
        PrintJob::query()->create([
            'company_id' => $company->id,
            'order_id' => $order->id,
            'job_type' => PrintJob::TYPE_ORDER_TICKET,
            'target_audience' => ReceiptTemplate::TARGET_KITCHEN,
            'status' => PrintJob::STATUS_PRINTED,
            'copy_number' => 1,
            'requested_at' => now(),
        ]);
        $other = Company::query()->create(['name' => 'Outra', 'slug' => 'outra']);
        $foreignOrder = app(OrderWorkflowService::class)->createDraft($other, [
            'customer_name_snapshot' => 'Cliente Outra',
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
        ]);
        PrintJob::query()->create([
            'company_id' => $other->id,
            'order_id' => $foreignOrder->id,
            'job_type' => PrintJob::TYPE_ORDER_TICKET,
            'target_audience' => ReceiptTemplate::TARGET_KITCHEN,
            'status' => PrintJob::STATUS_FAILED,
            'copy_number' => 1,
            'requested_at' => now(),
            'failed_at' => now(),
            'html_content' => '<html>interno</html>',
            'rendered_payload' => ['internal' => true],
        ]);

        $this->actingAs($manager)
            ->getJson('/api/app/settings/printing')
            ->assertOk()
            ->assertJsonPath('data.latest_job.order_code', $order->code)
            ->assertJsonPath('data.recent_failures_count', 0)
            ->assertJsonMissingPath('data.latest_job.html_content')
            ->assertJsonMissingPath('data.latest_job.rendered_payload')
            ->assertJsonMissingPath('data.printer.settings');
    }

    public function test_printing_permissions_and_configuration_update_are_enforced(): void
    {
        [$manager, $company] = $this->manager();
        $unauthorized = User::factory()->create(['company_id' => $company->id]);
        $template = $company->receiptTemplates()->firstOrFail();

        $this->actingAs($unauthorized)->getJson('/api/app/settings/printing')->assertForbidden();
        $this->actingAs($manager)
            ->patchJson('/api/app/settings/printing', [
                'printer_name' => 'Cozinha',
                'paper_width_mm' => 58,
                'receipt_template_id' => $template->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.printer.name', 'Cozinha')
            ->assertJsonPath('data.printer.paper_width_mm', 58);

        $this->assertDatabaseHas('printer_settings', [
            'company_id' => $company->id,
            'name' => 'Cozinha',
            'paper_width_mm' => 58,
        ]);
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'customer_name_snapshot' => 'Cliente de teste',
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
        ]);
        $html = app(PrintWorkflowService::class)->generateTicket($order)->html_content;

        $this->assertStringContainsString('size: 58mm auto;', $html);
        $this->assertStringContainsString('width:54mm;', $html);
    }

    /** @return array{User, Company} */
    private function manager(): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class, PrintingSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return [$user, $company];
    }
}
