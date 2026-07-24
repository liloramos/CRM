<?php

namespace Tests\Feature\Printing;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\PrintJobEvent;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PrintingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_preview_contains_order_items_notes_payment_and_pickup_details(): void
    {
        [$company, $order] = $this->createOperationalOrder();
        $user = User::factory()->create(['company_id' => $company->id]);
        $printing = app(PrintWorkflowService::class);

        $job = $printing->generateTicket($order, $user);
        $order->refresh();

        $this->assertSame(PrintJob::STATUS_PREVIEWED, $job->status);
        $this->assertSame(Order::PRINT_STATUS_PREVIEWED, $order->print_status);
        $this->assertSame(Order::STATUS_READY_TO_PRINT, $order->status);
        $this->assertNotNull($order->ticket_generated_at);
        $this->assertStringContainsString('COMANDA DE PEDIDO', $job->html_content);
        $this->assertStringContainsString('Cliente Pagador Sanitizado', $job->html_content);
        $this->assertStringContainsString('Pessoa Autorizada', $job->html_content);
        $this->assertStringContainsString('Pouco arroz, sem fritura.', $job->html_content);
        $this->assertStringContainsString('Credito usado', $job->html_content);
        $this->assertStringContainsString('Falta', $job->html_content);
        $this->assertDatabaseHas('print_job_events', [
            'order_id' => $order->id,
            'print_job_id' => $job->id,
            'event_type' => PrintJobEvent::EVENT_TICKET_GENERATED,
        ]);
    }

    public function test_ticket_preview_route_supports_autoprint_and_thermal_print_styles(): void
    {
        [$company, $order] = $this->createOperationalOrder();
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ATENDENTE);

        $response = $this->actingAs($user)
            ->get("/orders/{$order->id}/ticket/preview?autoprint=1")
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('COMANDA DE PEDIDO', $html);
        $this->assertStringContainsString('size: 80mm auto;', $html);
        $this->assertStringContainsString('width: 76mm;', $html);
        $this->assertStringContainsString('break-inside: avoid;', $html);
        $this->assertStringContainsString("get('autoprint') === '1'", $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'requested_by_user_id' => $user->id,
        ]);
    }

    public function test_ticket_uses_order_customer_snapshot_and_omits_empty_item_recipient(): void
    {
        $this->seed([CompanySeeder::class, PrintingSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'customer_name_snapshot' => 'Cliente Avulso da Rua',
            'customer_phone_snapshot' => '(62) 91111-2222',
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
        ]);

        $orders->addItem($order, $product, [
            'item_notes' => 'Sem observacao especial de pessoa.',
            'beneficiary_name' => null,
        ]);

        $html = app(PrintWorkflowService::class)->generateTicket($order->refresh())->html_content;

        $this->assertStringContainsString('Pagador: Cliente Avulso da Rua', $html);
        $this->assertStringContainsString('Telefone: (62) 91111-2222', $html);
        $this->assertStringNotContainsString('Para: Nao informado', $html);
        $this->assertStringNotContainsString('Para:', $html);
    }

    public function test_ticket_displays_special_item_recipient_only_when_informed(): void
    {
        $this->seed([CompanySeeder::class, PrintingSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente Principal']);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
        ]);

        $orders->addItem($order, $product, [
            'beneficiary_name' => 'Larissa',
        ]);

        $html = app(PrintWorkflowService::class)->generateTicket($order->refresh())->html_content;

        $this->assertStringContainsString('Pagador: Cliente Principal', $html);
        $this->assertStringContainsString('Para: Larissa', $html);
    }

    public function test_ticket_preview_route_keeps_company_isolation(): void
    {
        [, $order] = $this->createOperationalOrder();
        $this->seed(RoleAndPermissionSeeder::class);

        $otherCompany = Company::query()->create([
            'name' => 'Outro Restaurante',
            'slug' => 'outro-restaurante',
        ]);
        $user = User::factory()->create(['company_id' => $otherCompany->id]);
        $user->assignRole(Role::ATENDENTE);

        $this->actingAs($user)
            ->get("/orders/{$order->id}/ticket/preview?autoprint=1")
            ->assertNotFound();

        $this->assertDatabaseMissing('print_jobs', [
            'order_id' => $order->id,
            'requested_by_user_id' => $user->id,
        ]);
    }

    public function test_preparation_requires_printed_ticket_or_manual_authorization(): void
    {
        [, $order] = $this->createOperationalOrder();
        $orders = app(OrderWorkflowService::class);
        $printing = app(PrintWorkflowService::class);
        $job = $printing->generateTicket($order);

        try {
            $orders->transitionTo($order->refresh(), Order::STATUS_IN_PREPARATION, reason: 'prepare_without_ticket');
            $this->fail('Preparation should require printed ticket.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ticket is printed', $exception->getMessage());
        }

        $printing->markPrinted($job);
        $preparedOrder = $orders->transitionTo($order->refresh(), Order::STATUS_IN_PREPARATION, reason: 'prep_after_ticket');

        $this->assertSame(Order::STATUS_IN_PREPARATION, $preparedOrder->status);
        $this->assertSame(Order::PRINT_STATUS_PRINTED, $preparedOrder->print_status);
    }

    public function test_print_failure_and_reprint_are_audited(): void
    {
        [, $order] = $this->createOperationalOrder();
        $printing = app(PrintWorkflowService::class);

        $job = $printing->generateTicket($order);
        $failedJob = $printing->failPrint($job, message: 'Driver do navegador retornou erro operacional.');
        $reprint = $printing->requestReprint($failedJob, reason: 'Reimpressao apos falha operacional.');

        $this->assertSame(PrintJob::STATUS_REPRINT_REQUESTED, $failedJob->refresh()->status);
        $this->assertSame(PrintJob::STATUS_PREVIEWED, $reprint->status);
        $this->assertTrue($reprint->is_reprint);
        $this->assertSame(2, $reprint->copy_number);
        $this->assertSame($failedJob->id, $reprint->parent_print_job_id);
        $this->assertSame(Order::PRINT_STATUS_PREVIEWED, $order->refresh()->print_status);
        $this->assertDatabaseHas('print_job_events', [
            'order_id' => $order->id,
            'print_job_id' => $failedJob->id,
            'event_type' => PrintJobEvent::EVENT_PRINT_FAILED,
        ]);
        $this->assertDatabaseHas('print_job_events', [
            'order_id' => $order->id,
            'print_job_id' => $failedJob->id,
            'event_type' => PrintJobEvent::EVENT_REPRINT_REQUESTED,
        ]);
    }

    public function test_manual_print_confirmation_allows_preparation_without_browser_job(): void
    {
        [$company, $order] = $this->createOperationalOrder();
        $user = User::factory()->create(['company_id' => $company->id]);
        $printing = app(PrintWorkflowService::class);
        $orders = app(OrderWorkflowService::class);

        $manualOrder = $printing->markManualPrinted($order, $user, 'Comanda confirmada manualmente no atendimento.');
        $preparedOrder = $orders->transitionTo($manualOrder, Order::STATUS_IN_PREPARATION, $user, 'prep_after_manual_print');

        $this->assertSame(Order::STATUS_IN_PREPARATION, $preparedOrder->status);
        $this->assertSame(Order::PRINT_STATUS_MANUAL_CONFIRMED, $preparedOrder->print_status);
        $this->assertDatabaseHas('print_job_events', [
            'order_id' => $order->id,
            'event_type' => PrintJobEvent::EVENT_MANUAL_CONFIRMED,
        ]);
    }

    /**
     * @return array{0: Company, 1: Order}
     */
    private function createOperationalOrder(): array
    {
        $this->seed([CompanySeeder::class, PrintingSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Pagador Sanitizado',
        ]);

        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => CarbonImmutable::create(2026, 7, 6),
            'origin_channel' => Order::CHANNEL_COUNTER,
            'entry_mode' => Order::CHANNEL_MANUAL,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'pickup_person_name' => 'Pessoa Autorizada',
            'pickup_authorized_by' => 'Cliente Pagador Sanitizado',
            'general_notes' => 'Pedido conferido manualmente para preparo.',
            'kitchen_notes' => 'Separar item com observacao.',
        ]);

        $product = Product::query()->where('slug', 'n8-casa')->firstOrFail();
        $option = ProductOption::query()->where('slug', 'ovo-frito')->firstOrFail();

        $orders->addItem($order, $product, [
            'item_notes' => 'Pouco arroz, sem fritura.',
            'beneficiary_name' => 'Pessoa Beneficiaria',
            'beneficiary_notes' => 'Item separado para retirada.',
            'preferences' => ['mais_salada'],
            'restrictions' => ['sem_fritura'],
            'selected_components' => ['salada' => 'vinagrete'],
            'options' => [
                ['product_option_id' => $option->id],
            ],
        ]);

        return [$company, $order->refresh()];
    }
}
